<?php
session_start();
require_once 'config/db_config.php';
require_once 'config/auth.php';

$message = '';
$error = '';

function validateRecaptchaV2($recaptchaResponse, $remoteIp) {
    if (empty($recaptchaResponse)) return false;

    // Placeholders expected in config/auth.php as requested
    if (!defined('RECAPTCHA_SECRET_KEY') || RECAPTCHA_SECRET_KEY === 'YOUR_RECAPTCHA_SECRET_KEY') {
        return false;
    }

    $postData = http_build_query([
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $recaptchaResponse,
        'remoteip' => $remoteIp,
    ]);

    $opts = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $postData,
            'timeout' => 5,
        ]
    ];

    $context = stream_context_create($opts);
    $result = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    if ($result === false) return false;

    $json = json_decode($result, true);
    return is_array($json) && !empty($json['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'student';

    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } elseif (!validateRecaptchaV2($recaptchaResponse, $_SERVER['REMOTE_ADDR'] ?? '')) {
        $error = 'Captcha verification failed. Please try again.';
    } else {
        $check_query = "SELECT id FROM users WHERE email = ? OR username = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $check_result = $stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = 'Email or username already exists';
        } else {
            $result = registerUser($username, $email, $password, $full_name, $role);

            if (!empty($result['success'])) {
                $user_id = $result['user_id'];

                // CAPTCHA replaces OTP verification for this flow
                $verify_query = "UPDATE users SET email_verified = TRUE, verified_at = NOW() WHERE id = ?";
                $stmt2 = $conn->prepare($verify_query);
                $stmt2->bind_param("i", $user_id);
                $stmt2->execute();

                $user = getUserById($user_id);
                if ($user) {
                    createSession($user);
                }

                if ($user && $user['role'] === 'teacher') {
                    header('Location: teacher/dashboard.php');
                } elseif ($user && $user['role'] === 'admin') {
                    header('Location: admin/dashboard.php');
                } else {
                    header('Location: student/dashboard.php');
                }
                exit;
            }

            $error = $result['message'] ?? 'Registration failed.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Smart Study Planner</title>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            max-width: 400px;
            width: 100%;
            padding: 2rem;
            animation: slideUp 0.5s ease;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        h1 { color: #2c3e50; margin-bottom: 0.5rem; font-size: 1.8rem; }
        .subtitle { color: #7f8c8d; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; margin-bottom: 0.5rem; color: #2c3e50; font-weight: 500; }
        input, select {
            width: 100%; padding: 0.8rem; border: 2px solid #ecf0f1;
            border-radius: 5px; font-size: 1rem; transition: border-color 0.3s;
            font-family: inherit;
        }
        input:focus, select:focus { outline: none; border-color: #3498db; }
        .password-requirements { font-size: 0.85rem; color: #7f8c8d; margin-top: 0.5rem; }
        .btn {
            width: 100%; padding: 0.9rem; border: none; border-radius: 5px;
            font-size: 1rem; cursor: pointer; transition: all 0.3s; font-weight: 600;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white; margin-bottom: 1rem;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3); }
        .message {
            padding: 1rem; border-radius: 5px; margin-bottom: 1rem; animation: slideDown 0.3s ease;
        }
        .success { background-color: #d5f4e6; color: #27ae60; border-left: 4px solid #27ae60; }
        .error { background-color: #fadbd8; color: #c0392b; border-left: 4px solid #c0392b; }
        .link { text-align: center; margin-top: 1rem; color: #7f8c8d; }
        .link a { color: #3498db; text-decoration: none; font-weight: 600; }
        .link a:hover { text-decoration: underline; }
        .captcha-wrap { margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📚 Create Account</h1>
        <p class="subtitle">Join Smart Study Planner and start learning</p>

        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="?" method="POST">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <div class="password-requirements">At least 6 characters</div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <div class="form-group">
                <label for="role">Register as</label>
                <select id="role" name="role" required>
                    <option value="student">Student</option>
                    <option value="teacher">Teacher</option>
                </select>
            </div>

        <div class="captcha-wrap">
                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars(RECAPTCHA_SITE_KEY) ?>"></div>
            </div>


            <button type="submit" class="btn btn-primary">Create Account</button>

            <div class="link">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </form>
    </div>
</body>
</html>

