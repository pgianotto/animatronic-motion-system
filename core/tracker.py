"""Face + body tracking — mediapipe Tasks API (0.10.14+).

On first run, downloads two model files into models/:
  face_landmarker.task  (~2.5 MB)  — head pose, facial expressions
  pose_landmarker.task  (~7 MB)    — full body landmarks

Falls back to OpenCV Haar cascade for face if downloads fail (live tracking
still works; motion-capture expression/body values will be zero).
"""

import time
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

import cv2
import numpy as np

_MODELS_DIR = Path(__file__).parent.parent / "models"

_FACE_MODEL_URL  = (
    "https://storage.googleapis.com/mediapipe-models/"
    "face_landmarker/face_landmarker/float16/1/face_landmarker.task"
)
_POSE_MODEL_URL  = (
    "https://storage.googleapis.com/mediapipe-models/"
    "pose_landmarker/pose_landmarker_full/float16/1/pose_landmarker_full.task"
)
_FACE_MODEL_PATH = _MODELS_DIR / "face_landmarker.task"
_POSE_MODEL_PATH = _MODELS_DIR / "pose_landmarker.task"

# Blendshape keys
_BS_JAW_OPEN   = "jawOpen"
_BS_BLINK_L    = "eyeBlinkLeft"
_BS_BLINK_R    = "eyeBlinkRight"
_BS_BROW_UP_L  = "browOuterUpLeft"
_BS_BROW_UP_R  = "browOuterUpRight"
_BS_BROW_IN_UP = "browInnerUp"

# Pose landmark indices (mediapipe BlazePose 33-point model)
_NOSE          = 0
_L_SHOULDER    = 11
_R_SHOULDER    = 12
_L_ELBOW       = 13
_R_ELBOW       = 14
_L_WRIST       = 15
_R_WRIST       = 16
_L_HIP         = 23
_R_HIP         = 24


@dataclass
class TrackingResult:
    # --- Face ---
    head_yaw:             float = 0.0    # degrees: neg=left, pos=right
    head_pitch:           float = 0.0    # degrees: neg=down, pos=up
    head_roll:            float = 0.0    # degrees
    mouth_open:           float = 0.0    # 0–1
    left_eye_open:        float = 1.0    # 0–1
    right_eye_open:       float = 1.0
    left_eyebrow_raise:   float = 0.5
    right_eyebrow_raise:  float = 0.5
    face_center_x:        float = 0.5    # normalised 0–1 in frame
    face_center_y:        float = 0.5
    face_detected:        bool  = False

    # --- Body (normalised 0–1 in frame unless noted as degrees) ---
    body_detected:        bool  = False
    body_center_x:        float = 0.5    # nose landmark position, normalised
    body_center_y:        float = 0.5
    # Torso
    torso_lean_lr:        float = 0.0    # shoulder midpoint X offset from hip midpoint (-1→+1)
    torso_lean_fb:        float = 0.0    # shoulder Z vs hip Z (forward/back lean, -1→+1)
    torso_tilt:           float = 0.0    # shoulder height difference left-right (-1→+1)
    # Arms — normalised angles (0=full down, 1=full up)
    left_arm_raise:       float = 0.0    # shoulder→elbow elevation
    right_arm_raise:      float = 0.0
    left_elbow_bend:      float = 0.0    # elbow angle 0=straight, 1=fully bent
    right_elbow_bend:     float = 0.0
    left_wrist_raise:     float = 0.0    # wrist height relative to shoulder
    right_wrist_raise:    float = 0.0

    # Raw landmarks for overlay drawing
    face_landmarks:       Optional[object] = field(default=None, repr=False)
    pose_landmarks:       Optional[object] = field(default=None, repr=False)


class Tracker:
    def __init__(self):
        self._face_det   = None
        self._pose_det   = None
        self._haar:      Optional[cv2.CascadeClassifier] = None
        self._mp         = None
        self._api:       str = 'none'
        self._pose_ok:   bool = False
        self._start_ms:  int  = 0

    # ------------------------------------------------------------------
    # Startup
    # ------------------------------------------------------------------

    def start(self, frame_width: int = 640, frame_height: int = 480):
        self._start_ms = int(time.time() * 1000)
        if self._try_tasks_api():
            print("[Tracker] Face Tasks API ready.")
        else:
            self._try_opencv_fallback()
            print("[Tracker] Fallback: OpenCV Haar (body/expression values will be 0).")

    def _try_tasks_api(self) -> bool:
        try:
            import mediapipe as mp
            from mediapipe.tasks import python as mp_python
            from mediapipe.tasks.python import vision as mp_vision

            self._mp = mp

            # --- Face landmarker ---
            if not _ensure_model(_FACE_MODEL_PATH, _FACE_MODEL_URL, "face (~2.5 MB)"):
                return False
            face_opts = mp_vision.FaceLandmarkerOptions(
                base_options=mp_python.BaseOptions(model_asset_path=str(_FACE_MODEL_PATH)),
                output_face_blendshapes=True,
                output_facial_transformation_matrixes=True,
                num_faces=1,
                running_mode=mp_vision.RunningMode.VIDEO,
                min_face_detection_confidence=0.4,
                min_face_presence_confidence=0.4,
                min_tracking_confidence=0.4,
            )
            self._face_det = mp_vision.FaceLandmarker.create_from_options(face_opts)
            self._api = 'tasks'

            # --- Pose landmarker (optional — continues without it) ---
            if _ensure_model(_POSE_MODEL_PATH, _POSE_MODEL_URL, "pose (~7 MB)"):
                pose_opts = mp_vision.PoseLandmarkerOptions(
                    base_options=mp_python.BaseOptions(model_asset_path=str(_POSE_MODEL_PATH)),
                    output_segmentation_masks=False,
                    num_poses=1,
                    running_mode=mp_vision.RunningMode.VIDEO,
                    min_pose_detection_confidence=0.4,
                    min_pose_presence_confidence=0.4,
                    min_tracking_confidence=0.4,
                )
                self._pose_det = mp_vision.PoseLandmarker.create_from_options(pose_opts)
                self._pose_ok = True
                print("[Tracker] Pose Tasks API ready.")
            else:
                print("[Tracker] Pose model unavailable — body tracking disabled.")

            return True
        except Exception as exc:
            print(f"[Tracker] Tasks API failed: {exc}")
            return False

    def _try_opencv_fallback(self):
        cascade = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
        self._haar = cv2.CascadeClassifier(cascade)
        self._api = 'opencv'

    # ------------------------------------------------------------------
    # Per-frame processing
    # ------------------------------------------------------------------

    def process(self, frame: np.ndarray) -> TrackingResult:
        if self._api == 'tasks':
            return self._process_tasks(frame)
        if self._api == 'opencv':
            return self._process_opencv(frame)
        return TrackingResult()

    def _process_tasks(self, frame: np.ndarray) -> TrackingResult:
        result = TrackingResult()
        h, w   = frame.shape[:2]

        rgb    = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mp_img = self._mp.Image(image_format=self._mp.ImageFormat.SRGB, data=rgb)
        ts_ms  = int(time.time() * 1000) - self._start_ms

        # ---- Face ----
        face_det = self._face_det.detect_for_video(mp_img, ts_ms)
        if face_det.face_landmarks:
            lms = face_det.face_landmarks[0]
            result.face_detected   = True
            result.face_landmarks  = lms
            xs = [lm.x for lm in lms]
            ys = [lm.y for lm in lms]
            result.face_center_x   = float(np.mean(xs))
            result.face_center_y   = float(np.mean(ys))

            if face_det.facial_transformation_matrixes:
                mat = np.array(face_det.facial_transformation_matrixes[0], dtype=np.float64)
                if mat.shape == (4, 4):
                    angles = cv2.RQDecomp3x3(mat[:3, :3])[0]
                    result.head_pitch = float(angles[0])
                    result.head_yaw   = float(angles[1])
                    result.head_roll  = float(angles[2])

            if face_det.face_blendshapes:
                bs = {c.category_name: float(c.score) for c in face_det.face_blendshapes[0]}
                result.mouth_open          = bs.get(_BS_JAW_OPEN, 0.0)
                result.left_eye_open       = 1.0 - bs.get(_BS_BLINK_L, 0.0)
                result.right_eye_open      = 1.0 - bs.get(_BS_BLINK_R, 0.0)
                result.left_eyebrow_raise  = max(bs.get(_BS_BROW_UP_L, 0.5),
                                                 bs.get(_BS_BROW_IN_UP, 0.5))
                result.right_eyebrow_raise = max(bs.get(_BS_BROW_UP_R, 0.5),
                                                 bs.get(_BS_BROW_IN_UP, 0.5))

        # ---- Pose / body ----
        if self._pose_ok and self._pose_det:
            pose_det = self._pose_det.detect_for_video(mp_img, ts_ms)
            if pose_det.pose_landmarks:
                plms = pose_det.pose_landmarks[0]
                result.body_detected  = True
                result.pose_landmarks = plms
                result.body_center_x  = float(plms[_NOSE].x)
                result.body_center_y  = float(plms[_NOSE].y)
                _fill_body(result, plms)

        return result

    def _process_opencv(self, frame: np.ndarray) -> TrackingResult:
        result = TrackingResult()
        gray   = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        h, w   = frame.shape[:2]
        faces  = self._haar.detectMultiScale(gray, scaleFactor=1.1,
                                              minNeighbors=5, minSize=(80, 80))
        if len(faces) == 0:
            return result
        x, y, fw, fh      = max(faces, key=lambda f: f[2] * f[3])
        result.face_detected = True
        result.face_center_x = float((x + fw / 2) / w)
        result.face_center_y = float((y + fh / 2) / h)
        return result

    # ------------------------------------------------------------------
    # Overlay
    # ------------------------------------------------------------------

    def draw_overlay(self, frame: np.ndarray, result: TrackingResult) -> np.ndarray:
        h, w = frame.shape[:2]

        if not result.face_detected and not result.body_detected:
            cv2.putText(frame, "No subject", (10, 30),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.7, (80, 80, 80), 2)
            return frame

        # Face box + dots
        if result.face_detected and result.face_landmarks:
            lms = result.face_landmarks
            xs  = [int(lm.x * w) for lm in lms]
            ys  = [int(lm.y * h) for lm in lms]
            cv2.rectangle(frame, (min(xs), min(ys)), (max(xs), max(ys)), (0, 180, 0), 2)
            for i in range(0, len(xs), 6):
                cv2.circle(frame, (xs[i], ys[i]), 1, (0, 210, 0), -1)
            cx = int(result.face_center_x * w)
            cy = int(result.face_center_y * h)
            cv2.circle(frame, (cx, cy), 5, (0, 255, 255), -1)
            cv2.putText(
                frame,
                f"Y:{result.head_yaw:+.1f}  P:{result.head_pitch:+.1f}  R:{result.head_roll:+.1f}",
                (10, 28), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 255, 0), 2,
            )

        # Body skeleton (key joints only)
        if result.body_detected and result.pose_landmarks:
            plms  = result.pose_landmarks
            pairs = [
                (_L_SHOULDER, _R_SHOULDER),
                (_L_SHOULDER, _L_ELBOW),
                (_L_ELBOW,    _L_WRIST),
                (_R_SHOULDER, _R_ELBOW),
                (_R_ELBOW,    _R_WRIST),
                (_L_SHOULDER, _L_HIP),
                (_R_SHOULDER, _R_HIP),
                (_L_HIP,      _R_HIP),
            ]
            def _pt(idx):
                lm = plms[idx]
                return int(lm.x * w), int(lm.y * h)
            for a, b in pairs:
                cv2.line(frame, _pt(a), _pt(b), (255, 140, 0), 2)
            for idx in [_L_SHOULDER, _R_SHOULDER, _L_ELBOW, _R_ELBOW,
                        _L_WRIST, _R_WRIST, _L_HIP, _R_HIP]:
                cv2.circle(frame, _pt(idx), 5, (0, 140, 255), -1)

        return frame

    # ------------------------------------------------------------------
    # Cleanup
    # ------------------------------------------------------------------

    def stop(self):
        if self._face_det:
            self._face_det.close()
            self._face_det = None
        if self._pose_det:
            self._pose_det.close()
            self._pose_det = None


# ---------------------------------------------------------------------------
# Body value extraction
# ---------------------------------------------------------------------------

def _fill_body(result: TrackingResult, lms) -> None:
    def lm(i):
        return np.array([lms[i].x, lms[i].y, lms[i].z])

    ls, rs = lm(_L_SHOULDER), lm(_R_SHOULDER)
    lh, rh = lm(_L_HIP),      lm(_R_HIP)
    le, re = lm(_L_ELBOW),    lm(_R_ELBOW)
    lw, rw = lm(_L_WRIST),    lm(_R_WRIST)

    shoulder_mid = (ls + rs) / 2
    hip_mid      = (lh + rh) / 2

    # Torso lean left/right: shoulder X offset from hip X, normalised by shoulder width
    shoulder_w = float(np.linalg.norm(rs[:2] - ls[:2])) + 1e-6
    result.torso_lean_lr = float(np.clip((shoulder_mid[0] - hip_mid[0]) / shoulder_w, -1, 1))

    # Torso forward/back lean: Z difference (mediapipe Z is depth, negative = closer)
    result.torso_lean_fb = float(np.clip((shoulder_mid[2] - hip_mid[2]) * 3, -1, 1))

    # Torso tilt: left shoulder Y vs right shoulder Y (positive = left higher)
    result.torso_tilt = float(np.clip((rh[1] - ls[1]) / shoulder_w * 2, -1, 1))

    # Arm raise: elbow Y relative to shoulder Y (0=arm down, 1=arm above shoulder)
    def arm_raise(shoulder, elbow):
        # In image coords y increases downward, so elbow above shoulder = elbow.y < shoulder.y
        return float(np.clip((shoulder[1] - elbow[1]) * 4, 0, 1))

    result.left_arm_raise  = arm_raise(ls, le)
    result.right_arm_raise = arm_raise(rs, re)

    # Elbow bend: angle at elbow joint (0=straight, 1=fully bent ~160°)
    def elbow_bend(shoulder, elbow, wrist):
        v1 = shoulder - elbow
        v2 = wrist    - elbow
        cos_a = np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2) + 1e-6)
        angle = np.degrees(np.arccos(np.clip(cos_a, -1, 1)))  # 0=bent 180=straight
        return float(np.clip(1 - (angle / 180), 0, 1))

    result.left_elbow_bend  = elbow_bend(ls, le, lw)
    result.right_elbow_bend = elbow_bend(rs, re, rw)

    # Wrist height relative to shoulder (0=wrist at hip level, 1=wrist above head)
    def wrist_raise(shoulder, wrist):
        return float(np.clip((shoulder[1] - wrist[1]) * 2 + 0.5, 0, 1))

    result.left_wrist_raise  = wrist_raise(ls, lw)
    result.right_wrist_raise = wrist_raise(rs, rw)


# ---------------------------------------------------------------------------
# Model management
# ---------------------------------------------------------------------------

def _ensure_model(path: Path, url: str, label: str) -> bool:
    if path.exists():
        return True
    try:
        print(f"[Tracker] Downloading {label} model ...")
        path.parent.mkdir(parents=True, exist_ok=True)
        urllib.request.urlretrieve(url, path)
        print(f"[Tracker] Saved: {path}")
        return True
    except Exception as exc:
        print(f"[Tracker] Download failed ({label}): {exc}")
        return False
