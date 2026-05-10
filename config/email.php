<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

/**
 * Send an HTML email using SMTP settings from config.php.
 *
 * @param string $to
 * @param string $subject
 * @param string $body
 * @return bool
 */
function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>'], "\n", $body));

        return $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer Error sending email to ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Wrap content in a simple email template.
 *
 * @param string $content
 * @return string
 */
function _emailWrap($content) {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6fb; color: #1f2937; margin: 0; padding: 0; }
        .container { width: 100%; background-color: #f4f6fb; padding: 40px 0; }
        .content { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08); }
        .header { background: #3b82f6; color: #ffffff; padding: 24px; text-align: center; }
        .body { padding: 32px; }
        .footer { background: #f8fafc; color: #64748b; font-size: 13px; padding: 20px 24px; text-align: center; }
        .otp-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; text-align: center; margin: 24px 0; }
        .otp-code { display: inline-block; font-size: 28px; letter-spacing: 6px; font-weight: 700; color: #111827; }
        .otp-label { display: block; margin-top: 10px; font-size: 14px; color: #6b7280; }
        .note { margin-top: 24px; font-size: 14px; line-height: 1.7; color: #475569; }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <div class="header">
                <h1>Smart Study Planner</h1>
            </div>
            <div class="body">
                {$content}
            </div>
            <div class="footer">
                &copy; {$year} Smart Study Planner. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Generate a secure 6-digit OTP code.
 *
 * @return string
 */
function generateOTP() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Send registration OTP email.
 *
 * @param string $to
 * @param string $name
 * @param string $otp
 * @return bool
 */
function sendRegistrationOTPEmail($to, $name, $otp) {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $content  = "<p>Hello <strong>{$safeName}</strong>,</p>";
    $content .= "<p>Welcome to Smart Study Planner! Use the code below to verify your email address. It expires in <strong>10 minutes</strong>.</p>";
    $content .= "<div class='otp-box'><span class='otp-code'>{$otp}</span><span class='otp-label'>Verification Code</span></div>";
    $content .= "<p class='note'>If you did not request this code, you can safely ignore this email.</p>";

    return sendEmail($to, 'Your Smart Study Planner Verification Code', _emailWrap($content));
}

/**
 * Send login OTP email.
 *
 * @param string $to
 * @param string $name
 * @param string $otp
 * @return bool
 */
function sendLoginOTPEmail($to, $name, $otp) {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $content  = "<p>Hello <strong>{$safeName}</strong>,</p>";
    $content .= "<p>Use the code below to finish logging in. It expires in <strong>5 minutes</strong>.</p>";
    $content .= "<div class='otp-box'><span class='otp-code'>{$otp}</span><span class='otp-label'>Login OTP</span></div>";
    $content .= "<p class='note'>If you did not request this login, please secure your account immediately.</p>";

    return sendEmail($to, 'Login OTP - Smart Study Planner', _emailWrap($content));
}

/**
 * Send password reset OTP email.
 *
 * @param string $to
 * @param string $name
 * @param string $otp
 * @return bool
 */
function sendOTPEmail($to, $name, $otp) {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $content  = "<p>Hello <strong>{$safeName}</strong>,</p>";
    $content .= "<p>We received a request to reset your password. Use the code below to continue. It expires in <strong>15 minutes</strong>.</p>";
    $content .= "<div class='otp-box'><span class='otp-code'>{$otp}</span><span class='otp-label'>Password Reset OTP</span></div>";
    $content .= "<p class='note'>If you did not request this, ignore this email or contact support.</p>";

    return sendEmail($to, 'Password Reset OTP - Smart Study Planner', _emailWrap($content));
}

/**
 * Check whether email SMTP is configured.
 *
 * @return bool
 */
function isEmailConfigured() {
    return defined('MAIL_USERNAME') && MAIL_USERNAME !== 'your_email@gmail.com'
        && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== 'xxxx xxxx xxxx xxxx'
        && defined('MAIL_FROM') && MAIL_FROM !== 'your_email@gmail.com';
}

/**
 * Provide a simple SMTP config status message.
 *
 * @return string
 */
function getEmailConfigStatus() {
    $errors = [];
    if (!defined('MAIL_USERNAME') || MAIL_USERNAME === 'your_email@gmail.com') {
        $errors[] = 'MAIL_USERNAME is not configured.';
    }
    if (!defined('MAIL_PASSWORD') || MAIL_PASSWORD === 'xxxx xxxx xxxx xxxx') {
        $errors[] = 'MAIL_PASSWORD is not configured.';
    }
    if (!defined('MAIL_FROM') || MAIL_FROM === 'your_email@gmail.com') {
        $errors[] = 'MAIL_FROM is not configured.';
    }

    return empty($errors) ? 'SMTP configuration looks correct.' : implode(' ', $errors);
}
