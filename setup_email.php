<?php
/**
 * Email Configuration Setup Helper
 *
 * This script helps you configure Gmail SMTP for OTP functionality
 */

session_start();
require_once __DIR__ . '/config/config.php';

$message = '';
$error = '';
$current_email = defined('MAIL_USERNAME') ? MAIL_USERNAME : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $gmail_address = trim($_POST['gmail_address'] ?? '');
    $app_password = trim($_POST['app_password'] ?? '');

    if (empty($gmail_address) || empty($app_password)) {
        $error = 'Both Gmail address and App Password are required';
    } elseif (!filter_var($gmail_address, FILTER_VALIDATE_EMAIL) || !str_ends_with($gmail_address, '@gmail.com')) {
        $error = 'Please enter a valid Gmail address';
    } elseif (strlen($app_password) !== 19 || substr_count($app_password, ' ') !== 3) {
        $error = 'App Password should be 19 characters with 4 groups separated by spaces (e.g., "abcd efgh ijkl mnop")';
    } else {
        
        $config_content = <<<PHP
<?php
/**
 * Email Configuration - CONFIGURED
 */

define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_SMTP_SECURE', 'tls');
define('MAIL_USERNAME', '{$gmail_address}');
define('MAIL_PASSWORD', '{$app_password}');
define('MAIL_FROM', '{$gmail_address}');
define('MAIL_FROM_NAME', 'Smart Study Planner');

/**
 * Gmail SMTP Configuration Complete!
 *
 * MAIL_USERNAME: {$gmail_address}
 * MAIL_PASSWORD: [HIDDEN FOR SECURITY]
 *
 * To change these settings, edit this file directly or run this setup again.
 */

define('OTP_EXPIRY_MINUTES', 10);
define('LOGIN_OTP_EXPIRY', 5);
define('RESET_OTP_EXPIRY', 15);

define('SYSTEM_EMAIL', 'noreply@smartsp.local');
define('SUPPORT_EMAIL', 'support@smartsp.local');

?>
PHP;

        $result = file_put_contents(__DIR__ . '/config/config.php', $config_content);

        if ($result !== false) {
            $message = '✅ Email configuration updated successfully! <a href="test_email.php">Test the configuration</a>';
            $current_email = $gmail_address;
        } else {
            $error = '❌ Failed to update configuration file. Check file permissions.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Configuration Setup - Smart Study Planner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            color: #333;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: #666;
            font-size: 0.9rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .instructions {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 5px;
            margin-bottom: 2rem;
            border-left: 4px solid #667eea;
        }

        .instructions h3 {
            color: #333;
            margin-bottom: 1rem;
        }

        .instructions ol {
            padding-left: 1.5rem;
        }

        .instructions li {
            margin-bottom: 0.5rem;
            color: #555;
        }

        .instructions strong {
            color: #333;
        }

        .test-link {
            text-align: center;
            margin-top: 1rem;
        }

        .test-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .test-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📧 Email Configuration Setup</h1>
            <p>Configure Gmail SMTP to enable OTP functionality</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="instructions">
            <h3>🚀 Quick Setup Instructions</h3>
            <ol>
                <li><strong>Enable 2-Factor Authentication:</strong> Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account Security</a> → Enable 2-Step Verification</li>
                <li><strong>Generate App Password:</strong> Go to <a href="https://myaccount.google.com/apppasswords" target="_blank">App Passwords</a> → Select "Mail" → Choose your device → Copy the 16-character password</li>
                <li><strong>Fill the form below:</strong> Enter your Gmail address and the App Password (with spaces)</li>
                <li><strong>Test the setup:</strong> Click the test link after saving to verify it works</li>
            </ol>
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="gmail_address">📧 Gmail Address</label>
                <input type="email" id="gmail_address" name="gmail_address" placeholder="your.email@gmail.com" value="<?php echo htmlspecialchars($current_email); ?>" required>
            </div>

            <div class="form-group">
                <label for="app_password">🔑 Gmail App Password</label>
                <input type="password" id="app_password" name="app_password" placeholder="abcd efgh ijkl mnop" required>
                <small style="color: #666; font-size: 0.8rem;">16 characters with spaces (e.g., "abcd efgh ijkl mnop")</small>
            </div>

            <button type="submit" class="btn">💾 Save Configuration</button>
        </form>

        <div class="test-link">
            <a href="test_email.php">🧪 Test Email Configuration</a>
        </div>
    </div>
</body>
</html>