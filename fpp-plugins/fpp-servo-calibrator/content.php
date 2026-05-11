<?php
// Servo Calibrator — pure frontend, no daemon required.
// Live testing: POST /api/command "Single Channel Fill" (one channel at a time).
// Save: POST /api/channel/output/co-other with updated min/max/description.
// NOTE: Click "Stop Test" when done calibrating to restore normal FPP output.
?>

<style>
#sc { font-family: 'Segoe UI', Arial, sans-serif; background: #0d0d0d; color: #e0e0e0; padding: 12px; }
*, *::before, *::after { box-sizing: border-box; }

/* ── Top bar ─────────────────────────────────────────────── */
#sc-bar {
    display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
    padding: 10px 14px; background: #16213e; border-radius: 8px; margin-bottom: 14px;
}
#sc-bar .sc-lbl { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
#sc-output {
    flex: 1; min-width: 180px; background: #0f3460; color: #e0e0e0;
    border: 1px solid #4cc9f0; border-radius: 4px; padding: 6px 10px; font-size: 13px;
}
.sc-tbtn {
    padding: 7px 16px; border: 2px solid; border-radius: 5px;
    font-weight: bold; font-size: 12px; letter-spacing: 1px; cursor: pointer; white-space: nowrap;
}
#sc-master          { background: #06d6a0; border-color: #06d6a0; color: #000; }
#sc-master.off      { background: transparent; color: #06d6a0; }
#sc-stoptst         { background: transparent; border-color: #e63946; color: #e63946; }
#sc-stoptst:hover   { background: #e63946; color: #fff; }
#sc-savebtn         { background: #4cc9f0; border-color: #4cc9f0; color: #000; }
#sc-savemsg         { font-size: 12px; color: #06d6a0; min-width: 70px; }

/* ── Strip container ─────────────────────────────────────── */
#sc-strips {
    display: flex; flex-direction: row; gap: 5px;
    overflow-x: auto; padding-bottom: 8px; align-items: stretch;
}

/* ── Single strip ────────────────────────────────────────── */
.sc-strip {
    background: #1a1a2e; border: 1px solid #1f2a4a; border-radius: 8px;
    padding: 8px 5px; width: 120px; min-width: 120px;
    display: flex; flex-direction: column; align-items: center; gap: 5px;
    transition: border-color .15s, opacity .15s;
}
.sc-strip.sc-muted  { opacity: .3; }
.sc-strip.sc-soloed { border-color: #f4a261; }
.sc-strip.sc-active { border-color: #4cc9f0; box-shadow: 0 0 6px #4cc9f040; }

.sc-head { display: flex; justify-content: space-between; width: 100%; font-size: 10px; }
.sc-pnum { color: #4cc9f0; font-weight: bold; }
.sc-pch  { color: #555; }

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
.sc-lbl { font-size: 8px; color: #555; text-transform: uppercase; letter-spacing: .5px; }

/* Vertical range inputs — bottom=low, top=high */
input[type="range"].sc-v {
    writing-mode: vertical-lr;
    direction: rtl;
    -webkit-appearance: slider-vertical;
    background: transparent;
    cursor: ns-resize;
}
.sc-fader { width: 34px; height: 160px; accent-color: #4cc9f0; }
.sc-rs    { width: 20px; height: 140px; accent-color: #f4a261; }

.sc-fval { font-size: 9px; color: #4cc9f0; font-family: monospace; text-align: center; min-height: 13px; }
.sc-nval {
    width: 34px; background: #0a0a1a; color: #f4a261;
    border: 1px solid #2a2a3e; border-radius: 3px;
    font-size: 9px; font-family: monospace;
    padding: 1px 2px; text-align: center; -moz-appearance: textfield;
}
.sc-nval::-webkit-inner-spin-button,
.sc-nval::-webkit-outer-spin-button { -webkit-appearance: none; }

/* ── M / S buttons ───────────────────────────────────────── */
.sc-btns { display: flex; gap: 5px; margin-top: 2px; }
.sc-m, .sc-s {
    width: 30px; height: 26px; border: 1px solid #333; border-radius: 4px;
    background: #222; color: #666; font-weight: bold; font-size: 12px; cursor: pointer;
    transition: background .1s, color .1s;
}
.sc-m.on { background: #e63946; color: #fff; border-color: #e63946; }
.sc-s.on { background: #f4a261; color: #000; border-color: #f4a261; }
</style>

<div id="sc">
  <div id="sc-bar">
    <span class="sc-lbl">Output</span>
    <select id="sc-output"><option value="">Loading…</option></select>
    <button id="sc-master"  class="sc-tbtn" onclick="scMaster()">&#9646; ALL ON</button>
    <button id="sc-stoptst" class="sc-tbtn" onclick="scStop()">&#9646; Stop Test</button>
    <button id="sc-savebtn" class="sc-tbtn" onclick="scSave()">Save Config</button>
    <span id="sc-savemsg"></span>
  </div>
  <div id="sc-strips">
    <span style="color:#444; padding:24px; font-size:13px;">Select a PCA9685 output above.</span>
  </div>
</div>

<script>
'use strict';

const SC = { on: true, mute: {}, solo: {}, out: null, data: null, list: [], activeStrip: -1 };

/* ── FPP API ──────────────────────────────────────────────── */

async function scCmd(payload) {
    await fetch('plugin.php?plugin=fpp-servo-calibrator&page=cmd.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
    }).catch(() => {});
}

async function scSendCh(port, us) {
    us = Math.max(0, Math.min(4000, Math.round(us)));
    await scCmd({ action: 'set', port: port, us: us });
}

async function scStop() {
    await scCmd({ action: 'stop' });
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
    SC.list = (SC.data.channelOutputs || []).filter(o => o.type === 'PCA9685');
    const sel = document.getElementById('sc-output');
    sel.innerHTML = '<option value="">Select PCA9685 output…</option>';
    SC.list.forEach((o, i) => {
        const end = o.startChannel + o.channelCount - 1;
        sel.innerHTML += `<option value="${i}">PCA9685  Ch ${o.startChannel}–${end}  (${o.channelCount} ch)</option>`;
    });
    if (SC.list.length === 1) { sel.value = '0'; scRender(0); }
}

async function scSave() {
    if (!SC.out) return;
    document.querySelectorAll('.sc-strip').forEach(strip => {
        const p = +strip.dataset.port;
        if (!SC.out.ports[p]) SC.out.ports[p] = {};
        SC.out.ports[p].min         = +strip.querySelector('.sc-rmin').value;
        SC.out.ports[p].max         = +strip.querySelector('.sc-rmax').value;
        SC.out.ports[p].description =  strip.querySelector('.sc-desc').value;
    });
    const resp = await fetch('/api/channel/output/co-other', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(SC.data)
    }).catch(() => null);
    const el = document.getElementById('sc-savemsg');
    el.style.color  = resp?.ok ? '#06d6a0' : '#e63946';
    el.textContent  = resp?.ok ? '✓ Saved'  : '✗ Failed';
    setTimeout(() => el.textContent = '', 3000);
}

/* ── Render ───────────────────────────────────────────────── */

function scRender(idx) {
    SC.out  = SC.list[idx];
    SC.mute = {};
    SC.solo = {};
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
        cont.appendChild(scStrip(x, ch, min, max, ctr, p.description ?? '', absMin, absMax, unit));
    });
}

function scStrip(px, ch, min, max, ctr, desc, absMin, absMax, unit) {
    const d = document.createElement('div');
    d.className    = 'sc-strip';
    d.id           = `scs${px}`;
    d.dataset.port = px;
    d.dataset.ch   = ch;

    d.innerHTML = `
      <div class="sc-head">
        <span class="sc-pnum">P${px}</span>
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
      </div>
      <div class="sc-btns">
        <button class="sc-m" data-px="${px}" title="Mute">M</button>
        <button class="sc-s" data-px="${px}" title="Solo">S</button>
      </div>`;

    const fdr = d.querySelector('.sc-fader');
    const rmx = d.querySelector('.sc-rmax');
    const rmn = d.querySelector('.sc-rmin');
    const nmx = d.querySelector('.sc-nmx');
    const nmn = d.querySelector('.sc-nmn');

    // Fader — live send on drag
    fdr.addEventListener('input', () => {
        const v = +fdr.value;
        document.getElementById(`scfv${px}`).textContent = v + unit;
        // Highlight active strip
        if (SC.activeStrip !== px) {
            document.querySelectorAll('.sc-strip').forEach(s => s.classList.remove('sc-active'));
            d.classList.add('sc-active');
            SC.activeStrip = px;
        }
        if (scCanOut(px)) scSendCh(px, v);
    });

    // Range Max slider — clamp, sync number input, reclamp fader
    rmx.addEventListener('input', () => {
        if (+rmx.value < +rmn.value) rmx.value = rmn.value;
        nmx.value = rmx.value;
        scClampFader(fdr, rmn, rmx);
    });
    // Max number input — validate, sync range slider
    nmx.addEventListener('change', () => {
        let v = Math.max(+nmx.min, Math.min(+nmx.max, +nmx.value || 0));
        if (v < +rmn.value) v = +rmn.value;
        nmx.value = rmx.value = v;
        scClampFader(fdr, rmn, rmx);
    });

    // Range Min slider — clamp, sync number input, reclamp fader
    rmn.addEventListener('input', () => {
        if (+rmn.value > +rmx.value) rmn.value = rmx.value;
        nmn.value = rmn.value;
        scClampFader(fdr, rmn, rmx);
    });
    // Min number input — validate, sync range slider
    nmn.addEventListener('change', () => {
        let v = Math.max(+nmn.min, Math.min(+nmn.max, +nmn.value || 0));
        if (v > +rmx.value) v = +rmx.value;
        nmn.value = rmn.value = v;
        scClampFader(fdr, rmn, rmx);
    });

    // Mute
    d.querySelector('.sc-m').addEventListener('click', function () {
        SC.mute[px] = !SC.mute[px];
        this.classList.toggle('on', SC.mute[px]);
        d.classList.toggle('sc-muted', SC.mute[px]);
        if (SC.mute[px]) scSendCh(px, Math.round((+rmn.value + +rmx.value) / 2));
    });

    // Solo
    d.querySelector('.sc-s').addEventListener('click', function () {
        SC.solo[px] = !SC.solo[px];
        this.classList.toggle('on', SC.solo[px]);
        d.classList.toggle('sc-soloed', SC.solo[px]);
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

function scMaster() {
    SC.on = !SC.on;
    const b = document.getElementById('sc-master');
    b.textContent = SC.on ? '■ ALL ON' : '□ ALL OFF';
    b.classList.toggle('off', !SC.on);
    if (!SC.on) scStop();
}

function scEsc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/* ── Init ─────────────────────────────────────────────────── */
document.getElementById('sc-output').addEventListener('change', e => {
    if (e.target.value !== '') scRender(+e.target.value);
});
scLoad();
</script>
