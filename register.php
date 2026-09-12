<?php
// ================================================
// register.php – إنشاء حساب جديد (معالجة آمنة)
// ================================================
require_once 'db_config.php';
require_once 'config.php';

// ── Session Handling ──────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
function register_set_language_from_request() {
    if (isset($_GET['lang'])) {
        $_SESSION['language'] = ($_GET['lang'] === 'en') ? 'en' : 'ar';
    }
    return ($_SESSION['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
}

function register_date_label($lang) {
    if ($lang === 'en') {
        return date('l, F j, Y');
    }
    $ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
    $ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    return $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');
}

function register_lang_switch($current, $target) {
    $label = $target === 'en' ? 'ENGLISH' : 'العربية';
    $class = $current === $target ? ' lang-active' : '';
    $query = $_GET;
    $query['lang'] = $target;
    return '<a class="lang-pill' . $class . '" href="register.php?' . htmlspecialchars(http_build_query($query)) . '">' . $label . '</a>';
}

$lang = register_set_language_from_request();
$isRtl = ($lang === 'ar');
$htmlLang = $isRtl ? 'ar' : 'en';
$htmlDir = $isRtl ? 'rtl' : 'ltr';

$translations = [
    'ar' => [
        'page_title' => 'إنشاء حساب – كنيسة العذراء والملاك ميخائيل',
        'church_name' => 'كنيسة العذراء والملاك ميخائيل',
        'login' => 'تسجيل الدخول',
        'contact' => 'تواصل معنا',
        'create_account' => 'إنشاء حساب جديد',
        'error_label' => 'خطأ:',
        'personal_data' => 'البيانات الشخصية',
        'full_name' => 'الاسم الكامل',
        'full_name_ph' => 'مثال: جورج مينا سمير',
        'phone' => 'رقم الهاتف',
        'account_data' => 'بيانات الحساب',
        'username' => 'اسم المستخدم',
        'username_ph' => 'أحرف إنجليزية وأرقام فقط',
        'email' => 'البريد الإلكتروني',
        'role' => 'الدور / الخدمة في الكنيسة',
        'servant' => 'خادم',
        'admin' => 'أمين الخدمة',
        'family' => 'اسم الأسرة',
        'family_ph' => 'اختر اسم الاسرة...',
        'class' => 'الفصل الذي تخدم فيه',
        'class_ph' => 'اختر الفصل...',
        'password' => 'كلمة المرور',
        'password_ph' => '8 أحرف على الأقل',
        'password_confirm' => 'تأكيد كلمة المرور',
        'password_confirm_ph' => 'أعد كتابة كلمة المرور',
        'submit' => 'إنشاء الحساب',
        'have_account' => 'لديك حساب بالفعل؟',
        'required_all' => 'يرجى ملء جميع الحقول الإلزامية',
        'invalid_email' => 'البريد الإلكتروني غير صحيح',
        'short_password' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
        'weak_password' => 'كلمة المرور ضعيفة جداً. يجب أن تحتوي على حروف كبيرة وصغيرة وأرقام.',
        'password_mismatch' => 'كلمة المرور وتأكيدها غير متطابقتين',
        'invalid_username' => 'اسم المستخدم: أحرف إنجليزية وأرقام فقط (3-30 حرف)',
        'missing_class' => 'يرجى اختيار الفصل الخاص بالخادم',
        'duplicate_user' => 'اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل',
        'db_error' => 'خطأ في قاعدة البيانات: ',
    ],
    'en' => [
        'page_title' => 'Create Account - Virgin Mary and Archangel Michael Church',
        'church_name' => 'Virgin Mary and Archangel Michael Church',
        'login' => 'Login',
        'contact' => 'Contact Us',
        'create_account' => 'Create New Account',
        'error_label' => 'Error:',
        'personal_data' => 'Personal Data',
        'full_name' => 'Full Name',
        'full_name_ph' => 'Example: George Mina Samir',
        'phone' => 'Phone Number',
        'account_data' => 'Account Data',
        'username' => 'Username',
        'username_ph' => 'English letters and numbers only',
        'email' => 'Email Address',
        'role' => 'Role / Church Service',
        'servant' => 'Servant',
        'admin' => 'Service Leader',
        'family' => 'Family Name',
        'family_ph' => 'Choose family...',
        'class' => 'Class You Serve',
        'class_ph' => 'Choose class...',
        'password' => 'Password',
        'password_ph' => 'At least 8 characters',
        'password_confirm' => 'Confirm Password',
        'password_confirm_ph' => 'Retype your password',
        'submit' => 'Create Account',
        'have_account' => 'Already have an account?',
        'required_all' => 'Please fill all required fields.',
        'invalid_email' => 'Email address is invalid.',
        'short_password' => 'Password must be at least 8 characters.',
        'weak_password' => 'Password is too weak. It must contain uppercase letters, lowercase letters, and numbers.',
        'password_mismatch' => 'Password and confirmation do not match.',
        'invalid_username' => 'Username must contain English letters and numbers only (3-30 characters).',
        'missing_class' => 'Please choose the servant class.',
        'duplicate_user' => 'Username or email is already used.',
        'db_error' => 'Database error: ',
    ],
];
$t = $translations[$lang];

function get_family_classes($family_name) {
    $g1 = ['أسرة ني انجيلوس', 'أسرة ملايكه', 'أسرة سمائيين', 'أسرة شهداء', 'أسرة قديسين'];
    $g2 = ['أسرة لباس الصليب', 'أسرة قديسات', 'أسرة ابطال الايمان', 'أسرة ابطل الايمان', 'أسرة شهيدات', 'أسرة الرسل الجامعيه', 'أسرة الرسل الجامعية', 'أسرة الانبا ابرأم للخريجيين', 'أسرة الانبا ابرأم للخريجين'];

    if (in_array($family_name, $g1, true)) {
        return [
            'مارمرقس', 'مارجرجس', 'تادرس الشطبي', 'أبو سيفين', 'مارمينا', 'أبانوب',
            'الأنبا أنطونيوس', 'الأنبا بولا', 'الأنبا بيشوي', 'أبو مقار', 'الأنبا شنودة',
            'كيرلس السادس', 'الملاك ميخائيل', 'الملاك غبريال', 'العذراء مريم', 'دميانة'
        ];
    } elseif (in_array($family_name, $g2, true)) {
        return [
            'بولس الرسول', 'بطرس الرسول', 'يوحنا الحبيب', 'مارمرقس الرسول', 'أندراوس الرسول',
            'فيلبس الرسول', 'توما الرسول', 'متى الرسول', 'يعقوب بن زبدي', 'يعقوب بن حلفى',
            'تداوس الرسول', 'سمعان القانوي', 'متياس الرسول', 'استفانوس', 'الأنبا موسى الأسود',
            'الأنبا تكلا هيمانوت', 'الأنبا صموئيل المعترف', 'الأنبا بيشاي', 'القديس أوغسطينوس',
            'القديسة مونيكا', 'القديسة فيلومينا', 'القديسة بربارة', 'القديسة كاترين', 'القديسة رفقة'
        ];
    }
    return [];
}

// ── معالجة POST ──────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $username         = trim($_POST['username'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $role             = $_POST['role'] ?? 'خادم';
    $family_name      = $_POST['churchFamily'] ?? '';
    $language         = ($_POST['language'] ?? $lang) === 'en' ? 'en' : 'ar';
    $class_name       = trim($_POST['class_name'] ?? '');

    if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
        $error = $t['required_all'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = $t['invalid_email'];
    } elseif (strlen($password) < 8) {
        $error = $t['short_password'];
    } elseif (!preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)) {
        $error = $t['weak_password'];
    } elseif ($password !== $password_confirm) {
        $error = $t['password_mismatch'];
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = $t['invalid_username'];
    } elseif ($role === 'خادم' && $class_name === '') {
        $error = $t['missing_class'];
    } else {
        try {
            $conn = get_db();

            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $chk->bind_param('ss', $username, $email);
            $chk->execute();
            $chk->store_result();

            if ($chk->num_rows > 0) {
                $error = $t['duplicate_user'];
                $chk->close();
                $conn->close();
            } else {
                $_SESSION['pending_user'] = [
                    'username'    => $username,
                    'email'       => strtolower($email),
                    'password'    => password_hash($password, PASSWORD_DEFAULT),
                    'full_name'   => $full_name,
                    'phone'       => $phone,
                    'role'        => $role,
                    'family_name' => $family_name,
                    'class_name'  => $role === 'خادم' ? $class_name : '',
                    'language'    => $language,
                ];
                $_SESSION['language'] = $language;

                $chk->close();
                $conn->close();

                header('Location: otp_send.php');
                exit;
            }

        } catch (Exception $e) {
            $error = $t['db_error'] . htmlspecialchars($e->getMessage());
        }
    }
}

$today = register_date_label($lang);

function old(string $key): string {
    return htmlspecialchars($_POST[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>" dir="<?= $htmlDir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($t['page_title']) ?></title>
    <link rel="stylesheet" href="login.css?v=6">
    <link rel="stylesheet" href="register.css?v=6">
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
            <h1><?= htmlspecialchars($t['church_name']) ?></h1>
            <span class="header-date"><?= htmlspecialchars($today) ?></span>
        </div>
    </div>
    <nav>
        <a href="login.php"><?= htmlspecialchars($t['login']) ?></a>
        <a href="contact.php"><?= htmlspecialchars($t['contact']) ?></a>
        <div class="auth-lang-switch">
            <?= register_lang_switch($lang, 'ar') ?>
            <?= register_lang_switch($lang, 'en') ?>
        </div>
    </nav>
</header>

<main class="main-register">
    <div class="card card-wide">

        <svg class="cross-icon" viewBox="0 0 60 70" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="26" y="2"  width="8" height="66" rx="3" fill="#C8973A"/>
            <rect x="8"  y="22" width="44" height="8" rx="3" fill="#C8973A"/>
            <circle cx="30" cy="26" r="6" fill="#FDFAF4" stroke="#C8973A" stroke-width="1.5"/>
            <circle cx="30" cy="4"  r="4" fill="none"   stroke="#C8973A" stroke-width="1.5"/>
        </svg>

        <h2><?= htmlspecialchars($t['create_account']) ?></h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <strong><?= htmlspecialchars($t['error_label']) ?></strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

<form method="POST" action="" class="login-form reg-form" id="regForm" novalidate>
    <input type="hidden" name="language" value="<?= htmlspecialchars($lang) ?>">

    <!-- Personal Data -->
    <div class="form-section">
        <div class="section-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($t['personal_data']) ?>
        </div>
        <div class="fields-grid">
            <div class="field">
                <label for="full_name"><?= htmlspecialchars($t['full_name']) ?> <span class="required">*</span></label>
                <input type="text" id="full_name" name="full_name"
                       placeholder="<?= htmlspecialchars($t['full_name_ph']) ?>"
                       value="<?= old('full_name') ?>" required>
            </div>
            <div class="field">
                <label for="phone"><?= htmlspecialchars($t['phone']) ?> <span class="required">*</span></label>
                <input type="tel" id="phone" name="phone"
                       placeholder="01xxxxxxxxx"
                       value="<?= old('phone') ?>" dir="ltr" required>
            </div>
        </div>
    </div>

    <!-- Account Data -->
    <div class="form-section">
        <div class="section-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <?= htmlspecialchars($t['account_data']) ?>
        </div>

        <!-- Row 1: Username + Email -->
        <div class="fields-grid">
            <div class="field">
                <label for="username"><?= htmlspecialchars($t['username']) ?> <span class="required">*</span></label>
                <input type="text" id="username" name="username"
                       placeholder="<?= htmlspecialchars($t['username_ph']) ?>"
                       value="<?= old('username') ?>" dir="ltr"
                       autocomplete="username" required>
            </div>
            <div class="field">
                <label for="email"><?= htmlspecialchars($t['email']) ?> <span class="required">*</span></label>
                <input type="email" id="email" name="email"
                       placeholder="example@mail.com"
                       value="<?= old('email') ?>" dir="ltr"
                       autocomplete="email" required>
            </div>
        </div>

        <!-- Row 2: Role + Family -->
        <div class="fields-grid" style="margin-top:16px;">
            <div class="form-section role-section">
                <label class="section-title"><?= htmlspecialchars($t['role']) ?> *</label>
                <div class="radio-group">
                    <label class="radio-card">
                        <input type="radio" name="role" value="خادم" required <?php echo (!isset($_POST['role']) || $_POST['role'] == 'خادم') ? 'checked' : ''; ?>>
                        <div class="radio-content">
                            <span class="radio-text"><?= htmlspecialchars($t['servant']) ?></span>
                        </div>
                    </label>
                    <label class="radio-card">
                        <input type="radio" name="role" value="أمين الخدمة" <?php echo (isset($_POST['role']) && $_POST['role'] == 'أمين الخدمة') ? 'checked' : ''; ?>>
                        <div class="radio-content">
                            <span class="radio-text"><?= htmlspecialchars($t['admin']) ?></span>
                        </div>
                    </label>
                </div>
            </div>
            <div class="form-section family-section">
                <label for="churchFamily" class="section-title"><?= htmlspecialchars($t['family']) ?> *</label>
                <div class="select-wrapper">
                    <select name="churchFamily" id="churchFamily" required>
                        <option value="" disabled <?php echo !isset($_POST['churchFamily']) ? 'selected' : ''; ?>><?= htmlspecialchars($t['family_ph']) ?></option>
                        <option value="أسرة ني انجيلوس" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة ني انجيلوس') ? 'selected' : ''; ?>>أسرة ني انجيلوس</option>
                        <option value="أسرة ملايكه" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة ملايكه') ? 'selected' : ''; ?>>أسرة ملايكه</option>
                        <option value="أسرة سمائيين" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة سمائيين') ? 'selected' : ''; ?>>أسرة سمائيين</option>
                        <option value="أسرة شهداء" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة شهداء') ? 'selected' : ''; ?>>أسرة شهداء</option>
                        <option value="أسرة قديسين" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة قديسين') ? 'selected' : ''; ?>>أسرة قديسين</option>
                        <option value="أسرة لباس الصليب" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة لباس الصليب') ? 'selected' : ''; ?>>أسرة لباس الصليب</option>
                        <option value="أسرة قديسات" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة قديسات') ? 'selected' : ''; ?>>أسرة قديسات</option>
                        <option value="أسرة ابطال الايمان" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة ابطال الايمان') ? 'selected' : ''; ?>>أسرة ابطال الايمان</option>
                        <option value="أسرة شهيدات" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة شهيدات') ? 'selected' : ''; ?>>أسرة شهيدات</option>
                        <option value="أسرة الرسل الجامعية" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة الرسل الجامعية') ? 'selected' : ''; ?>>أسرة الرسل الجامعية</option>
                        <option value="أسرة الانبا ابرأم للخريجين" <?php echo (isset($_POST['churchFamily']) && $_POST['churchFamily'] == 'أسرة الانبا ابرأم للخريجين') ? 'selected' : ''; ?>>أسرة الانبا ابرأم للخريجين</option>
                    </select>
                    <div class="select-arrow">▼</div>
                </div>
            </div>
        </div>

        <!-- Row 3: Class (full width, servant only) -->
        <div class="form-section class-section" id="classSection" style="margin-top:16px; <?= (!isset($_POST['role']) || $_POST['role'] === 'خادم') ? '' : 'display:none;' ?>">
            <label for="class_name" class="section-title"><?= htmlspecialchars($t['class']) ?> *</label>
            <div class="select-wrapper" style="max-width:100%;">
                <select name="class_name" id="class_name" style="width:100%;">
                    <option value="" disabled <?= empty($_POST['class_name']) ? 'selected' : '' ?>><?= htmlspecialchars($t['class_ph']) ?></option>
                    <?php
                    $selected_family = $_POST['churchFamily'] ?? '';
                    $selected_class  = $_POST['class_name'] ?? '';
                    foreach (get_family_classes($selected_family) as $cls):
                    ?>
                        <option value="<?= htmlspecialchars($cls) ?>" <?= $selected_class === $cls ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cls) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="select-arrow">▼</div>
            </div>
        </div>

        <!-- Row 4: Password + Confirm Password (equal side by side) -->
        <div class="fields-grid" style="margin-top:16px;">
            <div class="field">
                <label for="password"><?= htmlspecialchars($t['password']) ?> <span class="required">*</span></label>
                <div class="pw-wrapper">
                    <input type="password" id="password" name="password"
                           placeholder="<?= htmlspecialchars($t['password_ph']) ?>"
                           autocomplete="new-password" required>
                    <button type="button" class="pw-toggle" data-target="password">
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
                <label for="password_confirm"><?= htmlspecialchars($t['password_confirm']) ?> <span class="required">*</span></label>
                <div class="pw-wrapper">
                    <input type="password" id="password_confirm" name="password_confirm"
                           placeholder="<?= htmlspecialchars($t['password_confirm_ph']) ?>"
                           autocomplete="new-password" required>
                    <button type="button" class="pw-toggle" data-target="password_confirm">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <span class="match-label" id="matchLabel"></span>
            </div>
        </div>

    </div><!-- end account form-section -->

    <button type="submit" class="btn-submit" id="submitBtn"><?= htmlspecialchars($t['submit']) ?></button>

</form>
    <div>
        <hr class="divider">
        <p class="register-link"><?= htmlspecialchars($t['have_account']) ?> <a href="login.php"><?= htmlspecialchars($t['login']) ?></a></p>
    </div>
</main>

<script src="register.js"></script>
<script>
window.registerLang = <?= json_encode($lang) ?>;
window.registerText = <?= json_encode([
    'classPlaceholder' => $t['class_ph'],
    'creating' => $lang === 'en' ? 'Creating...' : 'جاري الإنشاء...',
    'passwordMatch' => $lang === 'en' ? '✓ Passwords match' : '✓ كلمتا المرور متطابقتان',
    'passwordMismatch' => $lang === 'en' ? '✗ Passwords do not match' : '✗ كلمتا المرور غير متطابقتين',
    'strength' => $lang === 'en'
        ? ['Very weak', 'Weak', 'Medium', 'Good', 'Strong']
        : ['ضعيف جداً', 'ضعيف', 'متوسط', 'جيد', 'قوي'],
], JSON_UNESCAPED_UNICODE) ?>;
window.familyClasses = <?= json_encode([
    'أسرة ني انجيلوس' => get_family_classes('أسرة ني انجيلوس'),
    'أسرة ملايكه' => get_family_classes('أسرة ملايكه'),
    'أسرة سمائيين' => get_family_classes('أسرة سمائيين'),
    'أسرة شهداء' => get_family_classes('أسرة شهداء'),
    'أسرة قديسين' => get_family_classes('أسرة قديسين'),
    'أسرة لباس الصليب' => get_family_classes('أسرة لباس الصليب'),
    'أسرة قديسات' => get_family_classes('أسرة قديسات'),
    'أسرة ابطال الايمان' => get_family_classes('أسرة ابطال الايمان'),
    'أسرة شهيدات' => get_family_classes('أسرة شهيدات'),
    'أسرة الرسل الجامعية' => get_family_classes('أسرة الرسل الجامعية'),
    'أسرة الانبا ابرأم للخريجين' => get_family_classes('أسرة الانبا ابرأم للخريجين'),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
</body>
</html>
