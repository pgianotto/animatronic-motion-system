"""Live tracking mode — keeps a detected face centered in frame.

Approach: direct position mapping + rate limiter.
  - Face x position [0→1] maps to pan  servo [min→max angle]
  - Face y position [0→1] maps to tilt servo [max→min angle] (inverted: up is high y=0)
  - Movement is clamped to speed_limit degrees per frame for smooth motion
  - A deadzone around center prevents jitter on a stationary face

This position-based approach works correctly with both mock hardware (Windows
development) and real servos on the Pi — no feedback loop required to converge.
"""

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

    def start(self):
        self.active    = True
        self._has_face = False  # reset so next detection snaps rather than sweeps

    def stop(self):
        self.active = False
        self._servos.center_all()

    def update(self, result: TrackingResult):
        if not self.active or not result.face_detected:
            self._has_face = False
            return

        lt_cfg     = self._cfg.get('live_tracking', {})
        servo_cfgs = self._cfg.get('servos', {})
        cam_w      = self._cfg.get('camera', {}).get('width', 640)

        deadzone   = lt_cfg.get('deadzone_px', 25) / cam_w
        face_smooth = lt_cfg.get('face_smoothing', 0.25)

        pan_cfg    = servo_cfgs.get('pan',  {})
        tilt_cfg   = servo_cfgs.get('tilt', {})
        pan_speed  = pan_cfg.get('speed_limit', 8)
        tilt_speed = tilt_cfg.get('speed_limit', 5)

        # --- Smooth the raw face position to filter detector noise ---
        if not self._has_face:
            self._sx   = result.face_center_x   # snap on first detection
            self._sy   = result.face_center_y
            self._has_face = True
        else:
            self._sx += face_smooth * (result.face_center_x - self._sx)
            self._sy += face_smooth * (result.face_center_y - self._sy)

        cur_pan  = self._servos.get_angle('pan')
        cur_tilt = self._servos.get_angle('tilt')

        pan_err  = self._sx - 0.5
        tilt_err = self._sy - 0.5

        # --- Pan target ---
        if abs(pan_err) < deadzone:
            pan_target = cur_pan
        else:
            p_min = pan_cfg.get('min_angle', 0)
            p_max = pan_cfg.get('max_angle', 180)
            pan_target = p_min + self._sx * (p_max - p_min)

        # --- Tilt target ---
        if abs(tilt_err) < deadzone:
            tilt_target = cur_tilt
        else:
            t_min = tilt_cfg.get('min_angle', 30)
            t_max = tilt_cfg.get('max_angle', 150)
            tilt_target = t_max - self._sy * (t_max - t_min)

        # --- Rate-limit movement toward target ---
        pan_step  = float(np.clip(pan_target  - cur_pan,  -pan_speed,  pan_speed))
        tilt_step = float(np.clip(tilt_target - cur_tilt, -tilt_speed, tilt_speed))

        self._servos.set_servo('pan',  cur_pan  + pan_step)
        self._servos.set_servo('tilt', cur_tilt + tilt_step)
