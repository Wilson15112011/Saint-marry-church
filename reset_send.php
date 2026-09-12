<?php
// TEMPORARY — shows the real error on screen instead of a blank 500.
// Remove these two lines once the problem is found and fixed.


// ================================================
// reset_send.php – إرسال OTP لإعادة تعيين كلمة المرور
// ================================================
require_once 'db_config.php';
require_once 'config.php';
session_start();

if (empty($_SESSION['reset_email']) || empty($_SESSION['reset_user_id'])) {
    header('Location: forgot_password.php');
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once 'PHPMailer-master/src/Exception.php';
require_once 'PHPMailer-master/src/PHPMailer.php';
require_once 'PHPMailer-master/src/SMTP.php';

// توليد OTP جديد
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiry = time() + (15 * 60); // 15 دقيقة

$_SESSION['reset_otp']      = $otp;
$_SESSION['reset_expiry']   = $expiry;
$_SESSION['reset_attempts'] = 0;

$user_email = $_SESSION['reset_email'];
$user_name  = $_SESSION['reset_full_name'] ?? 'عزيزي المستخدم';

// إرسال الإيميل
$mail = new PHPMailer(true);
$sent = false;

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = GMAIL_USER;
    $mail->Password   = GMAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 10; // fail fast instead of risking PHP's execution-time limit
    // $mail->SMTPDebug = 2; // uncomment temporarily to log the full SMTP conversation

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom(GMAIL_USER, 'كنيسة العذراء والملاك ميخائيل');
    $mail->addAddress($user_email, $user_name);

    $mail->isHTML(true);
    $mail->Subject = 'كود إعادة تعيين كلمة المرور – كنيسة العذراء والملاك ميخائيل';
    $mail->Body    = "
    <div dir='rtl' style='font-family: Arial, sans-serif; max-width:480px; margin:0 auto; background:#FDFAF4; border:1px solid #E2D5BC; border-radius:12px; overflow:hidden;'>
        <div style='background:#2C2416; padding:28px; text-align:center;'>
            <h2 style='color:#C8973A; margin:0;'>✝ كنيسة العذراء والملاك ميخائيل</h2>
        </div>
        <div style='padding:32px 28px;'>
            <p style='color:#2C2416; font-size:1rem;'>مرحباً <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
            <p style='color:#5C4A2A;'>كود إعادة تعيين كلمة المرور الخاص بك هو:</p>
            <div style='background:#fff; border:2px solid #C8973A; border-radius:10px; padding:24px; text-align:center; margin:24px 0;'>
                <span style='font-size:2.8rem; font-weight:700; letter-spacing:12px; color:#2C2416; font-family:monospace;'>{$otp}</span>
            </div>
            <p style='color:#8C7A5A; text-align:center;'>الكود صالح لمدة <strong>15 دقيقة</strong> فقط.</p>
        </div>
        <div style='background:#F5F0E8; padding:16px; text-align:center; border-top:1px solid #E2D5BC;'>
            <p style='color:#8C7A5A; font-size:0.78rem;'>© " . date('Y') . " كنيسة العذراء والملاك ميخائيل</p>
        </div>
    </div>";

    $mail->AltBody = "كود إعادة التعيين: {$otp} (صالح 15 دقيقة)";

    $mail->send();
    $sent = true;

} catch (PHPMailerException $e) {
    error_log('Reset OTP Mail Error (PHPMailer): ' . $mail->ErrorInfo);
    $_SESSION['debug_detail'] = $mail->ErrorInfo;
} catch (\Throwable $e) {
    error_log('Reset OTP Mail Error (General): ' . $e->getMessage());
    $_SESSION['debug_detail'] = $e->getMessage();
}

if ($sent) {
    header('Location: reset_verify.php');
} else {
    $_SESSION['reset_error'] = 'فشل إرسال الكود: ' . ($_SESSION['debug_detail'] ?? 'unknown');
    unset($_SESSION['debug_detail']);
    header('Location: forgot_password.php');
}
exit;
?>