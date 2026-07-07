<?php

/**
 * Gym Check-In — member barcode scanner (camera, USB wedge, or manual entry).
 *
 * Mobile-first standalone page in the same mould as stock-barcode-receive.php:
 * scan a member card barcode (the member_number, emailed on enrolment) to
 * check the member in; scan again to check them out. Expired / suspended
 * memberships are refused. Backed by gym_attendance (migration
 * admin/migrations/2026_07_04_gym_attendance.sql) — until that runs the page
 * shows a pending-migration notice.
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/gym-checkin-lib.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

if (!hasPermission((int)$user['id'], 'gym_checkin')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$gc_table_missing = !gym_attendance_table_exists($pdo);
$gc_snapshot = gym_checkin_snapshot($pdo);
$siteName = getSetting('site_name', 'Gym');
$gc_member_count = 0;
try {
    $gc_member_count = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active'")->fetchColumn();
} catch (Throwable $e) { /* register table optional here */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Gym Check-In — <?php echo htmlspecialchars($siteName); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- BarcodeDetector polyfill for desktop Chrome / Firefox / Safari (self-hosted) -->
<script type="module">
import { BarcodeDetectorPolyfill } from './js/barcode-detector-polyfill.js';
if (!('BarcodeDetector' in window)) { window.BarcodeDetector = BarcodeDetectorPolyfill; }
</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f4f2ef;--surface:#fffdfb;--surface2:#ede9e3;--border:#d7dde6;
  --primary:#8A775F;--success:#3f8f5a;--warn:#9a7c53;--danger:#956a5b;
  --text:#1f2a37;--muted:#5f6b7c;--radius:12px;
  --navy:#111827;--gold:#B18247;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Jost',sans-serif;font-size:15px;overscroll-behavior:none}
a{color:var(--primary);text-decoration:none}

.topbar{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--navy);border-bottom:3px solid var(--gold);position:sticky;top:0;z-index:100}
.topbar-back{width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:rgba(255,255,255,.1);color:#fff;font-size:16px;border:none;cursor:pointer}
.topbar-back:hover{background:rgba(255,255,255,.18)}
.topbar-title{flex:1;font-size:16px;font-weight:600;color:#fff}
.topbar-stats{font-size:12px;color:rgba(255,255,255,.6);text-align:right;line-height:1.4}

.camera-zone{position:relative;background:#000;width:100%;max-height:240px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.camera-zone video{width:100%;max-height:240px;object-fit:cover;display:block}
.scan-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.scan-frame{width:200px;height:100px;border:2px solid var(--gold);border-radius:8px;box-shadow:0 0 0 9999px rgba(0,0,0,.45)}
.scan-line{position:absolute;width:180px;height:2px;background:var(--gold);opacity:.8;animation:scanline 1.8s ease-in-out infinite}
@keyframes scanline{0%{top:calc(50% - 45px)}100%{top:calc(50% + 43px)}}
.scan-status{position:absolute;bottom:10px;background:rgba(0,0,0,.7);border-radius:20px;padding:4px 14px;font-size:12px;color:#fff}
.cam-error-msg{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;text-align:center;background:rgba(0,0,0,.88)}
.cam-error-msg i{font-size:28px;opacity:.5;margin-bottom:12px;color:#fff}
.cam-error-msg .cam-err-title{font-size:13px;font-weight:700;color:#fff;margin-bottom:6px}
.cam-error-msg .cam-err-hint{font-size:12px;color:rgba(255,255,255,.65);line-height:1.6}
.cam-error-msg .cam-err-retry{margin-top:14px;padding:8px 20px;background:var(--gold);border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}

.manual-row{display:flex;gap:8px;padding:12px 16px;background:var(--surface);border-bottom:1px solid var(--border);flex-wrap:wrap}
.manual-row input{flex:1;min-width:160px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);font-size:14px;font-family:inherit;outline:none;text-transform:uppercase}
.manual-row input:focus{border-color:var(--primary)}
.manual-row button.go{padding:10px 16px;background:var(--primary);border:none;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;font-size:14px;white-space:nowrap}
.cam-btn{display:flex;align-items:center;gap:8px;padding:9px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-weight:500;cursor:pointer;white-space:nowrap}
.cam-btn.active{background:#e8f5ee;border-color:var(--success);color:var(--success)}
.scanner-toggle-btn{display:flex;align-items:center;gap:8px;padding:9px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .2s}
.scanner-toggle-btn.active{background:#f0ede8;border-color:var(--primary);color:var(--primary)}
.torch-btn{display:none;align-items:center;gap:6px;padding:9px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:13px;font-weight:500;cursor:pointer;white-space:nowrap}
.torch-btn.active{background:#fef9e7;border-color:#d4a017;color:#a07800}
.torch-btn.visible{display:flex}
#scannerStrip{display:none;align-items:center;justify-content:center;gap:8px;padding:8px 16px;background:#e8f5ee;border-bottom:1px solid #b5dcc4;font-size:12px;color:var(--success)}

/* Result card */
.result-card{margin:14px 16px;border-radius:var(--radius);padding:18px;border:1px solid var(--border);background:var(--surface);display:flex;gap:14px;align-items:center;min-height:86px;transition:background .2s,border-color .2s}
.result-card .rc-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;background:var(--surface2);color:var(--muted)}
.result-card .rc-title{font-size:16px;font-weight:700;line-height:1.3}
.result-card .rc-sub{font-size:13px;color:var(--muted);margin-top:3px;line-height:1.4}
.result-card.ok{background:#e8f5ee;border-color:#3f8f5a}
.result-card.ok .rc-icon{background:#3f8f5a;color:#fff}
.result-card.out{background:#eef2fb;border-color:#5b76b7}
.result-card.out .rc-icon{background:#5b76b7;color:#fff}
.result-card.warn{background:#fdf6e7;border-color:#d4a017}
.result-card.warn .rc-icon{background:#d4a017;color:#fff}
.result-card.err{background:#f9ecec;border-color:#c0605f}
.result-card.err .rc-icon{background:#c0605f;color:#fff}

.section-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 6px;font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.gc-list{padding:0 16px 8px}
.gc-empty{text-align:center;padding:26px 20px;color:var(--muted);font-size:13px}
.gc-empty i{font-size:30px;display:block;margin-bottom:10px;opacity:.3;color:var(--primary)}
.gc-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:8px;padding:11px 14px;display:flex;align-items:center;gap:12px}
.gc-item .gi-icon{width:34px;height:34px;background:var(--surface2);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:13px;flex-shrink:0}
.gc-item .gi-name{flex:1;font-weight:600;font-size:14px;line-height:1.3}
.gc-item .gi-sub{font-size:11px;color:var(--muted);margin-top:2px;font-weight:400}
.gc-item .gi-dur{font-size:12px;color:var(--muted);white-space:nowrap}
.gc-item button.gi-out{padding:7px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap}
.gc-item button.gi-out:hover{border-color:var(--danger);color:var(--danger)}
.gi-badge{font-size:10px;font-weight:700;border-radius:9px;padding:2px 8px;white-space:nowrap}
.gi-badge.in{background:#e8f5ee;color:#2e7d52}
.gi-badge.done{background:var(--surface2);color:var(--muted)}
.notice{margin:14px 16px;padding:12px 16px;border-radius:10px;background:#f9ecec;border:1px solid #c0605f;color:#7c3a39;font-size:13px;line-height:1.5}
.foot-space{height:40px}
</style>
</head>
<body>

<div class="topbar">
    <button class="topbar-back" onclick="location.href='gym-members.php'"><i class="fas fa-arrow-left"></i></button>
    <div class="topbar-title"><i class="fas fa-barcode" style="color:var(--gold);margin-right:8px"></i>Gym Check-In</div>
    <div class="topbar-stats"><span id="statInGym"><?php echo (int)$gc_snapshot['in_gym_count']; ?></span> in gym<br><span id="statVisits"><?php echo (int)$gc_snapshot['visits_today']; ?></span> visits today · <?php echo $gc_member_count; ?> active members</div>
</div>

<?php if ($gc_table_missing): ?>
    <div class="notice"><i class="fas fa-triangle-exclamation"></i> The attendance table (gym_attendance) has not been created yet — run the migration in admin/migrations/2026_07_04_gym_attendance.sql, then reload this page. Scanning is disabled until then.</div>
<?php endif; ?>

<!-- Camera zone -->
<div class="camera-zone" id="cameraZone" style="display:none">
    <video id="camVideo" autoplay playsinline muted></video>
    <div class="scan-overlay" id="scanOverlay" style="display:none">
        <div class="scan-frame"></div>
        <div class="scan-line"></div>
        <div class="scan-status" id="scanStatus">Point camera at the member barcode</div>
    </div>
    <div class="cam-error-msg" id="camErrorMsg" style="display:none"></div>
</div>

<div id="scannerStrip"></div>

<!-- Controls -->
<div class="manual-row">
    <button class="scanner-toggle-btn" id="scannerToggleBtn" onclick="toggleScanner()" title="Enable / disable barcode scanner">
        <i class="fas fa-barcode"></i> <span id="scannerToggleLbl">Scanner: OFF</span>
    </button>
    <button class="cam-btn" id="camToggle" onclick="toggleCamera()" style="display:none">
        <i class="fas fa-camera"></i> Camera
    </button>
    <button class="torch-btn" id="torchBtn" onclick="toggleTorch()" title="Toggle flashlight">
        <i class="fas fa-bolt"></i>
    </button>
    <input type="text" id="manualInput" placeholder="Type member # (GM-XXXXXX) or scan…"
        autocomplete="off" autocorrect="off" spellcheck="false" inputmode="text">
    <button class="go" onclick="handleManualInput()"><i class="fas fa-right-to-bracket"></i> Check</button>
</div>

<!-- Result card -->
<div class="result-card" id="resultCard">
    <div class="rc-icon" id="rcIcon"><i class="fas fa-id-card"></i></div>
    <div>
        <div class="rc-title" id="rcTitle">Ready to scan</div>
        <div class="rc-sub" id="rcSub">Scan a member card, use the camera, or type the member number. Scanning again checks the member out.</div>
    </div>
</div>

<!-- Currently in the gym -->
<div class="section-head"><span><i class="fas fa-person-running" style="margin-right:6px"></i>Currently in the gym</span><span id="inGymCount"><?php echo (int)$gc_snapshot['in_gym_count']; ?></span></div>
<div class="gc-list" id="inGymList"></div>

<!-- Today's activity -->
<div class="section-head"><span><i class="fas fa-clock-rotate-left" style="margin-right:6px"></i>Today's activity</span><span id="todayCount"><?php echo (int)$gc_snapshot['visits_today']; ?></span></div>
<div class="gc-list" id="todayList"></div>
<div class="foot-space"></div>

<script>
const CSRF = <?php echo json_encode($csrf_token); ?>;
const TABLE_READY = <?php echo $gc_table_missing ? 'false' : 'true'; ?>;
const API = 'api/gym-checkin.php';
const LS_SCANNER_KEY = 'gymCheckinScanner';
const LS_CAM_KEY = 'gymCheckinCamera';
let scannerEnabled = localStorage.getItem(LS_SCANNER_KEY) !== '0'; // default ON for a check-in desk
let camStream = null, camDetecting = false;

// ── Feedback (identical mechanics to POS/receive scanners) ────────────────
let _audioCtx = null;
function _getAudioCtx(){ if(!_audioCtx){ _audioCtx = new (window.AudioContext||window.webkitAudioContext)(); } return _audioCtx; }
function _beep(freq, ms, type){ try{ const ctx=_getAudioCtx(),osc=ctx.createOscillator(),gain=ctx.createGain(); osc.connect(gain); gain.connect(ctx.destination); osc.frequency.value=freq; osc.type=type||'sine'; gain.gain.setValueAtTime(0.25,ctx.currentTime); gain.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+ms/1000); osc.start(); osc.stop(ctx.currentTime+ms/1000);}catch(e){} }
function _vib(p){ try{ navigator.vibrate && navigator.vibrate(p); }catch(e){} }
function fbIn(){ _vib(40); _beep(1400,60); }
function fbOut(){ _vib(40); _beep(950,80); }
function fbError(){ _vib([40,20,40]); _beep(360,180,'sawtooth'); }

// ── Scanner toggle ────────────────────────────────────────────────────────
function toggleScanner(){
    scannerEnabled = !scannerEnabled;
    localStorage.setItem(LS_SCANNER_KEY, scannerEnabled ? '1':'0');
    if(!scannerEnabled && camStream) stopCamera();
    updateScannerUI();
}
function updateScannerUI(){
    const btn=document.getElementById('scannerToggleBtn'), lbl=document.getElementById('scannerToggleLbl'),
          strip=document.getElementById('scannerStrip'), cam=document.getElementById('camToggle');
    if(scannerEnabled){
        btn.classList.add('active'); lbl.textContent='Scanner: ON';
        strip.style.display='flex';
        strip.innerHTML='<i class="fas fa-circle" style="font-size:8px"></i> Barcode scanner active — camera or USB wedge';
        cam.style.display='';
        if(localStorage.getItem(LS_CAM_KEY)==='1' && !camStream){ setTimeout(toggleCamera,400); }
    }else{
        btn.classList.remove('active'); lbl.textContent='Scanner: OFF';
        strip.style.display='none';
        cam.style.display='none'; cam.classList.remove('active'); cam.innerHTML='<i class="fas fa-camera"></i> Camera';
        document.getElementById('torchBtn').classList.remove('visible','active');
        document.getElementById('cameraZone').style.display='none';
        hideCameraError();
    }
}

// ── Camera (BarcodeDetector + polyfill; same flow as stock-barcode-receive) ─
function isPWA(){ return window.matchMedia('(display-mode: standalone)').matches || window.matchMedia('(display-mode: fullscreen)').matches || window.navigator.standalone===true; }
function isIOS(){ return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream; }
function showCameraError(title,hint,showRetry){
    const msg=document.getElementById('camErrorMsg'), overlay=document.getElementById('scanOverlay');
    if(overlay) overlay.style.display='none';
    msg.innerHTML='<i class="fas fa-camera-slash"></i><div class="cam-err-title">'+title+'</div><div class="cam-err-hint">'+hint+'</div>'+(showRetry?'<button class="cam-err-retry" onclick="retryCamera()">Try Again</button>':'');
    msg.style.display='flex'; msg.style.flexDirection='column'; msg.style.alignItems='center';
    document.getElementById('cameraZone').style.display='flex';
}
function hideCameraError(){
    const msg=document.getElementById('camErrorMsg'); if(msg) msg.style.display='none';
    const overlay=document.getElementById('scanOverlay'); if(overlay) overlay.style.display='';
}
function permissionDeniedMsg(){
    if(isIOS()&&isPWA()) return 'Go to iOS Settings → Safari → Camera → Allow, then tap Try Again.';
    if(isIOS()) return 'Tap AA in the address bar → Website Settings → Camera → Allow, then tap Try Again.';
    if(isPWA()) return 'Android Settings → Apps → this app → Permissions → Camera → Allow, then tap Try Again.';
    return 'Tap the lock icon in the address bar → Permissions → Camera → Allow, then tap Try Again below.';
}
function stopCamera(){
    camDetecting=false;
    if(camStream){ camStream.getTracks().forEach(t=>t.stop()); camStream=null; }
    const v=document.getElementById('camVideo'); if(v) v.srcObject=null;
}
async function toggleCamera(){
    if(!scannerEnabled) return;
    const btn=document.getElementById('camToggle'), zone=document.getElementById('cameraZone'), tBtn=document.getElementById('torchBtn');
    if(camStream){
        stopCamera(); localStorage.setItem(LS_CAM_KEY,'0');
        btn.classList.remove('active'); btn.innerHTML='<i class="fas fa-camera"></i> Camera';
        tBtn.classList.remove('visible','active'); zone.style.display='none'; hideCameraError();
        return;
    }
    for(let i=0;i<8 && !('BarcodeDetector' in window);i++){ await new Promise(r=>setTimeout(r,500)); }
    if(!('BarcodeDetector' in window)){ showCameraError('Barcode engine unavailable','Could not load the barcode engine. Refresh, or use the text input / USB scanner.',true); return; }
    if(location.protocol!=='https:' && location.hostname!=='localhost'){ showCameraError('HTTPS required','Camera access only works on secure (https://) connections.',false); return; }
    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){ showCameraError('Camera API unavailable','Use the text input or a USB/Bluetooth scanner instead.',false); return; }
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    try{
        const constraints={video:{facingMode:{ideal:'environment'},width:{ideal:640},height:{ideal:480}}};
        try{ camStream=await navigator.mediaDevices.getUserMedia(constraints); }
        catch(e){ if(e.name==='OverconstrainedError'||e.name==='ConstraintNotSatisfiedError'){ camStream=await navigator.mediaDevices.getUserMedia({video:true}); } else { throw e; } }
        const video=document.getElementById('camVideo'); video.srcObject=camStream;
        hideCameraError(); zone.style.display='flex';
        btn.classList.add('active'); btn.innerHTML='<i class="fas fa-stop"></i> Stop';
        localStorage.setItem(LS_CAM_KEY,'1');
        const track=camStream.getVideoTracks()[0];
        if(track && track.getCapabilities && track.getCapabilities().torch){ tBtn.classList.add('visible'); }
        detectLoop();
    }catch(e){
        btn.innerHTML='<i class="fas fa-camera"></i> Camera'; btn.classList.remove('active'); localStorage.setItem(LS_CAM_KEY,'0');
        if(e.name==='NotAllowedError'||e.name==='PermissionDeniedError'){ showCameraError('Camera permission denied',permissionDeniedMsg(),true); }
        else if(e.name==='NotFoundError'||e.name==='DevicesNotFoundError'){ showCameraError('No camera found','No camera detected on this device. Use the text input instead.',false); }
        else if(e.name==='NotReadableError'||e.name==='TrackStartError'){ showCameraError('Camera busy','Camera is in use by another app. Close it and tap Try Again.',true); }
        else{ showCameraError('Could not start camera', e.message||'Try the text input instead.', true); }
        document.getElementById('manualInput').focus();
    }
}
function retryCamera(){ hideCameraError(); document.getElementById('cameraZone').style.display='none'; toggleCamera(); }
async function toggleTorch(){
    if(!camStream) return;
    const track=camStream.getVideoTracks()[0]; if(!track) return;
    const caps=track.getCapabilities?track.getCapabilities():{};
    if(!caps.torch) return;
    const newTorch=!track.getSettings().torch;
    try{ await track.applyConstraints({advanced:[{torch:newTorch}]}); document.getElementById('torchBtn').classList.toggle('active',newTorch); }catch(e){}
}
async function detectLoop(){
    const detector=new BarcodeDetector({formats:['code_128','code_39','code_93','qr_code','ean_13','data_matrix']});
    const video=document.getElementById('camVideo');
    camDetecting=true;
    let lastCode='',lastCodeAt=0;
    while(camDetecting && camStream){
        await new Promise(r=>setTimeout(r,200));
        try{
            if(!video.readyState || video.readyState<2) continue;
            const codes=await detector.detect(video);
            if(!codes.length) continue;
            const code=codes[0].rawValue;
            const now=Date.now();
            if(code===lastCode && now-lastCodeAt<3000) continue; // 3 s cooldown — avoids double check-in/out flip
            lastCode=code; lastCodeAt=now;
            const s=document.getElementById('scanStatus'); if(s) s.textContent='✓ '+code;
            processScan(code,'barcode');
        }catch(e){}
    }
}

// ── Keyboard wedge (USB/Bluetooth) ────────────────────────────────────────
let _kwBuf='',_kwLast=0;
document.addEventListener('keydown', e=>{
    if(!scannerEnabled) return;
    if(e.ctrlKey||e.altKey||e.metaKey) return;
    const tag=(document.activeElement||{}).tagName||'';
    if(['INPUT','TEXTAREA','SELECT'].includes(tag)) return;
    const now=Date.now();
    if(e.key==='Enter'){ if(_kwBuf.length>=3 && now-_kwLast<300) processScan(_kwBuf,'barcode'); _kwBuf=''; return; }
    if(e.key.length===1){ if(now-_kwLast>600) _kwBuf=''; _kwBuf+=e.key; _kwLast=now; }
});
document.getElementById('manualInput').addEventListener('keydown', e=>{
    if(e.key==='Enter'){ e.preventDefault(); handleManualInput(); }
});
function handleManualInput(){
    const inp=document.getElementById('manualInput');
    const v=inp.value.trim();
    if(v){ processScan(v,'manual'); inp.value=''; }
}

// ── Scan processing — one request in flight, latest queued ────────────────
let _inFlight=false,_queued=null;
function processScan(code,method){
    if(!TABLE_READY){ setResult('err','fa-triangle-exclamation','Attendance table missing','Run the gym_attendance migration first.'); fbError(); return; }
    if(_inFlight){ _queued={code:code,method:method}; return; }
    _inFlight=true;
    const fd=new FormData();
    fd.append('csrf_token',CSRF); fd.append('action','scan'); fd.append('code',code); fd.append('method',method||'barcode');
    fetch(API,{method:'POST',body:fd,credentials:'same-origin'})
        .then(r=>r.json())
        .then(d=>{ handleScanResult(d); })
        .catch(()=>{ setResult('err','fa-wifi','Network error','Could not reach the server — try again.'); fbError(); })
        .finally(()=>{ _inFlight=false; if(_queued){ const q=_queued; _queued=null; processScan(q.code,q.method); } });
}
function handleScanResult(d){
    const m=d.member||{};
    if(d.outcome==='checked_in'){
        const cls=d.expiring_soon?'warn':'ok';
        const sub=(m.membership_type?m.membership_type+' · ':'')+(m.member_number||'')+(d.expiring_soon?' — membership expires soon!':'');
        setResult(cls,'fa-person-walking-arrow-right', d.message||'Checked in', sub);
        fbIn();
    }else if(d.outcome==='checked_out'){
        setResult('out','fa-person-walking-arrow-loop-left', d.message||'Checked out', (m.member_number||''));
        fbOut();
    }else if(d.outcome==='blocked'){
        setResult('err','fa-ban', d.message||'Membership not valid', 'Renew the membership on the Gym Members page before check-in.');
        fbError();
    }else if(d.outcome==='not_found'){
        setResult('err','fa-circle-question', d.message||'Member not found', 'Check the number or enrol the member first.');
        fbError();
    }else{
        setResult('err','fa-triangle-exclamation', d.message||'Scan failed', '');
        fbError();
    }
    if(d.snapshot) renderSnapshot(d.snapshot);
}
function setResult(cls,icon,title,sub){
    const card=document.getElementById('resultCard');
    card.className='result-card '+(cls||'');
    document.getElementById('rcIcon').innerHTML='<i class="fas '+icon+'"></i>';
    document.getElementById('rcTitle').textContent=title;
    document.getElementById('rcSub').textContent=sub||'';
}

// ── Snapshot rendering ────────────────────────────────────────────────────
function esc(s){ const d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
function fmtTime(dt){ if(!dt) return ''; const d=new Date(String(dt).replace(' ','T')); return isNaN(d)?String(dt):d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}); }
function fmtDur(mins){ if(mins==null) return ''; mins=parseInt(mins,10); return mins>=60?Math.floor(mins/60)+'h '+(mins%60)+'m':mins+'m'; }
function renderSnapshot(s){
    document.getElementById('statInGym').textContent=s.in_gym_count;
    document.getElementById('statVisits').textContent=s.visits_today;
    document.getElementById('inGymCount').textContent=s.in_gym_count;
    document.getElementById('todayCount').textContent=s.visits_today;
    const inList=document.getElementById('inGymList');
    if(!s.in_gym.length){
        inList.innerHTML='<div class="gc-empty"><i class="fas fa-door-open"></i>Nobody is checked in right now.</div>';
    }else{
        inList.innerHTML=s.in_gym.map(r=>
            '<div class="gc-item">'+
            '<div class="gi-icon"><i class="fas fa-user"></i></div>'+
            '<div class="gi-name">'+esc(r.full_name||r.member_number)+'<div class="gi-sub">'+esc(r.member_number)+' · in since '+fmtTime(r.checked_in_at)+'</div></div>'+
            '<div class="gi-dur">'+fmtDur(r.minutes_in)+'</div>'+
            '<button class="gi-out" onclick="forceCheckout('+parseInt(r.id,10)+')"><i class="fas fa-right-from-bracket"></i> Out</button>'+
            '</div>').join('');
    }
    const tList=document.getElementById('todayList');
    if(!s.today.length){
        tList.innerHTML='<div class="gc-empty"><i class="fas fa-clock"></i>No visits recorded today yet.</div>';
    }else{
        tList.innerHTML=s.today.map(r=>
            '<div class="gc-item">'+
            '<div class="gi-icon"><i class="fas '+(r.checked_out_at?'fa-check':'fa-person-running')+'"></i></div>'+
            '<div class="gi-name">'+esc(r.full_name||r.member_number)+'<div class="gi-sub">'+esc(r.member_number)+' · '+fmtTime(r.checked_in_at)+(r.checked_out_at?' → '+fmtTime(r.checked_out_at):'')+'</div></div>'+
            (r.checked_out_at?'<span class="gi-badge done">'+fmtDur(r.minutes)+'</span>':'<span class="gi-badge in">IN GYM</span>')+
            '</div>').join('');
    }
}
function forceCheckout(id){
    const fd=new FormData();
    fd.append('csrf_token',CSRF); fd.append('action','checkout'); fd.append('attendance_id',id);
    fetch(API,{method:'POST',body:fd,credentials:'same-origin'})
        .then(r=>r.json())
        .then(d=>{ if(d.snapshot) renderSnapshot(d.snapshot); if(d.success){ fbOut(); } });
}
function refreshSnapshot(){
    if(!TABLE_READY) return;
    const fd=new FormData();
    fd.append('csrf_token',CSRF); fd.append('action','snapshot');
    fetch(API,{method:'POST',body:fd,credentials:'same-origin'})
        .then(r=>r.json())
        .then(d=>{ if(d.snapshot) renderSnapshot(d.snapshot); })
        .catch(()=>{});
}

updateScannerUI();
renderSnapshot(<?php echo json_encode($gc_snapshot); ?>);
setInterval(refreshSnapshot, 60000); // keep durations fresh on a wall-mounted device
</script>
</body>
</html>
