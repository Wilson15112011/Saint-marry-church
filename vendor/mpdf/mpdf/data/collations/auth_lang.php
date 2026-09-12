<?php
function auth_set_language_from_request(): string {
    if (isset($_GET['lang'])) {
        $_SESSION['language'] = ($_GET['lang'] === 'en') ? 'en' : 'ar';
    }
    return ($_SESSION['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
}

function auth_date_label(string $lang): string {
    if ($lang === 'en') {
        return date('l, F j, Y');
    }
    $ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
    $ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    return $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');
}

function ensure_user_language_column(mysqli $conn): void {
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'language'");
    if ($column_check && $column_check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN language VARCHAR(2) NOT NULL DEFAULT 'ar'");
    }
}

function auth_lang_switch(string $current, string $target): string {
    $label = $target === 'en' ? 'English' : 'عربي';
    $class = $current === $target ? ' lang-active' : '';
    $query = $_GET;
    $query['lang'] = $target;
    $href = htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($query));
    return '<a class="lang-pill' . $class . '" href="' . $href . '">' . $label . '</a>';
}
?>
