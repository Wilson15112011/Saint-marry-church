<?php
// ================================================
// lang.php – Shared translations for login/register
// ================================================

$translations = [];

// ── Arabic ──────────────────────────────────────
$translations['ar'] = [
    // Common
    'church_name'      => 'كنيسة العذراء والملاك ميخائيل',
    'church_subtitle'  => 'كنيسة العذراء والملاك ميخائيل بالخلفاوي',
    'contact_us'       => 'تواصل معنا',
    'language_ar'      => 'العربية',
    'language_en'      => 'English',

    // Login page
    'login_page_title' => 'تسجيل الدخول – كنيسة العذراء والملاك ميخائيل',
    'welcome_back'     => 'أهلاً بك',
    'username_or_email' => 'اسم المستخدم أو البريد الإلكتروني',
    'username_placeholder' => 'أدخل اسم المستخدم أو البريد',
    'password'          => 'كلمة المرور',
    'password_placeholder' => 'أدخل كلمة المرور',
    'login_btn'         => 'تسجيل الدخول',
    'forgot_password'   => 'نسيت كلمة المرور؟',
    'no_account'        => 'ليس لديك حساب؟',
    'create_account'    => 'إنشاء حساب جديد',
    'show_hide_pw'      => 'إظهار / إخفاء كلمة المرور',
    'create_account_btn'=> 'إنشاء حساب',

    // Register page
    'register_page_title' => 'إنشاء حساب – كنيسة العذراء والملاك ميخائيل',
    'register_title'    => 'إنشاء حساب جديد',
    'personal_info'     => 'البيانات الشخصية',
    'full_name'         => 'الاسم الكامل',
    'full_name_placeholder' => 'مثال: جورج مينا سمير',
    'phone'             => 'رقم الهاتف',
    'phone_placeholder' => '01xxxxxxxxx',
    'account_info'      => 'بيانات الحساب',
    'username'          => 'اسم المستخدم',
    'username_register_placeholder' => 'أحرف إنجليزية وأرقام فقط',
    'email'             => 'البريد الإلكتروني',
    'email_placeholder' => 'example@mail.com',
    'role'              => 'الدور / الخدمة في الكنيسة',
    'role_servant'      => 'خادم',
    'role_admin'        => 'أمين الخدمة',
    'family_name'       => 'اسم الأسرة',
    'family_placeholder'=> 'اختر اسم الاسرة...',
    'class_name'        => 'الفصل الذي تخدم فيه',
    'class_placeholder' => '— اختر فصلك —',
    'password_register' => 'كلمة المرور',
    'password_register_placeholder' => '8 أحرف على الأقل',
    'confirm_password'  => 'تأكيد كلمة المرور',
    'confirm_password_placeholder' => 'أعد كتابة كلمة المرور',
    'register_btn'      => 'إنشاء الحساب',
    'has_account'       => 'لديك حساب بالفعل؟',
    'login_link'        => 'تسجيل الدخول',
    'password_strength' => [
        'very_weak' => 'ضعيف جداً',
        'weak'      => 'ضعيف',
        'medium'    => 'متوسط',
        'good'      => 'جيد',
        'strong'    => 'قوي',
    ],
    'passwords_match'   => '✓ كلمتا المرور متطابقتان',
    'passwords_mismatch'=> '✗ كلمتا المرور غير متطابقتين',
    'required'          => '*',
    'submitting'        => 'جاري الإنشاء...',
    'select_family'     => 'اختر اسم الاسرة...',
];

// ── English ──────────────────────────────────────
$translations['en'] = [
    // Common
    'church_name'      => 'Church of Virgin Mary & Archangel Michael',
    'church_subtitle'  => 'Church of Virgin Mary & Archangel Michael – Al-Khalfawy',
    'contact_us'       => 'Contact Us',
    'language_ar'      => 'العربية',
    'language_en'      => 'English',

    // Login page
    'login_page_title' => 'Login – Church of Virgin Mary & Archangel Michael',
    'welcome_back'     => 'Welcome back',
    'username_or_email' => 'Username or Email',
    'username_placeholder' => 'Enter username or email',
    'password'          => 'Password',
    'password_placeholder' => 'Enter your password',
    'login_btn'         => 'Sign In',
    'forgot_password'   => 'Forgot password?',
    'no_account'        => "Don't have an account?",
    'create_account'    => 'Create New Account',
    'show_hide_pw'      => 'Show / Hide password',
    'create_account_btn'=> 'Create Account',

    // Register page
    'register_page_title' => 'Register – Church of Virgin Mary & Archangel Michael',
    'register_title'    => 'Create New Account',
    'personal_info'     => 'Personal Information',
    'full_name'         => 'Full Name',
    'full_name_placeholder' => 'e.g. George Mina Samir',
    'phone'             => 'Phone Number',
    'phone_placeholder' => '01xxxxxxxxx',
    'account_info'      => 'Account Information',
    'username'          => 'Username',
    'username_register_placeholder' => 'English letters and numbers only',
    'email'             => 'Email',
    'email_placeholder' => 'example@mail.com',
    'role'              => 'Role / Service',
    'role_servant'      => 'Servant',
    'role_admin'        => 'Service Secretary',
    'family_name'       => 'Family Name',
    'family_placeholder'=> 'Select a family...',
    'class_name'        => 'Your Class',
    'class_placeholder' => '— Select your class —',
    'password_register' => 'Password',
    'password_register_placeholder' => 'At least 8 characters',
    'confirm_password'  => 'Confirm Password',
    'confirm_password_placeholder' => 'Re-enter password',
    'register_btn'      => 'Create Account',
    'has_account'       => 'Already have an account?',
    'login_link'        => 'Sign In',
    'password_strength' => [
        'very_weak' => 'Very Weak',
        'weak'      => 'Weak',
        'medium'    => 'Medium',
        'good'      => 'Good',
        'strong'    => 'Strong',
    ],
    'passwords_match'   => '✓ Passwords match',
    'passwords_mismatch'=> '✗ Passwords do not match',
    'required'          => '*',
    'submitting'        => 'Creating account...',
    'select_family'     => 'Select a family...',
];
