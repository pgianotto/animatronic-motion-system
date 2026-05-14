<?php
// FPP Live Follow — face/body tracking servo control.
// Requires servo_follow_daemon (systemd: fpp-live-follow.service).
?>

<style>
#lf { font-family: 'Segoe UI', Arial, sans-serif; background: #0d0d0d; color: #e0e0e0; padding: 12px; }
*, *::before, *::after { box-sizing: border-box; }

/* ── Top bar ─────────────────────────────────────────────── */
#lf-bar {
    display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
    padding: 12px 16px; background: #16213e; border-radius: 8px; margin-bottom: 14px;
}
.lf-lbl { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; white-space: nowrap; }
.lf-tbtn {
    padding: 8px 18px; border: 2px solid; border-radius: 5px;
    font-weight: bold; font-size: 13px; letter-spacing: 1px; cursor: pointer;
}
#lf-start        { background: transparent; border-color: #06d6a0; color: #06d6a0; }
#lf-start.on     { background: #e63946; border-color: #e63946; color: #fff; }

.lf-sel {
    background: #0f3460; color: #e0e0e0;
    border: 1px solid #4cc9f0; border-radius: 4px; padding: 6px 10px; font-size: 13px;
}
.lf-num {
    width: 54px; background: #111827; color: #e0e0e0;
    border: 1px solid #374151; border-radius: 4px; padding: 5px 6px;
    font-size: 13px; text-align: center; -moz-appearance: textfield;
}
.lf-num::-webkit-inner-spin-button, .lf-num::-webkit-outer-spin-button { -webkit-appearance: none; }

/* ── Sliders ─────────────────────────────────────────────── */
.lf-slider-wrap { display: flex; align-items: center; gap: 6px; }
.lf-slider { width: 90px; accent-color: #a78bfa; cursor: pointer; }
.lf-slider-val { font-size: 11px; color: #a78bfa; min-width: 30px; }

/* ── Status panel ────────────────────────────────────────── */
#lf-status {
    background: #1a1a2e; border: 1px solid #1f2a4a; border-radius: 8px;
    padding: 16px; display: flex; flex-direction: column; gap: 12px;
}
#lf-state-row { display: flex; align-items: center; gap: 12px; }
#lf-dot {
    width: 12px; height: 12px; border-radius: 50%; background: #333;
    flex-shrink: 0; transition: background .3s;
}
#lf-dot.idle     { background: #555; }
#lf-dot.tracking { background: #06d6a0; box-shadow: 0 0 6px #06d6a040; }
#lf-dot.no_target{ background: #ffd60a; }
#lf-dot.error    { background: #e63946; }
#lf-state-txt { font-size: 14px; font-weight: bold; }
#lf-servo-txt { font-size: 12px; color: #888; margin-left: auto; }

/* ── Detection bar ───────────────────────────────────────── */
#lf-detect-wrap {
    width: 100%; max-width: 500px;
}
.lf-bar-lbl { font-size: 10px; color: #555; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
#lf-detect-track {
    position: relative; height: 24px; background: #111827;
    border: 1px solid #2a2a3e; border-radius: 4px; overflow: hidden;
}
#lf-detect-center {
    position: absolute; left: 50%; top: 0; bottom: 0;
    width: 1px; background: #2a2a3e;
}
#lf-detect-deadzone {
    position: absolute; top: 0; bottom: 0;
    background: #1f2a4a; opacity: .5; transition: left .1s, width .1s;
}
#lf-detect-dot {
    position: absolute; top: 50%; width: 14px; height: 14px;
    background: #06d6a0; border-radius: 50%; transform: translate(-50%, -50%);
    transition: left .1s; display: none;
}
</style>

<div id="lf">
  <div id="lf-bar">
    <span class="lf-lbl">Output</span>
    <select id="lf-output" class="lf-sel"><option value="">Loading…</option></select>

    <span class="lf-lbl">Pan port</span>
    <input type="number" id="lf-pan-port" class="lf-num" value="0" min="0" max="31">

    <span class="lf-lbl">Camera</span>
    <input type="number" id="lf-camera" class="lf-num" value="0" min="0" max="9">

    <span class="lf-lbl">Detect</span>
    <select id="lf-mode" class="lf-sel">
      <option value="face">Face</option>
      <option value="body">Body</option>
      <option value="both">Both (face → body)</option>
    </select>

    <span class="lf-lbl">Gain</span>
    <div class="lf-slider-wrap">
      <input type="range" id="lf-gain" class="lf-slider" min="0.1" max="2.0" step="0.05" value="0.5">
      <span id="lf-gain-val" class="lf-slider-val">0.50</span>
    </div>

    <span class="lf-lbl">Dead zone</span>
    <div class="lf-slider-wrap">
      <input type="range" id="lf-dz" class="lf-slider" min="0.01" max="0.25" step="0.01" value="0.05">
      <span id="lf-dz-val" class="lf-slider-val">5%</span>
    </div>

    <button id="lf-start" class="lf-tbtn" onclick="lfToggle()">▶ Start Follow</button>
  </div>

  <div id="lf-status">
    <div id="lf-state-row">
      <div id="lf-dot" class="idle"></div>
      <span id="lf-state-txt">Idle</span>
      <span id="lf-servo-txt">—</span>
    </div>
    <div id="lf-detect-wrap">
      <div class="lf-bar-lbl">Target position</div>
      <div id="lf-detect-track">
        <div id="lf-detect-center"></div>
        <div id="lf-detect-deadzone"></div>
        <div id="lf-detect-dot"></div>
      </div>
    </div>
  </div>
</div>

<script>
'use strict';

const LF = { running: false, outIdx: 0, pollTimer: null };

async function lfCmd(payload) {
    const r = await fetch('plugin.php?plugin=fpp-live-follow&page=cmd.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    }).catch(() => null);
    if (!r?.ok) return null;
    return r.json().catch(() => null);
}

async function lfLoad() {
    const r = await fetch('/api/channel/output/co-other').catch(() => null);
    if (!r?.ok) return;
    const data = await r.json();
    const list = (data.channelOutputs || []).filter(o => o.ports?.length > 0);
    const sel  = document.getElementById('lf-output');
    sel.innerHTML = '<option value="">Select servo output…</option>';
    list.forEach((o, i) => {
        const end = o.startChannel + o.channelCount - 1;
        sel.innerHTML += `<option value="${i}">${o.type}  Ch ${o.startChannel}–${end}</option>`;
    });
    if (list.length === 1) sel.value = '0';
}

function lfConfig() {
    return {
        pan_output:  parseInt(document.getElementById('lf-output').value    || '0', 10),
        pan_port:    parseInt(document.getElementById('lf-pan-port').value  || '0', 10),
        camera:      parseInt(document.getElementById('lf-camera').value    || '0', 10),
        detect_mode: document.getElementById('lf-mode').value,
        gain:        parseFloat(document.getElementById('lf-gain').value),
        deadzone:    parseFloat(document.getElementById('lf-dz').value),
    };
}

async function lfToggle() {
    if (!LF.running) {
        const resp = await lfCmd({ action: 'start', config: lfConfig() });
        if (resp?.status === 'ok') {
            LF.running = true;
            document.getElementById('lf-start').textContent = '■ Stop Follow';
            document.getElementById('lf-start').classList.add('on');
            LF.pollTimer = setInterval(lfPoll, 200);
        }
    } else {
        await lfCmd({ action: 'stop' });
        LF.running = false;
        document.getElementById('lf-start').textContent = '▶ Start Follow';
        document.getElementById('lf-start').classList.remove('on');
        clearInterval(LF.pollTimer);
        lfSetState('idle', null, null);
    }
}

async function lfPoll() {
    const resp = await lfCmd({ action: 'status' });
    if (!resp) return;
    if (!resp.running && LF.running) {
        // daemon stopped the loop (e.g. camera error)
        LF.running = false;
        document.getElementById('lf-start').textContent = '▶ Start Follow';
        document.getElementById('lf-start').classList.remove('on');
        clearInterval(LF.pollTimer);
    }
    lfSetState(resp.state, resp.servo_us, resp.detection);
}

function lfSetState(state, servoUs, detection) {
    const dot  = document.getElementById('lf-dot');
    const txt  = document.getElementById('lf-state-txt');
    const stxt = document.getElementById('lf-servo-txt');
    const dot2 = document.getElementById('lf-detect-dot');
    const dz   = document.getElementById('lf-detect-deadzone');

    dot.className = '';
    if (state === 'tracking')       { dot.classList.add('tracking');  txt.textContent = 'Tracking'; }
    else if (state === 'no_target') { dot.classList.add('no_target'); txt.textContent = 'No target'; }
    else if (state?.startsWith('error')) { dot.classList.add('error'); txt.textContent = state; }
    else                            { dot.classList.add('idle');     txt.textContent = 'Idle'; }

    stxt.textContent = servoUs != null ? `Pan: ${servoUs} µs` : '—';

    const dzFrac = parseFloat(document.getElementById('lf-dz').value);
    dz.style.left  = `${(0.5 - dzFrac) * 100}%`;
    dz.style.width = `${dzFrac * 200}%`;

    if (detection?.x != null) {
        dot2.style.display = 'block';
        dot2.style.left    = `${detection.x * 100}%`;
    } else {
        dot2.style.display = 'none';
    }
}

// ── Slider labels ─────────────────────────────────────────────────────────────
document.getElementById('lf-gain').addEventListener('input', function () {
    document.getElementById('lf-gain-val').textContent = parseFloat(this.value).toFixed(2);
});
document.getElementById('lf-dz').addEventListener('input', function () {
    document.getElementById('lf-dz-val').textContent = Math.round(this.value * 100) + '%';
    // update dead zone overlay if visible
    const dzFrac = parseFloat(this.value);
    document.getElementById('lf-detect-deadzone').style.left  = `${(0.5 - dzFrac) * 100}%`;
    document.getElementById('lf-detect-deadzone').style.width = `${dzFrac * 200}%`;
});

lfLoad();
</script>
