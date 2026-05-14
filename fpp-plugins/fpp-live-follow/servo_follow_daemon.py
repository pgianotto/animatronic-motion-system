#!/usr/bin/env python3
"""FPP Live Follow Daemon — tracks faces/bodies and drives a pan servo."""
from http.server import HTTPServer, BaseHTTPRequestHandler
import json, signal, sys, threading, time
import cv2
import smbus2

CONFIG   = '/home/fpp/media/config/co-other.json'
HOST     = '127.0.0.1'
PORT_NUM = 5005

_face_cascade = cv2.CascadeClassifier(
    cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')

_lock  = threading.Lock()
_state = {
    'running':   False,
    'status':    'idle',   # idle | tracking | no_target | error:<msg>
    'servo_us':  1500,
    'detection': None,     # {'x': 0-1} normalized horizontal position
}

# ── I2C helpers ───────────────────────────────────────────────────────────────

def _load_output(idx):
    with open(CONFIG) as f:
        cfg = json.load(f)
    outputs = [o for o in cfg.get('channelOutputs', [])
               if o.get('ports') and o.get('enabled')]
    return outputs[idx]

def _open_bus(out):
    dev = out.get('device', 'i2c-1')
    return smbus2.SMBus(int(dev.split('-')[-1]))

def _us_to_counts(us, freq):
    return round(us * freq * 4096 / 1_000_000)

def _set_ch(bus, addr, ch, counts):
    base = 0x06 + ch * 4
    bus.write_byte_data(addr, base,   0)
    bus.write_byte_data(addr, base+1, 0)
    bus.write_byte_data(addr, base+2, counts & 0xFF)
    bus.write_byte_data(addr, base+3, counts >> 8)

# ── Detection ─────────────────────────────────────────────────────────────────

def _detect_face(frame):
    gray  = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    faces = _face_cascade.detectMultiScale(
        gray, scaleFactor=1.1, minNeighbors=5, minSize=(40, 40))
    if len(faces) == 0:
        return None
    fx, fy, fw, fh = max(faces, key=lambda f: f[2] * f[3])
    return (fx + fw / 2) / frame.shape[1]  # normalized 0-1

def _detect_body(frame):
    # Requires mediapipe — import lazily so the daemon starts without it
    try:
        import mediapipe as mp
        pose = mp.solutions.pose.Pose(
            static_image_mode=True,
            model_complexity=0,
            min_detection_confidence=0.5)
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        res = pose.process(rgb)
        pose.close()
        if not res.pose_landmarks:
            return None
        lm = res.pose_landmarks.landmark
        # average of left/right shoulder X
        x = (lm[mp.solutions.pose.PoseLandmark.LEFT_SHOULDER].x +
             lm[mp.solutions.pose.PoseLandmark.RIGHT_SHOULDER].x) / 2
        return x
    except Exception:
        return None

# ── Tracking loop (runs in background thread) ─────────────────────────────────

def _track_loop(config):
    cap = bus = None
    try:
        out      = _load_output(int(config.get('pan_output', 0)))
        addr     = out.get('deviceID', 0x40)
        ports    = out.get('ports', [])
        pan_port = int(config.get('pan_port', 0))
        p        = ports[pan_port] if pan_port < len(ports) else {}
        pan_min  = p.get('min',    1000)
        pan_max  = p.get('max',    2000)
        pan_ctr  = p.get('center', (pan_min + pan_max) // 2)
        pan_range = pan_max - pan_min

        bus = _open_bus(out)
        m = bus.read_byte_data(addr, 0x00)
        if m & 0x10:
            bus.write_byte_data(addr, 0x00, m & ~0x10)
            time.sleep(0.005)
        pre  = bus.read_byte_data(addr, 0xFE)
        freq = 25_000_000 / (4096 * (pre + 1))

        cap      = cv2.VideoCapture(int(config.get('camera', 0)))
        servo_us = float(pan_ctr)
        gain     = float(config.get('gain', 0.5))
        deadzone = float(config.get('deadzone', 0.05))
        mode     = config.get('detect_mode', 'face')

        with _lock:
            _state['servo_us'] = servo_us
            _state['status']   = 'tracking'

        while True:
            with _lock:
                if not _state['running']:
                    break

            ret, frame = cap.read()
            if not ret:
                time.sleep(0.033)
                continue

            # Detection: face preferred, fall back to body
            target_x = None
            if mode in ('face', 'both'):
                target_x = _detect_face(frame)
            if target_x is None and mode in ('body', 'both'):
                target_x = _detect_body(frame)

            with _lock:
                _state['detection'] = {'x': round(target_x, 3)} if target_x is not None else None
                _state['status']    = 'tracking' if target_x is not None else 'no_target'

            if target_x is not None:
                error = target_x - 0.5   # negative=left, positive=right
                if abs(error) > deadzone:
                    # Proportional: at gain=0.5, full-error traverses range in ~1.5s at 30fps
                    delta    = error * gain * pan_range * 0.09
                    servo_us = max(pan_min, min(pan_max, servo_us + delta))
                    _set_ch(bus, addr, pan_port, _us_to_counts(servo_us, freq))
                    with _lock:
                        _state['servo_us'] = round(servo_us)

    except Exception as e:
        with _lock:
            _state['status'] = f'error: {e}'
    finally:
        if cap:  cap.release()
        if bus:  bus.close()
        with _lock:
            _state['running'] = False
            if not _state['status'].startswith('error'):
                _state['status'] = 'idle'

# ── HTTP handler ──────────────────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def reply(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        try:
            length = int(self.headers.get('Content-Length', 0))
            req    = json.loads(self.rfile.read(length))
        except Exception:
            self.reply(400, {'status': 'error', 'message': 'Bad request'})
            return

        action = req.get('action', '')

        if action == 'start':
            with _lock:
                if _state['running']:
                    self.reply(200, {'status': 'ok', 'message': 'already running'})
                    return
                _state['running'] = True
            threading.Thread(
                target=_track_loop, args=(req.get('config', {}),), daemon=True
            ).start()
            self.reply(200, {'status': 'ok', 'action': 'start'})

        elif action == 'stop':
            with _lock:
                _state['running'] = False
            self.reply(200, {'status': 'ok', 'action': 'stop'})

        elif action == 'status':
            with _lock:
                self.reply(200, {
                    'status':    'ok',
                    'running':   _state['running'],
                    'state':     _state['status'],
                    'servo_us':  _state['servo_us'],
                    'detection': _state['detection'],
                })

        else:
            self.reply(400, {'status': 'error', 'message': f'Unknown: {action}'})

# ── Entry point ───────────────────────────────────────────────────────────────

def main():
    def shutdown(sig, frame):
        with _lock:
            _state['running'] = False
        sys.exit(0)
    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT,  shutdown)
    server = HTTPServer((HOST, PORT_NUM), Handler)
    print(f'Live follow daemon on {HOST}:{PORT_NUM}', flush=True)
    server.serve_forever()

if __name__ == '__main__':
    main()
