"""FSEQ v2.0 writer for xLights / FPP.

Generates uncompressed FSEQ files from recorded motion capture frames.
Servo positions are written as 2-byte little-endian µs values at the FPP
channel offsets defined in co-other.json (startChannel + port * 2).

xLights can import the file and map each 2-channel pair to a Servo model
using the FPP channel numbers reported after export.

Format reference: https://github.com/FalconChristmas/fpp/blob/master/docs/FSEQ_Sequence_File_Format.txt
"""

import struct
import time
from typing import List

import numpy as np

from modes.motion_capture import CaptureFrame

# Normalization bounds (lo, hi) for each tracked joint — mirrors daemon.py NORM_RANGE
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


def export_fseq_servo(
    frames: List[CaptureFrame],
    joint_map: dict,
    co_other_out: dict,
    step_time_ms: int = 50,
    output_path: str = "sequence.fseq",
) -> tuple:
    """Write servo FSEQ compatible with FPP and xLights.

    Channel layout respects each port's dataType:
      dataType=2 (16-bit): 2 bytes per port as little-endian µs value
      dataType=0 (8-bit):  1 byte per port as 0-255 scale (0=min, 255=max)

    Unmapped ports are filled with their calibrated center value.

    Returns:
        (num_frames, channel_count, start_channel_1indexed)
    """
    if not frames:
        raise ValueError("No frames to export — record a performance first.")

    ports = co_other_out.get('ports', [])
    if not ports:
        raise ValueError("No servo ports found in output config.")

    start_ch = int(co_other_out.get('startChannel', 1)) - 1   # 0-indexed in FSEQ
    n_ports  = len(ports)

    # Per-port byte width: dataType=2 → 16-bit (2 bytes), otherwise 8-bit (1 byte)
    port_sizes   = [2 if p.get('dataType', 0) == 2 else 1 for p in ports]
    port_offsets = []
    off = start_ch
    for sz in port_sizes:
        port_offsets.append(off)
        off += sz
    ch_count = off

    total_ms   = frames[-1].timestamp * 1000.0
    num_frames = max(1, int(total_ms / step_time_ms))
    timestamps = [f.timestamp for f in frames]

    frame_data = np.zeros((num_frames, ch_count), dtype=np.uint8)

    # Pre-fill every port at its center value
    for pi, p in enumerate(ports):
        mn  = p.get('min',    500)
        mx  = p.get('max',   2500)
        ctr = max(mn, min(mx, p.get('center', (mn + mx) // 2)))
        ch  = port_offsets[pi]
        if port_sizes[pi] == 2:
            frame_data[:, ch]     = ctr & 0xFF
            frame_data[:, ch + 1] = (ctr >> 8) & 0xFF
        else:
            val = round((ctr - mn) / max(1, mx - mn) * 255)
            frame_data[:, ch] = max(0, min(255, val))

    # Overlay each mapped joint
    for joint_key, mapping in joint_map.items():
        port_idx = int(mapping.get('port', -1))
        if port_idx < 0 or port_idx >= n_ports:
            continue
        p      = ports[port_idx]
        mn     = p.get('min',    500)
        mx     = p.get('max',   2500)
        lo, hi = NORM_RANGE.get(joint_key, (0, 1))
        scale  = float(mapping.get('scale',  1.0))
        invert = bool(mapping.get('invert', False))
        raw    = [f.values.get(joint_key, (lo + hi) / 2) for f in frames]
        ch     = port_offsets[port_idx]
        size   = port_sizes[port_idx]

        for i in range(num_frames):
            t      = i * step_time_ms / 1000.0
            v      = _interp(t, timestamps, raw)
            t_norm = max(0.0, min(1.0, (v - lo) / (hi - lo + 1e-9)))
            t2     = 0.5 + (t_norm - 0.5) * scale
            if invert:
                t2 = 1.0 - t2
            t2 = max(0.0, min(1.0, t2))
            us = max(mn, min(mx, round(mn + t2 * (mx - mn))))
            if size == 2:
                frame_data[i, ch]     = us & 0xFF
                frame_data[i, ch + 1] = (us >> 8) & 0xFF
            else:
                val = round((us - mn) / max(1, mx - mn) * 255)
                frame_data[i, ch] = max(0, min(255, val))

    _write_v2(output_path, frame_data, ch_count, num_frames, step_time_ms)
    return num_frames, ch_count, int(co_other_out.get('startChannel', 1))


def _interp(t: float, timestamps: List[float], values: List[float]) -> float:
    """Linear interpolate values at time t."""
    if t <= timestamps[0]:
        return values[0]
    if t >= timestamps[-1]:
        return values[-1]
    for i in range(len(timestamps) - 1):
        if timestamps[i] <= t <= timestamps[i + 1]:
            span  = timestamps[i + 1] - timestamps[i]
            alpha = (t - timestamps[i]) / (span + 1e-9)
            return values[i] + alpha * (values[i + 1] - values[i])
    return values[-1]


def _write_v2(path: str, frame_data: np.ndarray, channel_count: int,
              frame_count: int, step_time_ms: int):
    """Serialize frame_data as an uncompressed FSEQ v2 file.

    Fixed header is exactly 32 bytes:
      4s  magic "FSEQ"
      H   data_offset  (where frame data begins)
      B   minor_version (0)
      B   major_version (2)
      H   variable_data_size
      I   channel_count
      I   frame_count
      B   step_time_ms
      B   flags
      B   compression_type  (0 = uncompressed)
      B   num_compression_blocks
      B   num_sparse_ranges
      B   reserved
      Q   unique_id (timestamp-based)
    Total = 4+2+1+1+2+4+4+1+1+1+1+1+1+8 = 32 bytes
    """
    unique_id = int(time.time() * 1000) & 0xFFFFFFFFFFFFFFFF
    data_offset = 32   # no variable data

    header = struct.pack(
        '<4sHBBHIIBBBBBBQ',
        b'FSEQ',        # magic
        data_offset,    # data offset
        0,              # minor version
        2,              # major version
        0,              # variable data size (none)
        channel_count,
        frame_count,
        step_time_ms,
        0,              # flags
        0,              # compression type (uncompressed)
        0,              # compression block count
        0,              # sparse range count
        0,              # reserved
        unique_id,
    )
    assert len(header) == 32, f"Header size mismatch: {len(header)}"

    with open(path, 'wb') as f:
        f.write(header)
        f.write(frame_data.tobytes())
