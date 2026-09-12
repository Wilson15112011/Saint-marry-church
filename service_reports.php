<<<<<<< HEAD
<?php
// ================================================
// service_reports.php – تقارير الخدمة وحساب متوسط الحضور
// ================================================
require_once 'db_config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'أمين الخدمة') {
    header('Location: dashboard.php');
    exit;
}

$current_user_family = $_SESSION['family_name'] ?? '';

$conn = get_db();
$conn->set_charset("utf8mb4");

// استعلام سحري: يحسب إجمالي أيام الحضور لكل خادم تابع للأسرة الحالية
// المتوسط = (أيام حضور الخادم ÷ إجمالي عدد أيام الحضور المسجلة في المنظومة للأسرة) × 100
$sql = "SELECT 
            u.full_name,
            COUNT(a.id) as attended_days,
            (SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE user_id IN (SELECT id FROM users WHERE family_name = ?)) as total_meeting_days
        FROM users u
        LEFT JOIN attendance a ON u.id = a.user_id
        WHERE u.family_name = ? AND u.role = 'خادم'
        GROUP BY u.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $current_user_family, $current_user_family);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقارير الخدمة - نسبة متوسط الحضور</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #FAF7F2; margin: 40px; }
        .report-container { max-width: 900px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 5px solid #C8973A; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; text-align: right; }
        th { background: #2C2416; color: #E0B86A; padding: 12px; border: 1px solid #E2D5BC; }
        td { padding: 12px; border: 1px solid #E2D5BC; }
        tr:nth-child(even) { background: #FDFAF4; }
        .badge { padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.85rem; }
        .badge-good { background: #E2F7E2; color: #1E5C1E; }
        .badge-alert { background: #FFEAEA; color: #A32A2A; }
    </style>
</head>
<body>

<div class="report-container">
    <h2>📊 تقارير الخدمة: متوسط حضور خدام أسرة (<?= htmlspecialchars($current_user_family) ?>)</h2>
    <p style="color: #666;">يتم احتساب النسبة المئوية للمتوسط بناءً على حضور الخادم مقارنةً بإجمالي عدد الاجتماعات الفعلي المنعقد للأسرة.</p>

    <table>
        <thead>
            <tr>
                <th>اسم الخادم</th>
                <th>الأيام المحضور فيها</th>
                <th>إجمالي الاجتماعات</th>
                <th>متوسط نسبة الحضور</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): 
                    $total_days = $row['total_meeting_days'] > 0 ? $row['total_meeting_days'] : 1;
                    // حساب النسبة المئوية لمتوسط الحضور
                    $average = round(($row['attended_days'] / $total_days) * 100);
                    $badge_class = $average >= 70 ? 'badge-good' : 'badge-alert';
                ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= $row['attended_days'] ?> يوم</td>
                        <td><?= $row['total_meeting_days'] ?> اجتماع</td>
                        <td>
                            <span class="badge <?= $badge_class ?>"><?= $average ?>%</span>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align:center; color:#888;">لا يوجد بيانات حضور مسجلة للخدام حتى الآن.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <br>
    <a href="dashboard.php" style="color: #C8973A; text-decoration: none; font-weight: bold;">➡️ العودة للوحة التحكم</a>
</div>

</body>
</html>
<?php 
$stmt->close();
$conn->close();
=======
<?php
// ================================================
// service_reports.php – تقارير الخدمة وحساب متوسط الحضور
// ================================================
require_once 'db_config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'أمين الخدمة') {
    header('Location: dashboard.php');
    exit;
}

$current_user_family = $_SESSION['family_name'] ?? '';

$conn = get_db();
$conn->set_charset("utf8mb4");

// استعلام سحري: يحسب إجمالي أيام الحضور لكل خادم تابع للأسرة الحالية
// المتوسط = (أيام حضور الخادم ÷ إجمالي عدد أيام الحضور المسجلة في المنظومة للأسرة) × 100
$sql = "SELECT 
            u.full_name,
            COUNT(a.id) as attended_days,
            (SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE user_id IN (SELECT id FROM users WHERE family_name = ?)) as total_meeting_days
        FROM users u
        LEFT JOIN attendance a ON u.id = a.user_id
        WHERE u.family_name = ? AND u.role = 'خادم'
        GROUP BY u.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $current_user_family, $current_user_family);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقارير الخدمة - نسبة متوسط الحضور</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #FAF7F2; margin: 40px; }
        .report-container { max-width: 900px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 5px solid #C8973A; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; text-align: right; }
        th { background: #2C2416; color: #E0B86A; padding: 12px; border: 1px solid #E2D5BC; }
        td { padding: 12px; border: 1px solid #E2D5BC; }
        tr:nth-child(even) { background: #FDFAF4; }
        .badge { padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.85rem; }
        .badge-good { background: #E2F7E2; color: #1E5C1E; }
        .badge-alert { background: #FFEAEA; color: #A32A2A; }
    </style>
</head>
<body>

<div class="report-container">
    <h2>📊 تقارير الخدمة: متوسط حضور خدام أسرة (<?= htmlspecialchars($current_user_family) ?>)</h2>
    <p style="color: #666;">يتم احتساب النسبة المئوية للمتوسط بناءً على حضور الخادم مقارنةً بإجمالي عدد الاجتماعات الفعلي المنعقد للأسرة.</p>

    <table>
        <thead>
            <tr>
                <th>اسم الخادم</th>
                <th>الأيام المحضور فيها</th>
                <th>إجمالي الاجتماعات</th>
                <th>متوسط نسبة الحضور</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): 
                    $total_days = $row['total_meeting_days'] > 0 ? $row['total_meeting_days'] : 1;
                    // حساب النسبة المئوية لمتوسط الحضور
                    $average = round(($row['attended_days'] / $total_days) * 100);
                    $badge_class = $average >= 70 ? 'badge-good' : 'badge-alert';
                ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= $row['attended_days'] ?> يوم</td>
                        <td><?= $row['total_meeting_days'] ?> اجتماع</td>
                        <td>
                            <span class="badge <?= $badge_class ?>"><?= $average ?>%</span>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align:center; color:#888;">لا يوجد بيانات حضور مسجلة للخدام حتى الآن.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <br>
    <a href="dashboard.php" style="color: #C8973A; text-decoration: none; font-weight: bold;">➡️ العودة للوحة التحكم</a>
</div>

</body>
</html>
<?php 
$stmt->close();
$conn->close();
>>>>>>> f9d1529 (Initial commit)
?>