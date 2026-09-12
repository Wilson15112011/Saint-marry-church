<?php
// ================================================
// prepare_lesson.php – تحضير اليوم (للخادم)
// ================================================
require_once 'db_config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'خادم') {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lesson_title     = trim($_POST['lesson_title'] ?? '');
    $preparation_date = $_POST['preparation_date'] ?? date('Y-m-d');
    $content          = trim($_POST['content'] ?? '');

    if (empty($lesson_title)) {
        $error = 'يرجى إدخال عنوان الدرس';
    } else {
        $file_path = null;

        // Upload Image
        if (isset($_FILES['lesson_image']) && $_FILES['lesson_image']['error'] === 0) {
            $upload_dir = 'uploads/lessons/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $ext = strtolower(pathinfo($_FILES['lesson_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($ext, $allowed)) {
                $new_name = 'lesson_' . time() . '_' . $_SESSION['user_id'] . '.' . $ext;
                $target   = $upload_dir . $new_name;

                if (move_uploaded_file($_FILES['lesson_image']['tmp_name'], $target)) {
                    $file_path = $target;
                } else {
                    $error = 'فشل في رفع الصورة';
                }
            } else {
                $error = 'نوع الملف غير مسموح (مسموح: jpg, jpeg, png, gif)';
            }
        }

        if (empty($error)) {
            try {
                $conn = get_db();
                $stmt = $conn->prepare("INSERT INTO preparations 
                    (user_id, family_name, lesson_title, preparation_date, content, file_path) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                
                $stmt->bind_param("isssss", 
                    $_SESSION['user_id'],
                    $_SESSION['family_name'],
                    $lesson_title,
                    $preparation_date,
                    $content,
                    $file_path
                );

                if ($stmt->execute()) {
                    $success = '✅ تم حفظ تحضير الدرس بنجاح!';
                    // Clear form after success
                    $lesson_title = $content = '';
                } else {
                    $error = '❌ حدث خطأ أثناء حفظ الدرس';
                }
            } catch (Exception $e) {
                $error = 'خطأ في قاعدة البيانات';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحضير اليوم</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>

<div class="card" style="max-width:680px; margin:40px auto; padding:40px 30px;">
    <h2>📝 تحضير درس اليوم</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="field">
            <label>عنوان الدرس <span class="required">*</span></label>
            <input type="text" name="lesson_title" value="<?= htmlspecialchars($lesson_title ?? '') ?>" 
                   placeholder="مثال: دروس الإيمان - الدرس الأول" required>
        </div>

        <div class="field">
            <label>تاريخ التحضير</label>
            <input type="date" name="preparation_date" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="field">
            <label>رفع صورة الدرس (اختياري)</label>
            <input type="file" name="lesson_image" accept="image/jpeg,image/png,image/gif">
        </div>

        <div class="field">
            <label>محتوى الدرس (نص)</label>
            <textarea name="content" rows="12" placeholder="اكتب محتوى الدرس هنا..."><?= htmlspecialchars($content ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn-submit">حفظ التحضير</button>
    </form>

    <div style="text-align:center; margin-top:25px;">
        <a href="dashboard.php" style="color:var(--gold);">← العودة إلى لوحة التحكم</a>
    </div>
</div>

</body>
</html>