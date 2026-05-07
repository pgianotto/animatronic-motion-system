import cv2
import numpy as np
from typing import Optional, Tuple


class Camera:
    def __init__(self, index: int = 0, width: int = 640, height: int = 480,
                 fps: int = 30, **_):
        self.index = index
        self.width = width
        self.height = height
        self.fps = fps
        self._cap: Optional[cv2.VideoCapture] = None

    def start(self) -> bool:
        self._cap = cv2.VideoCapture(self.index)
        if not self._cap.isOpened():
            return False
        self._cap.set(cv2.CAP_PROP_FRAME_WIDTH, self.width)
        self._cap.set(cv2.CAP_PROP_FRAME_HEIGHT, self.height)
        self._cap.set(cv2.CAP_PROP_FPS, self.fps)
        return True

    def read(self) -> Tuple[bool, Optional[np.ndarray]]:
        if self._cap is None or not self._cap.isOpened():
            return False, None
        return self._cap.read()

    def stop(self):
        if self._cap:
            self._cap.release()
            self._cap = None

    @property
    def is_open(self) -> bool:
        return self._cap is not None and self._cap.isOpened()

    @property
    def center(self) -> Tuple[int, int]:
        return self.width // 2, self.height // 2
