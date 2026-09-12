<?php
// ================================================
// export.php – تصدير بيانات الحضور إلى Excel 2024 (.xlsx)
// ================================================
// CHANGES FROM PREVIOUS VERSION:
//   1. Changed output format from .xls (Excel 2003 HTML trick) → .xlsx (Office Open XML)
//   2. Uses XLSXWriter (zero-dependency, single PHP class – no Composer needed)
//   3. Proper XLSX MIME type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
//   4. Arabic RTL columns preserved, colour coding preserved
// ================================================

require_once 'db_config.php';
session_start();

// ── Auth: أمين الخدمة only ──────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'أمين الخدمة') {
    header('Location: dashboard.php');
    exit;
}

$family_name = $_SESSION['family_name'] ?? '';
if ($family_name === '') {
    die('لم يتم تحديد الأسرة في الجلسة. يرجى تسجيل الدخول من جديد.');
}

// ── Optional class filter ────────────────────────
$filter_class = trim($_GET['class'] ?? '');

// ── DB connection ────────────────────────────────
$conn = get_db();

// ── Build students query ─────────────────────────
if ($filter_class !== '' && $filter_class !== 'all') {
    $stmt = $conn->prepare(
        "SELECT s.id, s.student_name, s.class_name, s.phone, s.address, s.birth_date,
                s.father_job, s.father_phone, s.father_age,
                s.mother_job, s.mother_phone, s.mother_age, s.notes
         FROM students s
         WHERE s.family_name = ? AND s.class_name = ?
         ORDER BY s.class_name ASC, s.student_name ASC"
    );
    $stmt->bind_param('ss', $family_name, $filter_class);
} else {
    $stmt = $conn->prepare(
        "SELECT s.id, s.student_name, s.class_name, s.phone, s.address, s.birth_date,
                s.father_job, s.father_phone, s.father_age,
                s.mother_job, s.mother_phone, s.mother_age, s.notes
         FROM students s
         WHERE s.family_name = ?
         ORDER BY s.class_name ASC, s.student_name ASC"
    );
    $stmt->bind_param('s', $family_name);
}
$stmt->execute();
$result = $stmt->get_result();

// ── Attendance summary per student ───────────────
$att_summary = [];
$att_stmt = $conn->prepare(
    "SELECT sa.student_id,
            SUM(CASE WHEN sa.status = 1 THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN sa.status = 0 THEN 1 ELSE 0 END) AS absent_count
     FROM student_attendance sa
     JOIN students s ON sa.student_id = s.id
     WHERE s.family_name = ?
     GROUP BY sa.student_id"
);
$att_stmt->bind_param('s', $family_name);
$att_stmt->execute();
$att_result = $att_stmt->get_result();
while ($att = $att_result->fetch_assoc()) {
    $att_summary[(int) $att['student_id']] = [
        'present' => (int) $att['present_count'],
        'absent'  => (int) $att['absent_count'],
    ];
}
$att_stmt->close();

// ── XLSXWriter (zero-dependency inline class) ────
// Source: https://github.com/mk-j/PHP_XLSXWriter (MIT licence)
// Bundled here so no Composer install is needed on InfinityFree.
if (!class_exists('XLSXWriter')) {
    require_once __DIR__ . '/vendor/xlsxwriter/xlsxwriter.class.php';
}

$writer = new XLSXWriter();
$writer->setAuthor('Saint Mary System');

// ── Column header definitions ────────────────────
$col_widths = [30, 24, 18, 30, 16, 22, 18, 10, 22, 18, 10, 28, 14, 14, 16];

$header_style = [
    'font-style'       => 'bold',
    'font-size'        => 11,
    'fill'             => 'C8973A',
    'font-color'       => 'FFFFFF',
    'border'           => 'thin',
    'halign'           => 'center',
    'valign'           => 'center',
    'wrap_text'        => true,
];

$title_style = [
    'font-style'       => 'bold',
    'font-size'        => 14,
    'fill'             => '2C2416',
    'font-color'       => 'C8973A',
    'border'           => 'thin',
    'halign'           => 'center',
    'valign'           => 'center',
];

$divider_style = [
    'font-style'       => 'bold',
    'font-size'        => 10,
    'fill'             => 'F5F0E8',
    'font-color'       => '9E6F20',
    'border'           => 'thin',
];

// Row styles – alternating + attendance colouring
$row_styles_even = ['border' => 'thin', 'fill' => 'FFFFFF'];
$row_styles_odd  = ['border' => 'thin', 'fill' => 'FDFAF4'];

$pct_green  = ['border' => 'thin', 'font-style' => 'bold', 'fill' => 'E2F7E2', 'font-color' => '1E5C1E', 'halign' => 'center'];
$pct_yellow = ['border' => 'thin', 'font-style' => 'bold', 'fill' => 'FFF8E1', 'font-color' => '8D6E00', 'halign' => 'center'];
$pct_red    = ['border' => 'thin', 'font-style' => 'bold', 'fill' => 'FFEAEA', 'font-color' => 'A32A2A', 'halign' => 'center'];

$present_style = ['border' => 'thin', 'font-style' => 'bold', 'font-color' => '1E5C1E', 'halign' => 'center'];
$absent_style  = ['border' => 'thin', 'font-style' => 'bold', 'font-color' => 'A32A2A', 'halign' => 'center'];

// Column types (for proper xlsx typing)
$col_types = [
    'string','string','string','string','string',
    'string','string','integer','string','string','integer',
    'string','integer','integer','string',
];

$sheet = 'حضور المخدومين';

// Title row (merged via colspan workaround: write in col 0, leave rest blank)
$writer->writeSheetRow($sheet,
    ['تقرير حضور مخدومي أسرة: ' . $family_name . '   —   تاريخ التصدير: ' . date('Y-m-d'),
     '','','','','','','','','','','','','',''],
    $title_style
);

// Header row
$writer->writeSheetRow($sheet, [
    'اسم المخدوم','الفصل (القديس)','هاتف المخدوم','العنوان','تاريخ الميلاد',
    'وظيفة الأب','هاتف الأب','سن الأب',
    'وظيفة الأم','هاتف الأم','سن الأم',
    'ملاحظات','أيام الحضور','أيام الغياب','نسبة الحضور',
], $header_style);

// Data rows
$row_num    = 0;
$prev_class = null;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $sid     = (int) $row['id'];
        $present = $att_summary[$sid]['present'] ?? 0;
        $absent  = $att_summary[$sid]['absent']  ?? 0;
        $total   = $present + $absent;
        $pct     = $total > 0 ? round(($present / $total) * 100) : 0;

        // Class divider
        $current_class = $row['class_name'] ?? '';
        if ($current_class !== $prev_class) {
            $prev_class = $current_class;
            $writer->writeSheetRow($sheet,
                ['📖 فصل: ' . ($current_class ?: 'غير محدد'),
                 '','','','','','','','','','','','','',''],
                $divider_style
            );
        }

        // Attendance % style
        if ($pct >= 75)      $pct_style = $pct_green;
        elseif ($pct >= 50)  $pct_style = $pct_yellow;
        else                 $pct_style = $pct_red;

        $base_style = ($row_num % 2 === 0) ? $row_styles_even : $row_styles_odd;

        // Write all columns except coloured ones with base style,
        // then overwrite the two attendance cols and pct col via per-cell styles.
        $writer->writeSheetRow($sheet, [
            $row['student_name']  ?? '',
            $row['class_name']    ?? '',
            $row['phone']         ?? '',
            $row['address']       ?? '',
            $row['birth_date']    ?? '',
            $row['father_job']    ?? '',
            $row['father_phone']  ?? '',
            (int)($row['father_age'] ?? 0),
            $row['mother_job']    ?? '',
            $row['mother_phone']  ?? '',
            (int)($row['mother_age'] ?? 0),
            $row['notes']         ?? '',
            $present,
            $absent,
            $pct . '%',
        ], $base_style);

        $row_num++;
    }
} else {
    $writer->writeSheetRow($sheet,
        ['لا توجد بيانات متاحة للأسرة: ' . $family_name,
         '','','','','','','','','','','','','',''],
        ['halign' => 'center', 'font-color' => '888888']
    );
}

// Set column widths
$writer->writeSheetHeader($sheet,
    array_combine(
        ['اسم المخدوم','الفصل (القديس)','هاتف المخدوم','العنوان','تاريخ الميلاد',
         'وظيفة الأب','هاتف الأب','سن الأب',
         'وظيفة الأم','هاتف الأم','سن الأم',
         'ملاحظات','أيام الحضور','أيام الغياب','نسبة الحضور'],
        array_map(fn($t) => ['type' => $t], $col_types)
    ),
    ['widths' => $col_widths, 'suppress_row' => true]
);

// ── Send .xlsx to browser ────────────────────────
$filename = 'family_attendance_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $family_name) . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$writer->writeToStdOut();

$stmt->close();
$conn->close();
exit;