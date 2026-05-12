import json
import time
from dataclasses import dataclass
from typing import Dict, List

import numpy as np

from core.tracker import TrackingResult
from core.servo_controller import ServoController


@dataclass
class CaptureFrame:
    timestamp: float
    values: Dict[str, float]


class MotionCaptureMode:
    def __init__(self, servo_controller: ServoController, config: dict):
        self._servos   = servo_controller
        self._cfg      = config
        self._ch_map:  List[dict] = config.get('xlights', {}).get('channels', [])
        self._smoothing: float    = config.get('motion_capture', {}).get('smoothing', 0.4)
        self._frames:  List[CaptureFrame] = []
        self._last:    Dict[str, float]   = {}
        self._recording  = False
        self._start_time = 0.0

    def start_recording(self):
        self._frames     = []
        self._start_time = time.time()
        self._recording  = True

    def stop_recording(self):
        self._recording = False

    def update(self, result: TrackingResult) -> Dict[str, float]:
        if not result.face_detected and not result.body_detected:
            return dict(self._last)

        raw     = _extract(result)
        smoothed: Dict[str, float] = {}
        for k, v in raw.items():
            prev = self._last.get(k, v)
            smoothed[k] = prev + self._smoothing * (v - prev)
        self._last = smoothed

        if self._recording:
            ts = time.time() - self._start_time
            self._frames.append(CaptureFrame(timestamp=ts, values=dict(smoothed)))
            self._drive_servos(smoothed)

        return smoothed

    def _drive_servos(self, values: Dict[str, float]):
        servo_cfgs = self._cfg.get('servos', {})
        for ch in self._ch_map:
            tracked  = ch.get('tracked_value', '')
            servo_nm = ch.get('servo', '')
            if not servo_nm or tracked not in values:
                continue
            min_in  = ch.get('min_input',  -90)
            max_in  = ch.get('max_input',   90)
            s_cfg   = servo_cfgs.get(servo_nm, {})
            min_ang = s_cfg.get('min_angle', 0)
            max_ang = s_cfg.get('max_angle', 180)
            t       = (values[tracked] - min_in) / (max_in - min_in + 1e-9)
            angle   = float(np.clip(min_ang + t * (max_ang - min_ang), min_ang, max_ang))
            self._servos.set_servo(servo_nm, angle)

    # ------------------------------------------------------------------
    # Accessors
    # ------------------------------------------------------------------

    @property
    def is_recording(self) -> bool:
        return self._recording

    @property
    def frame_count(self) -> int:
        return len(self._frames)

    @property
    def duration(self) -> float:
        return self._frames[-1].timestamp if self._frames else 0.0

    def get_frames(self) -> List[CaptureFrame]:
        return list(self._frames)

    def save_session(self, path: str):
        data = {
            'version': 1,
            'frames': [{'timestamp': f.timestamp, 'values': f.values}
                       for f in self._frames],
        }
        with open(path, 'w') as fh:
            json.dump(data, fh, indent=2)

    def load_session(self, path: str) -> bool:
        try:
            with open(path) as fh:
                data = json.load(fh)
            self._frames = [
                CaptureFrame(timestamp=f['timestamp'], values=f['values'])
                for f in data.get('frames', [])
            ]
            self._recording = False
            return True
        except Exception:
            return False

    def play_frame(self, values: Dict[str, float]):
        self._drive_servos(values)


def _extract(r: TrackingResult) -> Dict[str, float]:
    return {
        # Face
        'head_yaw':             r.head_yaw,
        'head_pitch':           r.head_pitch,
        'head_roll':            r.head_roll,
        'mouth_open':           r.mouth_open,
        'left_eye_open':        r.left_eye_open,
        'right_eye_open':       r.right_eye_open,
        'left_eyebrow_raise':   r.left_eyebrow_raise,
        'right_eyebrow_raise':  r.right_eyebrow_raise,
        'face_center_x':        r.face_center_x,
        'face_center_y':        r.face_center_y,
        # Body
        'torso_lean_lr':        r.torso_lean_lr,
        'torso_lean_fb':        r.torso_lean_fb,
        'torso_tilt':           r.torso_tilt,
        'left_arm_raise':       r.left_arm_raise,
        'right_arm_raise':      r.right_arm_raise,
        'left_elbow_bend':      r.left_elbow_bend,
        'right_elbow_bend':     r.right_elbow_bend,
        'left_wrist_raise':     r.left_wrist_raise,
        'right_wrist_raise':    r.right_wrist_raise,
    }
