"""Generate an xLights XSQ sequence file with per-frame Servo effects.

Each joint mapping row carries an xlights_model name. Ports that share the
same model name are grouped into one Element with one EffectLayer per port
(Servo1, Servo2, … in port-index order). Different model names produce
separate Elements, so a multi-model rig (e.g. Mickey.Arm + Mickey.Head)
exports correctly in a single XSQ.

Effect settings are stored in the EffectDB (deduplicated by value+channel)
and referenced by index from ElementEffects, matching the format xLights
itself produces.
"""

from pathlib import Path

from xlights.fseq_writer import NORM_RANGE, _interp

_PALETTE = (
    "C_BUTTON_Palette1=#ffffff,C_BUTTON_Palette2=#ff0000,"
    "C_BUTTON_Palette3=#00ff00,C_BUTTON_Palette4=#0000ff,"
    "C_BUTTON_Palette5=#ffff00,C_BUTTON_Palette6=#000000,"
    "C_BUTTON_Palette7=#8080ff,C_BUTTON_Palette8=#ff00ff,"
    "C_CHECKBOXBRIGHTNESSLEVEL=0,C_CHECKBOX_Chroma=0,"
    "C_CHECKBOX_MusicSparkles=0,C_CHECKBOX_Palette1=0,"
    "C_CHECKBOX_Palette2=0,C_CHECKBOX_Palette3=0,"
    "C_CHECKBOX_Palette4=0,C_CHECKBOX_Palette5=0,"
    "C_CHECKBOX_Palette6=0,C_CHECKBOX_Palette7=0,"
    "C_CHECKBOX_Palette8=0,C_SLIDER_SparkleFrequency=0"
)


def _servo_settings(pct: float, servo_num: int) -> str:
    return (
        f"E_CHECKBOX_16bit=1,E_CHECKBOX_Timing_Track=0,"
        f"E_CHOICE_Channel=Servo{servo_num},"
        f"E_TEXTCTRL_EndValue={pct:.1f},"
        f"E_TEXTCTRL_Servo={pct:.1f},"
        f"E_TOGGLEBUTTON_End=0,E_TOGGLEBUTTON_Start=0,"
        f"T_CHECKBOX_Canvas=0,T_CHECKBOX_LayerMorph=0,"
        f"T_CHOICE_LayerMethod=Normal,T_SLIDER_EffectLayerMix=0"
    )


def _compute_frame_pcts(
    frames, timestamps, num_frames, step_time_ms,
    joint_key, mapping, ports, n_ports,
):
    """Return per-frame 0-100% positions for one mapped joint."""
    port_idx = int(mapping.get('port', -1))
    if port_idx < 0 or port_idx >= n_ports:
        return port_idx, None
    p      = ports[port_idx]
    mn     = p.get('min',    500)
    mx     = p.get('max',   2500)
    lo, hi = NORM_RANGE.get(joint_key, (0, 1))
    scale  = float(mapping.get('scale',  1.0))
    invert = bool(mapping.get('invert', False))
    raw    = [f.values.get(joint_key, (lo + hi) / 2) for f in frames]
    pcts   = []
    for i in range(num_frames):
        t      = i * step_time_ms / 1000.0
        v      = _interp(t, timestamps, raw)
        t_norm = max(0.0, min(1.0, (v - lo) / (hi - lo + 1e-9)))
        t2     = 0.5 + (t_norm - 0.5) * scale
        if invert:
            t2 = 1.0 - t2
        t2  = max(0.0, min(1.0, t2))
        us  = mn + t2 * (mx - mn)
        pcts.append(round(max(0.0, min(100.0, (us - mn) / max(1, mx - mn) * 100)), 1))
    return port_idx, pcts


def export_xsq(
    fseq_filename: str,
    frames,
    joint_map: dict,
    co_other_out: dict,
    step_time_ms: int,
    output_path: str,
    model_name: str = "DmxServo",   # fallback only; per-joint xlights_model takes priority
) -> str:
    """Write an xLights 2026-format XSQ with Servo effects per mapped port.

    Joints that share the same xlights_model value are grouped into one
    Element. Each port within a model becomes a separate EffectLayer, numbered
    Servo1, Servo2, … in ascending port-index order. Joints with no
    xlights_model set fall back to the model_name parameter.
    """
    ports   = co_other_out.get('ports', []) if co_other_out else []
    n_ports = len(ports)

    timestamps = [f.timestamp for f in frames]
    total_ms   = timestamps[-1] * 1000.0 if timestamps else 0
    num_frames = max(1, int(total_ms / step_time_ms))
    duration_s = num_frames * step_time_ms / 1000.0

    # Deduplicate by port index — first joint alphabetically wins per port
    port_joint = {}   # port_idx -> (joint_key, mapping)
    for joint_key, mapping in joint_map.items():
        idx = int(mapping.get('port', -1))
        if idx < 0 or idx >= n_ports:
            continue
        if idx not in port_joint or joint_key < port_joint[idx][0]:
            port_joint[idx] = (joint_key, mapping)

    # Group ports by xLights model name, preserving port-index order per model
    from collections import defaultdict
    model_ports = defaultdict(list)   # model_name -> sorted list of port_idx
    for port_idx in sorted(port_joint.keys()):
        joint_key, mapping = port_joint[port_idx]
        mdl = (mapping.get('xlights_model') or '').strip() or model_name
        model_ports[mdl].append(port_idx)

    # Build EffectDB and per-layer refs across all models
    db_lookup = {}   # settings_str -> index
    db_list   = []
    # model_layers[model] = list of (servo_num, desc, refs)
    model_layers = {}
    for mdl, port_list in model_ports.items():
        layers = []
        for servo_num, port_idx in enumerate(port_list, start=1):
            joint_key, mapping = port_joint[port_idx]
            _, frame_pcts = _compute_frame_pcts(
                frames, timestamps, num_frames, step_time_ms,
                joint_key, mapping, ports, n_ports,
            )
            if frame_pcts is None:
                continue
            refs = []
            for pct in frame_pcts:
                s = _servo_settings(pct, servo_num)
                if s not in db_lookup:
                    db_lookup[s] = len(db_list)
                    db_list.append(s)
                refs.append(db_lookup[s])
            desc = ports[port_idx].get('description', f'Port {port_idx}')
            layers.append((servo_num, desc, refs))
        model_layers[mdl] = layers

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
        f'    <ColorPalette>{_PALETTE}</ColorPalette>',
        '  </ColorPalettes>',
        '  <EffectDB>',
    ]
    for s in db_list:
        lines.append(f'    <Effect>{s}</Effect>')
    lines += [
        '  </EffectDB>',
        '  <SequenceMedia />',
        '  <DataLayers>',
        '    <DataLayer lor_params="0" channel_offset="0" num_channels="0" num_frames="0" '
        'data="&lt;rendered: erase-mode>" source="&lt;auto-generated>" name="Nutcracker" />',
        '  </DataLayers>',
        '  <DisplayElements>',
    ]
    for mdl in model_layers:
        lines.append(f'    <Element collapsed="false" type="model" name="{mdl}" visible="true" />')
    lines += ['  </DisplayElements>', '  <ElementEffects>']

    for mdl, layers in model_layers.items():
        lines.append(f'    <Element type="model" name="{mdl}">')
        for servo_num, desc, refs in layers:
            lines.append(f'      <EffectLayer><!-- Servo{servo_num}: {desc} -->')
            for i, ref in enumerate(refs):
                t0 = i * step_time_ms
                t1 = t0 + step_time_ms
                lines.append(
                    f'        <Effect ref="{ref}" name="Servo" startTime="{t0}" endTime="{t1}" palette="0" />'
                )
            lines.append('      </EffectLayer>')
        lines.append('    </Element>')

    lines += ['  </ElementEffects>', '  <lastView>0</lastView>', '  <TimingTags>']
    for i in range(10):
        lines.append(f'    <Tag number="{i}" position="-1" />')
    lines += ['  </TimingTags>', '</xsequence>']

    Path(output_path).write_text('\n'.join(lines), encoding='utf-8')
    return output_path
