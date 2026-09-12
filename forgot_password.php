<?php
// ================================================
// forgot_password.php – نسيت كلمة المرور
// ================================================
require_once 'db_config.php';
require_once 'config.php';
session_start();

// ── Arabic date ──────────────────────────────────
$ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$today = $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if (!empty($_SESSION['reset_error'])) {
    $error = $_SESSION['reset_error'];
    unset($_SESSION['reset_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'يرجى إدخال البريد الإلكتروني';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'البريد الإلكتروني غير صحيح';
    } else {
        try {
            $conn = get_db();
            $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                // توليد OTP لإعادة التعيين
                $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiry = time() + (15 * 60); // 15 دقيقة

                $_SESSION['reset_otp']        = $otp;
                $_SESSION['reset_email']      = $email;
                $_SESSION['reset_expiry']     = $expiry;
                $_SESSION['reset_user_id']    = $user['id'];
                $_SESSION['reset_full_name']  = $user['full_name'];
                $_SESSION['reset_attempts']   = 0;

                // إرسال OTP
                $_SESSION['pending_user'] = ['full_name' => $user['full_name'], 'email' => $email];
                header('Location: reset_send.php');
                exit;
            } else {
                // Security Fix: Prevent user enumeration by showing a generic message
                // However, to keep it simple for the user, we can also redirect to a "check your email" page
                // or just act as if it was sent.
                $_SESSION['reset_email_sent_generic'] = true;
                header('Location: reset_send.php');
                exit;
            }
        } catch (Exception $e) {
            $error = 'حدث خطأ، يرجى المحاولة لاحقاً';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نسيت كلمة المرور – كنيسة العذراء والملاك ميخائيل</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="login.css">
    <style>
        .forgot-container {
            max-width: 460px;
            margin: 40px auto;
            padding: 40px 30px;
        }
        .back-link {
            color: var(--gold);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 20px;
        }
    </style>
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
    <nav>
        <a href="login.php">تسجيل الدخول</a>
    </nav>
</header>

<main>
    <div class="card forgot-container">

        <a href="login.php" class="back-link">← العودة لتسجيل الدخول</a>

        <h2>نسيت كلمة المرور؟</h2>
        <p class="subtitle">أدخل بريدك الإلكتروني المسجل وسيتم إرسال كود التحقق</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <div class="field">
                <label for="email">البريد الإلكتروني</label>
                <input type="email" id="email" name="email" 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                       placeholder="example@email.com" required autofocus>
            </div>

            <button type="submit" class="btn-submit">إرسال كود التحقق</button>
        </form>
    </div>
</main>

</body>
</html>
