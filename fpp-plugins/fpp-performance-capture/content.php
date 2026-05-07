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
</style>

<div style="max-width:960px;">

<!-- Camera + tracked values side by side -->
<div style="display:flex; gap:16px; margin-bottom:16px;">

  <!-- Camera stream -->
  <div class="pc-card" style="flex:1; min-width:0;">
    <h3>Camera Feed</h3>
    <img src="http://<?= $_SERVER['HTTP_HOST'] ?>:5002/stream"
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
                        'left_eye_open','right_eye_open','left_eyebrow_raise','right_eyebrow_raise'] as $k): ?>
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
const API = 'http://' + location.hostname + ':5002';

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

    // Tracked values
    for (const [k, v] of Object.entries(s.values || {})) {
      const el = document.getElementById('tv-' + k);
      if (el) el.textContent = (v >= 0 ? '+' : '') + v.toFixed(2);
    }
  }).catch(() => {});
}

setInterval(pollStatus, 500);
pollStatus();
</script>
