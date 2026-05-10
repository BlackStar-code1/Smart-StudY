<?php
require_once 'includes/config.php';

$emailResult = null;
$smsResult   = null;
$emailError  = '';
$smsError    = '';

// ── Test Email ────────────────────────────────────────────────────────────────
if (isset($_POST['test_email'])) {
    $testTo = trim($_POST['test_email_addr'] ?? '');
    if ($testTo) {
        require_once 'vendor/autoload.php';
        require_once 'includes/email.php';

        $otp = '123456';
        $sent = sendLoginOTPEmail($testTo, 'Test User', $otp);
        $emailResult = $sent ? 'SUCCESS' : 'FAILED';
        if (!$sent) {
            // Grab last error from log
            $logFile = ini_get('error_log');
            $emailError = 'Check your PHP error log for details.';
            if ($logFile && file_exists($logFile)) {
                $lines = array_slice(file($logFile), -5);
                foreach (array_reverse($lines) as $line) {
                    if (strpos($line, 'PHPMailer') !== false) {
                        $emailError = trim($line);
                        break;
                    }
                }
            }
        }
    }
}

// ── Test SMS ──────────────────────────────────────────────────────────────────
if (isset($_POST['test_sms'])) {
    $testPhone = trim($_POST['test_phone'] ?? '');
    if ($testPhone) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://semaphore.co/api/v4/messages',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'apikey'     => SEMAPHORE_API_KEY,
                'number'     => $testPhone,
                'message'    => 'AutoCare Pro Test OTP: 123456. If you received this, SMS is working!',
                'sendername' => SEMAPHORE_SENDER,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $smsResult = 'FAILED';
            $smsError  = 'cURL error: ' . $curlErr;
        } elseif ($httpCode === 200) {
            $smsResult = 'SUCCESS';
        } else {
            $smsResult = 'FAILED';
            $smsError  = "HTTP $httpCode — " . $response;
        }
    }
}

// ── Check config status ───────────────────────────────────────────────────────
$emailConfigured = (defined('MAIL_USERNAME') && MAIL_USERNAME !== 'your_email@gmail.com' && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== 'xxxx xxxx xxxx xxxx');
$smsConfigured   = (defined('SEMAPHORE_API_KEY') && SEMAPHORE_API_KEY !== 'YOUR_SEMAPHORE_API_KEY_HERE');
$vendorExists    = file_exists(__DIR__ . '/vendor/autoload.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OTP Test — AutoCare Pro</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:32px 16px;min-height:100vh;}
.wrap{max-width:760px;margin:0 auto;}
h1{font-size:1.8rem;color:#e94560;margin-bottom:4px;}
.sub{color:#94a3b8;font-size:14px;margin-bottom:32px;}
.card{background:#1e293b;border-radius:16px;border:1px solid #334155;padding:28px;margin-bottom:24px;}
.card h2{font-size:1rem;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.status-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #334155;font-size:14px;}
.status-row:last-child{border-bottom:none;}
.pill{padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700;}
.pill-ok{background:rgba(34,197,94,.15);color:#4ade80;}
.pill-warn{background:rgba(251,191,36,.15);color:#fbbf24;}
.pill-err{background:rgba(239,68,68,.15);color:#f87171;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:#94a3b8;margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px;}
.form-input{width:100%;background:#0f172a;border:1px solid #334155;color:#e2e8f0;padding:12px 14px;border-radius:10px;font-family:inherit;font-size:14px;}
.form-input:focus{outline:none;border-color:#e94560;}
.btn{padding:12px 24px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;border:none;font-family:inherit;transition:all .2s;}
.btn-primary{background:#e94560;color:#fff;}
.btn-primary:hover{background:#c12a36;}
.result-ok{background:rgba(34,197,94,.1);border-left:4px solid #22c55e;color:#4ade80;padding:14px 16px;border-radius:8px;margin-top:16px;font-size:14px;}
.result-err{background:rgba(239,68,68,.1);border-left:4px solid #ef4444;color:#f87171;padding:14px 16px;border-radius:8px;margin-top:16px;font-size:14px;word-break:break-all;}
.code{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:14px 16px;font-family:'Courier New',monospace;font-size:13px;color:#94a3b8;margin-top:10px;line-height:1.8;}
.step{display:flex;gap:14px;margin-bottom:16px;align-items:flex-start;}
.step-num{width:28px;height:28px;background:#e94560;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;}
.step-body{font-size:14px;color:#94a3b8;line-height:1.6;}
.step-body strong{color:#e2e8f0;}
a{color:#e94560;}
.warn-box{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);border-radius:10px;padding:16px;font-size:13px;color:#fbbf24;margin-top:24px;}
</style>
</head>
<body>
<div class="wrap">
    <h1>⚙ AutoCare Pro — OTP Diagnostics</h1>
    <p class="sub">Use this page to configure and test real email & SMS sending. Delete it after setup.</p>

    <!-- ── Status Check ── -->
    <div class="card">
        <h2>📋 Configuration Status</h2>
        <div class="status-row">
            <span>PHPMailer (vendor/autoload.php)</span>
            <span class="pill <?= $vendorExists ? 'pill-ok' : 'pill-err' ?>"><?= $vendorExists ? '✓ Installed' : '✗ Missing' ?></span>
        </div>
        <div class="status-row">
            <span>Gmail SMTP credentials</span>
            <span class="pill <?= $emailConfigured ? 'pill-ok' : 'pill-warn' ?>"><?= $emailConfigured ? '✓ Configured' : '⚠ Not set' ?></span>
        </div>
        <div class="status-row">
            <span>Semaphore SMS API key</span>
            <span class="pill <?= $smsConfigured ? 'pill-ok' : 'pill-warn' ?>"><?= $smsConfigured ? '✓ Configured' : '⚠ Not set' ?></span>
        </div>
        <div class="status-row">
            <span>cURL extension</span>
            <span class="pill <?= function_exists('curl_init') ? 'pill-ok' : 'pill-err' ?>"><?= function_exists('curl_init') ? '✓ Enabled' : '✗ Disabled' ?></span>
        </div>
        <div class="status-row">
            <span>OpenSSL extension</span>
            <span class="pill <?= extension_loaded('openssl') ? 'pill-ok' : 'pill-err' ?>"><?= extension_loaded('openssl') ? '✓ Enabled' : '✗ Disabled' ?></span>
        </div>
    </div>

    <!-- ── Email Setup Guide ── -->
    <?php if (!$emailConfigured): ?>
    <div class="card">
        <h2>📧 How to Set Up Gmail SMTP</h2>
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-body">
                <strong>Enable 2-Step Verification on your Google account</strong><br>
                Go to <a href="https://myaccount.google.com/security" target="_blank">myaccount.google.com/security</a> → turn on 2-Step Verification
            </div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-body">
                <strong>Create a Gmail App Password</strong><br>
                Go to <a href="https://myaccount.google.com/apppasswords" target="_blank">myaccount.google.com/apppasswords</a><br>
                Select <em>Mail</em> → <em>Windows Computer</em> → click <em>Generate</em><br>
                Copy the 16-character password shown (e.g. <code style="color:#e94560;">abcd efgh ijkl mnop</code>)
            </div>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-body">
                <strong>Edit <code>includes/config.php</code></strong> — update these 3 lines:
                <div class="code">
define('MAIL_USERNAME',  '<strong style="color:#e94560;">youremail@gmail.com</strong>');<br>
define('MAIL_PASSWORD',  '<strong style="color:#e94560;">abcd efgh ijkl mnop</strong>');  // app password<br>
define('MAIL_FROM',      '<strong style="color:#e94560;">youremail@gmail.com</strong>');
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SMS Setup Guide ── -->
    <?php if (!$smsConfigured): ?>
    <div class="card">
        <h2>📱 How to Set Up Semaphore SMS</h2>
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-body">
                <strong>Register at Semaphore</strong><br>
                Go to <a href="https://semaphore.co" target="_blank">semaphore.co</a> → Sign Up (free account gives test credits)
            </div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-body">
                <strong>Get your API key</strong><br>
                After login → go to <strong>Account → API</strong> → copy your API key
            </div>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-body">
                <strong>Edit <code>includes/config.php</code></strong>:
                <div class="code">
define('SEMAPHORE_API_KEY', '<strong style="color:#e94560;">your_actual_api_key_here</strong>');<br>
define('SEMAPHORE_SENDER',  '<strong style="color:#e94560;">SEMAPHORE</strong>');  // or your sender name
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Test Email ── -->
    <div class="card">
        <h2>📧 Test Email Sending</h2>
        <?php if (!$emailConfigured): ?>
        <p style="color:#fbbf24;font-size:14px;margin-bottom:16px;">⚠️ Configure Gmail credentials in <code>includes/config.php</code> first.</p>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Send test OTP email to</label>
                <input type="email" name="test_email_addr" class="form-input"
                       placeholder="recipient@gmail.com" required
                       value="<?= htmlspecialchars($_POST['test_email_addr'] ?? '') ?>">
            </div>
            <button type="submit" name="test_email" class="btn btn-primary">
                Send Test Email →
            </button>
        </form>
        <?php if ($emailResult === 'SUCCESS'): ?>
        <div class="result-ok">✅ Email sent successfully! Check your inbox (and spam folder).</div>
        <?php elseif ($emailResult === 'FAILED'): ?>
        <div class="result-err">
            ❌ Email failed.<br><br>
            <strong>Error:</strong> <?= htmlspecialchars($emailError) ?><br><br>
            <strong>Common fixes:</strong><br>
            • Make sure 2-Step Verification is ON in your Google account<br>
            • Use an App Password, NOT your regular Gmail password<br>
            • Check that MAIL_USERNAME and MAIL_FROM match your Gmail address<br>
            • If error says "connection refused", your ISP may block port 587 — try port 465 with SSL
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Test SMS ── -->
    <div class="card">
        <h2>📱 Test SMS Sending</h2>
        <?php if (!$smsConfigured): ?>
        <p style="color:#fbbf24;font-size:14px;margin-bottom:16px;">⚠️ Configure Semaphore API key in <code>includes/config.php</code> first.</p>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Send test OTP SMS to (Philippine number)</label>
                <input type="text" name="test_phone" class="form-input"
                       placeholder="09XXXXXXXXX or +639XXXXXXXXX" required
                       value="<?= htmlspecialchars($_POST['test_phone'] ?? '') ?>">
            </div>
            <button type="submit" name="test_sms" class="btn btn-primary">
                Send Test SMS →
            </button>
        </form>
        <?php if ($smsResult === 'SUCCESS'): ?>
        <div class="result-ok">✅ SMS sent successfully! Check your phone.</div>
        <?php elseif ($smsResult === 'FAILED'): ?>
        <div class="result-err">
            ❌ SMS failed.<br><br>
            <strong>Error:</strong> <?= htmlspecialchars($smsError) ?><br><br>
            <strong>Common fixes:</strong><br>
            • Double-check your Semaphore API key<br>
            • Make sure your Semaphore account has credits<br>
            • Phone number must be a valid Philippine number (09XX or +639XX)
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Current Config (masked) ── -->
    <div class="card">
        <h2>🔍 Current Config Values (masked)</h2>
        <div class="code">
            MAIL_HOST: <?= MAIL_HOST ?><br>
            MAIL_PORT: <?= MAIL_PORT ?><br>
            MAIL_USERNAME: <?= $emailConfigured ? substr(MAIL_USERNAME, 0, 4) . '****@' . explode('@', MAIL_USERNAME)[1] : '<span style="color:#fbbf24;">not set</span>' ?><br>
            MAIL_PASSWORD: <?= MAIL_PASSWORD !== 'xxxx xxxx xxxx xxxx' ? '****' . substr(str_replace(' ','',MAIL_PASSWORD), -4) : '<span style="color:#fbbf24;">not set</span>' ?><br>
            SEMAPHORE_API_KEY: <?= $smsConfigured ? substr(SEMAPHORE_API_KEY, 0, 6) . '...' . substr(SEMAPHORE_API_KEY, -4) : '<span style="color:#fbbf24;">not set</span>' ?><br>
            SEMAPHORE_SENDER: <?= SEMAPHORE_SENDER ?>
        </div>
    </div>

    <div class="warn-box">
        ⚠️ <strong>Security reminder:</strong> Delete <code>test-otp.php</code> after you finish testing.
        It exposes your configuration status.
        <br><br>
        <a href="login.php" style="color:#fbbf24;font-weight:700;">← Back to Login</a>
    </div>
</div>
</body>
</html>
