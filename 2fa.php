<?php
require_once 'db_config.php';

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    die('❌ مكتبة 2FA غير موجودة. تأكد من رفع مجلد vendor إلى السيرفر.');
}
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/mail_helper.php';

use PragmaRX\Google2FA\Google2FA;

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

function ensure_2fa_columns(mysqli $conn): void
{
    $cols = [];
    $col_res = $conn->query('SHOW COLUMNS FROM users');
    if ($col_res) {
        while ($c = $col_res->fetch_assoc()) {
            $cols[] = $c['Field'];
        }
        $col_res->free();
    }

    if (!in_array('two_factor_enabled', $cols, true)) {
        $conn->query('ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!in_array('two_factor_secret', $cols, true)) {
        $conn->query('ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64) DEFAULT NULL');
    }
    if (!in_array('theme_mode', $cols, true)) {
        $conn->query("ALTER TABLE users ADD COLUMN theme_mode VARCHAR(10) NOT NULL DEFAULT 'light'");
    }
}

$google2fa = new Google2FA();
$error = '';
$success = '';
$qrCodeUrl = null;
$secret = null;

$conn = get_db();
ensure_2fa_columns($conn);

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT username, full_name, two_factor_enabled, two_factor_secret, theme_mode FROM users WHERE id = ?');
if (!$stmt) {
    http_response_code(500);
    die('❌ تعذر تحميل إعدادات 2FA. يرجى المحاولة لاحقاً.');
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    die('❌ المستخدم غير موجود.');
}

$user['two_factor_enabled'] = (int) ($user['two_factor_enabled'] ?? 0);
$themeMode = ($user['theme_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
$accountLabel = trim((string) ($user['username'] ?? ''));
if ($accountLabel === '') {
    $accountLabel = 'user' . $userId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['enable_2fa'])) {
        if (!$user['two_factor_enabled']) {
            $secret = $google2fa->generateSecretKey(32);
            $_SESSION['temp_2fa_secret'] = $secret;
            $success = 'تم تفعيل 2FA. امسح الـ QR Code أدناه بتطبيق Google Authenticator أو Authy.';
        }
    } elseif (isset($_POST['disable_2fa'])) {
        $password = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result && password_verify($password, $result['password_hash'])) {
            $stmt = $conn->prepare('UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            $success = 'تم تعطيل 2FA بنجاح.';
            unset($_SESSION['temp_2fa_secret']);
            $user['two_factor_enabled'] = 0;
            $user['two_factor_secret'] = null;

            $stmt2 = $conn->prepare('SELECT email, full_name FROM users WHERE id = ?');
            $stmt2->bind_param('i', $userId);
            $stmt2->execute();
            $res = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            if (!empty($res['email'])) {
                $to = $res['email'];
                $username = $res['full_name'] ?: $accountLabel;
                $subject = 'تم تعطيل التحقق بخطوتين (2FA)';
                $body = "
    <div dir='rtl' style='font-family: Arial, sans-serif; max-width:480px; margin:0 auto;
         background:#FDFAF4; border:1px solid #E2D5BC; border-radius:12px; overflow:hidden;'>

        <div style='background:#2C2416; padding:28px; text-align:center;'>
            <h2 style='color:#C8973A; margin:0; font-size:1.2rem;'>
                كنيسة العذراء والملاك ميخائيل
            </h2>
        </div>

        <div style='padding:32px 28px;'>
            <p style='color:#2C2416; font-size:1rem; margin:0 0 12px;'>
                مرحباً <strong>" . htmlspecialchars($username) . "</strong>،
            </p>
            <p style='color:#5C4A2A; font-size:0.95rem; margin:0 0 24px;'>
                تم <strong>تعطيل</strong> التحقق بخطوتين (2FA) على حسابك.
            </p>

            <div style='background:#fff3cd; border:2px solid #ffc107; border-radius:10px;
                        padding:20px; text-align:center; margin-bottom:24px;'>
                <span style='font-size:2rem; color:#856404;'>!</span>
                <p style='color:#856404; font-size:0.9rem; margin:10px 0 0; font-weight:bold;'>
                    إذا لم تقم بهذا الإجراء، يرجى التواصل مع الدعم فورًا.
                </p>
            </div>

            <p style='color:#8C7A5A; font-size:0.82rem; margin:0; text-align:center;'>
                هذا إشعار أمني تلقائي من النظام.
            </p>
        </div>

        <div style='background:#F5F0E8; padding:16px; text-align:center; border-top:1px solid #E2D5BC;'>
            <p style='color:#8C7A5A; font-size:0.78rem; margin:0;'>
                © " . date('Y') . " كنيسة العذراء والملاك ميخائيل بالخلفاوي
            </p>
        </div>
    </div>";
                send_email($to, $username, $subject, $body);
            }
        } else {
            $error = 'كلمة المرور غير صحيحة. لم يتم تعطيل 2FA.';
        }
    } elseif (isset($_POST['verify_setup'])) {
        if (!isset($_SESSION['temp_2fa_secret'])) {
            $error = 'انتهت جلسة الإعداد. يرجى الضغط على تفعيل 2FA مرة أخرى.';
        } else {
            $code = trim($_POST['verification_code'] ?? '');

            if ($google2fa->verifyKey($_SESSION['temp_2fa_secret'], $code, 2)) {
                $secret = $_SESSION['temp_2fa_secret'];

                $stmt = $conn->prepare('
                    UPDATE users
                    SET two_factor_secret = ?, two_factor_enabled = 1
                    WHERE id = ?
                ');
                $stmt->bind_param('si', $secret, $userId);
                $stmt->execute();
                $stmt->close();

                $user['two_factor_enabled'] = 1;
                $user['two_factor_secret'] = $secret;
                unset($_SESSION['temp_2fa_secret']);
                $success = '✅ تم التحقق بنجاح! 2FA مفعل الآن.';

                $stmt2 = $conn->prepare('SELECT email, full_name FROM users WHERE id = ?');
                $stmt2->bind_param('i', $userId);
                $stmt2->execute();
                $res = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
                if (!empty($res['email'])) {
                    $to = $res['email'];
                    $username = $res['full_name'] ?: $accountLabel;
                    $subject = 'تم تفعيل التحقق بخطوتين (2FA)';
                    $body = "
    <div dir='rtl' style='font-family: Arial, sans-serif; max-width:480px; margin:0 auto;
         background:#FDFAF4; border:1px solid #E2D5BC; border-radius:12px; overflow:hidden;'>

        <div style='background:#2C2416; padding:28px; text-align:center;'>
            <h2 style='color:#C8973A; margin:0; font-size:1.2rem;'>
                كنيسة العذراء والملاك ميخائيل
            </h2>
        </div>

        <div style='padding:32px 28px;'>
            <p style='color:#2C2416; font-size:1rem; margin:0 0 12px;'>
                مرحباً <strong>" . htmlspecialchars($username) . "</strong>،
            </p>
            <p style='color:#5C4A2A; font-size:0.95rem; margin:0 0 24px;'>
                تم <strong>تفعيل</strong> التحقق بخطوتين (2FA) على حسابك بنجاح. ✅
            </p>

            <div style='background:#fff; border:2px solid #C8973A; border-radius:10px;
                        padding:20px; text-align:center; margin-bottom:24px;'>
                <p style='color:#2C2416; font-size:0.9rem; margin:10px 0 0; font-weight:bold;'>
                    حسابك الآن محمي بطبقة أمان إضافية
                </p>
            </div>

            <p style='color:#8C7A5A; font-size:0.85rem; margin:0 0 8px; text-align:center;'>
                إذا لم تقم بهذا الإجراء، يرجى التواصل مع الدعم فورًا.
            </p>
            <p style='color:#8C7A5A; font-size:0.82rem; margin:0; text-align:center;'>
                هذا إشعار أمني تلقائي من النظام.
            </p>
        </div>

        <div style='background:#F5F0E8; padding:16px; text-align:center; border-top:1px solid #E2D5BC;'>
            <p style='color:#8C7A5A; font-size:0.78rem; margin:0;'>
                © " . date('Y') . " كنيسة العذراء والملاك ميخائيل بالخلفاوي
            </p>
        </div>
    </div>";
                    send_email($to, $username, $subject, $body);
                }
            } else {
                $error = 'الكود غير صحيح. حاول مرة أخرى.';
            }
        }
    }
}

$secret = $user['two_factor_secret'] ?? $_SESSION['temp_2fa_secret'] ?? null;

if ($secret) {
    $qrCodeUrl = $google2fa->getQRCodeUrl(
        'Church System',
        $accountLabel . '@church',
        $secret
    );
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.5">
    <title>إعداد التحقق بخطوتين (2FA)</title>
    <link rel="stylesheet" href="login.css">
    <style>
        :root {
            --2fa-bg: #F5F0E8;
            --2fa-card: #FFFFFF;
            --2fa-text: #2C2416;
            --2fa-soft: #8C7A5A;
            --2fa-border: #E2D5BC;
        }
        body.theme-dark {
            --cream: #27221A;
            --white: #1D1B18;
            --text-dark: #F3EBDD;
            --text-mid: #D6C7AD;
            --text-soft: #AFA188;
            --border: #3D3428;
            --input-bg: #26231F;
            --2fa-bg: #141311;
            --2fa-card: #1D1B18;
            --2fa-text: #F3EBDD;
            --2fa-soft: #AFA188;
            --2fa-border: #3D3428;
        }
        body { background-color: var(--2fa-bg); color: var(--2fa-text); }
        body.theme-dark .card { background: var(--2fa-card); }
        body.theme-dark .backup-warning { background: #4B3B16 !important; border-color: #8A6D1F !important; color: #F3D98B !important; }
        body.theme-dark .secret-box { background: #26231F; color: var(--text-dark); }
        body.theme-dark .secret-box strong { color: var(--text-dark); }
        body.theme-dark .card p { color: var(--text-mid) !important; }
        body.theme-dark .card hr { border-top-color: var(--2fa-border) !important; }
        .qr-container { text-align: center; margin: 20px 0; }
        .qr-container img { border: 3px solid var(--gold); border-radius: 12px; padding: 10px; background: white; }
        .secret-box { background: #f5f5f5; padding: 15px; border-radius: 8px; margin: 15px 0; font-family: monospace; direction: ltr; }
        .backup-warning { background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .btn-danger { background: #c0392b !important; }
        .btn-danger:hover { background: #a93226 !important; }
    </style>
</head>
<body class="<?= $themeMode === 'dark' ? 'theme-dark' : '' ?>">

<div class="card" style="max-width: 520px; margin: 40px auto;">
    <h2>🔐 التحقق بخطوتين (2FA)</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$user['two_factor_enabled']): ?>
        <?php if (!isset($_SESSION['temp_2fa_secret'])): ?>
            <p>التفعيل يضيف طبقة أمان إضافية لحسابك.</p>
            <form method="POST">
                <button type="submit" name="enable_2fa" class="btn-submit">تفعيل 2FA</button>
            </form>
        <?php else: ?>
            <div class="backup-warning">
                <strong>⚠️ احفظ هذا الكود السري في مكان آمن!</strong><br>
                إذا فقدت هاتفك، ستحتاج هذا الكود لاستعادة الوصول.
            </div>

            <div class="qr-container">
                <p>امسح هذا الـ QR Code بتطبيق Google Authenticator أو Authy:</p>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?= urlencode($qrCodeUrl) ?>" alt="QR Code">
            </div>

            <div class="secret-box">
                <strong>Secret Key (للنسخ اليدوي):</strong><br>
                <?= chunk_split(htmlspecialchars($secret), 4, ' ') ?>
            </div>

            <p>أدخل الكود من التطبيق للتأكد من الإعداد:</p>
            <form method="POST">
                <div class="field">
                    <input type="text" name="verification_code" placeholder="123456" maxlength="6" inputmode="numeric" required>
                </div>
                <button type="submit" name="verify_setup" class="btn-submit">تحقق وتفعيل</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-success">✅ 2FA مفعل حالياً على حسابك.</div>

        <form method="POST">
            <div class="field">
                <label>أدخل كلمة المرور لتعطيل 2FA:</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" name="disable_2fa" class="btn-submit btn-danger">تعطيل 2FA</button>
        </form>
    <?php endif; ?>

    <hr style="margin: 25px 0; border: none; border-top: 1px solid var(--border);">
    <a href="dashboard.php" style="color: var(--gold);">← العودة للوحة التحكم</a>
</div>

</body>
</html>
