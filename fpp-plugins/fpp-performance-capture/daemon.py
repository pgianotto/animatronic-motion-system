"""FPP Performance Capture daemon — port 5002.

Handles recording, session save/load, playback, and FSEQ export directly
into FPP's sequences folder so files appear in FPP's scheduler immediately.
"""

import bisect
import http.client
import json
import queue
import os
import re
import signal
import subprocess
import sys
import threading
import time
from pathlib import Path

PLUGIN_DIR   = Path(__file__).parent
LIB_DIR      = PLUGIN_DIR / 'lib'
PROJECT_ROOT = PLUGIN_DIR.parent.parent

for search in (LIB_DIR, PROJECT_ROOT):
    if (search / 'core').exists() and str(search) not in sys.path:
        sys.path.insert(0, str(search))

import cv2
import yaml
from flask import Flask, Response, jsonify, request, send_file

from core.camera import Camera
from core.servo_controller import ServoController, create_backend
from core.tracker import Tracker
from modes.motion_capture import MotionCaptureMode

CFG_PATH      = Path('/home/fpp/media/config/animatronic_capture.json')
FSEQ_DIR  = Path('/home/fpp/media/sequences')
SESS_DIR  = Path('/home/fpp/media/animations')
MEDIA_DIR = Path('/home/fpp/media/music')
PORT      = 5002


def _tee_stdio_to_fpp_log():
    """Mirror stdout/stderr into FPP's log dir so this service's output shows
    up in FPP's log viewer and Support Zip, not just wherever systemd sends it."""
    log_dir = '/home/fpp/media/logs'
    try:
        conn = http.client.HTTPConnection('localhost', 80, timeout=2)
        conn.request('GET', '/api/settings/logDirectory')
        resp = conn.getresponse()
        val = json.loads(resp.read())
        conn.close()
        if isinstance(val, dict):
            val = val.get('value') or val.get('logDirectory')
        if isinstance(val, str) and val:
            log_dir = val
    except Exception:
        pass

    class _Tee:
        def __init__(self, *streams):
            self._streams = streams

        def write(self, data):
            for s in self._streams:
                s.write(data)

        def flush(self):
            for s in self._streams:
                s.flush()

    try:
        log_fh = open(Path(log_dir) / 'plugin-fpp-performance-capture.log', 'a', buffering=1)
        sys.stdout = _Tee(sys.stdout, log_fh)
        sys.stderr = _Tee(sys.stderr, log_fh)
    except Exception:
        pass


_tee_stdio_to_fpp_log()

DEFAULTS = {
    'smoothing':          0.15,  # joint value smoothing (lower = smoother/slower)
    'servo_smoothing':    0.25,  # servo µs output smoothing (lower = smoother/slower)
    'step_time_ms':       50,
    'camera_index':       0,
    'camera_width':       640,
    'camera_height':      480,
    'hardware_type':      'mock',
    'pca9685_address':    '0x40',
    'pca9685_frequency':  50,
    'channel_pan':        0,
    'channel_tilt':       1,
    'joint_map':          {},   # {joint_key: {port, invert, scale}}
    'pca_output_idx':     0,    # which output from co-other.json to drive
    'live_output':        False, # drive servos in real time when True
}

# Normalization bounds (lo, hi) → t=0.0–1.0 across the joint's natural range
NORM_RANGE = {
    'head_yaw':            (-45, 45),
    'head_pitch':          (-40, 40),
    'head_roll':           (-30, 30),
    'mouth_open':          (0, 1),
    'left_eye_open':       (0, 1),
    'right_eye_open':      (0, 1),
    'left_eyebrow_raise':  (0, 1),
    'right_eyebrow_raise': (0, 1),
    'face_center_x':       (0, 1),
    'face_center_y':       (0, 1),
    'torso_lean_lr':       (-1, 1),
    'torso_lean_fb':       (-1, 1),
    'torso_tilt':          (-1, 1),
    'left_arm_raise':      (0, 1),
    'right_arm_raise':     (0, 1),
    'left_elbow_bend':     (0, 1),
    'right_elbow_bend':    (0, 1),
    'left_wrist_raise':    (0, 1),
    'right_wrist_raise':   (0, 1),
}


def _interp_val(t: float, timestamps: list, values: list) -> float:
    """Linear interpolation into a (timestamps, values) series; clamps at ends."""
    if not timestamps:
        return 0.0
    if t <= timestamps[0]:
        return values[0]
    if t >= timestamps[-1]:
        return values[-1]
    for i in range(len(timestamps) - 1):
        if timestamps[i] <= t <= timestamps[i + 1]:
            dt = timestamps[i + 1] - timestamps[i]
            if dt < 1e-9:
                return values[i]
            return values[i] + (t - timestamps[i]) / dt * (values[i + 1] - values[i])
    return values[-1]



def _get_co_other_config() -> dict:
    """Fetch co-other.json's contents through FPP's documented channel-output
    API rather than reading the config file directly — the file's format is
    not a stable contract across FPP releases."""
    conn = http.client.HTTPConnection('localhost', 80, timeout=3)
    try:
        conn.request('GET', '/api/channel/output/co-other')
        return json.loads(conn.getresponse().read())
    finally:
        conn.close()


def _load_co_other(out_idx: int) -> dict | None:
    try:
        cfg     = _get_co_other_config()
        outputs = [o for o in cfg.get('channelOutputs', [])
                   if o.get('ports')]
        return outputs[out_idx] if 0 <= out_idx < len(outputs) else None
    except Exception:
        return None


def _ensure_fpp_output_enabled():
    """Re-enable FPP's PCA9685 channel output if it was left disabled."""
    try:
        conn = http.client.HTTPConnection('localhost', 80, timeout=3)
        conn.request('GET', '/api/channel/output/co-other')
        resp = conn.getresponse()
        cfg  = json.loads(resp.read())
        changed = False
        for out in cfg.get('channelOutputs', []):
            if out.get('type') == 'PCA9685' and not out.get('enabled', 1):
                out['enabled'] = 1
                changed = True
        if changed:
            data = json.dumps(cfg).encode()
            conn.request('POST', '/api/channel/output/co-other', data,
                         {'Content-Type': 'application/json'})
            conn.getresponse().read()
            print('[Capture] Re-enabled FPP PCA9685 channel output.', flush=True)
        conn.close()
    except Exception as exc:
        print(f'[Capture] Could not verify FPP output state: {exc}', flush=True)


class JointMapper:
    """Maps tracked joint values → servo µs using co-other.json calibration."""

    def __init__(self, joint_map: dict, out_idx: int):
        self._map     = joint_map    # {joint_key: {port, invert, scale}}
        self._out_idx = out_idx
        self._out     = _load_co_other(out_idx)

    def reload(self, joint_map: dict, out_idx: int):
        self._map     = joint_map
        self._out_idx = out_idx
        self._out     = _load_co_other(out_idx)

    def compute(self, values: dict) -> list:
        """Return list of (port_idx, us) for all mapped joints."""
        if not self._out or not self._map:
            return []
        ports = self._out.get('ports', [])
        result = []
        for joint, mapping in self._map.items():
            if joint not in values:
                continue
            port_idx = int(mapping.get('port', -1))
            if port_idx < 0 or port_idx >= len(ports):
                continue
            p  = ports[port_idx]
            mn = p.get('min',    500)
            mx = p.get('max',   2500)
            lo, hi = NORM_RANGE.get(joint, (0, 1))
            t = (values[joint] - lo) / (hi - lo + 1e-9)
            t = max(0.0, min(1.0, t))
            scale  = float(mapping.get('scale',  1.0))
            invert = bool(mapping.get('invert', False))
            t2 = 0.5 + (t - 0.5) * scale
            if invert:
                t2 = 1.0 - t2
            t2 = max(0.0, min(1.0, t2))
            us = max(mn, min(mx, round(mn + t2 * (mx - mn))))
            result.append((port_idx, us))
        return result

    def port_info(self) -> list:
        # Re-read co-other.json fresh rather than using the cached self._out:
        # other tools (e.g. fpp-servo-calibrator) can rename/recalibrate ports
        # at any time, and the mapping page polls this via /api/status, so it
        # should always reflect what's currently on disk. self._out itself
        # stays cached for compute()'s hot path and is only refreshed via
        # reload() (joint-map save) or lazy playback-start init.
        out = _load_co_other(self._out_idx)
        if not out:
            return []
        start_ch = int(out.get('startChannel', 1))
        result = []
        fpp_ch = start_ch
        for i, p in enumerate(out.get('ports', [])):
            size = 2 if p.get('dataType', 0) == 2 else 1
            result.append({
                'port': i,
                'desc': p.get('description', f'Port {i}'),
                'min': p.get('min', 500),
                'max': p.get('max', 2500),
                'center': p.get('center', 1500),
                'fpp_channel': fpp_ch,
                'data_type': p.get('dataType', 0),
            })
            fpp_ch += size
        return result


class OverlayWriter:
    """Writes µs servo values into FPP's channel buffer via the overlay range API.

    FPP keeps full ownership of the PCA9685 and I2C bus.  HTTP PUTs happen on a
    background thread so set_channels() never blocks the playback timing loop.
    Latest-value-wins: if the sender falls behind, stale values are discarded and
    only the most recent position per channel is sent.
    """

    def __init__(self, out: dict):
        self._pending: dict  = {}   # fpp_ch → body str (latest per channel)
        self._plock          = threading.Lock()
        self._event          = threading.Event()
        self._running        = True
        sc = int(out.get('startChannel', 1))
        self._ports = []
        for p in out.get('ports', []):
            dt = p.get('dataType', 0)
            is_16bit = dt in (2, 3, 5)
            self._ports.append({
                'fpp_ch':    sc,
                'is_16bit':  is_16bit,
                'min_us':    float(p.get('min',    500)),
                'center_us': float(p.get('center', 1500)),
                'max_us':    float(p.get('max',    2500)),
            })
            sc += 2 if is_16bit else 1
        self._thread = threading.Thread(target=self._worker, daemon=True,
                                        name='OverlayWriter')
        self._thread.start()

    def _send_one(self, conn, fpp_ch: int, body: str):
        try:
            if conn is None:
                conn = http.client.HTTPConnection('127.0.0.1', 80, timeout=0.1)
            conn.request('PUT', f'/api/overlays/range/{fpp_ch}', body,
                         {'Content-Type': 'application/json'})
            conn.getresponse().read()
        except Exception as exc:
            if not getattr(self, '_overlay_err_logged', False):
                self._overlay_err_logged = True
                print(f'[Capture] Overlay API PUT ch={fpp_ch} failed: {exc}', flush=True)
            try:
                conn.close()
            except Exception:
                pass
            conn = None
        else:
            self._overlay_err_logged = False
        return conn

    def _worker(self):
        conn = None
        while self._running:
            self._event.wait(timeout=1.0)
            self._event.clear()
            with self._plock:
                batch = list(self._pending.items())
                self._pending.clear()
            for fpp_ch, body in batch:
                conn = self._send_one(conn, fpp_ch, body)
        if conn:
            try:
                conn.close()
            except Exception:
                pass

    def _us_to_fpp_val(self, us: float, port: dict) -> int:
        mn, ctr, mx = port['min_us'], port['center_us'], port['max_us']
        if port['is_16bit']:
            if us <= ctr:
                val = round((us - mn) / max(1, ctr - mn) * 32767)
            else:
                val = 32768 + round((us - ctr) / max(1, mx - ctr) * 32767)
            return max(1, min(65535, val))
        else:
            if us <= ctr:
                val = round((us - mn) / max(1, ctr - mn) * 127)
            else:
                val = 128 + round((us - ctr) / max(1, mx - ctr) * 127)
            return max(1, min(255, val))

    def set_channels(self, commands: list):
        """Queue commands and return immediately — background thread sends them."""
        with self._plock:
            for port_idx, us in commands:
                if port_idx >= len(self._ports):
                    continue
                port    = self._ports[port_idx]
                fpp_val = self._us_to_fpp_val(us, port)
                fpp_ch  = port['fpp_ch']
                if port['is_16bit']:
                    self._pending[fpp_ch]     = f'{{"Value": {(fpp_val >> 8) & 0xFF}}}'
                    self._pending[fpp_ch + 1] = f'{{"Value": {fpp_val & 0xFF}}}'
                else:
                    self._pending[fpp_ch] = f'{{"Value": {fpp_val}}}'
        self._event.set()

    def clear_channels(self):
        """Remove all overlay overrides synchronously so fppd resumes immediately."""
        with self._plock:
            self._pending.clear()
        conn = None
        for port in self._ports:
            fpp_ch = port['fpp_ch']
            for ch in ([fpp_ch, fpp_ch + 1] if port['is_16bit'] else [fpp_ch]):
                conn = self._send_one(conn, ch, '{"delete": true}')
        if conn:
            try:
                conn.close()
            except Exception:
                pass

    def close(self):
        self._running = False
        self._event.set()
        self._thread.join(timeout=1.0)

def _load_cfg() -> dict:
    if CFG_PATH.exists():
        try:
            return {**DEFAULTS, **json.loads(CFG_PATH.read_text())}
        except Exception:
            pass
    for search in (LIB_DIR, PROJECT_ROOT):
        yaml_path = search / 'config.yaml'
        if yaml_path.exists():
            try:
                raw = yaml.safe_load(yaml_path.read_text())
                m = dict(DEFAULTS)
                m['smoothing']    = raw.get('motion_capture', {}).get('smoothing', 0.4)
                m['step_time_ms'] = raw.get('xlights', {}).get('step_time_ms', 50)
                m['camera_index'] = raw.get('camera', {}).get('index', 0)
                m['camera_width'] = raw.get('camera', {}).get('width', 640)
                m['camera_height']= raw.get('camera', {}).get('height', 480)
                m['channels']     = raw.get('xlights', {}).get('channels', [])
                hw = raw.get('hardware', {})
                m['hardware_type']      = hw.get('type', 'mock')
                m['pca9685_address']    = hw.get('pca9685_address', '0x40')
                m['pca9685_frequency']  = hw.get('pca9685_frequency', 50)
                ch = hw.get('channel_assignments', {})
                m['channel_pan']  = ch.get('pan', 0)
                m['channel_tilt'] = ch.get('tilt', 1)
                return m
            except Exception:
                pass
    return dict(DEFAULTS)

def _save_cfg(cfg: dict):
    CFG_PATH.parent.mkdir(parents=True, exist_ok=True)
    CFG_PATH.write_text(json.dumps(cfg, indent=2))

def _build_core_config(cfg: dict) -> dict:
    return {
        'camera':   {'index': cfg['camera_index'],
                     'width': cfg['camera_width'],
                     'height': cfg['camera_height'], 'fps': 30},
        'hardware': {'type': cfg['hardware_type'],
                     'pca9685_address':  cfg['pca9685_address'],
                     'pca9685_frequency':cfg['pca9685_frequency'],
                     'channel_assignments': {'pan': cfg['channel_pan'],
                                             'tilt': cfg['channel_tilt']}},
        'servos': {'pan': {}, 'tilt': {}},
        'motion_capture': {'smoothing': cfg['smoothing']},
        'xlights': {'step_time_ms': cfg['step_time_ms'],
                    'channels': cfg.get('channels', [])},
    }


class CaptureDaemon:
    def __init__(self):
        self.cfg          = _load_cfg()
        self._lock        = threading.Lock()
        self._frame_lock  = threading.Lock()
        self._latest_jpg  = b''
        self._values      = {}

        self._servo_pos:  dict = {}
        self._servo_last: dict = {}
        self._pb_thread: threading.Thread = None
        self._session_name = ''
        self._pb_stop    = threading.Event()
        self._pb_pause   = threading.Event()
        self._pb_playing = False
        self._pb_pos     = 0
        self._pb_speed   = 1.0
        self._pb_loop    = False
        self._audio_proc: subprocess.Popen = None
        self._rerecord_snapshot: list = []
        self._rerecord_locked:   set  = set()

        self._start_components()
        self._start_camera_thread()

    def _smooth_servos(self, cmds: list) -> list:
        alpha    = float(self.cfg.get('servo_smoothing', 0.25))
        deadband = int(self.cfg.get('servo_deadband', 4))
        out = []
        for port, us in cmds:
            prev     = self._servo_pos.get(port, us)
            smoothed = prev + alpha * (us - prev)
            self._servo_pos[port] = smoothed
            rounded  = round(smoothed)
            last     = self._servo_last.get(port)
            if last is None or abs(rounded - last) >= deadband:
                self._servo_last[port] = rounded
                out.append((port, rounded))
        return out

    def _start_components(self):
        cc = _build_core_config(self.cfg)
        hw = cc['hardware']
        try:
            backend = create_backend(hw)
        except Exception:
            from core.servo_controller import MockServoBackend
            backend = MockServoBackend()
        servos = ServoController(backend, cc['servos'], hw['channel_assignments'])
        self._tracker = Tracker()
        cam = cc['camera']
        self._camera  = Camera(index=cam['index'], width=cam['width'], height=cam['height'])
        self._tracker.start(cam['width'], cam['height'])
        self._capture  = MotionCaptureMode(servos, cc)
        self._mapper  = JointMapper(self.cfg.get('joint_map', {}),
                                    self.cfg.get('pca_output_idx', 0))
        out = self._mapper._out
        self._writer = OverlayWriter(out) if out else None

    def _start_camera_thread(self):
        if not self._camera.start():
            print('[Capture] Camera failed to open.')
            return
        self._cam_running = True
        threading.Thread(target=self._cam_loop, daemon=True).start()

    def _cam_loop(self):
        while self._cam_running:
            ok, frame = self._camera.read()
            if not ok or frame is None:
                continue
            result = self._tracker.process(frame)
            with self._lock:
                vals = self._capture.update(result)
                self._values = vals
                cmds = self._mapper.compute(vals)
            if cmds and self.cfg.get('live_output') and not self._pb_playing:
                cmds = self._smooth_servos(cmds)
                if self._writer:
                    self._writer.set_channels(cmds)
            display = self._tracker.draw_overlay(frame.copy(), result)
            if self._capture.is_recording:
                cv2.putText(display, '● REC', (10, 58),
                            cv2.FONT_HERSHEY_SIMPLEX, 1.0, (30, 30, 220), 2)
            _, buf = cv2.imencode('.jpg', display, [cv2.IMWRITE_JPEG_QUALITY, 70])
            with self._frame_lock:
                self._latest_jpg = buf.tobytes()

    # ── Audio ────────────────────────────────────────────────────────────────

    @staticmethod
    def _find_player():
        import shutil
        for b in ('ffplay', 'mpv', 'cvlc', 'mpg123', 'aplay'):
            if shutil.which(b):
                return b
        return None

    @staticmethod
    def _build_cmd(player: str, path: str, offset_s: float, device: str = '') -> list:
        ss = offset_s > 0.05
        dev = device if device and device != 'browser' else ''
        if player == 'ffplay':
            cmd = ['ffplay', '-nodisp', '-autoexit', '-loglevel', 'quiet']
            if ss: cmd += ['-ss', f'{offset_s:.3f}']
        elif player == 'mpv':
            cmd = ['mpv', '--no-video', '--really-quiet']
            if dev: cmd += [f'--audio-device=alsa/{dev}']
            if ss: cmd += [f'--start={offset_s:.3f}']
        elif player == 'cvlc':
            cmd = ['cvlc', '--intf', 'dummy', '--play-and-exit']
            if ss: cmd += [f'--start-time={offset_s:.3f}']
        elif player == 'mpg123':
            cmd = ['mpg123', '-q']
            if dev: cmd += ['-a', dev]
        else:  # aplay
            cmd = ['aplay', '-q']
            if dev: cmd += ['-D', dev]
        cmd.append(path)
        return cmd

    def _play_audio(self, filename: str, offset_s: float = 0.0):
        """Play audio on the configured device output. No-op if output=browser."""
        self._stop_audio()
        output = self.cfg.get('audio_output', 'browser')
        if output == 'browser':
            return  # browser-side <audio> element handles playback
        if not filename:
            return
        path = MEDIA_DIR / Path(filename).name
        if not path.exists():
            print(f'[audio] file not found: {path}', flush=True)
            return
        player = self._find_player()
        if not player:
            print('[audio] no player found (tried ffplay, mpv, cvlc, mpg123, aplay)', flush=True)
            return
        cmd = self._build_cmd(player, str(path), offset_s, output)
        env = os.environ.copy()
        if player == 'ffplay' and output not in ('', 'browser'):
            env['SDL_AUDIODRIVER'] = 'alsa'
            env['AUDIODEV'] = output
        print(f'[audio] output={output} {" ".join(cmd)}', flush=True)
        try:
            self._audio_proc = subprocess.Popen(
                cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
        except Exception as exc:
            print(f'[audio] launch failed: {exc}', flush=True)
            self._audio_proc = None

    def list_audio_devices(self) -> list:
        """Return output options: browser + ALSA playback devices."""
        devices = [{'label': 'Browser (your computer speakers)', 'value': 'browser'}]
        try:
            out = subprocess.check_output(['aplay', '-l'], stderr=subprocess.DEVNULL, text=True)
            for line in out.splitlines():
                m = re.match(r'card\s+(\d+)[^:]*:\s+\S+\s+\[([^\]]+)\].*device\s+(\d+)', line)
                if m:
                    card, name, dev = m.group(1), m.group(2), m.group(3)
                    devices.append({
                        'label': f'Card {card}: {name} (hw:{card},{dev})',
                        'value': f'hw:{card},{dev}',
                    })
        except Exception:
            pass
        return devices

    def audio_test(self) -> dict:
        """Play 3 s of the configured audio file on the configured device."""
        filename = self.cfg.get('audio_file', '')
        output   = self.cfg.get('audio_output', 'browser')
        player   = self._find_player() if output != 'browser' else None
        path     = (MEDIA_DIR / Path(filename).name) if filename else None
        info = {
            'audio_file':       filename,
            'audio_output':     output,
            'player':           player,
            'path':             str(path) if path else None,
            'path_exists':      path.exists() if path else False,
            'media_dir':        str(MEDIA_DIR),
            'media_dir_exists': MEDIA_DIR.exists(),
        }
        if output == 'browser':
            info['launched'] = 'browser'
            return info
        if player and path and path.exists():
            cmd = self._build_cmd(player, str(path), 0.0, output)
            env = os.environ.copy()
            if player == 'ffplay' and output not in ('', 'browser'):
                env['SDL_AUDIODRIVER'] = 'alsa'
                env['AUDIODEV'] = output
            try:
                proc = subprocess.Popen(
                    cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
                time.sleep(3)
                proc.terminate()
                info['launched'] = True
            except Exception as exc:
                info['launched'] = False
                info['error'] = str(exc)
        else:
            info['launched'] = False
        return info

    def _stop_audio(self):
        if self._audio_proc and self._audio_proc.poll() is None:
            self._audio_proc.terminate()
        self._audio_proc = None

    def _pause_audio(self):
        if self._audio_proc and self._audio_proc.poll() is None:
            try:
                self._audio_proc.send_signal(signal.SIGSTOP)
            except Exception:
                pass

    def _resume_audio(self):
        if self._audio_proc and self._audio_proc.poll() is None:
            try:
                self._audio_proc.send_signal(signal.SIGCONT)
            except Exception:
                pass

    def list_media_files(self) -> list:
        if not MEDIA_DIR.exists():
            return []
        exts = {'.mp3', '.wav', '.ogg', '.flac', '.m4a', '.aac'}
        return sorted(p.name for p in MEDIA_DIR.iterdir()
                      if p.is_file() and p.suffix.lower() in exts)

    # ── Recording ────────────────────────────────────────────────────────────

    def start_recording(self):
        self._stop_audio()
        self._capture.start_recording()
        audio = self.cfg.get('audio_file', '')
        if audio:
            self._play_audio(audio)

    def stop_recording(self):
        self._capture.stop_recording()
        self._stop_audio()
        if self._rerecord_locked and self._rerecord_snapshot:
            self._merge_rerecord()
        self._rerecord_locked   = set()
        self._rerecord_snapshot = []

    def start_rerecord(self, locked: list):
        """Snapshot current session then start recording; on stop, locked channels
        are restored from the snapshot so only unlocked channels are replaced."""
        frames = self._capture._frames
        self._rerecord_snapshot = [(f.timestamp, dict(f.values)) for f in frames]
        self._rerecord_locked   = set(locked)
        self._stop_audio()
        self._capture.start_recording()
        audio = self.cfg.get('audio_file', '')
        if audio:
            self._play_audio(audio)

    def _merge_rerecord(self):
        snap_ts = [s[0] for s in self._rerecord_snapshot]
        snap_keys = list(self._rerecord_locked)
        snap_vals = {k: [s[1].get(k, 0.0) for s in self._rerecord_snapshot]
                     for k in snap_keys}
        for f in self._capture._frames:
            for key in snap_keys:
                f.values[key] = _interp_val(f.timestamp, snap_ts, snap_vals[key])

    def patch_frames(self, channel: str, edits: list) -> dict:
        """Apply sparse {frame, value} edits to a channel; interpolates between points."""
        frames = self._capture._frames
        if not frames or not channel or not edits:
            return {'ok': False, 'error': 'no frames or channel'}
        edits = sorted(edits, key=lambda e: int(e['frame']))
        idxs  = [int(e['frame']) for e in edits]
        vals  = [float(e['value']) for e in edits]
        n     = len(frames)
        start, end = idxs[0], idxs[-1]
        for i in range(max(0, start), min(end + 1, n)):
            frames[i].values[channel] = _interp_val(i, idxs, vals)
        return {'ok': True, 'patched': max(0, min(end, n - 1) - start + 1)}

    # ── Playback ─────────────────────────────────────────────────────────────

    def start_playback(self, start_idx: int = 0,
                       speed: float = 1.0, loop: bool = False) -> bool:
        frames = self._capture.get_frames()
        if not frames:
            return False
        start_idx        = max(0, min(start_idx, len(frames) - 1))
        self._pb_speed   = max(0.1, float(speed))
        self._pb_loop    = bool(loop)
        if self._pb_thread and self._pb_thread.is_alive():
            self._pb_stop.set()
            self._pb_pause.clear()
        self._pb_stop.clear()
        self._pb_pause.clear()
        self._pb_playing = True
        self._pb_pos     = start_idx

        # Re-init writer if co-other.json became available since startup
        if self._writer is None:
            self._mapper.reload(self.cfg.get('joint_map', {}),
                                self.cfg.get('pca_output_idx', 0))
            out = self._mapper._out
            if out:
                self._writer = OverlayWriter(out)
                print('[Capture] Overlay writer initialized on playback start.', flush=True)
        _ensure_fpp_output_enabled()

        self._pb_thread  = threading.Thread(
            target=self._playback_loop,
            args=(frames, start_idx, self._pb_speed, self._pb_loop),
            daemon=True)
        self._pb_thread.start()
        audio = self.cfg.get('audio_file', '')
        if audio:
            offset = frames[start_idx].timestamp if frames else 0.0
            self._play_audio(audio, offset_s=offset)
        return True

    def pause_playback(self):
        if self._pb_pause.is_set():
            self._pb_pause.clear()
            self._resume_audio()
        else:
            self._pb_pause.set()
            self._pause_audio()

    def stop_playback(self):
        self._pb_stop.set()
        self._pb_pause.clear()
        self._pb_playing = False
        self._stop_audio()
        if self._writer:
            self._writer.clear_channels()

    def _playback_loop(self, frames, start_idx: int = 0,
                       speed: float = 1.0, loop: bool = False):
        # Pre-compute timestamp list and joint key list for fast interpolation
        ts   = [f.timestamp for f in frames]
        keys = list(frames[0].values.keys())
        n    = len(frames)
        # Output at 50 Hz regardless of recording frame rate so servos are smooth
        # even when the camera only captured 10-20 fps during recording.
        step = 0.020
        _diag_done = False

        cur_t = ts[max(0, min(start_idx, n - 1))]
        while True:
            wall_start = time.time() - cur_t / speed

            while cur_t <= ts[-1]:
                if self._pb_stop.is_set():
                    break
                if self._pb_pause.is_set():
                    while self._pb_pause.is_set() and not self._pb_stop.is_set():
                        time.sleep(0.02)
                    if self._pb_stop.is_set():
                        break
                    # Re-anchor timing from scratch so resume is always clean
                    wall_start = time.time() - cur_t / speed

                target = wall_start + cur_t / speed
                wait   = target - time.time()
                if wait > 0:
                    time.sleep(wait)
                if self._pb_stop.is_set():
                    break

                # Binary-search for the two recorded frames that bracket cur_t,
                # then linearly interpolate all joint values between them.
                hi = min(n - 1, bisect.bisect_right(ts, cur_t))
                lo = max(0, hi - 1)
                if lo == hi or ts[hi] <= ts[lo]:
                    interp = frames[lo].values
                else:
                    alpha  = (cur_t - ts[lo]) / (ts[hi] - ts[lo])
                    lo_v   = frames[lo].values
                    hi_v   = frames[hi].values
                    interp = {k: lo_v.get(k, 0.0) + alpha * (hi_v.get(k, 0.0) - lo_v.get(k, 0.0))
                              for k in keys}

                self._pb_pos = lo
                self._capture.play_frame(interp)
                cmds = self._mapper.compute(interp)
                if not _diag_done:
                    _diag_done = True
                    print(f'[Capture] playback: joint_map={len(self._mapper._map)} '
                          f'out={self._mapper._out is not None} '
                          f'writer={self._writer is not None} cmds={len(cmds)}', flush=True)
                if cmds and self._writer:
                    self._writer.set_channels(cmds)

                cur_t += step * speed

            if self._pb_stop.is_set() or not loop:
                break
            cur_t = ts[0]

        self._pb_playing = False
        self._pb_stop.clear()

    # ── Export ───────────────────────────────────────────────────────────────

    def export_xsq_bundle(self, base_filename: str, step_time_ms: int = None) -> dict:
        """Export a paired FSEQ + XSQ for direct import into xLights."""
        stem          = Path(base_filename).stem
        fseq_filename = stem + '.fseq'
        xsq_filename  = stem + '.xsq'
        step_ms       = step_time_ms if step_time_ms is not None \
                        else int(self.cfg.get('step_time_ms', 50))

        result = self.export_fseq(fseq_filename, step_ms)
        if not result['ok']:
            return result

        xsq_path = FSEQ_DIR / xsq_filename
        try:
            from xlights.xsq_writer import export_xsq
            export_xsq(
                fseq_filename=fseq_filename,
                frames=self._capture.get_frames(),
                joint_map=self.cfg.get('joint_map', {}),
                # Fresh read so exported model names reflect the current
                # co-other.json, not whatever was cached at daemon startup.
                co_other_out=_load_co_other(self._mapper._out_idx),
                step_time_ms=step_ms,
                output_path=str(xsq_path),
            )
            result['xsq_filename']  = xsq_filename
            result['fseq_filename'] = fseq_filename
        except Exception as exc:
            result['xsq_error'] = str(exc)

        return result

    def export_fseq(self, filename: str, step_time_ms: int = None) -> dict:
        frames = self._capture.get_frames()
        if not frames:
            return {'ok': False, 'error': 'No frames recorded'}
        joint_map = self.cfg.get('joint_map', {})
        if not joint_map:
            return {'ok': False, 'error': 'No joint mapping configured — map joints to ports first'}
        out = self._mapper._out
        if not out:
            return {'ok': False, 'error': 'No servo output found in co-other.json'}
        step_ms = step_time_ms if step_time_ms is not None else int(self.cfg.get('step_time_ms', 50))
        FSEQ_DIR.mkdir(parents=True, exist_ok=True)
        out_path = FSEQ_DIR / filename
        try:
            from xlights.fseq_writer import export_fseq_servo
            nf, nch, start_ch = export_fseq_servo(
                frames, joint_map, out, step_ms, str(out_path))
            return {'ok': True, 'frames': nf, 'channels': nch,
                    'duration': round(nf * step_ms / 1000, 2),
                    'path': str(out_path),
                    'start_channel': start_ch}
        except Exception as exc:
            return {'ok': False, 'error': str(exc)}

    # ── Session save/load ────────────────────────────────────────────────────

    def save_session(self, filename: str) -> dict:
        frames = self._capture.get_frames()
        if not frames:
            return {'ok': False, 'error': 'No frames'}
        SESS_DIR.mkdir(parents=True, exist_ok=True)
        path = SESS_DIR / filename
        try:
            self._capture.save_session(str(path))
            return {'ok': True, 'path': str(path), 'frames': len(frames)}
        except Exception as exc:
            return {'ok': False, 'error': str(exc)}

    def load_session(self, filename: str) -> dict:
        path = SESS_DIR / filename
        if self._capture.load_session(str(path)):
            self._session_name = filename
            return {'ok': True, 'frames': self._capture.frame_count,
                    'duration': round(self._capture.duration, 2)}
        return {'ok': False, 'error': f'Could not load {filename}'}

    def list_sessions(self) -> list:
        SESS_DIR.mkdir(parents=True, exist_ok=True)
        return [p.name for p in sorted(SESS_DIR.glob('*.json'))]

    def delete_session(self, filename: str) -> dict:
        path = (SESS_DIR / Path(filename).name).resolve()
        if not str(path).startswith(str(SESS_DIR.resolve())):
            return {'ok': False, 'error': 'Invalid path'}
        if not path.exists():
            return {'ok': False, 'error': 'File not found'}
        try:
            path.unlink()
            return {'ok': True}
        except Exception as exc:
            return {'ok': False, 'error': str(exc)}

    def list_sequences(self) -> list:
        FSEQ_DIR.mkdir(parents=True, exist_ok=True)
        return [p.name for p in sorted(FSEQ_DIR.glob('*.fseq'))]

    # ── Status ───────────────────────────────────────────────────────────────

    def status(self) -> dict:
        frames = self._capture.get_frames()
        dur    = self._capture.duration
        m, s   = divmod(dur, 60)
        pb_ts  = frames[self._pb_pos].timestamp if (self._pb_playing and frames) else 0
        return {
            'recording':    self._capture.is_recording,
            'playing':      self._pb_playing,
            'paused':       self._pb_pause.is_set(),
            'frame_count':  self._capture.frame_count,
            'duration':     round(dur, 2),
            'duration_str': f'{int(m):02d}:{s:04.1f}',
            'session_name': self._session_name,
            'pb_pos':       self._pb_pos,
            'pb_timestamp': round(pb_ts, 2),
            'pb_speed':     self._pb_speed,
            'pb_loop':      self._pb_loop,
            'values':       {k: round(v, 3) for k, v in self._values.items()},
            'joint_map':    self.cfg.get('joint_map', {}),
            'ports':        self._mapper.port_info(),
            'live_output':  self.cfg.get('live_output', False),
            'audio_file':      self.cfg.get('audio_file', ''),
            'audio_output':    self.cfg.get('audio_output', 'browser'),
            'cam_running':     getattr(self, '_cam_running', False),
            'writer_ok':       self._writer is not None,
            'joint_map_count': len(self.cfg.get('joint_map', {})),
        }

    def mjpeg_frames(self):
        while True:
            with self._frame_lock:
                jpg = self._latest_jpg
            if jpg:
                yield (b'--frame\r\nContent-Type: image/jpeg\r\n\r\n'
                       + jpg + b'\r\n')
            time.sleep(0.033)


# ── Flask ─────────────────────────────────────────────────────────────────────

app    = Flask(__name__)
daemon = CaptureDaemon()


@app.route('/api/status')
def api_status():
    return jsonify(daemon.status())


@app.route('/api/record/start', methods=['POST'])
def api_rec_start():
    daemon.start_recording()
    return jsonify({'ok': True})

@app.route('/api/record/stop', methods=['POST'])
def api_rec_stop():
    daemon.stop_recording()
    return jsonify(daemon.status())

@app.route('/api/record/rerecord', methods=['POST'])
def api_rerecord_start():
    data   = request.get_json(force=True, silent=True) or {}
    locked = data.get('locked', [])
    daemon.start_rerecord(locked)
    return jsonify({'ok': True})

@app.route('/api/session/frames/patch', methods=['POST'])
def api_patch_frames():
    data    = request.get_json(force=True, silent=True) or {}
    channel = data.get('channel', '')
    edits   = data.get('edits', [])
    return jsonify(daemon.patch_frames(channel, edits))

@app.route('/api/session/frames')
def api_sess_frames():
    """Return downsampled waveform data for canvas drawing."""
    frames = daemon._capture.get_frames()
    if not frames:
        return jsonify({'total_frames': 0, 'duration': 0,
                        'timestamps': [], 'data': {}, 'servo_mapped': []})
    n    = len(frames)
    step = max(1, n // 500)
    idxs = list(range(0, n, step))
    if idxs[-1] != n - 1:
        idxs.append(n - 1)
    keys = list(frames[0].values.keys())
    return jsonify({
        'total_frames': n,
        'duration':     round(frames[-1].timestamp, 2),
        'timestamps':   [round(frames[i].timestamp, 3) for i in idxs],
        'data':         {k: [round(frames[i].values.get(k, 0.0), 3) for i in idxs]
                         for k in keys},
        'servo_mapped': list(daemon.cfg.get('joint_map', {}).keys()),
    })

@app.route('/api/session/frames/channel/<key>')
def api_sess_frames_channel(key):
    """Return full-resolution (no downsampling) per-frame values for one channel."""
    frames = daemon._capture.get_frames()
    if not frames:
        return jsonify({'ok': False, 'error': 'no frames'})
    return jsonify({
        'ok': True, 'channel': key,
        'total_frames': len(frames),
        'duration':     round(frames[-1].timestamp, 2),
        'timestamps':   [round(f.timestamp, 3) for f in frames],
        'values':       [round(f.values.get(key, 0.0), 3) for f in frames],
    })


@app.route('/api/playback/seek', methods=['POST'])
def api_pb_seek():
    """Seek to a frame, drive servos to that position."""
    data  = request.get_json(force=True, silent=True) or {}
    idx   = int(data.get('frame', 0))
    frames = daemon._capture.get_frames()
    if not frames:
        return jsonify({'ok': False, 'error': 'No frames'})
    idx = max(0, min(idx, len(frames) - 1))
    daemon._pb_pos = idx
    frame = frames[idx]
    daemon._capture.play_frame(frame.values)
    cmds = daemon._mapper.compute(frame.values)
    if cmds and daemon._writer:
        daemon._writer.set_channels(cmds)
    return jsonify({'ok': True, 'frame': idx,
                    'timestamp': round(frame.timestamp, 2)})


@app.route('/api/playback/start', methods=['POST'])
def api_pb_start():
    data      = request.get_json(force=True, silent=True) or {}
    start_idx = int(data.get('frame', 0))
    speed     = float(data.get('speed', daemon._pb_speed))
    loop      = bool(data.get('loop',  daemon._pb_loop))
    ok = daemon.start_playback(start_idx, speed=speed, loop=loop)
    return jsonify({'ok': ok})

@app.route('/api/playback/pause', methods=['POST'])
def api_pb_pause():
    daemon.pause_playback()
    return jsonify({'ok': True})

@app.route('/api/playback/stop', methods=['POST'])
def api_pb_stop():
    daemon.stop_playback()
    return jsonify({'ok': True})


@app.route('/api/servo/test', methods=['POST'])
def api_servo_test():
    """Send center position to all configured servo ports via the overlay API."""
    out = daemon._mapper._out
    if not out:
        return jsonify({'ok': False,
                        'error': 'No servo output — check co-other.json and pca_output_idx setting'})
    _ensure_fpp_output_enabled()
    if daemon._writer is None:
        daemon._writer = OverlayWriter(out)
    ports = out.get('ports', [])
    if not ports:
        return jsonify({'ok': False, 'error': 'No ports in servo output config'})
    cmds = [(i, float(p.get('center', 1500))) for i, p in enumerate(ports)]
    daemon._writer.set_channels(cmds)
    return jsonify({'ok': True, 'ports': len(ports),
                    'joint_map_count': len(daemon.cfg.get('joint_map', {}))})


@app.route('/api/export', methods=['POST'])
def api_export():
    data     = request.get_json(force=True, silent=True) or {}
    filename = data.get('filename', 'capture.fseq')
    step_ms  = data.get('step_time_ms')
    if step_ms is not None:
        step_ms = max(10, min(500, int(step_ms)))
    if not filename.endswith('.fseq'):
        filename += '.fseq'
    return jsonify(daemon.export_fseq(filename, step_ms))

@app.route('/api/export/xsq', methods=['POST'])
def api_export_xsq():
    data     = request.get_json(force=True, silent=True) or {}
    filename = data.get('filename', 'capture')
    step_ms  = data.get('step_time_ms')
    if step_ms is not None:
        step_ms = max(10, min(500, int(step_ms)))
    return jsonify(daemon.export_xsq_bundle(filename, step_ms))


@app.route('/api/sequence/download/<path:filename>')
def api_seq_download(filename):
    safe = FSEQ_DIR / Path(filename).name   # strip any traversal
    if not safe.exists():
        return jsonify({'error': 'Not found'}), 404
    return send_file(str(safe), as_attachment=True, download_name=safe.name)


@app.route('/api/session/save', methods=['POST'])
def api_sess_save():
    data     = request.get_json(force=True, silent=True) or {}
    filename = data.get('filename', 'session.json')
    return jsonify(daemon.save_session(filename))

@app.route('/api/session/load', methods=['POST'])
def api_sess_load():
    data     = request.get_json(force=True, silent=True) or {}
    filename = data.get('filename', '')
    return jsonify(daemon.load_session(filename))

@app.route('/api/sessions')
def api_sessions():
    return jsonify(daemon.list_sessions())

@app.route('/api/media/files')
def api_media_files():
    return jsonify(daemon.list_media_files())

@app.route('/api/audio/devices')
def api_audio_devices():
    return jsonify(daemon.list_audio_devices())

@app.route('/api/audio/stream/<path:filename>')
def api_audio_stream(filename):
    safe = MEDIA_DIR / Path(filename).name
    if not safe.exists():
        return jsonify({'error': 'Not found'}), 404
    return send_file(str(safe), conditional=True)

@app.route('/api/audio/test', methods=['POST'])
def api_audio_test():
    return jsonify(daemon.audio_test())

@app.route('/api/session/delete', methods=['POST'])
def api_sess_delete():
    data     = request.get_json(force=True, silent=True) or {}
    filename = data.get('filename', '')
    return jsonify(daemon.delete_session(filename))

@app.route('/api/sequences')
def api_sequences():
    return jsonify(daemon.list_sequences())

@app.route('/api/config', methods=['GET'])
def api_get_cfg():
    return jsonify(daemon.cfg)

@app.route('/api/config', methods=['POST'])
def api_set_cfg():
    updates = request.get_json(force=True, silent=True) or {}
    prev_live = daemon.cfg.get('live_output', False)
    daemon.cfg.update(updates)
    _save_cfg(daemon.cfg)
    if 'joint_map' in updates or 'pca_output_idx' in updates:
        daemon._mapper.reload(daemon.cfg.get('joint_map', {}),
                              daemon.cfg.get('pca_output_idx', 0))
        out = daemon._mapper._out
        if daemon._writer:
            daemon._writer.close()
        daemon._writer = OverlayWriter(out) if out else None
    if 'live_output' in updates:
        new_live = daemon.cfg.get('live_output', False)
        if new_live and not prev_live:
            daemon._servo_last.clear()
        elif not new_live and prev_live:
            if daemon._writer:
                daemon._writer.clear_channels()
    return jsonify({'ok': True})

@app.route('/api/camera/release', methods=['POST'])
def api_cam_release():
    """Stop the camera thread so another daemon can claim the device."""
    daemon._cam_running = False
    time.sleep(0.15)
    if daemon._camera:
        daemon._camera.stop()
    return jsonify({'ok': True})


@app.route('/api/camera/retry', methods=['POST'])
def api_cam_retry():
    """Retry opening the camera — call after live-follow releases it."""
    if not getattr(daemon, '_cam_running', False):
        daemon._start_camera_thread()
    return jsonify({'ok': True, 'cam_running': getattr(daemon, '_cam_running', False)})


@app.route('/stream')
def stream():
    return Response(daemon.mjpeg_frames(),
                    mimetype='multipart/x-mixed-replace; boundary=frame')


def _shutdown(sig, frame):
    if daemon._writer:
        daemon._writer.clear_channels()
        daemon._writer.close()
    sys.exit(0)


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT,  _shutdown)
    print(f'[Capture] Daemon starting on port {PORT}')
    app.run(host='127.0.0.1', port=PORT, threaded=True)
