"""FSEQ v2.0 writer for xLights.

Generates uncompressed FSEQ files from recorded motion capture frames.
Each tracked value is mapped to one DMX channel (0-255).
xLights reads the file via File > Import > Import Effects or directly
as a sequence by opening the .fseq in the Sequence Settings.

Format reference: https://github.com/FalconChristmas/fpp/blob/master/docs/FSEQ_Sequence_File_Format.txt
"""

import struct
import time
from typing import List

import numpy as np

from modes.motion_capture import CaptureFrame


def export_fseq(
    frames: List[CaptureFrame],
    channel_map: List[dict],
    step_time_ms: int = 50,
    output_path: str = "sequence.fseq",
) -> tuple:
    """Write *frames* to an FSEQ v2 file and return (frame_count, channel_count).

    Args:
        frames:        List of CaptureFrame objects from MotionCaptureMode.
        channel_map:   List of channel dicts from config.yaml xlights.channels.
        step_time_ms:  Milliseconds per frame (50 = 20fps, 25 = 40fps).
        output_path:   Destination file path.

    Returns:
        (num_frames, channel_count) written.
    """
    if not frames:
        raise ValueError("No frames to export — record a performance first.")

    # Highest 1-based channel number used
    channel_count = max(ch.get('xlights_channel', 1) for ch in channel_map)

    total_ms = frames[-1].timestamp * 1000.0
    num_frames = max(1, int(total_ms / step_time_ms))

    frame_data = np.zeros((num_frames, channel_count), dtype=np.uint8)

    timestamps = [f.timestamp for f in frames]

    for ch in channel_map:
        tracked    = ch.get('tracked_value', '')
        ch_idx     = ch.get('xlights_channel', 1) - 1   # convert to 0-based
        min_in     = float(ch.get('min_input', -90))
        max_in     = float(ch.get('max_input',  90))
        raw_values = [f.values.get(tracked, (min_in + max_in) / 2.0) for f in frames]

        for i in range(num_frames):
            t = i * step_time_ms / 1000.0
            frame_data[i, ch_idx] = _lerp_to_dmx(t, timestamps, raw_values, min_in, max_in)

    _write_v2(output_path, frame_data, channel_count, num_frames, step_time_ms)
    return num_frames, channel_count


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------

def _lerp_to_dmx(t: float, timestamps: List[float], values: List[float],
                 min_in: float, max_in: float) -> int:
    """Linear interpolate *values* at time *t*, then map range to 0-255."""
    if t <= timestamps[0]:
        v = values[0]
    elif t >= timestamps[-1]:
        v = values[-1]
    else:
        # Binary search would be faster for large recordings; linear is fine here
        for i in range(len(timestamps) - 1):
            if timestamps[i] <= t <= timestamps[i + 1]:
                span = timestamps[i + 1] - timestamps[i]
                alpha = (t - timestamps[i]) / (span + 1e-9)
                v = values[i] + alpha * (values[i + 1] - values[i])
                break
        else:
            v = values[-1]

    normalized = (v - min_in) / (max_in - min_in + 1e-9)
    return int(np.clip(normalized * 255.0, 0, 255))


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
