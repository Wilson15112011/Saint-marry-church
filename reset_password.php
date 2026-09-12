<?php
// =========================================================
// reset_password.php – تعيين كلمة مرور جديدة (نسخة آمنة ومعدلة)
// =========================================================
require_once 'db_config.php';
session_start();

// 1. معالجة أمنية: منع التخطي العشوائي للملف دون إتمام التحقق من الـ OTP بنجاح
if (empty($_SESSION['reset_user_id']) || empty($_SESSION['reset_otp_verified'])) {
    header('Location: forgot_password.php');
    exit;
}

$error = '';
$success = false;

// حساب التاريخ الحالي باللغة العربية للـ Header
$ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$today = $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // Match the registration password requirements.
    if (strlen($password) < 8) {
        $error = 'كلمة المرور يجب أن تكون 8 أحرف وأرقام على الأقل لضمان الأمان.';
    } elseif (!preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)) {
        $error = 'كلمة المرور يجب أن تحتوي على حروف كبيرة وصغيرة وأرقام.';
    } elseif ($password !== $password_confirm) {
        $error = 'كلمتا المرور غير متطابقتين، يرجى إعادة الكتابة بدقة.';
    } else {
        try {
            $conn = get_db();
            $user_id = $_SESSION['reset_user_id'];

            $current_stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $current_stmt->bind_param("i", $user_id);
            $current_stmt->execute();
            $current_user = $current_stmt->get_result()->fetch_assoc();
            $current_stmt->close();

            if (!$current_user) {
                $error = 'تعذر العثور على الحساب. يرجى بدء استعادة كلمة المرور مرة أخرى.';
            } elseif (password_verify($password, $current_user['password_hash'])) {
                $error = 'لا يمكن استخدام كلمة المرور القديمة. يرجى اختيار كلمة مرور جديدة.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
                // Update the stored password only after all checks pass.
                $stmt = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $hash, $user_id);

                if ($stmt->execute()) {
                    $success = true;

                    // Clean reset-only session values after successful use.
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['reset_full_name']);
                    unset($_SESSION['reset_otp']);
                    unset($_SESSION['reset_expiry']);
                    unset($_SESSION['reset_attempts']);
                    unset($_SESSION['reset_otp_verified']);
                } else {
                    $error = 'حدث خطأ غير متوقع أثناء تحديث البيانات بالسيرفر الداخلي.';
                }
                $stmt->close();
            }
            $conn->close();
        } catch (Exception $e) {
            error_log("Reset Password Exception: " . $e->getMessage());
            $error = 'تعذر الاتصال بقاعدة البيانات حالياً لإجراء التحديث الفوري.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعيين كلمة المرور الجديدة</title>
    <link rel="stylesheet" href="login.css">
    <link rel="stylesheet" href="register.css">
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
    <div class="card" style="max-width:460px; margin:40px auto; padding:40px 32px;">

        <?php if ($success): ?>
            <div class="success-screen" style="text-align:center;">
                <div style="font-size: 3.5rem; margin-bottom: 15px;">✅</div>
                <h2>تم تغيير كلمة المرور بنجاح</h2>
                <p class="subtitle" style="margin-top: 8px;">يمكنك الآن التوجه لصفحة تسجيل الدخول باستخدام البيانات الجديدة.</p>
                <a href="login.php" class="btn-submit" style="display:inline-block; margin-top:24px; text-decoration:none;">تسجيل الدخول</a>
            </div>
        <?php else: ?>
            <h2>كلمة مرور جديدة</h2>
            <p class="subtitle">قم بكتابة وتأكيد كلمة مرور جديدة يصعب تخمينها.</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px; text-align:right;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="regForm" class="login-form">
                <div class="field">
                    <label for="password">كلمة المرور الجديدة</label>
                    <div class="pw-wrapper">
                        <input type="password" id="password" name="password" required placeholder="8 خانات تشمل أحرف وأرقام على الأقل" autofocus>
                        <button type="button" class="pw-toggle" data-target="password" aria-label="إظهار كلمة المرور">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <span class="strength-label" id="strengthLabel"></span>
                </div>
                <div class="field">
                    <label for="password_confirm">تأكيد كلمة المرور الجديدة</label>
                    <div class="pw-wrapper">
                        <input type="password" id="password_confirm" name="password_confirm" required placeholder="أعد كتابة نفس كلمة المرور أعلاه">
                        <button type="button" class="pw-toggle" data-target="password_confirm" aria-label="إظهار تأكيد كلمة المرور">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <span class="match-label" id="matchLabel"></span>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn" style="margin-top:15px;">حفظ وتحديث الحساب</button>
            </form>
        <?php endif; ?>

    </div>
</main>

<script>
window.registerText = <?= json_encode([
    'creating' => 'جاري الحفظ...',
    'passwordMatch' => '✓ كلمتا المرور متطابقتان',
    'passwordMismatch' => '✗ كلمتا المرور غير متطابقتين',
    'strength' => ['ضعيف جداً', 'ضعيف', 'متوسط', 'جيد', 'قوي'],
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="register.js"></script>
</body>
</html>