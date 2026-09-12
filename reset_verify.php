<?php
// =========================================================
// reset_verify.php – التحقق من OTP إعادة التعيين (نسخة معدلة وآمنة)
// =========================================================
require_once 'db_config.php';
session_start();

// ── Arabic date ──────────────────────────────────
$ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$today = $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');

if (empty($_SESSION['reset_email']) || empty($_SESSION['reset_otp'])) {
    header('Location: forgot_password.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered = '';
    for ($i = 1; $i <= 6; $i++) {
        $entered .= trim($_POST["d{$i}"] ?? '');
    }

    $_SESSION['reset_attempts'] = ($_SESSION['reset_attempts'] ?? 0) + 1;
    $remaining = max(0, 5 - $_SESSION['reset_attempts']);

    if ($_SESSION['reset_attempts'] > 5) {
        $error = 'تجاوزت الحد المسموح من المحاولات. يرجى إعادة تقديم الطلب.';
        unset($_SESSION['reset_otp']); // إتلاف الكود القديم لزيادة الأمان
    } elseif (time() > ($_SESSION['reset_expiry'] ?? 0)) {
        $error = 'انتهت صلاحية الكود. اضغط "إعادة الإرسال".';
    } elseif ($entered !== $_SESSION['reset_otp']) {
        $error = "الكود غير صحيح. لديك {$remaining} محاولات متبقية.";
    } else {
        // ✅ تعديل أمني: تفعيل راية النجاح لمنع تخطي الصفحة والدخول العشوائي لـ reset_password.php
        $_SESSION['reset_otp_verified'] = true;
        
        header('Location: reset_password.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق من رمز الأمان</title>
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
            <span class="header-date"><?= htmlspecialchars($today ?? '') ?></span>
        </div>
    </div>
</header>

<main>
    <div class="card otp-card">
        <h2>التحقق من الحساب</h2>
        <p class="subtitle">أدخل كود التحقق المكون من 6 أرقام المرسل إلى بريدك الإلكتروني</p>

        <?php if ($error): ?>
            <div class="alert alert-error" style="text-align: right;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="otpForm" class="login-form">
            <div class="field">
                <div class="otp-wrapper" style="display: flex; gap: 8px; justify-content: center; direction: ltr;">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text" name="d<?= $i ?>" class="otp-box" maxlength="1" pattern="[0-9]*" inputmode="numeric" required autocomplete="off" <?= $i === 1 ? 'autofocus' : '' ?>>
                    <?php endfor; ?>
                </div>
            </div>
            <button type="submit" class="btn-submit" id="submitBtn">تأكيد الكود</button>
        </form>

        <div class="resend-row">
            <a href="reset_send.php" class="resend-link">إعادة إرسال الكود</a>
        </div>
    </div>
</main>

<script>
// سكريبت الـ OTP الذكي لإدارة التنقل التلقائي، المسح، واللصق (Auto-focus + Paste + Auto-submit)
const boxes = document.querySelectorAll('.otp-box');
boxes.forEach((box, idx) => {
    box.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(-1);
        if (this.value && idx < boxes.length - 1) boxes[idx + 1].focus();
        if ([...boxes].every(b => b.value.length === 1)) {
            setTimeout(() => document.getElementById('otpForm').submit(), 300);
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
            setTimeout(() => document.getElementById('otpForm').submit(), 300);
        }
    });
});
</script>
</body>
</html>