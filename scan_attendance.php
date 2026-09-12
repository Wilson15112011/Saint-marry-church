<?php
// ================================================
// scan_attendance.php – استقبال مسح الـ QR وتسجيل الحضور
// ================================================
require_once 'db_config.php';
session_start();

// حماية الصفحة: أمين الخدمة فقط هو من يسجل الحضور
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'أمين الخدمة') {
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>🛑 عذراً، هذه الصلاحية مقتصرة على أمين الخدمة فقط.</div>");
}
$admin_family = $_SESSION['family_name'] ?? '';

$servant_id = intval($_GET['id'] ?? 0);
$today_date = date('Y-m-d');
$message = "";

if (empty($admin_family)) {
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>🛑 لا يمكن التحقق من أسرتك. يرجى التأكد من بيانات الحساب.</div>");
}


if ($servant_id > 0) {
    try {
        $conn = get_db();
        
        // 1. التحقق من أن المستخدم خادم حقيقي في السيستم
        $check_user = $conn->prepare("SELECT full_name, family_name FROM users WHERE id = ? AND role = 'خادم' LIMIT 1");
        $check_user->bind_param("i", $servant_id);
        $check_user->execute();
        $user_res = $check_user->get_result();
        
        if ($user_res->num_rows === 1) {
            $servant = $user_res->fetch_assoc();

            // 2. صلاحية المسح حسب الأسرة:
            // - إذا كان الخادم بدون أسرة (family_name فارغ) => يسمح له بالمسح بأي QR
            // - إذا كان الخادم له أسرة => يجب أن تكون مساوية لأسرة أمين الخدمة
            $servant_family = trim((string)($servant['family_name'] ?? ''));
            $admin_family_trimmed = trim((string)$admin_family);

            if ($servant_family !== '' && $admin_family_trimmed !== $servant_family) {
                $message = "⛔ عذراً، لا يمكنك تسجيل حضور خادم من أسرة مختلفة عن أسرتك.";
            } else {
                // 3. تسجيل الحضور في الجدول
                $stmt = $conn->prepare("INSERT INTO attendance (user_id, attendance_date, status) VALUES (?, ?, 1)");
                $stmt->bind_param("is", $servant_id, $today_date);
                
                if ($stmt->execute()) {
                    $message = "✅ تم تسجيل حضور الأستاذ/ة: <b>" . htmlspecialchars($servant['full_name']) . "</b> بنجاح اليوم!";
                } else {
                    $message = "⚠️ الخادم مسجل حضور بالفعل اليوم سابقاً.";
                }
                $stmt->close();
            }
        } else {
            $message = "❌ خطأ: الخادم غير موجود بالمنظومة.";
        }
        $check_user->close();
        $conn->close();
    } catch (Exception $e) {
        $message = "⚠️ الخادم مسجل حضور بالفعل اليوم.";
    }
} else {
    $message = "❌ بيانات الـ QR غير صالحة.";
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تسجيل الحضور عبر الـ QR</title>
    <link rel="stylesheet" href="login.css">
</head>
<body style="background:#FAF7F2; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;">
    <div class="card" style="text-align:center; padding:40px; max-width:400px;">
        <h2>نظام الحضور الذكي</h2>
        <p style="font-size:1.1rem; margin:20px 0; color:#2C2416;"><?= $message ?></p>
        <a href="dashboard.php" class="btn-submit" style="text-decoration:none; display:inline-block;">العودة للوحة التحكم</a>
    </div>
</body>
</html>