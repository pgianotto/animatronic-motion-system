"""Live tracking mode — keeps a detected face centered in frame.

Approach: direct position mapping + time-based rate limiter.
  - Face x position [0→1] maps to pan  servo [min→max angle]
  - Face y position [0→1] maps to tilt servo [max→min angle] (inverted: up is high y=0)
  - Movement is clamped to speed_limit degrees per second for smooth motion
    regardless of the processing frame rate (mediapipe can be slow on Pi)
  - A deadzone around center prevents jitter on a stationary face
  - invert flag per axis flips direction for reversed servo mounts
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
        self._sx: float = 0.5   # smoothed face x
        self._sy: float = 0.5   # smoothed face y
        self._has_face: bool = False
        self._last_t: float = 0.0

    def start(self):
        self.active    = True
        self._has_face = False
        self._last_t   = 0.0

    def stop(self):
        self.active = False
        self._servos.center_all()

    def update(self, result: TrackingResult):
        if not self.active:
            self._has_face = False
            return

        lt_cfg       = self._cfg.get('live_tracking', {})
        track_mode   = lt_cfg.get('tracking_mode', 'face')

        # Resolve subject position based on tracking mode
        if track_mode == 'face':
            if not result.face_detected:
                self._has_face = False
                return
            fx, fy = result.face_center_x, result.face_center_y
        elif track_mode == 'body':
            if not result.body_detected:
                self._has_face = False
                return
            fx, fy = result.body_center_x, result.body_center_y
        else:  # face_or_body — face takes priority
            if result.face_detected:
                fx, fy = result.face_center_x, result.face_center_y
            elif result.body_detected:
                fx, fy = result.body_center_x, result.body_center_y
            else:
                self._has_face = False
                return

        now = time.monotonic()
        dt  = min(now - self._last_t, 0.15) if self._last_t else 0.033
        self._last_t = now

        servo_cfgs = self._cfg.get('servos', {})
        cam_w      = self._cfg.get('camera', {}).get('width', 640)

        deadzone    = lt_cfg.get('deadzone_px', 25) / cam_w
        face_smooth = lt_cfg.get('face_smoothing', 0.25)

        pan_cfg    = servo_cfgs.get('pan',  {})
        tilt_cfg   = servo_cfgs.get('tilt', {})
        pan_speed  = pan_cfg.get('speed_limit',  120)   # degrees/sec
        tilt_speed = tilt_cfg.get('speed_limit',  90)   # degrees/sec

        # Apply per-axis invert before smoothing
        if pan_cfg.get('invert', False):
            fx = 1.0 - fx
        if tilt_cfg.get('invert', False):
            fy = 1.0 - fy

        # Smooth the raw face position to filter detector noise
        if not self._has_face:
            self._sx   = fx
            self._sy   = fy
            self._has_face = True
        else:
            self._sx += face_smooth * (fx - self._sx)
            self._sy += face_smooth * (fy - self._sy)

        cur_pan  = self._servos.get_angle('pan')
        cur_tilt = self._servos.get_angle('tilt')

        pan_err  = self._sx - 0.5
        tilt_err = self._sy - 0.5

        # Pan target
        if abs(pan_err) < deadzone:
            pan_target = cur_pan
        else:
            p_min = pan_cfg.get('min_angle', 0)
            p_max = pan_cfg.get('max_angle', 180)
            pan_target = p_min + self._sx * (p_max - p_min)

        # Tilt target
        if abs(tilt_err) < deadzone:
            tilt_target = cur_tilt
        else:
            t_min = tilt_cfg.get('min_angle', 30)
            t_max = tilt_cfg.get('max_angle', 150)
            tilt_target = t_max - self._sy * (t_max - t_min)

        # Time-based rate limit (degrees/sec × elapsed seconds)
        max_pan  = pan_speed  * dt
        max_tilt = tilt_speed * dt
        pan_step  = float(np.clip(pan_target  - cur_pan,  -max_pan,  max_pan))
        tilt_step = float(np.clip(tilt_target - cur_tilt, -max_tilt, max_tilt))

        self._servos.set_servo('pan',  cur_pan  + pan_step)
        self._servos.set_servo('tilt', cur_tilt + tilt_step)
