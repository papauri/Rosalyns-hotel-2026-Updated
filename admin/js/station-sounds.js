/**
 * station-sounds.js — Notification Sound & Alert System for RH POS & KDS
 *
 * Synthesised entirely via Web Audio API — no audio files required.
 * Settings (volume, sound choices, enabled state) persist in localStorage.
 * Provides: RHSounds  (sound engine + settings panel)
 *           RHNotif   (rich notification-card stack)
 *
 * Both namespaces are attached to window so any inline script can call them.
 */
(function (global) {
    'use strict';

    /* ─── Constants ────────────────────────────────────────────────────── */
    const LS_KEY = 'rh_station_sounds_v2';
    const NORMAL_SOUNDS = [
        { id: 'chime', label: 'Chime' },
        { id: 'bell', label: 'Bell' },
        { id: 'double_tap', label: 'Double Tap' },
        { id: 'triple_rise', label: 'Triple Rise' },
        { id: 'soft_ding', label: 'Soft Ding' },
        { id: 'xylophone', label: 'Xylophone' },
        { id: 'warm_tick', label: 'Warm Tick' },
        { id: 'water_drop', label: 'Water Drop' },
    ];
    const URGENT_SOUNDS = [
        { id: 'alarm', label: 'Alarm' },
        { id: 'siren', label: 'Siren' },
        { id: 'rapid_beep', label: 'Rapid Beep' },
        { id: 'descending', label: 'Descending' },
        { id: 'buzz_alert', label: 'Buzz Alert' },
        { id: 'kitchen_call', label: 'Kitchen Call' },
        { id: 'long_pulse', label: 'Long Pulse' },
    ];

    /* ─── State ─────────────────────────────────────────────────────────── */
    let _ctx = null;
    let _settings = { enabled: true, volume: 0.75, normal: 'chime', urgent: 'alarm' };
    let _toggleCbs = [];
    let _interactionUnlocked = false;

    /* ─── Persistence ───────────────────────────────────────────────────── */
    function _load() {
        try {
            const s = JSON.parse(localStorage.getItem(LS_KEY) || '{}');
            if (typeof s.enabled === 'boolean') _settings.enabled = s.enabled;
            if (typeof s.volume === 'number' && s.volume >= 0 && s.volume <= 1) _settings.volume = s.volume;
            if (NORMAL_SOUNDS.find(x => x.id === s.normal)) _settings.normal = s.normal;
            if (URGENT_SOUNDS.find(x => x.id === s.urgent)) _settings.urgent = s.urgent;
        } catch (e) { /* corrupt storage — use defaults */ }
    }
    function _save() {
        try { localStorage.setItem(LS_KEY, JSON.stringify(_settings)); } catch (e) { }
    }

    /* ─── AudioContext ──────────────────────────────────────────────────── */
    function _getCtx() {
        if (!_interactionUnlocked) return null;
        if (!global.AudioContext && !global.webkitAudioContext) return null;
        if (!_ctx || _ctx.state === 'closed') {
            _ctx = new (global.AudioContext || global.webkitAudioContext)();
        }
        if (_ctx.state === 'suspended') {
            try {
                const resumePromise = _ctx.resume();
                if (resumePromise && typeof resumePromise.catch === 'function') {
                    resumePromise.catch(() => { });
                }
            } catch (e) { }
        }
        return _ctx;
    }
    function unlockAudio(force) {
        if (!_interactionUnlocked && !force) return;
        try { _getCtx(); } catch (e) { }
    }
    function _markInteractionUnlocked() {
        _interactionUnlocked = true;
        unlockAudio(true);
        if (typeof _hideUnlockPrompt === 'function') _hideUnlockPrompt();
    }
    document.addEventListener('pointerdown', _markInteractionUnlocked, { once: true, passive: true, capture: true });
    document.addEventListener('click', _markInteractionUnlocked, { once: true, capture: true });
    document.addEventListener('touchstart', _markInteractionUnlocked, { once: true, passive: true, capture: true });
    document.addEventListener('keydown', _markInteractionUnlocked, { once: true, capture: true });

    /* ─── Low-level oscillator helpers ─────────────────────────────────── */
    function _tone(ctx, type, freq, t, dur, vol) {
        const osc = ctx.createOscillator();
        const gn = ctx.createGain();
        osc.connect(gn); gn.connect(ctx.destination);
        osc.type = type; osc.frequency.value = freq;
        const v = vol * _settings.volume;
        gn.gain.setValueAtTime(0.001, t);
        gn.gain.linearRampToValueAtTime(v, t + 0.012);
        gn.gain.exponentialRampToValueAtTime(0.001, t + dur);
        osc.start(t); osc.stop(t + dur + 0.05);
    }
    function _sweep(ctx, type, f0, f1, t, dur, vol) {
        const osc = ctx.createOscillator();
        const gn = ctx.createGain();
        osc.connect(gn); gn.connect(ctx.destination);
        osc.type = type;
        osc.frequency.setValueAtTime(f0, t);
        osc.frequency.linearRampToValueAtTime(f1, t + dur);
        const v = vol * _settings.volume;
        gn.gain.setValueAtTime(v, t);
        gn.gain.exponentialRampToValueAtTime(0.001, t + dur);
        osc.start(t); osc.stop(t + dur + 0.05);
    }

    /* ─── Normal sound library ──────────────────────────────────────────── */
    const _normal = {
        chime(ctx, t) {
            _tone(ctx, 'sine', 880, t, 0.40, 0.55);
            _tone(ctx, 'sine', 1175, t + 0.22, 0.45, 0.50);
        },
        bell(ctx, t) {
            _tone(ctx, 'sine', 1046, t, 0.60, 0.70);
            _tone(ctx, 'sine', 1568, t, 0.30, 0.22);
            _tone(ctx, 'sine', 2093, t, 0.18, 0.10);
        },
        double_tap(ctx, t) {
            _tone(ctx, 'square', 880, t, 0.09, 0.50);
            _tone(ctx, 'square', 880, t + 0.16, 0.09, 0.50);
        },
        triple_rise(ctx, t) {
            _tone(ctx, 'sine', 784, t, 0.16, 0.60);
            _tone(ctx, 'sine', 988, t + 0.18, 0.16, 0.60);
            _tone(ctx, 'sine', 1175, t + 0.36, 0.22, 0.70);
        },
        soft_ding(ctx, t) {
            _tone(ctx, 'sine', 1046, t, 0.55, 0.55);
        },
        xylophone(ctx, t) {
            [880, 1046, 1175, 1397].forEach((f, i) => _tone(ctx, 'triangle', f, t + i * 0.13, 0.18, 0.65));
        },
        warm_tick(ctx, t) {
            _tone(ctx, 'triangle', 660, t, 0.12, 0.45);
            _tone(ctx, 'sine', 990, t + 0.11, 0.20, 0.38);
        },
        water_drop(ctx, t) {
            _sweep(ctx, 'sine', 1180, 520, t, 0.28, 0.42);
            _tone(ctx, 'triangle', 760, t + 0.08, 0.16, 0.20);
        },
    };

    /* ─── Urgent sound library ──────────────────────────────────────────── */
    const _urgent = {
        alarm(ctx, t) {
            [0, 0.14, 0.28, 0.42, 0.56].forEach(d => _tone(ctx, 'square', 1400, t + d, 0.10, 0.80));
        },
        siren(ctx, t) {
            _sweep(ctx, 'sawtooth', 880, 1760, t, 0.38, 0.75);
            _sweep(ctx, 'sawtooth', 1760, 880, t + 0.42, 0.38, 0.75);
        },
        rapid_beep(ctx, t) {
            for (let i = 0; i < 7; i++) _tone(ctx, 'square', 1320, t + i * 0.08, 0.06, 0.75);
        },
        descending(ctx, t) {
            [1397, 1175, 988, 830].forEach((f, i) => _tone(ctx, 'sine', f, t + i * 0.16, 0.20, 0.70));
        },
        buzz_alert(ctx, t) {
            _sweep(ctx, 'sawtooth', 200, 230, t, 0.32, 0.85);
            _sweep(ctx, 'sawtooth', 200, 230, t + 0.38, 0.32, 0.85);
        },
        kitchen_call(ctx, t) {
            [0, 0.18, 0.46, 0.64].forEach((d, i) => _tone(ctx, 'square', i < 2 ? 1180 : 1480, t + d, 0.11, 0.82));
        },
        long_pulse(ctx, t) {
            _tone(ctx, 'sawtooth', 620, t, 0.55, 0.72);
            _tone(ctx, 'sine', 1240, t + 0.16, 0.36, 0.26);
        },
    };

    /* ─── Public: play ──────────────────────────────────────────────────── */
    /* Burst guard. A poll that returns six new tickets used to fire six copies of
       the same chime into the same AudioContext tick — they summed into a clipped
       blare that told the cook nothing about how many tickets arrived. One alert
       per burst carries the same information and stays audible over a kitchen.
       'urgent' keeps its own window so an urgent alert is never swallowed by a
       normal chime that happened to land milliseconds earlier. */
    const BURST_MS = { normal: 700, urgent: 1200, success: 700 };
    const _lastPlayedAt = { normal: 0, urgent: 0, success: 0 };

    function play(type, opts) {
        if (!_settings.enabled) return false;
        const kind = (type === 'urgent') ? 'urgent' : (type === 'success' ? 'success' : 'normal');
        const force = !!(opts && opts.force);
        const stamp = Date.now();
        if (!force && (stamp - (_lastPlayedAt[kind] || 0)) < BURST_MS[kind]) return false;
        try {
            const ctx = _getCtx();
            if (!ctx || ctx.state !== 'running') return false;
            _lastPlayedAt[kind] = stamp;
            const now = ctx.currentTime;
            if (kind === 'urgent') (_urgent[_settings.urgent] || _urgent.alarm)(ctx, now);
            else if (kind === 'success') _successCue(ctx, now);
            else (_normal[_settings.normal] || _normal.chime)(ctx, now);
            return true;
        } catch (e) { /* audio blocked */ }
        return false;
    }

    /* A completion cue that is deliberately NOT the new-work chime. On a station
       screen "a ticket arrived" and "you cleared a ticket" must not sound alike —
       staff act on the first and ignore the second. */
    function _successCue(ctx, t) {
        _tone(ctx, 'sine', 784, t, 0.14, 0.34);
        _tone(ctx, 'sine', 1175, t + 0.12, 0.22, 0.30);
    }

    function preview(type, id) {
        try {
            _markInteractionUnlocked();
            const ctx = _getCtx();
            if (!ctx || ctx.state !== 'running') return;
            const now = ctx.currentTime;
            if (type === 'urgent') (_urgent[id] || _urgent.alarm)(ctx, now);
            else (_normal[id] || _normal.chime)(ctx, now);
        } catch (e) { }
    }

    /* ─── Getters / setters ─────────────────────────────────────────────── */
    const isEnabled = () => _settings.enabled;
    const getVolume = () => Math.round(_settings.volume * 100);
    const isInteractionUnlocked = () => _interactionUnlocked;
    function setEnabled(v) {
        _settings.enabled = !!v;
        _save();
        if (!_settings.enabled && typeof _hideUnlockPrompt === 'function') _hideUnlockPrompt();
        _toggleCbs.forEach(cb => cb(_settings.enabled));
    }
    function setVolume(pct) {
        _settings.volume = Math.max(0, Math.min(1, pct / 100));
        _save();
    }
    function onToggle(fn) { _toggleCbs.push(fn); }
    function _onNormalChange(v) { _settings.normal = v; _save(); }
    function _onUrgentChange(v) { _settings.urgent = v; _save(); }

    /* ─── Sound Settings Panel CSS ──────────────────────────────────────── */
    function _injectSettingsCSS() {
        if (document.getElementById('rh-ssp-style')) return;
        const s = document.createElement('style');
        s.id = 'rh-ssp-style';
        s.textContent = `
#rh-sound-settings-panel{position:fixed;inset:0;z-index:99998;display:none;align-items:center;justify-content:center;}
#rh-sound-settings-panel.open{display:flex;}
.rh-ssp-bd{position:absolute;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);}
.rh-ssp-card{position:relative;z-index:1;background:#151820;border:1px solid #2a2f3e;border-radius:16px;width:440px;max-width:95vw;box-shadow:0 28px 72px rgba(0,0,0,.75);animation:rh-ssp-in .18s ease;}
@keyframes rh-ssp-in{from{transform:translateY(-14px) scale(.96);opacity:0}to{transform:none;opacity:1}}
.rh-ssp-head{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #23283a;color:#f0f0f8;font-size:15px;font-weight:700;font-family:'Jost',sans-serif;letter-spacing:.02em;}
.rh-ssp-head-icon{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:rgba(212,168,67,.14);border:1px solid rgba(212,168,67,.24);color:#d4a843;font-size:15px;margin-right:10px;flex-shrink:0;}
.rh-ssp-close{background:none;border:none;color:#6b7280;font-size:19px;cursor:pointer;padding:4px 8px;border-radius:7px;line-height:1;transition:color .14s,background .14s;}
.rh-ssp-close:hover{color:#f0f0f8;background:rgba(255,255,255,.08);}
.rh-ssp-body{padding:22px;}
.rh-ssp-row{margin-bottom:22px;}
.rh-ssp-row:last-child{margin-bottom:0;}
.rh-ssp-label{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#8892a4;margin-bottom:11px;font-family:'Jost',sans-serif;}
.rh-ssp-label small{font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:#4b5563;}
.rh-ssp-vol-row{display:flex;align-items:center;gap:12px;}
.rh-ssp-vi{color:#6b7280;font-size:13px;flex-shrink:0;}
#rh-vol-slider{flex:1;-webkit-appearance:none;appearance:none;height:6px;border-radius:3px;background:linear-gradient(to right,#d4a843 0%,#d4a843 var(--pct,75%),#22283a var(--pct,75%));outline:none;cursor:pointer;}
#rh-vol-slider::-webkit-slider-thumb{-webkit-appearance:none;width:20px;height:20px;border-radius:50%;background:#d4a843;border:2.5px solid #151820;cursor:pointer;box-shadow:0 2px 8px rgba(212,168,67,.45);}
#rh-vol-slider::-moz-range-thumb{width:20px;height:20px;border-radius:50%;background:#d4a843;border:2.5px solid #151820;cursor:pointer;}
.rh-ssp-pct{min-width:42px;text-align:right;color:#d4a843;font-weight:700;font-size:14px;font-family:'Jost',sans-serif;}
.rh-ssp-sel-row{display:flex;gap:8px;align-items:center;}
.rh-ssp-sel-row select{flex:1;background:#0f1118;border:1px solid #2a2f3e;border-radius:9px;color:#d8ddf0;padding:10px 13px;font-size:13px;cursor:pointer;font-family:'Jost',sans-serif;outline:none;transition:border-color .15s;}
.rh-ssp-sel-row select:focus{border-color:#d4a843;}
.rh-ssp-prev{display:inline-flex;align-items:center;gap:6px;background:rgba(212,168,67,.1);border:1px solid rgba(212,168,67,.28);color:#d4a843;padding:9px 15px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0;transition:background .14s;font-family:'Jost',sans-serif;}
.rh-ssp-prev:hover{background:rgba(212,168,67,.2);}
.rh-ssp-prev.urgent{color:#f87171;border-color:rgba(248,113,113,.28);background:rgba(248,113,113,.08);}
.rh-ssp-prev.urgent:hover{background:rgba(248,113,113,.16);}
.rh-ssp-actions{display:flex;gap:10px;}
.rh-ssp-mute-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#8892a4;padding:11px 14px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;transition:background .14s,color .14s;font-family:'Jost',sans-serif;}
.rh-ssp-mute-btn:hover{background:rgba(255,255,255,.09);color:#d8ddf0;}
.rh-ssp-mute-btn.muted{color:#f59e0b;border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.07);}
.rh-ssp-test-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.28);color:#a5b4fc;padding:11px 14px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;transition:background .14s;font-family:'Jost',sans-serif;}
.rh-ssp-test-btn:hover{background:rgba(99,102,241,.2);}
    `;
        document.head.appendChild(s);
    }

    /* ─── Settings Panel HTML ───────────────────────────────────────────── */
    let _panel = null;
    function _buildPanel() {
        const nOpts = NORMAL_SOUNDS.map(s =>
            `<option value="${s.id}"${_settings.normal === s.id ? ' selected' : ''}>${s.label}</option>`
        ).join('');
        const uOpts = URGENT_SOUNDS.map(s =>
            `<option value="${s.id}"${_settings.urgent === s.id ? ' selected' : ''}>${s.label}</option>`
        ).join('');
        const el = document.createElement('div');
        el.id = 'rh-sound-settings-panel';
        el.innerHTML = `
<div class="rh-ssp-bd"></div>
<div class="rh-ssp-card">
  <div class="rh-ssp-head">
    <span><span class="rh-ssp-head-icon"><i class="fas fa-sliders"></i></span>Notification Sounds</span>
    <button class="rh-ssp-close" onclick="RHSounds.closeSettings()" title="Close"><i class="fas fa-times"></i></button>
  </div>
  <div class="rh-ssp-body">
    <div class="rh-ssp-row">
      <label class="rh-ssp-label">Master Volume</label>
      <div class="rh-ssp-vol-row">
        <i class="fas fa-volume-off rh-ssp-vi"></i>
        <input type="range" id="rh-vol-slider" min="0" max="100" step="5" value="${getVolume()}"
          oninput="document.getElementById('rh-vol-pct').textContent=this.value+'%';RHSounds.setVolume(+this.value);document.getElementById('rh-vol-slider').style.setProperty('--pct',this.value+'%');">
        <i class="fas fa-volume-high rh-ssp-vi"></i>
        <span id="rh-vol-pct" class="rh-ssp-pct">${getVolume()}%</span>
      </div>
    </div>
    <div class="rh-ssp-row">
      <label class="rh-ssp-label">Normal Sound <small>&nbsp;— new order · FOH note · reply</small></label>
      <div class="rh-ssp-sel-row">
        <select id="rh-normal-sel" onchange="RHSounds._onNormalChange(this.value)">${nOpts}</select>
        <button class="rh-ssp-prev" onclick="RHSounds.preview('normal',document.getElementById('rh-normal-sel').value)">
          <i class="fas fa-play"></i> Preview
        </button>
      </div>
    </div>
    <div class="rh-ssp-row">
      <label class="rh-ssp-label">Urgent Alert <small>&nbsp;— urgent messages · vibrate events</small></label>
      <div class="rh-ssp-sel-row">
        <select id="rh-urgent-sel" onchange="RHSounds._onUrgentChange(this.value)">${uOpts}</select>
        <button class="rh-ssp-prev urgent" onclick="RHSounds.preview('urgent',document.getElementById('rh-urgent-sel').value)">
          <i class="fas fa-play"></i> Preview
        </button>
      </div>
    </div>
    <div class="rh-ssp-row">
      <div class="rh-ssp-actions">
        <button class="rh-ssp-mute-btn${!_settings.enabled ? ' muted' : ''}" id="rh-mute-all-btn" onclick="RHSounds._toggleMute()">
          <i class="fas ${_settings.enabled ? 'fa-volume-mute' : 'fa-volume-up'}"></i>
          <span>${_settings.enabled ? 'Mute All Sounds' : 'Unmute Sounds'}</span>
        </button>
        <button class="rh-ssp-test-btn" onclick="RHSounds._sendTest()">
          <i class="fas fa-flask"></i> Test Notification
        </button>
      </div>
    </div>
  </div>
</div>`;
        return el;
    }

    function openSettings() {
        if (!_panel || !document.body.contains(_panel)) {
            _panel = _buildPanel();
            document.body.appendChild(_panel);
            _panel.querySelector('.rh-ssp-bd').addEventListener('click', closeSettings);
            document.addEventListener('keydown', _onEscSettings);
        }
        // Sync state in case toggled externally
        const slider = _panel.querySelector('#rh-vol-slider');
        if (slider) {
            slider.value = getVolume();
            slider.style.setProperty('--pct', getVolume() + '%');
        }
        _panel.classList.add('open');
        unlockAudio();
    }
    function closeSettings() {
        if (_panel) _panel.classList.remove('open');
        document.removeEventListener('keydown', _onEscSettings);
    }
    function _onEscSettings(e) { if (e.key === 'Escape') closeSettings(); }

    function _toggleMute() {
        setEnabled(!_settings.enabled);
        const btn = document.getElementById('rh-mute-all-btn');
        if (!btn) return;
        const icon = btn.querySelector('i');
        const span = btn.querySelector('span');
        btn.classList.toggle('muted', !_settings.enabled);
        if (icon) icon.className = _settings.enabled ? 'fas fa-volume-mute' : 'fas fa-volume-up';
        if (span) span.textContent = _settings.enabled ? 'Mute All Sounds' : 'Unmute Sounds';
    }
    function _sendTest() {
        /* A test the operator asked for must never be swallowed by the burst
           guard, and must say so plainly when the browser is still holding audio. */
        _markInteractionUnlocked();
        const heard = play('normal', { force: true });
        RHNotif.show({
            title: heard ? 'Test Notification' : 'Alert shown — no sound',
            body: heard
                ? 'Sounds and alerts are working correctly.'
                : (_settings.enabled
                    ? 'Cards are working, but this browser is still blocking audio. Tap anywhere on the screen, then test again.'
                    : 'Sounds are muted. Use Unmute Sounds above to turn them back on.'),
            type: heard ? 'info' : 'urgent',
            source: 'System',
            sound: false,
        });
    }

    /* ═══════════════════════════════════════════════════════════════════
       RHNotif — Rich Notification Card Stack
       ═══════════════════════════════════════════════════════════════════ */
    let _notifContainer = null;
    let _notifIdSeq = 0;

    function _injectNotifCSS() {
        if (document.getElementById('rh-notif-style')) return;
        const s = document.createElement('style');
        s.id = 'rh-notif-style';
        s.textContent = `
:root{--rh-notif-top:16px;--rh-notif-right:16px;--rh-notif-bottom:16px;}
#rh-notif-stack{position:fixed;top:var(--rh-notif-top);right:var(--rh-notif-right);z-index:99993;display:flex;flex-direction:column;gap:10px;pointer-events:none;width:340px;max-width:calc(100vw - 32px);max-height:calc(100vh - var(--rh-notif-top) - var(--rh-notif-bottom));}
.rh-nc{position:relative;background:#1a1e2a;border:1px solid #2a2f3e;border-radius:13px;padding:0;display:flex;flex-direction:column;box-shadow:0 10px 36px rgba(0,0,0,.65);pointer-events:all;cursor:default;overflow:hidden;animation:rh-nc-in .22s cubic-bezier(.22,.61,.36,1);flex:0 0 auto;}
@keyframes rh-nc-in{from{transform:translateX(28px);opacity:0}to{transform:none;opacity:1}}
@keyframes rh-nc-out{from{transform:none;opacity:1;max-height:200px}to{transform:translateX(28px);opacity:0;max-height:0;margin-bottom:0}}
.rh-nc.removing{animation:rh-nc-out .22s ease forwards;}
.rh-nc--normal{border-left:4px solid #10b981;}
.rh-nc--urgent{border-left:4px solid #f43f5e;}
.rh-nc--info{border-left:4px solid #d4a843;}
.rh-nc--success{border-left:4px solid #22d3ee;}
.rh-nc-body{display:flex;align-items:flex-start;gap:12px;padding:13px 14px 11px;}
.rh-nc-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;margin-top:1px;}
.rh-nc--normal .rh-nc-icon{background:rgba(16,185,129,.14);color:#34d399;}
.rh-nc--urgent .rh-nc-icon{background:rgba(244,63,94,.14);color:#fb7185;animation:rh-nc-pulse 1s ease-in-out infinite;}
@keyframes rh-nc-pulse{0%,100%{box-shadow:none}50%{box-shadow:0 0 0 5px rgba(244,63,94,.18);}}
.rh-nc--info .rh-nc-icon{background:rgba(212,168,67,.14);color:#d4a843;}
.rh-nc--success .rh-nc-icon{background:rgba(34,211,238,.14);color:#22d3ee;}
.rh-nc-text{flex:1;min-width:0;}
.rh-nc-title{font-size:14.5px;font-weight:700;color:#f0f0f8;font-family:'Jost',sans-serif;line-height:1.2;margin-bottom:3px;}
.rh-nc-source{display:inline-block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:1px 7px;border-radius:5px;margin-bottom:5px;font-family:'Jost',sans-serif;}
.rh-nc--normal .rh-nc-source{background:rgba(16,185,129,.12);color:#34d399;}
.rh-nc--urgent .rh-nc-source{background:rgba(244,63,94,.12);color:#fb7185;}
.rh-nc--info .rh-nc-source{background:rgba(212,168,67,.12);color:#d4a843;}
.rh-nc--success .rh-nc-source{background:rgba(34,211,238,.12);color:#22d3ee;}
.rh-nc-body-text{font-size:13.5px;color:#aab3c0;font-family:'Jost',sans-serif;line-height:1.45;word-break:break-word;white-space:pre-line;}
.rh-nc-close{position:absolute;top:4px;right:4px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;background:none;border:none;color:#6b7280;font-size:14px;cursor:pointer;border-radius:8px;line-height:1;transition:color .12s,background .12s;}
.rh-nc-close:hover,.rh-nc-close:focus-visible{color:#f0f0f8;background:rgba(255,255,255,.1);}
.rh-nc-prog{height:3px;width:100%;background:#0f1118;}
.rh-nc-prog-bar{height:100%;background:currentColor;transition:width linear;}
.rh-nc--normal .rh-nc-prog-bar{color:#10b981;}
.rh-nc--urgent .rh-nc-prog-bar{color:#f43f5e;}
.rh-nc--info .rh-nc-prog-bar{color:#d4a843;}
.rh-nc--success .rh-nc-prog-bar{color:#22d3ee;}
/* Repeat counter — a second copy of the same alert bumps this instead of
   pushing another card onto a stack nobody can read. */
.rh-nc-dupe{display:none;margin-left:6px;padding:1px 7px;border-radius:9px;font-size:10px;font-weight:800;background:rgba(255,255,255,.12);color:#f0f0f8;vertical-align:middle;}
.rh-nc-dupe.show{display:inline-block;}
/* Overflow pill: how many alerts are queued behind the visible cards. */
#rh-notif-more{display:none;align-items:center;justify-content:space-between;gap:10px;pointer-events:all;background:#12151d;border:1px solid #2a2f3e;border-radius:11px;padding:9px 12px;color:#9aa3af;font-size:12px;font-weight:700;font-family:'Jost',sans-serif;box-shadow:0 8px 26px rgba(0,0,0,.55);flex:0 0 auto;}
#rh-notif-more.show{display:flex;}
#rh-notif-more button{min-height:32px;padding:0 12px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#d8ddf0;font-size:11px;font-weight:700;cursor:pointer;font-family:'Jost',sans-serif;}
#rh-notif-more button:hover{background:rgba(255,255,255,.12);}
/* Muted-audio prompt. A station screen that nobody has tapped cannot legally
   play audio, and silently dropping every chime is how tickets get missed. */
#rh-sound-unlock{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(var(--rh-notif-bottom) + 8px);z-index:99994;display:none;align-items:center;gap:10px;padding:11px 16px;min-height:48px;border-radius:999px;background:#b45309;border:1px solid #f59e0b;color:#fff;font-size:13px;font-weight:700;font-family:'Jost',sans-serif;cursor:pointer;box-shadow:0 10px 30px rgba(0,0,0,.5);animation:rh-nc-pulse 1.6s ease-in-out infinite;}
#rh-sound-unlock.show{display:inline-flex;}
@media(max-width:640px){
  #rh-notif-stack{top:auto;bottom:calc(var(--rh-notif-bottom) + 76px);right:8px;left:8px;width:auto;max-height:60vh;}
  #rh-sound-unlock{bottom:calc(var(--rh-notif-bottom) + 24px);}
}
@media(prefers-reduced-motion:reduce){
  .rh-nc,.rh-nc.removing,#rh-sound-unlock{animation:none;}
  .rh-nc--urgent .rh-nc-icon{animation:none;}
}
    `;
        document.head.appendChild(s);
    }

    /* Hard cap on visible cards. Beyond this the stack stops being a notification
       surface and becomes a curtain over the buttons underneath it — on a till that
       is the Pay button, on a station board it is Bump. Everything past the cap
       waits in _notifQueue and is announced by the overflow pill. */
    const MAX_VISIBLE = 3;
    const _notifQueue = [];
    let _moreEl = null;

    function _ensureContainer() {
        if (_notifContainer && document.body.contains(_notifContainer)) return _notifContainer;
        _notifContainer = document.createElement('div');
        _notifContainer.id = 'rh-notif-stack';
        document.body.appendChild(_notifContainer);
        _moreEl = null;
        return _notifContainer;
    }

    function _ensureMoreEl() {
        const container = _ensureContainer();
        if (_moreEl && container.contains(_moreEl)) return _moreEl;
        _moreEl = document.createElement('div');
        _moreEl.id = 'rh-notif-more';
        _moreEl.innerHTML = '<span id="rh-notif-more-label"></span>' +
            '<span style="display:flex;gap:6px;">' +
            '<button type="button" onclick="RHNotif.showNext()">Show next</button>' +
            '<button type="button" onclick="RHNotif.dismissAll()">Clear all</button>' +
            '</span>';
        container.appendChild(_moreEl);
        return _moreEl;
    }

    function _visibleCards() {
        if (!_notifContainer) return [];
        return Array.from(_notifContainer.querySelectorAll('.rh-nc:not(.removing)'));
    }

    function _syncOverflow() {
        const el = _ensureMoreEl();
        const n = _notifQueue.length;
        const label = el.querySelector('#rh-notif-more-label');
        if (label) label.textContent = n === 1 ? '1 more alert waiting' : n + ' more alerts waiting';
        el.classList.toggle('show', n > 0);
        // Keep the pill at the bottom of the stack as cards come and go.
        if (n > 0 && _notifContainer && el.parentNode === _notifContainer) {
            _notifContainer.appendChild(el);
        }
    }

    /* Identical alert arriving again (the same table calling twice, one ticket
       re-announced by two pollers) increments the existing card's counter and
       restarts its timer rather than duplicating it. */
    function _dedupeKey(opts) {
        return [opts.type || 'normal', opts.source || '', opts.title || '', opts.body || ''].join(String.fromCharCode(31));
    }

    function _bumpDuplicate(opts) {
        const key = _dedupeKey(opts);
        const card = _visibleCards().find(c => c.dataset.dedupe === key);
        if (!card) return false;
        const count = (parseInt(card.dataset.dupeCount, 10) || 1) + 1;
        card.dataset.dupeCount = String(count);
        const badge = card.querySelector('.rh-nc-dupe');
        if (badge) {
            badge.textContent = '×' + count;
            badge.classList.add('show');
        }
        if (typeof card._rhRestart === 'function') card._rhRestart();
        return true;
    }

    const _TYPE_ICON = {
        normal: 'fa-bell',
        urgent: 'fa-triangle-exclamation',
        info: 'fa-circle-info',
        success: 'fa-circle-check',
    };

    /**
     * Show a rich notification card.
     * @param {Object} opts
     * @param {string}  opts.title    — Bold heading
     * @param {string}  [opts.body]   — Body text
     * @param {string}  [opts.type]   — 'normal'|'urgent'|'info'|'success'
     * @param {string}  [opts.source] — Small badge label (e.g. "Kitchen")
     * @param {number}  [opts.duration] — ms to auto-dismiss (default varies by type)
     * @param {boolean} [opts.sound]  — play a sound alongside (default true)
     */
    function show(opts) {
        opts = opts || {};
        const type = opts.type || 'normal';
        const withSound = opts.sound !== false;

        if (withSound) {
            const played = play(type === 'urgent' ? 'urgent' : (type === 'success' ? 'success' : 'normal'));
            if (!played && !_interactionUnlocked) _showUnlockPrompt();
        }

        _ensureContainer();
        if (_bumpDuplicate(opts)) return 0;

        /* Over the cap the alert queues instead of stacking. Urgent jumps the
           queue so a 86'd item is never held behind four routine chits. */
        if (_visibleCards().length >= MAX_VISIBLE) {
            if (type === 'urgent') _notifQueue.unshift(opts);
            else _notifQueue.push(opts);
            if (_notifQueue.length > 40) _notifQueue.length = 40;
            _syncOverflow();
            _browserNotify(opts, type);
            return 0;
        }

        return _render(opts, type);
    }

    function _render(opts, type) {
        const container = _ensureContainer();
        const duration = opts.duration != null ? opts.duration : (type === 'urgent' ? 12000 : 6000);
        const id = ++_notifIdSeq;
        const card = document.createElement('div');
        card.className = `rh-nc rh-nc--${type}`;
        card.dataset.id = id;
        card.dataset.dedupe = _dedupeKey(opts);
        card.dataset.dupeCount = '1';

        const icon = _TYPE_ICON[type] || 'fa-bell';
        const source = opts.source ? `<span class="rh-nc-source">${_esc(opts.source)}</span><br>` : '';
        const bodyTxt = opts.body ? `<div class="rh-nc-body-text">${_esc(opts.body)}</div>` : '';

        card.innerHTML = `
<div class="rh-nc-body">
  <div class="rh-nc-icon"><i class="fas ${icon}"></i></div>
  <div class="rh-nc-text">
    ${source}
    <div class="rh-nc-title">${_esc(opts.title || '')}<span class="rh-nc-dupe"></span></div>
    ${bodyTxt}
  </div>
</div>
<button class="rh-nc-close" onclick="RHNotif._dismiss(${id})" title="Dismiss" aria-label="Dismiss notification"><i class="fas fa-xmark"></i></button>
<div class="rh-nc-prog"><div class="rh-nc-prog-bar" id="rh-nc-pb-${id}" style="width:100%;transition:width ${duration}ms linear;"></div></div>`;

        // Insert above the overflow pill so the pill always reads as the tail.
        if (_moreEl && _moreEl.parentNode === container) container.insertBefore(card, _moreEl);
        else container.appendChild(card);

        // Trigger progress bar shrink after a brief delay so transition runs
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const pb = document.getElementById(`rh-nc-pb-${id}`);
                if (pb) pb.style.width = '0%';
            });
        });

        let remainingDuration = duration;
        let timerStartedAt = Date.now();
        card._rhTimer = setTimeout(() => _dismiss(id), duration);

        function _pause() {
            if (card._rhPaused) return;
            card._rhPaused = true;
            remainingDuration = Math.max(1500, remainingDuration - (Date.now() - timerStartedAt));
            clearTimeout(card._rhTimer);
            const pb = document.getElementById(`rh-nc-pb-${id}`);
            if (pb) {
                const width = pb.getBoundingClientRect().width;
                const parentWidth = pb.parentElement ? pb.parentElement.getBoundingClientRect().width : width;
                pb.style.transition = 'none';
                if (parentWidth > 0) pb.style.width = ((width / parentWidth) * 100) + '%';
            }
        }

        function _resume() {
            if (!card._rhPaused) return;
            card._rhPaused = false;
            const pb = document.getElementById(`rh-nc-pb-${id}`);
            if (pb) {
                pb.style.transition = `width ${remainingDuration}ms linear`;
                pb.style.width = '0%';
            }
            timerStartedAt = Date.now();
            card._rhTimer = setTimeout(() => _dismiss(id), remainingDuration);
        }

        /* Restart on a duplicate hit so a repeated alert stays on screen for a
           full cycle from the latest occurrence, not from the first. */
        card._rhRestart = function () {
            clearTimeout(card._rhTimer);
            card._rhPaused = false;
            remainingDuration = duration;
            timerStartedAt = Date.now();
            const pb = document.getElementById(`rh-nc-pb-${id}`);
            if (pb) {
                pb.style.transition = 'none';
                pb.style.width = '100%';
                requestAnimationFrame(() => {
                    pb.style.transition = `width ${duration}ms linear`;
                    pb.style.width = '0%';
                });
            }
            card._rhTimer = setTimeout(() => _dismiss(id), duration);
        };

        /* pointerenter/leave covers mouse AND stylus/touch hold — the original
           mouseenter pair did nothing at all on the touchscreen tills these
           screens actually run on. */
        card.addEventListener('pointerenter', _pause);
        card.addEventListener('pointerleave', _resume);
        card.addEventListener('pointercancel', _resume);

        _browserNotify(opts, type);

        // Vibrate for urgent
        if (type === 'urgent' && _interactionUnlocked && navigator.vibrate) navigator.vibrate([250, 80, 250, 80, 500]);

        return id;
    }

    function _browserNotify(opts, type) {
        if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
        try {
            new Notification(opts.title || '', {
                body: opts.body || '',
                icon: '/images/logo.png',
                /* Tag by content, not by type: tagging every normal alert
                   'rh-station-normal' meant each new ticket silently replaced the
                   previous one in the OS tray, so a cook returning to the screen
                   saw one notification where five tickets had landed. */
                tag: 'rh-station-' + type + '-' + _dedupeKey(opts).slice(0, 80),
                silent: true,
            });
        } catch (e) { }
    }

    function _dismiss(id) {
        if (!_notifContainer) return;
        const card = _notifContainer.querySelector(`.rh-nc[data-id="${id}"]`);
        if (!card) return;
        clearTimeout(card._rhTimer);
        card.classList.add('removing');
        setTimeout(() => {
            if (card.parentNode) card.parentNode.removeChild(card);
            _drainQueue();
        }, 240);
    }

    /* Promote queued alerts as slots free up. */
    function _drainQueue() {
        while (_notifQueue.length && _visibleCards().length < MAX_VISIBLE) {
            const next = _notifQueue.shift();
            _render(next, next.type || 'normal');
        }
        _syncOverflow();
    }

    function showNext() {
        if (!_notifQueue.length) return;
        // Make room for one queued alert by retiring the oldest visible card.
        const cards = _visibleCards();
        if (cards.length >= MAX_VISIBLE && cards[0]) _dismiss(cards[0].dataset.id);
        else _drainQueue();
    }

    function dismissAll() {
        _notifQueue.length = 0;
        _visibleCards().forEach(c => _dismiss(c.dataset.id));
        _syncOverflow();
    }

    /* ─── Muted-audio prompt ────────────────────────────────────────────── */
    let _unlockEl = null;
    function _showUnlockPrompt() {
        if (!_settings.enabled) return;         // deliberately muted — respect it
        if (_interactionUnlocked) return;
        if (!_unlockEl || !document.body.contains(_unlockEl)) {
            _unlockEl = document.createElement('button');
            _unlockEl.id = 'rh-sound-unlock';
            _unlockEl.type = 'button';
            _unlockEl.innerHTML = '<i class="fas fa-volume-xmark"></i><span>Alert sounds are blocked — tap to enable</span>';
            _unlockEl.addEventListener('click', () => {
                _markInteractionUnlocked();
                _hideUnlockPrompt();
                play('normal', { force: true });
            });
            document.body.appendChild(_unlockEl);
        }
        _unlockEl.classList.add('show');
    }
    function _hideUnlockPrompt() {
        if (_unlockEl) _unlockEl.classList.remove('show');
    }
    function needsUnlock() { return _settings.enabled && !_interactionUnlocked; }

    function _esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** Request browser notification permission on first user interaction */
    function requestPermission() {
        if (typeof Notification === 'undefined') return;
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    /* ─── Init ──────────────────────────────────────────────────────────── */
    function init() {
        _load();
        _injectSettingsCSS();
        _injectNotifCSS();
        // Initialize volume slider gradient when panel is opened
        document.addEventListener('click', function _onFirstInteraction() {
            requestPermission();
            document.removeEventListener('click', _onFirstInteraction);
        }, { once: true });
    }

    /* ─── Exports ───────────────────────────────────────────────────────── */
    global.RHSounds = {
        init, play, preview, unlockAudio,
        openSettings, closeSettings,
        isEnabled, getVolume, setEnabled, setVolume, onToggle, isInteractionUnlocked, needsUnlock,
        _onNormalChange, _onUrgentChange, _toggleMute, _sendTest,
    };
    global.RHNotif = {
        show,
        showNext,
        dismissAll,
        _dismiss,
    };

})(window);
