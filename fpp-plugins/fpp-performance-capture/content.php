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

/* ── Process steps bar ─────────────────────────────────────────────────────── */
.pc-steps { display:flex; align-items:center; gap:0; margin-bottom:16px;
            background:var(--panel); border-radius:8px; padding:10px 16px; }
.pc-step  { display:flex; align-items:center; gap:7px; color:#333;
            font-size:10px; font-weight:bold; letter-spacing:1.2px; text-transform:uppercase; }
.pc-step .sn { width:18px; height:18px; border-radius:50%; border:1px solid #333;
               display:flex; align-items:center; justify-content:center; font-size:9px; flex-shrink:0; }
.pc-step .sa { color:#2a2a4a; font-size:14px; margin:0 10px; }
.pc-step.active     { color:var(--cyan); }
.pc-step.active .sn { border-color:var(--cyan); background:var(--cyan); color:#000; }
.pc-step.done       { color:#2a4a3a; }
.pc-step.done .sn   { border-color:#2a4a3a; background:#2a4a3a; color:#06d6a0; }
</style>

<div class="pc-wrap">

<!-- ══ Process bar ══════════════════════════════════════════════════════════ -->
<div class="pc-steps" id="pc-steps">
  <div class="pc-step active" id="step-1"><span class="sn">1</span>Record</div>
  <span class="sa">›</span>
  <div class="pc-step" id="step-2"><span class="sn">2</span>Review</div>
  <span class="sa">›</span>
  <div class="pc-step" id="step-3"><span class="sn">3</span>Map Joints</div>
  <span class="sa">›</span>
  <div class="pc-step" id="step-4"><span class="sn">4</span>Export</div>
</div>

<!-- ══ Camera unavailable ═══════════════════════════════════════════════════ -->
<div class="pc-card" id="cam-ownership-card" style="<?= $cam_running ? 'display:none' : '' ?>">
  <h3>Camera Unavailable</h3>
  <p class="pc-hint" style="margin-bottom:10px;">
    The camera is held by the Live Follow plugin.
    Click <strong>Claim Camera</strong> to release it and start the capture feed.
  </p>
  <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
    <button class="pc-btn btn-play" onclick="claimCamera()">▶ Claim Camera</button>
    <button class="pc-btn btn-ghost" onclick="restoreLiveFollow()">↩ Restore Live Follow</button>
    <span id="cam-claim-msg" class="pc-msg" style="margin:0;"></span>
  </div>
</div>

<!-- ══ Row 1: Camera + Right panel ══════════════════════════════════════════ -->
<div style="display:flex; gap:14px; margin-bottom:14px; align-items:flex-start;">

  <!-- Camera -->
  <div class="pc-card" style="flex:2; min-width:0; padding:0; overflow:hidden;">
    <div class="cam-container">
      <img src="/fpp-capture-api/stream" class="pc-stream"
           onerror="this.style.display='none'" alt="">
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

  <!-- Right panel: live values + live output only -->
  <div class="pc-card" style="width:290px; flex-shrink:0;">

    <div class="rp-section" style="padding-top:0;">
      <div class="rp-hdr">Live Values</div>
      <div class="tv-grid">
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
    </div>

    <div class="rp-section">
      <button id="btn-live" class="pc-btn <?= ($cfg['live_output'] ?? false) ? 'btn-live-on' : 'btn-live-off' ?>"
              style="width:100%;" onclick="toggleLive()">
        <?= ($cfg['live_output'] ?? false) ? '⏹ Live Output ON' : '⏵ Live Output OFF' ?>
      </button>
    </div>

  </div><!-- /right panel -->
</div><!-- /row 1 -->

<!-- ══ Session ═══════════════════════════════════════════════════════════════ -->
<div class="pc-card">

  <!-- Top row: status + save (post-recording actions) -->
  <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap; margin-bottom:12px;">

    <!-- Status -->
    <div style="flex:1; min-width:180px;">
      <div class="rp-hdr">Current Session</div>
      <div id="sess-status" style="font-size:12px; font-family:monospace; color:var(--fg); line-height:1.8;">
        <?php if ($session_name): ?>
          <span style="color:var(--cyan);"><?= htmlspecialchars($session_name) ?></span>
          &nbsp;·&nbsp; <span id="sess-frames" style="color:var(--muted);"><?= $fc ?> frames</span>
          &nbsp;·&nbsp; <span id="sess-dur" style="color:var(--muted);"><?= $dur ?></span>
        <?php else: ?>
          <span style="color:#444;">No session — record above or load below</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Save — labeled optional, auto-filled after recording -->
    <div style="flex-shrink:0;">
      <div class="rp-hdr">Save to File <span style="color:#444; font-size:9px; font-weight:normal; letter-spacing:0;">(optional — export works without saving)</span></div>
      <div style="display:flex; gap:6px; align-items:center;">
        <input class="pc-input" id="sess-save-name" value="" placeholder="record first, then save" style="width:200px; font-size:11px;">
        <button class="pc-btn btn-ghost btn-sm" onclick="sessSave()">Save</button>
      </div>
    </div>

    <span id="status-msg" class="pc-msg" style="margin:0; align-self:flex-end;"></span>
  </div>

  <!-- Bottom row: load saved sessions (separate concern) -->
  <div style="padding-top:10px; border-top:1px solid var(--div); display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <span style="color:var(--muted); font-size:10px; font-weight:bold; letter-spacing:1.5px; text-transform:uppercase; flex-shrink:0;">Load Saved</span>
    <select class="pc-select" id="sess-load-sel" style="width:200px; font-size:11px;">
      <?php foreach ($sessions as $s): ?>
        <option><?= htmlspecialchars($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="pc-btn btn-ghost btn-sm" onclick="sessLoad()">Load</button>
    <button class="pc-btn btn-muted btn-sm" onclick="refreshSessions()" title="Refresh list">↻</button>
  </div>

</div>

<!-- ══ Timeline ═══════════════════════════════════════════════════════════════ -->
<div class="pc-card">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
    <h3 style="margin:0;">Review</h3>
    <div class="wf-filter">
      <label><input type="radio" name="wf-filter" value="all" checked onchange="wfSetFilter('all')"> All</label>
      <label><input type="radio" name="wf-filter" value="servo" onchange="wfSetFilter('servo')"> Servo-mapped</label>
      <label><input type="radio" name="wf-filter" value="select" onchange="wfSetFilter('select')"> Custom</label>
    </div>
  </div>

  <!-- Scrub -->
  <input type="range" class="pc-scrub" id="scrub-slider"
         min="0" max="<?= max(1, $fc - 1) ?>" value="0"
         <?= ($fc > 0) ? '' : 'disabled' ?>
         oninput="onScrub(this.value)">
  <div style="display:flex; justify-content:space-between; font-size:10px;
              color:#444; margin-bottom:8px; font-family:monospace;">
    <span id="scrub-pos">00:00.0</span>
    <span id="scrub-dur"><?= $dur ?></span>
  </div>

  <!-- Transport -->
  <div style="display:flex; gap:5px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
    <button class="pc-btn btn-play  btn-sm" onclick="tlPlay()">▶ Play</button>
    <button class="pc-btn btn-pause btn-sm" onclick="tlPause()">⏸ Pause</button>
    <button class="pc-btn btn-halt  btn-sm" onclick="tlStop()">■ Stop</button>
    <button class="pc-btn btn-ghost btn-sm" onclick="tlRestart()">⏮ Restart</button>
    <button class="pc-btn btn-ghost btn-sm" id="btn-tl-half" onclick="tlToggleSpeed()">½×</button>
    <button class="pc-btn btn-ghost btn-sm" id="btn-tl-loop" onclick="tlToggleLoop()">↻ Loop</button>
    <span id="tl-status" style="color:var(--muted); font-size:11px; font-family:monospace; margin-left:4px;"></span>
  </div>

  <div id="wf-custom-checks" style="display:flex;"></div>
  <canvas id="wf-canvas" class="no-session"></canvas>
  <div id="wf-msg">Load or record a session to see the waveform.</div>
</div>

<!-- ══ Joint Mapping ══════════════════════════════════════════════════════════ -->
<div class="pc-card">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
    <h3 style="margin:0;">Map Joints</h3>
    <button class="pc-btn btn-ghost btn-sm" onclick="saveJointMap()">Save Mapping</button>
  </div>
  <p class="pc-hint" style="margin-bottom:10px;">
    Map each tracked joint to a servo port. Enable <strong>Live Output</strong> to drive servos in real time.
    Min/max/center calibration is read from the Servo Calibrator plugin.
  </p>
  <table class="jm-table">
    <thead>
      <tr>
        <th>Joint</th><th>Live Value</th><th>Port</th>
        <th style="text-align:center;">Invert</th><th>Scale %</th>
        <th>xLights Model</th>
      </tr>
    </thead>
    <tbody id="jm-tbody">
      <tr><td colspan="6" style="color:#555; font-style:italic; padding:12px 8px;">
        Loading servo ports…
      </td></tr>
    </tbody>
  </table>
  <div id="jm-msg" class="pc-msg" style="color:var(--green); margin-top:6px;"></div>

  <!-- Settings inline footer -->
  <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--div);
              display:grid; grid-template-columns:1fr 1fr; gap:12px 32px; max-width:600px;">
    <div>
      <div style="color:var(--muted); font-size:11px; margin-bottom:4px;">
        Joint Smoothing
        <span style="color:#444; font-size:10px; margin-left:4px;">lower = smoother/slower</span>
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
        Servo Smoothing
        <span style="color:#444; font-size:10px; margin-left:4px;">output µs damping</span>
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
</div>

<!-- ══ Export ══════════════════════════════════════════════════════════════════ -->
<div class="pc-card">
  <h3>Export for xLights</h3>
  <p class="pc-hint">
    Exports a paired <code>.xsq</code> + <code>.fseq</code> bundle.
    Copy both files to your xLights sequences folder, then open the <code>.xsq</code>.
  </p>

  <div class="pc-field" style="margin-bottom:14px;">
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

  <div class="pc-field">
    <span class="pc-label">Base name</span>
    <input class="pc-input" id="xsq-name" value="capture" style="width:180px;" placeholder="no extension">
    <button class="pc-btn btn-export" onclick="exportXlights()">Export for xLights</button>
  </div>

  <div id="xsq-msg" class="pc-msg"></div>
  <div id="xsq-downloads" style="display:none; gap:8px; flex-wrap:wrap; margin-top:8px;"></div>

  <div id="export-xlights" style="display:none; margin-top:12px; padding:10px;
       background:var(--dark); border-radius:5px; border:1px solid #1a2a4a;">
    <div style="color:var(--cyan); font-weight:bold; font-size:11px; margin-bottom:6px;">Channel Mapping</div>
    <div id="export-ch-map" style="font-size:11px; font-family:monospace;"></div>
    <div style="color:#555; font-size:11px; margin-top:6px; padding-top:6px; border-top:1px solid #1a2a4a;">
      In xLights: add a <strong style="color:var(--fg);">Servo</strong> model per port set to the channel pair above.
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

// ── Process step highlight ────────────────────────────────────────────────────
function setStep(n) {
  [1,2,3,4].forEach(i => {
    const el = document.getElementById('step-'+i);
    if (!el) return;
    el.className = 'pc-step' + (i === n ? ' active' : i < n ? ' done' : '');
  });
}

// ── Waveform ──────────────────────────────────────────────────────────────────
const WF = {
  data: null, filter: 'all',
  checked: Object.fromEntries(JOINTS.map(j => [j.key, true])),
  cursor: 0, dragging: false,
  LABEL_W:110, VAL_W:50, ROW_H:28, RULER_H:18, PAD:4,
  FACE_COL:'#4cc9f0', BODY_COL:'#fb8500',

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
    const waveX = this.LABEL_W;
    const waveW = Math.max(cssW - this.LABEL_W - this.VAL_W, 1);

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
      if (mapped) { ctx.fillStyle = color; ctx.fillRect(0, ry, 3, this.ROW_H); }

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
    const waveW  = Math.max(rect.width - this.LABEL_W - this.VAL_W, 1);
    const frac   = Math.max(0, Math.min(1, (canvasX - this.LABEL_W) / waveW));
    const frame  = Math.round(frac * (this.data.total_frames - 1));
    fetch(API + '/api/playback/seek', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({frame})
    }).then(() => { this.cursor = frame; this.draw(); updateScrubSlider(frame); });
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

(function() {
  const canvas = document.getElementById('wf-canvas');
  canvas.addEventListener('mousedown', e => {
    if (e.offsetX < WF.LABEL_W) return;
    WF.dragging = true; WF.seek(e.offsetX);
  });
  canvas.addEventListener('mousemove', e => { if (WF.dragging) WF.seek(e.offsetX); });
  canvas.addEventListener('mouseup',    () => { WF.dragging = false; });
  canvas.addEventListener('mouseleave', () => { WF.dragging = false; });
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
  }).then(() => { WF.cursor = frame; WF.draw(); });
}

function updateScrubSlider(frame) {
  _scrubBusy = true;
  const sl = document.getElementById('scrub-slider');
  if (sl) sl.value = frame;
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
      rows.push(`<tr><td colspan="6" style="padding:8px 8px 2px; color:${color};
        font-size:10px; font-weight:bold; letter-spacing:1px;">${j.group === 'face' ? 'Face' : 'Body'}</td></tr>`);
    }
    const m   = jointMap[j.key] || {};
    const sel = m.port !== undefined ? m.port : -1;
    const sc  = m.scale !== undefined ? Math.round(m.scale * 100) : 100;
    const mdl = m.xlights_model || '';
    const opts = portOpts.replace(`value="${sel}"`, `value="${sel}" selected`);
    rows.push(`<tr>
      <td style="color:${j.group==='face'?'#4cc9f0':'#fb8500'}; font-size:11px;">${j.label}</td>
      <td><div style="display:flex; align-items:center; gap:6px;">
        <div class="jm-bar-bg"><div class="jm-bar" id="jm-bar-${j.key}"></div></div>
        <span class="jm-val" id="jm-val-${j.key}">—</span>
      </div></td>
      <td><select class="jm-sel" id="jm-port-${j.key}">${opts}</select></td>
      <td style="text-align:center;"><input type="checkbox" id="jm-inv-${j.key}" ${m.invert?'checked':''}></td>
      <td><input type="number" class="jm-scale-in" id="jm-scale-${j.key}" value="${sc}" min="0" max="200" step="5"></td>
      <td><input type="text" class="jm-model-in" id="jm-model-${j.key}" value="${mdl}" placeholder="e.g. Mickey.Arm"></td>
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
  btn.textContent = on ? '⏹ Live Output ON' : '⏵ Live Output OFF';
  btn.className   = 'pc-btn ' + (on ? 'btn-live-on' : 'btn-live-off');
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
function tlPlay()    { tlPbStart(WF.cursor || 0); }
function tlPause()   { fetch(API+'/api/playback/pause', {method:'POST'}).then(pollStatus); }
function tlStop()    { fetch(API+'/api/playback/stop',  {method:'POST'}).then(pollStatus); }
function tlRestart() { tlPbStart(0); }

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
function recStart() { fetch(API+'/api/record/start', {method:'POST'}).then(pollStatus); }
function recStop()  {
  fetch(API+'/api/record/stop', {method:'POST'}).then(() => {
    pollStatus();
    WF.load();
    setStep(2);
    const d = new Date(), pad = n => String(n).padStart(2,'0');
    const name = `capture-${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}.json`;
    const el = document.getElementById('sess-save-name');
    if (!el.value.trim()) el.value = name;
  });
}

// ── Session files ─────────────────────────────────────────────────────────────
function sessSave() {
  const name = (document.getElementById('sess-save-name').value.trim()) || 'session.json';
  fetch(API+'/api/session/save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({filename: name})
  }).then(r=>r.json()).then(d => {
    showMsg(d.ok ? `✓ Saved ${d.frames} frames → ${d.path}` : '✗ '+d.error, d.ok);
    if (d.ok) refreshSessions();
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
      setStep(2);
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

function showMsg(msg, ok=true) {
  const el = document.getElementById('status-msg');
  el.style.color = ok ? '#06d6a0' : '#e63946';
  el.textContent = msg;
  setTimeout(() => el.textContent='', 5000);
}

// ── Export ────────────────────────────────────────────────────────────────────
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
        [d.xsq_filename, d.fseq_filename].filter(Boolean).forEach(fn => {
          const a = document.createElement('a');
          a.href=API+'/api/sequence/download/'+encodeURIComponent(fn);
          a.className='pc-btn btn-ghost'; a.textContent='↓ '+fn; a.download=fn;
          dl.appendChild(a);
        });
        dl.style.display='flex';
        showXLightsMap(d);
        setStep(4);
        if (d.xsq_error) { msg.textContent+='  (XSQ: '+d.xsq_error+')'; msg.style.color='#fb8500'; }
      } else { msg.style.color='#e63946'; msg.textContent='✗ '+d.error; }
    });
}

function showXLightsMap(d) {
  const xl  = document.getElementById('export-xlights');
  const map = document.getElementById('export-ch-map');
  if (d.start_channel !== undefined && JM_ports.length > 0) {
    map.innerHTML = JM_ports.map(p => {
      const ch1 = d.start_channel + p.port*2, ch2 = ch1+1;
      return `<div style="padding:2px 0;">Port ${p.port} <span style="color:#e0e0e0;">${p.desc||''}</span>
        &rarr; xLights ch <strong style="color:#4cc9f0;">${ch1}</strong>&ndash;<strong style="color:#4cc9f0;">${ch2}</strong></div>`;
    }).join('');
    xl.style.display='block';
  }
}

// ── Status poll ───────────────────────────────────────────────────────────────
function pollStatus() {
  return fetch(API+'/api/status').then(r=>r.json()).then(s => {
    // Recording badge
    const recBadge = document.getElementById('badge-rec');
    recBadge.textContent = s.recording ? '● REC' : 'IDLE';
    recBadge.className   = 'pc-badge '+(s.recording ? 'badge-rec' : 'badge-idle');
    document.getElementById('btn-rec').style.display  = s.recording ? 'none':'';
    document.getElementById('btn-srec').style.display = s.recording ? '':'none';
    document.getElementById('rec-info').innerHTML = s.duration_str+' &nbsp;·&nbsp; '+s.frame_count+' frames';

    if (s.recording) setStep(1);

    // Scrub + waveform cursor
    if (s.playing || s.paused) {
      updateScrubSlider(s.pb_pos);
      WF.cursor = s.pb_pos;
      WF.draw();
    }

    // Timeline buttons
    if (s.pb_speed !== undefined) {
      TL.speed = s.pb_speed;
      TL.loop  = !!s.pb_loop;
      _updateTlButtons();
      const badge = document.getElementById('tl-status');
      if (badge) badge.textContent = s.playing
        ? (TL.speed !== 1.0 ? `${TL.speed}×` : '') + (TL.loop ? '  ↻' : '') : '';
    }

    // Session info
    if (s.session_name) {
      const frEl  = document.getElementById('sess-frames');
      const durEl = document.getElementById('sess-dur');
      if (frEl)  frEl.textContent  = s.frame_count + ' frames';
      if (durEl) durEl.textContent = s.duration_str;
      const sl = document.getElementById('scrub-slider');
      if (sl && !sl.disabled) sl.max = Math.max(1, s.frame_count-1);
      const scrubDur = document.getElementById('scrub-dur');
      if (scrubDur) scrubDur.textContent = s.duration_str;
    }

    // Tracked values + joint bars
    updateJointBars(s.values || {});

    // Build joint table once
    if (!JM_built && s.ports && s.ports.length > 0) {
      buildJointTable(s.ports, s.joint_map || {});
    }

    setLiveBtn(!!s.live_output);

    const ownerCard = document.getElementById('cam-ownership-card');
    if (ownerCard) ownerCard.style.display = s.cam_running ? 'none':'';
  }).catch(()=>{});
}

// ── Camera claim ──────────────────────────────────────────────────────────────
function claimCamera() {
  const msg = document.getElementById('cam-claim-msg');
  msg.style.color='#888'; msg.textContent='Releasing from Live Follow…';
  fetch('/fpp-live-follow-api/api/camera/release', {method:'POST'}).catch(()=>null)
    .then(() => { msg.textContent='Opening camera…'; return fetch(API+'/api/camera/retry', {method:'POST'}); })
    .then(r=>r.json()).then(d => {
      if (d.cam_running) {
        msg.style.color='#06d6a0'; msg.textContent='✓ Camera claimed';
        document.getElementById('cam-ownership-card').style.display='none';
      } else { msg.style.color='#e63946'; msg.textContent='✗ Camera still unavailable'; }
    }).catch(() => { msg.style.color='#e63946'; msg.textContent='✗ Could not reach daemon'; });
}

function restoreLiveFollow() {
  const msg = document.getElementById('cam-claim-msg');
  msg.style.color='#888'; msg.textContent='Restoring Live Follow camera…';
  fetch('/fpp-live-follow-api/api/camera/restore', {method:'POST'})
    .then(() => { msg.style.color='#06d6a0'; msg.textContent='✓ Restored'; })
    .catch(() => { msg.style.color='#e63946'; msg.textContent='✗ Could not reach Live Follow'; });
}

// ── Init ──────────────────────────────────────────────────────────────────────
WF.load();
setInterval(pollStatus, 500);
pollStatus();
</script>
