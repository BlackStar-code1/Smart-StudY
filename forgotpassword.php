<?php
session_start();
require_once 'config/db_config.php';
require_once 'config/email.php';

// Handle restart — clear session before any output
if (isset($_GET['restart'])) {
    unset($_SESSION['reset_step'], $_SESSION['reset_otp'], $_SESSION['reset_expires'], $_SESSION['reset_email'], $_SESSION['reset_verified']);
    header('Location: forgotpassword.php');
    exit();
}

$message = '';
$error   = '';
$step    = $_SESSION['reset_step'] ?? 1;

// ── STEP 1: Submit email, send OTP ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_otp') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, username, status FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['status'] !== 'active') {
            $error = 'Your account is inactive. Please contact the administrator.';
        } else {
            $otp = generateOTP();
            $name = $user['full_name'] ?: $user['username'];

            if (sendOTPEmail($email, $name, $otp)) {
                $_SESSION['reset_email']   = $email;
                $_SESSION['reset_otp']     = $otp;
                $_SESSION['reset_expires'] = time() + (15 * 60); // 15 minutes
                $_SESSION['reset_step']    = 2;
                $step    = 2;
                $message = 'OTP sent to your email. Check your inbox.';
            } else {
                $error = isEmailConfigured()
                    ? 'Failed to send OTP. Please try again later.'
                    : 'Email not configured. <a href="setup_email.php" style="color:#e74c3c;text-decoration:underline;">Configure SMTP</a> first.';
            }
        }
    }
}

// ── STEP 2: Verify OTP ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    $otp_input = trim($_POST['otp_code'] ?? '');
    $step = 2;

    if (empty($otp_input)) {
        $error = 'Please enter the OTP code.';
    } elseif (time() > ($_SESSION['reset_expires'] ?? 0)) {
        $error = 'OTP has expired. Please request a new one.';
        unset($_SESSION['reset_step'], $_SESSION['reset_otp'], $_SESSION['reset_expires'], $_SESSION['reset_email']);
        $step = 1;
    } elseif ($otp_input !== ($_SESSION['reset_otp'] ?? '')) {
        $error = 'Invalid OTP. Please try again.';
    } else {
        $_SESSION['reset_step']     = 3;
        $_SESSION['reset_verified'] = true;
        $step    = 3;
        $message = 'Email verified. Set your new password below.';
    }
}

// ── STEP 3: Reset password ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $step = 3;
    $new_password     = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $email            = $_SESSION['reset_email'] ?? '';

    if (!($_SESSION['reset_verified'] ?? false) || empty($email)) {
        $error = 'Session expired. Please start over.';
        unset($_SESSION['reset_step'], $_SESSION['reset_otp'], $_SESSION['reset_expires'], $_SESSION['reset_email'], $_SESSION['reset_verified']);
        $step = 1;
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt   = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->bind_param('ss', $hashed, $email);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            unset($_SESSION['reset_step'], $_SESSION['reset_otp'], $_SESSION['reset_expires'], $_SESSION['reset_email'], $_SESSION['reset_verified']);
            $_SESSION['reset_success'] = 'Password reset successfully! You can now login with your new password.';
            header('Location: login.php');
            exit();
        } else {
            $error = 'Failed to update password. Please try again.';
        }
    }
}

// ── Resend OTP ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_otp') {
    $email = $_SESSION['reset_email'] ?? '';
    $step  = 2;

    if ($email) {
        $stmt = $conn->prepare("SELECT full_name, username FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $name = ($user['full_name'] ?? '') ?: ($user['username'] ?? 'User');

        $otp = generateOTP();
        if (sendOTPEmail($email, $name, $otp)) {
            $_SESSION['reset_otp']     = $otp;
            $_SESSION['reset_expires'] = time() + (15 * 60);
            $message = 'A new OTP has been sent to your email.';
        } else {
            $error = 'Failed to resend OTP. Please try again.';
        }
    } else {
        $error = 'Session expired. Please start over.';
        $step  = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Smart Study Planner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #3498db 0%, #2ecc71 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 420px;
            width: 100%;
            padding: 2rem;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .logo {
            text-align: center;
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
        }

        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 0.4rem;
            font-size: 1.6rem;
        }

        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Steps indicator */
        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.8rem;
            gap: 0;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.3rem;
        }

        .step-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 2px solid #ecf0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 700;
            color: #bdc3c7;
            background: white;
            transition: all 0.3s;
        }

        .step-circle.active {
            border-color: #3498db;
            color: #3498db;
            background: #ebf5fb;
        }

        .step-circle.done {
            border-color: #2ecc71;
            background: #2ecc71;
            color: white;
        }

        .step-label {
            font-size: 0.7rem;
            color: #bdc3c7;
            font-weight: 500;
            white-space: nowrap;
        }

        .step-label.active { color: #3498db; }
        .step-label.done   { color: #2ecc71; }

        .step-line {
            width: 50px;
            height: 2px;
            background: #ecf0f1;
            margin: 0 0.3rem;
            margin-bottom: 1.4rem;
            transition: background 0.3s;
        }

        .step-line.done { background: #2ecc71; }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-weight: 500;
            font-size: 0.95rem;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #ecf0f1;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
            font-family: inherit;
        }

        input:focus {
            outline: none;
            border-color: #3498db;
        }

        .btn {
            width: 100%;
            padding: 0.9rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            margin-bottom: 0.8rem;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background-color: #ecf0f1;
            color: #2c3e50;
            margin-bottom: 0.8rem;
        }

        .btn-secondary:hover { background-color: #d5dbdb; }

        .message {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }

        .success {
            background-color: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        .error {
            background-color: #fadbd8;
            color: #c0392b;
            border-left: 4px solid #c0392b;
        }

        .otp-instruction {
            background: #e3f2fd;
            border-left: 4px solid #3498db;
            color: #1565c0;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .otp-boxes {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .otp-boxes input {
            width: 48px;
            height: 54px;
            text-align: center;
            font-size: 1.4rem;
            font-weight: 700;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            padding: 0;
        }

        .otp-boxes input:focus { border-color: #3498db; }

        .password-hint {
            font-size: 0.82rem;
            color: #7f8c8d;
            margin-top: 0.4rem;
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-pw {
            position: absolute;
            right: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #95a5a6;
            font-size: 1rem;
            padding: 0;
        }

        .back-link {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.9rem;
        }

        .back-link a {
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
        }

        .back-link a:hover { text-decoration: underline; }

        .resend-row {
            text-align: center;
            font-size: 0.88rem;
            color: #7f8c8d;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="logo">📚</div>
    <h1>Forgot Password</h1>
    <p class="subtitle">
        <?php if ($step === 1): ?>Enter your email to receive a reset code.
        <?php elseif ($step === 2): ?>Enter the 6-digit code sent to your email.
        <?php else: ?>Create a new password for your account.
        <?php endif; ?>
    </p>

    <!-- Steps -->
    <div class="steps">
        <?php
        $labels = ['Email', 'Verify', 'Reset'];
        for ($i = 1; $i <= 3; $i++):
            $cls = $step > $i ? 'done' : ($step === $i ? 'active' : '');
        ?>
        <div class="step">
            <div class="step-circle <?= $cls ?>">
                <?= $step > $i ? '✓' : $i ?>
            </div>
            <span class="step-label <?= $cls ?>"><?= $labels[$i - 1] ?></span>
        </div>
        <?php if ($i < 3): ?>
        <div class="step-line <?= $step > $i ? 'done' : '' ?>"></div>
        <?php endif; endfor; ?>
    </div>

    <?php if ($message): ?>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <!-- ── Step 1: Enter Email ── -->
    <form method="POST">
        <input type="hidden" name="action" value="send_otp">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required
                   placeholder="your@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary">Send Reset Code</button>
    </form>

    <?php elseif ($step === 2): ?>
    <!-- ── Step 2: Verify OTP ── -->
    <div class="otp-instruction">
        📧 A 6-digit code was sent to <strong><?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?></strong>
        <br>Enter it below. Code expires in 15 minutes.
    </div>

    <form method="POST" id="otpForm">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="otp_code" id="otpHidden">
        <div class="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" inputmode="numeric" pattern="\d" autocomplete="off">
            <?php endfor; ?>
        </div>
        <button type="submit" class="btn btn-primary">Verify Code</button>
    </form>

    <form method="POST">
        <input type="hidden" name="action" value="resend_otp">
        <button type="submit" class="btn btn-secondary">Resend Code</button>
    </form>

    <div class="resend-row">
        Wrong email? <a href="forgotpassword.php?restart=1" style="color:#3498db;font-weight:600;">Start over</a>
    </div>

    <?php elseif ($step === 3): ?>
    <!-- ── Step 3: New Password ── -->
    <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <div class="form-group">
            <label for="password">New Password</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" required
                       placeholder="At least 6 characters">
                <button type="button" class="toggle-pw" onclick="togglePw('password', this)">👁</button>
            </div>
            <div class="password-hint">At least 6 characters</div>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <div class="password-wrapper">
                <input type="password" id="confirm_password" name="confirm_password" required
                       placeholder="Repeat your new password">
                <button type="button" class="toggle-pw" onclick="togglePw('confirm_password', this)">👁</button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Reset Password</button>
    </form>
    <?php endif; ?>

    <div class="back-link">
        <a href="login.php">← Back to Login</a>
    </div>
</div>

<script>
// OTP box auto-advance
const boxes = document.querySelectorAll('.otp-boxes input');
boxes.forEach((box, i) => {
    box.addEventListener('input', () => {
        box.value = box.value.replace(/\D/g, '');
        if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
    });
    box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
    });
    box.addEventListener('paste', e => {
        e.preventDefault();
        const digits = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
        digits.split('').forEach((d, j) => { if (boxes[j]) boxes[j].value = d; });
        const last = boxes[Math.min(digits.length, 5)];
        if (last) last.focus();
    });
});

const otpForm = document.getElementById('otpForm');
if (otpForm) {
    otpForm.addEventListener('submit', () => {
        document.getElementById('otpHidden').value = Array.from(boxes).map(b => b.value).join('');
    });
    if (boxes[0]) boxes[0].focus();
}

// Toggle password visibility
function togglePw(id, btn) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}
</script>
</body>
</html>
