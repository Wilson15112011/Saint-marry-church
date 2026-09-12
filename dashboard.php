<?php
// ================================================
// dashboard.php – النسخة الإمبراطورية المستقرة والمعدلة بالكامل
// ================================================

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Africa/Cairo');
}
require_once 'db_config.php';
require_once __DIR__ . '/mail_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user = [
    'id' => $_SESSION['user_id'],
    'full_name' => $_SESSION['full_name'] ?? 'خادم الكنيسة',
    'role' => $_SESSION['role'] ?? 'خادم'
];
$family_info = [
    'meeting_day'      => 'غير محدد',
    'meeting_time'     => 'غير محدد',
    'meeting_location' => 'غير محدد'
];
$next_spiritual_day = [
    'event_date' => null,
    'event_time' => 'غير محدد'
];
$current_user_family = $_SESSION['family_name'] ?? '';
$is_admin = ($user['role'] === 'أمين الخدمة');
$lang = $_SESSION['lang'] ?? 'ar';
// Current user's assigned class (may be empty)
$current_user_class = $_SESSION['class_name'] ?? '';



function ensure_family_chat_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS family_chat_messages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        family_name VARCHAR(100) NOT NULL,
        user_id INT(10) UNSIGNED NOT NULL,
        full_name VARCHAR(120) NOT NULL,
        message TEXT NOT NULL,
        attachment_path VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        attachment_type VARCHAR(100) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_family_created (family_name, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cols = [];
    $col_res = $conn->query("SHOW COLUMNS FROM family_chat_messages");
    if ($col_res) {
        while ($c = $col_res->fetch_assoc()) {
            $cols[] = $c['Field'];
        }
    }
    if (!in_array('attachment_path', $cols, true)) {
        $conn->query("ALTER TABLE family_chat_messages ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL AFTER message");
    }
    if (!in_array('attachment_name', $cols, true)) {
        $conn->query("ALTER TABLE family_chat_messages ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path");
    }
    if (!in_array('attachment_type', $cols, true)) {
        $conn->query("ALTER TABLE family_chat_messages ADD COLUMN attachment_type VARCHAR(100) DEFAULT NULL AFTER attachment_name");
    }
}

function family_chat_columns(mysqli $conn): array {
    $cols = [];
    $col_res = $conn->query("SHOW COLUMNS FROM family_chat_messages");
    if ($col_res) {
        while ($c = $col_res->fetch_assoc()) {
            $cols[] = $c['Field'];
        }
    }
    return $cols;
}

if (isset($_GET['chat_action'])) {
    $chat_action = $_GET['chat_action'];
    $family_name = $_SESSION['family_name'] ?? '';

    if ($family_name === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($chat_action === 'fetch'
            ? ['success' => true, 'messages' => []]
            : ['success' => false, 'error' => 'no_family']);
        exit;
    }

    try {
        $conn = get_db();
        $conn->set_charset('utf8mb4');
        ensure_family_chat_table($conn);

        if ($chat_action === 'attachment') {
            $message_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $chat_cols = family_chat_columns($conn);
            if (!in_array('attachment_path', $chat_cols, true)) {
                http_response_code(404);
                exit;
            }
            $stmt = $conn->prepare("SELECT attachment_path, attachment_name, attachment_type FROM family_chat_messages WHERE id = ? AND family_name = ? LIMIT 1");
            $stmt->bind_param('is', $message_id, $family_name);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || empty($row['attachment_path']) || !is_file($row['attachment_path'])) {
                http_response_code(404);
                exit;
            }

            $type = $row['attachment_type'] ?: 'application/octet-stream';
            $name = $row['attachment_name'] ?: basename($row['attachment_path']);
            header('Content-Type: ' . $type);
            header('Content-Length: ' . filesize($row['attachment_path']));
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($row['attachment_path']);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');

        if ($chat_action === 'fetch') {
            $since_id = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
            $chat_cols = family_chat_columns($conn);
            $attachment_path_select = in_array('attachment_path', $chat_cols, true) ? 'attachment_path' : 'NULL AS attachment_path';
            $attachment_name_select = in_array('attachment_name', $chat_cols, true) ? 'attachment_name' : 'NULL AS attachment_name';
            $attachment_type_select = in_array('attachment_type', $chat_cols, true) ? 'attachment_type' : 'NULL AS attachment_type';
            $stmt = $conn->prepare("SELECT id, user_id, full_name, message, $attachment_path_select, $attachment_name_select, $attachment_type_select, created_at FROM family_chat_messages WHERE family_name = ? AND id > ? ORDER BY id ASC LIMIT 100");
            $stmt->bind_param('si', $family_name, $since_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $messages = [];
            while ($row = $result->fetch_assoc()) {
                $messages[] = [
                    'id' => (int) $row['id'],
                    'user_id' => (int) $row['user_id'],
                    'full_name' => $row['full_name'],
                    'message' => $row['message'],
                    'attachment_path' => $row['attachment_path'],
                    'attachment_name' => $row['attachment_name'],
                    'attachment_type' => $row['attachment_type'],
                    'created_at' => $row['created_at'],
                ];
            }
            $stmt->close();

            echo json_encode(['success' => true, 'messages' => $messages]);
            exit;
        }

        if ($chat_action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $message = trim($_POST['message'] ?? '');
            $attachment_path = null;
            $attachment_name = null;
            $attachment_type = null;

            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $max_size = 10 * 1024 * 1024;
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip'];
                $original_name = basename($_FILES['attachment']['name']);
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                if ($_FILES['attachment']['size'] > $max_size || !in_array($ext, $allowed_ext, true)) {
                    echo json_encode(['success' => false, 'error' => 'invalid_attachment']);
                    exit;
                }

                $upload_dir = 'uploads/chat/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
                $stored_name = 'chat_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe_name . '.' . $ext;
                $target = $upload_dir . $stored_name;

                if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $target)) {
                    echo json_encode(['success' => false, 'error' => 'upload_failed']);
                    exit;
                }

                $attachment_path = $target;
                $attachment_name = $original_name;
                $attachment_type = function_exists('mime_content_type')
                    ? (mime_content_type($target) ?: ($_FILES['attachment']['type'] ?? 'application/octet-stream'))
                    : ($_FILES['attachment']['type'] ?? 'application/octet-stream');
            }

            if ($message === '' && $attachment_path === null) {
                echo json_encode(['success' => false, 'error' => 'empty_message']);
                exit;
            }

            if (mb_strlen($message) > 1000) {
                $message = mb_substr($message, 0, 1000);
            }

            $full_name = $_SESSION['full_name'] ?? 'خادم الكنيسة';
            $user_id = (int) $_SESSION['user_id'];
            $created_at = date('Y-m-d H:i:s');
            $chat_cols = family_chat_columns($conn);

            if ($attachment_path !== null && !in_array('attachment_path', $chat_cols, true)) {
                echo json_encode(['success' => false, 'error' => 'attachment_columns_missing']);
                exit;
            }

            if (in_array('attachment_path', $chat_cols, true)) {
                $stmt = $conn->prepare("INSERT INTO family_chat_messages (family_name, user_id, full_name, message, attachment_path, attachment_name, attachment_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sissssss', $family_name, $user_id, $full_name, $message, $attachment_path, $attachment_name, $attachment_type, $created_at);
            } else {
                $stmt = $conn->prepare("INSERT INTO family_chat_messages (family_name, user_id, full_name, message, created_at) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sisss', $family_name, $user_id, $full_name, $message, $created_at);
            }

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $stmt->close();

                echo json_encode([
                    'success' => true,
                    'message_data' => [
                        'id' => (int) $new_id,
                        'user_id' => $user_id,
                        'full_name' => $full_name,
                        'message' => $message,
                        'attachment_path' => $attachment_path,
                        'attachment_name' => $attachment_name,
                        'attachment_type' => $attachment_type,
                        'created_at' => $created_at,
                    ],
                ]);
                exit;
            }

            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'insert_failed']);
            exit;
        }

        if ($chat_action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $message_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            if ($message_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'invalid_id']);
                exit;
            }

            // Allow deletion only by the message author OR an admin of the same family
            $chat_cols = family_chat_columns($conn);
            if (!in_array('attachment_path', $chat_cols, true)) {
                $stmt = $conn->prepare("SELECT id, user_id FROM family_chat_messages WHERE id = ? AND family_name = ? LIMIT 1");
                $stmt->bind_param('is', $message_id, $family_name);
            } else {
                $stmt = $conn->prepare("SELECT id, user_id, attachment_path FROM family_chat_messages WHERE id = ? AND family_name = ? LIMIT 1");
                $stmt->bind_param('is', $message_id, $family_name);
            }
            $stmt->execute();
            $del_msg = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$del_msg) {
                echo json_encode(['success' => false, 'error' => 'not_found']);
                exit;
            }

            $current_user_id   = (int) $_SESSION['user_id'];
            $current_user_role = $_SESSION['role'] ?? '';
            $is_owner  = ((int) $del_msg['user_id'] === $current_user_id);
            $is_family_admin = ($current_user_role === 'أمين الخدمة');

            if (!$is_owner && !$is_family_admin) {
                echo json_encode(['success' => false, 'error' => 'forbidden']);
                exit;
            }

            $del_stmt = $conn->prepare("DELETE FROM family_chat_messages WHERE id = ? AND family_name = ?");
            $del_stmt->bind_param('is', $message_id, $family_name);
            if ($del_stmt->execute() && $del_stmt->affected_rows > 0) {
                // Remove attachment file if it exists
                if (!empty($del_msg['attachment_path']) && is_file($del_msg['attachment_path'])) {
                    @unlink($del_msg['attachment_path']);
                }
                $del_stmt->close();
                echo json_encode(['success' => true, 'deleted_id' => $message_id]);
                exit;
            }
            $del_stmt->close();
            echo json_encode(['success' => false, 'error' => 'delete_failed']);
            exit;
        }

        if ($chat_action === 'delete_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_SESSION['role'] ?? '') !== 'أمين الخدمة') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'forbidden']);
                exit;
            }

            $attachment_paths = [];
            $chat_cols = family_chat_columns($conn);
            if (in_array('attachment_path', $chat_cols, true)) {
                $path_stmt = $conn->prepare("SELECT attachment_path FROM family_chat_messages WHERE family_name = ?");
                $path_stmt->bind_param('s', $family_name);
                $path_stmt->execute();
                $path_result = $path_stmt->get_result();
                while ($path_row = $path_result->fetch_assoc()) {
                    if (!empty($path_row['attachment_path'])) {
                        $attachment_paths[] = $path_row['attachment_path'];
                    }
                }
                $path_stmt->close();
            }

            $delete_all_stmt = $conn->prepare("DELETE FROM family_chat_messages WHERE family_name = ?");
            $delete_all_stmt->bind_param('s', $family_name);
            if (!$delete_all_stmt->execute()) {
                $delete_all_stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'delete_failed']);
                exit;
            }
            $deleted_count = $delete_all_stmt->affected_rows;
            $delete_all_stmt->close();

            foreach ($attachment_paths as $attachment_path) {
                if (is_file($attachment_path)) {
                    @unlink($attachment_path);
                }
            }

            echo json_encode(['success' => true, 'deleted_count' => $deleted_count]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bad_chat_action']);
        exit;
    } catch (Exception $e) {
        error_log('Chat endpoint error: ' . $e->getMessage());
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => 'server_error']);
        exit;
    }
}

if (!empty($current_user_family)) {
    try {
        $conn = get_db();
        $conn->set_charset("utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS spiritual_days (
            id INT(11) NOT NULL AUTO_INCREMENT,
            family_name VARCHAR(100) NOT NULL,
            event_date DATE NOT NULL,
            event_time VARCHAR(50) NOT NULL,
            source_note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_family_event (family_name, event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS student_attendance (
            id INT(11) NOT NULL AUTO_INCREMENT,
            student_id INT(11) NOT NULL,
            attendance_date DATE NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=present, 0=absent',
            recorded_by INT(11) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_student_date (student_id, attendance_date),
            KEY idx_date (attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $seed_spiritual_days = [
            ['أسرة شهداء', '2026-06-12'],
            ['أسرة شهداء', '2026-07-24'],
            ['أسرة شهداء', '2026-09-04'],
            ['أسرة ملايكه', '2026-07-03'],
            ['أسرة ملايكه', '2026-08-14'],
            ['أسرة ملايكه', '2026-09-25'],
            ['أسرة سمائيين', '2026-07-17'],
            ['أسرة سمائيين', '2026-08-28'],
            ['أسرة سمائيين', '2026-10-09'],
            ['أسرة ابطال الايمان', '2026-07-10'],
            ['أسرة ابطال الايمان', '2026-08-21'],
            ['أسرة ابطال الايمان', '2026-10-02'],
        ];
        $seed_time = '8:00 ص - 9:30 ص';
        $seed_note = 'من جدول حضور القداسات - عمود مخدومين فقط بدون كشافة';
        $stmt_seed = $conn->prepare("INSERT IGNORE INTO spiritual_days (family_name, event_date, event_time, source_note) VALUES (?, ?, ?, ?)");
        if ($stmt_seed) {
            foreach ($seed_spiritual_days as $day) {
                $stmt_seed->bind_param("ssss", $day[0], $day[1], $seed_time, $seed_note);
                $stmt_seed->execute();
            }
            $stmt_seed->close();
        }

        ensure_family_chat_table($conn);
        ensure_student_columns($conn);

        $stmt = $conn->prepare("SELECT meeting_day, meeting_time, meeting_location FROM family_details WHERE family_name = ? LIMIT 1");
        $stmt->bind_param("s", $current_user_family);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $family_info['meeting_day']      = $row['meeting_day'];
            $family_info['meeting_time']     = $row['meeting_time'];
            $family_info['meeting_location'] = $row['meeting_location'];
        }
        $stmt->close();

        $stmt_spiritual = $conn->prepare("SELECT event_date, event_time FROM spiritual_days WHERE family_name = ? AND event_date >= CURDATE() ORDER BY event_date ASC LIMIT 1");
        $stmt_spiritual->bind_param("s", $current_user_family);
        $stmt_spiritual->execute();
        $result_spiritual = $stmt_spiritual->get_result();
        if ($row = $result_spiritual->fetch_assoc()) {
            $next_spiritual_day['event_date'] = $row['event_date'];
            $next_spiritual_day['event_time'] = $row['event_time'];
        }
        $stmt_spiritual->close();
    } catch (Exception $e) { }
}

$prepare_error = '';
$prepare_success = '';
$lord_error = '';
$lord_success = '';
$error = '';
$success = '';
$student_error = '';
$student_success = '';
$attendance_success = '';
$attendance_error = '';
$settings_error = '';
$settings_success = '';
$prep_edit_success = '';
$prep_edit_error = '';

function redirect_dashboard_section(string $section): void {
    header('Location: dashboard.php?section=' . rawurlencode($section));
    exit;
}

foreach ([
    'prepare_success_flash' => 'prepare_success',
    'prepare_error_flash' => 'prepare_error',
    'lord_success_flash' => 'lord_success',
    'lord_error_flash' => 'lord_error',
    'student_success_flash' => 'student_success',
    'student_error_flash' => 'student_error',
    'attendance_success_flash' => 'attendance_success',
    'attendance_error_flash' => 'attendance_error',
    'review_success_flash' => 'success',
    'review_error_flash' => 'error',
    'prep_edit_success_flash' => 'prep_edit_success',
    'prep_edit_error_flash' => 'prep_edit_error',
] as $flash_key => $target_var) {
    if (isset($_SESSION[$flash_key])) {
        $$target_var = $_SESSION[$flash_key];
        unset($_SESSION[$flash_key]);
    }
}

if (isset($_SESSION['settings_success_flash'])) {
    $settings_success = $_SESSION['settings_success_flash'];
    unset($_SESSION['settings_success_flash']);
}
if (isset($_SESSION['settings_error_flash'])) {
    $settings_error = $_SESSION['settings_error_flash'];
    unset($_SESSION['settings_error_flash']);
}

function ensure_user_class_column(mysqli $conn): void {
    $col_res = $conn->query("SHOW COLUMNS FROM users LIKE 'class_name'");
    if ($col_res && $col_res->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN class_name VARCHAR(100) DEFAULT NULL");
    }
}

function ensure_user_theme_column(mysqli $conn): void {
    $col_res = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_mode'");
    if ($col_res && $col_res->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN theme_mode VARCHAR(10) NOT NULL DEFAULT 'light'");
    }
}

function ensure_student_columns($conn) {
    $cols = [];
    $col_res = $conn->query("SHOW COLUMNS FROM students");
    if ($col_res) {
        while ($c = $col_res->fetch_assoc()) {
            $cols[] = $c['Field'];
        }
    }

    if (!in_array('class_name', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN class_name VARCHAR(100) DEFAULT NULL");
    }
    if (!in_array('father_job', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN father_job VARCHAR(150) DEFAULT NULL");
    }
    if (!in_array('father_phone', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN father_phone VARCHAR(50) DEFAULT NULL");
    }
    if (!in_array('father_age', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN father_age INT DEFAULT NULL");
    }
    if (!in_array('mother_job', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN mother_job VARCHAR(150) DEFAULT NULL");
    }
    if (!in_array('mother_phone', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN mother_phone VARCHAR(50) DEFAULT NULL");
    }
    if (!in_array('mother_age', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN mother_age INT DEFAULT NULL");
    }
    if (!in_array('profile_members', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN 	profile_members TEXT DEFAULT NULL");
    }
    if (!in_array('profile_picture', $cols, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");
    }
}

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

function ensure_preparation_review_columns($conn) {
    $cols = [];
    $col_res = $conn->query("SHOW COLUMNS FROM preparations");
    if ($col_res) {
        while ($c = $col_res->fetch_assoc()) {
            $cols[] = $c['Field'];
        }
    }

    if (!in_array('content', $cols, true)) {
        $conn->query("ALTER TABLE preparations ADD COLUMN content TEXT NULL AFTER preparation_date");
    }
    if (!in_array('review_status', $cols, true)) {
        $conn->query("ALTER TABLE preparations ADD COLUMN review_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER file_path");
    }
    if (!in_array('admin_review_text', $cols, true)) {
        $conn->query("ALTER TABLE preparations ADD COLUMN admin_review_text TEXT NULL AFTER review_status");
    }
    if (!in_array('admin_reviewed_at', $cols, true)) {
        $conn->query("ALTER TABLE preparations ADD COLUMN admin_reviewed_at DATETIME NULL AFTER admin_review_text");
    }
    if (!in_array('admin_user_id', $cols, true)) {
        $conn->query("ALTER TABLE preparations ADD COLUMN admin_user_id INT NULL AFTER admin_reviewed_at");
    }
}

function ensure_lord_brothers_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS lord_brothers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        selected_by INT NOT NULL,
        family_name VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_lord_brother (student_id, family_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_user_devices_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS user_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_token_hash VARCHAR(64) NOT NULL,
        device_name VARCHAR(160) NOT NULL,
        browser_name VARCHAR(80) NOT NULL,
        platform_name VARCHAR(80) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent TEXT NULL,
        first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_session (user_id, session_token_hash),
        KEY idx_user_last_seen (user_id, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function detect_client_device(string $user_agent): array {
    $browser = 'Browser';
    if (stripos($user_agent, 'Edg/') !== false) {
        $browser = 'Microsoft Edge';
    } elseif (stripos($user_agent, 'Chrome/') !== false && stripos($user_agent, 'Chromium') === false) {
        $browser = 'Google Chrome';
    } elseif (stripos($user_agent, 'Firefox/') !== false) {
        $browser = 'Mozilla Firefox';
    } elseif (stripos($user_agent, 'Safari/') !== false) {
        $browser = 'Safari';
    }

    $platform = 'Unknown device';
    if (stripos($user_agent, 'Windows') !== false) {
        $platform = 'Windows';
    } elseif (stripos($user_agent, 'Android') !== false) {
        $platform = 'Android';
    } elseif (stripos($user_agent, 'iPhone') !== false) {
        $platform = 'iPhone';
    } elseif (stripos($user_agent, 'iPad') !== false) {
        $platform = 'iPad';
    } elseif (stripos($user_agent, 'Mac OS') !== false || stripos($user_agent, 'Macintosh') !== false) {
        $platform = 'macOS';
    } elseif (stripos($user_agent, 'Linux') !== false) {
        $platform = 'Linux';
    }

    return [
        'browser' => $browser,
        'platform' => $platform,
        'device' => $browser . ' on ' . $platform,
    ];
}

function client_ip_address(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $value = explode(',', $_SERVER[$key])[0];
            return trim($value);
        }
    }
    return '';
}

function touch_current_user_device(mysqli $conn, int $user_id): void {
    ensure_user_devices_table($conn);

    $session_hash = hash('sha256', session_id());
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip_address = client_ip_address();
    $device = detect_client_device($user_agent);
    $device_name = $device['device'];
    $browser_name = $device['browser'];
    $platform_name = $device['platform'];
    $seen_at = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT INTO user_devices
        (user_id, session_token_hash, device_name, browser_name, platform_name, ip_address, user_agent, first_seen, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            device_name = VALUES(device_name),
            browser_name = VALUES(browser_name),
            platform_name = VALUES(platform_name),
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent),
            first_seen = IF(first_seen > VALUES(first_seen), VALUES(first_seen), first_seen),
            last_seen = VALUES(last_seen)");
    $stmt->bind_param(
        "issssssss",
        $user_id,
        $session_hash,
        $device_name,
        $browser_name,
        $platform_name,
        $ip_address,
        $user_agent,
        $seen_at,
        $seen_at
    );
    $stmt->execute();
    $stmt->close();
}

function get_user_devices(mysqli $conn, int $user_id): array {
    ensure_user_devices_table($conn);
    $stmt = $conn->prepare("SELECT session_token_hash, device_name, browser_name, platform_name, ip_address, first_seen, last_seen
        FROM user_devices
        WHERE user_id = ?
        ORDER BY last_seen DESC
        LIMIT 20");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $devices = [];
    $current_hash = hash('sha256', session_id());
    while ($row = $result->fetch_assoc()) {
        $row['is_current'] = hash_equals($current_hash, $row['session_token_hash']);
        $devices[] = $row;
    }
    $stmt->close();
    return $devices;
}

$account_devices = [];
try {
    $conn_devices = get_db();
    $conn_devices->set_charset("utf8mb4");
    touch_current_user_device($conn_devices, (int) $user['id']);
    $account_devices = get_user_devices($conn_devices, (int) $user['id']);
} catch (Exception $e) {
    $account_devices = [];
}

// Handle تحضير اليوم Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_lesson'])) {
    $lesson_title = trim($_POST['lesson_title'] ?? '');
    $preparation_date = $_POST['preparation_date'] ?? date('Y-m-d');
    $content = trim($_POST['content'] ?? '');

    $prep_day_of_week = (int)date('N', strtotime($preparation_date)); // 5 is Friday

    if (empty($lesson_title)) {
        $_SESSION['prepare_error_flash'] = 'يرجى إدخال عنوان الدرس';
        redirect_dashboard_section('prepare');
    } elseif ($prep_day_of_week !== 5) {
        $_SESSION['prepare_error_flash'] = '⚠️ عذراً، يجب أن يكون تاريخ التحضير يوم جمعة فقط.';
        redirect_dashboard_section('prepare');
    } else {
        $file_path = null;
        if (isset($_FILES['lesson_image']) && $_FILES['lesson_image']['error'] === 0) {
            $upload_dir = 'uploads/lessons/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['lesson_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                $new_name = 'lesson_' . time() . '_' . $user['id'] . '.' . $ext;
                $target = $upload_dir . $new_name;
                if (move_uploaded_file($_FILES['lesson_image']['tmp_name'], $target)) {
                    $file_path = $target;
                }
            }
        }
        try {
            $conn = get_db();
            ensure_preparation_review_columns($conn);
            $stmt = $conn->prepare("INSERT INTO preparations 
                (user_id, lesson_title, preparation_date, content, file_path) 
                VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $user['id'], $lesson_title, $preparation_date, $content, $file_path);
            if ($stmt->execute()) {
                $_SESSION['prepare_success_flash'] = '✅ تم حفظ تحضير الدرس بنجاح!';
            } else {
                $_SESSION['prepare_error_flash'] = '❌ حدث خطأ أثناء الحفظ';
            }
        } catch (Exception $e) {
            $_SESSION['prepare_error_flash'] = 'خطأ في قاعدة البيانات';
        }
        redirect_dashboard_section('prepare');
    }
}

// Handle Delete Preparation (servant can delete their own)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_preparation'])) {
    $del_prep_id = intval($_POST['prep_id'] ?? 0);
    if ($user['role'] !== 'خادم' || $del_prep_id <= 0) {
        $_SESSION['prep_edit_error_flash'] = 'غير مصرح بهذه العملية.';
        redirect_dashboard_section('prepare');
    }
    try {
        $conn_del = get_db();
        // Fetch file path first
        $stmt_f = $conn_del->prepare("SELECT file_path FROM preparations WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt_f->bind_param("ii", $del_prep_id, $user['id']);
        $stmt_f->execute();
        $res_f = $stmt_f->get_result();
        $del_row = $res_f ? $res_f->fetch_assoc() : null;
        $stmt_f->close();

        if (!$del_row) {
            $_SESSION['prep_edit_error_flash'] = '❌ لم يتم العثور على التحضير أو ليس لديك صلاحية حذفه.';
            redirect_dashboard_section('prepare');
        }

        $stmt_del = $conn_del->prepare("DELETE FROM preparations WHERE id = ? AND user_id = ?");
        $stmt_del->bind_param("ii", $del_prep_id, $user['id']);
        if ($stmt_del->execute() && $stmt_del->affected_rows > 0) {
            // Remove file if exists
            if (!empty($del_row['file_path']) && file_exists($del_row['file_path'])) {
                @unlink($del_row['file_path']);
            }
            $_SESSION['prep_edit_success_flash'] = '🗑️ تم حذف التحضير بنجاح.';
        } else {
            $_SESSION['prep_edit_error_flash'] = '❌ حدث خطأ أثناء الحذف.';
        }
        $stmt_del->close();
    } catch (Exception $e) {
        $_SESSION['prep_edit_error_flash'] = 'خطأ في قاعدة البيانات.';
    }
    redirect_dashboard_section('prepare');
}

// Handle Edit Preparation (servant can edit their own)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_preparation'])) {
    $edit_prep_id = intval($_POST['prep_id'] ?? 0);
    $edit_lesson_title = trim($_POST['lesson_title'] ?? '');
    $edit_preparation_date = $_POST['preparation_date'] ?? date('Y-m-d');
    $edit_content = trim($_POST['content'] ?? '');

    $edit_day_of_week = (int)date('N', strtotime($edit_preparation_date)); // 5 is Friday

    if ($user['role'] !== 'خادم' || $edit_prep_id <= 0) {
        $_SESSION['prep_edit_error_flash'] = 'غير مصرح بهذه العملية.';
        redirect_dashboard_section('prepare');
    } elseif (empty($edit_lesson_title)) {
        $_SESSION['prep_edit_error_flash'] = 'يرجى إدخال عنوان الدرس.';
        redirect_dashboard_section('prepare');
    } elseif ($edit_day_of_week !== 5) {
        $_SESSION['prep_edit_error_flash'] = '⚠️ عذراً، يجب أن يكون تاريخ التحضير يوم جمعة فقط.';
        redirect_dashboard_section('prepare');
    } else {
        try {
            $conn_edit = get_db();
            ensure_preparation_review_columns($conn_edit);

            // Fetch old file path
            $stmt_ef = $conn_edit->prepare("SELECT file_path FROM preparations WHERE id = ? AND user_id = ? LIMIT 1");
            $stmt_ef->bind_param("ii", $edit_prep_id, $user['id']);
            $stmt_ef->execute();
            $res_ef = $stmt_ef->get_result();
            $edit_row = $res_ef ? $res_ef->fetch_assoc() : null;
            $stmt_ef->close();

            if (!$edit_row) {
                $_SESSION['prep_edit_error_flash'] = '❌ لم يتم العثور على التحضير.';
                redirect_dashboard_section('prepare');
            }

            $new_file_path = $edit_row['file_path'];

            // Handle image replacement
            if (isset($_FILES['lesson_image']) && $_FILES['lesson_image']['error'] === 0) {
                $upload_dir = 'uploads/lessons/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['lesson_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                    $new_name = 'lesson_' . time() . '_' . $user['id'] . '.' . $ext;
                    $target = $upload_dir . $new_name;
                    if (move_uploaded_file($_FILES['lesson_image']['tmp_name'], $target)) {
                        // Remove old file
                        if (!empty($edit_row['file_path']) && file_exists($edit_row['file_path'])) {
                            @unlink($edit_row['file_path']);
                        }
                        $new_file_path = $target;
                    }
                }
            }

            // Handle image deletion if user ticked remove-image
            if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
                if (!empty($edit_row['file_path']) && file_exists($edit_row['file_path'])) {
                    @unlink($edit_row['file_path']);
                }
                $new_file_path = null;
            }

            // Validate that the preparation has at least content OR an image
            if (empty($edit_content) && empty($new_file_path)) {
                $_SESSION['prep_edit_error_flash'] = '⚠️ يجب كتابة محتوى/وصف للدرس أو رفع صورة على الأقل.';
                redirect_dashboard_section('prepare');
            }

            $stmt_upd = $conn_edit->prepare("UPDATE preparations SET lesson_title = ?, preparation_date = ?, content = ?, file_path = ?, review_status = 'pending', admin_review_text = NULL, admin_reviewed_at = NULL WHERE id = ? AND user_id = ?");
            $stmt_upd->bind_param("ssssii", $edit_lesson_title, $edit_preparation_date, $edit_content, $new_file_path, $edit_prep_id, $user['id']);
            if ($stmt_upd->execute() && $stmt_upd->affected_rows >= 0) {
                $_SESSION['prep_edit_success_flash'] = '✅ تم تعديل التحضير بنجاح!';
            } else {
                $_SESSION['prep_edit_error_flash'] = '❌ حدث خطأ أثناء التعديل.';
            }
            $stmt_upd->close();
        } catch (Exception $e) {
            $_SESSION['prep_edit_error_flash'] = 'خطأ في قاعدة البيانات.';
        }
        redirect_dashboard_section('prepare');
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lord_brothers'])) {
    $lord_error = '';
    $lord_success = '';
    $selected_students = array_map('intval', $_POST['lord_brother_students'] ?? []);

    if ($user['role'] !== 'خادم') {
        $_SESSION['lord_error_flash'] = 'عذراً، هذه الصفحة متاحة للخدام فقط.';
        redirect_dashboard_section('lord-brothers');
    } else {
        try {
            $conn = get_db();
            ensure_lord_brothers_table($conn);

            $stmt_delete = $conn->prepare("DELETE FROM lord_brothers WHERE selected_by = ? AND family_name = ?");
            $stmt_delete->bind_param("is", $user['id'], $current_user_family);
            $stmt_delete->execute();
            $stmt_delete->close();

            if (!empty($selected_students)) {
                $stmt_check = $conn->prepare("SELECT id FROM students WHERE id = ? AND family_name = ? LIMIT 1");
                $stmt_insert = $conn->prepare("INSERT IGNORE INTO lord_brothers (student_id, selected_by, family_name) VALUES (?, ?, ?)");

                foreach ($selected_students as $student_id) {
                    $stmt_check->bind_param("is", $student_id, $current_user_family);
                    $stmt_check->execute();
                    $check_result = $stmt_check->get_result();
                    if ($check_result && $check_result->num_rows > 0) {
                        $stmt_insert->bind_param("iis", $student_id, $user['id'], $current_user_family);
                        $stmt_insert->execute();
                    }
                }

                $stmt_check->close();
                $stmt_insert->close();
            }

            $_SESSION['lord_success_flash'] = '✅ تم حفظ إخوة الرب المختارين بنجاح.';
        } catch (Exception $e) {
            $_SESSION['lord_error_flash'] = 'خطأ في قاعدة البيانات';
        }
        redirect_dashboard_section('lord-brothers');
    }
}

/**
 * ADMIN: approve/decline preparations
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['admin_approve_prep']) || isset($_POST['admin_decline_prep']))) {
    $error = '';
    $success = '';

    $prep_id = intval($_POST['prep_id'] ?? 0);
    $review_text = trim($_POST['admin_review_text'] ?? '');
    $decision = isset($_POST['admin_approve_prep']) ? 'approved' : 'declined';

    if ($user['role'] !== 'أمين الخدمة') {
        $error = 'عذراً، لا تملك صلاحية مراجعة هذا التحضير.';
    } elseif ($prep_id <= 0) {
        $error = 'Invalid preparation id';
    } elseif ($review_text === '') {
        $error = 'يرجى كتابة محتوى المراجعة قبل الاعتماد أو الرفض.';
    } else {
        try {
            $conn = get_db();
            ensure_preparation_review_columns($conn);

            // Only allow admin of current family to update its own preparations
            $update_sql = "UPDATE preparations p
                JOIN users u ON p.user_id = u.id
                SET p.review_status = ?,
                    p.admin_review_text = ?,
                    p.admin_reviewed_at = NOW(),
                    p.admin_user_id = ?
                WHERE p.id = ? AND u.family_name = ?";

            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ssiis", $decision, $review_text, $user['id'], $prep_id, $current_user_family);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $_SESSION['review_success_flash'] = '✅ تم حفظ المراجعة واتخاذ القرار بنجاح.';
            } else {
                $_SESSION['review_error_flash'] = '❌ Failed to update review';
            }
            $stmt->close();
        } catch (Exception $e) {
            $_SESSION['review_error_flash'] = 'خطأ في قاعدة البيانات';
        }
        redirect_dashboard_section('preparations');
    }
}

/**
 * Handle Student Actions (ADD, EDIT, DELETE)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student'])) {
    $student_id = intval($_POST['student_id'] ?? 0);
    $student_name = trim($_POST['student_name'] ?? '');
    $student_phone = trim($_POST['student_phone'] ?? '');
    $student_address = trim($_POST['student_address'] ?? '');
    $student_birth_date = trim($_POST['student_birth_date'] ?? '');
    // Servants: class is forced to their own assigned class.
    // Admins: can pick any class from the form.
    $student_class_name = trim($_POST['student_class_name'] ?? '');
    $father_job = trim($_POST['father_job'] ?? '');
    $father_phone = trim($_POST['father_phone'] ?? '');
    $father_age = (isset($_POST['father_age']) && $_POST['father_age'] !== '') ? intval($_POST['father_age']) : null;
    $mother_job = trim($_POST['mother_job'] ?? '');
    $mother_phone = trim($_POST['mother_phone'] ?? '');
    $mother_age = (isset($_POST['mother_age']) && $_POST['mother_age'] !== '') ? intval($_POST['mother_age']) : null;
    $notes = trim($_POST['notes'] ?? '');
    $student_profile_members = trim($_POST['profile_members'] ?? '');

    // Enforce: servant can only save students to their own class
    $effective_class = $student_class_name;
    if (!$is_admin) {
        $servant_class = $_SESSION['class_name'] ?? '';
        if ($servant_class === '') {
            $student_error = '⚠️ لم يتم تعيين فصل لك بعد. يرجى تحديث بيانات حسابك أولاً لتحديد فصلك.';
        } else {
            $effective_class = $servant_class;
        }
    }

    if ($student_error === '' && $student_name === '') {
        $student_error = 'يرجى إدخال اسم المخدوم';
    }
    $placeholder_pattern = '/\bمثال\b|example\b/ui';
    $fields_to_check = [
        $student_name        => ($lang === 'ar' ? 'اسم المخدوم' : 'Member Name'),
        $student_phone       => ($lang === 'ar' ? 'رقم الهاتف' : 'Phone'),
        $student_address     => ($lang === 'ar' ? 'العنوان' : 'Address'),
        $father_job          => ($lang === 'ar' ? 'وظيفة الأب' : 'Father Job'),
        $father_phone        => ($lang === 'ar' ? 'هاتف الأب' : 'Father Phone'),
        $mother_job          => ($lang === 'ar' ? 'وظيفة الأم' : 'Mother Job'),
        $mother_phone        => ($lang === 'ar' ? 'هاتف الأم' : 'Mother Phone'),
        $notes               => ($lang === 'ar' ? 'الملاحظات' : 'Notes'),
        $student_profile_members => ($lang === 'ar' ? 'أفراد العائلة' : 'Family Members'),
    ];
    foreach ($fields_to_check as $value => $label) {
        if (preg_match($placeholder_pattern, $value)) {
            $student_error = ($lang === 'ar')
                ? "⚠️ حقل «{$label}» يحتوي على كلمة «مثال» أو «example». يرجى إدخال بيانات حقيقية."
                : "⚠️ The field «{$label}» contains a placeholder word (مثال / example). Please enter real data.";
            break;
        }
    }

    if ($student_error === '') {
        try {
            $conn = get_db();
            ensure_student_columns($conn);

            // Handle member profile picture upload (optional)
            $student_photo_path = null;
            if (isset($_FILES['student_profile_picture']) && $_FILES['student_profile_picture']['error'] === UPLOAD_ERR_OK) {
                $photo_ext = strtolower(pathinfo($_FILES['student_profile_picture']['name'], PATHINFO_EXTENSION));
                $allowed_photo_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (!in_array($photo_ext, $allowed_photo_ext, true) || $_FILES['student_profile_picture']['size'] > 5 * 1024 * 1024) {
                    $student_error = ($lang === 'ar')
                        ? 'صيغة صورة المخدوم غير مدعومة أو الحجم أكبر من 5 ميجا.'
                        : 'Member photo format not supported or larger than 5MB.';
                } else {
                    $photo_dir = 'uploads/members/';
                    if (!is_dir($photo_dir)) mkdir($photo_dir, 0755, true);
                    $photo_name = 'member_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $photo_ext;
                    $photo_target = $photo_dir . $photo_name;
                    if (move_uploaded_file($_FILES['student_profile_picture']['tmp_name'], $photo_target)) {
                        $student_photo_path = $photo_target;
                    } else {
                        $student_error = ($lang === 'ar') ? 'تعذر رفع صورة المخدوم.' : 'Failed to upload member photo.';
                    }
                }
            }

            if ($student_error !== '') {
                // Skip DB write if photo validation/upload failed
            } elseif ($student_id > 0) {
                // Edit existing student — servants can only edit students in their own class
                $where_class = $is_admin ? '' : ' AND class_name = ?';
                $sql_update = "UPDATE students SET 
                    student_name = ?, phone = ?, address = ?, birth_date = ?, class_name = ?, 
                    father_job = ?, father_phone = ?, father_age = ?, 
                    mother_job = ?, mother_phone = ?, mother_age = ?, notes = ?, profile_members = ?
                    WHERE id = ? AND family_name = ?" . $where_class;
                $stmt = $conn->prepare($sql_update);
                if ($is_admin) {
                    $stmt->bind_param("sssssssisisiis",
                        $student_name, $student_phone, $student_address, $student_birth_date, $effective_class,
                        $father_job, $father_phone, $father_age,
                        $mother_job, $mother_phone, $mother_age, $notes, $student_profile_members,
                        $student_id, $current_user_family
                    );
                } else {
                    $servant_class_ref = $_SESSION['class_name'];
                    $stmt->bind_param("sssssssisisiiss",
                        $student_name, $student_phone, $student_address, $student_birth_date, $effective_class,
                        $father_job, $father_phone, $father_age,
                        $mother_job, $mother_phone, $mother_age, $notes, $student_profile_members,
                        $student_id, $current_user_family, $servant_class_ref
                    );
                }
                if ($stmt->execute()) {
                    $student_success = '✅ تم تحديث بيانات المخدوم بنجاح.';
                    // Update profile picture separately when a new one was uploaded
                    if ($student_photo_path !== null) {
                        $old_photo_stmt = $conn->prepare("SELECT profile_picture FROM students WHERE id = ? AND family_name = ? LIMIT 1");
                        $old_photo_stmt->bind_param("is", $student_id, $current_user_family);
                        $old_photo_stmt->execute();
                        $old_photo_row = $old_photo_stmt->get_result()->fetch_assoc();
                        $old_photo_stmt->close();

                        $photo_upd = $conn->prepare("UPDATE students SET profile_picture = ? WHERE id = ? AND family_name = ?");
                        $photo_upd->bind_param("sis", $student_photo_path, $student_id, $current_user_family);
                        $photo_upd->execute();
                        $photo_upd->close();

                        if (!empty($old_photo_row['profile_picture']) && $old_photo_row['profile_picture'] !== $student_photo_path && file_exists($old_photo_row['profile_picture'])) {
                            @unlink($old_photo_row['profile_picture']);
                        }
                    }
                } else {
                    $student_error = '❌ فشل في تحديث بيانات المخدوم.';
                }
                $stmt->close();
            } else {
                // Add new student to the family
                $stmt = $conn->prepare("INSERT INTO students 
                    (student_name, phone, address, birth_date, class_name, 
                     father_job, father_phone, father_age, 
                     mother_job, mother_phone, mother_age, notes, family_name) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssisisis",
                    $student_name, $student_phone, $student_address, $student_birth_date, $effective_class,
                    $father_job, $father_phone, $father_age,
                    $mother_job, $mother_phone, $mother_age, $notes, $current_user_family
                );
                if ($stmt->execute()) {
                    $student_success = '✅ تم إضافة المخدوم بنجاح.';
                    // Attach uploaded profile picture to the newly created member
                    if ($student_photo_path !== null) {
                        $new_student_id = $stmt->insert_id;
                        $photo_upd = $conn->prepare("UPDATE students SET profile_picture = ? WHERE id = ? AND family_name = ?");
                        $photo_upd->bind_param("sis", $student_photo_path, $new_student_id, $current_user_family);
                        $photo_upd->execute();
                        $photo_upd->close();
                    }
                } else {
                    $student_error = '❌ فشل في إضافة المخدوم.';
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $student_error = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
        }
    }

    if ($student_success !== '') {
        $_SESSION['student_success_flash'] = $student_success;
    }
    if ($student_error !== '') {
        $_SESSION['student_error_flash'] = $student_error;
    }
    redirect_dashboard_section('members');
}

/**
 * Handle Student Attendance Recording
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student_attendance'])) {
    $att_date = $_POST['attendance_date'] ?? date('Y-m-d');
    $present_ids = $_POST['present_students'] ?? [];
    if (!is_array($present_ids)) $present_ids = [];
    $present_ids = array_map('intval', $present_ids);

    if (date('N', strtotime($att_date)) !== '5') {
        $attendance_error = 'يمكن تسجيل الحضور ليوم الجمعة فقط. يرجى اختيار يوم جمعة.';
    } else {
        try {
            $conn_a = get_db();
            $conn_a->set_charset("utf8mb4");

            if ($is_admin) {
                $stmt_a = $conn_a->prepare("SELECT id FROM students WHERE family_name = ?");
                $stmt_a->bind_param("s", $current_user_family);
            } else {
                // If user is not admin, filter by family and optionally by class if provided.
                if ($current_user_class !== '') {
                    $stmt_a = $conn_a->prepare("SELECT id FROM students WHERE family_name = ? AND class_name = ?");
                    $stmt_a->bind_param("ss", $current_user_family, $current_user_class);
                } else {
                    // No class specified: select all students for the family
                    $stmt_a = $conn_a->prepare("SELECT id FROM students WHERE family_name = ?");
                    $stmt_a->bind_param("s", $current_user_family);
                }
            }

            if ($stmt_a) {
                $stmt_a->execute();
                $result_a = $stmt_a->get_result();
                $all_student_ids = [];
                while ($row_a = $result_a->fetch_assoc()) {
                    $all_student_ids[] = (int) $row_a['id'];
                }
                $stmt_a->close();

                $insert_stmt = $conn_a->prepare("INSERT INTO student_attendance (student_id, attendance_date, status, recorded_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by)");

                foreach ($all_student_ids as $sid) {
                    $status = in_array($sid, $present_ids) ? 1 : 0;
                    $insert_stmt->bind_param("isii", $sid, $att_date, $status, $user['id']);
                    $insert_stmt->execute();
                }
                $insert_stmt->close();
                $attendance_success = (isset($t) && is_array($t) && isset($t['attendance_saved'])) ? $t['attendance_saved'] : 'تم حفظ الحضور بنجاح.';
            }
        } catch (Exception $e) {
            $attendance_error = 'Database error: ' . $e->getMessage();
        }
    }

    if ($attendance_success !== '') {
        $_SESSION['attendance_success_flash'] = $attendance_success;
    }
    if ($attendance_error !== '') {
        $_SESSION['attendance_error_flash'] = $attendance_error;
    }
    redirect_dashboard_section('members');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    $student_id = intval($_POST['student_id'] ?? 0);
    try {
        $conn = get_db();
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ? AND family_name = ?");
        $stmt->bind_param("is", $student_id, $current_user_family);
        if ($stmt->execute()) {
            $student_success = '✅ تم حذف المخدوم بنجاح.';
        } else {
            $student_error = '❌ فشل في حذف المخدوم.';
        }
        $stmt->close();
    } catch (Exception $e) {
        $student_error = 'خطأ في قاعدة البيانات.';
    }

    if ($student_success !== '') {
        $_SESSION['student_success_flash'] = $student_success;
    }
    if ($student_error !== '') {
        $_SESSION['student_error_flash'] = $student_error;
    }
    redirect_dashboard_section('members');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $new_role = $_POST['role'] ?? '';
    $new_family = $_POST['family_name'] ?? '';
    $new_class = trim($_POST['class_name'] ?? '');

    if ($full_name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $settings_error = 'يرجى إدخال اسم وبريد إلكتروني صحيح.';
    } else {
        try {
            $conn = get_db();

            // ── One-admin-per-family rule ──────────────────────────────────────
            // Servants can ALWAYS switch families freely.
            // The only restriction: a servant cannot become admin in a family that
            // already has an admin. If blocked, the role stays as-is but ALL other
            // fields (name, email, phone, family, picture) are still saved.
            $role_blocked = false;
            if ($new_role === 'أمين الخدمة' && $user['role'] !== 'أمين الخدمة') {
                $chk_admin = $conn->prepare(
                    "SELECT full_name FROM users
                     WHERE role = 'أمين الخدمة'
                       AND family_name = ?
                       AND id != ?
                     LIMIT 1"
                );
                $chk_admin->bind_param('si', $new_family, $user['id']);
                $chk_admin->execute();
                $existing_admin = $chk_admin->get_result()->fetch_assoc();
                $chk_admin->close();

                if ($existing_admin) {
                    $admin_name = htmlspecialchars($existing_admin['full_name']);
                    $settings_error = "⛔ يوجد بالفعل أمين خدمة لهذه الأسرة وهو/هي: «{$admin_name}». لا يمكنك تغيير دورك إلى أمين الخدمة.";
                    $role_blocked = true;
                    // Revert role to current value — family switch still proceeds below
                    $new_role = $user['role'];
                }
            }
            // ──────────────────────────────────────────────────────────────────

            // Ensure profile_picture column exists
            $col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
            if ($col_check && $col_check->num_rows === 0) {
                $conn->query("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");
            }

            // Handle profile picture removal or upload
            $new_avatar_path = null;
            $remove_avatar = isset($_POST['remove_profile_picture']) && $_POST['remove_profile_picture'] === '1';

            if ($remove_avatar) {
                if (!empty($profile['profile_picture']) && file_exists($profile['profile_picture'])) {
                    @unlink($profile['profile_picture']);
                }
                $new_avatar_path = ''; // explicitly clear
            } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
                $avatar_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                $allowed_avatar_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($avatar_ext, $allowed_avatar_ext, true) && $_FILES['profile_picture']['size'] <= 5 * 1024 * 1024) {
                    $avatar_dir = 'uploads/avatars/';
                    if (!is_dir($avatar_dir)) mkdir($avatar_dir, 0755, true);
                    $avatar_name = 'avatar_' . $user['id'] . '_' . time() . '.' . $avatar_ext;
                    $avatar_target = $avatar_dir . $avatar_name;
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $avatar_target)) {
                        // Remove old avatar if exists
                        if (!empty($profile['profile_picture']) && file_exists($profile['profile_picture'])) {
                            @unlink($profile['profile_picture']);
                        }
                        $new_avatar_path = $avatar_target;
                    } else {
                        $settings_error = 'تعذر رفع الصورة الشخصية.';
                    }
                } else {
                    $settings_error = 'صيغة الصورة غير مدعومة أو الحجم أكبر من 5 ميجا.';
                }
            }

            // Always save name, email, phone, family, picture, class.
            // $new_role is either the requested role (if allowed) or original role (if blocked).
            // Admins don't need a class_name, servants do.
            $save_class = ($new_role === 'خادم') ? $new_class : null;
            if ($remove_avatar) {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, family_name = ?, profile_picture = NULL, class_name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssssi", $full_name, $email, $phone, $new_role, $new_family, $save_class, $user['id']);
            } elseif ($new_avatar_path !== null) {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, family_name = ?, profile_picture = ?, class_name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssssssi", $full_name, $email, $phone, $new_role, $new_family, $new_avatar_path, $save_class, $user['id']);
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, family_name = ?, class_name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssssi", $full_name, $email, $phone, $new_role, $new_family, $save_class, $user['id']);
            }
            if ($stmt->execute()) {
                if ($remove_avatar) {
                    $_SESSION['profile_picture'] = null;
                    $profile['profile_picture'] = null;
                } elseif ($new_avatar_path !== null) {
                    $_SESSION['profile_picture'] = $new_avatar_path;
                    $profile['profile_picture'] = $new_avatar_path;
                }
                $_SESSION['role'] = $new_role;
                $_SESSION['family_name'] = $new_family;
                $_SESSION['class_name'] = $save_class ?? '';
                $user['role'] = $new_role;
                $current_user_family = $new_family;
                $current_user_class = $save_class ?? '';
                $is_admin = ($new_role === 'أمين الخدمة');
                $_SESSION['full_name'] = $full_name;
                $_SESSION['phone'] = $phone;
                $user['full_name'] = $full_name;

                if (!$role_blocked) {
                    $settings_success = 'تم تحديث بيانات الحساب بنجاح.';
                } else {
                    // Role was blocked but family + all other data saved successfully
                    $settings_error .= '<br>✅ تم حفظ الاسم والبريد والهاتف والأسرة بنجاح، فقط الدور لم يتغير.';
                }
            } else {
                $settings_error = 'حدث خطأ أثناء تحديث بيانات الحساب.';
            }
            $stmt->close();
        } catch (Exception $e) {
            $settings_error = 'خطأ في قاعدة البيانات.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_security'])) {

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 8) {
        $settings_error = 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل.';
    } elseif ($new_password !== $confirm_password) {
        $settings_error = 'كلمة المرور الجديدة وتأكيدها غير متطابقين.';
    } else {
        try {
            $conn = get_db();
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($current_password, $row['password_hash'])) {
                $settings_error = 'كلمة المرور الحالية غير صحيحة.';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $new_hash, $user['id']);
                if ($stmt->execute()) {
                    $settings_success = 'تم تغيير كلمة المرور بنجاح.';
                } else {
                    $settings_error = 'حدث خطأ أثناء تغيير كلمة المرور.';
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $settings_error = 'خطأ في قاعدة البيانات.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_language'])) {
    $language = ($_POST['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
    $redirect_to = $_POST['redirect_to'] ?? 'dashboard.php';
    if (!preg_match('/^dashboard\.php(?:\?.*)?$/', $redirect_to)) {
        $redirect_to = 'dashboard.php';
    }
    try {
        $conn = get_db();
        $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'language'");
        if ($column_check && $column_check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN language VARCHAR(2) NOT NULL DEFAULT 'ar'");
        }
        $stmt = $conn->prepare("UPDATE users SET language = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $language, $user['id']);
        if ($stmt->execute()) {
            $_SESSION['language'] = $language;
            $_SESSION['settings_success_flash'] = $language === 'en' ? 'Language saved successfully.' : 'تم حفظ اللغة المفضلة.';
        } else {
            $_SESSION['settings_error_flash'] = $language === 'en' ? 'Error saving language.' : 'حدث خطأ أثناء حفظ اللغة.';
        }
        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['settings_error_flash'] = 'خطأ في قاعدة البيانات.';
    }

    header('Location: ' . $redirect_to);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_theme'])) {
    $theme_mode = ($_POST['theme_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
    $message_lang = $_SESSION['language'] ?? 'ar';

    try {
        $conn = get_db();
        ensure_user_theme_column($conn);
        $stmt = $conn->prepare("UPDATE users SET theme_mode = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $theme_mode, $user['id']);
        if ($stmt->execute()) {
            $_SESSION['theme_mode'] = $theme_mode;
            $_SESSION['settings_success_flash'] = $theme_mode === 'dark'
                ? ($message_lang === 'en' ? 'Dark mode saved.' : 'تم حفظ الوضع الداكن.')
                : ($message_lang === 'en' ? 'Light mode saved.' : 'تم حفظ الوضع الفاتح.');
        } else {
            $_SESSION['settings_error_flash'] = $message_lang === 'en' ? 'Error saving appearance.' : 'حدث خطأ أثناء حفظ المظهر.';
        }
        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['settings_error_flash'] = 'خطأ في قاعدة البيانات.';
    }

    header('Location: dashboard.php?section=settings');
    exit;
}

$profile = [
    'full_name' => $user['full_name'],
    'email' => '',
    'phone' => $_SESSION['phone'] ?? '',
    'role' => $user['role'],
    'family_name' => $current_user_family,
    'language' => $_SESSION['language'] ?? 'ar',
    'profile_picture' => $_SESSION['profile_picture'] ?? '',
    'class_name' => $_SESSION['class_name'] ?? '',
    'theme_mode' => $_SESSION['theme_mode'] ?? 'light'
];
try {
    $conn = get_db();
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'language'");
    if ($column_check && $column_check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN language VARCHAR(2) NOT NULL DEFAULT 'ar'");
    }
    $avatar_column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($avatar_column_check && $avatar_column_check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");
    }
    ensure_user_class_column($conn);
    ensure_user_theme_column($conn);
    $stmt = $conn->prepare("SELECT full_name, email, phone, role, family_name, language, profile_picture, class_name, theme_mode FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $profile = array_merge($profile, $row);
        $_SESSION['language'] = $profile['language'] ?: 'ar';
        $_SESSION['profile_picture'] = $profile['profile_picture'] ?: '';
        $_SESSION['class_name'] = $profile['class_name'] ?: '';
        $_SESSION['theme_mode'] = $profile['theme_mode'] ?: 'light';
    }
    $stmt->close();
} catch (Exception $e) { }

// The class the current servant is assigned to (empty string = unassigned)
$current_user_class = $profile['class_name'] ?? '';

// ================================================
// LANGUAGE & TRANSLATION SYSTEM
// ================================================
$lang    = $_SESSION['language'] ?? 'ar';
$isRtl   = ($lang === 'ar');
$htmlDir = $isRtl ? 'rtl' : 'ltr';
$htmlLang = $isRtl ? 'ar' : 'en';
$tableAlign = $isRtl ? 'right' : 'left';
$sidebarBorder    = $isRtl ? 'border-left: 3px solid var(--gold);' : 'border-right: 3px solid var(--gold);';
$menuActiveBorder = $isRtl ? 'border-right: 5px solid var(--gold-light);' : 'border-left: 5px solid var(--gold-light);';
$welcomeBorderSide = $isRtl ? 'border-right' : 'border-left';
$themeMode = ($profile['theme_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
$bodyClass = $themeMode === 'dark' ? 'theme-dark' : '';

$translations = [
    'ar' => [
        'page_title'             => 'لوحة التحكم | كنيسة العذراء والملاك ميخائيل',
        'church_name'            => 'كنيسة العذراء والملاك ميخائيل',
        'menu_home'              => '🏠 الرئيسية',
        'menu_members'           => '👥 مخدومين الأسرة',
        'menu_prepare'           => '📝 تحضير اليوم',
        'menu_lord_brothers'     => '🤝 إخوة الرب',
        'menu_my_qr'             => '🆔 الـ QR Code الخاص بي',
        'menu_servants'          => '👥 خدام الأسرة',
        'menu_preparations'      => '📚 تحضير الخدام',
        'menu_reports'           => '📊 تقارير الخدمة',
        'menu_contact'           => '📍 تواصل معنا',
        'menu_chat'              => '💬 دردشة الأسرة',
        'menu_settings'          => '⚙️ إعدادات الحساب',
        'menu_reminders'         => '🔔 إرسال التذكيرات',
        'menu_logout'            => '🚪 تسجيل الخروج',
        'role_admin'             => 'أمين الخدمة',
        'role_servant'           => 'خادم',
        'welcome_prefix'         => 'مرحباً بك، أستاذ/ة',
        'welcome_admin_sub'      => 'أهلاً بك في لوحة إدارة الأسرة والخدمة الفاخرة',
        'welcome_servant_sub'    => 'أهلاً بك في بوابة الكنيسة الإلكترونية للمتابعة والخدمة',
        'family_name_label'      => 'اسم الأسرة الحالي',
        'meeting_day'            => 'يوم الاجتماع',
        'meeting_time'           => 'الميعاد والوقت',
        'meeting_location'       => 'مكان الاجتماع',
        'next_spiritual_day'     => 'اليوم الروحي القادم',
        'not_set'                => 'غير محدد',
        'members_title'          => '👥 قائمة مخدومين الأسرة',
        'members_desc'           => 'تعرض هذه القائمة المخدومين المسجلين في أسركتكم للمتابعة وافتقادهم تلفونياً أو ميدانياً:',
        'col_student_name'       => 'اسم المخدوم',
        'col_phone'              => 'رقم الهاتف',
        'col_address'            => 'العنوان',
        'col_birth_date'         => 'تاريخ الميلاد',
        'col_notes'              => 'ملاحظات الافتقاد',
        'btn_call'               => '📞 اتصال',
        'not_registered'         => 'غير مسجل',
        'no_notes'               => 'لا توجد ملاحظات',
        'no_members'             => 'لا يوجد مخدومين مضافين لهذه الأسرة حالياً في الداتابيز.',
        'prepare_title'          => '📝 تحضير درس اليوم',
        'lesson_title_label'     => 'عنوان الدرس *',
        'prep_date_label'        => 'تاريخ التحضير',
        'upload_image_label'     => 'رفع صورة',
        'lesson_content_label'   => 'محتوى الدرس',
        'btn_save_prep'          => 'حفظ التحضير',
        'preparations_title'     => '📚 تحضيرات الخدام',
        'no_preparations'        => 'لم يتم رفع أي تحضير بعد.',
        'servants_title'         => '👥 خدام الأسرة التابعين لـ',
        'servants_desc'          => 'تعرض هذه القائمة الخدام النشطين، يمكنك مسح كود الـ QR لتسجيل حضورهم اليوم:',
        'col_servant_name'       => 'اسم الخادم',
        'col_email'              => 'البريد الإلكتروني',
        'col_qr'                 => 'رمز الحضور QR',
        'btn_view_qr'            => '🔍 عرض الـ QR',
        'no_servants'            => 'لا يوجد خدام مسجلين حالياً.',
        'reports_title'          => '📊 تقارير الخدمة: متوسط الحضور الدوري',
        'reports_desc'           => 'يتم حساب نسبة الحضور المئوية بناءً على إجمالي عدد الاجتماعات الفعلي المنعقد للأسرة وتتحدث ديناميكياً.',
        'col_attended_days'      => 'أيام الحضور الفعلي',
        'col_total_meetings'     => 'إجمالي الاجتماعات',
        'col_avg_attendance'     => 'نسبة متوسط الحضور',
        'unit_day'               => 'يوم',
        'unit_meeting'           => 'اجتماع',
        'no_reports'             => 'لا توجد بيانات حضور مسجلة حتى الآن.',
        'settings_title'         => '⚙️ إعدادات الحساب',
        'profile_title'          => 'الملف الشخصي',
        'profile_picture_label'  => 'الصورة الشخصية',
        'remove_profile_picture_label' => 'حذف الصورة الشخصية',
        'chat_title'             => '💬 دردشة الأسرة',
        'chat_subtitle'          => 'تواصل مع أعضاء أسرتك في مكان واحد',
        'chat_placeholder'       => 'اكتب رسالتك هنا...',
        'chat_send'              => 'إرسال',
        'chat_delete'            => 'حذف الرسالة',
        'chat_confirm_delete'    => 'هل أنت متأكد من حذف هذه الرسالة؟',
        'chat_options'           => 'خيارات الدردشة',
        'chat_delete_all'        => 'حذف كل الدردشة',
        'chat_confirm_delete_all' => 'هل أنت متأكد من حذف كل رسائل الأسرة؟ لا يمكن التراجع عن هذه العملية.',
        'chat_empty'             => 'لا توجد رسائل بعد، كن أول من يبدأ الحديث!',
        'full_name_label'        => 'الاسم الكامل',
        'email_label'            => 'البريد الإلكتروني',
        'phone_label'            => 'رقم الهاتف',
        'role_label'             => 'الدور',
        'family_label'           => 'الأسرة',
        'btn_save_profile'       => 'حفظ البيانات',
        'register_btn'           => 'إضافة مخدوم',
        'security_title'         => 'الأمان',
        'current_password_label' => 'كلمة المرور الحالية',
        'new_password_label'     => 'كلمة المرور الجديدة',
        'confirm_password_label' => 'تأكيد كلمة المرور الجديدة',
        'btn_change_password'    => 'تغيير كلمة المرور',
        'language_title'         => 'اللغة',
        'lang_arabic'            => 'عربي',
        'lang_english'           => 'English',
        'btn_save_language'      => 'حفظ اللغة',
        'appearance_title'       => 'المظهر',
        'theme_light'            => 'الوضع الفاتح',
        'theme_dark'             => 'الوضع الداكن',
        'btn_save_theme'         => 'حفظ المظهر',
        'qr_popup_title'         => 'الرمز الخاص بالخادم لتسجيل الحضور',
        'qr_popup_desc'          => 'يتم مسح هذا الرمز بواسطة هاتف أمين الخدمة لتسجيل حضور اليوم تلقائياً.',
        'role_option_servant'    => 'خادم',
        'role_option_admin'      => 'أمين الخدمة',

        // Admin review workflow (preparations)
        'prep_review_title'       => '📝 مراجعة التحضير من أمين الخدمة',
        'prep_review_text_label'  => 'محتوى المراجعة *',
        'prep_btn_approve'        => '✅ اعتماد',
        'prep_btn_decline'        => '❌ رفض',
        'prep_status_pending'     => 'قيد المراجعة',
        'prep_status_approved'    => 'تم الاعتماد',
        'prep_status_declined'    => 'تم الرفض',
        'prep_review_saved'       => '✅ تم حفظ المراجعة واتخاذ القرار بنجاح.',
        'prep_review_required'    => 'يرجى كتابة محتوى المراجعة قبل الاعتماد أو الرفض.',
        'prep_review_wrong_role'  => 'عذراً، لا تملك صلاحية مراجعة هذا التحضير.',
        'my_preparations_title'   => '📚 تحضيراتي المرفوعة',
        'admin_review_label'      => 'مراجعة أمين الخدمة',
        'no_review_yet'           => 'لم يتم كتابة مراجعة بعد.',
        'btn_edit_prep'           => '✏️ تعديل',
        'btn_delete_prep'         => '🗑️ حذف',
        'edit_prep_title'         => 'تعديل التحضير',
        'btn_save_edit_prep'      => 'حفظ التعديلات',
        'btn_cancel'              => 'إلغاء',
        'remove_image_label'      => 'حذف الصورة الحالية',
        'confirm_delete_prep'     => 'هل أنت متأكد من حذف هذا التحضير؟ لا يمكن التراجع عن هذه العملية.',
        'lord_brothers_title'     => '🤝 إخوة الرب',
        'lord_brothers_desc'      => 'اختر من مخدومين الأسرة الأسماء التي تريد إضافتها لقائمة إخوة الرب.',
        'lord_brothers_save'      => 'حفظ إخوة الرب',
        'lord_brothers_saved'     => '✅ تم حفظ إخوة الرب المختارين بنجاح.',
        'lord_brothers_admin_desc' => 'هذه القائمة تعرض إخوة الرب الذين اختارهم الخدام من مخدومين الأسرة.',
        'selected_by_label'       => 'تم الاختيار بواسطة',
        'selected_at_label'       => 'تاريخ الاختيار',
        'no_lord_brothers'        => 'لم يتم اختيار أي أسماء حتى الآن.',

        // Student attendance
        'btn_take_attendance'     => '📋 تسجيل الحضور',
        'btn_save_attendance'     => '💾 حفظ الحضور',
        'attendance_title'        => 'تسجيل حضور المخدومين',
        'attendance_desc'         => 'اختر المخدومين الحاضرين اليوم وسجل حضورهم.',
        'attendance_date'         => 'تاريخ الحضور',
        'attendance_saved'        => '✅ تم تسجيل الحضور بنجاح.',
        'attendance_status'       => 'الحضور',
        'present'                 => '✅ حاضر',
        'absent'                  => '❌ غائب',
        'no_attendance_yet'       => 'لم يتم تسجيل حضور بعد',
        'member_attendance_title'   => '📊 تقرير حضور المخدومين',
        'member_attendance_desc'    => 'عرض سجل الحضور لجميع المخدومين في الأسرة مع إمكانية تصدير إكسل.',
        'filter_all_classes'        => 'جميع الفصول',
        'filter_class'              => 'تصفية حسب الفصل',
        'filter_date_from'          => 'من تاريخ',
        'filter_date_to'            => 'إلى تاريخ',
        'btn_export_excel'          => '📥 تصدير إكسل',
        'col_total_attendance'      => 'إجمالي الحضور',
        'col_total_absence'         => 'إجمالي الغياب',
        'col_attendance_percent'    => 'نسبة الحضور',
        'col_student_class'         => 'الفصل (القديس)',
    ],
    'en' => [
        'page_title'             => 'Dashboard | Virgin Mary & Archangel Michael Church',
        'church_name'            => 'Virgin Mary & Archangel Michael Church',
        'menu_home'              => '🏠 Home',
        'menu_members'           => '👥 Members',
        'menu_prepare'           => '📝 Prepare Today',
        'menu_lord_brothers'     => '🤝 Lord\'s Brothers',
        'menu_my_qr'             => '🆔 My QR Code',
        'menu_servants'          => '👥 Servants',
        'menu_preparations'      => '📚 Servants\' Preparations',
        'menu_reports'           => '📊 Service Reports',
        'menu_contact'           => '📍 Contact Us',
        'menu_chat'              => '💬 Family Chat',
        'menu_settings'          => '⚙️ Account Settings',
        'menu_reminders'         => '🔔 Send Reminders',
        'menu_logout'            => '🚪 Logout',
        'role_admin'             => 'Service Leader',
        'role_servant'           => 'Servant',
        'welcome_prefix'         => 'Welcome,',
        'welcome_admin_sub'      => 'Welcome to the Family & Ministry Management Dashboard',
        'welcome_servant_sub'    => 'Welcome to the Church Electronic Portal for Follow-up & Ministry',
        'family_name_label'      => 'Current Family Name',
        'meeting_day'            => 'Meeting Day',
        'meeting_time'           => 'Meeting Time',
        'meeting_location'       => 'Meeting Location',
        'next_spiritual_day'     => 'Next Spiritual Day',
        'not_set'                => 'Not set',
        'members_title'          => '👥 Family Members List',
        'members_desc'           => 'This list shows the registered members of your family for phone or in-person follow-up:',
        'col_student_name'       => 'Member Name',
        'col_phone'              => 'Phone Number',
        'col_address'            => 'Address',
        'col_birth_date'         => 'Birth Date',
        'col_notes'              => 'Follow-up Notes',
        'btn_call'               => '📞 Call',
        'not_registered'         => 'Not registered',
        'no_notes'               => 'No notes',
        'no_members'             => 'No members added for this family yet.',
        'prepare_title'          => '📝 Prepare Today\'s Lesson',
        'lesson_title_label'     => 'Lesson Title *',
        'prep_date_label'        => 'Preparation Date',
        'upload_image_label'     => 'Upload Image',
        'lesson_content_label'   => 'Lesson Content',
        'btn_save_prep'          => 'Save Preparation',
        'preparations_title'     => '📚 Servants\' Preparations',
        'no_preparations'        => 'No preparations have been uploaded yet.',
        'servants_title'         => '👥 Family Servants under',
        'servants_desc'          => 'This list shows active servants. You can scan QR codes to record their attendance today:',
        'col_servant_name'       => 'Servant Name',
        'col_email'              => 'Email',
        'col_qr'                 => 'Attendance QR Code',
        'btn_view_qr'            => '🔍 View QR',
        'no_servants'            => 'No servants registered currently.',
        'reports_title'          => '📊 Service Reports: Average Periodic Attendance',
        'reports_desc'           => 'Attendance percentage is calculated based on total actual meetings held for the family and updates dynamically.',
        'col_attended_days'      => 'Attended Days',
        'col_total_meetings'     => 'Total Meetings',
        'col_avg_attendance'     => 'Avg. Attendance %',
        'unit_day'               => 'day(s)',
        'unit_meeting'           => 'meeting(s)',
        'no_reports'             => 'No attendance data recorded yet.',
        'settings_title'         => '⚙️ Account Settings',
        'profile_title'          => 'Profile',
        'profile_picture_label'  => 'Profile Picture',
        'remove_profile_picture_label' => 'Remove Profile Picture',
        'chat_title'             => '💬 Family Chat',
        'chat_subtitle'          => 'Connect with your family members in one place',
        'chat_placeholder'       => 'Type your message...',
        'chat_send'              => 'Send',
        'chat_delete'            => 'Delete message',
        'chat_confirm_delete'    => 'Are you sure you want to delete this message?',
        'chat_options'           => 'Chat options',
        'chat_delete_all'        => 'Delete all chat',
        'chat_confirm_delete_all' => 'Are you sure you want to delete all family chat messages? This cannot be undone.',
        'chat_empty'             => 'No messages yet, be the first to say hello!',
        'full_name_label'        => 'Full Name',
        'email_label'            => 'Email',
        'phone_label'            => 'Phone Number',
        'role_label'             => 'Role',
        'family_label'           => 'Family',
        'btn_save_profile'       => 'Save Profile',
        'register_btn'           => 'Add Member',
        'security_title'         => 'Security',
        'current_password_label' => 'Current Password',
        'new_password_label'     => 'New Password',
        'confirm_password_label' => 'Confirm New Password',
        'btn_change_password'    => 'Change Password',
        'language_title'         => 'Language',
        'lang_arabic'            => 'عربي',
        'lang_english'           => 'English',
        'btn_save_language'      => 'Save Language',
        'appearance_title'       => 'Appearance',
        'theme_light'            => 'Light Mode',
        'theme_dark'             => 'Dark Mode',
        'btn_save_theme'         => 'Save Appearance',
        'qr_popup_title'         => 'Servant QR Code for Attendance',
        'qr_popup_desc'          => 'This code is scanned by the Service Leader\'s phone to record today\'s attendance automatically.',
        'role_option_servant'    => 'خادم',
        'role_option_admin'      => 'أمين الخدمة',

        // Admin review workflow (preparations)
        'prep_review_title'       => '📝 Preparation review by the Service Leader',
        'prep_review_text_label'  => 'Review content *',
        'prep_btn_approve'        => '✅ Approve',
        'prep_btn_decline'        => '❌ Decline',
        'prep_status_pending'     => 'Pending review',
        'prep_status_approved'    => 'Approved',
        'prep_status_declined'    => 'Declined',
        'prep_review_saved'       => '✅ Review saved and decision updated successfully.',
        'prep_review_required'    => 'Please write the review content before approving or declining.',
        'prep_review_wrong_role'  => 'Sorry, you don’t have permission to review this preparation.',
        'my_preparations_title'    => '📚 My Uploaded Preparations',
        'admin_review_label'       => 'Service Leader Review',
        'no_review_yet'            => 'No review has been written yet.',
        'btn_edit_prep'           => '✏️ Edit',
        'btn_delete_prep'         => '🗑️ Delete',
        'edit_prep_title'         => 'Edit Preparation',
        'btn_save_edit_prep'      => 'Save Changes',
        'btn_cancel'              => 'Cancel',
        'remove_image_label'      => 'Remove current image',
        'confirm_delete_prep'     => 'Are you sure you want to delete this preparation? This action cannot be undone.',
        'lord_brothers_title'       => '🤝 Lord\'s Brothers',
        'lord_brothers_desc'        => 'Choose from the family members database who should be added to the Lord\'s Brothers list.',
        'lord_brothers_save'        => 'Save Lord\'s Brothers',
        'lord_brothers_saved'       => '✅ Lord\'s Brothers saved successfully.',
        'lord_brothers_admin_desc'  => 'This list shows the Lord\'s Brothers selected by servants from the family members database.',
        'selected_by_label'         => 'Selected By',
        'selected_at_label'         => 'Selected At',
        'no_lord_brothers'          => 'No names have been selected yet.',

        // Student attendance
        'btn_take_attendance'       => '📋 Take Attendance',
        'btn_save_attendance'       => '💾 Save Attendance',
        'attendance_title'          => 'Record Member Attendance',
        'attendance_desc'           => 'Select the members who are present today and record their attendance.',
        'attendance_date'           => 'Attendance Date',
        'attendance_saved'          => '✅ Attendance recorded successfully.',
        'attendance_status'         => 'Attendance',
        'present'                   => '✅ Present',
        'absent'                    => '❌ Absent',
        'no_attendance_yet'         => 'No attendance recorded yet',
        'member_attendance_title'   => '📊 Members Attendance Report',
        'member_attendance_desc'    => 'View attendance records for all family members with Excel export.',
        'filter_all_classes'        => 'All Classes',
        'filter_class'              => 'Filter by Class',
        'filter_date_from'          => 'From Date',
        'filter_date_to'            => 'To Date',
        'btn_export_excel'          => '📥 Export Excel',
        'col_total_attendance'      => 'Total Present',
        'col_total_absence'         => 'Total Absent',
        'col_attendance_percent'    => 'Attendance %',
        'col_student_class'         => 'Class (Saint)',
    ],
];

$t = $translations[$lang];

// Date string based on language
if ($lang === 'ar') {
    $ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
    $ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    $today = $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');
} else {
    $today = date('l, F j, Y');
}

function require_tcpdf() {
    return null;
}

function tcpdf_arabic_font($tcpdf_dir) {
    $font_dir = $tcpdf_dir . '/fonts';
    if (is_file($font_dir . '/aealarabiya.php') && is_file($font_dir . '/aealarabiya.z')) {
        return 'aealarabiya';
    }
    if (is_file($font_dir . '/dejavusans.php')) {
        return 'dejavusans';
    }
    return 'helvetica';
}

function render_printable_pdf_page($title, $subtitle, $headers, $rows, $filename) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($filename) ?></title>
    <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: Tahoma, Arial, sans-serif; color: #222; direction: rtl; }
        h1 { margin: 0 0 6px; text-align: center; color: #2C2416; font-size: 22px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 18px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #2C3E50; color: #fff; }
        th, td { border: 1px solid #aaa; padding: 7px 6px; text-align: center; }
        tbody tr:nth-child(even) { background: #f4f4f4; }
        .print-actions { display: flex; gap: 8px; justify-content: center; margin: 18px 0; }
        .print-actions button { border: 0; border-radius: 6px; padding: 8px 18px; background: #C0392B; color: #fff; cursor: pointer; }
        @media print { .print-actions { display: none; } }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($title) ?></h1>
    <div class="subtitle"><?= htmlspecialchars($subtitle) ?></div>
    <div class="print-actions">
        <button onclick="window.print()">طباعة / Save as PDF</button>
    </div>
    <table>
        <thead>
            <tr>
                <?php foreach ($headers as $header): ?>
                    <th><?= htmlspecialchars($header) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                        <td><?= htmlspecialchars((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
    <?php
    exit;
}

if (isset($_GET['export_choice']) && $is_admin) {
    $export_type = $_GET['export_choice'] === 'attendance' ? 'attendance' : 'servants';
    $att_class = $_GET['att_class'] ?? 'all';
    $att_from = $_GET['att_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $att_to = $_GET['att_to'] ?? date('Y-m-d');

    if ($export_type === 'attendance') {
        $page_title = $lang === 'ar' ? 'اختيار طريقة تصدير حضور المخدومين' : 'Choose Members Attendance Export';
        $excel_url = 'dashboard.php?export_members_attendance=1&att_class=' . urlencode($att_class) . '&att_from=' . urlencode($att_from) . '&att_to=' . urlencode($att_to);
        $pdf_url = 'dashboard.php?export_members_attendance_pdf=1&att_class=' . urlencode($att_class) . '&att_from=' . urlencode($att_from) . '&att_to=' . urlencode($att_to);
    } else {
        $page_title = $lang === 'ar' ? 'اختيار طريقة تصدير الخدام' : 'Choose Servants Export';
        $excel_url = 'dashboard.php?export_servants_excel=1';
        $pdf_url = 'dashboard.php?export_members_pdf=1';
    }
    ?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>" dir="<?= $htmlDir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Arial, Tahoma, sans-serif; background: #F8F5EF; color: #2C2416; }
        .export-box { width: min(92vw, 480px); background: #fff; border: 1px solid #E0D7C7; border-top: 5px solid #C8973A; border-radius: 10px; padding: 28px; box-shadow: 0 12px 32px rgba(44, 36, 22, 0.12); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 0 0 22px; color: #6D5D45; line-height: 1.6; }
        .export-actions { display: grid; gap: 12px; }
        a { display: block; text-align: center; padding: 13px 16px; border-radius: 8px; text-decoration: none; font-weight: 700; color: #fff; background: #2C3E50; }
        a.pdf { background: #C0392B; }
        a.back { background: #8A8175; }
    </style>
</head>
<body>
    <main class="export-box">
        <h1><?= htmlspecialchars($page_title) ?></h1>
        <p><?= $lang === 'ar' ? 'اختر طريقة التصدير المطلوبة. خيار الطباعة يفتح التقرير في نافذة الطباعة ويمكنك اختيار Save as PDF.' : 'Choose how to export. Print opens the print dialog where you can choose Save as PDF.' ?></p>
        <div class="export-actions">
            <a href="<?= htmlspecialchars($excel_url) ?>"><?= $lang === 'ar' ? 'تصدير Excel' : 'Export Excel' ?></a>
            <a class="pdf" href="<?= htmlspecialchars($pdf_url) ?>" target="_blank"><?= $lang === 'ar' ? 'طباعة / حفظ PDF' : 'Print / Save PDF' ?></a>
            <a class="back" href="dashboard.php" onclick="window.close(); return false;"><?= $lang === 'ar' ? 'إغلاق' : 'Close' ?></a>
        </div>
    </main>
</body>
</html>
    <?php
    exit;
}

/**
 * Excel Export Handler for Members Attendance — XLSX (Office Open XML)
 */
if (isset($_GET['export_members_attendance']) && $is_admin) {
    require_once __DIR__ . '/vendor/xlsxwriter/xlsxwriter.class.php';

    $exp_family = $current_user_family;
    $exp_class  = $_GET['att_class'] ?? ($_GET['class'] ?? '');
    $exp_from   = $_GET['att_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $exp_to     = $_GET['att_to']   ?? date('Y-m-d');

    $exp_conn = get_db();

    $exp_where  = "WHERE s.family_name = ?";
    $exp_params = [$exp_family];
    $exp_types  = "s";
    if ($exp_class !== '' && $exp_class !== 'all') {
        $exp_where   .= " AND s.class_name = ?";
        $exp_params[] = $exp_class;
        $exp_types   .= "s";
    }
    $exp_sql  = "SELECT s.id, s.student_name, s.class_name, s.phone, s.address, s.birth_date,
                        s.father_job, s.father_phone, s.father_age,
                        s.mother_job, s.mother_phone, s.mother_age, s.notes
                 FROM students s $exp_where ORDER BY s.class_name ASC, s.student_name ASC";
    $exp_stmt = $exp_conn->prepare($exp_sql);
    if ($exp_stmt) {
        $exp_stmt->bind_param($exp_types, ...$exp_params);
        $exp_stmt->execute();
        $exp_result = $exp_stmt->get_result();

        $exp_att_summary = [];
        $att_stmt = $exp_conn->prepare(
            "SELECT sa.student_id, sa.status
             FROM student_attendance sa
             JOIN students s ON sa.student_id = s.id
             WHERE s.family_name = ? AND sa.attendance_date BETWEEN ? AND ?"
        );
        if ($att_stmt) {
            $att_stmt->bind_param("sss", $exp_family, $exp_from, $exp_to);
            $att_stmt->execute();
            $att_result = $att_stmt->get_result();
            while ($att = $att_result->fetch_assoc()) {
                $sid = (int) $att['student_id'];
                if (!isset($exp_att_summary[$sid])) $exp_att_summary[$sid] = ['present' => 0, 'absent' => 0];
                $st = strtolower(trim((string) $att['status']));
                if ($st === '1' || $st === 'present' || $st === 'حاضر') $exp_att_summary[$sid]['present']++;
                else $exp_att_summary[$sid]['absent']++;
            }
            $att_stmt->close();
        }

        $writer = new XLSXWriter();
        $sheet  = 'حضور المخدومين';

        $title_style  = ['font-style' => 'bold', 'font-size' => 14, 'fill' => '2C3E50', 'font-color' => 'FFFFFF', 'halign' => 'center', 'border' => 'thin'];
        $header_style = ['font-style' => 'bold', 'font-size' => 11, 'fill' => 'D9EAD3', 'font-color' => '1E5C1E',  'border' => 'thin', 'halign' => 'center'];
        $even_style   = ['border' => 'thin', 'fill' => 'FFFFFF'];
        $odd_style    = ['border' => 'thin', 'fill' => 'F9FBF9'];

        $writer->writeSheetRow($sheet,
            ['تقرير حضور المخدومين — أسرة: ' . $exp_family . '  (' . $exp_from . ' ← ' . $exp_to . ')',
             '','','','','','','','','','','','','',''],
            $title_style
        );
        $writer->writeSheetRow($sheet, [
            $t['col_student_name'], $t['col_student_class'], $t['col_phone'],
            'العنوان', 'تاريخ الميلاد', 'وظيفة الأب', 'هاتف الأب', 'سن الأب',
            'وظيفة الأم', 'هاتف الأم', 'سن الأم', $t['col_notes'],
            $t['col_total_attendance'], $t['col_total_absence'], $t['col_attendance_percent'],
        ], $header_style);

        $row_num = 0;
        while ($exp_row = $exp_result->fetch_assoc()) {
            $sid   = (int) $exp_row['id'];
            $p     = $exp_att_summary[$sid]['present'] ?? 0;
            $ab    = $exp_att_summary[$sid]['absent']  ?? 0;
            $total = $p + $ab;
            $pct   = ($total > 0 ? round(($p / $total) * 100) : 0) . '%';
            $style = ($row_num % 2 === 0) ? $even_style : $odd_style;
            $writer->writeSheetRow($sheet, [
                $exp_row['student_name']  ?? '',
                $exp_row['class_name']    ?? '',
                $exp_row['phone']         ?? '',
                $exp_row['address']       ?? '',
                $exp_row['birth_date']    ?? '',
                $exp_row['father_job']    ?? '',
                $exp_row['father_phone']  ?? '',
                (int)($exp_row['father_age'] ?? 0),
                $exp_row['mother_job']    ?? '',
                $exp_row['mother_phone']  ?? '',
                (int)($exp_row['mother_age'] ?? 0),
                $exp_row['notes']         ?? '',
                $p, $ab, $pct,
            ], $style);
            $row_num++;
        }

        $writer->writeSheetHeader($sheet,
            array_combine(
                ['a','b','c','d','e','f','g','h','i','j','k','l','m','n','o'],
                array_map(fn($x) => ['type' => 'string'], range(1, 15))
            ),
            ['widths' => [28,22,16,28,14,20,16,8,20,16,8,26,12,12,14], 'suppress_row' => true]
        );

        $safe_family  = preg_replace('/[^A-Za-z0-9_]/', '_', $exp_family);
        $exp_filename = 'members_attendance_' . $safe_family . '_' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $exp_filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $writer->writeToStdOut();
        $exp_stmt->close();
    }
    $exp_conn->close();
    exit;
}

if (isset($_GET['export_members_attendance_pdf']) && $is_admin) {
    $tcpdf_dir = require_tcpdf();

    $exp_family = $current_user_family;
    $exp_class  = $_GET['att_class'] ?? 'all';
    $exp_from   = $_GET['att_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $exp_to     = $_GET['att_to'] ?? date('Y-m-d');
    $exp_conn   = get_db();

    $exp_where  = "WHERE s.family_name = ?";
    $exp_params = [$exp_family];
    $exp_types  = "s";
    if ($exp_class !== '' && $exp_class !== 'all') {
        $exp_where .= " AND s.class_name = ?";
        $exp_params[] = $exp_class;
        $exp_types .= "s";
    }

    $exp_stmt = $exp_conn->prepare(
        "SELECT s.id, s.student_name, s.class_name, s.phone
         FROM students s $exp_where
         ORDER BY s.class_name ASC, s.student_name ASC"
    );
    $exp_stmt->bind_param($exp_types, ...$exp_params);
    $exp_stmt->execute();
    $rows = $exp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $exp_stmt->close();

    $att_stats = [];
    $stats_stmt = $exp_conn->prepare(
        "SELECT sa.student_id,
                SUM(CASE WHEN sa.status = 1 OR sa.status = 'present' OR sa.status = 'حاضر' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN sa.status = 0 OR sa.status = 'absent' OR sa.status = 'غائب' THEN 1 ELSE 0 END) AS absent_count
         FROM student_attendance sa
         JOIN students s ON sa.student_id = s.id
         WHERE s.family_name = ? AND sa.attendance_date BETWEEN ? AND ?
         GROUP BY sa.student_id"
    );
    $stats_stmt->bind_param("sss", $exp_family, $exp_from, $exp_to);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    while ($stat = $stats_result->fetch_assoc()) {
        $att_stats[(int) $stat['student_id']] = $stat;
    }
    $stats_stmt->close();
    $exp_conn->close();

    $safe_family = preg_replace('/[^A-Za-z0-9_]/', '_', $exp_family);
    if ($tcpdf_dir === null || !class_exists('TCPDF')) {
        $print_rows = [];
        foreach ($rows as $row) {
            $sid = (int) $row['id'];
            $present = (int) ($att_stats[$sid]['present_count'] ?? 0);
            $absent = (int) ($att_stats[$sid]['absent_count'] ?? 0);
            $total = $present + $absent;
            $print_rows[] = [
                $row['student_name'] ?? '',
                $row['class_name'] ?? '',
                $row['phone'] ?? '',
                $present,
                $absent,
                $total > 0 ? round(($present / $total) * 100) . '%' : '0%',
            ];
        }
        render_printable_pdf_page(
            'تقرير حضور المخدومين',
            $exp_family . ' | ' . $exp_from . ' إلى ' . $exp_to,
            ['اسم المخدوم', 'الفصل', 'الهاتف', 'الحضور', 'الغياب', 'النسبة'],
            $print_rows,
            'members_attendance_' . $safe_family . '_' . date('Y-m-d')
        );
    }

    $pdf_font = tcpdf_arabic_font($tcpdf_dir);
    $pdf->SetCreator('كنيسة العذراء والملاك ميخائيل');
    $pdf->SetAuthor('كنيسة العذراء والملاك ميخائيل');
    $pdf->SetTitle('تقرير حضور المخدومين - ' . $exp_family);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();
    $pdf->setRTL(true);

    $pdf->SetFont($pdf_font, 'B', 16);
    $pdf->SetTextColor(44, 36, 22);
    $pdf->Cell(0, 10, 'تقرير حضور المخدومين', 0, 1, 'C');
    $pdf->SetFont($pdf_font, '', 10);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 6, $exp_family . ' | ' . $exp_from . ' إلى ' . $exp_to, 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFillColor(44, 62, 80);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf_font, 'B', 9);
    $col_w = [55, 38, 32, 24, 24, 28];
    $headers = ['اسم المخدوم', 'الفصل', 'الهاتف', 'الحضور', 'الغياب', 'النسبة'];
    foreach ($headers as $index => $header) {
        $pdf->Cell($col_w[$index], 8, $header, 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont($pdf_font, '', 9);
    $pdf->SetTextColor(34, 34, 34);
    foreach ($rows as $index => $row) {
        $sid = (int) $row['id'];
        $present = (int) ($att_stats[$sid]['present_count'] ?? 0);
        $absent = (int) ($att_stats[$sid]['absent_count'] ?? 0);
        $total = $present + $absent;
        $percent = $total > 0 ? round(($present / $total) * 100) . '%' : '0%';
        $fill = ($index % 2) === 1;
        $pdf->SetFillColor(242, 242, 242);
        $pdf->Cell($col_w[0], 7, $row['student_name'] ?? '', 1, 0, 'R', $fill);
        $pdf->Cell($col_w[1], 7, $row['class_name'] ?? '', 1, 0, 'R', $fill);
        $pdf->Cell($col_w[2], 7, $row['phone'] ?? '', 1, 0, 'C', $fill);
        $pdf->Cell($col_w[3], 7, (string) $present, 1, 0, 'C', $fill);
        $pdf->Cell($col_w[4], 7, (string) $absent, 1, 0, 'C', $fill);
        $pdf->Cell($col_w[5], 7, $percent, 1, 0, 'C', $fill);
        $pdf->Ln();
    }

    $pdf->Output('members_attendance_' . $safe_family . '_' . date('Y-m-d') . '.pdf', 'I');
    exit;
}


/**
 * Excel Export Handler for Servants — XLSX (Office Open XML)
 */
if (isset($_GET['export_servants_excel']) && $is_admin) {
    require_once __DIR__ . '/vendor/xlsxwriter/xlsxwriter.class.php';

    $exp_family = $current_user_family;
    $exp_conn   = get_db();

    $exp_sql  = "SELECT u.full_name, u.family_name, u.class_name, u.phone,
                        COUNT(a.id) AS attended_days
                 FROM users u
                 LEFT JOIN attendance a ON u.id = a.user_id
                 WHERE u.family_name = ? AND u.role = 'خادم'
                 GROUP BY u.id, u.full_name, u.family_name, u.class_name, u.phone
                 ORDER BY u.class_name ASC, u.full_name ASC";
    $exp_stmt = $exp_conn->prepare($exp_sql);

    if ($exp_stmt) {
        $exp_stmt->bind_param("s", $exp_family);
        $exp_stmt->execute();
        $exp_result = $exp_stmt->get_result();

        $writer = new XLSXWriter();
        $sheet  = 'الخدام';

        $title_style  = ['font-style' => 'bold', 'font-size' => 14, 'fill' => '2C3E50', 'font-color' => 'FFFFFF', 'halign' => 'center', 'border' => 'thin'];
        $header_style = ['font-style' => 'bold', 'font-size' => 11, 'fill' => 'D9EAD3', 'font-color' => '1E5C1E',  'border' => 'thin', 'halign' => 'center'];
        $even_style   = ['border' => 'thin', 'fill' => 'FFFFFF'];
        $odd_style    = ['border' => 'thin', 'fill' => 'F9FBF9'];

        $writer->writeSheetRow($sheet,
            ['تقرير الخدام — أسرة: ' . $exp_family . '  ' . date('Y-m-d'), '','','',''],
            $title_style
        );
        $writer->writeSheetRow($sheet, [
            $t['col_servant_name'], $t['family_label'], $t['col_student_class'],
            $t['attendance_status'], $t['col_phone'],
        ], $header_style);

        $row_num = 0;
        while ($exp_row = $exp_result->fetch_assoc()) {
            $style = ($row_num % 2 === 0) ? $even_style : $odd_style;
            $writer->writeSheetRow($sheet, [
                $exp_row['full_name']   ?? '',
                $exp_row['family_name'] ?? '',
                $exp_row['class_name']  ?? '',
                (int)($exp_row['attended_days'] ?? 0),
                $exp_row['phone']       ?? '',
            ], $style);
            $row_num++;
        }

        $writer->writeSheetHeader($sheet,
            array_combine(['a','b','c','d','e'],
                array_map(fn($x) => ['type' => 'string'], range(1,5))),
            ['widths' => [28, 26, 26, 14, 18], 'suppress_row' => true]
        );

        $safe_family  = preg_replace('/[^A-Za-z0-9_]/', '_', $exp_family);
        $exp_filename = 'servants_' . ($safe_family ?: 'family') . '_' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $exp_filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $writer->writeToStdOut();
        $exp_stmt->close();
    }

    $exp_conn->close();
    exit;
}

if (isset($_GET['export_members_pdf']) && $is_admin) {
    $tcpdf_dir = require_tcpdf();

    $exp_family = $current_user_family;
    $exp_conn   = get_db();

    $exp_stmt = $exp_conn->prepare(
        "SELECT u.id, u.full_name, u.phone, u.email, u.class_name
         FROM users u
         WHERE u.family_name = ? AND u.role = 'خادم'
         ORDER BY u.class_name ASC, u.full_name ASC"
    );
    $exp_stmt->bind_param("s", $exp_family);
    $exp_stmt->execute();
    $rows = $exp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $exp_stmt->close();
    $exp_conn->close();

    $safe_family = preg_replace('/[^A-Za-z0-9_]/', '_', $exp_family);
    if ($tcpdf_dir === null || !class_exists('TCPDF')) {
        $print_rows = [];
        $index = 1;
        foreach ($rows as $row) {
            $print_rows[] = [
                $index,
                $row['full_name'] ?? '',
                $row['class_name'] ?? '',
                $row['phone'] ?? '',
                $row['email'] ?? '',
            ];
            $index++;
        }
        render_printable_pdf_page(
            'كنيسة العذراء والملاك ميخائيل',
            'تقرير خدام الأسرة | ' . $exp_family . ' | ' . date('Y-m-d'),
            ['#', 'اسم الخادم', 'الفصل (القديس)', 'الهاتف', 'البريد الإلكتروني'],
            $print_rows,
            'servants_' . $safe_family . '_' . date('Y-m-d')
        );
    }

    // ── Build PDF using TCPDF ──────────────────────────────────────────

    $pdf->SetCreator('كنيسة العذراء والملاك ميخائيل');
    $pdf->SetAuthor('كنيسة العذراء والملاك ميخائيل');
    $pdf->SetTitle('تقرير خدام الأسرة — ' . $exp_family);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();

    // RTL direction
    $pdf->setRTL(true);

    // Title
    $pdf->SetFont($pdf_font, 'B', 16);
    $pdf->SetTextColor(44, 36, 22);
    $pdf->Cell(0, 10, 'كنيسة العذراء والملاك ميخائيل', 0, 1, 'C');

    // Subtitle
    $pdf->SetFont($pdf_font, '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6,
        'تقرير خدام الأسرة  |  ' . $exp_family . '  |  ' . date('Y-m-d'),
        0, 1, 'C');
    $pdf->Ln(2);

    // Badge: total count
    $total = count($rows);
    $pdf->SetFillColor(44, 62, 80);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf_font, 'B', 10);
    $pdf->Cell(50, 7, 'إجمالي الخدام: ' . $total, 0, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(10);

    // Table header
    $pdf->SetFillColor(44, 62, 80);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf_font, 'B', 9);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.3);

    $col_w = [10, 55, 45, 35, 41]; // #, name, class, phone, email
    $headers = ['#', 'اسم الخادم', 'الفصل (القديس)', 'الهاتف', 'البريد الإلكتروني'];

    foreach ($headers as $k => $h) {
        $pdf->Cell($col_w[$k], 8, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Table rows
    $pdf->SetFont($pdf_font, '', 9);
    $i = 1;
    foreach ($rows as $row) {
        $fill = ($i % 2 === 0);
        $pdf->SetFillColor(242, 242, 242);
        $pdf->SetTextColor(34, 34, 34);

        $pdf->Cell($col_w[0], 7, $i,                                    1, 0, 'C', $fill);
        $pdf->Cell($col_w[1], 7, $row['full_name']  ?? '',              1, 0, 'R', $fill);
        $pdf->Cell($col_w[2], 7, $row['class_name'] ?? '',              1, 0, 'R', $fill);
        $pdf->Cell($col_w[3], 7, $row['phone']      ?? '',              1, 0, 'C', $fill);
        $pdf->Cell($col_w[4], 7, $row['email']      ?? '',              1, 0, 'L', $fill);
        $pdf->Ln();
        $i++;
    }

    // Footer line
    $pdf->Ln(4);
    $pdf->SetFont($pdf_font, '', 8);
    $pdf->SetTextColor(170, 170, 170);
    $pdf->Cell(0, 5,
        'تم إنشاء هذا التقرير تلقائياً من نظام إدارة الكنيسة — ' . date('Y-m-d H:i'),
        0, 1, 'C');

    $pdf->Output('servants_' . $safe_family . '_' . date('Y-m-d') . '.pdf', 'I');
    exit;
}

/**
 * Excel Export Handler for Service Reports (Average Periodic Attendance)
 */
if (isset($_GET['export_service_reports_excel']) && $is_admin) {
    require_once __DIR__ . '/vendor/xlsxwriter/xlsxwriter.class.php';

    $exp_family = $current_user_family;
    $exp_conn   = get_db();

    $sql_reports = "SELECT 
                        u.full_name,
                        COUNT(a.id) as attended_days,
                        (SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE user_id IN (SELECT id FROM users WHERE family_name = ?)) as total_meeting_days
                    FROM users u
                    LEFT JOIN attendance a ON u.id = a.user_id
                    WHERE u.family_name = ? AND u.role = 'خادم'
                    GROUP BY u.id
                    ORDER BY u.full_name ASC";
    $exp_stmt = $exp_conn->prepare($sql_reports);

    if ($exp_stmt) {
        $exp_stmt->bind_param("ss", $exp_family, $exp_family);
        $exp_stmt->execute();
        $exp_result = $exp_stmt->get_result();

        $writer = new XLSXWriter();
        $sheet  = 'تقارير الخدمة';

        $title_style  = ['font-style' => 'bold', 'font-size' => 14, 'fill' => '2C3E50', 'font-color' => 'FFFFFF', 'halign' => 'center', 'border' => 'thin'];
        $header_style = ['font-style' => 'bold', 'font-size' => 11, 'fill' => 'D9EAD3', 'font-color' => '1E5C1E',  'border' => 'thin', 'halign' => 'center'];
        $even_style   = ['border' => 'thin', 'fill' => 'FFFFFF', 'halign' => 'center'];
        $odd_style    = ['border' => 'thin', 'fill' => 'F9FBF9', 'halign' => 'center'];

        $writer->writeSheetRow($sheet,
            ['تقارير الخدمة: متوسط الحضور الدوري — أسرة: ' . $exp_family . '  ' . date('Y-m-d'), '','',''],
            $title_style
        );
        $writer->writeSheetRow($sheet, [
            $t['col_servant_name'],
            $t['col_attended_days'],
            $t['col_total_meetings'],
            $t['col_avg_attendance']
        ], $header_style);

        $row_num = 0;
        while ($exp_row = $exp_result->fetch_assoc()) {
            $total_days = $exp_row['total_meeting_days'] > 0 ? (int)$exp_row['total_meeting_days'] : 1;
            $attended = (int)$exp_row['attended_days'];
            $average = round(($attended / $total_days) * 100) . '%';
            $style = ($row_num % 2 === 0) ? $even_style : $odd_style;
            
            $writer->writeSheetRow($sheet, [
                $exp_row['full_name'] ?? '',
                $attended . ' ' . $t['unit_day'],
                $total_days . ' ' . $t['unit_meeting'],
                $average
            ], $style);
            $row_num++;
        }

        $writer->writeSheetHeader($sheet,
            array_combine(['a','b','c','d'],
                array_map(fn($x) => ['type' => 'string'], range(1,4))),
            ['widths' => [32, 22, 22, 24], 'suppress_row' => true]
        );

        $safe_family  = preg_replace('/[^A-Za-z0-9_]/', '_', $exp_family);
        $exp_filename = 'service_reports_' . ($safe_family ?: 'family') . '_' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $exp_filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $writer->writeToStdOut();
        $exp_stmt->close();
    }

    $exp_conn->close();
    exit;
}

/**
 * PDF Export Handler for Service Reports (Average Periodic Attendance)
 */
if (isset($_GET['export_service_reports_pdf']) && $is_admin) {
    $tcpdf_dir = require_tcpdf();

    $exp_family = $current_user_family;
    $exp_conn   = get_db();

    $sql_reports = "SELECT 
                        u.full_name,
                        COUNT(a.id) as attended_days,
                        (SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE user_id IN (SELECT id FROM users WHERE family_name = ?)) as total_meeting_days
                    FROM users u
                    LEFT JOIN attendance a ON u.id = a.user_id
                    WHERE u.family_name = ? AND u.role = 'خادم'
                    GROUP BY u.id
                    ORDER BY u.full_name ASC";
    $exp_stmt = $exp_conn->prepare($sql_reports);
    $exp_stmt->bind_param("ss", $exp_family, $exp_family);
    $exp_stmt->execute();
    $rows = $exp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $exp_stmt->close();
    $exp_conn->close();

    $safe_family = preg_replace('/[^A-Za-z0-9_]/', '_', $exp_family);
    if ($tcpdf_dir === null || !class_exists('TCPDF')) {
        $print_rows = [];
        $index = 1;
        foreach ($rows as $row) {
            $total_days = $row['total_meeting_days'] > 0 ? (int)$row['total_meeting_days'] : 1;
            $attended = (int)$row['attended_days'];
            $average = round(($attended / $total_days) * 100) . '%';
            $print_rows[] = [
                $index,
                $row['full_name'] ?? '',
                $attended . ' ' . $t['unit_day'],
                $total_days . ' ' . $t['unit_meeting'],
                $average
            ];
            $index++;
        }
        render_printable_pdf_page(
            'كنيسة العذراء والملاك ميخائيل',
            'تقارير الخدمة: متوسط الحضور الدوري | ' . $exp_family . ' | ' . date('Y-m-d'),
            ['#', 'اسم الخادم', 'أيام الحضور الفعلي', 'إجمالي الاجتماعات', 'نسبة متوسط الحضور'],
            $print_rows,
            'service_reports_' . $safe_family . '_' . date('Y-m-d')
        );
    }

    $pdf->SetCreator('كنيسة العذراء والملاك ميخائيل');
    $pdf->SetAuthor('كنيسة العذراء والملاك ميخائيل');
    $pdf->SetTitle('تقارير الخدمة - متوسط الحضور الدوري - ' . $exp_family);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(14, 14, 14);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();
    $pdf->setRTL(true);

    // Header
    $pdf->SetFont($pdf_font, 'B', 16);
    $pdf->SetTextColor(44, 36, 22);
    $pdf->Cell(0, 10, 'كنيسة العذراء والملاك ميخائيل', 0, 1, 'C');

    $pdf->SetFont($pdf_font, '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'تقارير الخدمة: متوسط الحضور الدوري  |  ' . $exp_family . '  |  ' . date('Y-m-d'), 0, 1, 'C');
    $pdf->Ln(4);

    // Table Header
    $pdf->SetFillColor(44, 62, 80);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf_font, 'B', 9);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.3);

    $col_w = [12, 70, 35, 35, 30]; // #, Name, Attended Days, Total Meetings, Avg Attendance
    $headers = ['#', 'اسم الخادم', 'أيام الحضور الفعلي', 'إجمالي الاجتماعات', 'متوسط الحضور'];

    foreach ($headers as $k => $h) {
        $pdf->Cell($col_w[$k], 8, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Table Rows
    $pdf->SetFont($pdf_font, '', 9);
    $i = 1;
    foreach ($rows as $row) {
        $total_days = $row['total_meeting_days'] > 0 ? (int)$row['total_meeting_days'] : 1;
        $attended = (int)$row['attended_days'];
        $average = round(($attended / $total_days) * 100) . '%';
        $fill = ($i % 2 === 0);
        $pdf->SetFillColor(242, 242, 242);
        $pdf->SetTextColor(34, 34, 34);

        $pdf->Cell($col_w[0], 7, $i, 1, 0, 'C', $fill);
        $pdf->Cell($col_w[1], 7, $row['full_name'] ?? '', 1, 0, 'R', $fill);
        $pdf->Cell($col_w[2], 7, $attended . ' ' . $t['unit_day'], 1, 0, 'C', $fill);
        $pdf->Cell($col_w[3], 7, $total_days . ' ' . $t['unit_meeting'], 1, 0, 'C', $fill);
        $pdf->Cell($col_w[4], 7, $average, 1, 0, 'C', $fill);
        $pdf->Ln();
        $i++;
    }

    $pdf->Ln(4);
    $pdf->SetFont($pdf_font, '', 8);
    $pdf->SetTextColor(170, 170, 170);
    $pdf->Cell(0, 5, 'تم إنشاء هذا التقرير تلقائياً من نظام إدارة الكنيسة — ' . date('Y-m-d H:i'), 0, 1, 'C');

    $pdf->Output('service_reports_' . $safe_family . '_' . date('Y-m-d') . '.pdf', 'I');
    exit;
}

?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>" dir="<?= $htmlDir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.2">
    <title><?= $t['page_title'] ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@700&family=Cairo:wght@400;600;700&display=swap">
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime('dashboard.css') ?>">
</head>
<body class="<?= $bodyClass ?>" style="--mine-align: <?= $isRtl ? 'flex-start' : 'flex-end' ?>; --theirs-align: <?= $isRtl ? 'flex-end' : 'flex-start' ?>;">

<div class="dashboard-container">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <svg width="36" height="36" viewBox="0 0 80 80" fill="none">
                <circle cx="40" cy="40" r="35" stroke="#C8973A" stroke-width="4" fill="none"/>
                <rect x="36" y="12" width="8" height="56" rx="3" fill="#C8973A"/>
                <rect x="16" y="34" width="48" height="8" rx="3" fill="#C8973A"/>
            </svg>
            <h2 class="brand-title"><?= $t['church_name'] ?></h2>
            <form method="POST" class="brand-lang-form" aria-label="<?= htmlspecialchars($t['language_title']) ?>">
                <input type="hidden" name="update_language" value="1">
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars(ltrim($_SERVER['REQUEST_URI'] ?? 'dashboard.php', '/')) ?>">
                <button type="submit" name="language" value="ar" class="<?= ($profile['language'] ?? 'ar') === 'ar' ? 'active' : '' ?>">AR</button>
                <button type="submit" name="language" value="en" class="<?= ($profile['language'] ?? 'ar') === 'en' ? 'active' : '' ?>">EN</button>
            </form>
        </div>

        <nav class="sidebar-menu">
            <a onclick="showSection('home', this)" id="home-btn" class="menu-item active"><?= $t['menu_home'] ?></a>
            <a onclick="showSection('members', this)" id="members-btn" class="menu-item"><?= $t['menu_members'] ?></a>

            <?php if ($user['role'] === 'خادم'): ?>
                <a onclick="showSection('prepare', this)" id="prepare-btn" class="menu-item"><?= $t['menu_prepare'] ?></a>
                <a onclick="showSection('lord-brothers', this)" id="lord-brothers-btn" class="menu-item"><?= $t['menu_lord_brothers'] ?></a>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'خادم'): ?>
                <a onclick="showQRCode(<?= $user['id'] ?>)" class="menu-item"><?= $t['menu_my_qr'] ?></a>
            <?php endif; ?>

            <?php if ($is_admin): ?>
                <a onclick="showSection('servants', this)" id="servants-btn" class="menu-item"><?= $t['menu_servants'] ?></a>
                <a onclick="showSection('lord-brothers-admin', this)" id="lord-brothers-admin-btn" class="menu-item"><?= $t['menu_lord_brothers'] ?></a>
                <a onclick="showSection('preparations', this)" id="preparations-btn" class="menu-item"><?= $t['menu_preparations'] ?></a>
                <a onclick="showSection('reports', this)" id="reports-btn" class="menu-item"><?= $t['menu_reports'] ?></a>
                <a onclick="showSection('member-attendance', this)" id="member-attendance-btn" class="menu-item"><?= $t['member_attendance_title'] ?></a>
            <?php endif; ?>

            <a onclick="showSection('chat', this)" id="chat-btn" class="menu-item"><?= $t['menu_chat'] ?></a>
            <a href="contact.php" class="menu-item"><?= $t['menu_contact'] ?></a>
            <a onclick="showSection('settings', this)" id="settings-btn" class="menu-item"><?= $t['menu_settings'] ?></a>
            <a href="logout.php" class="menu-item logout"><?= $t['menu_logout'] ?></a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="dash-header">
            <div style="font-weight: 600; color: var(--text-mid);"><?= htmlspecialchars($today) ?></div>
            <div class="user-profile-badge">
                <div class="avatar">
                    <?php if (!empty($profile['profile_picture']) && file_exists($profile['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($profile['profile_picture']) ?>?v=<?= time() ?>" alt="avatar" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                    <?php else: ?>
                        🙏
                    <?php endif; ?>
                </div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                    <div style="font-size:0.85rem; color:var(--gold); font-weight: bold;">
                        <?= $is_admin ? $t['role_admin'] : $t['role_servant'] ?>
                    </div>
                </div>
            </div>
        </header>

        <section class="dash-body">

            <!-- HOME -->
            <div id="home-section">
                <div class="welcome-banner" style="background: var(--white); padding: 30px; border-radius: 12px; <?= $welcomeBorderSide ?>: 5px solid var(--gold); box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
                    <h1 style="margin: 0 0 10px 0;"><?= $t['welcome_prefix'] ?> <?= htmlspecialchars($user['full_name']) ?> 👋</h1>
                    <p style="margin: 0; color: var(--text-soft); font-size: 1rem;"><?= $is_admin ? $t['welcome_admin_sub'] : $t['welcome_servant_sub'] ?></p>
                </div>

                <div class="metrics-grid" style="margin-top: 35px;">
                    <div class="card metric-card">
                        <div>
                            <h3 style="font-size:0.9rem; color:var(--text-soft); margin:0;"><?= $t['family_name_label'] ?></h3>
                            <div class="metric-value" style="color:var(--gold-dark);"><?= htmlspecialchars($current_user_family) ?></div>
                        </div>
                    </div>
                    <div class="card metric-card">
                        <div class="metric-icon">📅</div>
                        <div>
                            <h3 style="font-size:0.9rem; color:var(--text-soft); margin:0;"><?= $t['meeting_day'] ?></h3>
                            <div class="metric-value"><?= htmlspecialchars($family_info['meeting_day']) ?></div>
                        </div>
                    </div>
                    <div class="card metric-card">
                        <div class="metric-icon">⏰</div>
                        <div>
                            <h3 style="font-size:0.9rem; color:var(--text-soft); margin:0;"><?= $t['meeting_time'] ?></h3>
                            <div class="metric-value" dir="ltr"><?= htmlspecialchars($family_info['meeting_time']) ?></div>
                        </div>
                    </div>
                    <div class="card metric-card">
                        <div class="metric-icon">📍</div>
                        <div>
                            <h3 style="font-size:0.9rem; color:var(--text-soft); margin:0;"><?= $t['meeting_location'] ?></h3>
                            <div class="metric-value" style="font-size:0.95rem; font-weight:600;"><?= htmlspecialchars($family_info['meeting_location']) ?></div>
                        </div>
                    </div>
                    <div class="card metric-card">
                        <div class="metric-icon">🕊️</div>
                        <div>
                            <h3 style="font-size:0.9rem; color:var(--text-soft); margin:0;"><?= $t['next_spiritual_day'] ?></h3>
                            <?php if ($next_spiritual_day['event_date']): ?>
                                <div class="metric-value"><?= htmlspecialchars($next_spiritual_day['event_date']) ?></div>
                                <div style="color: var(--text-soft); font-weight: 700;" dir="ltr"><?= htmlspecialchars($next_spiritual_day['event_time']) ?></div>
                            <?php else: ?>
                                <div class="metric-value"><?= htmlspecialchars($t['not_set']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            if (!isset($conn) || $conn === null || !$conn instanceof mysqli) {
                $conn = get_db();
                $conn->set_charset("utf8mb4");
            }
            // Admin sees ALL students in the family.
            // Servants see ONLY students of their assigned class.
            $today_date = date('Y-m-d');
            if ($is_admin) {
                $sql_members = "SELECT s.id, s.student_name, s.phone, s.address, s.birth_date, s.class_name, s.father_job, s.father_phone, s.father_age, s.mother_job, s.mother_phone, s.mother_age, s.notes, s.profile_picture, sa.status as today_attendance FROM students s LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.attendance_date = ? WHERE s.family_name = ? ORDER BY s.class_name ASC, s.student_name ASC";
                $stmt_m = $conn->prepare($sql_members);
                $stmt_m->bind_param("ss", $today_date, $current_user_family);

                $stmt_count = $conn->prepare("SELECT COUNT(*) AS total_students, SUM(CASE WHEN sa.status = 1 THEN 1 ELSE 0 END) AS present_today FROM students s LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.attendance_date = ? WHERE s.family_name = ?");
                $stmt_count->bind_param("ss", $today_date, $current_user_family);
            } else {
                if ($current_user_class !== '') {
                    $sql_members = "SELECT s.id, s.student_name, s.phone, s.address, s.birth_date, s.class_name, s.father_job, s.father_phone, s.father_age, s.mother_job, s.mother_phone, s.mother_age, s.notes,s.profile_picture, sa.status as today_attendance FROM students s LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.attendance_date = ? WHERE s.family_name = ? AND s.class_name = ? ORDER BY s.student_name ASC";
                    $stmt_m = $conn->prepare($sql_members);
                    $stmt_m->bind_param("sss", $today_date, $current_user_family, $current_user_class);

                    $stmt_count = $conn->prepare("SELECT COUNT(*) AS total_students, SUM(CASE WHEN sa.status = 1 THEN 1 ELSE 0 END) AS present_today FROM students s LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.attendance_date = ? WHERE s.family_name = ? AND s.class_name = ?");
                    $stmt_count->bind_param("sss", $today_date, $current_user_family, $current_user_class);
                } else {
                    // Servant has no class assigned — return empty
                    $stmt_m = $conn->prepare("SELECT s.id, s.student_name, s.phone, s.address, s.birth_date, s.class_name, s.father_job, s.father_phone, s.father_age, s.mother_job, s.mother_phone, s.mother_age, s.notes, s.profile_picture, NULL as today_attendance FROM students s WHERE 1=0");
                    $stmt_count = null;
                }
            }
            $stmt_m->execute();
            $result_members = $stmt_m->get_result();

            $total_students = 0;
            $present_today = 0;
            if ($stmt_count) {
                $stmt_count->execute();
                $count_result = $stmt_count->get_result();
                if ($count_row = $count_result->fetch_assoc()) {
                    $total_students = (int) $count_row['total_students'];
                    $present_today = (int) $count_row['present_today'];
                }
                $stmt_count->close();
            }
            ?>

            <!-- MEMBERS -->
            <div id="members-section" class="card admin-section" style="padding: 30px; border-top: 4px solid var(--gold);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h3 style="color: var(--text-dark); margin: 0 0 4px 0;"><?= $t['members_title'] ?> (<?= htmlspecialchars($current_user_family) ?>)</h3>
                        <?php if ($is_admin): ?>
                            <span style="font-size:0.82rem; background:var(--gold); color:#fff; padding:2px 10px; border-radius:20px; font-weight:600;">👑 عرض الكل — جميع الفصول</span>
                        <?php elseif ($current_user_class !== ''): ?>
                            <span style="font-size:0.82rem; background:#2E7D32; color:#fff; padding:2px 10px; border-radius:20px; font-weight:600;">📚 فصلك: <?= htmlspecialchars($current_user_class) ?></span>
                        <?php else: ?>
                            <span style="font-size:0.82rem; background:#C62828; color:#fff; padding:2px 10px; border-radius:20px; font-weight:600;">⚠️ لم يتم تعيين فصل لك — اذهب للإعدادات</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <?php if ($is_admin || $current_user_class !== ''): ?>
                        <button class="btn-submit" onclick="openStudentModal()" style="margin: 0; padding: 8px 16px; font-size: 0.9rem;">➕ إضافة مخدوم جديد</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($student_success): ?>
                    <div class="alert alert-success"><?= $student_success ?></div>
                <?php endif; ?>
                <?php if ($student_error): ?>
                    <div class="alert alert-error"><?= $student_error ?></div>
                <?php endif; ?>
                <?php if (!$is_admin && $current_user_class === ''): ?>
                    <div style="background:#FFF3E0; border:1px solid #FF8F00; border-radius:8px; padding:16px 20px; color:#E65100; font-size:0.95rem; margin-bottom:16px;">
                        ⚠️ <strong>لم يتم تعيين فصل لحسابك بعد.</strong><br>
                        يرجى الذهاب إلى <strong>إعدادات الحساب</strong> واختيار فصلك حتى تتمكن من رؤية وإدارة مخدوميك.
                    </div>
                <?php endif; ?>

                <?php if ($attendance_success): ?>
                    <div class="alert alert-success"><?= $attendance_success ?></div>
                <?php endif; ?>
                <?php if ($attendance_error): ?>
                    <div class="alert alert-error"><?= $attendance_error ?></div>
                <?php endif; ?>
                <?php if ($total_students > 0): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                        <div class="card metric-card" style="flex:1; min-width:180px;">
                            <div>
                                <h3 style="font-size:0.85rem; color:var(--text-soft); margin:0;"><?= $lang === 'ar' ? 'إجمالي المخدومين' : 'Total Members' ?></h3>
                                <div class="metric-value" style="color:var(--gold-dark);"><?= $total_students ?></div>
                            </div>
                        </div>
                        <div class="card metric-card" style="flex:1; min-width:180px;">
                            <div>
                                <h3 style="font-size:0.85rem; color:var(--text-soft); margin:0;"><?= $lang === 'ar' ? 'حضر اليوم' : 'Present Today' ?></h3>
                                <div class="metric-value" style="color:#2E7D32;"><?= $present_today ?></div>
                            </div>
                        </div>
                        <div class="card metric-card" style="flex:1; min-width:180px;">
                            <div>
                                <h3 style="font-size:0.85rem; color:var(--text-soft); margin:0;"><?= $lang === 'ar' ? 'لم يحضر بعد' : 'Not Present Yet' ?></h3>
                                <div class="metric-value" style="color:#C62828;"><?= max(0, $total_students - $present_today) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <p style="color: var(--text-soft); font-size: 0.9rem; margin-bottom: 20px;"><?= $t['members_desc'] ?></p>

                <?php if ($is_admin || $current_user_class !== ''): ?>
                    <div style="margin-bottom: 16px;">
                        <button class="btn-submit" onclick="openAttendanceModal()" style="padding: 8px 16px; font-size: 0.9rem;"><?= $t['btn_take_attendance'] ?></button>
                    </div>
                <?php endif; ?>

                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>اسم المخدوم</th>
                            <th>شفيع الفصل (القديس)</th>
                            <th>هاتف المخدوم</th>
                            <th>بيانات الأب</th>
                            <th>بيانات الأم</th>
                            <th>العنوان والميلاد</th>
                            <th>ملاحظات</th>
                            <th><?= $t['attendance_status'] ?></th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_members && $result_members->num_rows > 0): ?>
                            <?php while($row = $result_members->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($row['student_name']) ?></td>
                                    <td style="font-weight: 600; color: var(--gold-dark);"><?= htmlspecialchars($row['class_name'] ?? 'غير محدد') ?></td>
                                    <td>
                                        <?= htmlspecialchars($row['phone'] ?? '') ?>
                                        <?php if(!empty($row['phone'])): ?>
                                            <a href="tel:<?= $row['phone'] ?>" class="btn-call"><?= $t['btn_call'] ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.88rem; line-height: 1.4;">
                                            <strong>الوظيفة:</strong> <?= htmlspecialchars($row['father_job'] ?? 'غير محدد') ?><br>
                                            <strong>الهاتف:</strong> <?= htmlspecialchars($row['father_phone'] ?? 'غير محدد') ?> 
                                            <?php if(!empty($row['father_phone'])): ?><a href="tel:<?= $row['father_phone'] ?>" class="btn-call" style="padding: 2px 5px; font-size:0.75rem;">📞</a><?php endif; ?><br>
                                            <strong>السن:</strong> <?= htmlspecialchars($row['father_age'] ?? 'غير محدد') ?> سنة
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.88rem; line-height: 1.4;">
                                            <strong>الوظيفة:</strong> <?= htmlspecialchars($row['mother_job'] ?? 'غير محدد') ?><br>
                                            <strong>الهاتف:</strong> <?= htmlspecialchars($row['mother_phone'] ?? 'غير محدد') ?>
                                            <?php if(!empty($row['mother_phone'])): ?><a href="tel:<?= $row['mother_phone'] ?>" class="btn-call" style="padding: 2px 5px; font-size:0.75rem;">📞</a><?php endif; ?><br>
                                            <strong>السن:</strong> <?= htmlspecialchars($row['mother_age'] ?? 'غير محدد') ?> سنة
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.88rem;">
                                            <strong>العنوان:</strong> <?= htmlspecialchars($row['address'] ?? 'غير مسجل') ?><br>
                                            <strong>الميلاد:</strong> <?= htmlspecialchars($row['birth_date'] ?? 'غير مسجل') ?>
                                        </div>
                                    </td>
                                    <td style="color: var(--text-soft); font-style: italic; font-size:0.88rem;"><?= htmlspecialchars($row['notes'] ?? $t['no_notes']) ?></td>
                                    <td style="text-align: center;">
                                        <?php if (isset($row['today_attendance']) && $row['today_attendance'] !== null): ?>
                                            <?php if ((int)$row['today_attendance'] === 1): ?>
                                                <span style="color: #2E7D32; font-weight: 700; font-size: 0.85rem;"><?= $t['present'] ?></span>
                                            <?php else: ?>
                                                <span style="color: #C62828; font-weight: 700; font-size: 0.85rem;"><?= $t['absent'] ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-soft); font-size: 0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-action-group">
                                            <button class="btn-edit" onclick='editStudent(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✍️ تعديل</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من رغبتك في حذف هذا المخدوم؟');">
                                                <input type="hidden" name="delete_student" value="1">
                                                <input type="hidden" name="student_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn-delete">🗑️ حذف</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align: center; color: var(--text-soft); padding: 25px;"><?= $t['no_members'] ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php $stmt_m->close(); ?>

            <!-- LORD BROTHERS -->
            <?php if ($user['role'] === 'خادم'): ?>
            <div id="lord-brothers-section" class="card admin-section prep-panel">
                <h3><?= $t['lord_brothers_title'] ?></h3>
                <?php if ($lord_success): ?><div class="alert alert-success"><?= htmlspecialchars($lord_success) ?></div><?php endif; ?>
                <?php if ($lord_error): ?><div class="alert alert-error"><?= htmlspecialchars($lord_error) ?></div><?php endif; ?>
                <p style="color: var(--text-soft); font-size: 0.9rem; margin-bottom: 20px;"><?= $t['lord_brothers_desc'] ?></p>

                <?php
                $conn_lord = get_db();
                ensure_lord_brothers_table($conn_lord);

                $selected_lord_ids = [];
                $stmt_selected_lord = $conn_lord->prepare("SELECT student_id FROM lord_brothers WHERE selected_by = ? AND family_name = ?");
                $stmt_selected_lord->bind_param("is", $user['id'], $current_user_family);
                $stmt_selected_lord->execute();
                $result_selected_lord = $stmt_selected_lord->get_result();
                while ($selected_row = $result_selected_lord->fetch_assoc()) {
                    $selected_lord_ids[] = (int)$selected_row['student_id'];
                }
                $stmt_selected_lord->close();

                if ($current_user_class !== '') {
                    $stmt_lord_students = $conn_lord->prepare("SELECT id, student_name, phone, address FROM students WHERE family_name = ? AND class_name = ? ORDER BY student_name ASC");
                    $stmt_lord_students->bind_param("ss", $current_user_family, $current_user_class);
                } else {
                    $stmt_lord_students = $conn_lord->prepare("SELECT id, student_name, phone, address FROM students WHERE 1=0");
                }
                $stmt_lord_students->execute();
                $result_lord_students = $stmt_lord_students->get_result();
                ?>

                <form method="POST" class="prep-form">
                    <input type="hidden" name="save_lord_brothers" value="1">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th><?= $t['lord_brothers_title'] ?></th>
                                <th><?= $t['col_student_name'] ?></th>
                                <th><?= $t['col_phone'] ?></th>
                                <th><?= $t['col_address'] ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result_lord_students && $result_lord_students->num_rows > 0): ?>
                                <?php while($student = $result_lord_students->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="lord_brother_students[]" value="<?= (int)$student['id'] ?>" <?= in_array((int)$student['id'], $selected_lord_ids, true) ? 'checked' : '' ?>>
                                        </td>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($student['student_name']) ?></td>
                                        <td><?= htmlspecialchars($student['phone'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($student['address'] ?? $t['not_registered']) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-soft); padding: 25px;"><?= $t['no_members'] ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <button type="submit" class="btn-submit"><?= $t['lord_brothers_save'] ?></button>
                </form>
                <?php $stmt_lord_students->close(); ?>
            </div>
            <?php endif; ?>

            <!-- PREPARE TODAY (servant only) -->
            <?php if ($user['role'] === 'خادم'): ?>
            <div id="prepare-section" class="card admin-section prep-panel">
                <h3><?= $t['prepare_title'] ?></h3>
                <?php if ($prepare_success): ?><div class="alert alert-success"><?= htmlspecialchars($prepare_success) ?></div><?php endif; ?>
                <?php if ($prepare_error): ?><div class="alert alert-error"><?= htmlspecialchars($prepare_error) ?></div><?php endif; ?>
                <?php if ($prep_edit_success): ?><div class="alert alert-success"><?= htmlspecialchars($prep_edit_success) ?></div><?php endif; ?>
                <?php if ($prep_edit_error): ?><div class="alert alert-error"><?= htmlspecialchars($prep_edit_error) ?></div><?php endif; ?>
                <?php
                    // Calculate nearest next or current Friday for default date
                    $default_prep_friday = (date('N') == 5) ? date('Y-m-d') : date('Y-m-d', strtotime('next friday'));
                ?>
                <form method="POST" enctype="multipart/form-data" class="prep-form" id="createPrepForm" onsubmit="return validateCreatePrepForm()">
                    <input type="hidden" name="prepare_lesson" value="1">
                    <div id="createPrepJsError" style="display:none;background:#FFEAEA;color:#A32A2A;border:1px solid #F0B8B8;border-radius:8px;padding:10px 14px;font-weight:700;font-size:0.92rem;"></div>
                    <div class="field"><label><?= $t['lesson_title_label'] ?></label><input type="text" name="lesson_title" id="createLessonTitle" required></div>
                    <div class="field">
                        <label><?= $t['prep_date_label'] ?> <small style="color:var(--gold-dark);font-weight:600;">(<?= $lang === 'ar' ? 'يوم الجمعة فقط' : 'Fridays only' ?>)</small></label>
                        <input type="date" name="preparation_date" id="createPrepDate" value="<?= $default_prep_friday ?>" required>
                    </div>
                    <div class="field"><label><?= $t['upload_image_label'] ?></label><input type="file" name="lesson_image" id="createLessonImage" accept="image/*"></div>
                    <div class="field"><label><?= $t['lesson_content_label'] ?></label><textarea name="content" id="createPrepContent" rows="10"></textarea></div>
                    <button type="submit" class="btn-submit"><?= $t['btn_save_prep'] ?></button>
                </form>

                <div class="prep-list" style="margin-top: 28px;">
                    <h3 style="margin: 0 0 4px; padding-top: 22px; border-top: 1px solid var(--border);"><?= $t['my_preparations_title'] ?></h3>
                    <?php
                    $conn_my_prep = get_db();
                    ensure_preparation_review_columns($conn_my_prep);
                    $sql_my_prep = "SELECT id, lesson_title, preparation_date, content, file_path, review_status, admin_review_text, admin_reviewed_at
                        FROM preparations
                        WHERE user_id = ?
                        ORDER BY created_at DESC";
                    $stmt_my_prep = $conn_my_prep->prepare($sql_my_prep);
                    $stmt_my_prep->bind_param("i", $user['id']);
                    $stmt_my_prep->execute();
                    $result_my_prep = $stmt_my_prep->get_result();
                    ?>
                    <?php if ($result_my_prep && $result_my_prep->num_rows > 0): ?>
                        <?php $prep_index = 0; ?>
                        <?php while($my_prep = $result_my_prep->fetch_assoc()): ?>
                            <?php
                                $my_status = $my_prep['review_status'] ?? 'pending';
                                $my_status_key = 'prep_status_' . $my_status;
                                $my_status_label = $t[$my_status_key] ?? $t['prep_status_pending'];
                            ?>
                            <details class="prep-item prep-collapsible">
                                <summary class="prep-summary">
                                    <span class="prep-arrow" aria-hidden="true">▾</span>
                                    <span class="prep-summary-main">
                                        <strong class="prep-title"><?= htmlspecialchars($my_prep['lesson_title']) ?></strong>
                                        <span class="prep-meta"><?= htmlspecialchars($my_prep['preparation_date']) ?></span>
                                    </span>
                                    <span class="prep-status <?= htmlspecialchars($my_status) ?>"><?= htmlspecialchars($my_status_label) ?></span>
                                </summary>
                                <div class="prep-details-body">
                                    <?php if (!empty($my_prep['content'])): ?>
                                        <div class="prep-content"><?= nl2br(htmlspecialchars($my_prep['content'])) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($my_prep['file_path'])): ?>
                                        <img src="<?= htmlspecialchars($my_prep['file_path']) ?>" class="prep-image">
                                    <?php endif; ?>
                                    <div class="prep-review-box">
                                        <h4><?= $t['admin_review_label'] ?></h4>
                                        <?php if (!empty($my_prep['admin_review_text'])): ?>
                                            <div class="prep-content" style="margin-top: 0;"><?= nl2br(htmlspecialchars($my_prep['admin_review_text'])) ?></div>
                                            <?php if (!empty($my_prep['admin_reviewed_at'])): ?>
                                                <small class="prep-meta"><?= htmlspecialchars($my_prep['admin_reviewed_at']) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="empty-state"><?= $t['no_review_yet'] ?></p>
                                        <?php endif; ?>
                                    <div class="prep-actions" style="display:flex;gap:10px;margin-top:12px;justify-content:flex-end;">
                                        <button type="button" class="btn-edit-prep"
                                            onclick="openEditPrepModal(<?= (int)$my_prep['id'] ?>, <?= htmlspecialchars(json_encode($my_prep['lesson_title']), ENT_QUOTES) ?>, '<?= htmlspecialchars($my_prep['preparation_date']) ?>', <?= htmlspecialchars(json_encode($my_prep['content'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($my_prep['file_path'] ?? ''), ENT_QUOTES) ?>)">
                                            <?= $t['btn_edit_prep'] ?>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t['confirm_delete_prep']), ENT_QUOTES) ?>);">
                                            <input type="hidden" name="delete_preparation" value="1">
                                            <input type="hidden" name="prep_id" value="<?= (int)$my_prep['id'] ?>">
                                            <button type="submit" class="btn-delete-prep"><?= $t['btn_delete_prep'] ?></button>
                                        </form>
                                    </div>
                                    </div>
                                </div>
                            </details>
                            <?php $prep_index++; ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="empty-state"><?= $t['no_preparations'] ?></p>
                    <?php endif; ?>
                    <?php $stmt_my_prep->close(); ?>
                </div>

            <!-- Edit Preparation Modal -->
            <div id="editPrepModal" style="display:none;position:fixed;inset:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99999;align-items:center;justify-content:center;padding:20px;">
                <div style="background:var(--white, #fff);border:1px solid var(--border);border-radius:16px;padding:30px;max-width:580px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 16px 48px rgba(0,0,0,0.3);position:relative;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:12px;">
                        <h3 style="margin:0;color:var(--gold-dark,#9E6F20);font-size:1.35rem;display:flex;align-items:center;gap:8px;">
                            <span>📝</span> <?= htmlspecialchars($t['edit_prep_title']) ?>
                        </h3>
                        <button type="button" onclick="closeEditPrepModal()" style="background:none;border:none;font-size:1.4rem;color:var(--text-soft);cursor:pointer;line-height:1;padding:4px 8px;border-radius:6px;">&times;</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data" id="editPrepForm" onsubmit="return validateEditPrepForm()" style="display:flex;flex-direction:column;gap:16px;">
                        <input type="hidden" name="edit_preparation" value="1">
                        <input type="hidden" name="prep_id" id="editPrepId">
                        <div id="editPrepJsError" style="display:none;background:#FFEAEA;color:#A32A2A;border:1px solid #F0B8B8;border-radius:8px;padding:10px 14px;font-weight:700;font-size:0.92rem;"></div>
                        <div class="field">
                            <label><?= htmlspecialchars($t['lesson_title_label']) ?></label>
                            <input type="text" name="lesson_title" id="editLessonTitle" required>
                        </div>
                        <div class="field">
                            <label><?= htmlspecialchars($t['prep_date_label']) ?> <small style="color:var(--gold-dark);font-weight:600;">(<?= $lang === 'ar' ? 'يوم الجمعة فقط' : 'Fridays only' ?>)</small></label>
                            <input type="date" name="preparation_date" id="editPrepDate" required>
                        </div>
                        <div class="field" id="editCurrentImageWrap" style="display:none;">
                            <label><?= htmlspecialchars($t['upload_image_label']) ?> (<?= $lang === 'ar' ? 'الصورة الحالية' : 'Current Image' ?>)</label>
                            <div style="background:var(--input-bg);border:1px dashed var(--border);border-radius:10px;padding:12px;display:flex;flex-direction:column;align-items:center;gap:10px;">
                                <img id="editCurrentImage" src="" style="max-width:100%;max-height:180px;border-radius:8px;object-fit:contain;border:1px solid var(--border);">
                                <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:700;color:var(--text-mid);font-size:0.9rem;margin:0;background:var(--white);padding:6px 12px;border-radius:6px;border:1px solid var(--border);">
                                    <input type="checkbox" name="remove_image" value="1" id="editRemoveImage" style="width:auto;margin:0;accent-color:#c0392b;" onchange="toggleNewImageField(this)">
                                    <span style="color:#c0392b;">🗑️ <?= htmlspecialchars($t['remove_image_label']) ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="field" id="editNewImageWrap">
                            <label id="editNewImageLabel"><?= htmlspecialchars($t['upload_image_label']) ?> (<?= $lang === 'ar' ? 'تغيير الصورة' : 'Change Image' ?>)</label>
                            <input type="file" name="lesson_image" accept="image/*">
                        </div>
                        <div class="field">
                            <label><?= htmlspecialchars($t['lesson_content_label']) ?></label>
                            <textarea name="content" id="editPrepContent" rows="8" placeholder="<?= $lang === 'ar' ? 'اكتب محتوى الدرس هنا...' : 'Write lesson content here...' ?>"></textarea>
                        </div>
                        <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:10px;padding-top:14px;border-top:1px solid var(--border);">
                            <button type="button" onclick="closeEditPrepModal()" style="padding:10px 20px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg);cursor:pointer;color:var(--text-mid);font-family:'Cairo',sans-serif;font-weight:700;">
                                <?= htmlspecialchars($t['btn_cancel']) ?>
                            </button>
                            <button type="submit" class="btn-submit" style="margin:0;padding:10px 24px;">
                                💾 <?= htmlspecialchars($t['btn_save_edit_prep']) ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            </div>
            <?php endif; ?>

            <!-- ADMIN SECTIONS -->
            <?php if ($is_admin): ?>

            <!-- LORD BROTHERS ADMIN VIEW -->
            <div id="lord-brothers-admin-section" class="card admin-section prep-panel">
                <h3><?= $t['lord_brothers_title'] ?></h3>
                <p style="color: var(--text-soft); font-size: 0.9rem; margin-bottom: 20px;"><?= $t['lord_brothers_admin_desc'] ?></p>
                <?php
                $conn_lord_admin = get_db();
                ensure_lord_brothers_table($conn_lord_admin);
                $sql_lord_admin = "SELECT s.student_name, s.phone, s.address, lb.created_at, u.full_name AS selected_by_name
                    FROM lord_brothers lb
                    JOIN students s ON lb.student_id = s.id
                    JOIN users u ON lb.selected_by = u.id
                    WHERE lb.family_name = ?
                    ORDER BY lb.created_at DESC, s.student_name ASC";
                $stmt_lord_admin = $conn_lord_admin->prepare($sql_lord_admin);
                $stmt_lord_admin->bind_param("s", $current_user_family);
                $stmt_lord_admin->execute();
                $result_lord_admin = $stmt_lord_admin->get_result();
                ?>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th><?= $t['col_student_name'] ?></th>
                            <th><?= $t['col_phone'] ?></th>
                            <th><?= $t['col_address'] ?></th>
                            <th><?= $t['selected_by_label'] ?></th>
                            <th><?= $t['selected_at_label'] ?></th>
                            <th><?= $t['col_profile_picture'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_lord_admin && $result_lord_admin->num_rows > 0): ?>
                            <?php while($lord_row = $result_lord_admin->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($lord_row['student_name']) ?></td>
                                    <td><?= htmlspecialchars($lord_row['phone'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($lord_row['address'] ?? $t['not_registered']) ?></td>
                                    <td><?= htmlspecialchars($lord_row['selected_by_name']) ?></td>
                                    <td><?= htmlspecialchars($lord_row['created_at']) ?></td>
                                    <td style="text-align: center;">
                                        <?php if (!empty($lord_row['profile_picture']) && file_exists($lord_row['profile_picture'])): ?>
                                            <img src="<?= htmlspecialchars($lord_row['profile_picture']) ?>?v=<?= time() ?>" alt="avatar" style="width:40px; height:40px; object-fit:cover; border-radius:50%;">
                                        <?php else: ?>
                                            🙏
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-soft); padding: 25px;"><?= $t['no_lord_brothers'] ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php $stmt_lord_admin->close(); ?>
            </div>

            <!-- PREPARATIONS -->
            <div id="preparations-section" class="card admin-section prep-panel">
                <h3><?= $t['preparations_title'] ?></h3>
                <?php
                $conn2 = get_db();
                ensure_preparation_review_columns($conn2);
                $sql = "SELECT p.*, u.full_name AS servant_name 
                    FROM preparations p 
                    JOIN users u ON p.user_id = u.id 
                    WHERE u.family_name = ?
                    ORDER BY p.created_at DESC";
                $stmt2 = $conn2->prepare($sql);
                $stmt2->bind_param("s", $current_user_family);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                ?>
                <div class="prep-list">
                <?php if ($result2->num_rows > 0): ?>
                    <?php while($row = $result2->fetch_assoc()): ?>
                    <?php
                        $status = $row['review_status'] ?? 'pending';
                        $status_key = 'prep_status_' . $status;
                        $status_label = $t[$status_key] ?? $t['prep_status_pending'];
                    ?>
                    <details class="prep-item prep-collapsible">
                        <summary class="prep-summary">
                            <span class="prep-arrow" aria-hidden="true">▾</span>
                            <span class="prep-summary-main">
                                <strong class="prep-title"><?= htmlspecialchars($row['lesson_title']) ?></strong>
                                <span class="prep-meta"><?= htmlspecialchars($row['preparation_date']) ?> — <?= htmlspecialchars($row['servant_name']) ?></span>
                            </span>
                            <span class="prep-status <?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status_label) ?></span>
                        </summary>
                        <div class="prep-details-body">
                            <?php if (!empty($row['content'])): ?>
                                <div class="prep-content"><?= nl2br(htmlspecialchars($row['content'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($row['file_path'])): ?>
                                <img src="<?= htmlspecialchars($row['file_path']) ?>" class="prep-image">
                            <?php endif; ?>
                            <form method="POST" class="prep-review-box">
                                <input type="hidden" name="prep_id" value="<?= (int)$row['id'] ?>">
                                <h4><?= $t['prep_review_title'] ?></h4>
                                <div class="field">
                                    <label><?= $t['prep_review_text_label'] ?></label>
                                    <textarea name="admin_review_text" rows="4" required><?= htmlspecialchars($row['admin_review_text'] ?? '') ?></textarea>
                                </div>
                                <div class="prep-review-actions">
                                    <button type="submit" name="admin_approve_prep" value="1" class="btn-approve"><?= $t['prep_btn_approve'] ?></button>
                                    <button type="submit" name="admin_decline_prep" value="1" class="btn-decline"><?= $t['prep_btn_decline'] ?></button>
                                </div>
                            </form>
                        </div>
                    </details>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="empty-state"><?= $t['no_preparations'] ?></p>
                <?php endif; ?>
                </div>
                <?php $stmt2->close(); ?>
            </div>

            <?php
            $sql_servants = "SELECT id, full_name, phone, email FROM users WHERE family_name = ? AND role = 'خادم'";
            $stmt_s = $conn->prepare($sql_servants);
            $stmt_s->bind_param("s", $current_user_family);
            $stmt_s->execute();
            $result_servants = $stmt_s->get_result();

            $sql_reports = "SELECT 
                                u.full_name,
                                COUNT(a.id) as attended_days,
                                (SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE user_id IN (SELECT id FROM users WHERE family_name = ?)) as total_meeting_days
                            FROM users u
                            LEFT JOIN attendance a ON u.id = a.user_id
                            WHERE u.family_name = ? AND u.role = 'خادم'
                            GROUP BY u.id";
            $stmt_r = $conn->prepare($sql_reports);
            $stmt_r->bind_param("ss", $current_user_family, $current_user_family);
            $stmt_r->execute();
            $result_reports = $stmt_r->get_result();
            ?>

            <!-- SERVANTS -->
            <div id="servants-section" class="card admin-section" style="padding: 30px; border-top: 4px solid var(--gold);">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                    <h3 style="color: var(--text-dark); margin: 0;"><?= $t['servants_title'] ?> (<?= htmlspecialchars($current_user_family) ?>)</h3>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <a href="dashboard.php?export_servants_excel=1"
                           class="btn-submit"
                           style="margin:0; padding:8px 16px; font-size:0.9rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                            <?= $lang === 'ar' ? '📥 Excel' : '📥 Excel' ?>
                        </a>
                        <a href="dashboard.php?export_members_pdf=1"
                           target="_blank"
                           class="btn-submit"
                           style="margin:0; padding:8px 16px; font-size:0.9rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; background:#C0392B;">
                            <?= $lang === 'ar' ? '📄 PDF' : '📄 PDF' ?>
                        </a>
                    </div>
                </div>
                <p style="color: var(--text-soft); font-size: 0.9rem; margin-bottom: 20px;"><?= $t['servants_desc'] ?></p>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th><?= $t['col_servant_name'] ?></th>
                            <th><?= $t['col_phone'] ?></th>
                            <th><?= $t['col_email'] ?></th>
                            <th><?= $t['col_qr'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_servants && $result_servants->num_rows > 0): ?>
                            <?php while($row = $result_servants->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($row['full_name']) ?></td>
                                    <td><?= htmlspecialchars($row['phone']) ?></td>
                                    <td style="color: var(--text-soft);"><?= htmlspecialchars($row['email']) ?></td>
                                    <td><button class="btn-qr" onclick="showQRCode(<?= $row['id'] ?>)"><?= $t['btn_view_qr'] ?></button></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-soft); padding: 25px;"><?= $t['no_servants'] ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- REPORTS -->
            <div id="reports-section" class="card admin-section" style="padding: 30px; border-top: 4px solid var(--gold);">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                    <h3 style="color: var(--text-dark); margin: 0;"><?= $t['reports_title'] ?></h3>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <a href="dashboard.php?export_service_reports_excel=1" class="btn-submit" style="margin:0; padding:8px 16px; font-size:0.9rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">📥 Excel</a>
                        <a href="dashboard.php?export_service_reports_pdf=1" target="_blank" class="btn-submit" style="margin:0; padding:8px 16px; font-size:0.9rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; background:#C0392B;">📄 PDF</a>
                    </div>
                </div>
                <p style="color: var(--text-soft); font-size: 0.9rem; margin-bottom: 20px;"><?= $t['reports_desc'] ?></p>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th><?= $t['col_servant_name'] ?></th>
                            <th><?= $t['col_attended_days'] ?></th>
                            <th><?= $t['col_total_meetings'] ?></th>
                            <th><?= $t['col_avg_attendance'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_reports && $result_reports->num_rows > 0): ?>
                            <?php while($row = $result_reports->fetch_assoc()):
                                $total_days = $row['total_meeting_days'] > 0 ? $row['total_meeting_days'] : 1;
                                $average = round(($row['attended_days'] / $total_days) * 100);
                                $badge_class = $average >= 75 ? 'badge-good' : 'badge-alert';
                            ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($row['full_name']) ?></td>
                                    <td><?= $row['attended_days'] ?> <?= $t['unit_day'] ?></td>
                                    <td><?= $row['total_meeting_days'] ?> <?= $t['unit_meeting'] ?></td>
                                    <td><span class="badge <?= $badge_class ?>"><?= $average ?>%</span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-soft); padding: 25px;"><?= $t['no_reports'] ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- MEMBER ATTENDANCE REPORT -->
            <div id="member-attendance-section" class="card admin-section" style="padding: 30px; border-top: 4px solid var(--gold); margin-top: 24px;">
                <h3 style="color: var(--text-dark); margin: 0 0 4px 0;"><?= $t['member_attendance_title'] ?></h3>
                <p style="color: var(--text-soft); font-size: 0.9rem; margin-bottom: 20px;"><?= $t['member_attendance_desc'] ?></p>

                <form method="GET" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 20px; padding: 16px; background: var(--cream); border-radius: 8px;">
                    <input type="hidden" name="section" value="reports">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-mid); margin-bottom: 3px;"><?= $t['filter_class'] ?></label>
                        <select name="att_class" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border); min-width: 150px;">
                            <option value="all"><?= $t['filter_all_classes'] ?></option>
                            <?php foreach (get_family_classes($current_user_family) as $cls): ?>
                                <option value="<?= htmlspecialchars($cls) ?>" <?= (isset($_GET['att_class']) && $_GET['att_class'] === $cls) ? 'selected' : '' ?>><?= htmlspecialchars($cls) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-mid); margin-bottom: 3px;"><?= $t['filter_date_from'] ?></label>
                        <input type="date" name="att_from" value="<?= htmlspecialchars($_GET['att_from'] ?? date('Y-m-d', strtotime('-30 days'))) ?>" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-mid); margin-bottom: 3px;"><?= $t['filter_date_to'] ?></label>
                        <input type="date" name="att_to" value="<?= htmlspecialchars($_GET['att_to'] ?? date('Y-m-d')) ?>" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border);">
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn-submit" style="padding: 6px 16px; font-size: 0.85rem;"><?= $lang === 'ar' ? '🔍 عرض' : '🔍 View' ?></button>
                        <a href="dashboard.php?export_members_attendance=1&att_class=<?= urlencode($_GET['att_class'] ?? 'all') ?>&att_from=<?= urlencode($_GET['att_from'] ?? date('Y-m-d', strtotime('-30 days'))) ?>&att_to=<?= urlencode($_GET['att_to'] ?? date('Y-m-d')) ?>" class="btn-submit" style="margin:0; padding:6px 16px; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:5px;">📥 Excel</a>
                        <a href="dashboard.php?export_members_attendance_pdf=1&att_class=<?= urlencode($_GET['att_class'] ?? 'all') ?>&att_from=<?= urlencode($_GET['att_from'] ?? date('Y-m-d', strtotime('-30 days'))) ?>&att_to=<?= urlencode($_GET['att_to'] ?? date('Y-m-d')) ?>" target="_blank" class="btn-submit" style="margin:0; padding:6px 16px; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:5px; background:#C0392B;">📄 PDF</a>
                    </div>
                </form>

                <?php
                $att_filter_class = $_GET['att_class'] ?? 'all';
                $att_from = $_GET['att_from'] ?? date('Y-m-d', strtotime('-30 days'));
                $att_to   = $_GET['att_to'] ?? date('Y-m-d');

                $att_where = "WHERE s.family_name = ?";
                $att_params = [$current_user_family];
                $att_types = "s";

                if ($att_filter_class !== '' && $att_filter_class !== 'all') {
                    $att_where .= " AND s.class_name = ?";
                    $att_params[] = $att_filter_class;
                    $att_types .= "s";
                }

                $att_sql = "SELECT s.id, s.student_name, s.class_name, s.phone
                    FROM students s $att_where ORDER BY s.class_name ASC, s.student_name ASC";
                $att_stmt = $conn->prepare($att_sql);
                $att_stmt->bind_param($att_types, ...$att_params);
                $att_stmt->execute();
                $att_result = $att_stmt->get_result();

                // Get attendance stats per student in date range
                $att_stats = [];
                $stats_stmt = $conn->prepare(
                    "SELECT sa.student_id,
                            SUM(CASE WHEN sa.status = 1 THEN 1 ELSE 0 END) as present_count,
                            SUM(CASE WHEN sa.status = 0 THEN 1 ELSE 0 END) as absent_count
                     FROM student_attendance sa
                     JOIN students s ON sa.student_id = s.id
                     WHERE s.family_name = ? AND sa.attendance_date BETWEEN ? AND ?
                     GROUP BY sa.student_id"
                );
                $stats_stmt->bind_param("sss", $current_user_family, $att_from, $att_to);
                $stats_stmt->execute();
                $stats_result = $stats_stmt->get_result();
                while ($stat = $stats_result->fetch_assoc()) {
                    $att_stats[(int)$stat['student_id']] = $stat;
                }
                $stats_stmt->close();
                ?>

                <table class="custom-table">
                    <thead>
                        <tr>
                            <th><?= $t['col_student_name'] ?></th>
                            <th><?= $t['col_student_class'] ?></th>
                            <th><?= $t['col_phone'] ?></th>
                            <th><?= $t['col_total_attendance'] ?></th>
                            <th><?= $t['col_total_absence'] ?></th>
                            <th><?= $t['col_attendance_percent'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($att_result && $att_result->num_rows > 0): ?>
                            <?php while ($att_row = $att_result->fetch_assoc()):
                                $sid = (int) $att_row['id'];
                                $present = (int)($att_stats[$sid]['present_count'] ?? 0);
                                $absent  = (int)($att_stats[$sid]['absent_count'] ?? 0);
                                $total   = $present + $absent;
                                $pct     = $total > 0 ? round(($present / $total) * 100) : 0;
                                $pct_badge = $pct >= 75 ? 'badge-good' : ($pct >= 50 ? 'badge-warn' : 'badge-alert');
                            ?>
                            <tr>
                                <td style="font-weight: 600;"><?= htmlspecialchars($att_row['student_name']) ?></td>
                                <td style="color: var(--gold-dark);"><?= htmlspecialchars($att_row['class_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($att_row['phone'] ?? '') ?></td>
                                <td style="color: #2E7D32; font-weight: 700;"><?= $present ?> <?= $t['unit_day'] ?></td>
                                <td style="color: #C62828; font-weight: 700;"><?= $absent ?> <?= $t['unit_day'] ?></td>
                                <td><span class="badge <?= $pct_badge ?>"><?= $pct ?>%</span></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-soft); padding: 25px;"><?= $t['no_reports'] ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php $att_stmt->close(); ?>
            </div>

            <?php
            $stmt_s->close();
            $stmt_r->close();
            endif;
            ?>

            <!-- FAMILY CHAT -->
            <div id="chat-section" class="card admin-section prep-panel">
                <div class="chat-heading">
                    <h3><?= $t['chat_title'] ?></h3>
                    <?php if ($is_admin): ?>
                        <div class="chat-options">
                            <button type="button" id="chat-options-button" class="chat-options-button" aria-label="<?= htmlspecialchars($t['chat_options']) ?>" aria-haspopup="true" aria-expanded="false">⋮</button>
                            <div id="chat-options-menu" class="chat-options-menu" hidden>
                                <button type="button" id="chat-delete-all-button" class="chat-menu-delete"><?= htmlspecialchars($t['chat_delete_all']) ?></button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <p style="color:var(--text-soft); margin:-10px 0 20px;"><?= $t['chat_subtitle'] ?> — <strong style="color:var(--gold-dark);"><?= htmlspecialchars($current_user_family) ?></strong></p>

                <div id="chat-messages" class="chat-messages" data-family="<?= htmlspecialchars($current_user_family) ?>" data-user-id="<?= (int) $user['id'] ?>">
                    <div class="empty-state" id="chat-empty-state"><?= $t['chat_empty'] ?></div>
                </div>

                <form id="chat-form" class="chat-input-row" autocomplete="off" enctype="multipart/form-data">
                    <label class="chat-file-btn" title="<?= $lang === 'ar' ? 'إرفاق ملف' : 'Attach file' ?>" aria-label="<?= $lang === 'ar' ? 'إرفاق ملف' : 'Attach file' ?>">
                        📎
                        <input type="file" id="chat-attachment-input" class="chat-file-input" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip">
                    </label>
                    <input type="text" id="chat-message-input" class="chat-input" maxlength="1000" placeholder="<?= htmlspecialchars($t['chat_placeholder']) ?>">
                    <button type="submit" class="btn-submit"><?= $t['chat_send'] ?></button>
                </form>
            </div>

            <!-- SETTINGS -->
            <div id="settings-section" class="card admin-section prep-panel">
                <h3><?= $t['settings_title'] ?></h3>
                <?php if ($settings_success): ?><div class="alert alert-success"><?= htmlspecialchars($settings_success) ?></div><?php endif; ?>
                <?php if ($settings_error): ?><div class="alert alert-error"><?= $settings_error ?></div><?php endif; ?>

                <div class="settings-grid">
                    <!-- Profile form -->
                    <form method="POST" enctype="multipart/form-data" class="settings-card prep-form">
                        <input type="hidden" name="update_profile" value="1">
                        <h4><?= $t['profile_title'] ?></h4>
                        <div class="field">
                            <label><?= $t['profile_picture_label'] ?></label>
                            <div style="display:flex; align-items:center; gap:16px;">
                                <div style="width:70px; height:70px; border-radius:50%; overflow:hidden; border:2px solid var(--gold); background:var(--cream); display:flex; align-items:center; justify-content:center; font-size:1.8rem; flex-shrink:0;">
                                    <?php if (!empty($profile['profile_picture']) && file_exists($profile['profile_picture'])): ?>
                                        <img src="<?= htmlspecialchars($profile['profile_picture']) ?>?v=<?= time() ?>" alt="avatar" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        🙏
                                    <?php endif; ?>
                                </div>
                                <div style="flex:1; display:flex; flex-direction:column; gap:10px;">
                                    <input type="file" name="profile_picture" accept="image/png,image/jpeg,image/gif,image/webp">
                                    <?php if (!empty($profile['profile_picture']) && file_exists($profile['profile_picture'])): ?>
                                        <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-size:0.88rem; font-weight:700; color:#C62828; background:#FFF5F5; border:1px solid #F5C6CB; border-radius:6px; padding:5px 10px; width:fit-content;">
                                            <input type="checkbox" name="remove_profile_picture" value="1" style="width:auto; margin:0; accent-color:#C62828;">
                                            🗑️ <?= htmlspecialchars($t['remove_profile_picture_label']) ?>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="field">
                            <label><?= $t['full_name_label'] ?></label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($profile['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="field">
                            <label><?= $t['email_label'] ?></label>
                            <input type="email" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" dir="ltr" required>
                        </div>
                        <div class="field">
                            <label><?= $t['phone_label'] ?></label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" dir="ltr">
                        </div>
                        <div class="field">
                            <label><?= $t['role_label'] ?></label>
                            <select name="role" id="settings_role_select" required onchange="toggleClassField(this.value)">
                                <option value="خادم" <?= ($profile['role'] === 'خادم') ? 'selected' : '' ?>><?= $t['role_option_servant'] ?></option>
                                <option value="أمين الخدمة" <?= ($profile['role'] === 'أمين الخدمة') ? 'selected' : '' ?>><?= $t['role_option_admin'] ?></option>
                            </select>
                        </div>
                        <div class="field" id="class_field_wrapper" style="<?= ($profile['role'] === 'أمين الخدمة') ? 'display:none;' : '' ?>">
                            <label>📚 فصلك (الفصل الذي تخدم فيه) *</label>
                            <select name="class_name" id="settings_class_name">
                                <option value="">— اختر فصلك —</option>
                                <?php foreach (get_family_classes($profile['family_name']) as $cls): ?>
                                    <option value="<?= htmlspecialchars($cls) ?>" <?= ($profile['class_name'] === $cls) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cls) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($profile['role'] === 'خادم' && empty($profile['class_name'])): ?>
                                <small style="color:#C62828; font-weight:600;">⚠️ يجب اختيار فصلك لتتمكن من رؤية مخدوميك</small>
                            <?php elseif (!empty($profile['class_name'])): ?>
                                <small style="color:#2E7D32; font-weight:600;">✅ فصلك الحالي: <?= htmlspecialchars($profile['class_name']) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="field">
                            <label><?= $t['family_label'] ?></label>
                            <select name="family_name" required>
                                <option value="أسرة ني انجيلوس" <?= ($profile['family_name'] === 'أسرة ني انجيلوس') ? 'selected' : '' ?>>أسرة ني انجيلوس</option>
                                <option value="أسرة ملايكه" <?= ($profile['family_name'] === 'أسرة ملايكه') ? 'selected' : '' ?>>أسرة ملايكه</option>
                                <option value="أسرة سمائيين" <?= ($profile['family_name'] === 'أسرة سمائيين') ? 'selected' : '' ?>>أسرة سمائيين</option>
                                <option value="أسرة شهداء" <?= ($profile['family_name'] === 'أسرة شهداء') ? 'selected' : '' ?>>أسرة شهداء</option>
                                <option value="أسرة قديسين" <?= ($profile['family_name'] === 'أسرة قديسين') ? 'selected' : '' ?>>أسرة قديسين</option>
                                <option value="أسرة لباس الصليب" <?= ($profile['family_name'] === 'أسرة لباس الصليب') ? 'selected' : '' ?>>أسرة لباس الصليب</option>
                                <option value="أسرة قديسات" <?= ($profile['family_name'] === 'أسرة قديسات') ? 'selected' : '' ?>>أسرة قديسات</option>
                                <option value="أسرة ابطال الايمان" <?= ($profile['family_name'] === 'أسرة ابطال الايمان') ? 'selected' : '' ?>>أسرة ابطال الايمان</option>
                                <option value="أسرة شهيدات" <?= ($profile['family_name'] === 'أسرة شهيدات') ? 'selected' : '' ?>>أسرة شهيدات</option>
                                <option value="أسرة الرسل الجامعية" <?= ($profile['family_name'] === 'أسرة الرسل الجامعية') ? 'selected' : '' ?>>أسرة الرسل الجامعية</option>
                                <option value="أسرة الانبا ابرأم للخريجين" <?= ($profile['family_name'] === 'أسرة الانبا ابرأم للخريجين') ? 'selected' : '' ?>>أسرة الانبا ابرأم للخريجين</option>
                            </select>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="btn-submit"><?= $t['btn_save_profile'] ?></button>
                        </div>
                    </form>

                    <!-- Security form -->
                    <form method="POST" class="settings-card prep-form">
                        <input type="hidden" name="update_security" value="1">
                        <h4><?= $t['security_title'] ?></h4>
                        <div class="field">
                            <label><?= $t['current_password_label'] ?></label>
                            <input type="password" name="current_password" required>
                        </div>
                        <div class="field">
                            <label><?= $t['new_password_label'] ?></label>
                            <input type="password" name="new_password" minlength="8" required>
                        </div>
                        <div class="field">
                            <label><?= $t['confirm_password_label'] ?></label>
                            <input type="password" name="confirm_password" minlength="8" required>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="btn-submit"><?= $t['btn_change_password'] ?></button>
                        </div>
                    </form>

                    <!-- Account devices -->
                    <div class="settings-card">
                        <h4><?= $lang === 'ar' ? 'الأجهزة المستخدمة للحساب' : 'Account Devices' ?></h4>
                        <p style="color: var(--text-soft); font-size: 0.9rem; margin: -4px 0 16px;">
                            <?= $lang === 'ar'
                                ? 'الأجهزة والمتصفحات التي استخدمت هذا الحساب مؤخراً.'
                                : 'Devices and browsers that recently used this account.' ?>
                        </p>

                        <div class="devices-list">
                            <?php if (!empty($account_devices)): ?>
                                <?php foreach ($account_devices as $device): ?>
                                    <div class="device-item">
                                        <div class="device-head">
                                            <div class="device-name">
                                                <?= htmlspecialchars($device['device_name'] ?? '') ?>
                                            </div>
                                            <?php if (!empty($device['is_current'])): ?>
                                                <span class="device-badge"><?= $lang === 'ar' ? 'هذا الجهاز' : 'This device' ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="device-meta">
                                            <span><?= $lang === 'ar' ? 'المتصفح:' : 'Browser:' ?> <?= htmlspecialchars($device['browser_name'] ?? '') ?></span>
                                            <span><?= $lang === 'ar' ? 'النظام:' : 'Platform:' ?> <?= htmlspecialchars($device['platform_name'] ?? '') ?></span>
                                            <span><?= $lang === 'ar' ? 'IP:' : 'IP:' ?> <?= htmlspecialchars($device['ip_address'] ?: ($lang === 'ar' ? 'غير معروف' : 'Unknown')) ?></span>
                                            <span><?= $lang === 'ar' ? 'أول استخدام:' : 'First seen:' ?> <?= htmlspecialchars($device['first_seen'] ?? '') ?></span>
                                            <span><?= $lang === 'ar' ? 'آخر نشاط:' : 'Last activity:' ?> <?= htmlspecialchars($device['last_seen'] ?? '') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="empty-state"><?= $lang === 'ar' ? 'لا توجد أجهزة مسجلة حتى الآن.' : 'No devices recorded yet.' ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Appearance form -->
                    <form method="POST" class="settings-card prep-form">
                        <input type="hidden" name="update_theme" value="1">
                        <h4><?= $t['appearance_title'] ?></h4>
                        <div class="language-options">
                            <label class="language-option">
                                <input type="radio" name="theme_mode" value="light" <?= ($profile['theme_mode'] ?? 'light') !== 'dark' ? 'checked' : '' ?>>
                                <?= $t['theme_light'] ?>
                            </label>
                            <label class="language-option">
                                <input type="radio" name="theme_mode" value="dark" <?= ($profile['theme_mode'] ?? 'light') === 'dark' ? 'checked' : '' ?>>
                                <?= $t['theme_dark'] ?>
                            </label>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="btn-submit"><?= $t['btn_save_theme'] ?></button>
                        </div>
                    </form>
                    <!-- 2FA Card -->
                    <div class="settings-card" style="border-top: 3px solid var(--gold);">
                        <h4>🔐 <?= $lang === 'ar' ? 'التحقق بخطوتين (2FA)' : 'Two-Factor Authentication (2FA)' ?></h4>
                        <p style="color: var(--text-soft); font-size: 0.9rem; margin-bottom: 16px;">
                            <?= $lang === 'ar' 
                                ? 'أضف طبقة أمان إضافية لحسابك باستخدام تطبيق Google Authenticator أو Authy.' 
                                : 'Add an extra security layer to your account using Google Authenticator or Authy app.' ?>
                        </p>
                        <div class="settings-actions">
                            <a href="2fa.php" class="btn-submit" style="text-decoration: none; display: inline-block;">
                                <?= $lang === 'ar' ? 'إدارة 2FA' : 'Manage 2FA' ?>
                            </a>
                        </div>
                    </div>

                </div>

        </section>
    </main>
</div>

<script>
function showSection(sectionName, clickedButton) {
    document.querySelectorAll('.admin-section, #home-section').forEach(el => {
        el.style.display = 'none';
    });
    document.querySelectorAll('.sidebar-menu .menu-item').forEach(item => {
        item.classList.remove('active');
    });
    const target = document.getElementById(sectionName + '-section');
    if (target) target.style.display = 'block';
    if (clickedButton) clickedButton.classList.add('active');
}

<?php if (
    ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_profile']) || isset($_POST['update_security']))) ||
    (($_GET['section'] ?? '') === 'settings')
): ?>
document.addEventListener('DOMContentLoaded', function () {
    showSection('settings', document.getElementById('settings-btn'));
});
<?php endif; ?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_lesson'])): ?>
document.addEventListener('DOMContentLoaded', function () {
    showSection('prepare', document.getElementById('prepare-btn'));
});
<?php endif; ?>

// Edit Preparation Modal
var currentEditFilePath = '';

function openEditPrepModal(id, title, date, content, filePath) {
    document.getElementById('editPrepId').value = id;
    document.getElementById('editLessonTitle').value = title;
    document.getElementById('editPrepDate').value = date;
    document.getElementById('editPrepContent').value = content;
    currentEditFilePath = filePath || '';
    
    var errEl = document.getElementById('editPrepJsError');
    if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
    }
    
    var fileInput = document.querySelector('#editPrepModal input[type="file"]');
    if (fileInput) fileInput.value = '';
    
    var imgWrap = document.getElementById('editCurrentImageWrap');
    var imgEl = document.getElementById('editCurrentImage');
    var removeChk = document.getElementById('editRemoveImage');
    var newImgLabel = document.getElementById('editNewImageLabel');
    
    if (filePath && filePath.trim() !== '') {
        imgEl.src = filePath;
        imgWrap.style.display = 'block';
        if (removeChk) removeChk.checked = false;
        if (newImgLabel) newImgLabel.textContent = '<?= $lang === "ar" ? "رفع صورة بديلة (اختياري)" : "Upload alternative image (optional)" ?>';
        toggleNewImageField(removeChk);
    } else {
        imgWrap.style.display = 'none';
        if (newImgLabel) newImgLabel.textContent = '<?= $lang === "ar" ? "رفع صورة الدرس (اختياري)" : "Upload lesson image (optional)" ?>';
        document.getElementById('editNewImageWrap').style.display = 'block';
    }
    
    var modal = document.getElementById('editPrepModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeEditPrepModal() {
    document.getElementById('editPrepModal').style.display = 'none';
    document.body.style.overflow = '';
}
function toggleNewImageField(checkbox) {
    var wrap = document.getElementById('editNewImageWrap');
    if (checkbox && checkbox.checked) {
        wrap.style.display = 'none';
    } else {
        wrap.style.display = 'block';
    }
}
function isFridayDateString(dateStr) {
    if (!dateStr) return false;
    var parts = dateStr.split('-');
    if (parts.length !== 3) return false;
    // Construct local date (year, monthIndex, day)
    var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    return d.getDay() === 5; // 5 is Friday
}

function validateCreatePrepForm() {
    var title = document.getElementById('createLessonTitle').value.trim();
    var dateVal = document.getElementById('createPrepDate').value;
    var errEl = document.getElementById('createPrepJsError');

    if (!title) {
        if (errEl) {
            errEl.textContent = '<?= $lang === "ar" ? "⚠️ يرجى إدخال عنوان الدرس." : "⚠️ Please enter the lesson title." ?>';
            errEl.style.display = 'block';
        }
        return false;
    }

    if (!isFridayDateString(dateVal)) {
        if (errEl) {
            errEl.textContent = '<?= $lang === "ar" ? "⚠️ عذراً، يجب أن يكون تاريخ التحضير يوم جمعة فقط." : "⚠️ Sorry, preparation date must be on a Friday only." ?>';
            errEl.style.display = 'block';
        }
        return false;
    }

    if (errEl) errEl.style.display = 'none';
    return true;
}

function validateEditPrepForm() {
    var title = document.getElementById('editLessonTitle').value.trim();
    var dateVal = document.getElementById('editPrepDate').value;
    var content = document.getElementById('editPrepContent').value.trim();
    var fileInput = document.querySelector('#editPrepModal input[type="file"]');
    var removeChk = document.getElementById('editRemoveImage');
    var hasNewFile = fileInput && fileInput.files && fileInput.files.length > 0;
    var isRemovingOld = removeChk && removeChk.checked;
    var hasOldFile = currentEditFilePath && currentEditFilePath.trim() !== '' && !isRemovingOld;
    var hasImage = hasNewFile || hasOldFile;
    
    var errEl = document.getElementById('editPrepJsError');
    
    if (!title) {
        if (errEl) {
            errEl.textContent = '<?= $lang === "ar" ? "⚠️ يرجى إدخال عنوان الدرس." : "⚠️ Please enter the lesson title." ?>';
            errEl.style.display = 'block';
        }
        return false;
    }

    if (!isFridayDateString(dateVal)) {
        if (errEl) {
            errEl.textContent = '<?= $lang === "ar" ? "⚠️ عذراً، يجب أن يكون تاريخ التحضير يوم جمعة فقط." : "⚠️ Sorry, preparation date must be on a Friday only." ?>';
            errEl.style.display = 'block';
        }
        return false;
    }
    
    if (!content && !hasImage) {
        if (errEl) {
            errEl.textContent = '<?= $lang === "ar" ? "⚠️ يجب كتابة محتوى/وصف للدرس أو رفع صورة على الأقل." : "⚠️ You must provide either a lesson description or an image." ?>';
            errEl.style.display = 'block';
        }
        return false;
    }
    
    if (errEl) errEl.style.display = 'none';
    return true;
}
// Close modal on backdrop click
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('editPrepModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeEditPrepModal();
        });
    }
});

<?php if (isset($_POST['delete_preparation']) || isset($_POST['edit_preparation'])): ?>
document.addEventListener('DOMContentLoaded', function () {
    showSection('prepare', document.getElementById('prepare-btn'));
});
<?php endif; ?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lord_brothers'])): ?>
document.addEventListener('DOMContentLoaded', function () {
    showSection('lord-brothers', document.getElementById('lord-brothers-btn'));
});
<?php endif; ?>

// ================================================
// FAMILY CHAT (AJAX, same-tab, no page reload)
// ================================================
(function () {
    const chatMessagesEl = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    if (!chatMessagesEl || !chatForm) return;

    const chatInput = document.getElementById('chat-message-input');
    const chatAttachmentInput = document.getElementById('chat-attachment-input');
    let emptyStateEl = document.getElementById('chat-empty-state');
    const currentUserId = parseInt(chatMessagesEl.getAttribute('data-user-id'), 10);
    let lastMessageId = 0;
    let pollTimer = null;
    const chatApiUrl = 'dashboard.php?chat_action=';
    const chatOptionsButton = document.getElementById('chat-options-button');
    const chatOptionsMenu = document.getElementById('chat-options-menu');
    const chatDeleteAllButton = document.getElementById('chat-delete-all-button');

    function formatTime(dateStr) {
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleString('<?= $isRtl ? "ar-EG" : "en-US" ?>', { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' });
    }

    function appendMessage(msg) {
        if (emptyStateEl) emptyStateEl.remove();
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (msg.user_id === currentUserId ? 'mine' : 'theirs');
        bubble.dataset.messageId = msg.id;
        const author = document.createElement('span');
        author.className = 'chat-author';
        author.textContent = msg.full_name;
        bubble.appendChild(author);
        if (msg.message) {
            const text = document.createElement('span');
            text.className = 'chat-text';
            text.textContent = msg.message;
            bubble.appendChild(text);
        }
        if (msg.attachment_path) {
            const attachmentUrl = chatApiUrl + 'attachment&id=' + encodeURIComponent(msg.id);
            const attachment = document.createElement('a');
            attachment.className = 'chat-attachment';
            attachment.href = attachmentUrl;
            attachment.target = '_blank';
            attachment.rel = 'noopener';

            if ((msg.attachment_type || '').startsWith('image/')) {
                const img = document.createElement('img');
                img.src = attachmentUrl;
                img.alt = msg.attachment_name || 'attachment';
                attachment.appendChild(img);
            } else {
                attachment.textContent = '📎 ' + (msg.attachment_name || 'Download attachment');
            }
            bubble.appendChild(attachment);
        }
        const time = document.createElement('span');
        time.className = 'chat-time';
        time.textContent = formatTime(msg.created_at);
        bubble.appendChild(time);
        if (msg.user_id === currentUserId) {
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'chat-delete-button';
            deleteButton.textContent = <?= json_encode($t['chat_delete'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            deleteButton.title = deleteButton.textContent;
            deleteButton.addEventListener('click', function () {
                deleteMessage(msg.id, bubble, deleteButton);
            });
            bubble.appendChild(deleteButton);
        }
        chatMessagesEl.appendChild(bubble);
        if (msg.id > lastMessageId) lastMessageId = msg.id;
        chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
    }

    async function deleteMessage(messageId, bubble, deleteButton) {
        if (!window.confirm(<?= json_encode($t['chat_confirm_delete'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)) return;
        deleteButton.disabled = true;
        try {
            const body = new FormData();
            body.set('id', messageId);
            const res = await fetch(chatApiUrl + 'delete', {
                method: 'POST',
                credentials: 'same-origin',
                body
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                deleteButton.disabled = false;
                return;
            }
            bubble.remove();
            if (!chatMessagesEl.querySelector('.chat-bubble')) {
                const emptyState = document.createElement('div');
                emptyState.className = 'empty-state';
                emptyState.id = 'chat-empty-state';
                emptyState.textContent = <?= json_encode($t['chat_empty'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                chatMessagesEl.appendChild(emptyState);
                emptyStateEl = emptyState;
            }
        } catch (e) {
            deleteButton.disabled = false;
        }
    }

    if (chatOptionsButton && chatOptionsMenu && chatDeleteAllButton) {
        chatOptionsButton.addEventListener('click', function () {
            const isOpen = !chatOptionsMenu.hidden;
            chatOptionsMenu.hidden = isOpen;
            chatOptionsButton.setAttribute('aria-expanded', String(!isOpen));
        });

        chatDeleteAllButton.addEventListener('click', async function () {
            if (!window.confirm(<?= json_encode($t['chat_confirm_delete_all'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)) return;
            chatDeleteAllButton.disabled = true;
            try {
                const res = await fetch(chatApiUrl + 'delete_all', {
                    method: 'POST',
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!res.ok || !data.success) return;
                chatMessagesEl.querySelectorAll('.chat-bubble').forEach(function (bubble) {
                    bubble.remove();
                });
                if (!chatMessagesEl.querySelector('.empty-state')) {
                    emptyStateEl = document.createElement('div');
                    emptyStateEl.className = 'empty-state';
                    emptyStateEl.id = 'chat-empty-state';
                    emptyStateEl.textContent = <?= json_encode($t['chat_empty'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    chatMessagesEl.appendChild(emptyStateEl);
                }
                chatOptionsMenu.hidden = true;
                chatOptionsButton.setAttribute('aria-expanded', 'false');
            } finally {
                chatDeleteAllButton.disabled = false;
            }
        });
    }

    async function pollMessages() {
        try {
            const res = await fetch(chatApiUrl + 'fetch&since_id=' + lastMessageId, { credentials: 'same-origin' });
            if (!res.ok) {
                if (pollTimer) clearInterval(pollTimer);
                return;
            }
            const data = await res.json();
            if (data.success && Array.isArray(data.messages) && data.messages.length) {
                data.messages.forEach(appendMessage);
            }
        } catch (e) { /* silent fail, retry on next interval */ }
    }

    chatForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const text = chatInput.value.trim();
        const attachment = chatAttachmentInput && chatAttachmentInput.files.length ? chatAttachmentInput.files[0] : null;
        if (!text && !attachment) return;
        if (attachment && attachment.size > 10 * 1024 * 1024) return;
        chatInput.value = '';
        if (chatAttachmentInput) chatAttachmentInput.value = '';
        chatInput.disabled = true;
        if (chatAttachmentInput) chatAttachmentInput.disabled = true;
        try {
            const body = new FormData();
            body.set('message', text);
            if (attachment) body.set('attachment', attachment);

            const res = await fetch(chatApiUrl + 'send', {
                method: 'POST',
                credentials: 'same-origin',
                body
            });
            if (!res.ok) return;
            const data = await res.json();
            if (data.success && data.message_data) {
                appendMessage(data.message_data);
            }
        } catch (e) { /* silent fail */ }
        chatInput.disabled = false;
        if (chatAttachmentInput) chatAttachmentInput.disabled = false;
        chatInput.focus();
    });

    // Initial load, then poll every 4 seconds while dashboard tab is open
    pollMessages();
    pollTimer = setInterval(pollMessages, 4000);
})();

function showQRCode(userId) {
    const domain = window.location.hostname;
    const protocol = window.location.protocol;
    const attendanceUrl = `${protocol}//${domain}/scan_attendance.php?id=${userId}`;
    const qrImageUrl = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(attendanceUrl)}`;

    const popup = window.open("", "QR Code", "width=400,height=450");
    popup.document.write(`
        <div style="text-align:center; font-family:sans-serif; padding:20px; direction:<?= $htmlDir ?>;">
            <h3 style="color:#2C2416;"><?= addslashes($t['qr_popup_title']) ?></h3>
            <img src="${qrImageUrl}" alt="QR Code" style="margin:20px auto; display:block; border: 2px solid #C8973A; padding: 5px; border-radius: 8px; width:250px; height:250px;">
            <p style="color:#5C4A2A; font-size:14px;"><?= addslashes($t['qr_popup_desc']) ?></p>
        </div>
    `);
}

/* Attendance Modal JS Helpers */
function openAttendanceModal() {
    document.getElementById('attendanceModal').classList.add('active');
    updateAttendanceCount();
}

function closeAttendanceModal() {
    document.getElementById('attendanceModal').classList.remove('active');
}

function toggleAllAttendance(checked) {
    document.querySelectorAll('.attendance-checkbox').forEach(function(cb) {
        cb.checked = checked;
    });
}

function updateAttendanceCount() {
    const total = document.querySelectorAll('.attendance-checkbox').length;
    const checked = document.querySelectorAll('.attendance-checkbox:checked').length;
    const countEl = document.getElementById('attendanceCount');
    if (countEl) countEl.textContent = checked + ' / ' + total;
}

/* Student Modal JS Helpers */
function openStudentModal() {
    document.getElementById('studentModalTitle').textContent = '<?= addslashes($t['btn_save_profile']) ?>';
    document.getElementById('student_id').value = '0';
    document.getElementById('modal_student_name').value = '';
    document.getElementById('modal_student_phone').value = '';
    const classSelect = document.getElementById('modal_student_class_name');
    if (classSelect) {
        classSelect.selectedIndex = 0;
    }
    document.getElementById('modal_student_address').value = '';
    document.getElementById('modal_student_birth_date').value = '';
    document.getElementById('modal_father_job').value = '';
    document.getElementById('modal_father_phone').value = '';
    document.getElementById('modal_father_age').value = '';
    document.getElementById('modal_mother_job').value = '';
    document.getElementById('modal_mother_phone').value = '';
    document.getElementById('modal_mother_age').value = '';
    document.getElementById('modal_notes').value = '';
    document.getElementById('modal_student_profile_picture').value = '';
    const addPreview = document.getElementById('modal_student_photo_preview');
    if (addPreview) { addPreview.src = ''; addPreview.style.display = 'none'; }
    document.getElementById('studentFormSubmitBtn').textContent = '<?= addslashes($t['register_btn']) ?>';
    document.getElementById('studentModal').classList.add('active');
}

function closeStudentModal() {
    document.getElementById('studentModal').classList.remove('active');
}
function previewStudentPhoto(input) {
    const preview = document.getElementById('modal_student_photo_preview');
    if (!preview) return;
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function validateStudentForm(form) {
    const blockedPattern = /مثال|example/i;
    const fieldsToCheck = [
        { id: 'modal_student_name',   label: 'اسم المخدوم / Member Name' },
        { id: 'modal_student_phone',  label: 'رقم الهاتف / Phone' },
        { id: 'modal_student_address',label: 'العنوان / Address' },
        { id: 'modal_father_job',     label: 'وظيفة الأب / Father Job' },
        { id: 'modal_father_phone',   label: 'هاتف الأب / Father Phone' },
        { id: 'modal_mother_job',     label: 'وظيفة الأم / Mother Job' },
        { id: 'modal_mother_phone',   label: 'هاتف الأم / Mother Phone' },
        { id: 'modal_notes',          label: 'الملاحظات / Notes' }
    ];
    for (const f of fieldsToCheck) {
        const el = document.getElementById(f.id);
        if (el && blockedPattern.test(el.value)) {
            el.focus();
            el.style.borderColor = '#C62828';
            el.style.background  = '#FFF5F5';
            const msg = `⚠️ حقل «${f.label}» يحتوي على كلمة مثال أو example.\nيرجى إدخال بيانات حقيقية.`;
            alert(msg);
            // Reset border after user edits
            el.addEventListener('input', () => {
                el.style.borderColor = '';
                el.style.background  = '';
            }, { once: true });
            return false;
        }
    }
    return true;
}

function editStudent(studentData) {
    document.getElementById('studentModalTitle').textContent = '<?= addslashes($t['btn_save_profile']) ?>';
    document.getElementById('student_id').value = studentData.id;
    document.getElementById('modal_student_name').value = studentData.student_name || '';
    document.getElementById('modal_student_phone').value = studentData.phone || '';

    // Select class
    const classSelect = document.getElementById('modal_student_class_name');
    if (classSelect) {
        classSelect.value = studentData.class_name || '';
    }

    document.getElementById('modal_student_address').value = studentData.address || '';
    document.getElementById('modal_student_birth_date').value = studentData.birth_date || '';
    document.getElementById('modal_father_job').value = studentData.father_job || '';
    document.getElementById('modal_father_phone').value = studentData.father_phone || '';
    document.getElementById('modal_father_age').value = studentData.father_age || '';
    document.getElementById('modal_mother_job').value = studentData.mother_job || '';
    document.getElementById('modal_mother_phone').value = studentData.mother_phone || '';
    document.getElementById('modal_mother_age').value = studentData.mother_age || '';
    document.getElementById('modal_notes').value = studentData.notes || '';
    document.getElementById('modal_student_profile_picture').value = '';
    const editPreview = document.getElementById('modal_student_photo_preview');
    if (editPreview) {
        if (studentData.profile_picture) {
            editPreview.src = studentData.profile_picture + '?v=' + Date.now();
            editPreview.style.display = 'block';
        } else {
            editPreview.src = '';
            editPreview.style.display = 'none';
        }
    }
    document.getElementById('studentFormSubmitBtn').textContent = '<?= addslashes($t['btn_save_profile']) ?>';
    document.getElementById('studentModal').classList.add('active');
}

function toggleClassField(role) {
    const wrapper = document.getElementById('class_field_wrapper');
    const selectEl = document.getElementById('settings_class_name');
    if (wrapper) {
        if (role === 'أمين الخدمة') {
            wrapper.style.display = 'none';
            if (selectEl) selectEl.value = '';
        } else {
            wrapper.style.display = 'block';
        }
    }
}

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_student']) || isset($_POST['delete_student']))): ?>
document.addEventListener('DOMContentLoaded', function () {
    showSection('members', document.getElementById('members-btn'));
});
<?php endif; ?>
</script>

<!-- Attendance Recording Modal -->
<div class="modal-overlay" id="attendanceModal">
    <div class="modal-content" style="max-width: 600px;" dir="<?= $htmlDir ?>">
        <div class="modal-header">
            <h3 style="margin:0; font-size:1.2rem; color:var(--text-dark);"><?= $t['attendance_title'] ?></h3>
            <button class="modal-close" onclick="closeAttendanceModal()">&times;</button>
        </div>
        <form method="POST" class="prep-form">
            <input type="hidden" name="save_student_attendance" value="1">
            <div class="field">
                <label><?= $t['attendance_date'] ?></label>
                <input type="date" name="attendance_date" value="<?= date('Y-m-d') ?>" required style="padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); width: 100%;">
                <small style="color: var(--text-soft); display:block; margin-top: 6px;"><?= $lang === 'ar' ? 'الحضور يُسجل فقط ليوم الجمعة، ولكن يمكنك رفع التحديد في أي وقت.' : 'Attendance can only be recorded for Friday, but you may submit it at any time.' ?></small>
            </div>
            <p style="color: var(--text-soft); font-size: 0.9rem;"><?= $t['attendance_desc'] ?></p>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span id="attendanceCount" style="font-weight: 700; color: var(--gold-dark);"></span>
                <div style="display: flex; gap: 8px;">
                <button type="button" class="btn-submit" onclick="toggleAllAttendance(true)" style="padding: 4px 12px; font-size: 0.8rem; background: #2E7D32;"><?= $lang === 'ar' ? 'تحديد الكل' : 'Select All' ?></button>
                    <button type="button" class="btn-submit" onclick="toggleAllAttendance(false)" style="padding: 4px 12px; font-size: 0.8rem; background: #C62828;"><?= $lang === 'ar' ? 'إلغاء الكل' : 'Deselect All' ?></button>
                </div>
            </div>
            <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; padding: 10px;">
                <?php
                $att_modal_conn = get_db();
                $att_modal_conn->set_charset("utf8mb4");
                if (!$is_admin && $current_user_class !== '') {
                    $modal_sql = "SELECT id, student_name, class_name FROM students WHERE family_name = ? AND class_name = ? ORDER BY student_name ASC";
                    $modal_stmt = $att_modal_conn->prepare($modal_sql);
                    $modal_stmt->bind_param("ss", $current_user_family, $current_user_class);
                } else {
                    $modal_sql = "SELECT id, student_name, class_name FROM students WHERE family_name = ? ORDER BY class_name ASC, student_name ASC";
                    $modal_stmt = $att_modal_conn->prepare($modal_sql);
                    $modal_stmt->bind_param("s", $current_user_family);
                }
                $modal_stmt->execute();
                $modal_result = $modal_stmt->get_result();

                $today_att = date('Y-m-d');
                $att_check_conn = get_db();
                $att_check_conn->set_charset("utf8mb4");
                $check_stmt = $att_check_conn->prepare("SELECT student_id FROM student_attendance WHERE attendance_date = ? AND status = 1");
                $check_stmt->bind_param("s", $today_att);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $present_today = [];
                while ($chk = $check_result->fetch_assoc()) {
                    $present_today[] = (int) $chk['student_id'];
                }
                $check_stmt->close();

                if ($modal_result && $modal_result->num_rows > 0):
                    $current_group = '';
                    while ($modal_row = $modal_result->fetch_assoc()):
                        $is_present = in_array((int)$modal_row['id'], $present_today);
                        $group = $modal_row['class_name'] ?? '';
                        if ($group !== $current_group):
                            $current_group = $group;
                            echo '<div style="font-weight: 700; color: var(--gold-dark); padding: 8px 4px; border-bottom: 1px solid var(--border); margin-top: 8px;">📖 ' . htmlspecialchars($group) . '</div>';
                        endif;
                ?>
                <label style="display: flex; align-items: center; gap: 10px; padding: 6px 4px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.15s;" onmouseover="this.style.background='var(--cream)'" onmouseout="this.style.background='transparent'">
                    <input type="checkbox" name="present_students[]" value="<?= (int)$modal_row['id'] ?>" class="attendance-checkbox" onchange="updateAttendanceCount()" <?= $is_present ? 'checked' : '' ?>>
                    <span style="flex: 1; font-weight: 600;"><?= htmlspecialchars($modal_row['student_name']) ?></span>
                    <?php if ($is_present): ?>
                        <span style="font-size: 0.75rem; color: #2E7D32; font-weight: 700;"><?= $t['present'] ?></span>
                    <?php endif; ?>
                </label>
                <?php
                    endwhile;
                else:
                    echo '<p class="empty-state">' . $t['no_members'] . '</p>';
                endif;
                $modal_stmt->close();
                ?>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button type="button" class="btn-submit" onclick="closeAttendanceModal()" style="background: var(--text-soft); box-shadow: none;"><?= $lang === 'ar' ? 'إلغاء' : 'Cancel' ?></button>
                <button type="submit" class="btn-submit"><?= $t['btn_save_attendance'] ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Student Management Modal (placed outside all sections so position:fixed works correctly) -->
<div class="modal-overlay" id="studentModal">
    <div class="modal-content" dir="<?= $htmlDir ?>">
        <div class="modal-header">
            <h3 id="studentModalTitle" style="margin:0; font-size:1.2rem; color:var(--text-dark);"><?= $lang === 'ar' ? 'إضافة مخدوم جديد' : 'Add New Member' ?></h3>
            <button class="modal-close" onclick="closeStudentModal()">&times;</button>
        </div>
        <form method="POST" class="prep-form" enctype="multipart/form-data" onsubmit="return validateStudentForm(this)">
            <input type="hidden" name="save_student" value="1">
            <input type="hidden" name="student_id" id="student_id" value="0">

            <div class="form-section">
                <div style="font-weight: 700; color: var(--gold-dark); margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 6px;"><?= $lang === 'ar' ? '📋 البيانات الشخصية للمخدوم' : '📋 Personal Data' ?></div>
                <div class="form-grid-3">
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'اسم المخدوم' : 'Member Name' ?> *</label>
                        <input type="text" name="student_name" id="modal_student_name" required placeholder="<?= $lang === 'ar' ? 'مثال: يوسف ماجد أمين' : 'e.g. George Mina' ?>">
                    </div>
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'رقم هاتف المخدوم' : 'Member Phone' ?></label>
                        <input type="tel" name="student_phone" id="modal_student_phone" placeholder="01xxxxxxxxx">
                    </div>
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'الفصل' : 'Class' ?> *</label>
                        <?php if (!$is_admin): ?>
                            <input type="text" readonly value="<?= htmlspecialchars($current_user_class ?: ($lang === 'ar' ? 'غير محدد' : 'Not set')) ?>" style="background: var(--cream); cursor: not-allowed;" placeholder="<?= $lang === 'ar' ? 'يرجى تحديد فصلك في الإعدادات أولاً' : 'Please set class in settings' ?>">
                            <input type="hidden" name="student_class_name" value="<?= htmlspecialchars($current_user_class) ?>">
                        <?php else: ?>
                            <select name="student_class_name" id="modal_student_class_name" required>
                                <option value="" disabled selected><?= $lang === 'ar' ? 'اختر شفيع الفصل...' : 'Select Class...' ?></option>
                                <?php foreach (get_family_classes($current_user_family) as $cls): ?>
                                    <option value="<?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($cls) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-grid-3" style="margin-top: 15px;">
                    <div class="field" style="grid-column: span 2;">
                        <label><?= $lang === 'ar' ? 'العنوان بالكامل' : 'Full Address' ?></label>
                        <input type="text" name="student_address" id="modal_student_address" placeholder="<?= $lang === 'ar' ? 'مثال: 13 ش عطية الاشقر، الخلفاوي' : 'e.g. 13th Street' ?>">
                    </div>
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'تاريخ الميلاد' : 'Birth Date' ?></label>
                        <input type="date" name="student_birth_date" id="modal_student_birth_date">
                    </div>
                </div>
            </div>

            <div class="form-section" style="margin-top: 25px;">
                <div style="font-weight: 700; color: var(--gold-dark); margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 6px;"><?= $lang === 'ar' ? '👨‍👩‍👦 بيانات أولياء الأمور' : '👨‍👩‍👦 Parents Data' ?></div>
                <div class="form-grid-3">
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'وظيفة الأب' : 'Father Job' ?></label>
                        <input type="text" name="father_job" id="modal_father_job" placeholder="<?= $lang === 'ar' ? 'مثال: مهندس ديكور' : 'e.g. Engineer' ?>">
                    </div>
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'هاتف الأب' : 'Father Phone' ?></label>
                        <input type="tel" name="father_phone" id="modal_father_phone" placeholder="01xxxxxxxxx">
                    </div>
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'سن الأب (بالسنوات)' : 'Father Age' ?></label>
                        <input type="number" name="father_age" id="modal_father_age" min="18" max="100" placeholder="<?= $lang === 'ar' ? 'مثال: 45' : 'e.g. 45' ?>">
                    </div>
                </div>
                <div class="form-grid-3" style="margin-top: 15px;">
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'وظيفة الأم' : 'Mother Job' ?></label>
                        <input type="text" name="mother_job" id="modal_mother_job" placeholder="<?= $lang === 'ar' ? 'مثال: معلمة لغة عربية' : 'e.g. Teacher' ?>">
                    </div>
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'هاتف الأم' : 'Mother Phone' ?></label>
                        <input type="tel" name="mother_phone" id="modal_mother_phone" placeholder="01xxxxxxxxx">
                    </div>
                    <div class="field">
                        <label><?= $lang === 'ar' ? 'سن الأم (بالسنوات)' : 'Mother Age' ?></label>
                        <input type="number" name="mother_age" id="modal_mother_age" min="18" max="100" placeholder="<?= $lang === 'ar' ? 'مثال: 40' : 'e.g. 40' ?>">
                    </div>
                </div>
            </div>

            <div class="field" style="margin-top: 20px;">
                <label><?= $lang === 'ar' ? 'ملاحظات الافتقاد والمتابعة' : 'Notes & Follow-up' ?></label>
                <textarea name="notes" id="modal_notes" rows="4" placeholder="<?= $lang === 'ar' ? 'اكتب أي ملاحظات تخص الحالة الصحية، الدراسية، أو الروحية للمخدوم...' : 'Write any notes regarding health, studies, or spiritual status...' ?>"></textarea>
            </div>

            <div class="field" style="margin-top: 20px;">
                <label><?= $lang === 'ar' ? 'صورة المخدوم' : 'Member Photo' ?></label>
                <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                    <img id="modal_student_photo_preview" src="" alt="preview" style="width:64px; height:64px; object-fit:cover; border-radius:50%; border:1px solid var(--border); display:none;">
                    <input type="file" name="student_profile_picture" id="modal_student_profile_picture" accept="image/*" onchange="previewStudentPhoto(this)">
                </div>
                <small style="color: var(--text-soft); display:block; margin-top: 6px;"><?= $lang === 'ar' ? 'الصيغ المدعومة: JPG, PNG, GIF, WEBP — بحد أقصى 5 ميجا.' : 'Supported: JPG, PNG, GIF, WEBP — max 5MB.' ?></small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px;">
                <button type="button" class="btn-submit" onclick="closeStudentModal()" style="background: var(--text-soft); box-shadow: none;"><?= $lang === 'ar' ? 'إلغاء' : 'Cancel' ?></button>
                <button type="submit" class="btn-submit" id="studentFormSubmitBtn"><?= $lang === 'ar' ? 'حفظ المخدوم' : 'Save Member' ?></button>
            </div>
            
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[name="days_before"]').forEach(function(radio) {
        if (radio.checked) {
            const lbl = radio.closest('label');
            if (lbl) { lbl.style.borderColor = 'var(--gold)'; lbl.style.background = 'var(--cream)'; }
        }
    });
});
</script>
</body>
</html>
