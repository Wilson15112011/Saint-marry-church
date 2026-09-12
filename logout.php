<?php
// ================================================
// logout.php – تسجيل خروج
// ================================================
require_once 'db_config.php';
session_start();

// تسجيل خروج آمن
session_unset();     // حذف كل المتغيرات
session_destroy();   // تدمير الجلسة

// حذف الكوكيز الخاصة بالـ session (اختياري لكن موصى به)
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// إعادة توجيه لصفحة تسجيل الدخول
header('Location: index.php');
exit;
?>