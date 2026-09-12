<?php
// ================================================
// otp_verify.php – التحقق من OTP
// ================================================
require_once 'db_config.php';
session_start();

// لو مفيش بيانات تسجيل → ارجع للتسجيل
if (empty($_SESSION['pending_user']) || empty($_SESSION['otp'])) {
    header('Location: register.php');
    exit;
}

$error   = '';
$success = false;
$lang = ($_SESSION['pending_user']['language'] ?? $_SESSION['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
$is_rtl = ($lang === 'ar');
$copy = [
    'ar' => [
        'title' => 'التحقق من الإيميل – كنيسة العذراء والملاك ميخائيل',
        'church' => 'كنيسة العذراء والملاك ميخائيل',
        'success_title' => 'تم التسجيل بنجاح!',
        'success_text' => 'جاري تحويلك إلى لوحة التحكم...',
        'step_data' => 'البيانات',
        'step_verify' => 'التحقق',
        'heading' => 'تحقق من بريدك الإلكتروني',
        'subtitle' => 'أرسلنا كود التحقق إلى',
        'submit' => 'تأكيد الكود',
        'resend' => 'إعادة إرسال الكود',
        'too_many' => 'تجاوزت الحد المسموح من المحاولات. يرجى إعادة التسجيل.',
        'expired' => 'انتهت صلاحية الكود. اضغط "إعادة الإرسال".',
        'wrong' => 'الكود غير صحيح. لديك %d محاولات متبقية.',
        'create_error' => 'حدث خطأ أثناء إنشاء الحساب.',
        'db_error' => 'خطأ في قاعدة البيانات: ',
    ],
    'en' => [
        'title' => 'Email Verification - Virgin Mary and Archangel Michael Church',
        'church' => 'Virgin Mary and Archangel Michael Church',
        'success_title' => 'Registration successful!',
        'success_text' => 'Redirecting you to the dashboard...',
        'step_data' => 'Details',
        'step_verify' => 'Verification',
        'heading' => 'Check your email',
        'subtitle' => 'We sent the verification code to',
        'submit' => 'Confirm Code',
        'resend' => 'Resend Code',
        'too_many' => 'You exceeded the allowed attempts. Please register again.',
        'expired' => 'The code has expired. Click "Resend Code".',
        'wrong' => 'The code is incorrect. You have %d attempts remaining.',
        'create_error' => 'An error occurred while creating the account.',
        'db_error' => 'Database error: ',
    ],
];
$txt = $copy[$lang];

if (isset($_GET['resend'])) {
    header('Location: otp_send.php');
    exit;
}

// ── معالجة POST ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered = '';
    for ($i = 1; $i <= 6; $i++) {
        $entered .= trim($_POST["d{$i}"] ?? '');
    }

    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
    $remaining = max(0, 5 - $_SESSION['otp_attempts']);

    if ($_SESSION['otp_attempts'] > 5) {
        $error = $txt['too_many'];
        session_unset();
    } elseif (time() > ($_SESSION['otp_expiry'] ?? 0)) {
        $error = $txt['expired'];
    } elseif ($entered !== $_SESSION['otp']) {
        $error = sprintf($txt['wrong'], $remaining);
    } else {
        // ✅ OTP صحيح → حفظ المستخدم في قاعدة البيانات
        $u = $_SESSION['pending_user'];
        
        try {
            $conn = get_db();
            // Fix: Use the already hashed password from register.php
            $hash = $u['password'];
            
            $role        = $u['role'] ?? 'خادم';
            $family_name = $u['family_name'] ?? '';
            $language    = ($u['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
            $class_name  = ($role === 'خادم') ? ($u['class_name'] ?? '') : null;

            $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'language'");
            if ($column_check && $column_check->num_rows === 0) {
                $conn->query("ALTER TABLE users ADD COLUMN language VARCHAR(2) NOT NULL DEFAULT 'ar'");
            }
            $class_column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'class_name'");
            if ($class_column_check && $class_column_check->num_rows === 0) {
                $conn->query("ALTER TABLE users ADD COLUMN class_name VARCHAR(100) DEFAULT NULL");
            }

            $stmt = $conn->prepare(
                "INSERT INTO users 
                 (username, email, password_hash, full_name, phone, family_name, role, class_name, language) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $stmt->bind_param(
                'sssssssss',
                $u['username'],
                $u['email'],
                $hash,
                $u['full_name'],
                $u['phone'],
                $family_name,
                $role,
                $class_name,
                $language
            );

            if ($stmt->execute()) {
                $new_id = $conn->insert_id;

                // حفظ البيانات في الـ Session بشكل صحيح
                session_regenerate_id(true);
                
                $_SESSION['user_id']     = $new_id;
                $_SESSION['username']    = $u['username'];
                $_SESSION['full_name']   = $u['full_name'];
                $_SESSION['family_name'] = $family_name;     // ← مهم جداً
                $_SESSION['phone']       = $u['phone'] ?? '';
                $_SESSION['role']        = $role;
                $_SESSION['class_name']  = $class_name ?? '';
                $_SESSION['language']    = $language;

                // تنظيف البيانات المؤقتة
                unset(
                    $_SESSION['pending_user'],
                    $_SESSION['otp'],
                    $_SESSION['otp_expiry'],
                    $_SESSION['otp_attempts'],
                    $_SESSION['otp_email']
                );

                $success = true;
            } else {
                $error = $txt['create_error'];
            }
            
            $stmt->close();
            $conn->close();

        } catch (Exception $e) {
            $error = $txt['db_error'] . $e->getMessage();
        }
    }
}

// لعرض الإيميل المقنع
$masked_email = '';
if (!empty($_SESSION['otp_email'])) {
    $parts = explode('@', $_SESSION['otp_email']);
    $masked_email = substr($parts[0], 0, 3) . '****@' . ($parts[1] ?? '');
}

$seconds_left = max(0, ($_SESSION['otp_expiry'] ?? time() + 300) - time());
?>

<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $is_rtl ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($txt['title']) ?></title>
    <link rel="stylesheet" href="login.css">
    <link rel="stylesheet" href="otp.css">
</head>
<body>

<header>
    <div class="logo-area">
        <!-- Logo SVG -->
        <svg class="logo-icon" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="40" cy="40" r="38" stroke="#C8973A" stroke-width="2" fill="none" opacity="0.25"/>
            <rect x="36" y="12" width="8" height="56" rx="3" fill="#C8973A"/>
            <rect x="16" y="34" width="48" height="8" rx="3" fill="#C8973A"/>
            <circle cx="40" cy="38" r="6" fill="#F5F0E8" stroke="#C8973A" stroke-width="1.5"/>
        </svg>
        <div class="logo-text">
            <h1><?= htmlspecialchars($txt['church']) ?></h1>
        </div>
    </div>
</header>

<main>
    <div class="card otp-card">

        <?php if ($success): ?>
            <!-- شاشة النجاح -->
            <div class="success-screen">
                <div class="success-icon">✓</div>
                <h2><?= htmlspecialchars($txt['success_title']) ?></h2>
                <p><?= htmlspecialchars($txt['success_text']) ?></p>
                <script>
                    setTimeout(() => { window.location.href = 'dashboard.php'; }, 1800);
                </script>
            </div>
        <?php else: ?>
            <!-- شاشة إدخال OTP -->
            <div class="steps-bar">
                <div class="step done"><div class="step-num">✓</div><span><?= htmlspecialchars($txt['step_data']) ?></span></div>
                <div class="step-line done-line"></div>
                <div class="step active"><div class="step-num"><?= $is_rtl ? '٢' : '2' ?></div><span><?= htmlspecialchars($txt['step_verify']) ?></span></div>
            </div>

            <div class="otp-icon">✉️</div>
            <h2><?= htmlspecialchars($txt['heading']) ?></h2>
            <p class="subtitle"><?= htmlspecialchars($txt['subtitle']) ?><br>
                <strong class="email-display"><?= htmlspecialchars($masked_email) ?></strong>
            </p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="otpForm">
                <div class="otp-inputs">
                    <?php for($i=1; $i<=6; $i++): ?>
                        <input type="text" name="d<?= $i ?>" class="otp-box" maxlength="1" inputmode="numeric" required>
                    <?php endfor; ?>
                </div>
                <button type="submit" class="btn-submit" id="submitBtn"><?= htmlspecialchars($txt['submit']) ?></button>
            </form>

            <div class="resend-row">
                <a href="otp_send.php" class="resend-link"><?= htmlspecialchars($txt['resend']) ?></a>
            </div>
        <?php endif; ?>

    </div>
</main>

<script>
// Auto-focus and paste handling for OTP
const boxes = document.querySelectorAll('.otp-box');
boxes.forEach((box, idx) => {
    box.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(-1);
        if (this.value && idx < boxes.length - 1) {
            boxes[idx + 1].focus();
        }
        if ([...boxes].every(b => b.value.length === 1)) {
            setTimeout(() => document.getElementById('otpForm').submit(), 300);
        }
    });

    // ✅ Paste handler
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
