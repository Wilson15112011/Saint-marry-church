<?php
require_once __DIR__ . '/config.php';

// DO NOT use vendor/autoload.php — crashes on InfinityFree
// Load PHPMailer directly instead:
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = 'smtp.gmail.com';
        $mail->SMTPAuth    = true;
        $mail->Username    = defined('GMAIL_USER') ? GMAIL_USER : '';
        $mail->Password    = defined('GMAIL_PASS') ? GMAIL_PASS : '';
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = 587;
        $mail->CharSet     = 'UTF-8';
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $senderName = defined('SITE_NAME') ? SITE_NAME : 'كنيسة العذراء والملاك ميخائيل';
        $mail->setFrom(defined('GMAIL_USER') ? GMAIL_USER : 'no-reply@example.com', $senderName);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}