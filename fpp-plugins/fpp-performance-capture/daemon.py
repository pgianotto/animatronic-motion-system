"""FPP Performance Capture daemon — port 5002.

Handles recording, session save/load, playback, and FSEQ export directly
into FPP's sequences folder so files appear in FPP's scheduler immediately.
"""

import json
import os
import signal
import sys
import threading
import time
import urllib.request
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
CO_OTHER_PATH = Path('/home/fpp/media/config/co-other.json')
CO_OTHER_API  = 'http://localhost/api/channel/output/co-other'
FSEQ_DIR  = Path('/home/fpp/media/sequences')
SESS_DIR  = Path('/home/fpp/media/animations')
PORT      = 5002

DEFAULTS = {
    'smoothing':          0.15,  # joint value smoothing (lower = smoother/slower)
    'servo_smoothing':    0.25,  # servo µs output smoothing (lower = smoother/slower)
    'step_time_ms':       50,
    'camera_index':       0,
    'camera_width':       640,
    'camera_height':      480,
    'hardware_type':      'pca9685',
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


def _set_fpp_pca9685_output(enabled: bool):
    try:
        with urllib.request.urlopen(CO_OTHER_API, timeout=3) as resp:
            cfg = json.loads(resp.read())
        changed = False
        for out in cfg.get('channelOutputs', []):
            if out.get('type') == 'PCA9685':
                out['enabled'] = 1 if enabled else 0
                changed = True
        if not changed:
            return
        if not enabled:
            data = json.dumps(cfg).encode()
            req  = urllib.request.Request(CO_OTHER_API, data=data, method='POST',
                                          headers={'Content-Type': 'application/json'})
            urllib.request.urlopen(req, timeout=3)
            print('[Capture] FPP PCA9685 output disabled.')
        else:
            CO_OTHER_PATH.write_text(json.dumps(cfg, indent=2))
            print('[Capture] FPP PCA9685 re-enabled in config (takes effect on next fppd restart).')
    except Exception as exc:
        print(f'[Capture] Could not toggle FPP PCA9685 output: {exc}')


def _load_co_other(out_idx: int) -> dict | None:
    try:
        cfg     = json.loads(CO_OTHER_PATH.read_text())
        outputs = [o for o in cfg.get('channelOutputs', [])
                   if o.get('ports')]
        return outputs[out_idx] if 0 <= out_idx < len(outputs) else None
    except Exception:
        return None


class JointMapper:
    """Maps tracked joint values → servo µs using co-other.json calibration."""

    def __init__(self, joint_map: dict, out_idx: int):
        self._map = joint_map    # {joint_key: {port, invert, scale}}
        self._out = _load_co_other(out_idx)

    def reload(self, joint_map: dict, out_idx: int):
        self._map = joint_map
        self._out = _load_co_other(out_idx)

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
        if not self._out:
            return []
        start_ch = int(self._out.get('startChannel', 1))
        return [
            {'port': i, 'desc': p.get('description', f'Port {i}'),
             'min': p.get('min', 500), 'max': p.get('max', 2500),
             'center': p.get('center', 1500),
             'fpp_channel': start_ch + i * 2}
            for i, p in enumerate(self._out.get('ports', []))
        ]


class PCA9685Writer:
    """Writes µs values directly to a PCA9685 via I2C (smbus2)."""

    def __init__(self, out: dict):
        self._bus  = None
        self._freq = 50.0
        self._addr = int(out.get('deviceID', 0x40))
        dev = out.get('device', 'i2c-1')
        bus_num = int(dev.split('-')[-1])
        try:
            import smbus2
            self._bus = smbus2.SMBus(bus_num)
            self._wake()
            self._freq = self._actual_freq()
        except Exception as exc:
            print(f'[Capture] PCA9685 unavailable: {exc}')

    def _wake(self):
        m = self._bus.read_byte_data(self._addr, 0x00)
        if m & 0x10:
            self._bus.write_byte_data(self._addr, 0x00, m & ~0x10)
            time.sleep(0.005)

    def _actual_freq(self) -> float:
        pre = self._bus.read_byte_data(self._addr, 0xFE)
        return 25_000_000 / (4096 * (pre + 1))

    def _us_to_counts(self, us: int) -> int:
        return round(us * self._freq * 4096 / 1_000_000)

    def set_channels(self, commands: list):
        if not self._bus:
            return
        for port, us in commands:
            counts = self._us_to_counts(us)
            base   = 0x06 + port * 4
            self._bus.write_byte_data(self._addr, base,     0x00)
            self._bus.write_byte_data(self._addr, base + 1, 0x00)
            self._bus.write_byte_data(self._addr, base + 2, counts & 0xFF)
            self._bus.write_byte_data(self._addr, base + 3, counts >> 8)

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
        self._pb_thread: threading.Thread = None
        self._pb_stop    = threading.Event()
        self._pb_pause   = threading.Event()
        self._pb_playing = False
        self._pb_pos     = 0

        self._start_components()
        self._start_camera_thread()

    def _smooth_servos(self, cmds: list) -> list:
        alpha = float(self.cfg.get('servo_smoothing', 0.25))
        out = []
        for port, us in cmds:
            prev = self._servo_pos.get(port, us)
            smoothed = prev + alpha * (us - prev)
            self._servo_pos[port] = smoothed
            out.append((port, round(smoothed)))
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
        self._writer  = PCA9685Writer(self._mapper._out) if self._mapper._out else None

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
            if cmds and self.cfg.get('live_output'):
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

    # ── Recording ────────────────────────────────────────────────────────────

    def start_recording(self):
        self._capture.start_recording()

    def stop_recording(self):
        self._capture.stop_recording()

    # ── Playback ─────────────────────────────────────────────────────────────

    def start_playback(self) -> bool:
        frames = self._capture.get_frames()
        if not frames:
            return False
        self._pb_stop.clear()
        self._pb_pause.clear()
        self._pb_playing = True
        self._pb_pos     = 0
        self._pb_thread  = threading.Thread(
            target=self._playback_loop, args=(frames,), daemon=True)
        self._pb_thread.start()
        return True

    def pause_playback(self):
        if self._pb_pause.is_set():
            self._pb_pause.clear()
        else:
            self._pb_pause.set()

    def stop_playback(self):
        self._pb_stop.set()
        self._pb_pause.clear()
        self._pb_playing = False

    def _playback_loop(self, frames):
        start      = time.time()
        pause_since = None
        total      = len(frames)
        for i, frame in enumerate(frames):
            if self._pb_stop.is_set():
                break
            while self._pb_pause.is_set() and not self._pb_stop.is_set():
                if pause_since is None:
                    pause_since = time.time()
                time.sleep(0.02)
            if pause_since is not None:
                start += time.time() - pause_since
                pause_since = None
            if self._pb_stop.is_set():
                break
            target = start + frame.timestamp
            wait = target - time.time()
            if wait > 0:
                time.sleep(wait)
            if self._pb_stop.is_set():
                break
            self._capture.play_frame(frame.values)
            self._pb_pos = i
            cmds = self._mapper.compute(frame.values)
            if cmds:
                cmds = self._smooth_servos(cmds)
                if self._writer:
                    self._writer.set_channels(cmds)
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
                num_frames=result['frames'],
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
            return {'ok': True, 'frames': self._capture.frame_count,
                    'duration': round(self._capture.duration, 2)}
        return {'ok': False, 'error': f'Could not load {filename}'}

    def list_sessions(self) -> list:
        SESS_DIR.mkdir(parents=True, exist_ok=True)
        return [p.name for p in sorted(SESS_DIR.glob('*.json'))]

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
            'duration_str': f'{int(m):02d}:{s:04.1f}',
            'pb_pos':       self._pb_pos,
            'pb_timestamp': round(pb_ts, 2),
            'values':       {k: round(v, 3) for k, v in self._values.items()},
            'joint_map':    self.cfg.get('joint_map', {}),
            'ports':        self._mapper.port_info(),
            'live_output':  self.cfg.get('live_output', False),
            'cam_running':  getattr(self, '_cam_running', False),
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

@app.route('/api/playback/start', methods=['POST'])
def api_pb_start():
    ok = daemon.start_playback()
    return jsonify({'ok': ok})

@app.route('/api/playback/pause', methods=['POST'])
def api_pb_pause():
    daemon.pause_playback()
    return jsonify({'ok': True})

@app.route('/api/playback/stop', methods=['POST'])
def api_pb_stop():
    daemon.stop_playback()
    return jsonify({'ok': True})

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
        daemon._writer = PCA9685Writer(out) if out else None
    if 'live_output' in updates:
        new_live = daemon.cfg.get('live_output', False)
        if new_live and not prev_live:
            _set_fpp_pca9685_output(False)
        elif not new_live and prev_live:
            _set_fpp_pca9685_output(True)
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
    if daemon.cfg.get('live_output', False):
        _set_fpp_pca9685_output(True)
    sys.exit(0)


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT,  _shutdown)
    print(f'[Capture] Daemon starting on port {PORT}')
    app.run(host='0.0.0.0', port=PORT, threaded=True)
