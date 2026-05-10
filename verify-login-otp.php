<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/email.php';

// Must have a pending login session
if (empty($_SESSION['login_pending'])) {
    header('Location: login.php');
    exit();
}

$pending = $_SESSION['login_pending'];
$error   = '';
$success = '';
$devOtp  = $_SESSION['dev_otp'] ?? null;
unset($_SESSION['dev_otp']); // show once

// ─── Verify OTP ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    $otp = trim($_POST['otp'] ?? '');

    $emailOk = verifyOTP($conn, $pending['email'], 'email', 'login', $otp);
    $smsOk   = !empty($pending['phone'])
               && verifyOTP($conn, $pending['phone'], 'sms', 'login', $otp);

    if ($emailOk || $smsOk) {
        // Complete login
        $_SESSION['user_id']   = $pending['id'];
        $_SESSION['user_name'] = $pending['name'];
        $_SESSION['role']      = $pending['role'];
        unset($_SESSION['login_pending'], $_SESSION['login_sms']);

        header('Location: ' . ($pending['role'] === 'admin' ? 'admin/dashboard.php' : 'dashboard.php'));
        exit();
    } else {
        $error = 'Invalid or expired OTP. Please try again.';
    }
}

// ─── Resend OTP ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $otp = generateOTP();
    saveOTP($conn, $pending['email'], 'email', 'login', $otp, 5);
    $emailSent = sendLoginOTPEmail($pending['email'], $pending['name'], $otp);

    if (!empty($pending['phone'])) {
        $smsOtp = generateOTP();
        saveOTP($conn, $pending['phone'], 'sms', 'login', $smsOtp, 5);
        sendSMS($pending['phone'], "AutoCare Pro: Your login OTP is {$smsOtp}. Valid 5 mins.");
    }

    $success = 'New OTP sent!';

    // Dev fallback: show OTP on screen if email not configured
    if (!$emailSent) {
        $_SESSION['dev_otp'] = $otp;
        $devOtp = $otp; // show immediately on this page load
    }
}

// Mask email for display
function maskEmail($email) {
    [$local, $domain] = explode('@', $email);
    return substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 2)) . '@' . $domain;
}
function maskPhone($phone) {
    return substr($phone, 0, 4) . str_repeat('*', max(strlen($phone) - 7, 2)) . substr($phone, -3);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login OTP - AutoCare Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root { --primary:#e63946; --dark:#1d3557; }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{background:linear-gradient(135deg,#0f172a,#1e293b);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;font-family:'DM Sans',sans-serif;}
        .card{background:#fff;border-radius:24px;padding:48px 40px;max-width:460px;width:100%;box-shadow:0 25px 50px -12px rgba(0,0,0,.5);text-align:center;}
        .icon-wrap{width:72px;height:72px;background:rgba(230,57,70,.1);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
        .auth-title{font-family:'Bebas Neue';font-size:2.2rem;color:var(--dark);margin-bottom:8px;}
        .otp-inputs{display:flex;gap:10px;justify-content:center;margin:28px 0;}
        .otp-inputs input{width:52px;height:60px;text-align:center;font-size:24px;font-weight:700;border:2px solid #e2e8f0;border-radius:12px;font-family:inherit;transition:all .3s;}
        .otp-inputs input:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 4px rgba(230,57,70,.1);}
        .btn-primary{background:var(--primary);color:#fff;border:none;width:100%;padding:15px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;transition:all .3s;}
        .btn-primary:hover{background:#c12a36;transform:translateY(-2px);}
        .btn-outline{background:none;border:2px solid #e2e8f0;color:#475569;width:100%;padding:13px;border-radius:12px;font-weight:600;font-size:14px;cursor:pointer;transition:all .3s;margin-top:10px;font-family:inherit;}
        .btn-outline:hover{border-color:var(--primary);color:var(--primary);}
        .alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:14px;display:flex;align-items:center;gap:10px;text-align:left;}
        .alert-error{background:#fff1f2;color:#be123c;border-left:4px solid #e11d48;}
        .alert-success{background:#f0fdf4;color:#166534;border-left:4px solid #22c55e;}
        .timer{font-size:13px;color:#94a3b8;margin-top:14px;}
        .timer span{color:var(--primary);font-weight:700;}
    </style>
</head>
<body>
<div class="card">
    <a href="index.php" style="text-decoration:none;display:block;margin-bottom:24px;">
        <span style="font-family:'Bebas Neue';font-size:1.7rem;color:var(--dark);">⚙ AutoCare<span style="color:var(--primary)">Pro</span></span>
    </a>

    <div class="icon-wrap">
        <i class="fas fa-shield-alt" style="font-size:28px;color:var(--primary);"></i>
    </div>

    <h1 class="auth-title">Two-Factor Verification</h1>
    <p style="color:#64748b;font-size:14px;line-height:1.6;margin-bottom:4px;">
        We sent a 6-digit OTP to<br>
        <strong><?= htmlspecialchars(maskEmail($pending['email'])) ?></strong>
        <?php if (!empty($pending['phone'])): ?>
        and <strong><?= htmlspecialchars(maskPhone($pending['phone'])) ?></strong>
        <?php endif; ?>
    </p>
    <p style="color:#94a3b8;font-size:12px;">Enter either the email or SMS code.</p>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-top:16px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success" style="margin-top:16px;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($devOtp): ?>
    <div style="background:#fef9c3;border:2px dashed #f59e0b;border-radius:12px;padding:16px 20px;margin:16px 0;text-align:center;">
        <div style="font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
            ⚠️ Dev Mode — Email not configured
        </div>
        <div style="font-size:36px;font-weight:900;color:#b45309;letter-spacing:10px;font-family:monospace;">
            <?= htmlspecialchars($devOtp) ?>
        </div>
        <div style="font-size:11px;color:#92400e;margin-top:6px;">
            Your OTP (configure Gmail in includes/config.php to send real emails)
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="otpForm">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="otp" id="otpHidden">
        <div class="otp-inputs">
            <?php for($i=0;$i<6;$i++): ?>
            <input type="text" maxlength="1" pattern="\d" inputmode="numeric" autocomplete="one-time-code">
            <?php endfor; ?>
        </div>
        <button type="submit" class="btn-primary">
            <i class="fas fa-check" style="margin-right:8px;"></i> Verify &amp; Login
        </button>
    </form>

    <form method="POST" style="margin-top:10px;">
        <input type="hidden" name="action" value="resend">
        <button type="submit" class="btn-outline">
            <i class="fas fa-redo" style="margin-right:8px;"></i> Resend OTP
        </button>
    </form>

    <div class="timer">OTP expires in <span id="countdown">5:00</span></div>

    <div style="margin-top:20px;">
        <a href="login.php" style="font-size:13px;color:#94a3b8;text-decoration:none;">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
    </div>
</div>

<script>
// OTP input auto-advance
const inputs = document.querySelectorAll('.otp-inputs input');
inputs.forEach((inp, i) => {
    inp.addEventListener('input', () => {
        inp.value = inp.value.replace(/\D/g, '');
        if (inp.value && i < inputs.length - 1) inputs[i + 1].focus();
    });
    inp.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !inp.value && i > 0) inputs[i - 1].focus();
    });
    inp.addEventListener('paste', e => {
        e.preventDefault();
        const digits = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
        digits.split('').forEach((d, j) => { if (inputs[j]) inputs[j].value = d; });
        if (inputs[digits.length - 1]) inputs[digits.length - 1].focus();
    });
});

document.getElementById('otpForm').addEventListener('submit', () => {
    document.getElementById('otpHidden').value = Array.from(inputs).map(i => i.value).join('');
});

// Countdown timer (5 minutes)
let secs = 300;
const el = document.getElementById('countdown');
const timer = setInterval(() => {
    secs--;
    if (secs <= 0) { clearInterval(timer); el.textContent = 'Expired'; el.style.color = '#ef4444'; return; }
    const m = Math.floor(secs / 60), s = secs % 60;
    el.textContent = m + ':' + String(s).padStart(2, '0');
}, 1000);

// Auto-focus first input
if (inputs[0]) inputs[0].focus();
</script>
</body>
</html>
