"""Generate a minimal xLights XSQ sequence file wrapping an FSEQ data layer.

xLights reads the XSQ for timing/model info and loads channel data from the
paired FSEQ via the DataLayer. Both files must be in the same xLights
sequences folder so xLights can resolve the relative filename.
"""

from pathlib import Path


def export_xsq(
    fseq_filename: str,
    num_frames: int,
    step_time_ms: int,
    output_path: str,
) -> str:
    """Write an xLights XSQ that references fseq_filename as a data layer.

    Args:
        fseq_filename: bare filename only, e.g. 'capture.fseq'
        num_frames: total frames in the FSEQ
        step_time_ms: milliseconds per frame (xLights 'timing' field)
        output_path: full path where the .xsq file will be written

    Returns:
        output_path
    """
    name = Path(fseq_filename).stem
    xml = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<xsequence version="2.20" author="" music="" song="" artist="" album=""'
        ' MusicURL="" comment="" ScaledTo="0" FixedPointTiming="1" MediaFile="">\n'
        '  <head>\n'
        '    <author></author>\n'
        f'    <name>{name}</name>\n'
        '    <song></song>\n'
        '    <artist></artist>\n'
        '    <album></album>\n'
        '    <MusicURL></MusicURL>\n'
        '    <comment>Exported by FPP Performance Capture</comment>\n'
        '    <sequencetype>Unlighted</sequencetype>\n'
        f'    <timing>{step_time_ms}</timing>\n'
        '    <media></media>\n'
        '    <version>2.20</version>\n'
        '  </head>\n'
        '  <ElementEffects/>\n'
        '  <ColorPalettes/>\n'
        '  <DataLayers>\n'
        '    <DataLayer name="Erase at start" Enabled="1" filename=""'
        ' desc="" DataReadSize="0" type="erase"/>\n'
        f'    <DataLayer name="{fseq_filename}" Enabled="1"'
        f' filename="{fseq_filename}" desc="" DataReadSize="0"'
        ' type="fseq" channelOffset="0"/>\n'
        '  </DataLayers>\n'
        '  <TimingTracks/>\n'
        '</xsequence>\n'
    )
    Path(output_path).write_text(xml, encoding='utf-8')
    return output_path
