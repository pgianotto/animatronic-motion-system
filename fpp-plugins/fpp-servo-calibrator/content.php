<?php
// Servo Calibrator — pure frontend, no daemon required.
// Live testing: direct I2C writes via servo_ctl.py (PCA9685 registers).
// Save: POST /api/channel/output/co-other with updated min/max/center/description.
?>

<style>
#sc { font-family: 'Segoe UI', Arial, sans-serif; background: #0d0d0d; color: #e0e0e0; padding: 12px; }
*, *::before, *::after { box-sizing: border-box; }

/* ── Top bar ─────────────────────────────────────────────── */
#sc-bar {
    display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
    padding: 10px 14px; background: #16213e; border-radius: 8px; margin-bottom: 14px;
}
#sc-bar .sc-lbl { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
#sc-output {
    flex: 1; min-width: 180px; background: #0f3460; color: #e0e0e0;
    border: 1px solid #4cc9f0; border-radius: 4px; padding: 6px 10px; font-size: 13px;
}
.sc-tbtn {
    padding: 7px 14px; border: 2px solid; border-radius: 5px;
    font-weight: bold; font-size: 12px; letter-spacing: 1px; cursor: pointer; white-space: nowrap;
}
#sc-enable          { background: transparent; border-color: #06d6a0; color: #06d6a0; }
#sc-enable.on       { background: #e63946; border-color: #e63946; color: #fff; }
#sc-ramp            { background: transparent; border-color: #a78bfa; color: #a78bfa; }
#sc-ramp.on         { background: #7c3aed; border-color: #7c3aed; color: #fff; }
#sc-ctrall          { background: transparent; border-color: #ffd60a; color: #ffd60a; }
#sc-savebtn         { background: #4cc9f0; border-color: #4cc9f0; color: #000; }
#sc-savemsg         { font-size: 12px; color: #06d6a0; min-width: 70px; }

/* ── Speed control ───────────────────────────────────────── */
#sc-speed-wrap { display: flex; align-items: center; gap: 5px; }
#sc-period { width: 80px; accent-color: #a78bfa; cursor: pointer; }
#sc-period-val { font-size: 11px; color: #a78bfa; min-width: 32px; }

/* ── Strip container ─────────────────────────────────────── */
#sc-strips {
    display: flex; flex-direction: row; gap: 5px;
    overflow-x: auto; padding-bottom: 8px; align-items: stretch;
}

/* ── Single strip ────────────────────────────────────────── */
.sc-strip {
    background: #1a1a2e; border: 1px solid #1f2a4a; border-radius: 8px;
    padding: 8px 5px; width: 168px; min-width: 168px;
    display: flex; flex-direction: column; align-items: center; gap: 5px;
    transition: border-color .15s, box-shadow .15s, opacity .15s;
}
.sc-strip.sc-muted  { opacity: .3; }
.sc-strip.sc-soloed { border-color: #f4a261; }
.sc-strip.sc-active { border-color: #4cc9f0; box-shadow: 0 0 6px #4cc9f040; }
.sc-strip.sc-dirty  { box-shadow: 0 0 0 2px #ffd60a55; }
.sc-strip.sc-active.sc-dirty { box-shadow: 0 0 6px #4cc9f040, 0 0 0 2px #ffd60a55; }

.sc-head { display: flex; justify-content: space-between; align-items: center; width: 100%; font-size: 10px; }
.sc-pnum { color: #4cc9f0; font-weight: bold; }
.sc-pch  { color: #555; }
.sc-dirty-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #ffd60a; opacity: 0; transition: opacity .2s;
    title: 'Unsaved changes';
}
.sc-strip.sc-dirty .sc-dirty-dot { opacity: 1; }

.sc-desc {
    width: 100%; background: #0a0a1a; color: #ccc;
    border: 1px solid #2a2a3e; border-radius: 3px;
    font-size: 10px; padding: 2px 4px; text-align: center;
}

/* ── Slider area ─────────────────────────────────────────── */
.sc-sliders {
    display: flex; flex-direction: row; align-items: flex-start;
    justify-content: center; gap: 5px; padding: 2px 0;
}
.sc-col { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.sc-lbl { font-size: 8px; color: #aaa; text-transform: uppercase; letter-spacing: .5px; }

input[type="range"].sc-v {
    writing-mode: vertical-lr;
    direction: rtl;
    -webkit-appearance: slider-vertical;
    background: transparent;
    cursor: ns-resize;
}
.sc-fader { width: 34px; height: 220px; accent-color: #4cc9f0; }
.sc-rs    { width: 14px; height: 160px; accent-color: #f4a261; }
.sc-rctr  { width: 14px; height: 160px; accent-color: #06d6a0; }

/* ── Capture buttons (set min/ctr/max from fader) ───────── */
.sc-capture-wrap { display: flex; align-items: center; justify-content: center; gap: 3px; }
.sc-cap-lbl { font-size: 8px; color: #555; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
.sc-cap-btn {
    padding: 2px 7px; border: 1px solid #374151; border-radius: 3px;
    background: #111827; color: #9ca3af; font-size: 9px; font-weight: bold; cursor: pointer;
    transition: background .1s, color .1s, border-color .1s;
}
.sc-cap-btn:hover { background: #1f2937; color: #e0e0e0; border-color: #4cc9f0; }
.sc-cap-btn:active { background: #4cc9f0; color: #000; border-color: #4cc9f0; }

/* ── Step controls ───────────────────────────────────────── */
.sc-step-wrap { display: flex; align-items: center; justify-content: center; gap: 3px; }
.sc-step-dn, .sc-step-up {
    width: 28px; height: 24px; border: 1px solid #374151; border-radius: 3px;
    background: #111827; color: #9ca3af; font-size: 16px; font-weight: bold;
    cursor: pointer; line-height: 1; padding: 0; transition: background .1s, color .1s;
}
.sc-step-dn:hover, .sc-step-up:hover { background: #1f2937; color: #e0e0e0; }
.sc-step-sz { width: 36px; -moz-appearance: textfield; }
#sc input.sc-step-sz {
    background: #111827 !important; color: #e0e0e0 !important;
    border: 1px solid #374151 !important; border-radius: 3px !important;
    font-size: 10px !important; text-align: center !important; padding: 2px 3px !important;
}
.sc-step-sz::-webkit-inner-spin-button,
.sc-step-sz::-webkit-outer-spin-button { -webkit-appearance: none; }

.sc-fval { font-size: 9px; color: #4cc9f0; font-family: monospace; text-align: center; min-height: 13px; }
.sc-pct  { font-size: 8px; color: #555; font-family: monospace; text-align: center; }

.sc-nval {
    width: 38px; background: #111827;
    border: 1px solid #374151; border-radius: 3px;
    padding: 2px 3px; text-align: center; -moz-appearance: textfield;
}
/* Override FPP Bootstrap font and color */
#sc input.sc-nval {
    color: #e0e0e0 !important; background: #111827 !important;
    font-size: 10px !important; font-family: monospace !important;
}
#sc input.sc-desc {
    color: #e0e0e0 !important; background: #0a0a1a !important;
    font-size: 10px !important;
}
.sc-nval::-webkit-inner-spin-button,
.sc-nval::-webkit-outer-spin-button { -webkit-appearance: none; }
.sc-nval.sc-invalid { border-color: #e63946 !important; }

/* ── Strip buttons ───────────────────────────────────────── */
.sc-btns { display: flex; gap: 3px; margin-top: 2px; justify-content: center; }
.sc-m, .sc-s, .sc-f, .sc-cp {
    width: 26px; height: 26px; border: 1px solid #333; border-radius: 4px;
    background: #222; color: #666; font-weight: bold; font-size: 11px; cursor: pointer;
    transition: background .1s, color .1s, border-color .1s;
}
.sc-m.on { background: #e63946; color: #fff; border-color: #e63946; }
.sc-s.on { background: #f4a261; color: #000; border-color: #f4a261; }
.sc-f.on { background: #f4a261; color: #000; border-color: #f4a261; }
.sc-cp { font-size: 13px; }
.sc-copy.flash   { background: #4cc9f0; color: #000; border-color: #4cc9f0; }
.sc-paste.ready  { border-color: #4cc9f0; color: #4cc9f0; }
.sc-paste:disabled { opacity: .3; cursor: default; }

/* ── Zero behavior select ─────────────────────────────────── */
.sc-zb-wrap { display: flex; align-items: center; justify-content: center; gap: 4px; width: 100%; }
.sc-zb-lbl { font-size: 8px; color: #aaa; text-transform: uppercase; letter-spacing: .5px; }
#sc select.sc-zbsel {
    flex: 1; background: #111827; color: #e0e0e0;
    border: 1px solid #374151; border-radius: 3px;
    font-size: 9px; padding: 2px 3px; cursor: pointer;
}
</style>

<div id="sc">
  <div id="sc-bar">
    <span class="sc-lbl">Output</span>
    <select id="sc-output"><option value="">Loading…</option></select>
    <button id="sc-enable" class="sc-tbtn" onclick="scToggleTest()">Enable Test</button>
    <button id="sc-ramp"   class="sc-tbtn" onclick="scToggleRamp()">Ramp Test</button>
    <button id="sc-ctrall" class="sc-tbtn" onclick="scCenterAll()">Center All</button>
    <div id="sc-speed-wrap">
      <span class="sc-lbl">Speed</span>
      <input type="range" id="sc-period" min="500" max="10000" step="100" value="3000">
      <span id="sc-period-val">3.0s</span>
    </div>
    <button id="sc-savebtn" class="sc-tbtn" onclick="scSave()">Save Config</button>
    <span id="sc-savemsg"></span>
  </div>
  <div id="sc-strips">
    <span style="color:#444; padding:24px; font-size:13px;">Select a servo output above.</span>
  </div>
</div>

<script>
'use strict';

const SC = {
    on: false, ramp: false,
    mute: {}, solo: {}, flip: {}, dirty: {}, phases: {}, clipboard: null,
    out: null, outIdx: 0, data: null, list: [], activeStrip: -1
};
let scRampInterval  = null;
let scRampStartTime = null;
let scRampInFlight  = false;

/* ── FPP API ──────────────────────────────────────────────── */

async function scCmd(payload) {
    await fetch('plugin.php?plugin=fpp-servo-calibrator&page=cmd.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ out: SC.outIdx, ...payload })
    }).catch(() => {});
}

async function scSendCh(port, us) {
    us = Math.max(0, Math.min(4000, Math.round(us)));
    await scCmd({ action: 'set', port, us });
}

async function scSendAll(channels) {
    if (!channels.length) return;
    await fetch('plugin.php?plugin=fpp-servo-calibrator&page=cmd.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ out: SC.outIdx, action: 'set_all', channels })
    }).catch(() => {});
}

async function scStop() {
    if (!SC.out) return;
    await scCmd({ action: 'stop' });
    document.querySelectorAll('.sc-strip').forEach(s => s.classList.remove('sc-active'));
    SC.activeStrip = -1;
}

async function scApplyZeroBehaviors() {
    if (!SC.out) return;
    const channels = [];
    document.querySelectorAll('.sc-strip').forEach(strip => {
        const px  = +strip.dataset.port;
        const zb  = parseInt(strip.querySelector('.sc-zbsel')?.value ?? '0', 10);
        const mn  = +strip.querySelector('.sc-rmin').value;
        const ctr = +strip.querySelector('.sc-rcenter').value;
        if      (zb === 1) channels.push({ port: px, us: mn  });  // Normal → min (ch=0 position)
        else if (zb === 2) channels.push({ port: px, us: ctr });  // To Center
        else if (zb === 3) channels.push({ port: px, us: 0   });  // Stop PWM → no signal
        // 0 (Hold) → keep last I2C output, no command needed
    });
    if (channels.length) await scSendAll(channels);
    document.querySelectorAll('.sc-strip').forEach(s => s.classList.remove('sc-active'));
    SC.activeStrip = -1;
}

async function scLoad() {
    const r = await fetch('/api/channel/output/co-other').catch(() => null);
    if (!r?.ok) {
        document.getElementById('sc-output').innerHTML = '<option>Error loading outputs</option>';
        return;
    }
    SC.data = await r.json();
    // Show any output that has a ports array (PCA9685, K2-Pi-Servo, etc.)
    SC.list = (SC.data.channelOutputs || []).filter(o => o.ports && o.ports.length > 0);
    const sel = document.getElementById('sc-output');
    sel.innerHTML = '<option value="">Select servo output…</option>';
    SC.list.forEach((o, i) => {
        const end = o.startChannel + o.channelCount - 1;
        sel.innerHTML += `<option value="${i}">${o.type}  Ch ${o.startChannel}–${end}  (${o.channelCount} ch)</option>`;
    });
    if (SC.list.length === 1) { sel.value = '0'; scRender(0); }
}

async function scSave() {
    if (!SC.out) return;
    document.querySelectorAll('.sc-strip').forEach(strip => {
        const p    = +strip.dataset.port;
        const rmn  = strip.querySelector('.sc-rmin');
        const rmx  = strip.querySelector('.sc-rmax');
        const rctr = strip.querySelector('.sc-rcenter');
        const nmn  = strip.querySelector('.sc-nmn');
        const nmx  = strip.querySelector('.sc-nmx');
        const nctr = strip.querySelector('.sc-ncenter');
        // Flush typed-but-not-blurred values
        const vx = +nmx.value, vn = +nmn.value, vc = +nctr.value;
        if (Number.isFinite(vx) && vx >= +nmx.min && vx <= +nmx.max) rmx.value = vx;
        if (Number.isFinite(vn) && vn >= +nmn.min && vn <= +nmn.max) rmn.value = vn;
        if (+rmx.value < +rmn.value) rmx.value = rmn.value;
        const cMin = +rmn.value, cMax = +rmx.value;
        if (Number.isFinite(vc) && vc >= cMin && vc <= cMax) rctr.value = vc;
        if (!SC.out.ports[p]) SC.out.ports[p] = {};
        SC.out.ports[p].min         = cMin;
        SC.out.ports[p].max         = cMax;
        SC.out.ports[p].center      = +rctr.value;
        SC.out.ports[p].description  = strip.querySelector('.sc-desc').value;
        SC.out.ports[p].zeroBehavior = parseInt(strip.querySelector('.sc-zbsel').value, 10);
    });
    const resp = await fetch('/api/channel/output/co-other', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(SC.data)
    }).catch(() => null);
    const el = document.getElementById('sc-savemsg');
    if (resp?.ok) {
        el.style.color = '#06d6a0';
        el.textContent = '✓ Saved';
        document.querySelectorAll('.sc-strip').forEach(s => s.classList.remove('sc-dirty'));
        SC.dirty = {};
        scCmd({ action: 'reload' }); // tell daemon to re-read co-other.json
    } else {
        el.style.color = '#e63946';
        el.textContent = '✗ Failed';
    }
    setTimeout(() => el.textContent = '', 3000);
}

/* ── Render ───────────────────────────────────────────────── */

function scRender(idx) {
    scRampStop();
    SC.out     = SC.list[idx];
    SC.outIdx  = idx;
    SC.mute    = {};
    SC.solo    = {};
    SC.flip    = {};
    SC.dirty   = {};
    SC.phases  = {};
    SC.activeStrip = -1;
    const cont   = document.getElementById('sc-strips');
    const usec   = !!SC.out.asUsec;
    const absMin = usec ? 500  : 0;
    const absMax = usec ? 2500 : 4095;
    const unit   = usec ? 'μs' : '';
    cont.innerHTML = '';
    (SC.out.ports || []).forEach((p, x) => {
        const min = p.min    ?? 1000;
        const max = p.max    ?? 2000;
        const ctr = p.center ?? Math.round((min + max) / 2);
        const ch  = SC.out.startChannel + x;
        SC.mute[x] = false;
        SC.solo[x] = false;
        SC.flip[x] = false;
        const zb = Number.isInteger(p.zeroBehavior) ? p.zeroBehavior : 0;
        cont.appendChild(scStrip(x, ch, min, max, ctr, p.description ?? '', zb, absMin, absMax, unit));
    });
}

function scStrip(px, ch, min, max, ctr, desc, zeroBehavior, absMin, absMax, unit) {
    const d = document.createElement('div');
    d.className    = 'sc-strip';
    d.id           = `scs${px}`;
    d.dataset.port = px;
    d.dataset.ch   = ch;
    const initPct = max > min ? Math.round((ctr - min) / (max - min) * 100) : 50;

    d.innerHTML = `
      <div class="sc-head">
        <span class="sc-pnum">P${px}</span>
        <span class="sc-dirty-dot" title="Unsaved changes"></span>
        <span class="sc-pch">Ch${ch}</span>
      </div>
      <input class="sc-desc" type="text" value="${scEsc(desc)}" placeholder="Label">
      <div class="sc-sliders">
        <div class="sc-col">
          <span class="sc-lbl">Test</span>
          <input type="range" class="sc-v sc-fader"
                 min="${min}" max="${max}" value="${ctr}"
                 data-px="${px}" data-ch="${ch}">
          <span class="sc-fval" id="scfv${px}">${ctr}${unit}</span>
          <span class="sc-pct"  id="scpct${px}">${initPct}%</span>
        </div>
        <div class="sc-col">
          <span class="sc-lbl">Max</span>
          <input type="range" class="sc-v sc-rs sc-rmax"
                 min="${absMin}" max="${absMax}" value="${max}" data-px="${px}">
          <input type="number" class="sc-nval sc-nmx" id="scmx${px}"
                 value="${max}" min="${absMin}" max="${absMax}">
        </div>
        <div class="sc-col">
          <span class="sc-lbl">Min</span>
          <input type="range" class="sc-v sc-rs sc-rmin"
                 min="${absMin}" max="${absMax}" value="${min}" data-px="${px}">
          <input type="number" class="sc-nval sc-nmn" id="scmn${px}"
                 value="${min}" min="${absMin}" max="${absMax}">
        </div>
        <div class="sc-col">
          <span class="sc-lbl">Ctr</span>
          <input type="range" class="sc-v sc-rctr sc-rcenter"
                 min="${min}" max="${max}" value="${ctr}" data-px="${px}">
          <input type="number" class="sc-nval sc-ncenter" id="scctr${px}"
                 value="${ctr}" min="${min}" max="${max}">
        </div>
      </div>
      <div class="sc-capture-wrap">
        <span class="sc-cap-lbl">Set→</span>
        <button class="sc-cap-btn sc-cap-min" title="Set Min to current fader position">Min</button>
        <button class="sc-cap-btn sc-cap-ctr" title="Set Center to current fader position">Ctr</button>
        <button class="sc-cap-btn sc-cap-max" title="Set Max to current fader position">Max</button>
      </div>
      <div class="sc-zb-wrap">
        <span class="sc-zb-lbl">Zero</span>
        <select class="sc-zbsel" title="Behavior when test mode is disabled">
          <option value="0"${zeroBehavior === 0 ? ' selected' : ''}>Hold</option>
          <option value="1"${zeroBehavior === 1 ? ' selected' : ''}>Normal</option>
          <option value="2"${zeroBehavior === 2 ? ' selected' : ''}>To Center</option>
          <option value="3"${zeroBehavior === 3 ? ' selected' : ''}>Stop PWM</option>
        </select>
      </div>
      <div class="sc-step-wrap">
        <button class="sc-step-dn" title="Step down">−</button>
        <input  type="number" class="sc-step-sz sc-nval" value="10" min="1" max="999" title="Step size">
        <button class="sc-step-up" title="Step up">+</button>
      </div>
      <div class="sc-btns">
        <button class="sc-m"  data-px="${px}" title="Mute">M</button>
        <button class="sc-s"  data-px="${px}" title="Solo">S</button>
        <button class="sc-f"  data-px="${px}" title="Flip output direction">F</button>
        <button class="sc-cp sc-copy"  data-px="${px}" title="Copy settings">⧉</button>
        <button class="sc-cp sc-paste" data-px="${px}" title="Paste settings" disabled>⬇</button>
      </div>`;

    const fdr  = d.querySelector('.sc-fader');
    const rmx  = d.querySelector('.sc-rmax');
    const rmn  = d.querySelector('.sc-rmin');
    const rctr = d.querySelector('.sc-rcenter');
    const nmx  = d.querySelector('.sc-nmx');
    const nmn  = d.querySelector('.sc-nmn');
    const nctr = d.querySelector('.sc-ncenter');

    // ── Fader (test only, does not mark dirty)
    fdr.addEventListener('input', () => {
        const rawV = +fdr.value;
        const outV = SC.flip[px] ? +rmn.value + +rmx.value - rawV : rawV;
        const pct  = +rmx.value > +rmn.value
            ? Math.round((rawV - +rmn.value) / (+rmx.value - +rmn.value) * 100) : 50;
        document.getElementById(`scfv${px}`).textContent  = rawV + unit;
        document.getElementById(`scpct${px}`).textContent = pct + '%';
        if (SC.activeStrip !== px) {
            document.querySelectorAll('.sc-strip').forEach(s => s.classList.remove('sc-active'));
            d.classList.add('sc-active');
            SC.activeStrip = px;
        }
        if (scCanOut(px)) scSendCh(px, outV);
    });

    // ── Max slider + number input
    rmx.addEventListener('input', () => {
        if (+rmx.value < +rmn.value) rmx.value = rmn.value;
        nmx.value = rmx.value;
        scClampFader(fdr, rmn, rmx);
        scClampCenter(rctr, nctr, rmn, rmx);
        scMarkDirty(px);
    });
    nmx.addEventListener('input', () => {
        const v = +nmx.value;
        if (Number.isFinite(v) && v >= +nmx.min && v <= +nmx.max && v >= +rmn.value) {
            rmx.value = v;
            scClampFader(fdr, rmn, rmx);
            scClampCenter(rctr, nctr, rmn, rmx);
            scMarkDirty(px);
        }
    });
    nmx.addEventListener('change', () => {
        let v = Math.max(+nmx.min, Math.min(+nmx.max, +nmx.value || 0));
        if (v < +rmn.value) v = +rmn.value;
        nmx.value = rmx.value = v;
        scClampFader(fdr, rmn, rmx);
        scClampCenter(rctr, nctr, rmn, rmx);
        scMarkDirty(px);
    });

    // ── Min slider + number input
    rmn.addEventListener('input', () => {
        if (+rmn.value > +rmx.value) rmn.value = rmx.value;
        nmn.value = rmn.value;
        scClampFader(fdr, rmn, rmx);
        scClampCenter(rctr, nctr, rmn, rmx);
        scMarkDirty(px);
    });
    nmn.addEventListener('input', () => {
        const v = +nmn.value;
        if (Number.isFinite(v) && v >= +nmn.min && v <= +nmn.max && v <= +rmx.value) {
            rmn.value = v;
            scClampFader(fdr, rmn, rmx);
            scClampCenter(rctr, nctr, rmn, rmx);
            scMarkDirty(px);
        }
    });
    nmn.addEventListener('change', () => {
        let v = Math.max(+nmn.min, Math.min(+nmn.max, +nmn.value || 0));
        if (v > +rmx.value) v = +rmx.value;
        nmn.value = rmn.value = v;
        scClampFader(fdr, rmn, rmx);
        scClampCenter(rctr, nctr, rmn, rmx);
        scMarkDirty(px);
    });

    // ── Center slider + number input
    rctr.addEventListener('input', () => {
        nctr.value = rctr.value;
        nctr.classList.remove('sc-invalid');
        scMarkDirty(px);
    });
    nctr.addEventListener('input', () => {
        const v = +nctr.value;
        const valid = Number.isFinite(v) && v >= +rmn.value && v <= +rmx.value;
        nctr.classList.toggle('sc-invalid', !valid && nctr.value !== '');
        if (valid) { rctr.value = v; scMarkDirty(px); }
    });
    nctr.addEventListener('change', () => {
        let v = Math.max(+rmn.value, Math.min(+rmx.value, +nctr.value || +rmn.value));
        nctr.value = rctr.value = v;
        nctr.classList.remove('sc-invalid');
        scMarkDirty(px);
    });

    // ── Description
    d.querySelector('.sc-desc').addEventListener('input', () => scMarkDirty(px));

    // ── Zero behavior
    d.querySelector('.sc-zbsel').addEventListener('change', () => scMarkDirty(px));

    // ── Mute
    d.querySelector('.sc-m').addEventListener('click', function () {
        SC.mute[px] = !SC.mute[px];
        this.classList.toggle('on', SC.mute[px]);
        d.classList.toggle('sc-muted', SC.mute[px]);
        if (SC.mute[px]) scSendCh(px, Math.round((+rmn.value + +rmx.value) / 2));
    });

    // ── Solo
    d.querySelector('.sc-s').addEventListener('click', function () {
        SC.solo[px] = !SC.solo[px];
        this.classList.toggle('on', SC.solo[px]);
        d.classList.toggle('sc-soloed', SC.solo[px]);
    });

    // ── Flip
    d.querySelector('.sc-f').addEventListener('click', function () {
        SC.flip[px] = !SC.flip[px];
        this.classList.toggle('on', SC.flip[px]);
    });

    // ── Step down / up
    const stepSz = d.querySelector('.sc-step-sz');
    d.querySelector('.sc-step-dn').addEventListener('click', () => {
        const step = Math.max(1, Math.round(+stepSz.value) || 1);
        fdr.value  = Math.max(+fdr.min, +fdr.value - step);
        fdr.dispatchEvent(new Event('input'));
    });
    d.querySelector('.sc-step-up').addEventListener('click', () => {
        const step = Math.max(1, Math.round(+stepSz.value) || 1);
        fdr.value  = Math.min(+fdr.max, +fdr.value + step);
        fdr.dispatchEvent(new Event('input'));
    });

    // ── Capture fader value → Min / Ctr / Max
    d.querySelector('.sc-cap-min').addEventListener('click', () => {
        const v = Math.min(+fdr.value, +rmx.value);
        rmn.value = nmn.value = v;
        scClampFader(fdr, rmn, rmx);
        scClampCenter(rctr, nctr, rmn, rmx);
        scMarkDirty(px);
    });
    d.querySelector('.sc-cap-ctr').addEventListener('click', () => {
        const v = Math.max(+rmn.value, Math.min(+rmx.value, +fdr.value));
        rctr.value = nctr.value = v;
        nctr.classList.remove('sc-invalid');
        scMarkDirty(px);
    });
    d.querySelector('.sc-cap-max').addEventListener('click', () => {
        const v = Math.max(+fdr.value, +rmn.value);
        rmx.value = nmx.value = v;
        scClampFader(fdr, rmn, rmx);
        scClampCenter(rctr, nctr, rmn, rmx);
        scMarkDirty(px);
    });

    // ── Copy
    d.querySelector('.sc-copy').addEventListener('click', function () {
        SC.clipboard = {
            min:  +rmn.value,
            max:  +rmx.value,
            ctr:  +rctr.value,
            desc: d.querySelector('.sc-desc').value
        };
        document.querySelectorAll('.sc-paste').forEach(b => {
            b.disabled = false;
            b.classList.add('ready');
        });
        this.classList.add('flash');
        setTimeout(() => this.classList.remove('flash'), 700);
    });

    // ── Paste
    d.querySelector('.sc-paste').addEventListener('click', () => {
        if (!SC.clipboard) return;
        const { min, max, ctr, desc } = SC.clipboard;
        const newMin = Math.max(+nmn.min, Math.min(+nmx.max, min));
        const newMax = Math.max(+nmn.min, Math.min(+nmx.max, max));
        const newCtr = Math.max(newMin, Math.min(newMax, ctr));
        rmn.value  = nmn.value  = newMin;
        rmx.value  = nmx.value  = newMax;
        rctr.value = nctr.value = newCtr;
        d.querySelector('.sc-desc').value = desc;
        scClampFader(fdr, rmn, rmx);
        scClampCenter(rctr, nctr, rmn, rmx);
        scMarkDirty(px);
    });

    return d;
}

/* ── Helpers ──────────────────────────────────────────────── */

function scCanOut(px) {
    if (!SC.on) return false;
    if (SC.mute[px]) return false;
    const anySolo = Object.values(SC.solo).some(Boolean);
    return !anySolo || !!SC.solo[px];
}

function scClampFader(fdr, rmn, rmx) {
    fdr.min = rmn.value;
    fdr.max = rmx.value;
    if (+fdr.value < +rmn.value) fdr.value = rmn.value;
    if (+fdr.value > +rmx.value) fdr.value = rmx.value;
}

function scClampCenter(rctr, nctr, rmn, rmx) {
    rctr.min = rmn.value;
    rctr.max = rmx.value;
    if (+rctr.value < +rmn.value) { rctr.value = rmn.value; nctr.value = rmn.value; }
    if (+rctr.value > +rmx.value) { rctr.value = rmx.value; nctr.value = rmx.value; }
}

function scMarkDirty(px) {
    SC.dirty[px] = true;
    const strip = document.getElementById(`scs${px}`);
    if (strip) strip.classList.add('sc-dirty');
}

function scComputePhases() {
    SC.phases = {};
    const active = [];
    document.querySelectorAll('.sc-strip').forEach(strip => {
        const px = +strip.dataset.port;
        if (scCanOut(px)) active.push(px);
    });
    const period = +document.getElementById('sc-period').value;
    active.forEach((px, i) => { SC.phases[px] = i / active.length * period; });
}

/* ── Test controls ────────────────────────────────────────── */

function scToggleTest() {
    SC.on = !SC.on;
    const b = document.getElementById('sc-enable');
    b.textContent = SC.on ? '■ Test Active' : 'Enable Test';
    b.classList.toggle('on', SC.on);
    if (!SC.on) { scRampStop(); scApplyZeroBehaviors(); }
}

async function scCenterAll() {
    const unit = SC.out?.asUsec ? 'μs' : '';
    document.querySelectorAll('.sc-strip').forEach(strip => {
        const px  = +strip.dataset.port;
        const fdr = strip.querySelector('.sc-fader');
        const rmn = strip.querySelector('.sc-rmin');
        const rmx = strip.querySelector('.sc-rmax');
        const ctr = +strip.querySelector('.sc-rcenter').value;
        const pct = +rmx.value > +rmn.value
            ? Math.round((ctr - +rmn.value) / (+rmx.value - +rmn.value) * 100) : 50;
        fdr.value = Math.max(+fdr.min, Math.min(+fdr.max, ctr));
        document.getElementById(`scfv${px}`).textContent  = ctr + unit;
        document.getElementById(`scpct${px}`).textContent = pct + '%';
    });
    await scStop();
}

/* ── Ramp test ────────────────────────────────────────────── */

async function scRampStep() {
    if (!SC.ramp || !SC.on) { scRampStop(); return; }
    if (scRampInFlight) return;
    scRampInFlight = true;
    const now    = Date.now();
    if (!scRampStartTime) scRampStartTime = now;
    const period = +document.getElementById('sc-period').value;
    const unit   = SC.out?.asUsec ? 'μs' : '';
    const channels = [];
    document.querySelectorAll('.sc-strip').forEach(strip => {
        const px    = +strip.dataset.port;
        if (!scCanOut(px)) return;
        const rmn   = +strip.querySelector('.sc-rmin').value;
        const rmx   = +strip.querySelector('.sc-rmax').value;
        const phase = SC.phases[px] || 0;
        const t     = ((now - scRampStartTime + phase) % period) / period;
        const frac  = t < 0.5 ? 2 * t : 2 * (1 - t);  // triangle wave
        const v     = Math.round(rmn + (rmx - rmn) * frac);
        const outV  = SC.flip[px] ? rmx + rmn - v : v;
        const pct   = rmx > rmn ? Math.round((v - rmn) / (rmx - rmn) * 100) : 50;
        const fdr   = strip.querySelector('.sc-fader');
        fdr.value   = Math.max(+fdr.min, Math.min(+fdr.max, v));
        document.getElementById(`scfv${px}`).textContent  = v + unit;
        document.getElementById(`scpct${px}`).textContent = pct + '%';
        channels.push({ port: px, us: outV });
    });
    try {
        if (channels.length) await scSendAll(channels);
    } finally {
        scRampInFlight = false;
    }
}

function scRampStop() {
    SC.ramp = false;
    if (scRampInterval) { clearInterval(scRampInterval); scRampInterval = null; }
    scRampStartTime = null;
    scRampInFlight  = false;
    const b = document.getElementById('sc-ramp');
    if (b) { b.textContent = 'Ramp Test'; b.classList.remove('on'); }
}

function scToggleRamp() {
    SC.ramp = !SC.ramp;
    const b = document.getElementById('sc-ramp');
    if (SC.ramp) {
        if (!SC.on) scToggleTest();
        scComputePhases();
        b.textContent = '■ Ramp Active';
        b.classList.add('on');
        scRampStartTime = null;
        scRampInterval  = setInterval(scRampStep, 50);
    } else {
        scRampStop();
    }
}

function scEsc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/* ── Init ─────────────────────────────────────────────────── */
document.getElementById('sc-output').addEventListener('change', e => {
    if (e.target.value !== '') scRender(+e.target.value);
});
document.getElementById('sc-period').addEventListener('input', function () {
    document.getElementById('sc-period-val').textContent = (this.value / 1000).toFixed(1) + 's';
});
scLoad();
</script>
