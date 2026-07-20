"""Live tracking mode — keeps a detected face/body centered in frame.

Uses a proportional controller: servo velocity is proportional to how far
the subject is from center. This naturally decelerates as it centers,
avoiding the jitter and overshoot of target-position + rate-limiter approaches.

  velocity (°/sec) = speed_limit × 2 × error
  where error = subject_pos - 0.5, range [-0.5 … 0.5]

The servo update loop runs at 30 Hz independently of the mediapipe frame rate
(~5 FPS on Pi). update() only refreshes the smoothed target position; the
servo loop integrates P-controller steps at a fixed dt so motion is smooth.
"""

import threading
import time

import numpy as np

from core.tracker import TrackingResult
from core.servo_controller import ServoController

_SERVO_HZ = 30
_SERVO_DT = 1.0 / _SERVO_HZ


class LiveTrackingMode:
    def __init__(self, servo_controller: ServoController, config: dict):
        self._servos = servo_controller
        self._cfg    = config
        self.active  = False

        self._lock        = threading.Lock()
        self._sx: float   = 0.5
        self._sy: float   = 0.5
        self._has_subject = False
        self._last_seen_ts = 0.0

        self._servo_thread: threading.Thread = None

    def start(self):
        self.active = True
        self._has_subject = False
        if self._servo_thread is None or not self._servo_thread.is_alive():
            self._servo_thread = threading.Thread(
                target=self._servo_loop, daemon=True)
            self._servo_thread.start()

    def stop(self):
        self.active = False
        with self._lock:
            self._has_subject = False
        self._servos.center_all()

    # ── 30 Hz servo loop ──────────────────────────────────────────────────────

    def _servo_loop(self):
        while True:
            if self.active:
                with self._lock:
                    has = self._has_subject
                    sx  = self._sx
                    sy  = self._sy
                if has:
                    self._step(sx, sy)
            time.sleep(_SERVO_DT)

    def _step(self, sx: float, sy: float):
        lt_cfg     = self._cfg.get('live_tracking', {})
        servo_cfgs = self._cfg.get('servos', {})
        cam_w      = self._cfg.get('camera', {}).get('width', 640)
        deadzone   = lt_cfg.get('deadzone_px', 25) / cam_w

        pan_cfg    = servo_cfgs.get('pan',  {})
        tilt_cfg   = servo_cfgs.get('tilt', {})
        pan_speed  = pan_cfg.get('speed_limit',  200)
        tilt_speed = tilt_cfg.get('speed_limit', 150)

        pan_err  = sx - 0.5
        tilt_err = sy - 0.5

        p_min = pan_cfg.get('min_angle',    0)
        p_max = pan_cfg.get('max_angle',  180)
        t_min = tilt_cfg.get('min_angle',  30)
        t_max = tilt_cfg.get('max_angle', 150)

        cur_pan  = self._servos.get_angle('pan')
        cur_tilt = self._servos.get_angle('tilt')

        if abs(pan_err) > deadzone:
            pan_vel  = pan_speed  * 2.0 * pan_err
            pan_step = float(np.clip(pan_vel * _SERVO_DT,
                                     -pan_speed  * _SERVO_DT,
                                      pan_speed  * _SERVO_DT))
            self._servos.set_servo('pan',
                float(np.clip(cur_pan + pan_step, p_min, p_max)))

        if abs(tilt_err) > deadzone:
            tilt_vel  = tilt_speed * 2.0 * tilt_err
            tilt_step = float(np.clip(tilt_vel * _SERVO_DT,
                                      -tilt_speed * _SERVO_DT,
                                       tilt_speed * _SERVO_DT))
            self._servos.set_servo('tilt',
                float(np.clip(cur_tilt + tilt_step, t_min, t_max)))

    # ── Called by camera loop at mediapipe rate (~5 FPS on Pi) ───────────────

    def update(self, result: TrackingResult):
        """Refresh the smoothed target position. Servo moves in _servo_loop."""
        if not self.active:
            with self._lock:
                self._has_subject = False
            return

        lt_cfg     = self._cfg.get('live_tracking', {})
        track_mode = lt_cfg.get('tracking_mode', 'face')
        smoothing  = lt_cfg.get('face_smoothing', 0.6)
        lost_grace = lt_cfg.get('lost_grace_sec', 0.5)
        servo_cfgs = self._cfg.get('servos', {})
        pan_cfg    = servo_cfgs.get('pan',  {})
        tilt_cfg   = servo_cfgs.get('tilt', {})

        # Resolve subject position
        fx = fy = None
        if track_mode == 'face':
            if result.face_detected:
                fx, fy = result.face_center_x, result.face_center_y
        elif track_mode == 'body':
            if result.body_detected:
                fx, fy = result.body_center_x, result.body_center_y
        else:  # face_or_body
            if result.face_detected:
                fx, fy = result.face_center_x, result.face_center_y
            elif result.body_detected:
                fx, fy = result.body_center_x, result.body_center_y

        now = time.time()
        if fx is None:
            # Brief dropout: keep steering toward the last known position
            # instead of freezing then snapping on reacquire. Mediapipe only
            # runs ~5 FPS on a Pi, so even a single missed detection frame is
            # ~200ms — without this grace window that reads as flicker rather
            # than a smooth loss of tracking, especially for face detection
            # (more prone to single-frame misses than body/pose detection).
            with self._lock:
                if self._has_subject and (now - self._last_seen_ts) > lost_grace:
                    self._has_subject = False
            return
        self._last_seen_ts = now

        # Apply invert
        if pan_cfg.get('invert', False):
            fx = 1.0 - fx
        if tilt_cfg.get('invert', False):
            fy = 1.0 - fy

        # EMA smoothing toward new detection
        with self._lock:
            if not self._has_subject:
                self._sx, self._sy = fx, fy
                self._has_subject  = True
            else:
                self._sx += smoothing * (fx - self._sx)
                self._sy += smoothing * (fy - self._sy)
