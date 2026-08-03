<?php
$DAEMON   = 'http://localhost:5002';
$status   = @json_decode(file_get_contents("$DAEMON/api/status"),  true) ?? [];
$cfg      = @json_decode(file_get_contents("$DAEMON/api/config"),   true) ?? [];
$sessions = @json_decode(file_get_contents("$DAEMON/api/sessions"), true) ?? [];

$recording    = $status['recording']   ?? false;
$playing      = $status['playing']     ?? false;
$paused       = $status['paused']      ?? false;
$fc           = $status['frame_count'] ?? 0;
$dur          = $status['duration_str'] ?? '00:00.0';
$session_name = $status['session_name'] ?? '';
$cam_running  = $status['cam_running'] ?? false;
$step_time_ms = intval($cfg['step_time_ms'] ?? 50);
?>

<style>
:root {
  --bg:      #1a1a2e;
  --panel:   #16213e;
  --accent:  #0f3460;
  --cyan:    #4cc9f0;
  --green:   #06d6a0;
  --magenta: #f72585;
  --amber:   #fb8500;
  --purple:  #7209b7;
  --red:     #e63946;
  --fg:      #e0e0e0;
  --muted:   #888;
  --dark:    #0d0d1f;
  --div:     #1a1a3e;
}

.pc-wrap { max-width:1100px; }
.pc-card { background:var(--panel); border-radius:8px; padding:18px; margin-bottom:14px; }
.pc-card h3 { color:var(--magenta); margin:0 0 12px; font-size:11px; letter-spacing:1.5px;
              text-transform:uppercase; font-weight:bold; }

.pc-field  { display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
.pc-label  { color:var(--muted); font-size:12px; width:110px; flex-shrink:0; }
.pc-value  { color:var(--fg); font-size:13px; font-family:monospace; }
.pc-input  { background:var(--accent); color:var(--fg); border:1px solid #555;
             border-radius:4px; padding:5px 9px; font-size:12px; }
.pc-select { background:var(--accent); color:var(--fg); border:1px solid var(--cyan);
             border-radius:4px; padding:5px 9px; font-size:12px; }

.pc-btn    { padding:6px 16px; border:none; border-radius:5px; font-weight:bold;
             cursor:pointer; font-size:12px; white-space:nowrap; }
.btn-rec   { background:var(--magenta); color:#fff; }
.btn-stop  { background:#2a2a4a; color:var(--muted); border:1px solid #3a3a5a; }
.btn-play  { background:var(--green);   color:#000; }
.btn-pause { background:var(--amber);   color:#000; }
.btn-halt  { background:var(--red);     color:#fff; }
.btn-export{ background:var(--purple);  color:#fff; }
.btn-ghost { background:var(--accent);  color:var(--cyan); border:1px solid var(--cyan); }
.btn-muted { background:#2a2a4a;        color:#555; border:1px solid #333; }
.btn-live-off { background:var(--dark); color:var(--muted); border:1px solid #555; }
.btn-live-on  { background:var(--green);color:#000; border:none; animation:blink 1.5s infinite; }
.btn-sm    { padding:4px 10px; font-size:11px; }

.pc-badge  { display:inline-flex; align-items:center; gap:5px; padding:3px 10px;
             border-radius:12px; font-size:11px; font-weight:bold; }
.badge-rec  { background:var(--magenta); color:#fff; animation:blink 1s infinite; }
.badge-play { background:var(--green);   color:#000; }
.badge-idle { background:#2a2a4a;        color:#666; }

@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }

.rp-section { padding:10px 0; border-bottom:1px solid var(--div); }
.rp-section:last-child { border-bottom:none; }
.rp-hdr { color:var(--muted); font-size:10px; font-weight:bold; letter-spacing:1.5px;
          text-transform:uppercase; margin-bottom:8px; }

.tv-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 8px; }
.tv-grp  { font-size:10px; font-weight:bold; letter-spacing:1px; margin-bottom:4px; }
.tv-row  { display:flex; justify-content:space-between; align-items:center;
           padding:1px 0; border-bottom:1px solid var(--dark); }
.tv-key  { color:#555; font-size:10px; }
.tv-val  { color:var(--cyan); font-family:monospace; font-size:10px; }
.tv-val.body { color:var(--amber); }

.pc-scrub { width:100%; accent-color:var(--cyan); cursor:pointer; height:4px; margin:4px 0 2px; }

.cam-container { position:relative; background:#000; border-radius:4px; overflow:hidden; line-height:0; }
.cam-controls  { position:absolute; bottom:0; left:0; right:0; line-height:1;
                 background:linear-gradient(transparent, rgba(0,0,0,0.85));
                 padding:28px 10px 10px;
                 display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.pc-stream     { border-radius:4px; width:100%; display:block; }

#wf-canvas { display:block; width:100%; cursor:crosshair; border-radius:4px; background:var(--dark); }
#wf-canvas.no-session { cursor:default; }
.wf-filter { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
.wf-filter label { color:var(--fg); font-size:12px; cursor:pointer; display:flex; align-items:center; gap:4px; }
.wf-filter input[type=radio] { accent-color:var(--cyan); cursor:pointer; }
#wf-custom-checks { display:none; padding:6px 0 2px; flex-wrap:wrap; gap:4px 10px; }
#wf-custom-checks label { color:var(--muted); font-size:11px; cursor:pointer; display:flex; align-items:center; gap:3px; }
#wf-custom-checks input { accent-color:var(--cyan); cursor:pointer; }
#wf-msg { color:var(--muted); font-size:12px; text-align:center; padding:20px 0; display:none; }

.fps-seg { display:inline-flex; border-radius:5px; overflow:hidden; border:1px solid var(--cyan); }
.fps-seg input[type=radio] { display:none; }
.fps-seg label { padding:5px 14px; font-size:12px; cursor:pointer; color:var(--muted);
                 background:var(--dark); border-right:1px solid var(--cyan); white-space:nowrap; }
.fps-seg label:last-of-type { border-right:none; }
.fps-seg input[type=radio]:checked + label { background:var(--cyan); color:#000; font-weight:bold; }

.jm-table  { width:100%; border-collapse:collapse; font-size:12px; }
.jm-table th { color:var(--muted); text-align:left; padding:5px 8px;
               border-bottom:1px solid #2a2a4a; font-weight:normal; font-size:11px; }
.jm-table td { padding:4px 8px; border-bottom:1px solid var(--dark); vertical-align:middle; }
.jm-table.jm-simple .jm-col-adv { display:none; }
.jm-grp-hdr { cursor:pointer; user-select:none; }
.jm-grp-hdr .jm-grp-arrow { display:inline-block; width:10px; }
.jm-table tr.jm-row-collapsed { display:none; }
.jm-bar-bg { flex:1; height:6px; background:var(--dark); border-radius:3px; overflow:hidden; min-width:50px; max-width:90px; }
.jm-bar    { height:100%; background:var(--cyan); border-radius:3px; transition:width .1s; }
.jm-val    { color:var(--cyan); font-family:monospace; font-size:10px; width:38px; text-align:right; flex-shrink:0; }
.jm-sel    { background:var(--accent); color:var(--fg); border:1px solid var(--cyan);
             border-radius:4px; padding:3px 6px; font-size:11px; max-width:170px; }
.jm-scale-in  { background:var(--accent); color:var(--fg); border:1px solid #555;
                border-radius:4px; padding:3px 6px; font-size:11px; width:54px; text-align:center; }
.jm-model-in  { background:var(--accent); color:var(--fg); border:1px solid #555;
                border-radius:4px; padding:3px 6px; font-size:11px; width:130px; }

.pc-msg  { font-size:11px; min-height:16px; }
.pc-hint { color:#555; font-size:11px; line-height:1.5; margin:0 0 8px; }

/* ── Tabs (also act as the step/progress indicator) ────────────────────────── */
.pc-tabs  { display:flex; gap:0; margin-bottom:16px; border-bottom:2px solid var(--div); }
.pc-tab   { display:flex; align-items:center; gap:8px; padding:9px 22px; cursor:pointer; font-size:12px; font-weight:bold;
            letter-spacing:0.8px; text-transform:uppercase; color:var(--muted);
            border-bottom:2px solid transparent; margin-bottom:-2px; }
.pc-tab:hover  { color:var(--fg); }
.pc-tab.active { color:var(--cyan); border-bottom-color:var(--cyan); }
.pc-tab .sn { width:18px; height:18px; border-radius:50%; border:1px solid #444;
              display:flex; align-items:center; justify-content:center; font-size:9px; flex-shrink:0;
              color:#666; }
.pc-tab.active .sn { border-color:var(--cyan); background:var(--cyan); color:#000; }
.pc-tab.done .sn   { border-color:#2a4a3a; background:#2a4a3a; color:#06d6a0; }
.pc-tab.done.active .sn { border-color:var(--cyan); background:var(--cyan); color:#000; }
.pc-tabpanel   { display:none; }
.pc-tabpanel.active { display:block; }

/* ── Live output toggle card ────────────────────────────────────────────────── */
.live-toggle-card { display:flex; align-items:center; justify-content:space-between;
                    gap:16px; flex-wrap:wrap; }
.live-toggle-card .live-desc { flex:1; min-width:200px; }
.live-toggle-card .live-desc p { color:#555; font-size:11px; line-height:1.5; margin:4px 0 0; }
.btn-live-main { padding:12px 28px; font-size:14px; }

.exp-tabs { display:flex; gap:0; margin-bottom:16px; border-bottom:2px solid var(--div); }
.exp-tab  { padding:8px 20px; cursor:pointer; font-size:12px; font-weight:bold;
            letter-spacing:0.8px; text-transform:uppercase; color:var(--muted);
            border-bottom:2px solid transparent; margin-bottom:-2px; }
.exp-tab:hover  { color:var(--fg); }
.exp-tab.active { color:var(--cyan); border-bottom-color:var(--cyan); }
.exp-panel      { display:none; }
.exp-panel.active { display:block; }

/* ── Per-channel curve editor (modal) ───────────────────────────────────────── */
.ce-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6);
              z-index:50; align-items:center; justify-content:center; }
.ce-modal   { background:var(--panel); border-radius:8px; padding:18px;
              width:90vw; max-width:1200px; height:78vh; display:flex; flex-direction:column; }
.ce-hdr     { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.ce-hdr h3  { margin:0; }
.ce-close   { background:none; border:none; color:var(--muted); font-size:20px; cursor:pointer; line-height:1; }
.ce-close:hover { color:var(--fg); }
.ce-toolbar { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:8px; }
.ce-tool-btn.active { background:var(--cyan); color:#000; border-color:var(--cyan); }
.ce-locked-note { color:var(--amber); font-size:11px; }
#ce-canvas  { display:block; width:100%; flex:1; min-height:0; cursor:crosshair;
              border-radius:4px; background:var(--dark); }
.ce-transport { display:flex; gap:5px; align-items:center; flex-wrap:wrap; margin-top:8px; }
</style>

<div class="pc-wrap">

<!-- ══ Tabs (also the step/progress indicator) ══════════════════════════════ -->
<div class="pc-tabs">
  <div class="pc-tab active" id="tab-btn-map-test" data-tab="map-test" onclick="switchTab('map-test')"><span class="sn">1</span>Map &amp; Test</div>
  <div class="pc-tab"        id="tab-btn-record"   data-tab="record"   onclick="switchTab('record')"><span class="sn">2</span>Record</div>
  <div class="pc-tab"        id="tab-btn-review"   data-tab="review"   onclick="switchTab('review')"><span class="sn">3</span>Review &amp; Export</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 1 — MAP & TEST
     Map each joint to a servo port, enable Live Output, and confirm the
     servos respond correctly before recording.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pc-tabpanel active" id="tab-map-test">

<!-- Live Output control ─────────────────────────────────────────────────── -->
<div class="pc-card">
  <div style="display:flex; gap:14px; align-items:flex-start;">

    <!-- Small camera preview -->
    <div style="width:220px; flex-shrink:0;">
      <div class="cam-container" style="border-radius:6px;">
        <img id="map-test-stream" src="/fpp-capture-api/stream" class="pc-stream"
             onerror="this.style.display='none'" alt="">
      </div>
    </div>

    <!-- Live Output toggle + values -->
    <div style="flex:1; min-width:0;">
      <h3>Test Servos Live</h3>
      <div class="live-toggle-card">
        <div class="live-desc">
          <p>
            Moves your mapped servos live so you can check the mapping.
            Turn this off before recording — it's for testing only.
          </p>
        </div>
        <button id="btn-live"
                class="pc-btn btn-live-main <?= ($cfg['live_output'] ?? false) ? 'btn-live-on' : 'btn-live-off' ?>"
                onclick="toggleLive()">
          <?= ($cfg['live_output'] ?? false) ? '⏹ Stop Live Test' : '▶ Start Live Test' ?>
        </button>
      </div>

  <!-- Live values collapsed by default — the joint table's Live Value column already
       shows the ones that matter while mapping; this is for deeper debugging. -->
  <details style="margin-top:14px; padding-top:12px; border-top:1px solid var(--div);">
    <summary style="cursor:pointer; color:var(--muted); font-size:10px; font-weight:bold;
                     letter-spacing:1.5px; text-transform:uppercase;">Show raw tracking values</summary>
    <div class="tv-grid" style="margin-top:8px;">
      <div>
        <div class="tv-grp" style="color:var(--cyan);">FACE</div>
        <?php foreach (['head_yaw','head_pitch','head_roll','mouth_open',
                        'left_eye_open','right_eye_open',
                        'left_eyebrow_raise','right_eyebrow_raise'] as $k): ?>
        <div class="tv-row">
          <span class="tv-key"><?= str_replace('_',' ', ucwords($k,'_')) ?></span>
          <span class="tv-val" id="tv-<?= $k ?>"><?= number_format($status['values'][$k] ?? 0, 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div>
        <div class="tv-grp" style="color:var(--amber);">BODY</div>
        <?php foreach (['torso_lean_lr','torso_lean_fb','torso_tilt',
                        'left_arm_raise','right_arm_raise',
                        'left_elbow_bend','right_elbow_bend',
                        'left_wrist_raise','right_wrist_raise'] as $k): ?>
        <div class="tv-row">
          <span class="tv-key"><?= str_replace('_',' ', ucwords($k,'_')) ?></span>
          <span class="tv-val body" id="tv-<?= $k ?>"><?= number_format($status['values'][$k] ?? 0, 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </details>
    </div><!-- /right col -->
  </div><!-- /outer flex row -->
</div><!-- /live output card -->

<!-- Joint Mapping ───────────────────────────────────────────────────────── -->
<div class="pc-card">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
    <h3 style="margin:0;">Joint → Servo Mapping</h3>
    <div style="display:flex; gap:8px; align-items:center;">
      <button class="pc-btn btn-ghost btn-sm" id="btn-jm-advanced" onclick="toggleJmAdvanced()">⚙ Show advanced</button>
      <button class="pc-btn btn-ghost btn-sm" onclick="testServoOutput()" title="Send center values to all configured servo ports">▶ Test Servos</button>
      <span id="jm-test-msg" class="pc-msg" style="margin:0;"></span>
      <button class="pc-btn btn-ghost btn-sm" onclick="saveJointMap()">Save Mapping</button>
    </div>
  </div>
  <p class="pc-hint">
    Assign each tracked joint to a servo port.
    Min / max / center calibration is read from the Servo Calibrator plugin.
  </p>
  <table class="jm-table jm-simple" id="jm-table">
    <thead>
      <tr>
        <th>Joint</th><th>Live Value</th><th>Port</th>
        <th class="jm-col-adv" style="text-align:center;">Invert</th>
        <th class="jm-col-adv" title="How far the servo swings relative to your motion">Scale %</th>
        <th class="jm-col-adv">xLights Model</th>
      </tr>
    </thead>
    <tbody id="jm-tbody">
      <tr><td colspan="6" style="color:#555; font-style:italic; padding:12px 8px;">
        Loading servo ports…
      </td></tr>
    </tbody>
  </table>
  <div id="jm-msg" class="pc-msg" style="color:var(--green); margin-top:6px;"></div>
</div>

<!-- Smoothing Settings ──────────────────────────────────────────────────── -->
<div class="pc-card">
  <details>
    <summary style="cursor:pointer;"><h3 style="display:inline; margin:0;">Smoothing (advanced)</h3></summary>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 32px; max-width:600px; margin-top:12px;">
      <div>
        <div style="color:var(--muted); font-size:11px; margin-bottom:4px;">
          Tracking Smoothness
          <span style="color:#444; font-size:10px; margin-left:4px;">higher = calmer motion, but slower to follow you</span>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
          <input type="range" id="sl-smoothing" style="flex:1; accent-color:var(--cyan);"
                 min="0.05" max="0.9" step="0.05"
                 value="<?= number_format($cfg['smoothing'] ?? 0.15, 2) ?>"
                 oninput="document.getElementById('lbl-smoothing').textContent=parseFloat(this.value).toFixed(2)">
          <span id="lbl-smoothing" class="pc-value" style="width:30px; font-size:12px;"><?= number_format($cfg['smoothing'] ?? 0.15, 2) ?></span>
        </div>
      </div>
      <div>
        <div style="color:var(--muted); font-size:11px; margin-bottom:4px;">
          Servo Motion Damping
          <span style="color:#444; font-size:10px; margin-left:4px;">higher = gentler servo movement, less mechanical jitter</span>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
          <input type="range" id="sl-servo" style="flex:1; accent-color:var(--cyan);"
                 min="0.05" max="0.9" step="0.05"
                 value="<?= number_format($cfg['servo_smoothing'] ?? 0.25, 2) ?>"
                 oninput="document.getElementById('lbl-servo').textContent=parseFloat(this.value).toFixed(2)">
          <span id="lbl-servo" class="pc-value" style="width:30px; font-size:12px;"><?= number_format($cfg['servo_smoothing'] ?? 0.25, 2) ?></span>
        </div>
      </div>
    </div>
    <div style="margin-top:10px; display:flex; align-items:center; gap:10px;">
      <button class="pc-btn btn-ghost btn-sm" onclick="saveSmoothing()">Save Settings</button>
      <span id="settings-msg" class="pc-msg" style="margin:0;"></span>
    </div>
  </details>
</div>

</div><!-- /tab-map-test -->


<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 2 — RECORD
     Pick an audio track, then hit Record while performing.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pc-tabpanel" id="tab-record">

<!-- Post-record contextual save bar — shown right after a recording stops ── -->
<div class="pc-card" id="post-rec-save" style="display:none; border:1px solid var(--green);">
  <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
    <span style="color:var(--green); font-size:12px;">✓ Recording captured</span>
    <span id="post-rec-info" style="color:var(--muted); font-size:12px; font-family:monospace;"></span>
    <input class="pc-input" id="sess-save-name-inline" style="flex:1; min-width:120px; font-size:11px;">
    <button class="pc-btn btn-play btn-sm" onclick="sessSaveAndReview()">Save &amp; Review</button>
    <button class="pc-btn btn-ghost btn-sm" onclick="skipPostRecordSave()">Skip, just review</button>
  </div>
</div>

<!-- Camera + Session & Audio ────────────────────────────────────────────── -->
<div style="display:flex; gap:14px; align-items:flex-start;">

  <!-- Camera feed -->
  <div class="pc-card" style="flex:2; min-width:0; padding:0; overflow:hidden;">
    <div class="cam-container">
      <img id="rec-stream" class="pc-stream"
           onerror="this.style.display='none'" alt="">
      <div style="position:absolute; top:10px; left:10px; display:flex; gap:6px;">
        <span class="pc-badge <?= $recording ? 'badge-rec' : 'badge-idle' ?>" id="badge-rec">
          <?= $recording ? '● REC' : 'IDLE' ?>
        </span>
        <span class="pc-badge badge-idle" id="cam-status-badge" style="display:none;"></span>
      </div>
      <div class="cam-controls">
        <span id="rec-info" style="font-size:11px; color:#ccc; margin-right:auto; font-family:monospace;">
          <?= $dur ?> &nbsp;·&nbsp; <?= $fc ?> frames
        </span>
        <button class="pc-btn btn-rec"  id="btn-rec"  onclick="recStart()" <?= $recording ? 'style="display:none"' : '' ?>>● Record</button>
        <button class="pc-btn btn-stop" id="btn-srec" onclick="recStop()"  <?= $recording ? '' : 'style="display:none"' ?>>■ Stop</button>
      </div>
    </div>
    <!-- Manual fallback — only shown after automatic reconnect attempts are exhausted -->
    <div id="cam-recovery-bar" style="display:none; padding:8px 10px; background:var(--dark);
         border-top:1px solid #2a2a4a; gap:8px; align-items:center; flex-wrap:wrap;">
      <span style="color:var(--amber); font-size:11px;">Camera still busy (held by Live Follow)</span>
      <button class="pc-btn btn-play  btn-sm" onclick="claimCamera()">Try Again</button>
      <button class="pc-btn btn-ghost btn-sm" onclick="restoreLiveFollow()">Use Live Follow Instead</button>
      <span id="cam-claim-msg" class="pc-msg" style="margin:0;"></span>
    </div>
  </div>

  <!-- Session & Audio panel -->
  <div class="pc-card" style="width:290px; flex-shrink:0;">

    <div class="rp-section" style="padding-top:0;">
      <div class="rp-hdr">Open Saved Recording</div>
      <div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">
        <select class="pc-select" id="sess-load-sel" style="flex:1; min-width:0; font-size:11px;">
          <?php foreach ($sessions as $s): ?>
            <option><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="pc-btn btn-ghost btn-sm" onclick="sessLoad()">Load</button>
        <button class="pc-btn btn-halt  btn-sm" onclick="sessDelete()">Del</button>
        <button class="pc-btn btn-muted btn-sm" onclick="refreshSessions()" title="Refresh">↻</button>
      </div>
    </div>

    <div class="rp-section">
      <div class="rp-hdr">Audio Track</div>
      <div style="display:flex; gap:4px; align-items:center;">
        <select class="pc-select" id="audio-sel" style="flex:1; min-width:0; font-size:11px;" onchange="setAudioFile(this.value)">
          <option value="">— none —</option>
        </select>
        <button class="pc-btn btn-muted btn-sm" onclick="loadMediaFiles()" title="Refresh">↻</button>
      </div>
    </div>

    <div class="rp-section">
      <div class="rp-hdr">Audio Output</div>
      <div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">
        <select class="pc-select" id="audio-out-sel" style="flex:1; min-width:0; font-size:11px;" onchange="setAudioOutput(this.value)">
          <option value="browser">Browser (your computer speakers)</option>
        </select>
        <button class="pc-btn btn-ghost btn-sm" onclick="testAudio()">Test</button>
        <button class="pc-btn btn-muted btn-sm" onclick="loadAudioDevices()" title="Refresh">↻</button>
      </div>
      <span id="audio-msg" class="pc-msg" style="display:block; margin-top:3px;"></span>
    </div>

    <span id="status-msg" class="pc-msg" style="display:block; margin-top:8px; padding-top:4px;"></span>
    <audio id="audio-player" preload="none" style="display:none;"></audio>

  </div><!-- /session & audio panel -->
</div><!-- /camera row -->

</div><!-- /tab-record -->


<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 3 — REVIEW & EXPORT
     Scrub through the recording, edit curves, then export for xLights.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pc-tabpanel" id="tab-review">

<!-- Servo output warning (shown when writer_ok=false or no joints mapped) ── -->
<div id="servo-warn" class="pc-card" style="display:none; border:1px solid var(--amber); padding:10px 18px; margin-bottom:14px;">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
    <div id="servo-warn-text" style="color:var(--amber); font-size:12px; flex:1;"></div>
    <button class="pc-btn btn-ghost btn-sm" onclick="testServoOutput()">▶ Test Servo Output</button>
  </div>
  <div id="servo-test-msg" class="pc-msg" style="margin-top:4px;"></div>
</div>

<!-- Current session info ────────────────────────────────────────────────── -->
<div class="pc-card" style="padding:12px 18px; margin-bottom:14px;">
  <div id="sess-status" style="font-size:12px; font-family:monospace; color:var(--fg); line-height:1.8;">
    <?php if ($session_name): ?>
      <span style="color:var(--cyan);"><?= htmlspecialchars($session_name) ?></span>
      &nbsp;·&nbsp; <span id="sess-frames" style="color:var(--muted);"><?= $fc ?> frames</span>
      &nbsp;·&nbsp; <span id="sess-dur" style="color:var(--muted);"><?= $dur ?></span>
    <?php else: ?>
      <span style="color:#444;">No session loaded — record in the Record tab or load a saved session.</span>
    <?php endif; ?>
  </div>
</div>

<!-- Timeline / Waveform editor ──────────────────────────────────────────── -->
<div class="pc-card">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
    <h3 style="margin:0;">Review</h3>
    <div class="wf-filter">
      <label><input type="radio" name="wf-filter" value="all" checked onchange="wfSetFilter('all')"> All</label>
      <label><input type="radio" name="wf-filter" value="servo" onchange="wfSetFilter('servo')"> Mapped Joints Only</label>
      <label><input type="radio" name="wf-filter" value="select" onchange="wfSetFilter('select')"> Choose Channels…</label>
    </div>
  </div>

  <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-bottom:8px;">
    <button class="pc-btn btn-ghost btn-sm" id="btn-edit-mode" onclick="toggleEditMode()">✎ Draw</button>
    <span id="lock-status" style="color:var(--amber); font-size:11px;"></span>
    <span style="color:#444; font-size:11px; margin-left:auto;">Click a channel name for a larger editor</span>
  </div>

  <input type="range" class="pc-scrub" id="scrub-slider"
         min="0" max="<?= max(1, $fc - 1) ?>" value="0"
         <?= ($fc > 0) ? '' : 'disabled' ?>
         oninput="onScrub(this.value)">
  <div style="display:flex; justify-content:space-between; font-size:10px;
              color:#444; margin-bottom:8px; font-family:monospace;">
    <span id="scrub-pos">00:00.0</span>
    <span id="scrub-dur"><?= $dur ?></span>
  </div>

  <div style="display:flex; gap:5px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
    <button class="pc-btn btn-play  btn-sm" onclick="tlPlay()">▶ Play</button>
    <button class="pc-btn btn-pause btn-sm" onclick="tlPause()">⏸ Pause</button>
    <button class="pc-btn btn-halt  btn-sm" onclick="tlStop()">■ Stop</button>
    <button class="pc-btn btn-ghost btn-sm" onclick="tlRestart()">⏮ Restart</button>
    <button class="pc-btn btn-ghost btn-sm" id="btn-tl-half" onclick="tlToggleSpeed()">½×</button>
    <button class="pc-btn btn-ghost btn-sm" id="btn-tl-loop" onclick="tlToggleLoop()">↻ Loop</button>
    <span style="color:#2a2a4a; margin:0 2px;">|</span>
    <button class="pc-btn btn-muted btn-sm" id="btn-undo" onclick="undoLastEdit()" disabled>↩ Undo</button>
    <button class="pc-btn btn-rec   btn-sm" onclick="rerecordStart()">⏺ Re-record</button>
    <span id="tl-status" style="color:var(--muted); font-size:11px; font-family:monospace; margin-left:4px;"></span>
  </div>

  <div id="wf-custom-checks" style="display:flex;"></div>
  <canvas id="wf-canvas" class="no-session"></canvas>
  <div id="wf-msg">Load or record a session to see the waveform.</div>
</div>

<!-- Export ──────────────────────────────────────────────────────────────── -->
<div class="pc-card">
  <h3>Export</h3>

  <!-- Shared frame-rate selector -->
  <div class="pc-field" style="margin-bottom:16px;">
    <span class="pc-label">Frame Rate</span>
    <div class="fps-seg">
      <input type="radio" name="fps" id="fps-20" value="50"   <?= $step_time_ms == 50 ? 'checked' : '' ?>>
      <label for="fps-20">20 fps</label>
      <input type="radio" name="fps" id="fps-40" value="25"   <?= $step_time_ms == 25 ? 'checked' : '' ?>>
      <label for="fps-40">40 fps</label>
      <input type="radio" name="fps" id="fps-cust" value="custom"
             <?= ($step_time_ms != 50 && $step_time_ms != 25) ? 'checked' : '' ?>>
      <label for="fps-cust">Custom</label>
    </div>
    <input type="number" id="fps-custom-val" class="pc-input"
           value="<?= $step_time_ms ?>" min="10" max="500" style="width:65px;"
           <?= ($step_time_ms != 50 && $step_time_ms != 25) ? '' : 'style="display:none"' ?>
           oninput="onCustomFpsInput()">
    <span id="fps-label" style="color:#555; font-size:11px;"></span>
  </div>

  <!-- Export type tabs -->
  <div class="exp-tabs">
    <div class="exp-tab active" data-exp="fpp"     onclick="switchExportTab('fpp')">FPP Direct</div>
    <div class="exp-tab"        data-exp="xlights" onclick="switchExportTab('xlights')">xLights</div>
  </div>

  <!-- FPP Direct panel -->
  <div class="exp-panel active" id="exp-fpp">
    <p class="pc-hint" style="margin-bottom:12px;">
      Saves an FSEQ directly to FPP&rsquo;s sequences folder. No xLights needed —
      go to FPP&rsquo;s scheduler and add the file to play it.
    </p>
    <div class="pc-field">
      <span class="pc-label">Filename</span>
      <input class="pc-input" id="fseq-name" value="capture" style="width:180px;" placeholder="no extension" oninput="this.dataset.dirty='1'">
      <button class="pc-btn btn-export" onclick="exportFseq()">Export FSEQ for FPP</button>
    </div>
    <div id="fseq-msg" class="pc-msg"></div>
    <div id="fseq-ch-map" style="display:none; margin-top:10px; padding:10px;
         background:var(--dark); border-radius:5px; border:1px solid #1a2a4a;">
      <div style="color:var(--cyan); font-weight:bold; font-size:11px; margin-bottom:6px;">FPP Channel Mapping</div>
      <div id="fseq-ch-map-inner" style="font-size:11px; font-family:monospace;"></div>
    </div>
  </div>

  <!-- xLights panel -->
  <div class="exp-panel" id="exp-xlights">
    <p class="pc-hint" style="margin-bottom:12px;">
      Exports an <code>.xsq</code> file for xLights. Open it in xLights,
      add your lighting effects on other channels, then upload to FPP.
      The servo channels are embedded as a data layer — no model or controller
      setup needed in xLights.
    </p>
    <div class="pc-field">
      <span class="pc-label">Filename</span>
      <input class="pc-input" id="xsq-name" value="capture" style="width:180px;" placeholder="no extension" oninput="this.dataset.dirty='1'">
      <button class="pc-btn btn-ghost" onclick="exportXlights()">Export XSQ for xLights</button>
    </div>
    <div id="xsq-msg" class="pc-msg"></div>
    <div id="xsq-downloads" style="display:none; gap:8px; flex-wrap:wrap; margin-top:8px;"></div>
  </div>
</div>

</div><!-- /tab-review -->

<!-- ══ Per-channel curve editor (modal) ══════════════════════════════════════ -->
<div class="ce-overlay" id="ce-overlay">
  <div class="ce-modal">
    <div class="ce-hdr">
      <h3 id="ce-title">Channel</h3>
      <button class="ce-close" onclick="closeChannelEditor()">×</button>
    </div>
    <div class="ce-toolbar" id="ce-toolbar">
      <button class="pc-btn btn-ghost btn-sm ce-tool-btn" id="ce-tool-draw"    onclick="ceSetTool('draw')">✎ Draw</button>
      <button class="pc-btn btn-ghost btn-sm ce-tool-btn" id="ce-tool-point"   onclick="ceSetTool('point')">● Add Point</button>
      <button class="pc-btn btn-ghost btn-sm ce-tool-btn" id="ce-tool-scissors" onclick="ceSetTool('scissors')">✂ Scissors</button>
      <span style="color:#2a2a4a; margin:0 2px;">|</span>
      <button class="pc-btn btn-play btn-sm" id="ce-btn-apply" onclick="ceApplyPoints()" disabled>Apply Points</button>
      <button class="pc-btn btn-ghost btn-sm" id="ce-btn-cancel" onclick="ceCancelPoints()" disabled>Cancel</button>
      <span style="color:#2a2a4a; margin:0 2px;">|</span>
      <span style="color:var(--muted); font-size:11px;">Smooth window</span>
      <input type="number" class="pc-input" id="ce-smooth-window" value="5" min="2" max="60" style="width:55px;">
      <button class="pc-btn btn-ghost btn-sm" id="ce-btn-smooth" onclick="ceSmooth()">〜 Smooth</button>
      <span id="ce-locked-note" class="ce-locked-note" style="display:none;">🔒 Channel is locked — unlock it in the timeline to edit</span>
      <span id="ce-msg" class="pc-msg" style="margin-left:auto;"></span>
    </div>
    <canvas id="ce-canvas"></canvas>
    <div class="ce-transport">
      <button class="pc-btn btn-play  btn-sm" onclick="tlPlay()">▶ Play</button>
      <button class="pc-btn btn-pause btn-sm" onclick="tlPause()">⏸ Pause</button>
      <button class="pc-btn btn-halt  btn-sm" onclick="tlStop()">■ Stop</button>
      <input type="range" class="pc-scrub" id="ce-scrub-slider" style="flex:1;" min="0" max="1" value="0" oninput="onScrub(this.value)">
    </div>
  </div>
</div>

</div><!-- .pc-wrap -->

<script>
const API = '/fpp-capture-api';

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
let JM_built  = false;

// ── Tab switching ─────────────────────────────────────────────────────────────
const STREAM_URL = API + '/stream';

function switchTab(name) {
  document.querySelectorAll('.pc-tab').forEach(t =>
    t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.pc-tabpanel').forEach(p =>
    p.classList.toggle('active', p.id === 'tab-' + name));
  // Only keep one MJPEG connection open at a time
  const mapImg = document.getElementById('map-test-stream');
  const recImg = document.getElementById('rec-stream');
  if (mapImg) mapImg.src = (name === 'map-test') ? STREAM_URL : '';
  if (recImg) recImg.src = (name === 'record')   ? STREAM_URL : '';
}

// ── Stepper (done-state on the tabs themselves) ───────────────────────────────
let _exportedThisSession = sessionStorage.getItem('pc-exported') === '1';

function markExported() {
  _exportedThisSession = true;
  sessionStorage.setItem('pc-exported', '1');
  updateStepper(_lastStatus || {});
}

let _lastStatus = null;
function updateStepper(s) {
  _lastStatus = s;
  const mapDone    = (s.joint_map_count || 0) > 0;
  const recordDone = (s.frame_count || 0) > 0;
  const exportDone = _exportedThisSession;
  const set = (id, done) => {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('done', done);
  };
  set('tab-btn-map-test', mapDone);
  set('tab-btn-record',   recordDone);
  set('tab-btn-review',   exportDone);
}

// ── Waveform ──────────────────────────────────────────────────────────────────
const WF = {
  data: null, filter: 'all',
  checked: Object.fromEntries(JOINTS.map(j => [j.key, true])),
  cursor: 0, dragging: false,
  LOCK_W:22, LABEL_W:110, VAL_W:50, ROW_H:28, RULER_H:18, PAD:4,
  FACE_COL:'#4cc9f0', BODY_COL:'#fb8500',
  editMode: false, _drawing: false, _drawChannel: null, _drawEdits: [], _drawSnapshot: null,

  visible() {
    if (!this.data) return [];
    const keys = Object.keys(this.data.data);
    if (this.filter === 'servo')  return JOINTS.filter(j => this.data.servo_mapped.includes(j.key) && keys.includes(j.key));
    if (this.filter === 'select') return JOINTS.filter(j => this.checked[j.key] && keys.includes(j.key));
    return JOINTS.filter(j => keys.includes(j.key));
  },

  load() {
    fetch(API + '/api/session/frames')
      .then(r => r.json())
      .then(d => {
        this.data = d; this.cursor = 0;
        const hasFrames = d.total_frames > 0;
        document.getElementById('wf-canvas').classList.toggle('no-session', !hasFrames);
        document.getElementById('wf-msg').style.display = hasFrames ? 'none' : '';
        this.buildCustomChecks();
        this.draw();
      }).catch(() => {});
  },

  buildCustomChecks() {
    const el = document.getElementById('wf-custom-checks');
    el.innerHTML = '';
    JOINTS.forEach(j => {
      const color = j.group === 'face' ? this.FACE_COL : this.BODY_COL;
      const cb = document.createElement('input');
      cb.type = 'checkbox'; cb.checked = this.checked[j.key] !== false;
      cb.id = 'wf-cb-' + j.key;
      cb.onchange = () => { this.checked[j.key] = cb.checked; this.draw(); };
      const lbl = document.createElement('label');
      lbl.htmlFor = cb.id; lbl.style.color = color; lbl.textContent = j.label;
      lbl.prepend(cb); el.appendChild(lbl);
    });
  },

  draw() {
    const canvas = document.getElementById('wf-canvas');
    const vis = this.visible();
    const dpr = window.devicePixelRatio || 1;
    const cssW = canvas.offsetWidth || 800;
    const nRows = vis.length;
    const cssH = nRows > 0 ? (this.RULER_H + nRows * this.ROW_H) : 60;

    canvas.width  = cssW * dpr;
    canvas.height = cssH * dpr;
    canvas.style.height = cssH + 'px';

    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    const waveX = this.LOCK_W + this.LABEL_W;
    const waveW = Math.max(cssW - waveX - this.VAL_W, 1);

    if (!this.data || this.data.total_frames === 0 || nRows === 0) {
      ctx.fillStyle = '#0d0d1f'; ctx.fillRect(0, 0, cssW, cssH);
      ctx.fillStyle = '#555'; ctx.font = '12px sans-serif'; ctx.textAlign = 'center';
      ctx.fillText(nRows === 0 ? 'No channels selected' : 'No session loaded', cssW/2, cssH/2 + 4);
      return;
    }

    const n = this.data.timestamps.length;
    const dur = this.data.duration || 1;

    ctx.fillStyle = '#080816'; ctx.fillRect(0, 0, cssW, this.RULER_H);
    ctx.strokeStyle = '#333'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(waveX, 0); ctx.lineTo(waveX, this.RULER_H); ctx.stroke();
    const hx = this.LOCK_W / 2, hy = this.RULER_H / 2 + 1;
    ctx.strokeStyle = '#3a3a5a'; ctx.lineWidth = 1.2;
    ctx.strokeRect(hx - 4, hy - 2, 8, 6);
    ctx.beginPath(); ctx.arc(hx, hy - 2, 2.5, Math.PI, 0); ctx.stroke();

    const tick = [0.1,0.5,1,2,5,10,30,60].find(m => dur/m <= 14) || 60;
    ctx.fillStyle = '#666'; ctx.font = '9px monospace'; ctx.textAlign = 'center';
    for (let t = 0; t <= dur + tick*0.01; t += tick) {
      const rx = waveX + (t / dur) * waveW;
      ctx.beginPath(); ctx.moveTo(rx, this.RULER_H-4); ctx.lineTo(rx, this.RULER_H); ctx.stroke();
      const mm = Math.floor(t/60), ss = (t%60).toFixed(1).padStart(4,'0');
      ctx.fillText(`${mm}:${ss}`, rx, this.RULER_H - 5);
    }

    vis.forEach((j, ri) => {
      const ry    = this.RULER_H + ri * this.ROW_H;
      const rowBg = ri % 2 === 0 ? '#14142a' : '#0d0d1f';
      const color = j.group === 'face' ? this.FACE_COL : this.BODY_COL;
      const mapped = this.data.servo_mapped.includes(j.key);
      const vals   = this.data.data[j.key] || [];

      ctx.fillStyle = rowBg; ctx.fillRect(0, ry, cssW, this.ROW_H);
      if (_locked[j.key]) { ctx.fillStyle = 'rgba(251,133,0,0.09)'; ctx.fillRect(0, ry, cssW, this.ROW_H); }
      if (mapped) { ctx.fillStyle = color; ctx.fillRect(0, ry, 3, this.ROW_H); }
      if (WF.editMode && !_locked[j.key]) { ctx.fillStyle = 'rgba(76,201,240,0.04)'; ctx.fillRect(waveX, ry, waveW, this.ROW_H); }
      if (WF._drawChannel === j.key) { ctx.fillStyle = 'rgba(76,201,240,0.10)'; ctx.fillRect(waveX, ry, waveW, this.ROW_H); }
      const lx = 4, lcy = ry + this.ROW_H / 2 - 1;
      ctx.lineWidth = 1.5; ctx.strokeStyle = _locked[j.key] ? '#fb8500' : '#2a2a3a';
      ctx.strokeRect(lx, lcy, 10, 7);
      ctx.beginPath();
      _locked[j.key] ? ctx.arc(lx + 5, lcy, 3, Math.PI, 0) : ctx.arc(lx + 5, lcy, 3, Math.PI, Math.PI * 1.6);
      ctx.stroke();

      ctx.font = `${mapped ? 'bold ' : ''}10px sans-serif`;
      ctx.fillStyle = mapped ? color : '#555';
      ctx.textAlign = 'right';
      ctx.fillText(j.label, waveX - 6, ry + this.ROW_H/2 + 3);

      ctx.strokeStyle = '#1e1e3a'; ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(waveX, ry); ctx.lineTo(waveX, ry + this.ROW_H); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(0, ry + this.ROW_H - 1); ctx.lineTo(cssW, ry + this.ROW_H - 1); ctx.stroke();

      if (vals.length < 2) return;
      let lo = Math.min(...vals), hi = Math.max(...vals);
      if (hi - lo < 1e-4) { lo -= 0.5; hi += 0.5; }
      const span = hi - lo;
      const yFor = v => ry + this.ROW_H - this.PAD - ((v-lo)/span) * (this.ROW_H - 2*this.PAD);

      if (lo <= 0 && 0 <= hi) {
        ctx.strokeStyle = '#2a2a50'; ctx.lineWidth = 1; ctx.setLineDash([3,4]);
        ctx.beginPath(); ctx.moveTo(waveX, yFor(0)); ctx.lineTo(waveX+waveW, yFor(0)); ctx.stroke();
        ctx.setLineDash([]);
      }

      ctx.strokeStyle = color; ctx.lineWidth = 1.2; ctx.beginPath();
      this.data.timestamps.forEach((ts, i) => {
        const x = waveX + (ts / dur) * waveW;
        const y = yFor(vals[i] ?? 0);
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
      });
      ctx.stroke();
    });

    this.drawPlayhead(ctx, cssW, waveX, waveW, dur, vis);
  },

  drawPlayhead(ctx, cssW, waveX, waveW, dur, vis) {
    if (!this.data || !this.data.total_frames) return;
    const n    = this.data.timestamps.length;
    const step = Math.max(1, this.data.total_frames / n);
    const sIdx = Math.min(Math.round(this.cursor / step), n - 1);
    const ts   = this.data.timestamps[sIdx] ?? 0;
    const x    = waveX + (ts / Math.max(dur, 1e-6)) * waveW;
    const totalH = this.RULER_H + vis.length * this.ROW_H;

    ctx.strokeStyle = '#e63946'; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, totalH); ctx.stroke();
    ctx.fillStyle = '#e63946';
    ctx.beginPath(); ctx.moveTo(x-5,0); ctx.lineTo(x+5,0); ctx.lineTo(x,9); ctx.closePath(); ctx.fill();

    const vals = vis.map(j => (this.data.data[j.key] || [])[sIdx] ?? 0);
    ctx.textAlign = 'right';
    vis.forEach((j, ri) => {
      const ry    = this.RULER_H + ri * this.ROW_H;
      const v     = vals[ri];
      const color = j.group === 'face' ? this.FACE_COL : this.BODY_COL;
      const lo = Math.min(...(this.data.data[j.key] || [0]));
      const hi = Math.max(...(this.data.data[j.key] || [1]));
      const span = hi - lo || 1;
      const t  = Math.max(0, Math.min(1, (v - lo) / span));
      const py = ry + this.ROW_H - this.PAD - t * (this.ROW_H - 2*this.PAD);
      ctx.fillStyle = '#e63946';
      ctx.beginPath(); ctx.arc(x, py, 3, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = color; ctx.font = '9px monospace';
      ctx.fillText((v >= 0 ? '+' : '') + v.toFixed(2), cssW - 2, ry + this.ROW_H/2 + 3);
    });
  },

  seek(canvasX) {
    if (!this.data || this.data.total_frames === 0) return;
    const canvas = document.getElementById('wf-canvas');
    const rect   = canvas.getBoundingClientRect();
    const waveW  = Math.max(rect.width - this.LOCK_W - this.LABEL_W - this.VAL_W, 1);
    const frac   = Math.max(0, Math.min(1, (canvasX - this.LOCK_W - this.LABEL_W) / waveW));
    const frame  = Math.round(frac * (this.data.total_frames - 1));
    fetch(API + '/api/playback/seek', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({frame})
    }).then(() => { this.cursor = frame; this.draw(); updateScrubSlider(frame); });
  },

  _drawAtPoint(x, y) {
    if (!this.data || this.data.total_frames === 0) return;
    const canvas  = document.getElementById('wf-canvas');
    const vis     = this.visible();
    const rowIdx  = Math.floor((y - this.RULER_H) / this.ROW_H);
    if (rowIdx < 0 || rowIdx >= vis.length) return;
    const j = vis[rowIdx];
    if (_locked[j.key]) return;
    if (this._drawChannel && this._drawChannel !== j.key) return;
    this._drawChannel = j.key;
    if (!this._drawSnapshot) {
      const v0 = this.data.data[j.key];
      if (v0) this._drawSnapshot = {key: j.key, vals: [...v0]};
    }
    const waveX  = this.LOCK_W + this.LABEL_W;
    const waveW  = Math.max(canvas.offsetWidth - waveX - this.VAL_W, 1);
    const frac   = Math.max(0, Math.min(1, (x - waveX) / waveW));
    const frameIdx = Math.round(frac * (this.data.total_frames - 1));
    const rowTop = this.RULER_H + rowIdx * this.ROW_H;
    const yFrac  = 1 - Math.max(0, Math.min(1, (y - rowTop - this.PAD) / (this.ROW_H - 2 * this.PAD)));
    const value  = j.lo + yFrac * (j.hi - j.lo);
    const last = this._drawEdits[this._drawEdits.length - 1];
    if (last && last.frame === frameIdx) { last.value = value; }
    else { this._drawEdits.push({frame: frameIdx, value}); }
    const vals = this.data.data[j.key];
    if (vals) {
      const n    = this.data.timestamps.length;
      const step = Math.max(1, this.data.total_frames / n);
      if (this._drawEdits.length >= 2) {
        const prev = this._drawEdits[this._drawEdits.length - 2];
        const curr = this._drawEdits[this._drawEdits.length - 1];
        const pIdx = Math.min(Math.round(prev.frame / step), n - 1);
        const cIdx = Math.min(Math.round(curr.frame / step), n - 1);
        const lo = Math.min(pIdx, cIdx), hi = Math.max(pIdx, cIdx);
        for (let si = lo; si <= hi; si++) {
          const t = lo === hi ? 0 : (si - lo) / (hi - lo);
          vals[si] = (pIdx <= cIdx ? prev.value : curr.value) * (1 - t) +
                     (pIdx <= cIdx ? curr.value : prev.value) * t;
        }
      } else {
        vals[Math.min(Math.round(frameIdx / step), n - 1)] = value;
      }
    }
    this.draw();
  },
};

function wfSetFilter(mode) {
  WF.filter = mode;
  document.getElementById('wf-custom-checks').style.display = mode === 'select' ? 'flex' : 'none';
  if (mode === 'all')   WF.checked = Object.fromEntries(JOINTS.map(j => [j.key, true]));
  if (mode === 'servo') WF.checked = Object.fromEntries(JOINTS.map(j => [j.key, (WF.data?.servo_mapped||[]).includes(j.key)]));
  WF.buildCustomChecks();
  WF.draw();
}

// ── Editor: lock, draw-edit, re-record ───────────────────────────────────────
const _locked = {};

function toggleEditMode() {
  WF.editMode = !WF.editMode;
  const canvas = document.getElementById('wf-canvas');
  canvas.style.cursor = WF.editMode ? 'crosshair' : '';
  const btn = document.getElementById('btn-edit-mode');
  if (btn) {
    btn.textContent  = WF.editMode ? '✎ Editing' : '✎ Draw';
    btn.className    = 'pc-btn btn-sm ' + (WF.editMode ? 'btn-pause' : 'btn-ghost');
  }
  WF.draw();
}

function patchChannel(channel, edits) {
  if (!channel || !edits || !edits.length) return Promise.resolve();
  return fetch(API + '/api/session/frames/patch', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({channel, edits})
  }).then(r => r.json()).then(d => {
    if (!d.ok) console.warn('Patch failed:', d.error);
    WF.load();
  });
}

function rerecordStart() {
  const locked = Object.entries(_locked).filter(([, v]) => v).map(([k]) => k);
  fetch(API + '/api/record/rerecord', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({locked})
  }).then(r => r.json()).then(d => {
    if (d.ok) { _syncAudio('play', 0); pollStatus(); }
  });
}

const _undoStack = [];
function updateUndoBtn() {
  const btn = document.getElementById('btn-undo');
  if (btn) btn.disabled = !_undoStack.length;
}
function undoLastEdit() {
  if (!_undoStack.length) return;
  const entry = _undoStack.pop();
  updateUndoBtn();
  patchChannel(entry.channel, entry.edits);
}

function toggleChannelLock(key) {
  _locked[key] = !_locked[key];
  const n = Object.values(_locked).filter(Boolean).length;
  const el = document.getElementById('lock-status');
  if (el) el.textContent = n > 0 ? `${n} locked` : '';
  WF.draw();
}

// ── Per-channel curve editor (modal) ───────────────────────────────────────────
const CE = {
  key: null, full: null, tool: 'draw',
  points: [],                       // staged {frame, value} keyframes for the Add Point tool
  RULER_H: 22, VAL_W: 60, PAD: 8,
  _drawing: false, _drawEdits: [], _drawSnapshot: null,
};

function openChannelEditor(key) {
  CE.key = key; CE.tool = 'draw'; CE.points = [];
  CE._drawing = false; CE._drawEdits = []; CE._drawSnapshot = null;
  const j = JOINTS.find(j => j.key === key);
  CE.color = j ? (j.group === 'face' ? WF.FACE_COL : WF.BODY_COL) : '#4cc9f0';
  document.getElementById('ce-title').textContent = j ? j.label : key;
  document.getElementById('ce-overlay').style.display = 'flex';
  document.getElementById('ce-locked-note').style.display = _locked[key] ? '' : 'none';
  const disable = !!_locked[key];
  ['ce-tool-draw','ce-tool-point','ce-tool-scissors','ce-btn-smooth'].forEach(id => {
    const b = document.getElementById(id); if (b) b.disabled = disable;
  });
  document.getElementById('ce-smooth-window').disabled = disable;
  ceSetTool('draw');
  ceUpdatePointButtons();
  const msg = document.getElementById('ce-msg');
  if (msg) msg.textContent = '';
  ceLoadChannel(key);
}

function closeChannelEditor() {
  if (CE.points.length && !confirm('Discard unapplied points?')) return;
  document.getElementById('ce-overlay').style.display = 'none';
}

function ceLoadChannel(key) {
  fetch(API + '/api/session/frames/channel/' + encodeURIComponent(key))
    .then(r => r.json())
    .then(d => { CE.full = d; ceDraw(); })
    .catch(() => {});
}

function ceSetTool(tool) {
  if (_locked[CE.key] && tool !== 'draw') return;
  CE.tool = tool;
  ['draw','point','scissors'].forEach(t =>
    document.getElementById('ce-tool-' + t).classList.toggle('active', t === tool));
  ceDraw();
}

function ceUpdatePointButtons() {
  document.getElementById('ce-btn-apply').disabled  = CE.points.length < 2;
  document.getElementById('ce-btn-cancel').disabled = CE.points.length === 0;
}

function ceApplyPoints() {
  if (CE.points.length < 2 || !CE.full) return;
  const snapshot = [...CE.full.values];
  const fs  = CE.points.map(p => p.frame);
  const lo  = Math.max(0, Math.min(...fs));
  const hi  = Math.min(CE.full.total_frames - 1, Math.max(...fs));
  const undoEdits = [];
  for (let f = lo; f <= hi; f++) undoEdits.push({frame: f, value: snapshot[f]});
  if (undoEdits.length) { _undoStack.push({channel: CE.key, edits: undoEdits}); updateUndoBtn(); }
  patchChannel(CE.key, [...CE.points]).then(() => ceLoadChannel(CE.key));
  CE.points = [];
  ceUpdatePointButtons();
}

function ceCancelPoints() {
  CE.points = [];
  ceUpdatePointButtons();
  ceDraw();
}

function ceSmooth() {
  if (!CE.full || _locked[CE.key]) return;
  const win = Math.max(2, parseInt(document.getElementById('ce-smooth-window').value, 10) || 5);
  const vals = CE.full.values;
  const n    = vals.length;
  if (n < 3) return;
  const smoothed = new Array(n);
  const half = Math.floor(win / 2);
  for (let i = 0; i < n; i++) {
    let sum = 0, count = 0;
    for (let k = Math.max(0, i - half); k <= Math.min(n - 1, i + half); k++) { sum += vals[k]; count++; }
    smoothed[i] = sum / count;
  }
  const undoEdits = vals.map((v, i) => ({frame: i, value: v}));
  _undoStack.push({channel: CE.key, edits: undoEdits}); updateUndoBtn();
  const edits = smoothed.map((v, i) => ({frame: i, value: v}));
  const msg = document.getElementById('ce-msg');
  if (msg) { msg.style.color = '#888'; msg.textContent = 'Smoothing…'; }
  patchChannel(CE.key, edits).then(() => {
    ceLoadChannel(CE.key);
    if (msg) { msg.style.color = '#06d6a0'; msg.textContent = '✓ Smoothed'; setTimeout(() => msg.textContent = '', 3000); }
  });
}

function ceCanvasSize() {
  const canvas = document.getElementById('ce-canvas');
  return {canvas, cssW: canvas.clientWidth || 800, cssH: canvas.clientHeight || 400};
}

function ceFrameValueAt(x, y, cssW, cssH) {
  const waveW = Math.max(cssW - CE.VAL_W, 1);
  const frac  = Math.max(0, Math.min(1, x / waveW));
  const frame = Math.round(frac * (CE.full.total_frames - 1));
  const top   = CE.RULER_H, areaH = cssH - top;
  let lo = Math.min(...CE.full.values), hi = Math.max(...CE.full.values);
  if (hi - lo < 1e-4) { lo -= 0.5; hi += 0.5; }
  const yFrac = 1 - Math.max(0, Math.min(1, (y - top - CE.PAD) / (areaH - 2 * CE.PAD)));
  const value = lo + yFrac * (hi - lo);
  return {frame, value};
}

function ceDrawAtPoint(x, y, cssW, cssH) {
  if (_locked[CE.key] || !CE.full) return;
  if (!CE._drawSnapshot) CE._drawSnapshot = [...CE.full.values];
  const {frame, value} = ceFrameValueAt(x, y, cssW, cssH);
  const last = CE._drawEdits[CE._drawEdits.length - 1];
  if (last && last.frame === frame) { last.value = value; }
  else { CE._drawEdits.push({frame, value}); }
  if (CE._drawEdits.length >= 2) {
    const prev = CE._drawEdits[CE._drawEdits.length - 2];
    const curr = CE._drawEdits[CE._drawEdits.length - 1];
    const lo = Math.min(prev.frame, curr.frame), hi = Math.max(prev.frame, curr.frame);
    for (let f = lo; f <= hi; f++) {
      const t = hi === lo ? 0 : (f - lo) / (hi - lo);
      CE.full.values[f] = (prev.frame <= curr.frame ? prev.value : curr.value) * (1 - t) +
                           (prev.frame <= curr.frame ? curr.value : prev.value) * t;
    }
  } else {
    CE.full.values[frame] = value;
  }
  ceDraw();
}

function ceCommitDraw() {
  if (CE._drawing && CE._drawEdits.length > 0 && CE._drawSnapshot) {
    const fs = CE._drawEdits.map(e => e.frame);
    const lo = Math.max(0, Math.min(...fs));
    const hi = Math.min(CE.full.total_frames - 1, Math.max(...fs));
    const undoEdits = [];
    for (let f = lo; f <= hi; f++) undoEdits.push({frame: f, value: CE._drawSnapshot[f]});
    if (undoEdits.length) { _undoStack.push({channel: CE.key, edits: undoEdits}); updateUndoBtn(); }
    patchChannel(CE.key, [...CE._drawEdits]).then(() => ceLoadChannel(CE.key));
  }
  CE._drawing = false; CE._drawEdits = []; CE._drawSnapshot = null;
}

function ceDraw() {
  const {canvas, cssW, cssH} = ceCanvasSize();
  const dpr = window.devicePixelRatio || 1;
  canvas.width  = cssW * dpr;
  canvas.height = cssH * dpr;
  const ctx = canvas.getContext('2d');
  ctx.scale(dpr, dpr);
  ctx.fillStyle = '#0d0d1f'; ctx.fillRect(0, 0, cssW, cssH);

  if (!CE.full || !CE.full.ok || !CE.full.total_frames) {
    ctx.fillStyle = '#555'; ctx.font = '13px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText('No data', cssW / 2, cssH / 2);
    return;
  }

  const waveW = Math.max(cssW - CE.VAL_W, 1);
  const dur   = CE.full.duration || 1;
  const vals  = CE.full.values;
  const ts    = CE.full.timestamps;

  ctx.fillStyle = '#080816'; ctx.fillRect(0, 0, cssW, CE.RULER_H);
  const tick = [0.1,0.5,1,2,5,10,30,60].find(m => dur/m <= 14) || 60;
  ctx.strokeStyle = '#333'; ctx.lineWidth = 1;
  ctx.fillStyle = '#666'; ctx.font = '10px monospace'; ctx.textAlign = 'center';
  for (let t = 0; t <= dur + tick*0.01; t += tick) {
    const rx = (t / dur) * waveW;
    ctx.beginPath(); ctx.moveTo(rx, CE.RULER_H-5); ctx.lineTo(rx, CE.RULER_H); ctx.stroke();
    const mm = Math.floor(t/60), ss = (t%60).toFixed(1).padStart(4,'0');
    ctx.fillText(`${mm}:${ss}`, rx, CE.RULER_H-6);
  }

  const top = CE.RULER_H, areaH = cssH - top;
  let lo = Math.min(...vals), hi = Math.max(...vals);
  if (hi - lo < 1e-4) { lo -= 0.5; hi += 0.5; }
  const span = hi - lo;
  const yFor = v => top + areaH - CE.PAD - ((v-lo)/span) * (areaH - 2*CE.PAD);

  if (lo <= 0 && 0 <= hi) {
    ctx.strokeStyle = '#2a2a50'; ctx.lineWidth = 1; ctx.setLineDash([4,5]);
    ctx.beginPath(); ctx.moveTo(0, yFor(0)); ctx.lineTo(waveW, yFor(0)); ctx.stroke();
    ctx.setLineDash([]);
  }

  ctx.strokeStyle = CE.color || '#4cc9f0'; ctx.lineWidth = 1.5; ctx.beginPath();
  ts.forEach((t, i) => {
    const x = (t / dur) * waveW, y = yFor(vals[i] ?? 0);
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  });
  ctx.stroke();

  if (CE.points.length) {
    ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.2; ctx.setLineDash([5,4]);
    ctx.beginPath();
    CE.points.forEach((p, i) => {
      const x = ((ts[p.frame] ?? 0) / dur) * waveW, y = yFor(p.value);
      i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    });
    ctx.stroke(); ctx.setLineDash([]);
    CE.points.forEach(p => {
      const x = ((ts[p.frame] ?? 0) / dur) * waveW, y = yFor(p.value);
      ctx.fillStyle = CE.tool === 'scissors' ? '#e63946' : '#fff';
      ctx.beginPath(); ctx.arc(x, y, 5, 0, Math.PI*2); ctx.fill();
    });
  }

  const frameIdx = Math.min(Math.max(0, Math.round(WF.cursor || 0)), CE.full.total_frames - 1);
  const px = ((ts[frameIdx] ?? 0) / dur) * waveW;
  ctx.strokeStyle = '#e63946'; ctx.lineWidth = 2;
  ctx.beginPath(); ctx.moveTo(px, 0); ctx.lineTo(px, cssH); ctx.stroke();
  const v = vals[frameIdx] ?? 0;
  ctx.fillStyle = '#e63946'; ctx.font = '11px monospace'; ctx.textAlign = 'left';
  ctx.fillText((v >= 0 ? '+' : '') + v.toFixed(3), waveW + 6, top + 14);
}

(function() {
  const canvas = document.getElementById('ce-canvas');
  canvas.addEventListener('mousedown', e => {
    if (!CE.full || !CE.full.total_frames) return;
    const {cssW, cssH} = ceCanvasSize();
    const x = e.offsetX, y = e.offsetY;
    if (x > cssW - CE.VAL_W) return;
    if (CE.tool === 'draw') {
      if (_locked[CE.key]) return;
      CE._drawing = true; CE._drawEdits = []; ceDrawAtPoint(x, y, cssW, cssH);
      return;
    }
    if (CE.tool === 'point') {
      if (_locked[CE.key]) return;
      const {frame, value} = ceFrameValueAt(x, y, cssW, cssH);
      const existingIdx = CE.points.findIndex(p => p.frame === frame);
      if (existingIdx >= 0) CE.points[existingIdx].value = value;
      else CE.points.push({frame, value});
      CE.points.sort((a,b) => a.frame - b.frame);
      ceUpdatePointButtons();
      ceDraw();
      return;
    }
    if (CE.tool === 'scissors') {
      const waveW = Math.max(cssW - CE.VAL_W, 1);
      const dur = CE.full.duration || 1;
      const top = CE.RULER_H, areaH = cssH - top;
      let lo = Math.min(...CE.full.values), hi = Math.max(...CE.full.values);
      if (hi - lo < 1e-4) { lo -= 0.5; hi += 0.5; }
      const yForPt = v => top + areaH - CE.PAD - ((v-lo)/(hi-lo)) * (areaH - 2*CE.PAD);
      let nearest = -1, nearestDist = 12; // px radius
      CE.points.forEach((p, i) => {
        const px = ((CE.full.timestamps[p.frame] ?? 0) / dur) * waveW;
        const py = yForPt(p.value);
        const d = Math.hypot(px - x, py - y);
        if (d < nearestDist) { nearest = i; nearestDist = d; }
      });
      if (nearest >= 0) { CE.points.splice(nearest, 1); ceUpdatePointButtons(); ceDraw(); }
      return;
    }
  });
  canvas.addEventListener('mousemove', e => {
    if (CE._drawing) {
      const {cssW, cssH} = ceCanvasSize();
      ceDrawAtPoint(e.offsetX, e.offsetY, cssW, cssH);
    }
  });
  canvas.addEventListener('mouseup',    ceCommitDraw);
  canvas.addEventListener('mouseleave', ceCommitDraw);
  window.addEventListener('resize', () => ceDraw());
})();

(function() {
  const canvas = document.getElementById('wf-canvas');
  canvas.addEventListener('mousedown', e => {
    const x = e.offsetX, y = e.offsetY;
    if (x < WF.LOCK_W) {
      const rowIdx = Math.floor((y - WF.RULER_H) / WF.ROW_H);
      const vis = WF.visible();
      if (rowIdx >= 0 && rowIdx < vis.length) toggleChannelLock(vis[rowIdx].key);
      return;
    }
    if (WF.editMode && x >= WF.LOCK_W + WF.LABEL_W) {
      WF._drawing = true; WF._drawEdits = []; WF._drawAtPoint(x, y); return;
    }
    if (x < WF.LOCK_W + WF.LABEL_W) {
      const rowIdx = Math.floor((y - WF.RULER_H) / WF.ROW_H);
      const vis = WF.visible();
      if (rowIdx >= 0 && rowIdx < vis.length) openChannelEditor(vis[rowIdx].key);
      return;
    }
    WF.dragging = true; WF.seek(x);
  });
  canvas.addEventListener('mousemove', e => {
    if (WF._drawing) { WF._drawAtPoint(e.offsetX, e.offsetY); return; }
    if (WF.dragging) WF.seek(e.offsetX);
  });
  function commitDraw() {
    if (WF._drawing && WF._drawEdits.length > 0 && WF._drawChannel) {
      const ch   = WF._drawChannel;
      const snap = WF._drawSnapshot;
      if (snap && snap.key === ch && WF.data) {
        const n    = snap.vals.length;
        const step = Math.max(1, WF.data.total_frames / n);
        const fs   = WF._drawEdits.map(e => e.frame);
        const lo   = Math.max(0, Math.round(Math.min(...fs) / step));
        const hi   = Math.min(n - 1, Math.round(Math.max(...fs) / step));
        const undoEdits = [];
        for (let si = lo; si <= hi; si++)
          undoEdits.push({frame: Math.round(si * step), value: snap.vals[si]});
        if (undoEdits.length) { _undoStack.push({channel: ch, edits: undoEdits}); updateUndoBtn(); }
      }
      patchChannel(ch, [...WF._drawEdits]);
    }
    WF._drawing = false; WF._drawEdits = []; WF._drawChannel = null; WF._drawSnapshot = null; WF.dragging = false;
    WF.draw();
  }
  canvas.addEventListener('mouseup',    commitDraw);
  canvas.addEventListener('mouseleave', commitDraw);
  window.addEventListener('resize', () => WF.draw());
})();

// ── Scrub ─────────────────────────────────────────────────────────────────────
let _scrubBusy = false;

function onScrub(val) {
  if (_scrubBusy) return;
  const frame = parseInt(val, 10);
  fetch(API + '/api/playback/seek', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({frame})
  }).then(() => { WF.cursor = frame; WF.draw(); ceDraw(); });
}

function updateScrubSlider(frame) {
  _scrubBusy = true;
  const sl = document.getElementById('scrub-slider');
  if (sl) sl.value = frame;
  const ceSl = document.getElementById('ce-scrub-slider');
  if (ceSl) ceSl.value = frame;
  _scrubBusy = false;
  if (WF.data && WF.data.timestamps) {
    const n    = WF.data.timestamps.length;
    const step = Math.max(1, WF.data.total_frames / n);
    const sIdx = Math.min(Math.round(frame / step), n - 1);
    const ts   = WF.data.timestamps[sIdx] ?? 0;
    const pos  = document.getElementById('scrub-pos');
    if (pos) {
      const mm = Math.floor(ts/60), ss = (ts%60).toFixed(1).padStart(4,'0');
      pos.textContent = `${mm}:${ss}`;
    }
  }
}

// ── FPS ───────────────────────────────────────────────────────────────────────
document.querySelectorAll('input[name="fps"]').forEach(r => r.addEventListener('change', onFpsChange));
function onFpsChange() {
  const val = document.querySelector('input[name="fps"]:checked').value;
  document.getElementById('fps-custom-val').style.display = val === 'custom' ? '' : 'none';
  updateFpsLabel(val === 'custom' ? (parseInt(document.getElementById('fps-custom-val').value)||50) : parseInt(val));
}
function onCustomFpsInput() {
  updateFpsLabel(parseInt(document.getElementById('fps-custom-val').value)||50);
}
function updateFpsLabel(ms) {
  document.getElementById('fps-label').textContent = `${ms} ms/frame · ${(1000/ms).toFixed(ms<100?0:1)} fps`;
}
function getStepTimeMs() {
  const v = document.querySelector('input[name="fps"]:checked').value;
  return v === 'custom' ? (parseInt(document.getElementById('fps-custom-val').value)||50) : parseInt(v);
}
onFpsChange();

// ── Joint mapping ─────────────────────────────────────────────────────────────
function buildJointTable(ports, jointMap) {
  JM_ports = ports; JM_built = true;
  const tbody  = document.getElementById('jm-tbody');
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
      const collapsed = localStorage.getItem('pc-jm-group-' + j.group) === 'closed';
      rows.push(`<tr class="jm-grp-hdr" data-group-hdr="${j.group}" onclick="toggleJmGroup('${j.group}')">
        <td colspan="6" style="padding:8px 8px 2px; color:${color};
        font-size:10px; font-weight:bold; letter-spacing:1px;">
        <span class="jm-grp-arrow">${collapsed ? '▸' : '▾'}</span>${label}</td></tr>`);
    }
    const m   = jointMap[j.key] || {};
    const sel = m.port !== undefined ? m.port : -1;
    const sc  = m.scale !== undefined ? Math.round(m.scale * 100) : 100;
    const mdl = m.xlights_model || '';
    const opts = portOpts.replace(`value="${sel}"`, `value="${sel}" selected`);
    const collapsedRow = localStorage.getItem('pc-jm-group-' + j.group) === 'closed';
    rows.push(`<tr data-group="${j.group}" class="${collapsedRow ? 'jm-row-collapsed' : ''}">
      <td style="color:${j.group==='face'?'#4cc9f0':'#fb8500'}; font-size:11px;">${j.label}</td>
      <td><div style="display:flex; align-items:center; gap:6px;">
        <div class="jm-bar-bg"><div class="jm-bar" id="jm-bar-${j.key}"></div></div>
        <span class="jm-val" id="jm-val-${j.key}">—</span>
      </div></td>
      <td><select class="jm-sel" id="jm-port-${j.key}">${opts}</select></td>
      <td class="jm-col-adv" style="text-align:center;"><input type="checkbox" id="jm-inv-${j.key}" ${m.invert?'checked':''}></td>
      <td class="jm-col-adv"><input type="number" class="jm-scale-in" id="jm-scale-${j.key}" value="${sc}" min="0" max="200" step="5"></td>
      <td class="jm-col-adv"><input type="text" class="jm-model-in" id="jm-model-${j.key}" value="${mdl}" placeholder="e.g. Mickey.Arm"></td>
    </tr>`);
  }
  tbody.innerHTML = rows.join('');
}

function toggleJmGroup(group) {
  const closed = localStorage.getItem('pc-jm-group-' + group) === 'closed';
  localStorage.setItem('pc-jm-group-' + group, closed ? 'open' : 'closed');
  document.querySelectorAll(`tr[data-group="${group}"]`).forEach(tr => tr.classList.toggle('jm-row-collapsed', !closed));
  const arrow = document.querySelector(`tr[data-group-hdr="${group}"] .jm-grp-arrow`);
  if (arrow) arrow.textContent = closed ? '▾' : '▸';
}

function toggleJmAdvanced() {
  const table = document.getElementById('jm-table');
  const btn   = document.getElementById('btn-jm-advanced');
  const showing = table.classList.contains('jm-simple');
  table.classList.toggle('jm-simple', !showing);
  if (btn) btn.textContent = showing ? '⚙ Hide advanced' : '⚙ Show advanced';
  localStorage.setItem('pc-jm-advanced', showing ? '1' : '0');
}

(function initJmAdvanced() {
  const on    = localStorage.getItem('pc-jm-advanced') === '1';
  const table = document.getElementById('jm-table');
  const btn   = document.getElementById('btn-jm-advanced');
  if (table) table.classList.toggle('jm-simple', !on);
  if (btn) btn.textContent = on ? '⚙ Hide advanced' : '⚙ Show advanced';
})();

function updateJointBars(values) {
  JOINTS.forEach(j => {
    const v = values[j.key];
    if (v === undefined) return;
    const valEl = document.getElementById('jm-val-' + j.key);
    if (valEl) valEl.textContent = (v >= 0 ? '+' : '') + v.toFixed(2);
    const bar = document.getElementById('jm-bar-' + j.key);
    if (bar) bar.style.width = Math.max(0,Math.min(100,(v-j.lo)/(j.hi-j.lo)*100)).toFixed(1) + '%';
    const tv = document.getElementById('tv-' + j.key);
    if (tv) tv.textContent = (v >= 0 ? '+' : '') + v.toFixed(2);
  });
}

function saveJointMap() {
  const map = {};
  JOINTS.forEach(j => {
    const port = parseInt((document.getElementById('jm-port-'+j.key)||{}).value, 10);
    if (isNaN(port) || port < 0) return;
    const mdl = ((document.getElementById('jm-model-'+j.key)||{}).value||'').trim();
    map[j.key] = {
      port,
      invert:        !!(document.getElementById('jm-inv-'+j.key)||{}).checked,
      scale:         parseFloat((document.getElementById('jm-scale-'+j.key)||{}).value||100) / 100,
      xlights_model: mdl,
    };
  });
  fetch(API + '/api/config', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({joint_map: map})
  }).then(r => r.json()).then(d => {
    const el = document.getElementById('jm-msg');
    el.style.color = d.ok ? '#06d6a0' : '#e63946';
    el.textContent = d.ok ? `✓ Saved ${Object.keys(map).length} mapping(s)` : '✗ ' + JSON.stringify(d);
    setTimeout(() => el.textContent = '', 4000);
    if (d.ok && WF.data) WF.data.servo_mapped = Object.keys(map);
  });
}

// ── Live output ───────────────────────────────────────────────────────────────
function toggleLive() {
  const btn  = document.getElementById('btn-live');
  const isOn = btn.classList.contains('btn-live-on');
  fetch(API + '/api/config', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({live_output: !isOn})
  }).then(r => r.json()).then(d => { if (d.ok) setLiveBtn(!isOn); });
}
function setLiveBtn(on) {
  const btn = document.getElementById('btn-live');
  if (!btn) return;
  btn.textContent = on ? '⏹ Stop Live Test' : '▶ Start Live Test';
  btn.className   = 'pc-btn btn-live-main ' + (on ? 'btn-live-on' : 'btn-live-off');
}

// ── Smoothing ─────────────────────────────────────────────────────────────────
function saveSmoothing() {
  fetch(API + '/api/config', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      smoothing:       parseFloat(document.getElementById('sl-smoothing').value),
      servo_smoothing: parseFloat(document.getElementById('sl-servo').value),
    })
  }).then(r => r.json()).then(d => {
    const el = document.getElementById('settings-msg');
    el.style.color = d.ok ? '#06d6a0' : '#e63946';
    el.textContent = d.ok ? '✓ Saved' : '✗ Error';
    setTimeout(() => el.textContent = '', 3000);
  });
}

// ── Timeline transport ────────────────────────────────────────────────────────
const TL = { speed: 1.0, loop: false };

function tlPbStart(frame) {
  fetch(API+'/api/playback/start', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({frame, speed: TL.speed, loop: TL.loop})
  }).then(pollStatus);
}
function tlPlay() {
  const offset = (WF.data && WF.data.timestamps) ? (WF.data.timestamps[WF.cursor] ?? 0) : 0;
  _syncAudio('play', offset);
  tlPbStart(WF.cursor || 0);
}
function tlPause() {
  _syncAudio('pause');
  fetch(API+'/api/playback/pause', {method:'POST'}).then(pollStatus);
}
function tlStop() {
  _syncAudio('stop');
  fetch(API+'/api/playback/stop', {method:'POST'}).then(pollStatus);
}
function tlRestart() {
  _syncAudio('play', 0);
  tlPbStart(0);
}

function tlToggleSpeed() {
  TL.speed = TL.speed === 1.0 ? 0.5 : 1.0;
  _updateTlButtons();
}
function tlToggleLoop() {
  TL.loop = !TL.loop;
  _updateTlButtons();
}
function _updateTlButtons() {
  const hBtn = document.getElementById('btn-tl-half');
  const lBtn = document.getElementById('btn-tl-loop');
  if (hBtn) { hBtn.className = 'pc-btn btn-sm ' + (TL.speed !== 1.0 ? 'btn-pause' : 'btn-ghost'); hBtn.textContent = TL.speed !== 1.0 ? '½× ON' : '½×'; }
  if (lBtn) { lBtn.className = 'pc-btn btn-sm ' + (TL.loop ? 'btn-play' : 'btn-ghost'); lBtn.textContent = TL.loop ? '↻ Loop ON' : '↻ Loop'; }
}

// ── Recording ─────────────────────────────────────────────────────────────────
function recStart() {
  hidePostRecordBar();
  fetch(API+'/api/record/start', {method:'POST'}).then(pollStatus);
  _syncAudio('play', 0);
}
function recStop() {
  _syncAudio('stop');
  fetch(API+'/api/record/stop', {method:'POST'}).then(() => {
    pollStatus();
    WF.load();
    switchTab('review');
    const base = defaultSessionBaseName();
    syncExportNames(base);
    showPostRecordBar(base);
  });
}

function defaultSessionBaseName() {
  const d = new Date(), pad = n => String(n).padStart(2,'0');
  return `capture-${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
}

// Keeps the export filename fields defaulted to the current session's name,
// unless the user has hand-edited one (tracked via data-dirty).
function syncExportNames(base) {
  ['fseq-name', 'xsq-name'].forEach(id => {
    const el = document.getElementById(id);
    if (el && el.dataset.dirty !== '1') el.value = base;
  });
}

// ── Post-record contextual save bar ───────────────────────────────────────────
function showPostRecordBar(base) {
  const bar = document.getElementById('post-rec-save');
  if (!bar) return;
  const info = document.getElementById('post-rec-info');
  if (info && _lastStatus) info.textContent = `${_lastStatus.duration_str||''} · ${_lastStatus.frame_count||0} frames`;
  const nameEl = document.getElementById('sess-save-name-inline');
  if (nameEl) nameEl.value = base + '.json';
  bar.style.display = '';
}
function hidePostRecordBar() {
  const bar = document.getElementById('post-rec-save');
  if (bar) bar.style.display = 'none';
}
function sessSaveAndReview() {
  sessSave(() => { hidePostRecordBar(); switchTab('review'); });
}
function skipPostRecordSave() {
  hidePostRecordBar();
  switchTab('review');
}

// ── Session files ─────────────────────────────────────────────────────────────
function sessSave(onDone) {
  const name = (document.getElementById('sess-save-name-inline').value.trim()) || 'session.json';
  fetch(API+'/api/session/save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: name})
  }).then(r=>r.json()).then(d => {
    showMsg(d.ok ? `✓ Saved ${d.frames} frames → ${d.path}` : '✗ '+d.error, d.ok);
    if (d.ok) {
      refreshSessions();
      syncExportNames(name.replace(/\.json$/i, ''));
    }
    if (onDone) onDone();
  });
}

function sessLoad() {
  const sel = document.getElementById('sess-load-sel');
  if (!sel.value) return;
  showMsg('Loading…', true);
  fetch(API+'/api/session/load', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: sel.value})
  }).then(r=>r.json()).then(d => {
    if (d.ok) {
      showMsg(`✓ Loaded ${d.frames} frames (${d.duration}s)`, true);
      updateSessionInfo(sel.value, d.frames, d.duration);
      WF.load();
      syncExportNames(sel.value.replace(/\.json$/i, ''));
      // Re-sync audio dropdown to whatever the loaded session stored
      pollStatus._audioSynced = false;
      // Don't auto-switch tab — user may still need to set audio before reviewing
    } else {
      showMsg('✗ '+d.error, false);
    }
  });
}

function updateSessionInfo(name, frames, dur) {
  const status = document.getElementById('sess-status');
  if (status) {
    status.innerHTML = `<span style="color:var(--cyan);">${name}</span>
      &nbsp;·&nbsp; <span id="sess-frames" style="color:var(--muted);">${frames} frames</span>
      &nbsp;·&nbsp; <span id="sess-dur" style="color:var(--muted);">${dur}s</span>`;
  }
  const sl = document.getElementById('scrub-slider');
  if (sl) { sl.max = Math.max(1, frames-1); sl.value = 0; sl.disabled = false; }
  const ceSl = document.getElementById('ce-scrub-slider');
  if (ceSl) { ceSl.max = Math.max(1, frames-1); ceSl.value = 0; }
  const durEl = document.getElementById('scrub-dur');
  if (durEl) durEl.textContent = typeof dur === 'number' ? dur.toFixed(1)+'s' : dur;
}

function refreshSessions() {
  fetch(API+'/api/sessions').then(r=>r.json()).then(list => {
    const sel = document.getElementById('sess-load-sel');
    const cur = sel.value;
    sel.innerHTML = list.map(s => `<option${s===cur?' selected':''}>${s}</option>`).join('');
  });
}

let _audioOutput = 'browser';

function _syncAudio(action, offsetSec = 0) {
  if (_audioOutput !== 'browser') return;
  const file = (document.getElementById('audio-sel') || {}).value || '';
  if (!file) return;
  const el = document.getElementById('audio-player');
  if (!el) return;
  const src = `${API}/api/audio/stream/${encodeURIComponent(file)}`;
  if (el.dataset.src !== src) { el.src = src; el.dataset.src = src; el.load(); }
  if (action === 'play') {
    el.currentTime = offsetSec;
    el.play().catch(() => {});
  } else if (action === 'pause') {
    el.pause();
  } else if (action === 'stop') {
    el.pause(); el.currentTime = 0;
  }
}

function loadMediaFiles() {
  fetch(API+'/api/media/files').then(r=>r.json()).then(files => {
    const sel = document.getElementById('audio-sel');
    const cur = sel.value;
    sel.innerHTML = '<option value="">— none —</option>' +
      files.map(f => `<option value="${f}"${f===cur?' selected':''}>${f}</option>`).join('');
  });
}

function loadAudioDevices() {
  fetch(API+'/api/audio/devices').then(r=>r.json()).then(devs => {
    const sel = document.getElementById('audio-out-sel');
    const cur = sel.value;
    sel.innerHTML = devs.map(d =>
      `<option value="${d.value}"${d.value===cur?' selected':''}>${d.label}</option>`
    ).join('');
  });
}

function testAudio() {
  const el = document.getElementById('audio-msg');
  el.style.color = '#888'; el.textContent = 'Testing…';
  if (_audioOutput === 'browser') {
    const file = (document.getElementById('audio-sel') || {}).value || '';
    if (!file) {
      el.style.color='#fb8500'; el.textContent='⚠ No audio file selected';
      setTimeout(() => el.textContent='', 4000); return;
    }
    _syncAudio('play', 0);
    el.style.color='#06d6a0'; el.textContent='✓ Playing via browser';
    setTimeout(() => { _syncAudio('stop'); el.textContent=''; }, 4000);
    return;
  }
  fetch(API+'/api/audio/test', {method:'POST'})
    .then(r=>r.json()).then(d => {
      if (!d.player) {
        el.style.color='#e63946';
        el.textContent = '✗ No audio player found on device (ffplay/mpv/cvlc/mpg123)';
      } else if (!d.path_exists) {
        el.style.color='#e63946';
        el.textContent = `✗ File not found: ${d.path}`;
      } else if (!d.audio_file) {
        el.style.color='#fb8500';
        el.textContent = '⚠ No audio file selected';
      } else if (d.launched) {
        el.style.color='#06d6a0';
        el.textContent = `✓ Playing via ${d.player} on ${d.audio_output}`;
      } else {
        el.style.color='#e63946';
        el.textContent = `✗ Launch failed: ${d.error||'unknown'}`;
      }
      setTimeout(() => el.textContent='', 6000);
    });
}

function setAudioFile(filename) {
  fetch(API+'/api/config', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({audio_file: filename})
  }).then(r=>r.json()).then(d => {
    const el = document.getElementById('audio-msg');
    el.style.color = d.ok ? '#06d6a0' : '#e63946';
    el.textContent = d.ok ? (filename ? `✓ ${filename}` : '✓ No audio') : '✗ Error';
    setTimeout(() => el.textContent = '', 3000);
    const ae = document.getElementById('audio-player');
    if (ae) ae.dataset.src = '';
  });
}

function setAudioOutput(value) {
  _audioOutput = value;
  fetch(API+'/api/config', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({audio_output: value})
  }).then(r=>r.json()).then(d => {
    const el = document.getElementById('audio-msg');
    el.style.color = d.ok ? '#06d6a0' : '#e63946';
    el.textContent = d.ok ? '✓ Output saved' : '✗ Error';
    setTimeout(() => el.textContent = '', 3000);
  });
}

function sessDelete() {
  const sel = document.getElementById('sess-load-sel');
  if (!sel.value) return;
  if (!confirm(`Delete "${sel.value}"? This cannot be undone.`)) return;
  fetch(API+'/api/session/delete', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: sel.value})
  }).then(r=>r.json()).then(d => {
    if (d.ok) {
      showMsg(`✓ Deleted ${sel.value}`, true);
      refreshSessions();
    } else {
      showMsg('✗ ' + d.error, false);
    }
  });
}

function showMsg(msg, ok=true) {
  const el = document.getElementById('status-msg');
  el.style.color = ok ? '#06d6a0' : '#e63946';
  el.textContent = msg;
  setTimeout(() => el.textContent='', 5000);
}

// ── Servo test ────────────────────────────────────────────────────────────────
function testServoOutput() {
  ['servo-test-msg', 'jm-test-msg'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.style.color = '#888'; el.textContent = 'Testing…'; }
  });
  fetch(API + '/api/servo/test', {method: 'POST'})
    .then(r => r.json())
    .then(d => {
      const msg   = d.ok ? `✓ Center sent to ${d.ports} port(s)` : '✗ ' + d.error;
      const color = d.ok ? '#06d6a0' : '#e63946';
      ['servo-test-msg', 'jm-test-msg'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.color = color; el.textContent = msg; }
      });
      setTimeout(() => {
        ['servo-test-msg', 'jm-test-msg'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.textContent = '';
        });
      }, 5000);
    }).catch(() => {
      ['servo-test-msg', 'jm-test-msg'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.color = '#e63946'; el.textContent = '✗ Daemon unreachable'; }
      });
    });
}

// ── Export ────────────────────────────────────────────────────────────────────
function exportFseq() {
  const name    = (document.getElementById('fseq-name').value.trim() || 'capture') + '.fseq';
  const step_ms = getStepTimeMs();
  const msg     = document.getElementById('fseq-msg');
  const mapBox  = document.getElementById('fseq-ch-map');
  const mapInner= document.getElementById('fseq-ch-map-inner');
  msg.style.color='#888'; msg.textContent='Exporting…'; mapBox.style.display='none';
  fetch(API+'/api/config', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({step_time_ms:step_ms})});
  fetch(API+'/api/export', {method:'POST', headers:{'Content-Type':'application/json'},
        body:JSON.stringify({filename:name, step_time_ms:step_ms})})
    .then(r=>r.json()).then(d => {
      if (d.ok) {
        msg.style.color='#06d6a0';
        msg.textContent=`✓ ${d.frames} frames · ${d.duration}s · ${d.channels} ch · Ready in FPP scheduler`;
        if (JM_ports.length > 0) {
          mapInner.innerHTML = JM_ports.map(p => {
            const ch  = p.fpp_channel;
            const ch2 = p.data_type === 2
              ? `&ndash;<strong style="color:#4cc9f0;">${ch+1}</strong> (16-bit)` : ' (8-bit)';
            return `<div style="padding:2px 0;">Port ${p.port} <span style="color:#e0e0e0;">${p.desc||''}</span>`
              + ` &rarr; FPP ch <strong style="color:#4cc9f0;">${ch}</strong>${ch2}</div>`;
          }).join('');
          mapBox.style.display = 'block';
        }
        markExported();
      } else { msg.style.color='#e63946'; msg.textContent='✗ '+d.error; }
    });
}

function switchExportTab(name) {
  document.querySelectorAll('.exp-tab').forEach(t =>
    t.classList.toggle('active', t.dataset.exp === name));
  document.querySelectorAll('.exp-panel').forEach(p =>
    p.classList.toggle('active', p.id === 'exp-' + name));
}

function exportXlights() {
  const name    = document.getElementById('xsq-name').value.trim() || 'capture';
  const step_ms = getStepTimeMs();
  const msg     = document.getElementById('xsq-msg');
  const dl      = document.getElementById('xsq-downloads');
  msg.style.color='#888'; msg.textContent='Exporting…'; dl.style.display='none';
  fetch(API+'/api/config', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({step_time_ms:step_ms})});
  fetch(API+'/api/export/xsq', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({filename:name, step_time_ms:step_ms})})
    .then(r=>r.json()).then(d => {
      if (d.ok) {
        msg.style.color='#06d6a0';
        msg.textContent=`✓ ${d.frames} frames · ${d.duration}s · ${d.channels} ch`;
        dl.innerHTML='';
        if (d.xsq_filename) {
          const a = document.createElement('a');
          a.href=API+'/api/sequence/download/'+encodeURIComponent(d.xsq_filename);
          a.className='pc-btn btn-ghost'; a.textContent='↓ '+d.xsq_filename; a.download=d.xsq_filename;
          dl.appendChild(a);
        }
        dl.style.display='flex';
        markExported();
        if (d.xsq_error) { msg.textContent+='  (XSQ: '+d.xsq_error+')'; msg.style.color='#fb8500'; }
      } else { msg.style.color='#e63946'; msg.textContent='✗ '+d.error; }
    });
}

// ── Status poll ───────────────────────────────────────────────────────────────
function pollStatus() {
  return fetch(API+'/api/status').then(r=>r.json()).then(s => {
    const recBadge = document.getElementById('badge-rec');
    recBadge.textContent = s.recording ? '● REC' : 'IDLE';
    recBadge.className   = 'pc-badge '+(s.recording ? 'badge-rec' : 'badge-idle');
    document.getElementById('btn-rec').style.display  = s.recording ? 'none':'';
    document.getElementById('btn-srec').style.display = s.recording ? '':'none';
    document.getElementById('rec-info').innerHTML = s.duration_str+' &nbsp;·&nbsp; '+s.frame_count+' frames';

    updateStepper(s);

    if (s.playing || s.paused) {
      updateScrubSlider(s.pb_pos);
      WF.cursor = s.pb_pos;
      WF.draw();
    }

    if (s.pb_speed !== undefined) {
      TL.speed = s.pb_speed;
      TL.loop  = !!s.pb_loop;
      _updateTlButtons();
      const badge = document.getElementById('tl-status');
      if (badge) badge.textContent = s.playing
        ? (TL.speed !== 1.0 ? `${TL.speed}×` : '') + (TL.loop ? '  ↻' : '') : '';
    }

    if (s.session_name) {
      const frEl  = document.getElementById('sess-frames');
      const durEl = document.getElementById('sess-dur');
      if (frEl)  frEl.textContent  = s.frame_count + ' frames';
      if (durEl) durEl.textContent = s.duration_str;
      const sl = document.getElementById('scrub-slider');
      if (sl && !sl.disabled) sl.max = Math.max(1, s.frame_count-1);
      const ceSl = document.getElementById('ce-scrub-slider');
      if (ceSl) ceSl.max = Math.max(1, s.frame_count-1);
      const scrubDur = document.getElementById('scrub-dur');
      if (scrubDur) scrubDur.textContent = s.duration_str;
    }

    updateJointBars(s.values || {});

    if (!JM_built && s.ports && s.ports.length > 0) {
      buildJointTable(s.ports, s.joint_map || {});
    }

    if (!pollStatus._audioSynced && s.audio_file !== undefined) {
      const asel = document.getElementById('audio-sel');
      if (asel && [...asel.options].some(o => o.value === s.audio_file)) asel.value = s.audio_file;
      const osel = document.getElementById('audio-out-sel');
      if (osel && s.audio_output) {
        if ([...osel.options].some(o => o.value === s.audio_output)) osel.value = s.audio_output;
        _audioOutput = s.audio_output;
      }
      pollStatus._audioSynced = true;
    }

    setLiveBtn(!!s.live_output);

    handleCameraOwnership(s);

    // Servo output health warning in Review tab
    const warn = document.getElementById('servo-warn');
    const warnText = document.getElementById('servo-warn-text');
    if (warn && warnText && s.writer_ok !== undefined) {
      if (!s.writer_ok) {
        warnText.textContent = "⚠ Servo output isn't connected yet. Make sure FPP has a PCA9685 controller set up, then re-save your mapping.";
        warnText.title = 'co-other.json not found or pca_output_idx is wrong';
        warn.style.display = '';
      } else if (s.joint_map_count === 0) {
        warnText.textContent = '⚠ No joints are mapped to servo ports. Go to the Map & Test tab, assign joints to ports, then click Save Mapping.';
        warn.style.display = '';
      } else {
        warn.style.display = 'none';
      }
    }
  }).catch(()=>{});
}

// ── Camera claim ──────────────────────────────────────────────────────────────
// Automatically reclaims the camera from fpp-live-follow (a sibling plugin) up to
// 3 times; only surfaces a manual fallback if all auto-attempts fail. Handing the
// camera back to Live Follow, however, is never automatic — that's a deliberate
// user action via restoreLiveFollow().
let _camAutoAttempts = 0;
let _camAutoInFlight  = false;

function handleCameraOwnership(s) {
  const badge = document.getElementById('cam-status-badge');
  const recoveryBar = document.getElementById('cam-recovery-bar');

  if (s.cam_running) {
    _camAutoAttempts = 0;
    if (badge) badge.style.display = 'none';
    if (recoveryBar) recoveryBar.style.display = 'none';
    return;
  }

  if (recoveryBar && recoveryBar.style.display !== 'none') return; // manual fallback already showing

  if (_camAutoAttempts < 3 && !_camAutoInFlight) {
    if (badge) { badge.textContent = 'Reconnecting camera…'; badge.style.display = ''; }
    claimCamera(true);
  } else if (_camAutoAttempts >= 3) {
    if (badge) badge.style.display = 'none';
    if (recoveryBar) recoveryBar.style.display = 'flex';
  }
}

function claimCamera(isAuto = false) {
  _camAutoInFlight = true;
  if (isAuto) _camAutoAttempts++;
  const msg = document.getElementById('cam-claim-msg');
  if (msg) { msg.style.color='#888'; msg.textContent='Releasing from Live Follow…'; }
  fetch('/fpp-live-follow-api/api/camera/release', {method:'POST'}).catch(()=>null)
    .then(() => { if (msg) msg.textContent='Opening camera…'; return fetch(API+'/api/camera/retry', {method:'POST'}); })
    .then(r=>r.json()).then(d => {
      _camAutoInFlight = false;
      if (d.cam_running) {
        _camAutoAttempts = 0;
        if (msg) { msg.style.color='#06d6a0'; msg.textContent='✓ Camera claimed'; }
        const recoveryBar = document.getElementById('cam-recovery-bar');
        if (recoveryBar) recoveryBar.style.display = 'none';
      } else if (msg) { msg.style.color='#e63946'; msg.textContent='✗ Camera still unavailable'; }
    }).catch(() => {
      _camAutoInFlight = false;
      if (msg) { msg.style.color='#e63946'; msg.textContent='✗ Could not reach daemon'; }
    });
}

function restoreLiveFollow() {
  const msg = document.getElementById('cam-claim-msg');
  msg.style.color='#888'; msg.textContent='Restoring Live Follow camera…';
  fetch('/fpp-live-follow-api/api/camera/restore', {method:'POST'})
    .then(() => { msg.style.color='#06d6a0'; msg.textContent='✓ Restored'; })
    .catch(() => { msg.style.color='#e63946'; msg.textContent='✗ Could not reach Live Follow'; });
}

// ── Init ──────────────────────────────────────────────────────────────────────
// Tab 1 is active by default — start its stream, leave Record's blank
document.getElementById('map-test-stream').src = STREAM_URL;

WF.load();
loadMediaFiles();
loadAudioDevices();
setInterval(pollStatus, 500);
pollStatus();
</script>
