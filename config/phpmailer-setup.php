<?php
/**
 * PHPMailer Setup and Configuration Helper
 *
 * This file helps test and validate PHPMailer configuration.
 */

require_once __DIR__ . '/config.php';

// Test PHPMailer Installation
function testPHPMailerInstallation() {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        return ['success' => true, 'message' => 'PHPMailer installed correctly'];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'PHPMailer installation problem: ' . $e->getMessage()];
    }
}

// Test Gmail SMTP configuration
function testMailConfig() {
    $username = defined('MAIL_USERNAME') ? MAIL_USERNAME : '';
    $password = defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '';
    $from = defined('MAIL_FROM') ? MAIL_FROM : '';

    $errors = [];

    if (empty($username) || $username === 'your_email@gmail.com') {
        $errors[] = 'MAIL_USERNAME is not configured. Set it in config/config.php';
    }

    if (empty($password) || $password === 'xxxx xxxx xxxx xxxx') {
        $errors[] = 'MAIL_PASSWORD is not configured. Set it in config/config.php';
    }

    if (empty($from) || $from === 'your_email@gmail.com') {
        $errors[] = 'MAIL_FROM is not configured. Set it in config/config.php';
    }

    if (!empty($errors)) {
        return ['success' => false, 'message' => implode('; ', $errors)];
    }

    return ['success' => true, 'message' => 'SMTP configuration looks good'];
}

// Send a test email using the current configuration
function sendTestEmail($recipient) {
    require_once __DIR__ . '/../vendor/autoload.php';

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        $mail->isSMTP();
        $mail->Host = defined('MAIL_HOST') ? MAIL_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = defined('MAIL_PORT') ? MAIL_PORT : 587;

        $mail->setFrom(MAIL_FROM, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Smart Study Planner');
        $mail->addAddress($recipient);

        $mail->isHTML(true);
        $mail->Subject = 'Test Email - Smart Study Planner';
        $mail->Body = '<h2>Test Email</h2><p>This is a test email to verify PHPMailer configuration.</p>';

        if ($mail->send()) {
            return ['success' => true, 'message' => 'Test email sent successfully to ' . $recipient];
        }

        return ['success' => false, 'message' => 'Failed to send: ' . $mail->ErrorInfo];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return ['success' => false, 'message' => 'PHPMailer exception: ' . $e->getMessage()];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'General exception: ' . $e->getMessage()];
    }
}
