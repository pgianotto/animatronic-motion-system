"""Waveform scrub-review timeline window.

Open via AnimatronicApp._open_review().
Public API:
  refresh()           — reload frames and redraw (call after session changes)
  update_cursor(idx)  — advance playhead (called by app during playback)
  show()              — deiconify / raise
  exists              — True if the underlying Toplevel is alive
"""

import tkinter as tk
from tkinter import ttk
from typing import Callable, Dict, List, Optional, Set, Tuple

from modes.motion_capture import CaptureFrame

# ── Palette (matches app.py) ──────────────────────────────────────────────────
_BG     = '#1a1a2e'
_PANEL  = '#16213e'
_ACCENT = '#0f3460'
_CYAN   = '#4cc9f0'
_GREEN  = '#06d6a0'
_AMBER  = '#fb8500'
_RED    = '#e63946'
_FG     = '#e0e0e0'
_MUTED  = '#888888'
_DARK   = '#0d0d1f'

# ── Layout ────────────────────────────────────────────────────────────────────
_LABEL_W = 120   # left column width (checkbox + name)
_VAL_W   = 52    # right column width (value readout at cursor)
_ROW_H   = 32    # height per channel row
_RULER_H = 22    # time ruler at top of canvas

# ── Channel registry ──────────────────────────────────────────────────────────
# key → (display label, waveform colour)
_CH_INFO: Dict[str, Tuple[str, str]] = {
    'head_yaw':            ('Head Yaw',    _CYAN),
    'head_pitch':          ('Head Pitch',  _CYAN),
    'head_roll':           ('Head Roll',   _CYAN),
    'mouth_open':          ('Mouth',       _CYAN),
    'left_eye_open':       ('L Eye',       _CYAN),
    'right_eye_open':      ('R Eye',       _CYAN),
    'left_eyebrow_raise':  ('L Eyebrow',   _CYAN),
    'right_eyebrow_raise': ('R Eyebrow',   _CYAN),
    'face_center_x':       ('Face X',      _CYAN),
    'face_center_y':       ('Face Y',      _CYAN),
    'torso_lean_lr':       ('Torso L/R',   _AMBER),
    'torso_lean_fb':       ('Torso F/B',   _AMBER),
    'torso_tilt':          ('Torso Tilt',  _AMBER),
    'left_arm_raise':      ('L Arm',       _AMBER),
    'right_arm_raise':     ('R Arm',       _AMBER),
    'left_elbow_bend':     ('L Elbow',     _AMBER),
    'right_elbow_bend':    ('R Elbow',     _AMBER),
    'left_wrist_raise':    ('L Wrist',     _AMBER),
    'right_wrist_raise':   ('R Wrist',     _AMBER),
}
_CH_ORDER = list(_CH_INFO.keys())


class ReviewWindow:
    """Waveform timeline scrub-review for a captured motion session."""

    def __init__(
        self,
        parent: tk.Tk,
        get_frames:    Callable[[], List[CaptureFrame]],
        get_config:    Callable[[], dict],
        on_seek_servo: Callable[[dict], None],
        on_play_from:  Callable[[int], None],
        on_stop:       Callable[[], None],
    ):
        self._get_frames    = get_frames
        self._get_config    = get_config
        self._on_seek_servo = on_seek_servo
        self._on_play_from  = on_play_from
        self._on_stop       = on_stop

        self._frames: List[CaptureFrame]              = []
        self._ch_ranges: Dict[str, Tuple[float,float]] = {}
        self._servo_mapped: Set[str]                   = set()
        self._cursor_idx   = 0
        self._scrub_busy   = False   # prevents recursive scrub callbacks

        self._filter_var = tk.StringVar(value='all')
        self._checked: Dict[str, bool] = {k: True for k in _CH_ORDER}

        self._win = tk.Toplevel(parent)
        self._win.title("Scrub Review — Timeline")
        self._win.geometry("980x620")
        self._win.configure(bg=_BG)
        self._win.minsize(720, 430)
        self._win.protocol("WM_DELETE_WINDOW", self._win.withdraw)

        self._build_ui()
        self.refresh()

    # ------------------------------------------------------------------ #
    # Public API                                                           #
    # ------------------------------------------------------------------ #

    def refresh(self):
        """Reload session data and redraw everything."""
        self._frames = self._get_frames()
        cfg = self._get_config()
        self._servo_mapped = {
            ch['tracked_value']
            for ch in cfg.get('xlights', {}).get('channels', [])
            if ch.get('servo')
        }
        self._ch_ranges = {}
        for key in _CH_ORDER:
            vals = [f.values[key] for f in self._frames if key in f.values]
            if vals:
                lo, hi = min(vals), max(vals)
                if hi - lo < 1e-4:
                    lo -= 0.5; hi += 0.5
                self._ch_ranges[key] = (lo, hi)

        n = len(self._frames)
        self._cursor_idx = 0
        self._scrub_busy = True
        self._scrub.config(to=max(1, n - 1))
        self._scrub_var.set(0)
        self._scrub_busy = False
        dur_str = f"{self._frames[-1].timestamp:.1f}s" if n else ""
        self._info_lbl.config(text=f"{n} frames  {dur_str}" if n else "No session loaded")
        self._win.after_idle(self._full_draw)

    def update_cursor(self, idx: int):
        """Advance playhead — called by main app during playback."""
        self._cursor_idx = max(0, min(idx, len(self._frames) - 1))
        self._scrub_busy = True
        self._scrub_var.set(self._cursor_idx)
        self._scrub_busy = False
        self._update_time_label()
        self._redraw_playhead()

    def show(self):
        self._win.deiconify()
        self._win.lift()

    @property
    def exists(self) -> bool:
        try:
            return bool(self._win.winfo_exists())
        except tk.TclError:
            return False

    # ------------------------------------------------------------------ #
    # UI construction                                                      #
    # ------------------------------------------------------------------ #

    def _build_ui(self):
        # ── Header ────────────────────────────────────────────────────
        hdr = tk.Frame(self._win, bg=_ACCENT, height=30)
        hdr.pack(fill=tk.X)
        hdr.pack_propagate(False)
        tk.Label(hdr, text="SCRUB REVIEW", bg=_ACCENT, fg=_FG,
                 font=('Helvetica', 10, 'bold')).pack(side=tk.LEFT, padx=10)
        self._info_lbl = tk.Label(hdr, text="", bg=_ACCENT, fg=_MUTED,
                                   font=('Helvetica', 8))
        self._info_lbl.pack(side=tk.LEFT, padx=6)

        # ── Filter bar ────────────────────────────────────────────────
        fbar = tk.Frame(self._win, bg=_PANEL, height=28)
        fbar.pack(fill=tk.X)
        fbar.pack_propagate(False)
        tk.Label(fbar, text="SHOW:", bg=_PANEL, fg=_MUTED,
                 font=('Helvetica', 8, 'bold')).pack(side=tk.LEFT, padx=(10, 4))
        for val, label in [
            ('all',    'All'),
            ('servo',  'Servo-mapped'),
            ('select', 'Custom select'),
        ]:
            tk.Radiobutton(
                fbar, text=label, variable=self._filter_var, value=val,
                bg=_PANEL, fg=_FG, activebackground=_PANEL, activeforeground=_CYAN,
                selectcolor=_ACCENT, font=('Helvetica', 9),
                command=self._on_filter_change,
            ).pack(side=tk.LEFT, padx=4)
        tk.Label(fbar, text="— click channel label to toggle in custom mode",
                 bg=_PANEL, fg=_MUTED, font=('Helvetica', 7, 'italic')).pack(
            side=tk.LEFT, padx=8)

        # ── Canvas + scrollbar ────────────────────────────────────────
        area = tk.Frame(self._win, bg=_BG)
        area.pack(fill=tk.BOTH, expand=True, padx=2, pady=(2, 0))

        vscroll = tk.Scrollbar(area, orient=tk.VERTICAL, bg=_PANEL,
                               troughcolor=_DARK, relief=tk.FLAT)
        vscroll.pack(side=tk.RIGHT, fill=tk.Y)

        self._canvas = tk.Canvas(area, bg=_BG, highlightthickness=0,
                                  yscrollcommand=vscroll.set)
        self._canvas.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        vscroll.config(command=self._canvas.yview)

        self._canvas.bind('<Configure>',   lambda e: self._win.after_idle(self._full_draw))
        self._canvas.bind('<Button-1>',    self._on_canvas_click)
        self._canvas.bind('<B1-Motion>',   self._on_canvas_drag)
        self._canvas.bind('<MouseWheel>',  self._on_mousewheel)

        # ── Bottom controls ────────────────────────────────────────────
        bot = tk.Frame(self._win, bg=_DARK)
        bot.pack(fill=tk.X, side=tk.BOTTOM)

        scrub_row = tk.Frame(bot, bg=_DARK)
        scrub_row.pack(fill=tk.X, padx=8, pady=(4, 0))
        self._scrub_var = tk.IntVar(value=0)
        self._scrub = ttk.Scale(scrub_row, from_=0, to=1,
                                 orient=tk.HORIZONTAL, variable=self._scrub_var,
                                 command=self._on_scrub)
        self._scrub.pack(fill=tk.X)

        tr = tk.Frame(bot, bg=_DARK)
        tr.pack(fill=tk.X, padx=8, pady=(2, 6))

        self._time_lbl = tk.Label(tr, text="00:00.0 / 00:00.0",
                                   bg=_DARK, fg=_FG, font=('Courier', 9, 'bold'))
        self._time_lbl.pack(side=tk.LEFT)
        self._frame_lbl = tk.Label(tr, text="frame 0/0",
                                    bg=_DARK, fg=_MUTED, font=('Courier', 9))
        self._frame_lbl.pack(side=tk.LEFT, padx=10)

        def _tb(parent, text, bg, fg, cmd):
            return tk.Button(parent, text=text, bg=bg, fg=fg,
                             font=('Helvetica', 9, 'bold'), relief=tk.FLAT,
                             padx=8, pady=3, activebackground=bg, activeforeground=fg,
                             command=cmd)

        _tb(tr, "END ▶▶",   _ACCENT, _FG,   lambda: self._seek(len(self._frames) - 1)).pack(side=tk.RIGHT, padx=2)
        _tb(tr, "+1 ▶",     _ACCENT, _FG,   lambda: self._seek(self._cursor_idx + 1)).pack(side=tk.RIGHT, padx=2)
        _tb(tr, "■  STOP",  _RED,    '#fff', self._stop).pack(side=tk.RIGHT, padx=2)
        self._play_btn = _tb(tr, "▶  PLAY", _GREEN, '#000', self._play)
        self._play_btn.pack(side=tk.RIGHT, padx=2)
        _tb(tr, "◀  -1",    _ACCENT, _FG,   lambda: self._seek(self._cursor_idx - 1)).pack(side=tk.RIGHT, padx=2)
        _tb(tr, "◀◀  START", _ACCENT, _FG,  lambda: self._seek(0)).pack(side=tk.RIGHT, padx=2)

    # ------------------------------------------------------------------ #
    # Channel visibility                                                   #
    # ------------------------------------------------------------------ #

    def _visible(self) -> List[str]:
        mode = self._filter_var.get()
        if mode == 'servo':
            return [k for k in _CH_ORDER if k in self._servo_mapped  and k in self._ch_ranges]
        if mode == 'select':
            return [k for k in _CH_ORDER if self._checked.get(k, True) and k in self._ch_ranges]
        return [k for k in _CH_ORDER if k in self._ch_ranges]

    # ------------------------------------------------------------------ #
    # Drawing                                                              #
    # ------------------------------------------------------------------ #

    def _full_draw(self):
        c   = self._canvas
        vis = self._visible()
        cw  = max(c.winfo_width(), 1)

        c.delete('all')

        if not self._frames or not vis:
            msg = "No session loaded" if not self._frames else "No channels selected"
            c.create_text(cw // 2, 60, text=msg, fill=_MUTED, font=('Helvetica', 11))
            c.config(scrollregion=(0, 0, cw, 120))
            return

        n         = len(self._frames)
        total_dur = self._frames[-1].timestamp or 1.0
        total_h   = _RULER_H + len(vis) * _ROW_H
        c.config(scrollregion=(0, 0, cw, total_h))

        wave_x = _LABEL_W
        wave_w = max(cw - _LABEL_W - _VAL_W, 1)

        # ── Time ruler ────────────────────────────────────────────────
        c.create_rectangle(0, 0, cw, _RULER_H, fill='#0a0a20', outline='')
        c.create_line(wave_x, 0, wave_x, _RULER_H, fill=_MUTED, width=1)

        # Pick tick interval that gives 6-12 marks
        tick = 0.1
        for mag in (0.1, 0.5, 1, 2, 5, 10, 30, 60):
            if total_dur / mag <= 12:
                tick = mag
                break

        t = 0.0
        while t <= total_dur + tick * 0.01:
            rx = wave_x + (t / total_dur) * wave_w
            c.create_line(rx, _RULER_H - 5, rx, _RULER_H, fill=_MUTED, width=1)
            mm, ss = divmod(t, 60)
            c.create_text(rx, _RULER_H // 2,
                          text=f"{int(mm)}:{ss:04.1f}",
                          fill=_MUTED, font=('Helvetica', 7), anchor='c')
            t += tick

        # ── Channel rows + waveforms ──────────────────────────────────
        for ri, key in enumerate(vis):
            ry     = _RULER_H + ri * _ROW_H
            row_bg = _PANEL if ri % 2 == 0 else _BG
            label, color = _CH_INFO[key]
            mapped  = key in self._servo_mapped
            checked = self._checked.get(key, True)

            # Row background
            c.create_rectangle(0, ry, cw, ry + _ROW_H - 1, fill=row_bg, outline='')

            # Servo-mapped left edge bar
            if mapped:
                c.create_rectangle(0, ry, 4, ry + _ROW_H - 1, fill=color, outline='')

            # Checkbox indicator (click-toggleable in custom-select mode)
            bx, by = 14, ry + _ROW_H // 2
            box_fill = _ACCENT if checked else row_bg
            c.create_rectangle(bx - 6, by - 6, bx + 6, by + 6,
                                outline=color, fill=box_fill, width=1,
                                tags=('checkbox', f'cb_{key}'))
            if checked:
                c.create_line(bx - 3, by, bx, by + 3, bx + 4, by - 3,
                               fill=color, width=2, tags=('checkbox', f'cb_{key}'))

            # Channel label
            lbl_color = color if (mapped or checked) else _MUTED
            lbl_font  = ('Helvetica', 8, 'bold') if mapped else ('Helvetica', 8)
            c.create_text(wave_x - 6, ry + _ROW_H // 2,
                          text=label, fill=lbl_color, font=lbl_font,
                          anchor='e', tags=(f'lbl_{key}',))

            # Column dividers
            c.create_line(wave_x, ry, wave_x, ry + _ROW_H, fill=_MUTED, width=1)
            c.create_line(0, ry + _ROW_H - 1, cw, ry + _ROW_H - 1,
                          fill='#1e1e40', width=1)

            # ── Waveform ──────────────────────────────────────────────
            lo, hi = self._ch_ranges.get(key, (-1.0, 1.0))
            span   = hi - lo or 1.0
            pad    = 4

            def _yv(v, ry=ry, lo=lo, span=span):
                t = max(0.0, min(1.0, (v - lo) / span))
                return ry + _ROW_H - pad - t * (_ROW_H - 2 * pad)

            # Zero line
            if lo <= 0.0 <= hi:
                c.create_line(wave_x, _yv(0.0), wave_x + wave_w, _yv(0.0),
                              fill='#2a2a50', width=1, dash=(3, 4))

            # Downsample so we target at most 400 points per channel
            step = max(1, n // 400)
            pts  = []
            for fi in range(0, n, step):
                f = self._frames[fi]
                x = wave_x + (f.timestamp / total_dur) * wave_w
                y = _yv(f.values.get(key, 0.0))
                pts += [x, y]
            # Always include last frame
            fl = self._frames[-1]
            pts += [wave_x + wave_w, _yv(fl.values.get(key, 0.0))]

            if len(pts) >= 4:
                c.create_line(pts, fill=color, width=1, smooth=False)

        # ── Playhead ──────────────────────────────────────────────────
        self._draw_playhead(vis, cw, wave_x, wave_w, total_dur)
        self._update_time_label()

    def _draw_playhead(
        self,
        vis: List[str],
        cw: int,
        wave_x: int,
        wave_w: int,
        total_dur: float,
    ):
        c   = self._canvas
        idx = max(0, min(self._cursor_idx, len(self._frames) - 1))
        ts  = self._frames[idx].timestamp
        x   = wave_x + (ts / total_dur) * wave_w if total_dur > 1e-6 else wave_x
        total_h = _RULER_H + len(vis) * _ROW_H

        c.create_line(x, 0, x, total_h, fill=_RED, width=2, tags='playhead')
        c.create_polygon(x - 6, 0, x + 6, 0, x, 10,
                         fill=_RED, outline='', tags='playhead')

        cur = self._frames[idx]
        val_x = cw - _VAL_W // 2   # centre of value column

        for ri, key in enumerate(vis):
            ry  = _RULER_H + ri * _ROW_H
            lo, hi = self._ch_ranges.get(key, (-1.0, 1.0))
            span   = hi - lo or 1.0
            v      = cur.values.get(key, 0.0)
            t      = max(0.0, min(1.0, (v - lo) / span))
            py     = ry + _ROW_H - 4 - t * (_ROW_H - 8)
            _, color = _CH_INFO[key]

            c.create_oval(x - 3, py - 3, x + 3, py + 3,
                          fill=_RED, outline='', tags='playhead')
            c.create_text(val_x, ry + _ROW_H // 2,
                          text=f"{v:+.2f}",
                          fill=color, font=('Courier', 7, 'bold'),
                          anchor='c', tags='playhead')

    def _redraw_playhead(self):
        vis = self._visible()
        if not vis or not self._frames:
            return
        cw       = max(self._canvas.winfo_width(), 1)
        wave_x   = _LABEL_W
        wave_w   = max(cw - _LABEL_W - _VAL_W, 1)
        total_dur = self._frames[-1].timestamp or 1.0
        self._canvas.delete('playhead')
        self._draw_playhead(vis, cw, wave_x, wave_w, total_dur)

    # ------------------------------------------------------------------ #
    # Seek / cursor                                                        #
    # ------------------------------------------------------------------ #

    def _seek(self, idx: int):
        if not self._frames:
            return
        idx = max(0, min(idx, len(self._frames) - 1))
        self._cursor_idx = idx
        self._scrub_busy = True
        self._scrub_var.set(idx)
        self._scrub_busy = False
        self._on_seek_servo(self._frames[idx].values)
        self._update_time_label()
        self._redraw_playhead()

    def _update_time_label(self):
        n = len(self._frames)
        if not n:
            self._time_lbl.config(text="00:00.0 / 00:00.0")
            self._frame_lbl.config(text="frame 0/0")
            return
        idx    = max(0, min(self._cursor_idx, n - 1))
        ts     = self._frames[idx].timestamp
        dur    = self._frames[-1].timestamp
        m,  s  = divmod(ts,  60)
        dm, ds = divmod(dur, 60)
        self._time_lbl.config(text=f"{int(m):02d}:{s:04.1f} / {int(dm):02d}:{ds:04.1f}")
        self._frame_lbl.config(text=f"frame {idx + 1}/{n}")

    # ------------------------------------------------------------------ #
    # Canvas events                                                        #
    # ------------------------------------------------------------------ #

    def _on_canvas_click(self, event):
        cx = self._canvas.canvasx(event.x)
        cy = self._canvas.canvasy(event.y)

        if cx < _LABEL_W:
            # Label column — toggle checkbox
            row_i = int((cy - _RULER_H) // _ROW_H)
            vis   = self._visible()
            if 0 <= row_i < len(vis):
                key = vis[row_i]
                self._checked[key] = not self._checked.get(key, True)
                if self._filter_var.get() != 'select':
                    # Sync checkboxes to current visibility, then switch to select
                    for k in _CH_ORDER:
                        self._checked[k] = k in self._ch_ranges
                    self._checked[key] = not self._checked.get(key, True)
                    self._filter_var.set('select')
                self._win.after_idle(self._full_draw)
            return

        # Waveform area — seek
        self._canvas_seek(cx)

    def _on_canvas_drag(self, event):
        cx = self._canvas.canvasx(event.x)
        if cx >= _LABEL_W:
            self._canvas_seek(cx)

    def _canvas_seek(self, canvas_x: float):
        if not self._frames:
            return
        cw        = max(self._canvas.winfo_width(), 1)
        wave_w    = max(cw - _LABEL_W - _VAL_W, 1)
        total_dur = self._frames[-1].timestamp or 1.0
        t_frac    = (canvas_x - _LABEL_W) / wave_w
        t_frac    = max(0.0, min(1.0, t_frac))
        target    = t_frac * total_dur
        # Binary-search closest frame by timestamp
        lo, hi = 0, len(self._frames) - 1
        while lo < hi:
            mid = (lo + hi) // 2
            if self._frames[mid].timestamp < target:
                lo = mid + 1
            else:
                hi = mid
        self._seek(lo)

    def _on_scrub(self, val: str):
        if self._scrub_busy or not self._frames:
            return
        idx = int(float(val))
        idx = max(0, min(idx, len(self._frames) - 1))
        self._cursor_idx = idx
        self._on_seek_servo(self._frames[idx].values)
        self._update_time_label()
        self._redraw_playhead()

    def _on_mousewheel(self, event):
        self._canvas.yview_scroll(-1 if event.delta > 0 else 1, 'units')

    def _on_filter_change(self):
        mode = self._filter_var.get()
        if mode == 'all':
            for k in _CH_ORDER:
                self._checked[k] = True
        elif mode == 'servo':
            for k in _CH_ORDER:
                self._checked[k] = k in self._servo_mapped
        self._win.after_idle(self._full_draw)

    # ------------------------------------------------------------------ #
    # Transport                                                            #
    # ------------------------------------------------------------------ #

    def _play(self):
        if self._frames:
            self._on_play_from(self._cursor_idx)

    def _stop(self):
        self._on_stop()
