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
    function play(type) {
        if (!_settings.enabled) return;
        try {
            const ctx = _getCtx();
            if (!ctx || ctx.state !== 'running') return;
            const now = ctx.currentTime;
            if (type === 'urgent') (_urgent[_settings.urgent] || _urgent.alarm)(ctx, now);
            else (_normal[_settings.normal] || _normal.chime)(ctx, now);
        } catch (e) { /* audio blocked */ }
    }

    function preview(type, id) {
        try {
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
        play('normal');
        RHNotif.show({ title: 'Test Notification', body: 'Sounds and alerts are working correctly.', type: 'info', source: 'System', sound: false });
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
#rh-notif-stack{position:fixed;top:16px;right:16px;z-index:99990;display:flex;flex-direction:column;gap:10px;pointer-events:none;width:340px;max-width:calc(100vw - 32px);}
.rh-nc{position:relative;background:#1a1e2a;border:1px solid #2a2f3e;border-radius:13px;padding:0;display:flex;flex-direction:column;box-shadow:0 10px 36px rgba(0,0,0,.65);pointer-events:all;cursor:default;overflow:hidden;animation:rh-nc-in .22s cubic-bezier(.22,.61,.36,1);}
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
.rh-nc-title{font-size:13px;font-weight:700;color:#f0f0f8;font-family:'Jost',sans-serif;line-height:1.2;margin-bottom:3px;}
.rh-nc-source{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:1px 7px;border-radius:5px;margin-bottom:5px;font-family:'Jost',sans-serif;}
.rh-nc--normal .rh-nc-source{background:rgba(16,185,129,.12);color:#34d399;}
.rh-nc--urgent .rh-nc-source{background:rgba(244,63,94,.12);color:#fb7185;}
.rh-nc--info .rh-nc-source{background:rgba(212,168,67,.12);color:#d4a843;}
.rh-nc--success .rh-nc-source{background:rgba(34,211,238,.12);color:#22d3ee;}
.rh-nc-body-text{font-size:12.5px;color:#9aa3af;font-family:'Jost',sans-serif;line-height:1.45;word-break:break-word;white-space:pre-line;}
.rh-nc-close{position:absolute;top:9px;right:10px;background:none;border:none;color:#4b5563;font-size:14px;cursor:pointer;padding:3px 5px;border-radius:5px;line-height:1;transition:color .12s,background .12s;}
.rh-nc-close:hover{color:#f0f0f8;background:rgba(255,255,255,.08);}
.rh-nc-prog{height:3px;width:100%;background:#0f1118;}
.rh-nc-prog-bar{height:100%;background:currentColor;transition:width linear;}
.rh-nc--normal .rh-nc-prog-bar{color:#10b981;}
.rh-nc--urgent .rh-nc-prog-bar{color:#f43f5e;}
.rh-nc--info .rh-nc-prog-bar{color:#d4a843;}
.rh-nc--success .rh-nc-prog-bar{color:#22d3ee;}
@media(max-width:480px){#rh-notif-stack{top:auto;bottom:80px;right:8px;left:8px;width:auto;}}
    `;
        document.head.appendChild(s);
    }

    function _ensureContainer() {
        if (_notifContainer && document.body.contains(_notifContainer)) return _notifContainer;
        _notifContainer = document.createElement('div');
        _notifContainer.id = 'rh-notif-stack';
        document.body.appendChild(_notifContainer);
        return _notifContainer;
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
        const type = opts.type || 'normal';
        const duration = opts.duration != null ? opts.duration : (type === 'urgent' ? 10000 : 5000);
        const withSound = opts.sound !== false;

        if (withSound) play(type === 'urgent' ? 'urgent' : 'normal');

        const container = _ensureContainer();
        const id = ++_notifIdSeq;
        const card = document.createElement('div');
        card.className = `rh-nc rh-nc--${type}`;
        card.dataset.id = id;

        const icon = _TYPE_ICON[type] || 'fa-bell';
        const source = opts.source ? `<span class="rh-nc-source">${_esc(opts.source)}</span><br>` : '';
        const bodyTxt = opts.body ? `<div class="rh-nc-body-text">${_esc(opts.body)}</div>` : '';

        card.innerHTML = `
<div class="rh-nc-body">
  <div class="rh-nc-icon"><i class="fas ${icon}"></i></div>
  <div class="rh-nc-text">
    ${source}
    <div class="rh-nc-title">${_esc(opts.title || '')}</div>
    ${bodyTxt}
  </div>
</div>
<button class="rh-nc-close" onclick="RHNotif._dismiss(${id})" title="Dismiss"><i class="fas fa-xmark"></i></button>
<div class="rh-nc-prog"><div class="rh-nc-prog-bar" id="rh-nc-pb-${id}" style="width:100%;transition:width ${duration}ms linear;"></div></div>`;

        container.appendChild(card);

        // Trigger progress bar shrink after a brief delay so transition runs
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const pb = document.getElementById(`rh-nc-pb-${id}`);
                if (pb) pb.style.width = '0%';
            });
        });

        let remainingDuration = duration;
        let timerStartedAt = Date.now();
        const timer = setTimeout(() => _dismiss(id), duration);
        card._rhTimer = timer;

        // Pause on hover
        card.addEventListener('mouseenter', () => {
            remainingDuration = Math.max(1000, remainingDuration - (Date.now() - timerStartedAt));
            clearTimeout(card._rhTimer);
            const pb = document.getElementById(`rh-nc-pb-${id}`);
            if (pb) {
                const width = pb.getBoundingClientRect().width;
                const parentWidth = pb.parentElement ? pb.parentElement.getBoundingClientRect().width : width;
                pb.style.transition = 'none';
                if (parentWidth > 0) pb.style.width = ((width / parentWidth) * 100) + '%';
            }
        });
        card.addEventListener('mouseleave', () => {
            const pb = document.getElementById(`rh-nc-pb-${id}`);
            if (pb) {
                pb.style.transition = `width ${remainingDuration}ms linear`;
                pb.style.width = '0%';
            }
            timerStartedAt = Date.now();
            card._rhTimer = setTimeout(() => _dismiss(id), remainingDuration);
        });

        // Also show browser notification if granted
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try {
                new Notification(opts.title || '', {
                    body: opts.body || '',
                    icon: '/images/logo.png',
                    tag: 'rh-station-' + type,
                    silent: true,
                });
            } catch (e) { }
        }

        // Vibrate for urgent
        if (type === 'urgent' && _interactionUnlocked && navigator.vibrate) navigator.vibrate([250, 80, 250, 80, 500]);

        return id;
    }

    function _dismiss(id) {
        if (!_notifContainer) return;
        const card = _notifContainer.querySelector(`[data-id="${id}"]`);
        if (!card) return;
        clearTimeout(card._rhTimer);
        card.classList.add('removing');
        setTimeout(() => { if (card.parentNode) card.parentNode.removeChild(card); }, 240);
    }

    function _esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
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
        isEnabled, getVolume, setEnabled, setVolume, onToggle, isInteractionUnlocked,
        _onNormalChange, _onUrgentChange, _toggleMute, _sendTest,
    };
    global.RHNotif = {
        show,
        _dismiss,
    };

})(window);
