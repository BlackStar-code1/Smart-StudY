<?php
session_start();
require_once 'config/db_config.php';
require_once 'config/auth.php';
require_once 'config/email.php';

// OTP helper functions are provided by config/email.php for this project.
// login.php relies on: generateOTP(), sendLoginOTPEmail(), isEmailConfigured().


$message = '';
$error = '';
$show_otp_form = false;

// Show success message from password reset
if (!empty($_SESSION['reset_success'])) {
    $message = $_SESSION['reset_success'];
    unset($_SESSION['reset_success']);
}

if (isset($_GET['clear'])) {
    unset($_SESSION['login_pending']);
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Step 2: Verify OTP and log in
    if (isset($_POST['verify_otp'])) {
        $otp_input = trim($_POST['otp_code'] ?? '');
        $pending   = $_SESSION['login_pending'] ?? null;

        if (!$pending) {
            $error = 'Session expired. Please login again.';
        } elseif (empty($otp_input)) {
            $error = 'Please enter the OTP code.';
            $show_otp_form = true;
        } elseif (time() > $pending['expires']) {
            $error = 'OTP has expired. Please login again.';
            unset($_SESSION['login_pending']);
        } elseif ($otp_input !== $pending['otp']) {
            $error = 'Invalid OTP. Please try again.';
            $show_otp_form = true;
        } else {
            $user = $pending['user'];
            createSession($user);
            unset($_SESSION['login_pending']);

            if (isset($pending['remember'])) {
                setcookie('remember_email', $user['email'], time() + (86400 * 30), '/');
            }

            switch ($user['role']) {
                case 'admin':
                    header('Location: admin/dashboard.php');
                    exit();
                case 'teacher':
                    header('Location: teacher/dashboard.php');
                    exit();
                default:
                    header('Location: student/dashboard.php');
                    exit();
            }
        }
    }

    // Step 1: Validate credentials and send OTP
    else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Email and password are required.';
        } else {
            $result = loginUser($email, $password);

            if (!$result['success']) {
                $error = $result['message'];
            } else {
                $user = $result['user'];
                $otp  = generateOTP();

                if (sendLoginOTPEmail($user['email'], $user['full_name'] ?? $user['username'], $otp)) {
                    $_SESSION['login_pending'] = [
                        'user'     => $user,
                        'otp'      => $otp,
                        'expires'  => time() + 300, // 5 minutes
                        'remember' => isset($_POST['remember']) ? true : null,
                    ];
                    $message       = 'OTP sent to your email. Enter the code below to complete login.';
                    $show_otp_form = true;
                } else {
                    $error = isEmailConfigured()
                        ? 'Failed to send OTP. Please try again later.'
                        : 'Email not configured. <a href="setup_email.php" style="color:#e74c3c;text-decoration:underline;">Configure SMTP</a> to enable OTP login.';
                }
            }
        }
    }
}

// Keep OTP form visible on page reload if session still active
if (!$show_otp_form && isset($_SESSION['login_pending'])) {
    $show_otp_form = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Smart Study Planner</title>
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

        .login-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
            padding: 2rem;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .logo {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }

        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-weight: 500;
        }

        input {
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

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .remember-forgot a {
            color: #3498db;
            text-decoration: none;
        }

        .remember-forgot a:hover {
            text-decoration: underline;
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
            margin-bottom: 1rem;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background-color: #ecf0f1;
            color: #2c3e50;
        }

        .btn-secondary:hover {
            background-color: #d5dbdb;
        }

        .message {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            animation: slideDown 0.3s ease;
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

        .resend-otp {
            text-align: center;
            margin-top: 1rem;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .resend-otp a {
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .resend-otp a:hover {
            text-decoration: underline;
        }

        .signup-link {
            text-align: center;
            margin-top: 1rem;
            color: #7f8c8d;
        }

        .signup-link a {
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
        }

        .signup-link a:hover {
            text-decoration: underline;
        }

        .demo-users {
            background-color: #f8f9fa;
            border: 1px dashed #bdc3c7;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 1.5rem;
            font-size: 0.85rem;
        }

        .demo-users h4 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .demo-user {
            margin-bottom: 0.5rem;
            color: #34495e;
        }

        .demo-user strong {
            color: #3498db;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">📚</div>
        <h1>Smart Study Planner</h1>
        <p class="subtitle">Welcome back! Please login to your account</p>

        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($show_otp_form && isset($_SESSION['login_pending'])): ?>
            <!-- OTP Verification Form -->
            <div class="otp-instruction">
                📧 A 6-digit code was sent to <strong><?php echo htmlspecialchars($_SESSION['login_pending']['user']['email']); ?></strong>
                <br>Enter it below to complete your login.
            </div>

            <form method="POST">
                <div class="form-group">
                    <label for="otp_code">Verification Code</label>
                    <input type="text"
                           id="otp_code"
                           name="otp_code"
                           maxlength="6"
                           placeholder="000000"
                           inputmode="numeric"
                           pattern="[0-9]{6}"
                           autocomplete="one-time-code"
                           required
                           autofocus
                           value="<?php echo htmlspecialchars($_POST['otp_code'] ?? ''); ?>">
                </div>

                <button type="submit" name="verify_otp" class="btn btn-primary">Verify &amp; Login</button>

                <div class="resend-otp">
                    Didn't receive the code? <a href="login.php?clear=1">Resend OTP</a>
                    <br><small style="color:#95a5a6; display:block; margin-top:0.5rem;">Code expires in 5 minutes</small>
                </div>

                <div class="link" style="text-align:center; margin-top:1rem;">
                    <a href="login.php?clear=1" class="btn btn-secondary" style="display:inline-block; width:100%; padding:0.9rem; text-decoration:none; text-align:center;">← Back to Login</a>
                </div>
            </form>

        <?php else: ?>
            <!-- Login Form -->
            <form method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email"
                           id="email"
                           name="email"
                           required
                           placeholder="your@email.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ($_COOKIE['remember_email'] ?? '')); ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required placeholder="Enter your password">
                </div>

                <div class="remember-forgot">
                    <label style="margin:0; font-weight:normal;">
                        <input type="checkbox" name="remember" style="width:auto; margin-right:0.5rem;"
                            <?php echo isset($_COOKIE['remember_email']) ? 'checked' : ''; ?>>
                        Remember me
                    </label>
                    <a href="forgotpassword.php">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary">Login</button>

                <div class="signup-link">
                    Don't have an account? <a href="register.php">Sign up here</a>
                </div>

               
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
