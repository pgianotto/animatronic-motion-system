<?php
$DAEMON = 'http://localhost:5002';
$status   = @json_decode(file_get_contents("$DAEMON/api/status"),    true) ?? [];
$cfg      = @json_decode(file_get_contents("$DAEMON/api/config"),     true) ?? [];
$sessions = @json_decode(file_get_contents("$DAEMON/api/sessions"),   true) ?? [];

$recording = $status['recording'] ?? false;
$playing   = $status['playing']   ?? false;
$paused    = $status['paused']    ?? false;
$fc        = $status['frame_count'] ?? 0;
$dur       = $status['duration_str'] ?? '00:00.0';
?>

<style>
.pc-card      { background:#16213e; border-radius:8px; padding:18px; margin-bottom:16px; }
.pc-card h3   { color:#f72585; margin:0 0 12px; font-size:13px; letter-spacing:1px; text-transform:uppercase; }
.pc-row       { display:flex; align-items:center; gap:12px; margin-bottom:8px; flex-wrap:wrap; }
.pc-label     { color:#888; font-size:12px; width:120px; flex-shrink:0; }
.pc-value     { color:#e0e0e0; font-size:13px; font-family:monospace; }
.pc-btn       { padding:8px 18px; border:none; border-radius:5px; font-weight:bold; cursor:pointer; font-size:13px; }
.btn-rec      { background:#f72585; color:#fff; }
.btn-stop-rec { background:#888;    color:#fff; }
.btn-play     { background:#06d6a0; color:#000; }
.btn-pause    { background:#fb8500; color:#000; }
.btn-stop-pb  { background:#e63946; color:#fff; }
.btn-export   { background:#7209b7; color:#fff; }
.btn-save     { background:#0f3460; color:#4cc9f0; border:1px solid #4cc9f0; }
.pc-input     { background:#0f3460; color:#e0e0e0; border:1px solid #555; border-radius:4px; padding:5px 8px; font-size:13px; }
.pc-select    { background:#0f3460; color:#e0e0e0; border:1px solid #4cc9f0; border-radius:4px; padding:5px 10px; font-size:13px; }
.pc-stream    { border:2px solid #0f3460; border-radius:6px; max-width:100%; }
.pc-badge     { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:bold; }
.badge-rec    { background:#f72585; color:#fff; animation:pulse 1s infinite; }
.badge-play   { background:#06d6a0; color:#000; }
.badge-idle   { background:#444;    color:#aaa; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.tv-grid      { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
.tv-row       { display:flex; justify-content:space-between; padding:2px 0; border-bottom:1px solid #1a1a2e; }
.tv-key       { color:#888; font-size:11px; }
.tv-val       { color:#4cc9f0; font-family:monospace; font-size:11px; }
.tv-val.body  { color:#fb8500; }
#status-msg   { color:#06d6a0; font-size:12px; margin-top:6px; min-height:18px; }
.jm-table     { width:100%; border-collapse:collapse; font-size:12px; }
.jm-table th  { color:#888; text-align:left; padding:4px 8px; border-bottom:1px solid #2a2a4a; font-weight:normal; font-size:11px; }
.jm-table td  { padding:5px 8px; border-bottom:1px solid #1a1a2e; vertical-align:middle; }
.jm-name      { font-size:11px; width:140px; }
.jm-bar-cell  { display:flex; align-items:center; gap:8px; }
.jm-bar-bg    { flex:1; height:8px; background:#1a1a2e; border-radius:4px; overflow:hidden; min-width:60px; max-width:100px; }
.jm-bar       { height:100%; background:#4cc9f0; border-radius:4px; transition:width 0.1s; }
.jm-val       { color:#4cc9f0; font-family:monospace; font-size:10px; width:40px; text-align:right; flex-shrink:0; }
.jm-sel       { background:#0f3460; color:#e0e0e0; border:1px solid #4cc9f0; border-radius:4px; padding:3px 6px; font-size:11px; max-width:180px; }
.jm-scale-in  { background:#0f3460; color:#e0e0e0; border:1px solid #555; border-radius:4px; padding:3px 6px; font-size:11px; width:58px; text-align:center; }
.btn-live-off { background:#1a1a2e; color:#888;    border:1px solid #555; }
.btn-live-on  { background:#06d6a0; color:#000;    border:none; animation:pulse 1.5s infinite; }
</style>

<div style="max-width:960px;">

<!-- Camera + tracked values side by side -->
<div style="display:flex; gap:16px; margin-bottom:16px;">

  <!-- Camera stream -->
  <div class="pc-card" style="flex:1; min-width:0;">
    <h3>Camera Feed</h3>
    <img src="/fpp-capture-api/stream"
         class="pc-stream" style="width:100%;"
         onerror="this.style.display='none'">
  </div>

  <!-- Tracked values -->
  <div class="pc-card" style="width:280px; flex-shrink:0;">
    <h3>Tracked Values</h3>
    <div class="tv-grid">
      <div>
        <div style="color:#4cc9f0; font-size:10px; font-weight:bold; margin-bottom:4px;">FACE</div>
        <?php foreach (['head_yaw','head_pitch','head_roll','mouth_open',
                        'left_eye_open','right_eye_open','left_eyebrow_raise','right_eyebrow_raise',
                        'face_center_x','face_center_y'] as $k): ?>
        <div class="tv-row">
          <span class="tv-key"><?= str_replace('_',' ', ucfirst($k)) ?></span>
          <span class="tv-val" id="tv-<?= $k ?>"><?= number_format($status['values'][$k] ?? 0, 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div>
        <div style="color:#fb8500; font-size:10px; font-weight:bold; margin-bottom:4px;">BODY</div>
        <?php foreach (['torso_lean_lr','torso_lean_fb','torso_tilt',
                        'left_arm_raise','right_arm_raise','left_elbow_bend',
                        'right_elbow_bend','left_wrist_raise','right_wrist_raise'] as $k): ?>
        <div class="tv-row">
          <span class="tv-key"><?= str_replace('_',' ', ucfirst($k)) ?></span>
          <span class="tv-val body" id="tv-<?= $k ?>"><?= number_format($status['values'][$k] ?? 0, 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Joint Mapping -->
<div class="pc-card">
  <h3>Joint Mapping
    <span style="float:right; display:flex; gap:8px; align-items:center;">
      <button id="btn-live" class="pc-btn" style="padding:5px 14px; font-size:12px;" onclick="toggleLive()">⏵ Live Output</button>
      <button class="pc-btn btn-save"       style="padding:5px 14px; font-size:12px;" onclick="saveJointMap()">Save Mapping</button>
    </span>
  </h3>
  <p style="color:#888; font-size:11px; margin:0 0 10px;">
    Map each tracked joint to a servo port. Uses min/max calibration from the Servo Calibrator plugin.
    Set port to <em>none</em> to leave a joint unmapped.
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
      <tr><td colspan="5" style="color:#555; font-style:italic; padding:12px 8px;">Loading ports from FPP config…</td></tr>
    </tbody>
  </table>
  <div id="jm-msg" style="color:#06d6a0; font-size:12px; margin-top:6px; min-height:16px;"></div>
</div>

<!-- Record controls -->
<div class="pc-card">
  <h3>Recording</h3>
  <div class="pc-row">
    <span class="pc-badge <?= $recording ? 'badge-rec' : 'badge-idle' ?>" id="badge-rec">
      <?= $recording ? '● REC' : 'IDLE' ?>
    </span>
    <span class="pc-value" id="rec-info"><?= $dur ?> &nbsp;|&nbsp; <?= $fc ?> frames</span>
  </div>
  <div class="pc-row">
    <button class="pc-btn btn-rec"      id="btn-rec"  onclick="recStart()" <?= $recording ? 'style="display:none"' : '' ?>>● RECORD</button>
    <button class="pc-btn btn-stop-rec" id="btn-srec" onclick="recStop()"  <?= $recording ? '' : 'style="display:none"' ?>>■ STOP</button>
  </div>
</div>

<!-- Playback controls -->
<div class="pc-card">
  <h3>Playback</h3>
  <div class="pc-row">
    <span class="pc-badge <?= $playing ? 'badge-play' : 'badge-idle' ?>" id="badge-play">
      <?= $playing ? ($paused ? '⏸ PAUSED' : '▶ PLAYING') : 'STOPPED' ?>
    </span>
    <span class="pc-value" id="pb-pos">—</span>
  </div>
  <div class="pc-row">
    <button class="pc-btn btn-play"  id="btn-play"  onclick="pbStart()">▶ Play</button>
    <button class="pc-btn btn-pause" id="btn-pause" onclick="pbPause()">⏸ Pause</button>
    <button class="pc-btn btn-stop-pb"              onclick="pbStop()">■ Stop</button>
  </div>
</div>

<!-- Session save/load -->
<div class="pc-card">
  <h3>Session Files <small style="color:#888; font-weight:normal;">(raw data — reload &amp; re-export anytime)</small></h3>
  <div class="pc-row">
    <span class="pc-label">Save current as</span>
    <input class="pc-input" id="sess-save-name" value="session.json" style="width:200px;">
    <button class="pc-btn btn-save" onclick="sessSave()">Save Session</button>
  </div>
  <div class="pc-row">
    <span class="pc-label">Load session</span>
    <select class="pc-select" id="sess-load-sel" style="width:200px;">
      <?php foreach ($sessions as $s): ?>
        <option><?= htmlspecialchars($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="pc-btn btn-save" onclick="sessLoad()">Load</button>
    <button class="pc-btn" style="background:#555;color:#fff;" onclick="refreshSessions()">↻</button>
  </div>
  <div id="status-msg"></div>
</div>

<!-- Export to FPP sequences -->
<div class="pc-card">
  <h3>Export to FPP Sequences</h3>
  <div class="pc-row">
    <span class="pc-label">Filename</span>
    <input class="pc-input" id="fseq-name" value="capture.fseq" style="width:220px;">
    <button class="pc-btn btn-export" onclick="exportFseq()">Export FSEQ → FPP</button>
  </div>
  <div id="export-msg" style="color:#888; font-size:12px; margin-top:4px;"></div>
  <p style="color:#888; font-size:11px; margin:8px 0 0;">
    Files are saved to <code>/home/fpp/media/sequences/</code> and appear in FPP's scheduler immediately.
  </p>
</div>

</div>

<script>
const API = '/fpp-capture-api';

// ── Joint mapping ──────────────────────────────────────────────────────────────

const JOINTS = [
  {key:'head_yaw',           label:'Head Yaw',            lo:-45, hi:45,  group:'face'},
  {key:'head_pitch',         label:'Head Pitch',           lo:-40, hi:40,  group:'face'},
  {key:'head_roll',          label:'Head Roll',            lo:-30, hi:30,  group:'face'},
  {key:'mouth_open',         label:'Mouth Open',           lo:0,   hi:1,   group:'face'},
  {key:'left_eye_open',      label:'Left Eye Open',        lo:0,   hi:1,   group:'face'},
  {key:'right_eye_open',     label:'Right Eye Open',       lo:0,   hi:1,   group:'face'},
  {key:'left_eyebrow_raise', label:'Left Eyebrow Raise',   lo:0,   hi:1,   group:'face'},
  {key:'right_eyebrow_raise',label:'Right Eyebrow Raise',  lo:0,   hi:1,   group:'face'},
  {key:'face_center_x',      label:'Face Center X',        lo:0,   hi:1,   group:'face'},
  {key:'face_center_y',      label:'Face Center Y',        lo:0,   hi:1,   group:'face'},
  {key:'torso_lean_lr',      label:'Torso Lean L/R',       lo:-1,  hi:1,   group:'body'},
  {key:'torso_lean_fb',      label:'Torso Lean F/B',       lo:-1,  hi:1,   group:'body'},
  {key:'torso_tilt',         label:'Torso Tilt',           lo:-1,  hi:1,   group:'body'},
  {key:'left_arm_raise',     label:'Left Arm Raise',       lo:0,   hi:1,   group:'body'},
  {key:'right_arm_raise',    label:'Right Arm Raise',      lo:0,   hi:1,   group:'body'},
  {key:'left_elbow_bend',    label:'Left Elbow Bend',      lo:0,   hi:1,   group:'body'},
  {key:'right_elbow_bend',   label:'Right Elbow Bend',     lo:0,   hi:1,   group:'body'},
  {key:'left_wrist_raise',   label:'Left Wrist Raise',     lo:0,   hi:1,   group:'body'},
  {key:'right_wrist_raise',  label:'Right Wrist Raise',    lo:0,   hi:1,   group:'body'},
];

let JM_ports = [];
let JM_built = false;

function buildJointTable(ports, jointMap) {
  JM_ports = ports;
  JM_built = true;
  const tbody = document.getElementById('jm-tbody');
  if (!tbody) return;
  const portOpts = '<option value="-1">— none —</option>' +
    ports.map(p => `<option value="${p.port}">${p.port}: ${p.desc}</option>`).join('');
  tbody.innerHTML = JOINTS.map(j => {
    const m   = jointMap[j.key] || {};
    const sel = m.port !== undefined ? m.port : -1;
    const inv = m.invert ? 'checked' : '';
    const sc  = m.scale  !== undefined ? Math.round(m.scale * 100) : 100;
    const gc  = j.group === 'face' ? '#4cc9f0' : '#fb8500';
    const opts = portOpts.replace(`value="${sel}"`, `value="${sel}" selected`);
    return `<tr>
      <td class="jm-name" style="color:${gc};">${j.label}</td>
      <td><div class="jm-bar-cell">
        <div class="jm-bar-bg"><div class="jm-bar" id="jm-bar-${j.key}"></div></div>
        <span class="jm-val" id="jm-val-${j.key}">—</span>
      </div></td>
      <td><select class="jm-sel" id="jm-port-${j.key}">${opts}</select></td>
      <td style="text-align:center;"><input type="checkbox" id="jm-inv-${j.key}" ${inv}></td>
      <td><input type="number" class="jm-scale-in" id="jm-scale-${j.key}" value="${sc}" min="0" max="200" step="5"></td>
    </tr>`;
  }).join('');
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

function toggleLive() {
  const btn = document.getElementById('btn-live');
  const isOn = btn.classList.contains('btn-live-on');
  fetch(API + '/api/config', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({live_output: !isOn})
  }).then(r => r.json()).then(d => {
    if (d.ok) setLiveBtn(!isOn);
  });
}

function setLiveBtn(on) {
  const btn = document.getElementById('btn-live');
  if (!btn) return;
  btn.textContent = on ? '⏹ Live On' : '⏵ Live Output';
  btn.className   = 'pc-btn ' + (on ? 'btn-live-on' : 'btn-live-off');
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
      invert: invEl ? invEl.checked : false,
      scale:  scaleEl ? parseFloat(scaleEl.value) / 100 : 1.0,
    };
  });
  fetch(API + '/api/config', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({joint_map: map})
  }).then(r => r.json()).then(d => {
    const el = document.getElementById('jm-msg');
    el.textContent = d.ok ? `✓ Saved ${Object.keys(map).length} joint mapping(s)` : '✗ ' + JSON.stringify(d);
    setTimeout(() => el.textContent = '', 4000);
  });
}

function recStart() {
  fetch(API + '/api/record/start', {method:'POST'}).then(pollStatus);
}
function recStop() {
  fetch(API + '/api/record/stop', {method:'POST'}).then(pollStatus);
}
function pbStart() {
  fetch(API + '/api/playback/start', {method:'POST'}).then(pollStatus);
}
function pbPause() {
  fetch(API + '/api/playback/pause', {method:'POST'}).then(pollStatus);
}
function pbStop() {
  fetch(API + '/api/playback/stop', {method:'POST'}).then(pollStatus);
}

function sessSave() {
  const name = document.getElementById('sess-save-name').value.trim() || 'session.json';
  fetch(API + '/api/session/save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: name})
  }).then(r=>r.json()).then(d => {
    showMsg(d.ok ? `Saved ${d.frames} frames → ${d.path}` : 'Error: ' + d.error);
    refreshSessions();
  });
}

function sessLoad() {
  const sel = document.getElementById('sess-load-sel');
  if (!sel.value) return;
  fetch(API + '/api/session/load', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: sel.value})
  }).then(r=>r.json()).then(d => {
    showMsg(d.ok ? `Loaded ${d.frames} frames (${d.duration}s)` : 'Error: ' + d.error);
    pollStatus();
  });
}

function refreshSessions() {
  fetch(API + '/api/sessions').then(r=>r.json()).then(list => {
    const sel = document.getElementById('sess-load-sel');
    const cur = sel.value;
    sel.innerHTML = list.map(s => `<option${s===cur?' selected':''}>${s}</option>`).join('');
  });
}

function exportFseq() {
  const name = document.getElementById('fseq-name').value.trim() || 'capture.fseq';
  document.getElementById('export-msg').textContent = 'Exporting...';
  fetch(API + '/api/export', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: name})
  }).then(r=>r.json()).then(d => {
    if (d.ok) {
      document.getElementById('export-msg').textContent =
        `✓ Exported ${d.frames} frames, ${d.channels} channels, ${d.duration}s → ${d.path}`;
    } else {
      document.getElementById('export-msg').textContent = '✗ ' + d.error;
    }
  });
}

function showMsg(msg) {
  const el = document.getElementById('status-msg');
  el.textContent = msg;
  setTimeout(() => el.textContent = '', 4000);
}

function pollStatus() {
  fetch(API + '/api/status').then(r=>r.json()).then(s => {
    // Rec badge
    document.getElementById('badge-rec').textContent  = s.recording ? '● REC' : 'IDLE';
    document.getElementById('badge-rec').className    = 'pc-badge ' + (s.recording ? 'badge-rec' : 'badge-idle');
    document.getElementById('btn-rec').style.display  = s.recording ? 'none' : '';
    document.getElementById('btn-srec').style.display = s.recording ? ''     : 'none';
    document.getElementById('rec-info').innerHTML     = s.duration_str + ' &nbsp;|&nbsp; ' + s.frame_count + ' frames';

    // Playback badge
    let pbLabel = 'STOPPED';
    if (s.playing && s.paused) pbLabel = '⏸ PAUSED';
    else if (s.playing)        pbLabel = '▶ PLAYING';
    document.getElementById('badge-play').textContent = pbLabel;
    document.getElementById('badge-play').className   = 'pc-badge ' + (s.playing ? 'badge-play' : 'badge-idle');
    if (s.playing) {
      const m = Math.floor(s.pb_timestamp / 60);
      const sec = (s.pb_timestamp % 60).toFixed(1).padStart(4,'0');
      document.getElementById('pb-pos').textContent = `${String(m).padStart(2,'0')}:${sec}  (${s.pb_pos+1}/${s.frame_count})`;
    } else {
      document.getElementById('pb-pos').textContent = '—';
    }

    // Tracked values panel
    for (const [k, v] of Object.entries(s.values || {})) {
      const el = document.getElementById('tv-' + k);
      if (el) el.textContent = (v >= 0 ? '+' : '') + v.toFixed(2);
    }

    // Joint mapping card: build table on first load, update bars every tick
    if (!JM_built && s.ports && s.ports.length > 0) {
      buildJointTable(s.ports, s.joint_map || {});
    }
    if (JM_built) updateJointBars(s.values || {});
    setLiveBtn(!!s.live_output);
  }).catch(() => {});
}

setInterval(pollStatus, 500);
pollStatus();
</script>
