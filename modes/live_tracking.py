"""Live tracking mode — keeps a detected face/body centered in frame.

Uses a proportional controller: servo velocity is proportional to how far
the subject is from center. This naturally decelerates as it centers,
avoiding the jitter and overshoot of target-position + rate-limiter approaches.

  velocity (°/sec) = speed_limit × 2 × error
  where error = subject_pos - 0.5, range [-0.5 … 0.5]

At max error (subject at frame edge), servo moves at full speed_limit.
At center, velocity → 0 naturally.
"""

import time

import numpy as np

from core.tracker import TrackingResult
from core.servo_controller import ServoController


class LiveTrackingMode:
    def __init__(self, servo_controller: ServoController, config: dict):
        self._servos  = servo_controller
        self._cfg     = config
        self.active   = False
        self._sx: float = 0.5
        self._sy: float = 0.5
        self._has_subject: bool = False
        self._last_t: float = 0.0

    def start(self):
        self.active       = True
        self._has_subject = False
        self._last_t      = 0.0

    def stop(self):
        self.active = False
        self._servos.center_all()

    def update(self, result: TrackingResult):
        if not self.active:
            self._has_subject = False
            return

        lt_cfg     = self._cfg.get('live_tracking', {})
        track_mode = lt_cfg.get('tracking_mode', 'face')

        # Resolve subject position
        if track_mode == 'face':
            if not result.face_detected:
                self._has_subject = False
                return
            fx, fy = result.face_center_x, result.face_center_y
        elif track_mode == 'body':
            if not result.body_detected:
                self._has_subject = False
                return
            fx, fy = result.body_center_x, result.body_center_y
        else:  # face_or_body
            if result.face_detected:
                fx, fy = result.face_center_x, result.face_center_y
            elif result.body_detected:
                fx, fy = result.body_center_x, result.body_center_y
            else:
                self._has_subject = False
                return

        now = time.monotonic()
        dt  = min(now - self._last_t, 0.15) if self._last_t else 0.033
        self._last_t = now

        servo_cfgs  = self._cfg.get('servos', {})
        cam_w       = self._cfg.get('camera', {}).get('width', 640)
        deadzone    = lt_cfg.get('deadzone_px', 25) / cam_w
        smoothing   = lt_cfg.get('face_smoothing', 0.6)

        pan_cfg    = servo_cfgs.get('pan',  {})
        tilt_cfg   = servo_cfgs.get('tilt', {})
        pan_speed  = pan_cfg.get('speed_limit',  200)   # °/sec at frame edge
        tilt_speed = tilt_cfg.get('speed_limit', 150)   # °/sec at frame edge

        # Apply invert before smoothing
        if pan_cfg.get('invert', False):
            fx = 1.0 - fx
        if tilt_cfg.get('invert', False):
            fy = 1.0 - fy

        # Exponential smoothing — snap on first detection
        if not self._has_subject:
            self._sx, self._sy = fx, fy
            self._has_subject = True
        else:
            self._sx += smoothing * (fx - self._sx)
            self._sy += smoothing * (fy - self._sy)

        pan_err  = self._sx - 0.5   # + = subject right of center → pan right
        tilt_err = self._sy - 0.5   # + = subject below center  → tilt down

        p_min = pan_cfg.get('min_angle',   0)
        p_max = pan_cfg.get('max_angle', 180)
        t_min = tilt_cfg.get('min_angle',  30)
        t_max = tilt_cfg.get('max_angle', 150)
        cur_pan  = self._servos.get_angle('pan')
        cur_tilt = self._servos.get_angle('tilt')

        # Proportional controller — velocity = speed × 2 × error, capped at speed
        if abs(pan_err) > deadzone:
            pan_vel  = pan_speed  * 2.0 * pan_err
            pan_step = float(np.clip(pan_vel * dt, -pan_speed * dt, pan_speed * dt))
            new_pan  = float(np.clip(cur_pan  + pan_step, p_min, p_max))
            self._servos.set_servo('pan', new_pan)

        if abs(tilt_err) > deadzone:
            tilt_vel  = tilt_speed * 2.0 * tilt_err
            tilt_step = float(np.clip(tilt_vel * dt, -tilt_speed * dt, tilt_speed * dt))
            new_tilt  = float(np.clip(cur_tilt + tilt_step, t_min, t_max))
            self._servos.set_servo('tilt', new_tilt)
