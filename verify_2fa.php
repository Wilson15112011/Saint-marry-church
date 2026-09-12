<?php
require_once 'db_config.php';
require_once 'config.php';

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    die('❌ مكتبة 2FA غير موجودة. تأكد من رفع مجلد vendor إلى السيرفر.');
}
require_once __DIR__ . '/vendor/autoload.php';

use PragmaRX\Google2FA\Google2FA;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer-master/src/Exception.php';
require_once 'PHPMailer-master/src/PHPMailer.php';
require_once 'PHPMailer-master/src/SMTP.php';

session_start();

// ── Arabic date ──────────────────────────────────
$ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$today = $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');

if (!isset($_SESSION['pending_2fa_user'])) {
    header('Location: index.php');
    exit;
}

$google2fa = new Google2FA();
$error = '';
$message = '';
$mode = $_SESSION['pending_2fa_mode'] ?? 'choose';

function finish_2fa_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['family_name'] = $user['family_name'];
    $_SESSION['phone'] = $user['phone'] ?? '';

    $conn = get_db();
    $upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $upd->bind_param('i', $user['id']);
    $upd->execute();
    $upd->close();

    unset(
        $_SESSION['pending_2fa_user'],
        $_SESSION['pending_2fa_secret'],
        $_SESSION['pending_2fa_mode'],
        $_SESSION['login_otp'],
        $_SESSION['login_otp_expiry'],
        $_SESSION['login_otp_attempts'],
        $_SESSION['login_otp_email']
    );

    header('Location: dashboard.php');
    exit;
}

function mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    $name = $parts[0] ?? '';
    return substr($name, 0, 3) . '****@' . ($parts[1] ?? '');
}

function send_login_otp(array $user): bool
{
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['login_otp'] = $otp;
    $_SESSION['login_otp_expiry'] = time() + (OTP_EXPIRY_MINUTES * 60);
    $_SESSION['login_otp_attempts'] = 0;
    $_SESSION['login_otp_email'] = $user['email'];

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = GMAIL_USER;
        $mail->Password = GMAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(GMAIL_USER, 'كنيسة العذراء والملاك ميخائيل');
        $mail->addAddress($user['email'], $user['full_name']);
        $mail->isHTML(true);
        $mail->Subject = 'كود تسجيل الدخول - كنيسة العذراء والملاك ميخائيل';
        $mail->Body = "
        <div dir='rtl' style='font-family: Arial, sans-serif; max-width:480px; margin:0 auto; background:#FDFAF4; border:1px solid #E2D5BC; border-radius:12px; overflow:hidden;'>
            <div style='background:#2C2416; padding:28px; text-align:center;'>
                <h2 style='color:#C8973A; margin:0; font-size:1.2rem;'>كنيسة العذراء والملاك ميخائيل</h2>
            </div>
            <div style='padding:32px 28px;'>
                <p style='color:#2C2416; font-size:1rem; margin:0 0 12px;'>مرحباً <strong>" . htmlspecialchars($user['full_name']) . "</strong>،</p>
                <p style='color:#5C4A2A; font-size:0.95rem; margin:0 0 24px;'>كود تأكيد تسجيل الدخول هو:</p>
                <div style='background:#fff; border:2px solid #C8973A; border-radius:10px; padding:24px; text-align:center; margin-bottom:24px;'>
                    <span style='font-size:2.8rem; font-weight:700; letter-spacing:12px; color:#2C2416; font-family:monospace;'>{$otp}</span>
                </div>
                <p style='color:#8C7A5A; font-size:0.85rem; margin:0; text-align:center;'>الكود صالح لمدة <strong>" . OTP_EXPIRY_MINUTES . " دقائق</strong> فقط.</p>
            </div>
        </div>";
        $mail->AltBody = "كود تسجيل الدخول: {$otp} (صالح " . OTP_EXPIRY_MINUTES . " دقائق)";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Login OTP Mail Error: ' . $mail->ErrorInfo);
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_SESSION['pending_2fa_user'];

    if (isset($_POST['use_app'])) {
        $_SESSION['pending_2fa_mode'] = 'app';
        $mode = 'app';
    } elseif (isset($_POST['send_email_otp']) || isset($_POST['resend_email_otp'])) {
        $_SESSION['pending_2fa_mode'] = 'email';
        $mode = 'email';
        if (send_login_otp($user)) {
            $message = 'تم إرسال كود التحقق إلى بريدك الإلكتروني.';
        } else {
            $error = 'تعذر إرسال كود التحقق الآن. حاول مرة أخرى.';
        }
    } elseif (isset($_POST['verify_code'])) {
        $code = '';
        for ($i = 1; $i <= 6; $i++) {
            $code .= trim($_POST["d{$i}"] ?? '');
        }

        if (($mode === 'email') || isset($_SESSION['login_otp'])) {
            $_SESSION['login_otp_attempts'] = ($_SESSION['login_otp_attempts'] ?? 0) + 1;
            $remaining = max(0, 5 - $_SESSION['login_otp_attempts']);
            if ($_SESSION['login_otp_attempts'] > 5) {
                $error = 'تجاوزت الحد المسموح من المحاولات. يرجى تسجيل الدخول من جديد.';
                session_unset();
            } elseif (time() > ($_SESSION['login_otp_expiry'] ?? 0)) {
                $error = 'انتهت صلاحية الكود. اضغط إعادة الإرسال.';
            } elseif (!hash_equals((string) ($_SESSION['login_otp'] ?? ''), $code)) {
                $error = "الكود غير صحيح. لديك {$remaining} محاولات متبقية.";
            } else {
                finish_2fa_login($user);
            }
        } elseif ($google2fa->verifyKey($_SESSION['pending_2fa_secret'], $code, 2)) {
            finish_2fa_login($user);
        } else {
            $error = 'كود التحقق غير صحيح، يرجى المحاولة مرة أخرى';
        }
    } elseif (isset($_POST['change_method'])) {
        $_SESSION['pending_2fa_mode'] = 'choose';
        $mode = 'choose';
        unset($_SESSION['login_otp'], $_SESSION['login_otp_expiry'], $_SESSION['login_otp_attempts'], $_SESSION['login_otp_email']);
    }
}

if (!isset($_SESSION['pending_2fa_user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['pending_2fa_user'];
if (!isset($user['email'])) {
    $user['email'] = '';
}
$masked_email = $user['email'] ? mask_email($user['email']) : '';
if (isset($_SESSION['login_otp'])) {
    $mode = 'email';
} elseif (($mode !== 'app') && ($mode !== 'email')) {
    $mode = 'choose';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق بخطوتين – كنيسة العذراء والملاك ميخائيل</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="login.css">
    <link rel="stylesheet" href="otp.css">
</head>
<body>

<header>
    <div class="logo-area">
        <svg class="logo-icon" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="40" cy="40" r="38" stroke="#C8973A" stroke-width="2" fill="none" opacity="0.25"/>
            <rect x="36" y="12" width="8" height="56" rx="3" fill="#C8973A"/>
            <rect x="16" y="34" width="48" height="8" rx="3" fill="#C8973A"/>
            <circle cx="40" cy="38" r="6" fill="#F5F0E8" stroke="#C8973A" stroke-width="1.5"/>
            <circle cx="40" cy="14" r="4" fill="none" stroke="#C8973A" stroke-width="1.5"/>
        </svg>
        <div class="logo-text">
            <h1>كنيسة العذراء والملاك ميخائيل</h1>
            <span class="header-date"><?= htmlspecialchars($today) ?></span>
        </div>
    </div>
</header>

<main>
    <div class="card otp-card">

        <div class="otp-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2 4 5v6c0 5 3.5 9 8 11 4.5-2 8-6 8-11V5l-8-3Z"/>
                <path d="M9.5 12.5 11 14l3.5-3.5"/>
            </svg>
        </div>

        <h2>التحقق بخطوتين</h2>
        <p class="subtitle">
            <?php if ($mode === 'email'): ?>
                أدخل الكود المرسل إلى <?= htmlspecialchars($masked_email) ?>
            <?php elseif ($mode === 'app'): ?>
                أدخل كود التحقق المكوّن من 6 أرقام من تطبيق المصادقة
            <?php else: ?>
                اختر طريقة التحقق المناسبة لحسابك
            <?php endif; ?>
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($mode === 'choose'): ?>
            <form method="POST" class="login-form" style="display:grid; gap:12px;">
                <button type="submit" name="use_app" class="btn-submit">استخدام تطبيق المصادقة</button>
                <button type="submit" name="send_email_otp" class="btn-submit">إرسال كود إلى الإيميل</button>
            </form>
        <?php else: ?>
            <form method="POST" id="twofaForm" class="login-form">
                <div class="otp-inputs" id="otpInputs">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text" name="d<?= $i ?>" class="otp-box" maxlength="1" pattern="[0-9]*" inputmode="numeric" required autocomplete="off" <?= $i === 1 ? 'autofocus' : '' ?>>
                    <?php endfor; ?>
                </div>
                <button type="submit" name="verify_code" class="btn-submit" id="submitBtn">تأكيد</button>
            </form>

            <form method="POST" class="resend-row" style="display:flex; justify-content:center; gap:14px; flex-wrap:wrap;">
                <?php if ($mode === 'email'): ?>
                    <button type="submit" name="resend_email_otp" class="resend-link" style="border:0; background:transparent; cursor:pointer;">إعادة إرسال الكود</button>
                <?php endif; ?>
                <button type="submit" name="change_method" class="resend-link" style="border:0; background:transparent; cursor:pointer;">تغيير الطريقة</button>
            </form>
        <?php endif; ?>

        <div class="resend-row">
            <a href="index.php" class="resend-link">إلغاء والعودة لتسجيل الدخول</a>
        </div>
    </div>
</main>

<script>
const boxes = document.querySelectorAll('.otp-box');
boxes.forEach((box, idx) => {
    box.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(-1);
        if (this.value && idx < boxes.length - 1) boxes[idx + 1].focus();
        if ([...boxes].every(b => b.value.length === 1)) {
            setTimeout(() => document.getElementById('submitBtn').click(), 250);
        }
    });

    box.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !this.value && idx > 0) {
            boxes[idx - 1].focus();
        }
    });

    box.addEventListener('paste', function(e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData)
            .getData('text')
            .replace(/\D/g, '')
            .slice(0, 6);
        pasted.split('').forEach((char, i) => {
            if (boxes[i]) boxes[i].value = char;
        });
        const lastFilled = Math.min(pasted.length, boxes.length) - 1;
        if (lastFilled >= 0) boxes[lastFilled].focus();
        if (pasted.length === 6) {
            setTimeout(() => document.getElementById('submitBtn').click(), 250);
        }
    });
});

<?php if ($error): ?>
document.getElementById('otpInputs').classList.add('shake');
<?php endif; ?>
</script>

</body>
</html>
