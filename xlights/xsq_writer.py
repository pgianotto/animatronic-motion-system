"""Generate an xLights XSQ sequence file with per-frame Servo effects.

Each joint-mapped servo port becomes a model row with one Servo effect
per captured frame. The user can open this XSQ directly in xLights, or
use File > Import to merge it into an existing sequence (xLights will
show a mapping UI so they can align our Port names to their models).
"""

from pathlib import Path
from typing import List

from xlights.fseq_writer import NORM_RANGE, _interp


def export_xsq(
    fseq_filename: str,
    frames,
    joint_map: dict,
    co_other_out: dict,
    step_time_ms: int,
    output_path: str,
) -> str:
    """Write an xLights 2026-format XSQ with Servo effects per mapped port.

    Args:
        fseq_filename: bare filename of the paired FSEQ, e.g. 'capture.fseq'
        frames: list of CaptureFrame objects from the recording
        joint_map: {joint_key: {port, invert, scale}}
        co_other_out: output block from co-other.json (needs 'ports')
        step_time_ms: milliseconds per frame
        output_path: full path where the .xsq will be written

    Returns:
        output_path
    """
    ports = co_other_out.get('ports', []) if co_other_out else []
    n_ports = len(ports)

    timestamps = [f.timestamp for f in frames]
    total_ms = timestamps[-1] * 1000.0 if timestamps else 0
    num_frames = max(1, int(total_ms / step_time_ms))
    duration_s = num_frames * step_time_ms / 1000.0

    # Compute µs value per frame for each mapped port
    port_frames = {}   # {port_idx: (port_cfg, [us_per_frame])}
    for joint_key, mapping in joint_map.items():
        port_idx = int(mapping.get('port', -1))
        if port_idx < 0 or port_idx >= n_ports:
            continue
        p = ports[port_idx]
        mn = p.get('min', 500)
        mx = p.get('max', 2500)
        lo, hi = NORM_RANGE.get(joint_key, (0, 1))
        scale = float(mapping.get('scale', 1.0))
        invert = bool(mapping.get('invert', False))
        raw = [f.values.get(joint_key, (lo + hi) / 2) for f in frames]
        frame_us = []
        for i in range(num_frames):
            t = i * step_time_ms / 1000.0
            v = _interp(t, timestamps, raw)
            t_norm = max(0.0, min(1.0, (v - lo) / (hi - lo + 1e-9)))
            t2 = 0.5 + (t_norm - 0.5) * scale
            if invert:
                t2 = 1.0 - t2
            t2 = max(0.0, min(1.0, t2))
            us = max(mn, min(mx, round(mn + t2 * (mx - mn))))
            frame_us.append(us)
        port_frames[port_idx] = (p, frame_us)

    lines = [
        '<?xml version="1.0"?>',
        '<xsequence BaseChannel="0" ChanCtrlBasic="0" ChanCtrlColor="0" FixedPointTiming="1" ModelBlending="true">',
        '  <head>',
        '    <version>2026.07</version>',
        '    <author></author>',
        '    <author-email></author-email>',
        '    <author-website></author-website>',
        '    <song></song>',
        '    <artist></artist>',
        '    <album></album>',
        '    <MusicURL></MusicURL>',
        '    <comment>Exported by FPP Performance Capture</comment>',
        f'    <sequenceTiming>{step_time_ms} ms</sequenceTiming>',
        '    <sequenceType>Animation</sequenceType>',
        '    <mediaFile></mediaFile>',
        f'    <sequenceDuration>{duration_s:.3f}</sequenceDuration>',
        '    <imageDir></imageDir>',
        '  </head>',
        '  <ColorPalettes>',
        '    <ColorPalette id="1" name=""></ColorPalette>',
        '  </ColorPalettes>',
        '  <EffectDB/>',
        '  <ElementEffects>',
    ]

    for port_idx, (port_cfg, frame_us) in sorted(port_frames.items()):
        desc = port_cfg.get('description', '')
        model_name = f'Port {port_idx}' + (f' - {desc}' if desc else '')
        mn = port_cfg.get('min', 500)
        mx = port_cfg.get('max', 2500)
        rng = max(1, mx - mn)
        lines.append(f'    <Element type="model" name="{model_name}">')
        lines.append('      <EffectLayer>')
        for i, us in enumerate(frame_us):
            t0 = i * step_time_ms
            t1 = t0 + step_time_ms
            pct = max(0.0, min(100.0, (us - mn) / rng * 100))
            lines.append(
                f'        <Effect name="Servo" startTime="{t0}" endTime="{t1}" '
                f'settings="E_TEXTCTRL_Servo_Value={pct:.1f},E_CHECKBOX_Servo_Advanced=0" palette="1"/>'
            )
        lines.append('      </EffectLayer>')
        lines.append('    </Element>')

    lines += [
        '  </ElementEffects>',
        '  <DataLayers/>',
        '  <TimingTracks/>',
        '</xsequence>',
    ]

    Path(output_path).write_text('\n'.join(lines), encoding='utf-8')
    return output_path
