<?php
// ================================================
// index.php – تسجيل الدخول مع حماية Brute-Force
// ================================================
require_once 'db_config.php';
 
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

require_once 'lang.php';

// ── Language switching ──────────────────────────
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['language'] = $_GET['lang'];
}
$lang     = $_SESSION['language'] ?? 'ar';
$t        = $translations[$lang];
$isRtl    = ($lang === 'ar');
$htmlLang = $isRtl ? 'ar' : 'en';
$htmlDir  = $isRtl ? 'rtl' : 'ltr';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
 
// ══════════════════════════════════════════════
// ── إعدادات Brute-Force ───────────────────────
// ══════════════════════════════════════════════
define('BF_MAX_ATTEMPTS',  5);   // أقصى عدد محاولات فاشلة
define('BF_WINDOW_MINUTES', 15); // نافذة الزمن بالدقائق
define('BF_LOCKOUT_MINUTES', 30);// مدة الحظر بالدقائق
 
/**
 * جلب عنوان IP الحقيقي للزائر
 */
function get_client_ip(): string {
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}
 
/**
 * هل هذا الـ identifier أو الـ IP محظور حالياً؟
 * يرجع: 0 = مسموح | عدد الثواني المتبقية للحظر
 */
function get_lockout_seconds(mysqli $conn, string $identifier, string $ip): int {
    $window  = BF_LOCKOUT_MINUTES * 60;
    $since   = date('Y-m-d H:i:s', time() - $window);
 
    // نعد المحاولات الفاشلة للـ identifier أ   و الـ IP خلال نافذة الحظر
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt, MIN(attempted_at) AS first_attempt
        FROM login_attempts
        WHERE success = 0
          AND attempted_at >= ?
          AND (identifier = ? OR ip_address = ?)
    ");
    $stmt->bind_param('sss', $since, $identifier, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
 
    if ($row['cnt'] < BF_MAX_ATTEMPTS) return 0;
 
    // حساب الوقت المتبقي بناءً على أول محاولة فاشلة في النافذة
    $first_ts   = strtotime($row['first_attempt']);
    $unlock_ts  = $first_ts + ($window);
    $remaining  = $unlock_ts - time();
 
    return max(0, $remaining);
}
 
/**
 * تسجيل محاولة دخول (ناجحة أو فاشلة) في جدول login_attempts
 */
function log_attempt(mysqli $conn, string $identifier, string $ip, bool $success): void {
    $stmt = $conn->prepare("
        INSERT INTO login_attempts (identifier, ip_address, attempted_at, success)
        VALUES (?, ?, NOW(), ?)
    ");
    $s = $success ? 1 : 0;
    $stmt->bind_param('ssi', $identifier, $ip, $s);
    $stmt->execute();
    $stmt->close();
}
 
/**
 * تنظيف السجلات القديمة (أكبر من ضعف نافذة الزمن) – اختياري لكن مهم
 */
function cleanup_old_attempts(mysqli $conn): void {
    $cutoff = date('Y-m-d H:i:s', time() - (BF_WINDOW_MINUTES * 60 * 2));
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < '$cutoff'");
}
 
// ══════════════════════════════════════════════
// ── معالجة POST ───────────────────────────────
// ══════════════════════════════════════════════
$error   = '';
$success = '';
$lockout_seconds = 0;
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = trim($_POST['password']   ?? '');
 
    if (empty($identifier) || empty($password)) {
        $error = 'يرجى ملء جميع الحقول';
    } else {
        try {
            $conn = get_db();
            $ip   = get_client_ip();
 
            // ── 1. فحص الحظر قبل أي شيء ──────────────
            $lockout_seconds = get_lockout_seconds($conn, $identifier, $ip);
 
            if ($lockout_seconds > 0) {
                $minutes = ceil($lockout_seconds / 60);
                $error = "تم تجاوز الحد المسموح من المحاولات. يرجى الانتظار {$minutes} دقيقة قبل المحاولة مجدداً.";
 
            } else {
                // ── 2. التحقق من بيانات المستخدم ──────
                $stmt = $conn->prepare(
                    "SELECT id, username, email, full_name, phone, password_hash, role, family_name
                     FROM users
                     WHERE (username = ? OR email = ?) AND is_active = 1
                     LIMIT 1"
                );
                $stmt->bind_param('ss', $identifier, $identifier);
                $stmt->execute();
                $result = $stmt->get_result();
 
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
 
                    if (password_verify($password, $user['password_hash'])) {
                        // ── 3. Check for 2FA ──────────────
                        $stmt2 = $conn->prepare("SELECT two_factor_enabled, two_factor_secret FROM users WHERE id = ?");
                        $stmt2->bind_param("i", $user['id']);
                        $stmt2->execute();
                        $res2fa = $stmt2->get_result()->fetch_assoc();
                        $stmt2->close();

                        if ($res2fa && $res2fa['two_factor_enabled']) {
                            // Store ALL user data needed for session after 2FA verification
                            $_SESSION['pending_2fa_user'] = [
                                'id'          => $user['id'],
                                'username'    => $user['username'],
                                'email'       => $user['email'],
                                'full_name'   => $user['full_name'],
                                'role'        => $user['role'],
                                'family_name' => $user['family_name'],
                                'phone'       => $user['phone'] ?? ''
                            ];
                            $_SESSION['pending_2fa_secret'] = $res2fa['two_factor_secret'];
                            header('Location: verify_2fa.php');
                            exit;
                        }

                        // ✅ نجاح – تسجيل المحاولة الناجحة
                        log_attempt($conn, $identifier, $ip, true);
                        cleanup_old_attempts($conn);
 
                        session_regenerate_id(true);
                        $_SESSION['user_id']     = $user['id'];
                        $_SESSION['username']    = $user['username'];
                        $_SESSION['full_name']   = $user['full_name'];
                        $_SESSION['role']        = $user['role'];
                        $_SESSION['family_name'] = $user['family_name'];
 
                        $upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $upd->bind_param('i', $user['id']);
                        $upd->execute();
                        $upd->close();
 
                        $stmt->close();
                        $conn->close();
 
                        header('Location: dashboard.php');
                        exit;
 
                    } else {
                        // ❌ كلمة مرور خاطئة
                        log_attempt($conn, $identifier, $ip, false);
 
                        // إعادة فحص الحظر بعد التسجيل مباشرةً
                        $lockout_seconds = get_lockout_seconds($conn, $identifier, $ip);
                        if ($lockout_seconds > 0) {
                            $minutes = ceil($lockout_seconds / 60);
                            $error = "تم تجاوز الحد المسموح من المحاولات. حسابك محظور لمدة {$minutes} دقيقة.";
                        } else {
                            // احسب كم محاولة تبقت
                            $window = date('Y-m-d H:i:s', time() - (BF_WINDOW_MINUTES * 60));
                            $chk = $conn->prepare("
                                SELECT COUNT(*) AS cnt FROM login_attempts
                                WHERE success = 0 AND attempted_at >= ?
                                  AND (identifier = ? OR ip_address = ?)
                            ");
                            $chk->bind_param('sss', $window, $identifier, $ip);
                            $chk->execute();
                            $cnt = $chk->get_result()->fetch_assoc()['cnt'];
                            $chk->close();
 
                            $remaining = BF_MAX_ATTEMPTS - $cnt;
                            if ($remaining > 0) {
                                $error = "كلمة المرور غير صحيحة. تبقى لك {$remaining} محاولة قبل الحظر المؤقت.";
                            } else {
                                $error = "كلمة المرور غير صحيحة.";
                            }
                        }
                    }
                } else {
                    // ❌ مستخدم غير موجود – سجّل المحاولة أيضاً
                    log_attempt($conn, $identifier, $ip, false);
                    $error = 'المستخدم غير موجود أو الحساب غير مفعّل';
                }
 
                $stmt->close();
            }
 
            $conn->close();
 
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}
 
// ── Arabic date helper ────────────────────────────
$ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو',
              'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$today = $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>" dir="<?= $htmlDir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t['login_page_title'] ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="login.css?v=<?= filemtime('login.css') ?>">
    <?php if ($lockout_seconds > 0): ?>
    <style>
        .lockout-timer { font-size: 2rem; font-weight: 700; color: var(--gold); letter-spacing: 2px; margin: 8px 0 0; }
        .alert-error { border-right: 4px solid #c0392b; }
    </style>
    <?php endif; ?>
</head>
<body style="direction: <?= $htmlDir ?>;">
 
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
            <h1><?= $t['church_name'] ?></h1>
            <span class="header-date"><?= htmlspecialchars($today) ?></span>
        </div>
    </div>
    <nav>
        <a href="?lang=ar" class="lang-switch <?= $lang === 'ar' ? 'active' : '' ?>"><?= $t['language_ar'] ?></a>
        <a href="?lang=en" class="lang-switch <?= $lang === 'en' ? 'active' : '' ?>"><?= $t['language_en'] ?></a>
        <a href="register.php" class="btn-outline"><?= $t['create_account_btn'] ?></a>
        <a href="contact.php"><?= $t['contact_us'] ?></a>
    </nav>
</header>
 
<main>
    <div class="card">
 
        <svg class="cross-icon" viewBox="0 0 60 70" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="26" y="2"  width="8" height="66" rx="3" fill="#C8973A"/>
            <rect x="8"  y="22" width="44" height="8" rx="3" fill="#C8973A"/>
            <circle cx="30" cy="26" r="6" fill="#FDFAF4" stroke="#C8973A" stroke-width="1.5"/>
            <circle cx="30" cy="4"  r="4" fill="none"   stroke="#C8973A" stroke-width="1.5"/>
        </svg>
 
        <h2><?= $t['church_subtitle'] ?></h2>
        <p class="subtitle"><?= $t['welcome_back'] ?></p>
 
        <?php if ($error): ?>
            <div class="alert alert-error">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <?= htmlspecialchars($error) ?>
                <?php if ($lockout_seconds > 0): ?>
                    <div class="lockout-timer" id="lockoutTimer"></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
 
        <?php if ($success): ?>
            <div class="alert alert-success">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/>
                </svg>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
 
        <form method="POST" action="" class="login-form" id="loginForm" novalidate>
 
            <div class="field">
                <label for="identifier"><?= $t['username_or_email'] ?></label>
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    placeholder="<?= $t['username_placeholder'] ?>"
                    value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                    autocomplete="username"
                    <?= $lockout_seconds > 0 ? 'disabled' : '' ?>
                    required
                >
            </div>
 
            <div class="field">
                <label for="password"><?= $t['password'] ?></label>
                <div class="pw-wrapper">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="<?= $t['password_placeholder'] ?>"
                        autocomplete="current-password"
                        <?= $lockout_seconds > 0 ? 'disabled' : '' ?>
                        required
                    >
                    <button type="button" class="pw-toggle" id="pwToggle" title="<?= $t['show_hide_pw'] ?>">
                        <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>
 
            <button type="submit" class="btn-submit" id="submitBtn"
                <?= $lockout_seconds > 0 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                <?= $t['login_btn'] ?>
            </button>
 
        </form>
 
        <a href="forgot_password.php" class="forgot"><?= $t['forgot_password'] ?></a>

        <hr class="divider">
        <p class="register-link"><?= $t['no_account'] ?> <a href="register.php"><?= $t['create_account'] ?></a></p>
 
    </div>
</main>
 
<script>
// ── إظهار/إخفاء كلمة المرور ──
document.getElementById('pwToggle').addEventListener('click', function() {
    const pw  = document.getElementById('password');
    const eye = document.getElementById('eyeIcon');
    if (pw.type === 'password') {
        pw.type = 'text';
        eye.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`;
    } else {
        pw.type = 'password';
        eye.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
    }
});
 
<?php if ($lockout_seconds > 0): ?>
// ── العداد التنازلي لرفع الحظر ──
(function() {
    let remaining = <?= (int)$lockout_seconds ?>;
    const timerEl = document.getElementById('lockoutTimer');
    const submitBtn = document.getElementById('submitBtn');
 
    function fmt(s) {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return (m > 0 ? m + 'د ' : '') + sec + 'ث';
    }
 
    timerEl.textContent = fmt(remaining);
 
    const iv = setInterval(function() {
        remaining--;
        if (remaining <= 0) {
            clearInterval(iv);
            timerEl.textContent = '';
            // رفع الحظر من جانب العميل
            document.getElementById('identifier').disabled = false;
            document.getElementById('password').disabled   = false;
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor  = 'pointer';
            timerEl.closest('.alert').innerHTML =
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg> انتهى وقت الحظر، يمكنك المحاولة الآن.';
            timerEl.closest('.alert').className = 'alert alert-success';
        } else {
            timerEl.textContent = fmt(remaining);
        }
    }, 1000);
})();
<?php endif; ?>
</script>
 
</body>
</html>
