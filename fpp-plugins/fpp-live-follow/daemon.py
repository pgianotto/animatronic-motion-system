"""FPP Live Follow daemon.

Runs as a systemd service on port 5001.
PHP web page and FPP command scripts talk to this via HTTP.

Trigger modes
─────────────
  always_on      — tracking runs whenever the daemon is running
  show_active    — FPP callbacks script calls /api/fpp_event on playlist start/stop
  command        — /api/start and /api/stop called by FPP playlist command scripts
  motion_sensor  — GPIO pin triggers tracking; auto-off after timeout
"""

import json
import os
import sys
import threading
import time
from pathlib import Path

# ── Resolve shared Python core ────────────────────────────────────────────────
PLUGIN_DIR  = Path(__file__).parent
LIB_DIR     = PLUGIN_DIR / 'lib'
PROJECT_ROOT = PLUGIN_DIR.parent.parent  # works when inside the project tree

for search in (LIB_DIR, PROJECT_ROOT):
    if (search / 'core').exists():
        sys.path.insert(0, str(search))
        break

# ── Imports ───────────────────────────────────────────────────────────────────
import cv2
import yaml
from flask import Flask, Response, jsonify, request

from core.camera import Camera
from core.servo_controller import ServoController, create_backend
from core.tracker import Tracker
from modes.live_tracking import LiveTrackingMode

# ── Config ────────────────────────────────────────────────────────────────────
CFG_PATH    = Path('/home/fpp/media/config/animatronic_live_follow.json')
FSEQ_DIR    = Path('/home/fpp/media/sequences')
PORT        = 5001

DEFAULTS = {
    'trigger_mode':        'always_on',
    'motion_sensor_pin':   7,
    'motion_timeout_sec':  30,
    'camera_index':        0,
    'camera_width':        640,
    'camera_height':       480,
    'hardware_type':       'pca9685',
    'pca9685_address':     '0x40',
    'pca9685_frequency':   50,
    'channel_pan':         0,
    'channel_tilt':        1,
    'servo_pan_min':       0,
    'servo_pan_max':       180,
    'servo_pan_center':    90,
    'servo_pan_speed':     8.0,
    'servo_pan_smooth':    0.25,
    'servo_tilt_min':      30,
    'servo_tilt_max':      150,
    'servo_tilt_center':   90,
    'servo_tilt_speed':    5.0,
    'deadzone_px':         25,
}

def _load_cfg() -> dict:
    if CFG_PATH.exists():
        try:
            return {**DEFAULTS, **json.loads(CFG_PATH.read_text())}
        except Exception:
            pass
    # Fall back to project config.yaml if available
    for search in (LIB_DIR, PROJECT_ROOT):
        yaml_path = search / 'config.yaml'
        if yaml_path.exists():
            try:
                raw = yaml.safe_load(yaml_path.read_text())
                merged = dict(DEFAULTS)
                merged['camera_index']    = raw.get('camera', {}).get('index', 0)
                merged['camera_width']    = raw.get('camera', {}).get('width', 640)
                merged['camera_height']   = raw.get('camera', {}).get('height', 480)
                merged['hardware_type']   = raw.get('hardware', {}).get('type', 'mock')
                merged['deadzone_px']     = raw.get('live_tracking', {}).get('deadzone_px', 25)
                pan  = raw.get('servos', {}).get('pan', {})
                tilt = raw.get('servos', {}).get('tilt', {})
                merged['servo_pan_min']   = pan.get('min_angle',    0)
                merged['servo_pan_max']   = pan.get('max_angle',  180)
                merged['servo_pan_center']= pan.get('center_angle', 90)
                merged['servo_pan_speed'] = pan.get('speed_limit',   8)
                merged['servo_tilt_min']  = tilt.get('min_angle',   30)
                merged['servo_tilt_max']  = tilt.get('max_angle',  150)
                merged['servo_tilt_center']= tilt.get('center_angle',90)
                merged['servo_tilt_speed']= tilt.get('speed_limit',  5)
                return merged
            except Exception:
                pass
    return dict(DEFAULTS)

def _save_cfg(cfg: dict):
    CFG_PATH.parent.mkdir(parents=True, exist_ok=True)
    CFG_PATH.write_text(json.dumps(cfg, indent=2))

def _build_tracking_config(cfg: dict) -> dict:
    return {
        'camera':  {'index': cfg['camera_index'],
                    'width': cfg['camera_width'],
                    'height': cfg['camera_height'], 'fps': 30},
        'hardware': {'type': cfg['hardware_type'],
                     'pca9685_address':   cfg['pca9685_address'],
                     'pca9685_frequency': cfg['pca9685_frequency'],
                     'channel_assignments': {'pan':  cfg['channel_pan'],
                                             'tilt': cfg['channel_tilt']}},
        'servos': {
            'pan':  {'min_angle': cfg['servo_pan_min'],
                     'max_angle': cfg['servo_pan_max'],
                     'center_angle': cfg['servo_pan_center'],
                     'speed_limit': cfg['servo_pan_speed']},
            'tilt': {'min_angle': cfg['servo_tilt_min'],
                     'max_angle': cfg['servo_tilt_max'],
                     'center_angle': cfg['servo_tilt_center'],
                     'speed_limit': cfg['servo_tilt_speed']},
        },
        'live_tracking': {
            'deadzone_px':   cfg['deadzone_px'],
            'face_smoothing': cfg.get('face_smoothing', 0.25),
        },
    }


# ── Daemon state ──────────────────────────────────────────────────────────────

class LiveFollowDaemon:
    def __init__(self):
        self.cfg         = _load_cfg()
        self._lock       = threading.Lock()
        self._frame_lock = threading.Lock()
        self._latest_jpg = b''
        self._result     = None
        self._tracking   = False
        self._cam_running = False

        self._camera  = None
        self._tracker = None
        self._servos  = None
        self._mode    = None

        self._motion_timer: threading.Timer = None

        self._start_components()
        self._start_camera_thread()

        mode = self.cfg.get('trigger_mode', 'always_on')
        if mode == 'always_on':
            self.start_tracking()
        elif mode == 'motion_sensor':
            self._setup_motion_sensor()

    # ── Component management ─────────────────────────────────────────────────

    def _start_components(self):
        tc = _build_tracking_config(self.cfg)
        hw = tc['hardware']
        try:
            backend = create_backend(hw)
        except Exception:
            from core.servo_controller import MockServoBackend
            backend = MockServoBackend()
        self._servos  = ServoController(backend, tc['servos'],
                                        hw['channel_assignments'])
        self._tracker = Tracker()
        cam_cfg = tc['camera']
        self._camera  = Camera(index=cam_cfg['index'],
                               width=cam_cfg['width'],
                               height=cam_cfg['height'])
        self._tracker.start(cam_cfg['width'], cam_cfg['height'])
        self._mode = LiveTrackingMode(self._servos, tc)

    def _stop_components(self):
        if self._tracker:
            self._tracker.stop()
        if self._camera:
            self._camera.stop()
        if self._servos:
            self._servos.close()

    # ── Camera thread ────────────────────────────────────────────────────────

    def _start_camera_thread(self):
        if not self._camera.start():
            print('[LiveFollow] Camera failed to open.')
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
                self._result = result
                if self._tracking:
                    self._mode.update(result)
            display = self._tracker.draw_overlay(frame.copy(), result)
            _, buf = cv2.imencode('.jpg', display, [cv2.IMWRITE_JPEG_QUALITY, 70])
            with self._frame_lock:
                self._latest_jpg = buf.tobytes()

    # ── Tracking control ─────────────────────────────────────────────────────

    def start_tracking(self):
        with self._lock:
            if not self._tracking:
                self._mode.start()
                self._tracking = True
        print('[LiveFollow] Tracking started.')

    def stop_tracking(self):
        with self._lock:
            if self._tracking:
                self._mode.stop()
                self._tracking = False
        print('[LiveFollow] Tracking stopped.')

    # ── Motion sensor ────────────────────────────────────────────────────────

    def _setup_motion_sensor(self):
        try:
            import RPi.GPIO as GPIO
            pin = int(self.cfg.get('motion_sensor_pin', 7))
            GPIO.setmode(GPIO.BCM)
            GPIO.setup(pin, GPIO.IN)
            GPIO.add_event_detect(pin, GPIO.RISING,
                                  callback=self._on_motion,
                                  bouncetime=500)
            print(f'[LiveFollow] Motion sensor on GPIO {pin}.')
        except Exception as exc:
            print(f'[LiveFollow] Motion sensor not available: {exc}')

    def _on_motion(self, channel):
        self.start_tracking()
        timeout = float(self.cfg.get('motion_timeout_sec', 30))
        if self._motion_timer:
            self._motion_timer.cancel()
        self._motion_timer = threading.Timer(timeout, self.stop_tracking)
        self._motion_timer.start()

    # ── Status ───────────────────────────────────────────────────────────────

    def status(self) -> dict:
        with self._lock:
            r = self._result
        return {
            'tracking':      self._tracking,
            'trigger_mode':  self.cfg.get('trigger_mode', 'always_on'),
            'face_detected': bool(r and r.face_detected),
            'pan':           self._servos.get_angle('pan')  if self._servos else 90,
            'tilt':          self._servos.get_angle('tilt') if self._servos else 90,
            'head_yaw':      float(r.head_yaw)   if r else 0.0,
            'head_pitch':    float(r.head_pitch) if r else 0.0,
        }

    # ── MJPEG stream ─────────────────────────────────────────────────────────

    def mjpeg_frames(self):
        while True:
            with self._frame_lock:
                jpg = self._latest_jpg
            if jpg:
                yield (b'--frame\r\nContent-Type: image/jpeg\r\n\r\n'
                       + jpg + b'\r\n')
            time.sleep(0.033)

    def reload_config(self):
        self.cfg = _load_cfg()
        was_tracking = self._tracking
        self.stop_tracking()
        self._cam_running = False
        time.sleep(0.2)
        self._stop_components()
        self._start_components()
        self._start_camera_thread()
        if was_tracking or self.cfg.get('trigger_mode') == 'always_on':
            self.start_tracking()
        if self.cfg.get('trigger_mode') == 'motion_sensor':
            self._setup_motion_sensor()


# ── Flask app ─────────────────────────────────────────────────────────────────

app    = Flask(__name__)
daemon = LiveFollowDaemon()


@app.route('/api/status')
def api_status():
    return jsonify(daemon.status())


@app.route('/api/start', methods=['POST'])
def api_start():
    daemon.start_tracking()
    return jsonify({'ok': True})


@app.route('/api/stop', methods=['POST'])
def api_stop():
    daemon.stop_tracking()
    return jsonify({'ok': True})


@app.route('/api/fpp_event', methods=['POST'])
def api_fpp_event():
    """Called by the FPP callbacks script for playlist start/stop."""
    data  = request.get_json(force=True, silent=True) or {}
    event = data.get('event', '')
    mode  = daemon.cfg.get('trigger_mode', 'always_on')
    if mode == 'show_active':
        if event == 'playlist_start':
            daemon.start_tracking()
        elif event == 'playlist_stop':
            daemon.stop_tracking()
    return jsonify({'ok': True})


@app.route('/api/config', methods=['GET'])
def api_get_config():
    return jsonify(daemon.cfg)


@app.route('/api/config', methods=['POST'])
def api_set_config():
    updates = request.get_json(force=True, silent=True) or {}
    daemon.cfg.update(updates)
    _save_cfg(daemon.cfg)
    daemon.reload_config()
    return jsonify({'ok': True})


@app.route('/stream')
def stream():
    return Response(daemon.mjpeg_frames(),
                    mimetype='multipart/x-mixed-replace; boundary=frame')


if __name__ == '__main__':
    print(f'[LiveFollow] Daemon starting on port {PORT}')
    app.run(host='0.0.0.0', port=PORT, threaded=True)
