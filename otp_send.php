<?php
// ================================================
// otp_send.php – توليد OTP وإرساله على الإيميل
// ================================================

require_once 'db_config.php';
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// لو مفيش بيانات تسجيل في الـ session → ارجع لـ register
if (empty($_SESSION['pending_user'])) {
    header('Location: register.php');
    exit;
}

// ── PHPMailer ────────────────────────────────────
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer-master/src/Exception.php';
require_once 'PHPMailer-master/src/PHPMailer.php';
require_once 'PHPMailer-master/src/SMTP.php';

// ── توليد OTP ──
$otp        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expiry = time() + (OTP_EXPIRY_MINUTES * 60);

$_SESSION['otp']          = $otp;
$_SESSION['otp_expiry']   = $otp_expiry;
$_SESSION['otp_email']    = $_SESSION['pending_user']['email'];
$_SESSION['otp_attempts'] = 0;

$user_email = $_SESSION['pending_user']['email'];
$user_name  = $_SESSION['pending_user']['full_name'];
$language   = ($_SESSION['pending_user']['language'] ?? $_SESSION['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
$_SESSION['language'] = $language;

// ── إرسال الإيميل ──
$mail     = new PHPMailer(true);
$sent     = false;
$send_error = '';

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = GMAIL_USER;
    $mail->Password   = GMAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    // SSL workaround لاستضافات مجانية (تُزال في Production حقيقي)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom(GMAIL_USER, $language === 'en' ? 'Virgin Mary and Archangel Michael Church' : 'كنيسة العذراء والملاك ميخائيل');
    $mail->addAddress($user_email, $user_name);

    $mail->isHTML(true);
    if ($language === 'en') {
        $mail->Subject = 'Verification Code - Virgin Mary and Archangel Michael Church';
        $mail->Body    = "
    <div dir='ltr' style='font-family: Arial, sans-serif; max-width:480px; margin:0 auto;
         background:#FDFAF4; border:1px solid #E2D5BC; border-radius:12px; overflow:hidden;'>

        <div style='background:#2C2416; padding:28px; text-align:center;'>
            <h2 style='color:#C8973A; margin:0; font-size:1.2rem;'>
                Virgin Mary and Archangel Michael Church
            </h2>
        </div>

        <div style='padding:32px 28px;'>
            <p style='color:#2C2416; font-size:1rem; margin:0 0 12px;'>
                Hello <strong>" . htmlspecialchars($user_name) . "</strong>,
            </p>
            <p style='color:#5C4A2A; font-size:0.95rem; margin:0 0 24px;'>
                Your account verification code is:
            </p>

            <div style='background:#fff; border:2px solid #C8973A; border-radius:10px;
                        padding:24px; text-align:center; margin-bottom:24px;'>
                <span style='font-size:2.8rem; font-weight:700; letter-spacing:12px;
                            color:#2C2416; font-family:monospace;'>{$otp}</span>
            </div>

            <p style='color:#8C7A5A; font-size:0.85rem; margin:0 0 8px; text-align:center;'>
                This code is valid for <strong>" . OTP_EXPIRY_MINUTES . " minutes</strong>.
            </p>
            <p style='color:#8C7A5A; font-size:0.82rem; margin:0; text-align:center;'>
                If you did not request this registration, please ignore this email.
            </p>
        </div>

        <div style='background:#F5F0E8; padding:16px; text-align:center; border-top:1px solid #E2D5BC;'>
            <p style='color:#8C7A5A; font-size:0.78rem; margin:0;'>
                © " . date('Y') . " Virgin Mary and Archangel Michael Church
            </p>
        </div>
    </div>";
        $mail->AltBody = "Verification code: {$otp} (valid for " . OTP_EXPIRY_MINUTES . " minutes)";
    } else {
        $mail->Subject = 'كود التحقق – كنيسة العذراء والملاك ميخائيل';
        $mail->Body    = "
    <div dir='rtl' style='font-family: Arial, sans-serif; max-width:480px; margin:0 auto;
         background:#FDFAF4; border:1px solid #E2D5BC; border-radius:12px; overflow:hidden;'>

        <div style='background:#2C2416; padding:28px; text-align:center;'>
            <h2 style='color:#C8973A; margin:0; font-size:1.2rem;'>
                ✝ كنيسة العذراء والملاك ميخائيل
            </h2>
        </div>

        <div style='padding:32px 28px;'>
            <p style='color:#2C2416; font-size:1rem; margin:0 0 12px;'>
                مرحباً <strong>" . htmlspecialchars($user_name) . "</strong>،
            </p>
            <p style='color:#5C4A2A; font-size:0.95rem; margin:0 0 24px;'>
                كود التحقق الخاص بتسجيل حسابك هو:
            </p>

            <div style='background:#fff; border:2px solid #C8973A; border-radius:10px;
                        padding:24px; text-align:center; margin-bottom:24px;'>
                <span style='font-size:2.8rem; font-weight:700; letter-spacing:12px;
                            color:#2C2416; font-family:monospace;'>{$otp}</span>
            </div>

            <p style='color:#8C7A5A; font-size:0.85rem; margin:0 0 8px; text-align:center;'>
                ⏱ الكود صالح لمدة <strong>" . OTP_EXPIRY_MINUTES . " دقائق</strong> فقط
            </p>
            <p style='color:#8C7A5A; font-size:0.82rem; margin:0; text-align:center;'>
                لو مطلبتش التسجيل، تجاهل هذا الإيميل.
            </p>
        </div>

        <div style='background:#F5F0E8; padding:16px; text-align:center; border-top:1px solid #E2D5BC;'>
            <p style='color:#8C7A5A; font-size:0.78rem; margin:0;'>
                © " . date('Y') . " كنيسة العذراء والملاك ميخائيل بالخلفاوي
            </p>
         </div>
     </div>";

        $mail->AltBody = "كود التحقق: {$otp} (صالح " . OTP_EXPIRY_MINUTES . " دقائق)";
    }

    $mail->send();
    $sent = true;

} catch (Exception $e) {
    $send_error = $mail->ErrorInfo;
    error_log('OTP Mail Error: ' . $send_error);
}

if ($sent) {
    header('Location: otp_verify.php');
} else {
    $_SESSION['otp_send_error'] = 'فشل إرسال الكود: ' . $send_error;
    header('Location: otp_verify.php');
}
exit;
