<?php
$DAEMON   = 'http://localhost:5002';
$status   = @json_decode(file_get_contents("$DAEMON/api/status"),  true) ?? [];
$cfg      = @json_decode(file_get_contents("$DAEMON/api/config"),   true) ?? [];
$sessions = @json_decode(file_get_contents("$DAEMON/api/sessions"), true) ?? [];

$cam_running  = $status['cam_running'] ?? false;
$recording    = $status['recording'] ?? false;
$playing      = $status['playing']   ?? false;
$paused       = $status['paused']    ?? false;
$fc           = $status['frame_count']  ?? 0;
$dur          = $status['duration_str'] ?? '00:00.0';
$step_time_ms = intval($cfg['step_time_ms'] ?? 50);
?>

<style>
/* ── Layout ── */
.pc-wrap  { max-width:980px; }

/* ── Cards ── */
.pc-card  { background:#16213e; border-radius:8px; padding:20px; margin-bottom:16px; }
.pc-card h3 { color:#f72585; margin:0 0 14px; font-size:11px; letter-spacing:1.5px;
              text-transform:uppercase; font-weight:bold; }

/* ── Form controls ── */
.pc-field  { display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
.pc-label  { color:#888; font-size:12px; width:110px; flex-shrink:0; }
.pc-value  { color:#e0e0e0; font-size:13px; font-family:monospace; }
.pc-input  { background:#0f3460; color:#e0e0e0; border:1px solid #555;
             border-radius:4px; padding:6px 10px; font-size:13px; }
.pc-select { background:#0f3460; color:#e0e0e0; border:1px solid #4cc9f0;
             border-radius:4px; padding:6px 10px; font-size:13px; }

/* ── Buttons ── */
.pc-btn    { padding:7px 18px; border:none; border-radius:5px; font-weight:bold;
             cursor:pointer; font-size:12px; white-space:nowrap; }
.btn-rec   { background:#f72585; color:#fff; }
.btn-stop  { background:#2a2a4a; color:#888; border:1px solid #3a3a5a; }
.btn-play  { background:#06d6a0; color:#000; }
.btn-pause { background:#fb8500; color:#000; }
.btn-halt  { background:#e63946; color:#fff; }
.btn-export{ background:#7209b7; color:#fff; }
.btn-ghost { background:#0f3460; color:#4cc9f0; border:1px solid #4cc9f0; }
.btn-muted { background:#2a2a4a; color:#666; border:1px solid #333; }
.btn-live-off { background:#1a1a2e; color:#888; border:1px solid #555; }
.btn-live-on  { background:#06d6a0; color:#000; border:none; animation:blink 1.5s infinite; }

/* ── Badges ── */
.pc-badge  { display:inline-flex; align-items:center; gap:5px; padding:3px 12px;
             border-radius:12px; font-size:11px; font-weight:bold; }
.badge-rec { background:#f72585; color:#fff; animation:blink 1s infinite; }
.badge-play{ background:#06d6a0; color:#000; }
.badge-idle{ background:#2a2a4a; color:#666; }

@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Camera stream ── */
.pc-stream { border:2px solid #0f3460; border-radius:6px; width:100%; display:block; }

/* ── Tracked values ── */
.tv-grid   { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; }
.tv-group  { font-size:10px; font-weight:bold; letter-spacing:1px; margin-bottom:6px; }
.tv-row    { display:flex; justify-content:space-between; align-items:center;
             padding:2px 0; border-bottom:1px solid #1a1a2e; }
.tv-key    { color:#666; font-size:11px; }
.tv-val    { color:#4cc9f0; font-family:monospace; font-size:11px; }
.tv-val.body { color:#fb8500; }

/* ── Transport divider ── */
.pc-divider { border:none; border-top:1px solid #1a1a2e; margin:16px 0; }
.pc-sub-hdr { color:#aaa; font-size:10px; font-weight:bold; letter-spacing:1.5px;
              text-transform:uppercase; margin-bottom:10px; }

/* ── Frame rate selector ── */
.fps-seg  { display:inline-flex; border-radius:5px; overflow:hidden; border:1px solid #4cc9f0; }
.fps-seg input[type=radio] { display:none; }
.fps-seg label { padding:6px 16px; font-size:12px; cursor:pointer; color:#888;
                 background:#0a0a1a; border-right:1px solid #4cc9f0; white-space:nowrap; }
.fps-seg label:last-of-type { border-right:none; }
.fps-seg input[type=radio]:checked + label { background:#4cc9f0; color:#000; font-weight:bold; }

/* ── Joint mapping ── */
.jm-table  { width:100%; border-collapse:collapse; font-size:12px; }
.jm-table th { color:#666; text-align:left; padding:5px 8px;
               border-bottom:1px solid #2a2a4a; font-weight:normal; font-size:11px; }
.jm-table td { padding:5px 8px; border-bottom:1px solid #1a1a2e; vertical-align:middle; }
.jm-name   { font-size:11px; width:140px; }
.jm-bar-cell { display:flex; align-items:center; gap:8px; }
.jm-bar-bg { flex:1; height:8px; background:#1a1a2e; border-radius:4px;
             overflow:hidden; min-width:60px; max-width:100px; }
.jm-bar    { height:100%; background:#4cc9f0; border-radius:4px; transition:width 0.1s; }
.jm-val    { color:#4cc9f0; font-family:monospace; font-size:10px;
             width:40px; text-align:right; flex-shrink:0; }
.jm-sel    { background:#0f3460; color:#e0e0e0; border:1px solid #4cc9f0;
             border-radius:4px; padding:3px 6px; font-size:11px; max-width:180px; }
.jm-scale-in { background:#0f3460; color:#e0e0e0; border:1px solid #555;
               border-radius:4px; padding:3px 6px; font-size:11px; width:58px; text-align:center; }

/* ── Slider ── */
.pc-slider { width:140px; accent-color:#4cc9f0; cursor:pointer; }

/* ── Camera overlay ── */
.cam-container { position:relative; background:#000; border-radius:4px; overflow:hidden; line-height:0; }
.cam-controls  { position:absolute; bottom:0; left:0; right:0; line-height:1;
                 background:linear-gradient(transparent, rgba(0,0,0,0.82));
                 padding:32px 10px 10px;
                 display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

/* ── Messages ── */
.pc-msg  { font-size:12px; min-height:18px; }
.pc-hint { color:#555; font-size:11px; line-height:1.5; margin:0 0 6px; }
</style>

<div class="pc-wrap">

<!-- ══ Camera Ownership ════════════════════════════════════════════════════ -->
<div class="pc-card" id="cam-ownership-card" style="<?= $cam_running ? 'display:none' : '' ?>">
  <h3>Camera Unavailable</h3>
  <p class="pc-hint" style="margin-bottom:12px;">
    The camera is held by the Live Follow plugin. Click <strong>Claim Camera</strong> to
    release it from Live Follow and start the capture feed. Click <strong>Restore Live Follow</strong>
    when you're done capturing.
  </p>
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <button class="pc-btn btn-play" onclick="claimCamera()">▶ Claim Camera</button>
    <button class="pc-btn btn-ghost" onclick="restoreLiveFollow()">↩ Restore Live Follow</button>
    <span id="cam-claim-msg" class="pc-msg" style="margin:0;"></span>
  </div>
</div>

<!-- ══ Row 1: Camera + Right Panel ════════════════════════════════════════ -->
<div style="display:flex; gap:16px; margin-bottom:16px; align-items:flex-start;">

  <div class="pc-card" style="flex:2; min-width:0; padding:0; overflow:hidden;">
    <div class="cam-container">
      <img src="/fpp-capture-api/stream" class="pc-stream"
           onerror="this.style.display='none'" alt="Camera feed">
      <div style="position:absolute; top:10px; left:10px;">
        <span class="pc-badge <?= $recording ? 'badge-rec' : 'badge-idle' ?>" id="badge-rec">
          <?= $recording ? '● REC' : 'IDLE' ?>
        </span>
      </div>
      <div class="cam-controls">
        <span id="rec-info" style="font-size:11px; color:#ccc; margin-right:auto; font-family:monospace;">
          <?= $dur ?> &nbsp;·&nbsp; <?= $fc ?> frames
        </span>
        <button class="pc-btn btn-rec"  id="btn-rec"  onclick="recStart()" <?= $recording ? 'style="display:none"' : '' ?>>● Record</button>
        <button class="pc-btn btn-stop" id="btn-srec" onclick="recStop()"  <?= $recording ? '' : 'style="display:none"' ?>>■ Stop</button>
      </div>
    </div>
  </div>

  <!-- ── Right panel ──────────────────────────────────────────────────── -->
  <div class="pc-card" style="width:300px; flex-shrink:0; display:flex; flex-direction:column; gap:0;">

    <!-- Live Values -->
    <div class="pc-sub-hdr" style="margin-bottom:6px;">Live Values</div>
    <div class="tv-grid">
      <div>
        <div class="tv-group" style="color:#4cc9f0;">FACE</div>
        <?php foreach (['head_yaw','head_pitch','head_roll','mouth_open',
                        'left_eye_open','right_eye_open',
                        'left_eyebrow_raise','right_eyebrow_raise',
                        'face_center_x','face_center_y'] as $k): ?>
        <div class="tv-row">
          <span class="tv-key"><?= str_replace('_',' ', ucfirst($k)) ?></span>
          <span class="tv-val" id="tv-<?= $k ?>"><?= number_format($status['values'][$k] ?? 0, 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div>
        <div class="tv-group" style="color:#fb8500;">BODY</div>
        <?php foreach (['torso_lean_lr','torso_lean_fb','torso_tilt',
                        'left_arm_raise','right_arm_raise',
                        'left_elbow_bend','right_elbow_bend',
                        'left_wrist_raise','right_wrist_raise'] as $k): ?>
        <div class="tv-row">
          <span class="tv-key"><?= str_replace('_',' ', ucfirst($k)) ?></span>
          <span class="tv-val body" id="tv-<?= $k ?>"><?= number_format($status['values'][$k] ?? 0, 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <hr class="pc-divider">

    <!-- Live Output -->
    <div class="pc-sub-hdr" style="margin-bottom:6px;">Live Output</div>
    <button id="btn-live" class="pc-btn <?= ($cfg['live_output'] ?? false) ? 'btn-live-on' : 'btn-live-off' ?>"
            style="width:100%;" onclick="toggleLive()">
      <?= ($cfg['live_output'] ?? false) ? '⏹ Live On' : '⏵ Live Output' ?>
    </button>

    <hr class="pc-divider">

    <!-- Smoothing -->
    <div class="pc-sub-hdr" style="margin-bottom:8px;">Smoothing</div>
    <div style="margin-bottom:8px;">
      <div style="color:#888; font-size:11px; margin-bottom:3px;">Joint</div>
      <div style="display:flex; align-items:center; gap:8px;">
        <input type="range" id="sl-smoothing" class="pc-slider" style="flex:1;"
               min="0.05" max="0.9" step="0.05"
               value="<?= number_format($cfg['smoothing'] ?? 0.15, 2) ?>"
               oninput="document.getElementById('lbl-smoothing').textContent=parseFloat(this.value).toFixed(2)">
        <span id="lbl-smoothing" class="pc-value" style="width:32px;"><?= number_format($cfg['smoothing'] ?? 0.15, 2) ?></span>
      </div>
    </div>
    <div style="margin-bottom:10px;">
      <div style="color:#888; font-size:11px; margin-bottom:3px;">Servo</div>
      <div style="display:flex; align-items:center; gap:8px;">
        <input type="range" id="sl-servo" class="pc-slider" style="flex:1;"
               min="0.05" max="0.9" step="0.05"
               value="<?= number_format($cfg['servo_smoothing'] ?? 0.25, 2) ?>"
               oninput="document.getElementById('lbl-servo').textContent=parseFloat(this.value).toFixed(2)">
        <span id="lbl-servo" class="pc-value" style="width:32px;"><?= number_format($cfg['servo_smoothing'] ?? 0.25, 2) ?></span>
      </div>
    </div>
    <div style="display:flex; align-items:center; gap:8px;">
      <button class="pc-btn btn-ghost" style="flex:1;" onclick="saveSmoothing()">Save Settings</button>
      <span id="settings-msg" class="pc-msg" style="margin:0; font-size:11px;"></span>
    </div>

    <hr class="pc-divider">

    <!-- Playback -->
    <div class="pc-sub-hdr" style="margin-bottom:6px;">Playback</div>
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
      <span class="pc-badge <?= $playing ? 'badge-play' : 'badge-idle' ?>" id="badge-play">
        <?= $playing ? ($paused ? '⏸ PAUSED' : '▶ PLAYING') : 'STOPPED' ?>
      </span>
      <span class="pc-value" id="pb-pos" style="font-size:11px; font-family:monospace;">—</span>
    </div>
    <div style="display:flex; gap:6px;">
      <button class="pc-btn btn-play"  style="flex:1;" onclick="pbStart()">▶ Play</button>
      <button class="pc-btn btn-pause" style="flex:1;" onclick="pbPause()">⏸ Pause</button>
      <button class="pc-btn btn-halt"  style="flex:1;" onclick="pbStop()">■ Stop</button>
    </div>

    <hr class="pc-divider">

    <!-- Session Files -->
    <div class="pc-sub-hdr" style="margin-bottom:8px;">Session Files</div>
    <div style="display:flex; gap:6px; margin-bottom:8px;">
      <input class="pc-input" id="sess-save-name" value="session.json" style="flex:1; min-width:0;">
      <button class="pc-btn btn-ghost" onclick="sessSave()">Save</button>
    </div>
    <div style="display:flex; gap:6px; margin-bottom:6px;">
      <select class="pc-select" id="sess-load-sel" style="flex:1; min-width:0;">
        <?php foreach ($sessions as $s): ?>
          <option><?= htmlspecialchars($s) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="pc-btn btn-ghost" onclick="sessLoad()">Load</button>
      <button class="pc-btn btn-muted" onclick="refreshSessions()" title="Refresh">↻</button>
    </div>
    <div id="status-msg" class="pc-msg" style="color:#06d6a0;"></div>

  </div>

</div>

<!-- ══ Export to FPP / xLights ════════════════════════════════════════════ -->
<div class="pc-card">
  <h3>Export to FPP / xLights</h3>
  <p class="pc-hint">
    Exports an FSEQ v2 file directly to <code>/home/fpp/media/sequences/</code> — visible in FPP's scheduler immediately.
    Servo positions are written as 16-bit µs pairs at the channel offsets from your servo calibration.
  </p>

  <div class="pc-field">
    <span class="pc-label">Frame Rate</span>
    <div class="fps-seg">
      <input type="radio" name="fps" id="fps-20" value="50"
             <?= $step_time_ms == 50 ? 'checked' : '' ?>>
      <label for="fps-20">20 fps</label>
      <input type="radio" name="fps" id="fps-40" value="25"
             <?= $step_time_ms == 25 ? 'checked' : '' ?>>
      <label for="fps-40">40 fps</label>
      <input type="radio" name="fps" id="fps-cust" value="custom"
             <?= ($step_time_ms != 50 && $step_time_ms != 25) ? 'checked' : '' ?>>
      <label for="fps-cust">Custom</label>
    </div>
    <input type="number" id="fps-custom-val" class="pc-input"
           value="<?= $step_time_ms ?>" min="10" max="500" step="1"
           style="width:70px; <?= ($step_time_ms != 50 && $step_time_ms != 25) ? '' : 'display:none;' ?>"
           oninput="onCustomFpsInput()">
    <span id="fps-label" style="color:#555; font-size:11px;"></span>
  </div>

  <div class="pc-field">
    <span class="pc-label">Filename</span>
    <input class="pc-input" id="fseq-name" value="capture.fseq" style="width:220px;">
    <button class="pc-btn btn-export" onclick="exportFseq()">Export FSEQ</button>
  </div>

  <div id="export-msg" class="pc-msg"></div>

  <div id="export-xlights" style="display:none; margin-top:12px; padding:12px;
       background:#0a0a1a; border-radius:6px; border:1px solid #1a2a4a;">
    <div style="color:#4cc9f0; font-weight:bold; font-size:11px; margin-bottom:8px;">
      xLights Channel Mapping
    </div>
    <div id="export-ch-map" style="font-size:11px; font-family:monospace;"></div>
    <div style="color:#555; font-size:11px; margin-top:8px; padding-top:8px; border-top:1px solid #1a2a4a;">
      In xLights: add a <strong style="color:#e0e0e0;">Servo</strong> model per port,
      set it to the channel pair shown above, type = 16-bit µs.
    </div>
  </div>
</div>

<!-- ══ Joint Mapping ══════════════════════════════════════════════════════ -->
<div class="pc-card">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
    <h3 style="margin:0;">Joint Mapping</h3>
    <button class="pc-btn btn-ghost"
            style="font-size:12px; padding:5px 14px;" onclick="saveJointMap()">Save Mapping</button>
  </div>
  <p class="pc-hint" style="margin-bottom:12px;">
    Map each tracked joint to a servo port. Min/max/center calibration is read from the Servo Calibrator plugin.
    Set port to <em>none</em> to leave a joint unmapped.
    Enable <strong>Live Output</strong> to drive servos in real time while tracking.
  </p>
  <table class="jm-table">
    <thead>
      <tr>
        <th>Joint</th>
        <th>Live Value</th>
        <th>Port</th>
        <th style="text-align:center;">Invert</th>
        <th>Scale %</th>
      </tr>
    </thead>
    <tbody id="jm-tbody">
      <tr><td colspan="5" style="color:#555; font-style:italic; padding:14px 8px;">
        Loading servo ports from FPP config…
      </td></tr>
    </tbody>
  </table>
  <div id="jm-msg" class="pc-msg" style="color:#06d6a0; margin-top:6px;"></div>
</div>

</div><!-- .pc-wrap -->

<script>
const API = '/fpp-capture-api';

// ── Joint definitions ─────────────────────────────────────────────────────

const JOINTS = [
  {key:'head_yaw',           label:'Head Yaw',           lo:-45, hi:45,  group:'face'},
  {key:'head_pitch',         label:'Head Pitch',          lo:-40, hi:40,  group:'face'},
  {key:'head_roll',          label:'Head Roll',           lo:-30, hi:30,  group:'face'},
  {key:'mouth_open',         label:'Mouth Open',          lo:0,   hi:1,   group:'face'},
  {key:'left_eye_open',      label:'Left Eye Open',       lo:0,   hi:1,   group:'face'},
  {key:'right_eye_open',     label:'Right Eye Open',      lo:0,   hi:1,   group:'face'},
  {key:'left_eyebrow_raise', label:'Left Eyebrow Raise',  lo:0,   hi:1,   group:'face'},
  {key:'right_eyebrow_raise',label:'Right Eyebrow Raise', lo:0,   hi:1,   group:'face'},
  {key:'face_center_x',      label:'Face Center X',       lo:0,   hi:1,   group:'face'},
  {key:'face_center_y',      label:'Face Center Y',       lo:0,   hi:1,   group:'face'},
  {key:'torso_lean_lr',      label:'Torso Lean L/R',      lo:-1,  hi:1,   group:'body'},
  {key:'torso_lean_fb',      label:'Torso Lean F/B',      lo:-1,  hi:1,   group:'body'},
  {key:'torso_tilt',         label:'Torso Tilt',          lo:-1,  hi:1,   group:'body'},
  {key:'left_arm_raise',     label:'Left Arm Raise',      lo:0,   hi:1,   group:'body'},
  {key:'right_arm_raise',    label:'Right Arm Raise',     lo:0,   hi:1,   group:'body'},
  {key:'left_elbow_bend',    label:'Left Elbow Bend',     lo:0,   hi:1,   group:'body'},
  {key:'right_elbow_bend',   label:'Right Elbow Bend',    lo:0,   hi:1,   group:'body'},
  {key:'left_wrist_raise',   label:'Left Wrist Raise',    lo:0,   hi:1,   group:'body'},
  {key:'right_wrist_raise',  label:'Right Wrist Raise',   lo:0,   hi:1,   group:'body'},
];

let JM_ports = [];
let JM_built = false;

// ── Frame rate selector ───────────────────────────────────────────────────

document.querySelectorAll('input[name="fps"]').forEach(r => r.addEventListener('change', onFpsChange));

function onFpsChange() {
  const val = document.querySelector('input[name="fps"]:checked').value;
  const customInput = document.getElementById('fps-custom-val');
  const label = document.getElementById('fps-label');
  if (val === 'custom') {
    customInput.style.display = '';
    updateFpsLabel(parseInt(customInput.value) || 50);
  } else {
    customInput.style.display = 'none';
    updateFpsLabel(parseInt(val));
  }
}

function onCustomFpsInput() {
  updateFpsLabel(parseInt(document.getElementById('fps-custom-val').value) || 50);
}

function updateFpsLabel(ms) {
  const fps = (1000 / ms).toFixed(ms < 100 ? 0 : 1);
  document.getElementById('fps-label').textContent = `${ms} ms/frame (${fps} fps)`;
}

function getStepTimeMs() {
  const val = document.querySelector('input[name="fps"]:checked').value;
  return val === 'custom' ? (parseInt(document.getElementById('fps-custom-val').value) || 50) : parseInt(val);
}

onFpsChange(); // initialize label on load

// ── Joint mapping ─────────────────────────────────────────────────────────

function buildJointTable(ports, jointMap) {
  JM_ports = ports;
  JM_built = true;
  const tbody = document.getElementById('jm-tbody');
  if (!tbody) return;
  const portOpts = '<option value="-1">— none —</option>' +
    ports.map(p => `<option value="${p.port}">${p.port}: ${p.desc}</option>`).join('');
  const rows = [];
  let lastGroup = null;
  for (const j of JOINTS) {
    if (j.group !== lastGroup) {
      lastGroup = j.group;
      const color = j.group === 'face' ? '#4cc9f0' : '#fb8500';
      const label = j.group === 'face' ? 'Face' : 'Body';
      rows.push(`<tr><td colspan="5" style="padding:10px 8px 3px; color:${color};
        font-size:10px; font-weight:bold; letter-spacing:1px;">${label}</td></tr>`);
    }
    const m   = jointMap[j.key] || {};
    const sel = m.port !== undefined ? m.port : -1;
    const inv = m.invert ? 'checked' : '';
    const sc  = m.scale  !== undefined ? Math.round(m.scale * 100) : 100;
    const gc  = j.group === 'face' ? '#4cc9f0' : '#fb8500';
    const opts = portOpts.replace(`value="${sel}"`, `value="${sel}" selected`);
    rows.push(`<tr>
      <td class="jm-name" style="color:${gc};">${j.label}</td>
      <td><div class="jm-bar-cell">
        <div class="jm-bar-bg"><div class="jm-bar" id="jm-bar-${j.key}"></div></div>
        <span class="jm-val" id="jm-val-${j.key}">—</span>
      </div></td>
      <td><select class="jm-sel" id="jm-port-${j.key}">${opts}</select></td>
      <td style="text-align:center;"><input type="checkbox" id="jm-inv-${j.key}" ${inv}></td>
      <td><input type="number" class="jm-scale-in" id="jm-scale-${j.key}" value="${sc}" min="0" max="200" step="5"></td>
    </tr>`);
  }
  tbody.innerHTML = rows.join('');
}

function updateJointBars(values) {
  JOINTS.forEach(j => {
    const v = values[j.key];
    if (v === undefined) return;
    const valEl = document.getElementById('jm-val-' + j.key);
    if (valEl) valEl.textContent = (v >= 0 ? '+' : '') + v.toFixed(2);
    const bar = document.getElementById('jm-bar-' + j.key);
    if (bar) {
      const pct = Math.max(0, Math.min(100, (v - j.lo) / (j.hi - j.lo) * 100));
      bar.style.width = pct.toFixed(1) + '%';
    }
  });
}

function saveJointMap() {
  const map = {};
  JOINTS.forEach(j => {
    const portEl  = document.getElementById('jm-port-'  + j.key);
    const invEl   = document.getElementById('jm-inv-'   + j.key);
    const scaleEl = document.getElementById('jm-scale-' + j.key);
    if (!portEl) return;
    const port = parseInt(portEl.value, 10);
    if (port < 0) return;
    map[j.key] = {
      port,
      invert: invEl  ? invEl.checked : false,
      scale:  scaleEl ? parseFloat(scaleEl.value) / 100 : 1.0,
    };
  });
  fetch(API + '/api/config', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({joint_map: map})
  }).then(r => r.json()).then(d => {
    const el = document.getElementById('jm-msg');
    el.style.color = d.ok ? '#06d6a0' : '#e63946';
    el.textContent = d.ok ? `✓ Saved ${Object.keys(map).length} mapping(s)` : '✗ ' + JSON.stringify(d);
    setTimeout(() => el.textContent = '', 4000);
  });
}

// ── Live output ───────────────────────────────────────────────────────────

function toggleLive() {
  const btn = document.getElementById('btn-live');
  const isOn = btn.classList.contains('btn-live-on');
  fetch(API + '/api/config', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({live_output: !isOn})
  }).then(r => r.json()).then(d => { if (d.ok) setLiveBtn(!isOn); });
}

function setLiveBtn(on) {
  const btn = document.getElementById('btn-live');
  if (!btn) return;
  btn.textContent = on ? '⏹ Live On' : '⏵ Live Output';
  btn.className   = 'pc-btn ' + (on ? 'btn-live-on' : 'btn-live-off');
}

// ── Smoothing ─────────────────────────────────────────────────────────────

function saveSmoothing() {
  const smoothing       = parseFloat(document.getElementById('sl-smoothing').value);
  const servo_smoothing = parseFloat(document.getElementById('sl-servo').value);
  fetch(API + '/api/config', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({smoothing, servo_smoothing})
  }).then(r => r.json()).then(d => {
    const el = document.getElementById('settings-msg');
    el.style.color = d.ok ? '#06d6a0' : '#e63946';
    el.textContent = d.ok ? '✓ Saved' : '✗ ' + JSON.stringify(d);
    setTimeout(() => el.textContent = '', 3000);
  });
}

// ── Recording / Playback ──────────────────────────────────────────────────

function recStart() { fetch(API + '/api/record/start',  {method:'POST'}).then(pollStatus); }
function recStop()  { fetch(API + '/api/record/stop',   {method:'POST'}).then(pollStatus); }
function pbStart()  { fetch(API + '/api/playback/start',{method:'POST'}).then(pollStatus); }
function pbPause()  { fetch(API + '/api/playback/pause',{method:'POST'}).then(pollStatus); }
function pbStop()   { fetch(API + '/api/playback/stop', {method:'POST'}).then(pollStatus); }

// ── Session files ─────────────────────────────────────────────────────────

function sessSave() {
  const name = document.getElementById('sess-save-name').value.trim() || 'session.json';
  fetch(API + '/api/session/save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: name})
  }).then(r => r.json()).then(d => {
    showMsg(d.ok ? `✓ Saved ${d.frames} frames → ${d.path}` : '✗ ' + d.error, d.ok);
    if (d.ok) refreshSessions();
  });
}

function sessLoad() {
  const sel = document.getElementById('sess-load-sel');
  if (!sel.value) return;
  fetch(API + '/api/session/load', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: sel.value})
  }).then(r => r.json()).then(d => {
    showMsg(d.ok ? `✓ Loaded ${d.frames} frames (${d.duration}s)` : '✗ ' + d.error, d.ok);
    if (d.ok) pollStatus();
  });
}

function refreshSessions() {
  fetch(API + '/api/sessions').then(r => r.json()).then(list => {
    const sel = document.getElementById('sess-load-sel');
    const cur = sel.value;
    sel.innerHTML = list.map(s => `<option${s === cur ? ' selected' : ''}>${s}</option>`).join('');
  });
}

function showMsg(msg, ok = true) {
  const el = document.getElementById('status-msg');
  el.style.color = ok ? '#06d6a0' : '#e63946';
  el.textContent = msg;
  setTimeout(() => el.textContent = '', 5000);
}

// ── Export ────────────────────────────────────────────────────────────────

function exportFseq() {
  const name         = document.getElementById('fseq-name').value.trim() || 'capture.fseq';
  const step_time_ms = getStepTimeMs();
  const msg          = document.getElementById('export-msg');
  msg.style.color    = '#888';
  msg.textContent    = 'Exporting…';

  // Persist the chosen frame rate to config
  fetch(API + '/api/config', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({step_time_ms})
  });

  fetch(API + '/api/export', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({filename: name, step_time_ms})
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      const fps = (1000 / step_time_ms).toFixed(step_time_ms < 100 ? 0 : 1);
      msg.style.color = '#06d6a0';
      msg.textContent = `✓ ${d.frames} frames at ${fps} fps · ${d.duration}s · ${d.channels} ch → ${d.path}`;
      showXLightsMap(d);
    } else {
      msg.style.color = '#e63946';
      msg.textContent = '✗ ' + d.error;
      document.getElementById('export-xlights').style.display = 'none';
    }
  });
}

function showXLightsMap(d) {
  const xl  = document.getElementById('export-xlights');
  const map = document.getElementById('export-ch-map');
  if (d.start_channel !== undefined && JM_ports.length > 0) {
    map.innerHTML = JM_ports.map(p => {
      const ch1   = d.start_channel + p.port * 2;
      const ch2   = ch1 + 1;
      const label = p.desc || `Port ${p.port}`;
      return `<div style="padding:2px 0;">Port ${p.port}
        <span style="color:#e0e0e0; font-family:sans-serif; font-size:11px;">${label}</span>
        &rarr; xLights ch
        <strong style="color:#4cc9f0;">${ch1}</strong>&ndash;<strong style="color:#4cc9f0;">${ch2}</strong>
      </div>`;
    }).join('');
    xl.style.display = 'block';
  }
}

// ── Status poll ───────────────────────────────────────────────────────────

function pollStatus() {
  fetch(API + '/api/status').then(r => r.json()).then(s => {
    // Recording
    const recBadge = document.getElementById('badge-rec');
    recBadge.textContent = s.recording ? '● REC' : 'IDLE';
    recBadge.className   = 'pc-badge ' + (s.recording ? 'badge-rec' : 'badge-idle');
    document.getElementById('btn-rec').style.display  = s.recording ? 'none' : '';
    document.getElementById('btn-srec').style.display = s.recording ? ''     : 'none';
    document.getElementById('rec-info').innerHTML =
      s.duration_str + ' &nbsp;·&nbsp; ' + s.frame_count + ' frames';

    // Playback
    const pbBadge = document.getElementById('badge-play');
    let pbLabel = 'STOPPED';
    if (s.playing && s.paused) pbLabel = '⏸ PAUSED';
    else if (s.playing)        pbLabel = '▶ PLAYING';
    pbBadge.textContent = pbLabel;
    pbBadge.className   = 'pc-badge ' + (s.playing ? 'badge-play' : 'badge-idle');
    if (s.playing) {
      const m   = Math.floor(s.pb_timestamp / 60);
      const sec = (s.pb_timestamp % 60).toFixed(1).padStart(4, '0');
      document.getElementById('pb-pos').textContent =
        `${String(m).padStart(2,'0')}:${sec}  (${s.pb_pos + 1}/${s.frame_count})`;
    } else {
      document.getElementById('pb-pos').textContent = '—';
    }

    // Live tracked values panel
    for (const [k, v] of Object.entries(s.values || {})) {
      const el = document.getElementById('tv-' + k);
      if (el) el.textContent = (v >= 0 ? '+' : '') + v.toFixed(2);
    }

    // Joint mapping: build once on first load, update bars on every tick
    if (!JM_built && s.ports && s.ports.length > 0) {
      buildJointTable(s.ports, s.joint_map || {});
    }
    if (JM_built) updateJointBars(s.values || {});

    setLiveBtn(!!s.live_output);

    const ownerCard = document.getElementById('cam-ownership-card');
    if (ownerCard) ownerCard.style.display = s.cam_running ? 'none' : '';
  }).catch(() => {});
}

// ── Camera claim / release ────────────────────────────────────────────────

function claimCamera() {
  const msg = document.getElementById('cam-claim-msg');
  msg.style.color = '#888';
  msg.textContent = 'Releasing from Live Follow…';
  // Swallow live-follow errors — if it's not running the camera is already free
  fetch('/fpp-live-follow-api/api/camera/release', {method: 'POST'})
    .catch(() => null)
    .then(() => {
      msg.textContent = 'Opening camera…';
      return fetch(API + '/api/camera/retry', {method: 'POST'});
    })
    .then(r => r.json())
    .then(d => {
      if (d.cam_running) {
        msg.style.color = '#06d6a0';
        msg.textContent = '✓ Camera claimed';
        document.getElementById('cam-ownership-card').style.display = 'none';
      } else {
        msg.style.color = '#e63946';
        msg.textContent = '✗ Camera still unavailable — try again';
      }
    })
    .catch(() => {
      msg.style.color = '#e63946';
      msg.textContent = '✗ Could not reach Performance Capture daemon';
    });
}

function restoreLiveFollow() {
  const msg = document.getElementById('cam-claim-msg');
  msg.style.color = '#888';
  msg.textContent = 'Restoring Live Follow camera…';
  fetch('/fpp-live-follow-api/api/camera/restore', {method: 'POST'})
    .then(() => {
      msg.style.color = '#06d6a0';
      msg.textContent = '✓ Live Follow camera restored';
    })
    .catch(() => {
      msg.style.color = '#e63946';
      msg.textContent = '✗ Could not reach Live Follow daemon';
    });
}

setInterval(pollStatus, 500);
pollStatus();
</script>
