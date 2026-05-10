<?php
// Test email configuration
require_once 'config/db_config.php';
require_once 'config/email.php';

echo "<h1>Email Configuration Test</h1>";

// Test basic email sending
$test_email = "test@example.com"; // Replace with your actual email for testing
$test_name = "Test User";
$test_otp = "123456";

echo "<h2>Testing OTP Email Function</h2>";
$result = sendRegistrationOTPEmail($test_email, $test_name, $test_otp);

if ($result) {
    echo "<p style='color: green;'>✅ Email sent successfully!</p>";
} else {
    echo "<p style='color: red;'>❌ Email sending failed!</p>";
    echo "<p>Check the PHP error log for details.</p>";
}

// Show current configuration (without password)
echo "<h2>Current Email Configuration</h2>";
echo "<ul>";
echo "<li><strong>MAIL_HOST:</strong> " . MAIL_HOST . "</li>";
echo "<li><strong>MAIL_PORT:</strong> " . MAIL_PORT . "</li>";
echo "<li><strong>MAIL_USERNAME:</strong> " . MAIL_USERNAME . "</li>";
echo "<li><strong>MAIL_FROM:</strong> " . MAIL_FROM . "</li>";
echo "<li><strong>MAIL_FROM_NAME:</strong> " . MAIL_FROM_NAME . "</li>";
echo "</ul>";

echo "<h2>Configuration Status</h2>";
$issues = [];

if (MAIL_USERNAME === 'your_email@gmail.com') {
    $issues[] = "MAIL_USERNAME is still set to placeholder value";
}

if (MAIL_PASSWORD === 'xxxx xxxx xxxx xxxx') {
    $issues[] = "MAIL_PASSWORD is still set to placeholder value";
}

if (MAIL_FROM === 'your_email@gmail.com') {
    $issues[] = "MAIL_FROM is still set to placeholder value";
}

if (empty($issues)) {
    echo "<p style='color: green;'>✅ Configuration appears to be set up correctly</p>";
} else {
    echo "<p style='color: red;'>❌ Configuration issues found:</p>";
    echo "<ul>";
    foreach ($issues as $issue) {
        echo "<li>$issue</li>";
    }
    echo "</ul>";
    echo "<p><strong>To fix this:</strong></p>";
    echo "<ol>";
    echo "<li><a href='setup_email.php'>Use the automated setup wizard</a> (recommended)</li>";
    echo "<li>Or manually edit <code>config/config.php</code></li>";
    echo "<li>Replace the placeholder values with your actual Gmail credentials</li>";
    echo "<li>Make sure to use a Gmail App Password (not your regular password)</li>";
    echo "<li>Enable 2-Factor Authentication on your Gmail account</li>";
    echo "</ol>";
}
?>