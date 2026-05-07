"""Animatronic Motion System GUI.

Two operating modes selected from the main screen:

  LIVE FOLLOW          — animatronic autonomously tracks a detected face,
                         keeping the subject centered via pan/tilt servos.

  PERFORMANCE CAPTURE  — performer's movements drive servos live; session is
                         recorded and exported as an FSEQ v2 file for xLights.

Shared configuration (Servos, Tuning, Hardware) lives in the ⚙ Settings popup.
Deployable on Raspberry Pi (real servos) or Windows PC (mock hardware).
Change hardware.type in config.yaml — no code changes needed.
"""

import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk
from typing import Dict, Optional

import cv2
import yaml
from PIL import Image, ImageTk

from core.camera import Camera
from core.servo_controller import ServoController, create_backend
from core.tracker import Tracker
from modes.live_tracking import LiveTrackingMode
from modes.motion_capture import MotionCaptureMode
from xlights.fseq_writer import export_fseq

# ── Palette ─────────────────────────────────────────────────────────────────
_BG      = '#1a1a2e'
_PANEL   = '#16213e'
_ACCENT  = '#0f3460'
_CYAN    = '#4cc9f0'
_GREEN   = '#06d6a0'
_MAGENTA = '#f72585'
_PURPLE  = '#7209b7'
_AMBER   = '#fb8500'
_RED     = '#e63946'
_FG      = '#e0e0e0'
_MUTED   = '#888888'

_LIVE_BG = '#0a4f6e'   # darkened cyan — active Live Follow button
_CAP_BG  = '#6e0a3d'   # darkened magenta — active Performance Capture button

# Camera display dimensions (capture still runs at native resolution)
_CAM_W = 560
_CAM_H = 420


# ---------------------------------------------------------------------------
# Widget helpers
# ---------------------------------------------------------------------------

def _btn(parent, text, bg, fg, cmd, width=None):
    kw = {'width': width} if width else {}
    return tk.Button(parent, text=text, bg=bg, fg=fg,
                     font=('Helvetica', 10, 'bold'), relief=tk.FLAT,
                     activebackground=bg, activeforeground=fg,
                     padx=12, pady=6, command=cmd, **kw)


def _ent(parent, var, width=10):
    return tk.Entry(parent, textvariable=var, bg=_PANEL, fg=_FG,
                    insertbackground='white', font=('Helvetica', 9),
                    width=width, relief=tk.FLAT)


def _lbl(parent, text, fg=None, font=None, **kw):
    return tk.Label(parent, text=text, bg=parent['bg'],
                    fg=fg or _FG, font=font or ('Helvetica', 9), **kw)


def _sep(parent, color=_MUTED):
    tk.Frame(parent, bg=color, height=1).pack(fill=tk.X, pady=6)


def _as_hex(val) -> str:
    """Return a hex string whether val is already a string ('0x40') or an int (64)."""
    return val if isinstance(val, str) else hex(val)


# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------

class AnimatronicApp:
    def __init__(self, config: dict, config_path: str = 'config.yaml'):
        self._cfg      = config
        self._cfg_path = config_path

        self._root = tk.Tk()
        self._root.title("Animatronic Motion System")
        self._root.geometry("960x580")
        self._root.minsize(960, 580)
        self._root.configure(bg=_BG)

        # Core components
        self._camera = Camera(**config.get('camera', {}))
        self._tracker = Tracker()
        hw_cfg = config.get('hardware', {})
        self._backend = create_backend(hw_cfg)
        self._servos  = ServoController(
            self._backend,
            config.get('servos', {}),
            hw_cfg.get('channel_assignments', {'pan': 0, 'tilt': 1}),
        )
        self._live_mode    = LiveTrackingMode(self._servos, config)
        self._capture_mode = MotionCaptureMode(self._servos, config)

        # Camera pipeline
        self._q: queue.Queue       = queue.Queue(maxsize=2)
        self._running              = False
        self._photo                = None   # prevents GC of PhotoImage

        self._active_mode: Optional[str]          = None
        self._settings_win: Optional[tk.Toplevel] = None

        # Playback state
        self._pb_stop_evt:  Optional[threading.Event] = None
        self._pb_pause_evt: Optional[threading.Event] = None
        self._pb_playing    = False
        self._pb_paused     = False

        self._setup_styles()
        self._build_ui()
        self._start_camera()

    # -----------------------------------------------------------------------
    # Styles
    # -----------------------------------------------------------------------

    def _setup_styles(self):
        s = ttk.Style()
        s.theme_use('clam')
        s.configure('TNotebook',     background=_BG,    borderwidth=0)
        s.configure('TNotebook.Tab', background=_PANEL, foreground=_FG,
                    padding=[12, 6], font=('Helvetica', 10, 'bold'))
        s.map('TNotebook.Tab', background=[('selected', _ACCENT)])
        s.configure('TScale',    background=_BG, troughcolor=_PANEL)
        s.configure('TCombobox', fieldbackground=_PANEL, background=_PANEL,
                    foreground=_FG, selectbackground=_ACCENT)

    # -----------------------------------------------------------------------
    # Top-level layout
    # -----------------------------------------------------------------------

    def _build_ui(self):
        # ── Status bar ──────────────────────────────────────────────────────
        bar = tk.Frame(self._root, bg=_ACCENT, height=36)
        bar.pack(fill=tk.X, side=tk.TOP)
        bar.pack_propagate(False)

        self._status_var = tk.StringVar(value="Ready")
        tk.Label(bar, textvariable=self._status_var,
                 bg=_ACCENT, fg=_FG, font=('Helvetica', 10)).pack(side=tk.LEFT, padx=12)

        _btn(bar, "⚙  SETTINGS", _ACCENT, _CYAN, self._open_settings).pack(side=tk.RIGHT, padx=8)

        hw_type = self._cfg.get('hardware', {}).get('type', 'mock')
        self._hw_badge = tk.Label(bar, text=f"hw: {hw_type}",
                                   bg=_ACCENT, fg=_CYAN, font=('Helvetica', 9, 'bold'))
        self._hw_badge.pack(side=tk.RIGHT, padx=8)
        self._cam_dot = tk.Label(bar, text="● cam", bg=_ACCENT,
                                  fg=_MUTED, font=('Helvetica', 9))
        self._cam_dot.pack(side=tk.RIGHT, padx=4)

        # ── Mode selector ───────────────────────────────────────────────────
        sel = tk.Frame(self._root, bg='#0d0d1f', height=56)
        sel.pack(fill=tk.X)
        sel.pack_propagate(False)

        self._mode_live_btn = tk.Button(
            sel, text="  ●  LIVE FOLLOW  ",
            bg=_PANEL, fg=_MUTED,
            font=('Helvetica', 13, 'bold'), relief=tk.FLAT,
            activebackground=_LIVE_BG, activeforeground=_CYAN,
            padx=24, pady=10,
            command=lambda: self._switch_mode('live'),
        )
        self._mode_live_btn.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        tk.Frame(sel, bg='#0d0d1f', width=3).pack(side=tk.LEFT, fill=tk.Y)

        self._mode_cap_btn = tk.Button(
            sel, text="  ◎  PERFORMANCE CAPTURE  ",
            bg=_PANEL, fg=_MUTED,
            font=('Helvetica', 13, 'bold'), relief=tk.FLAT,
            activebackground=_CAP_BG, activeforeground=_MAGENTA,
            padx=24, pady=10,
            command=lambda: self._switch_mode('capture'),
        )
        self._mode_cap_btn.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        # ── Content area ────────────────────────────────────────────────────
        content = tk.Frame(self._root, bg=_BG)
        content.pack(fill=tk.BOTH, expand=True, padx=6, pady=6)

        # Shared camera canvas (left side, both modes use the same feed)
        cam_frame = tk.Frame(content, bg=_BG)
        cam_frame.pack(side=tk.LEFT, padx=(0, 6))
        _lbl(cam_frame, "CAMERA FEED", fg=_MUTED, font=('Helvetica', 8)).pack()
        self._camera_canvas = tk.Canvas(cam_frame, width=_CAM_W, height=_CAM_H,
                                        bg='#000', highlightthickness=2,
                                        highlightbackground=_ACCENT)
        self._camera_canvas.pack()
        self._cam_img_id = self._camera_canvas.create_image(0, 0, anchor=tk.NW)

        # Right panel — mode-specific controls swap in here
        self._right = tk.Frame(content, bg=_BG)
        self._right.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        self._panel_live    = tk.Frame(self._right, bg=_BG)
        self._panel_capture = tk.Frame(self._right, bg=_BG)
        self._build_live_panel()
        self._build_capture_panel()

        self._switch_mode('live')

    def _switch_mode(self, mode: str):
        if mode == self._active_mode:
            return
        self._panel_live.pack_forget()
        self._panel_capture.pack_forget()
        if mode == 'live':
            self._panel_live.pack(fill=tk.BOTH, expand=True)
            self._mode_live_btn.config(bg=_LIVE_BG, fg=_CYAN)
            self._mode_cap_btn.config(bg=_PANEL,    fg=_MUTED)
            self._camera_canvas.config(highlightbackground=_CYAN)
            self._status_var.set("Live Follow  —  ready")
        else:
            self._panel_capture.pack(fill=tk.BOTH, expand=True)
            self._mode_live_btn.config(bg=_PANEL,  fg=_MUTED)
            self._mode_cap_btn.config(bg=_CAP_BG,  fg=_MAGENTA)
            self._camera_canvas.config(highlightbackground=_MAGENTA)
            self._status_var.set("Performance Capture  —  ready")
        self._active_mode = mode

    # -----------------------------------------------------------------------
    # Live Follow panel
    # -----------------------------------------------------------------------

    def _build_live_panel(self):
        p = self._panel_live

        _lbl(p, "LIVE FOLLOW", fg=_CYAN,
             font=('Helvetica', 12, 'bold')).pack(pady=(10, 2))
        _lbl(p, "Animatronic tracks and centers on a detected face.",
             fg=_MUTED, font=('Helvetica', 8, 'italic')).pack()

        _sep(p, _CYAN)

        _lbl(p, "SERVO POSITIONS", fg=_MUTED,
             font=('Helvetica', 8, 'bold')).pack(pady=(4, 2))
        self._pan_disp  = self._servo_row(p, 'Pan',  _CYAN)
        self._tilt_disp = self._servo_row(p, 'Tilt', _CYAN)

        _sep(p)
        self._face_lbl = _lbl(p, "No face detected", fg=_MUTED)
        self._face_lbl.pack(pady=4)
        _sep(p)

        self._live_btn = _btn(p, "START LIVE FOLLOW", _CYAN, '#000', self._toggle_live)
        self._live_btn.pack(pady=10, padx=12, fill=tk.X)

    def _servo_row(self, parent, name: str, color=_CYAN) -> tk.Label:
        row = tk.Frame(parent, bg=_BG)
        row.pack(fill=tk.X, padx=12, pady=2)
        _lbl(row, name, width=6, anchor='w').pack(side=tk.LEFT)
        lbl = _lbl(row, "90.0°", fg=color, font=('Helvetica', 11, 'bold'))
        lbl.pack(side=tk.RIGHT)
        return lbl

    # -----------------------------------------------------------------------
    # Performance Capture panel
    # -----------------------------------------------------------------------

    def _build_capture_panel(self):
        p = self._panel_capture

        _lbl(p, "PERFORMANCE CAPTURE", fg=_MAGENTA,
             font=('Helvetica', 12, 'bold')).pack(pady=(10, 2))
        _lbl(p, "Performer drives servos live. Record and export to xLights.",
             fg=_MUTED, font=('Helvetica', 8, 'italic')).pack()

        _sep(p, _MAGENTA)

        # Tracked values — two columns: Face (cyan) | Body (amber)
        tv = tk.Frame(p, bg=_BG)
        tv.pack(fill=tk.X, padx=8)

        self._tracked_vars: Dict[str, tk.StringVar] = {}

        face_col = tk.Frame(tv, bg=_BG)
        face_col.pack(side=tk.LEFT, fill=tk.Y, expand=True)
        body_col = tk.Frame(tv, bg=_BG)
        body_col.pack(side=tk.LEFT, fill=tk.Y, expand=True)

        _lbl(face_col, "FACE", fg=_MUTED, font=('Helvetica', 7, 'bold')).pack()
        for key, label in [
            ('head_yaw',            'Head Yaw'),
            ('head_pitch',          'Head Pitch'),
            ('head_roll',           'Head Roll'),
            ('mouth_open',          'Mouth'),
            ('left_eye_open',       'L Eye'),
            ('right_eye_open',      'R Eye'),
            ('left_eyebrow_raise',  'L Brow'),
            ('right_eyebrow_raise', 'R Brow'),
        ]:
            self._tracked_vars[key] = self._tracked_row(face_col, label, _CYAN)

        _lbl(body_col, "BODY", fg=_MUTED, font=('Helvetica', 7, 'bold')).pack()
        for key, label in [
            ('torso_lean_lr',    'Torso L/R'),
            ('torso_lean_fb',    'Torso F/B'),
            ('torso_tilt',       'Torso Tilt'),
            ('left_arm_raise',   'L Arm'),
            ('right_arm_raise',  'R Arm'),
            ('left_elbow_bend',  'L Elbow'),
            ('right_elbow_bend', 'R Elbow'),
            ('left_wrist_raise', 'L Wrist'),
            ('right_wrist_raise','R Wrist'),
        ]:
            self._tracked_vars[key] = self._tracked_row(body_col, label, _AMBER)

        _sep(p)

        self._rec_info = _lbl(p, "00:00.0  |  0 frames", fg=_MUTED, font=('Helvetica', 8))
        self._rec_info.pack(pady=2)

        self._rec_btn = _btn(p, "RECORD", _MAGENTA, '#fff', self._toggle_rec)
        self._rec_btn.pack(pady=4, padx=12, fill=tk.X)

        export_row = tk.Frame(p, bg=_BG)
        export_row.pack(pady=2, padx=12, fill=tk.X)
        _btn(export_row, "EXPORT FSEQ", _PURPLE, '#fff', self._export_fseq).pack(
            side=tk.LEFT, fill=tk.X, expand=True, padx=(0, 3))
        _btn(export_row, "SAVE SESSION", _ACCENT, _CYAN, self._save_session).pack(
            side=tk.LEFT, fill=tk.X, expand=True, padx=(3, 0))

        _sep(p)

        self._pb_info = _lbl(p, "No session loaded", fg=_MUTED, font=('Helvetica', 8))
        self._pb_info.pack(pady=2)

        pb_row = tk.Frame(p, bg=_BG)
        pb_row.pack(pady=4, padx=12, fill=tk.X)
        self._pb_btn = _btn(pb_row, "▶  PLAY", _GREEN, '#000', self._toggle_playback)
        self._pb_btn.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=(0, 3))
        self._pb_stop_btn = _btn(pb_row, "■  STOP", _MUTED, '#fff', self._stop_playback)
        self._pb_stop_btn.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=(3, 0))

        _btn(p, "LOAD SESSION", _PANEL, _CYAN, self._load_session).pack(
            pady=2, padx=12, fill=tk.X)

    def _tracked_row(self, parent, label: str, color: str) -> tk.StringVar:
        row = tk.Frame(parent, bg=_BG)
        row.pack(fill=tk.X, pady=1)
        tk.Label(row, text=label, bg=_BG, fg=_FG,
                 font=('Helvetica', 8), width=9, anchor='w').pack(side=tk.LEFT)
        var = tk.StringVar(value=" 0.00")
        tk.Label(row, textvariable=var, bg=_BG, fg=color,
                 font=('Courier', 8, 'bold'), width=6).pack(side=tk.RIGHT)
        return var

    # -----------------------------------------------------------------------
    # Settings window (singleton Toplevel)
    # -----------------------------------------------------------------------

    def _open_settings(self):
        if self._settings_win and self._settings_win.winfo_exists():
            self._settings_win.lift()
            return

        win = tk.Toplevel(self._root)
        win.title("Settings")
        win.geometry("640x520")
        win.configure(bg=_BG)
        win.resizable(False, False)
        self._settings_win = win

        nb = ttk.Notebook(win)
        nb.pack(fill=tk.BOTH, expand=True, padx=6, pady=6)

        tab_servos   = tk.Frame(nb, bg=_BG)
        tab_mapping  = tk.Frame(nb, bg=_BG)
        tab_tuning   = tk.Frame(nb, bg=_BG)
        tab_hardware = tk.Frame(nb, bg=_BG)
        nb.add(tab_servos,   text="  Servos  ")
        nb.add(tab_mapping,  text="  Servo Mapping  ")
        nb.add(tab_tuning,   text="  Tuning  ")
        nb.add(tab_hardware, text="  Hardware  ")

        self._build_servos_tab(tab_servos)
        self._build_servo_mapping_tab(tab_mapping)
        self._build_tuning_tab(tab_tuning)
        self._build_hardware_tab(tab_hardware)

    # -----------------------------------------------------------------------
    # Settings — Servos tab
    # -----------------------------------------------------------------------

    def _build_servos_tab(self, parent):
        outer = tk.Frame(parent, bg=_BG)
        outer.pack(padx=24, pady=12, fill=tk.BOTH)

        tk.Label(outer, text="SERVO LIMITS", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 8, 'bold')).grid(
            row=0, column=0, columnspan=3, sticky='w', pady=(0, 6))
        for col, (h, fg) in enumerate([('', _MUTED), ('Pan', _CYAN), ('Tilt', _CYAN)]):
            tk.Label(outer, text=h, bg=_BG, fg=fg,
                     font=('Helvetica', 9, 'bold')).grid(row=1, column=col, padx=12, pady=2)

        self._servo_vars: Dict[str, Dict[str, tk.StringVar]] = {'pan': {}, 'tilt': {}}
        for row_i, (label, key) in enumerate([
            ('Min Angle',   'min_angle'),
            ('Max Angle',   'max_angle'),
            ('Center',      'center_angle'),
            ('Speed Limit', 'speed_limit'),
        ], 2):
            tk.Label(outer, text=label, bg=_BG, fg=_FG,
                     font=('Helvetica', 9)).grid(row=row_i, column=0, sticky='w', pady=4)
            for col_i, servo in enumerate(['pan', 'tilt'], 1):
                val = str(self._cfg.get('servos', {}).get(servo, {}).get(key, 0))
                var = tk.StringVar(value=val)
                self._servo_vars[servo][key] = var
                _ent(outer, var, width=9).grid(row=row_i, column=col_i, padx=12, pady=2)

        tk.Frame(outer, bg=_MUTED, height=1).grid(
            row=6, column=0, columnspan=3, sticky='ew', pady=10)
        tk.Label(outer, text="TEST SERVOS", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 8, 'bold')).grid(
            row=7, column=0, columnspan=3, sticky='w', pady=(0, 4))

        self._test_vars: Dict[str, tk.DoubleVar] = {}
        for row_i, servo in enumerate(['pan', 'tilt'], 8):
            center = float(self._cfg.get('servos', {}).get(servo, {}).get('center_angle', 90))
            tk.Label(outer, text=servo.capitalize(), bg=_BG, fg=_FG,
                     font=('Helvetica', 9)).grid(row=row_i, column=0, sticky='w', pady=6)
            var = tk.DoubleVar(value=center)
            self._test_vars[servo] = var
            def _cb(*_, s=servo, v=var):
                self._servos.set_servo(s, v.get())
            ttk.Scale(outer, from_=0, to=180, orient=tk.HORIZONTAL,
                      variable=var, length=320, command=_cb).grid(
                row=row_i, column=1, columnspan=2, padx=12, sticky='w')

        btn_row = tk.Frame(outer, bg=_BG)
        btn_row.grid(row=10, column=0, columnspan=3, sticky='w', pady=14)
        _btn(btn_row, "Center All", _AMBER, '#000', self._center_all).pack(side=tk.LEFT, padx=(0, 8))
        _btn(btn_row, "Save", _GREEN, '#000', self._save_servo_config).pack(side=tk.LEFT)

    # -----------------------------------------------------------------------
    # Settings — Servo Mapping tab
    # -----------------------------------------------------------------------

    def _build_servo_mapping_tab(self, parent):
        outer = tk.Frame(parent, bg=_BG)
        outer.pack(fill=tk.BOTH, expand=True, padx=12, pady=8)

        # ── Servo Definitions ───────────────────────────────────────────
        tk.Label(outer, text="SERVO DEFINITIONS", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 8, 'bold')).pack(anchor='w', pady=(0, 2))

        hdr = tk.Frame(outer, bg=_BG)
        hdr.pack(fill=tk.X)
        for text, w in [('Name', 10), ('Min°', 5), ('Max°', 5), ('Center°', 7), ('HW Ch', 5)]:
            tk.Label(hdr, text=text, bg=_BG, fg=_MUTED,
                     font=('Helvetica', 8), width=w, anchor='w').pack(side=tk.LEFT, padx=2)

        self._sm_servo_list = tk.Frame(outer, bg=_BG)
        self._sm_servo_list.pack(fill=tk.X)
        self._sm_servo_rows: list = []

        servos    = self._cfg.get('servos', {})
        ch_assign = self._cfg.get('hardware', {}).get('channel_assignments', {})
        for sname, scfg in servos.items():
            self._sm_add_servo_row(sname,
                                   scfg.get('min_angle', 0),
                                   scfg.get('max_angle', 180),
                                   scfg.get('center_angle', 90),
                                   ch_assign.get(sname, 0))

        _btn(outer, "+ Add Servo", _ACCENT, _CYAN,
             lambda: self._sm_add_servo_row()).pack(anchor='w', pady=(4, 2))

        tk.Frame(outer, bg=_MUTED, height=1).pack(fill=tk.X, pady=(4, 6))

        # ── Joint → Servo Assignment ─────────────────────────────────────
        tk.Label(outer, text="JOINT  →  SERVO ASSIGNMENT", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 8, 'bold')).pack(anchor='w', pady=(0, 4))

        servo_names = ['—'] + list(servos.keys())
        channels    = self._cfg.get('xlights', {}).get('channels', [])
        current_map = {ch.get('tracked_value', ''): ch.get('servo', '')
                       for ch in channels}

        cols = tk.Frame(outer, bg=_BG)
        cols.pack(fill=tk.X)
        face_col = tk.Frame(cols, bg=_BG)
        face_col.pack(side=tk.LEFT, fill=tk.Y, expand=True, padx=(0, 12))
        body_col = tk.Frame(cols, bg=_BG)
        body_col.pack(side=tk.LEFT, fill=tk.Y, expand=True)

        self._sm_joint_vars:   Dict[str, tk.StringVar] = {}
        self._sm_comboboxes:   list = []

        tk.Label(face_col, text="FACE", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 7, 'bold')).pack(anchor='w')
        for key, label in [
            ('head_yaw',            'Head Yaw'),
            ('head_pitch',          'Head Pitch'),
            ('head_roll',           'Head Roll'),
            ('mouth_open',          'Mouth'),
            ('left_eye_open',       'L Eye'),
            ('right_eye_open',      'R Eye'),
            ('left_eyebrow_raise',  'L Brow'),
            ('right_eyebrow_raise', 'R Brow'),
        ]:
            self._sm_joint_row(face_col, key, label, servo_names, current_map.get(key, ''))

        tk.Label(body_col, text="BODY", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 7, 'bold')).pack(anchor='w')
        for key, label in [
            ('torso_lean_lr',    'Torso L/R'),
            ('torso_lean_fb',    'Torso F/B'),
            ('torso_tilt',       'Torso Tilt'),
            ('left_arm_raise',   'L Arm'),
            ('right_arm_raise',  'R Arm'),
            ('left_elbow_bend',  'L Elbow'),
            ('right_elbow_bend', 'R Elbow'),
            ('left_wrist_raise', 'L Wrist'),
            ('right_wrist_raise','R Wrist'),
        ]:
            self._sm_joint_row(body_col, key, label, servo_names, current_map.get(key, ''))

        btn_row = tk.Frame(outer, bg=_BG)
        btn_row.pack(anchor='w', pady=(8, 0))
        _btn(btn_row, "Save Mapping", _GREEN, '#000',
             self._save_servo_mapping).pack(side=tk.LEFT, padx=(0, 10))
        tk.Label(btn_row,
                 text="After adding a servo, Save first — then re-open to assign it.",
                 bg=_BG, fg=_MUTED, font=('Helvetica', 7, 'italic')).pack(side=tk.LEFT)

    def _sm_add_servo_row(self, name='', min_a=0, max_a=180, center=90, hw_ch=0):
        row_data: dict = {}
        row = tk.Frame(self._sm_servo_list, bg=_BG)
        row.pack(fill=tk.X, pady=1)

        name_var = tk.StringVar(value=str(name))
        min_var  = tk.StringVar(value=str(int(min_a)))
        max_var  = tk.StringVar(value=str(int(max_a)))
        ctr_var  = tk.StringVar(value=str(int(center)))
        ch_var   = tk.StringVar(value=str(int(hw_ch)))

        _ent(row, name_var, width=10).pack(side=tk.LEFT, padx=2)
        _ent(row, min_var,  width=5).pack(side=tk.LEFT, padx=2)
        _ent(row, max_var,  width=5).pack(side=tk.LEFT, padx=2)
        _ent(row, ctr_var,  width=6).pack(side=tk.LEFT, padx=2)
        _ent(row, ch_var,   width=5).pack(side=tk.LEFT, padx=2)
        tk.Button(row, text="✕", bg=_PANEL, fg=_RED,
                  font=('Helvetica', 9, 'bold'), relief=tk.FLAT,
                  command=lambda r=row, d=row_data: self._sm_remove_servo_row(r, d)
                  ).pack(side=tk.LEFT, padx=4)

        row_data.update({'frame': row, 'name': name_var, 'min': min_var,
                         'max': max_var, 'center': ctr_var, 'channel': ch_var})
        self._sm_servo_rows.append(row_data)

    def _sm_remove_servo_row(self, frame, row_data):
        frame.destroy()
        self._sm_servo_rows = [r for r in self._sm_servo_rows if r is not row_data]

    def _sm_joint_row(self, parent, key, label, servo_names, current):
        row = tk.Frame(parent, bg=_BG)
        row.pack(fill=tk.X, pady=1)
        tk.Label(row, text=label, bg=_BG, fg=_FG,
                 font=('Helvetica', 8), width=10, anchor='w').pack(side=tk.LEFT)
        val = current if current in servo_names else '—'
        var = tk.StringVar(value=val)
        self._sm_joint_vars[key] = var
        cb = ttk.Combobox(row, textvariable=var, values=servo_names,
                          width=11, state='readonly', font=('Helvetica', 8))
        cb.pack(side=tk.LEFT, padx=4)
        self._sm_comboboxes.append(cb)

    def _save_servo_mapping(self):
        # 1. Rebuild servo config from definition rows
        new_servos   = {}
        new_channels = {}
        for rd in self._sm_servo_rows:
            name = rd['name'].get().strip()
            if not name:
                continue
            try:
                new_servos[name] = {
                    'min_angle':    float(rd['min'].get()),
                    'max_angle':    float(rd['max'].get()),
                    'center_angle': float(rd['center'].get()),
                    'speed_limit':  self._cfg.get('servos', {}).get(
                                        name, {}).get('speed_limit', 10),
                }
                new_channels[name] = int(rd['channel'].get())
            except ValueError:
                continue

        self._cfg['servos'] = new_servos
        self._cfg.setdefault('hardware', {})['channel_assignments'] = new_channels

        # Update live servo controller — add new servos to _angles at center
        self._servos._configs  = new_servos
        self._servos._channels = new_channels
        for name, scfg in new_servos.items():
            if name not in self._servos._angles:
                self._servos._angles[name] = scfg.get('center_angle', 90)

        # 2. Write joint → servo into xlights channels
        channels = self._cfg.get('xlights', {}).get('channels', [])
        for ch in channels:
            tv = ch.get('tracked_value', '')
            if tv not in self._sm_joint_vars:
                continue
            servo_nm = self._sm_joint_vars[tv].get().strip()
            if servo_nm and servo_nm != '—':
                ch['servo'] = servo_nm
            else:
                ch.pop('servo', None)

        # Refresh joint combobox options with updated servo names
        servo_names = ['—'] + list(new_servos.keys())
        for cb in self._sm_comboboxes:
            cb['values'] = servo_names

        self._capture_mode._cfg = self._cfg
        self._live_mode._cfg    = self._cfg
        self._write_config()
        self._status_var.set("Servo mapping saved.")

    # -----------------------------------------------------------------------
    # Settings — Tuning tab
    # -----------------------------------------------------------------------

    def _build_tuning_tab(self, parent):
        outer = tk.Frame(parent, bg=_BG)
        outer.pack(padx=24, pady=12, fill=tk.BOTH)

        lt = self._cfg.get('live_tracking', {})
        self._tracking_vars: Dict[str, tk.DoubleVar] = {}

        tk.Label(outer, text="LIVE FOLLOW", bg=_BG, fg=_CYAN,
                 font=('Helvetica', 8, 'bold')).grid(
            row=0, column=0, columnspan=4, sticky='w', pady=(0, 8))

        params = [
            ('Face Smoothing',
             'face_smoothing',
             lt.get('face_smoothing', 0.25), 0.05, 1.0,
             "Filters detector noise. Lower = smoother, more lag. Start around 0.2–0.3."),
            ('Deadzone (px)',
             'deadzone_px',
             lt.get('deadzone_px', 25), 0, 100,
             "Pixels from center ignored — prevents jitter on a still face."),
            ('Speed Limit — Pan (°/frame)',
             'pan_speed',
             self._cfg.get('servos', {}).get('pan', {}).get('speed_limit', 8), 1, 30,
             "Max degrees the pan servo can move per camera frame."),
            ('Speed Limit — Tilt (°/frame)',
             'tilt_speed',
             self._cfg.get('servos', {}).get('tilt', {}).get('speed_limit', 5), 1, 20,
             "Max degrees the tilt servo can move per camera frame."),
        ]
        for row_i, (label, key, default, lo, hi, tip) in enumerate(params, 1):
            tk.Label(outer, text=label, bg=_BG, fg=_FG,
                     font=('Helvetica', 9)).grid(row=row_i, column=0, sticky='w', pady=6)
            var = tk.DoubleVar(value=default)
            self._tracking_vars[key] = var
            ttk.Scale(outer, from_=lo, to=hi, orient=tk.HORIZONTAL,
                      variable=var, length=240).grid(row=row_i, column=1, padx=12)
            fmt = ".0f" if key == 'deadzone_px' else ".2f"
            val_lbl = tk.Label(outer, text=f"{default:{fmt}}", bg=_BG, fg=_CYAN,
                               font=('Courier', 9, 'bold'), width=6)
            val_lbl.grid(row=row_i, column=2, padx=4)
            var.trace_add('write',
                          lambda *_, lbl=val_lbl, v=var, f=fmt:
                              lbl.config(text=f"{v.get():{f}}"))
            tk.Label(outer, text=tip, bg=_BG, fg=_MUTED,
                     font=('Helvetica', 7, 'italic')).grid(
                row=row_i, column=3, padx=8, sticky='w')

        sep_row = len(params) + 1
        tk.Frame(outer, bg=_MUTED, height=1).grid(
            row=sep_row, column=0, columnspan=4, sticky='ew', pady=10)
        tk.Label(outer, text="PERFORMANCE CAPTURE", bg=_BG, fg=_MAGENTA,
                 font=('Helvetica', 8, 'bold')).grid(
            row=sep_row + 1, column=0, columnspan=4, sticky='w', pady=(0, 6))

        sm_default = self._cfg.get('motion_capture', {}).get('smoothing', 0.4)
        tk.Label(outer, text="Smoothing", bg=_BG, fg=_FG,
                 font=('Helvetica', 9)).grid(row=sep_row + 2, column=0, sticky='w', pady=6)
        sm_var = tk.DoubleVar(value=sm_default)
        self._tracking_vars['smoothing'] = sm_var
        ttk.Scale(outer, from_=0.0, to=1.0, orient=tk.HORIZONTAL,
                  variable=sm_var, length=240).grid(row=sep_row + 2, column=1, padx=12)
        sm_lbl = tk.Label(outer, text=f"{sm_default:.2f}", bg=_BG, fg=_CYAN,
                          font=('Courier', 9, 'bold'), width=6)
        sm_lbl.grid(row=sep_row + 2, column=2, padx=4)
        sm_var.trace_add('write', lambda *_: sm_lbl.config(text=f"{sm_var.get():.2f}"))
        tk.Label(outer, text="0=instant / 1=frozen. 0.4–0.6 gives smooth servo movement.",
                 bg=_BG, fg=_MUTED, font=('Helvetica', 7, 'italic')).grid(
            row=sep_row + 2, column=3, padx=8, sticky='w')

        _btn(outer, "Save", _GREEN, '#000', self._save_tracking_config).grid(
            row=sep_row + 4, column=0, sticky='w', pady=14)

    # -----------------------------------------------------------------------
    # Settings — Hardware tab
    # -----------------------------------------------------------------------

    def _build_hardware_tab(self, parent):
        outer = tk.Frame(parent, bg=_BG)
        outer.pack(padx=24, pady=12, fill=tk.BOTH)

        hw = self._cfg.get('hardware', {})
        self._hw_vars: Dict[str, tk.StringVar] = {}

        tk.Label(outer, text="HARDWARE BACKEND", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 8, 'bold')).grid(
            row=0, column=0, columnspan=2, sticky='w', pady=(0, 8))

        tk.Label(outer, text="Type", bg=_BG, fg=_FG,
                 font=('Helvetica', 9)).grid(row=1, column=0, sticky='w', pady=4)
        type_var = tk.StringVar(value=hw.get('type', 'mock'))
        self._hw_vars['type'] = type_var
        ttk.Combobox(outer, textvariable=type_var,
                     values=['mock', 'pca9685', 'gpio', 'serial'],
                     state='readonly', width=14,
                     font=('Helvetica', 9)).grid(row=1, column=1, sticky='w', padx=12, pady=4)

        sections = [
            ("PCA9685 (I2C)", [
                ('Address (hex)',   'pca9685_address',   _as_hex(hw.get('pca9685_address', 0x40))),
                ('Frequency (Hz)', 'pca9685_frequency',  str(hw.get('pca9685_frequency', 50))),
            ]),
            ("GPIO (BCM pins)", [
                ('Pan Pin',  'gpio_pan_pin',  str(hw.get('gpio_pan_pin', 17))),
                ('Tilt Pin', 'gpio_tilt_pin', str(hw.get('gpio_tilt_pin', 27))),
            ]),
            ("Serial / Arduino", [
                ('Port',  'serial_port', hw.get('serial_port', '/dev/ttyUSB0')),
                ('Baud',  'serial_baud', str(hw.get('serial_baud', 115200))),
            ]),
            ("Channel Assignments", [
                ('Pan Channel',  'ch_pan',  str(hw.get('channel_assignments', {}).get('pan', 0))),
                ('Tilt Channel', 'ch_tilt', str(hw.get('channel_assignments', {}).get('tilt', 1))),
            ]),
        ]
        row_i = 2
        for header, fields in sections:
            tk.Label(outer, text=header, bg=_BG, fg=_MUTED,
                     font=('Helvetica', 8, 'bold')).grid(
                row=row_i, column=0, columnspan=2, sticky='w', pady=(10, 2))
            row_i += 1
            for label, key, val in fields:
                tk.Label(outer, text=f"  {label}", bg=_BG, fg=_FG,
                         font=('Helvetica', 9)).grid(row=row_i, column=0, sticky='w', pady=2)
                var = tk.StringVar(value=val)
                self._hw_vars[key] = var
                _ent(outer, var, width=22).grid(row=row_i, column=1, sticky='w', padx=12, pady=2)
                row_i += 1

        tk.Label(outer, text="Use 'mock' on Windows. Switch to 'pca9685', 'gpio', or 'serial' on the Pi.",
                 bg=_BG, fg=_MUTED, font=('Helvetica', 8, 'italic')).grid(
            row=row_i, column=0, columnspan=2, sticky='w', pady=(10, 4))

        btn_row = tk.Frame(outer, bg=_BG)
        btn_row.grid(row=row_i + 1, column=0, columnspan=2, sticky='w', pady=8)
        _btn(btn_row, "Save & Apply", _GREEN, '#000', self._apply_hardware).pack(side=tk.LEFT)
        tk.Label(btn_row, text="  (restarts hardware backend)", bg=_BG, fg=_MUTED,
                 font=('Helvetica', 8)).pack(side=tk.LEFT)

    # -----------------------------------------------------------------------
    # Camera pipeline
    # -----------------------------------------------------------------------

    def _start_camera(self):
        self._running = True
        if not self._camera.start():
            messagebox.showerror("Camera Error",
                                  f"Cannot open camera index {self._camera.index}.\n"
                                  "Check config.yaml camera.index.")
            return
        self._tracker.start(self._camera.width, self._camera.height)
        self._cam_dot.config(fg=_CYAN)
        threading.Thread(target=self._cam_loop, daemon=True).start()
        self._tick()

    def _cam_loop(self):
        while self._running:
            ok, frame = self._camera.read()
            if not ok or frame is None:
                continue

            result = self._tracker.process(frame)

            if self._live_mode.active:
                self._live_mode.update(result)

            values  = self._capture_mode.update(result)
            display = self._tracker.draw_overlay(frame.copy(), result)

            if self._capture_mode.is_recording:
                cv2.putText(display, "● REC", (10, 58),
                            cv2.FONT_HERSHEY_SIMPLEX, 1.0, (30, 30, 220), 2)

            if self._q.full():
                try:
                    self._q.get_nowait()
                except queue.Empty:
                    pass
            try:
                self._q.put_nowait((display, result, values))
            except queue.Full:
                pass

    def _tick(self):
        try:
            frame, result, values = self._q.get_nowait()

            rgb   = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            rgb   = cv2.resize(rgb, (_CAM_W, _CAM_H), interpolation=cv2.INTER_LINEAR)
            photo = ImageTk.PhotoImage(image=Image.fromarray(rgb))
            self._photo = photo     # prevent GC
            self._camera_canvas.itemconfig(self._cam_img_id, image=photo)

            # Live Follow readouts (always update — cheap, avoids stale state)
            self._pan_disp.config(text=f"{self._servos.get_angle('pan'):.1f}°")
            self._tilt_disp.config(text=f"{self._servos.get_angle('tilt'):.1f}°")
            self._face_lbl.config(
                text="Face detected" if result.face_detected else "No face detected",
                fg=_GREEN if result.face_detected else _MUTED,
            )

            # Performance Capture readouts
            for key, var in self._tracked_vars.items():
                var.set(f"{values.get(key, 0.0):+.2f}")
            if self._capture_mode.is_recording:
                dur = self._capture_mode.duration
                m, s = divmod(dur, 60)
                self._rec_info.config(
                    text=f"{int(m):02d}:{s:04.1f}  |  {self._capture_mode.frame_count} frames",
                    fg=_MAGENTA,
                )

        except queue.Empty:
            pass

        if self._running:
            self._root.after(33, self._tick)

    # -----------------------------------------------------------------------
    # Live Follow callbacks
    # -----------------------------------------------------------------------

    def _toggle_live(self):
        if not self._live_mode.active:
            self._live_mode.start()
            self._live_btn.config(text="STOP LIVE FOLLOW", bg=_RED)
            self._status_var.set("Live Follow  —  tracking active")
        else:
            self._live_mode.stop()
            self._live_btn.config(text="START LIVE FOLLOW", bg=_CYAN)
            self._status_var.set("Live Follow  —  stopped")

    # -----------------------------------------------------------------------
    # Performance Capture callbacks
    # -----------------------------------------------------------------------

    def _toggle_rec(self):
        if not self._capture_mode.is_recording:
            self._capture_mode.start_recording()
            self._rec_btn.config(text="STOP RECORDING", bg=_MUTED)
            self._rec_info.config(fg=_MAGENTA)
            self._status_var.set("Performance Capture  —  recording...")
        else:
            self._capture_mode.stop_recording()
            self._rec_btn.config(text="RECORD", bg=_MAGENTA)
            n, dur = self._capture_mode.frame_count, self._capture_mode.duration
            self._status_var.set(
                f"Performance Capture  —  {n} frames  ({dur:.1f}s) recorded")

    def _export_fseq(self):
        frames = self._capture_mode.get_frames()
        if not frames:
            messagebox.showwarning("No Data", "Record a performance first.")
            return
        path = filedialog.asksaveasfilename(
            defaultextension=".fseq",
            filetypes=[("FSEQ Sequence", "*.fseq"), ("All Files", "*.*")],
            title="Export xLights FSEQ",
            initialfile="animatronic_sequence.fseq",
        )
        if not path:
            return
        try:
            ch_map  = self._cfg.get('xlights', {}).get('channels', [])
            step_ms = self._cfg.get('xlights', {}).get('step_time_ms', 50)
            nf, nch = export_fseq(frames, ch_map, step_ms, path)
            self._status_var.set(f"FSEQ exported — {nf} frames, {nch} channels")
            messagebox.showinfo("Export Complete",
                                f"Frames:    {nf}\n"
                                f"Channels:  {nch}\n"
                                f"Duration:  {nf * step_ms / 1000:.1f}s\n\n"
                                f"{path}")
        except Exception as exc:
            messagebox.showerror("Export Error", str(exc))

    # -----------------------------------------------------------------------
    # Session save / load / playback callbacks
    # -----------------------------------------------------------------------

    def _save_session(self):
        if not self._capture_mode.get_frames():
            messagebox.showwarning("No Data", "Record a performance first.")
            return
        path = filedialog.asksaveasfilename(
            defaultextension=".json",
            filetypes=[("Session File", "*.json"), ("All Files", "*.*")],
            title="Save Session",
            initialfile="session.json",
        )
        if not path:
            return
        try:
            self._capture_mode.save_session(path)
            self._status_var.set(
                f"Session saved — {self._capture_mode.frame_count} frames")
        except Exception as exc:
            messagebox.showerror("Save Error", str(exc))

    def _load_session(self):
        path = filedialog.askopenfilename(
            filetypes=[("Session File", "*.json"), ("All Files", "*.*")],
            title="Load Session",
        )
        if not path:
            return
        if self._capture_mode.load_session(path):
            n   = self._capture_mode.frame_count
            dur = self._capture_mode.duration
            m, s = divmod(dur, 60)
            self._rec_info.config(
                text=f"{int(m):02d}:{s:04.1f}  |  {n} frames", fg=_CYAN)
            self._pb_info.config(
                text=f"Loaded  {n} frames  ({dur:.1f}s)", fg=_CYAN)
            self._status_var.set(f"Session loaded — {n} frames  ({dur:.1f}s)")
        else:
            messagebox.showerror("Load Error", "Could not read session file.")

    def _toggle_playback(self):
        if not self._pb_playing:
            frames = self._capture_mode.get_frames()
            if not frames:
                messagebox.showwarning("No Data", "Record or load a session first.")
                return
            self._pb_stop_evt  = threading.Event()
            self._pb_pause_evt = threading.Event()
            self._pb_playing   = True
            self._pb_paused    = False
            self._pb_btn.config(text="⏸  PAUSE", bg=_AMBER)
            self._pb_stop_btn.config(bg=_RED)
            threading.Thread(
                target=self._playback_loop,
                args=(frames, self._pb_stop_evt, self._pb_pause_evt),
                daemon=True,
            ).start()
        elif self._pb_paused:
            self._pb_pause_evt.clear()
            self._pb_paused = False
            self._pb_btn.config(text="⏸  PAUSE", bg=_AMBER)
        else:
            self._pb_pause_evt.set()
            self._pb_paused = True
            self._pb_btn.config(text="▶  RESUME", bg=_GREEN)

    def _stop_playback(self):
        if self._pb_stop_evt:
            self._pb_stop_evt.set()
        if self._pb_pause_evt:
            self._pb_pause_evt.clear()

    def _playback_loop(self, frames, stop_evt, pause_evt):
        import time as _time
        start      = _time.time()
        pause_since: Optional[float] = None
        total      = len(frames)

        for i, frame in enumerate(frames):
            if stop_evt.is_set():
                break

            # Pause handling — adjust start to absorb pause duration
            while pause_evt.is_set() and not stop_evt.is_set():
                if pause_since is None:
                    pause_since = _time.time()
                _time.sleep(0.02)
            if pause_since is not None:
                start += _time.time() - pause_since
                pause_since = None

            if stop_evt.is_set():
                break

            # Accurate frame timing
            target = start + frame.timestamp
            wait   = target - _time.time()
            if wait > 0:
                _time.sleep(wait)

            if stop_evt.is_set():
                break

            self._capture_mode.play_frame(frame.values)
            self._root.after(0, self._on_playback_frame, i, total, frame.timestamp)

        self._root.after(0, self._on_playback_done)

    def _on_playback_frame(self, idx: int, total: int, ts: float):
        m, s = divmod(ts, 60)
        dur  = self._capture_mode.duration
        dm, ds = divmod(dur, 60)
        self._pb_info.config(
            text=f"{int(m):02d}:{s:04.1f} / {int(dm):02d}:{ds:04.1f}  ({idx+1}/{total})",
            fg=_GREEN,
        )
        for key, var in self._tracked_vars.items():
            var.set(f"{self._capture_mode.get_frames()[idx].values.get(key, 0.0):+.2f}")

    def _on_playback_done(self):
        self._pb_playing = False
        self._pb_paused  = False
        self._pb_btn.config(text="▶  PLAY", bg=_GREEN)
        self._pb_stop_btn.config(bg=_MUTED)
        self._pb_info.config(text="Playback complete", fg=_MUTED)
        self._status_var.set("Performance Capture  —  playback complete")

    # -----------------------------------------------------------------------
    # Servos tab callbacks
    # -----------------------------------------------------------------------

    def _center_all(self):
        self._servos.center_all()
        for servo, var in self._test_vars.items():
            center = self._cfg.get('servos', {}).get(servo, {}).get('center_angle', 90)
            var.set(float(center))
        self._status_var.set("Servos centered.")

    def _save_servo_config(self):
        servos = self._cfg.setdefault('servos', {})
        for servo in ('pan', 'tilt'):
            s = servos.setdefault(servo, {})
            for key, var in self._servo_vars[servo].items():
                try:
                    s[key] = float(var.get())
                except ValueError:
                    pass
        self._servos._configs   = self._cfg['servos']
        self._live_mode._cfg    = self._cfg
        self._capture_mode._cfg = self._cfg
        self._write_config()
        self._status_var.set("Servo config saved.")

    # -----------------------------------------------------------------------
    # Tuning tab callbacks
    # -----------------------------------------------------------------------

    def _save_tracking_config(self):
        lt = self._cfg.setdefault('live_tracking', {})
        lt['face_smoothing'] = round(self._tracking_vars['face_smoothing'].get(), 3)
        lt['deadzone_px'] = int(round(self._tracking_vars['deadzone_px'].get()))
        self._cfg.setdefault('servos', {}).setdefault('pan',  {})['speed_limit'] = \
            round(self._tracking_vars['pan_speed'].get(), 1)
        self._cfg.setdefault('servos', {}).setdefault('tilt', {})['speed_limit'] = \
            round(self._tracking_vars['tilt_speed'].get(), 1)
        self._cfg.setdefault('motion_capture', {})['smoothing'] = \
            round(self._tracking_vars['smoothing'].get(), 3)
        self._live_mode._cfg          = self._cfg
        self._capture_mode._cfg       = self._cfg
        self._capture_mode._smoothing = self._cfg['motion_capture']['smoothing']
        self._write_config()
        self._status_var.set("Tuning config saved.")

    # -----------------------------------------------------------------------
    # Hardware tab callbacks
    # -----------------------------------------------------------------------

    def _apply_hardware(self):
        hw = self._cfg.setdefault('hardware', {})
        hw['type']              = self._hw_vars['type'].get()
        hw['pca9685_address']   = self._hw_vars['pca9685_address'].get()
        hw['pca9685_frequency'] = int(self._hw_vars['pca9685_frequency'].get())
        hw['gpio_pan_pin']      = int(self._hw_vars['gpio_pan_pin'].get())
        hw['gpio_tilt_pin']     = int(self._hw_vars['gpio_tilt_pin'].get())
        hw['serial_port']       = self._hw_vars['serial_port'].get()
        hw['serial_baud']       = int(self._hw_vars['serial_baud'].get())
        ch = hw.setdefault('channel_assignments', {})
        ch['pan']  = int(self._hw_vars['ch_pan'].get())
        ch['tilt'] = int(self._hw_vars['ch_tilt'].get())
        self._write_config()

        was_tracking = self._live_mode.active
        if was_tracking:
            self._live_mode.stop()

        try:
            old_backend = self._backend
            self._backend = create_backend(hw)
            self._servos._backend  = self._backend
            self._servos._channels = ch
            try:
                old_backend.close()
            except Exception:
                pass
            self._hw_badge.config(text=f"hw: {hw['type']}", fg=_CYAN)
            self._status_var.set(f"Hardware backend switched to: {hw['type']}")
            if was_tracking:
                self._live_mode.start()
        except Exception as exc:
            messagebox.showerror(
                "Hardware Error",
                f"Could not initialize '{hw['type']}' backend:\n{exc}\n\nFalling back to mock.",
            )
            self._backend = __import__('core.servo_controller',
                                       fromlist=['MockServoBackend']).MockServoBackend()
            self._servos._backend = self._backend
            hw['type'] = 'mock'
            self._hw_badge.config(text="hw: mock (fallback)", fg=_AMBER)

    # -----------------------------------------------------------------------
    # Config persistence
    # -----------------------------------------------------------------------

    def _write_config(self):
        try:
            with open(self._cfg_path, 'w') as f:
                yaml.dump(self._cfg, f, default_flow_style=False, sort_keys=False)
        except OSError as exc:
            messagebox.showerror("Save Error", f"Could not write config:\n{exc}")

    # -----------------------------------------------------------------------
    # Lifecycle
    # -----------------------------------------------------------------------

    def run(self):
        self._root.protocol("WM_DELETE_WINDOW", self._shutdown)
        self._root.mainloop()

    def _shutdown(self):
        self._running = False
        self._live_mode.stop()
        if self._capture_mode.is_recording:
            self._capture_mode.stop_recording()
        self._stop_playback()
        self._camera.stop()
        self._tracker.stop()
        self._servos.close()
        self._root.destroy()
