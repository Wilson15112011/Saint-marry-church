<?php
// ================================================
// chat_send.php – Post a message to the family chat (AJAX, JSON)
// ================================================
header('Content-Type: application/json; charset=utf-8');
require_once 'db_config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$family_name = $_SESSION['family_name'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'خادم الكنيسة';
$user_id = (int) $_SESSION['user_id'];

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);
$message = trim($data['message'] ?? ($_POST['message'] ?? ''));

if ($family_name === '') {
    echo json_encode(['success' => false, 'error' => 'no_family']);
    exit;
}

if ($message === '') {
    echo json_encode(['success' => false, 'error' => 'empty_message']);
    exit;
}

if (mb_strlen($message) > 1000) {
    $message = mb_substr($message, 0, 1000);
}

try {
    $conn = get_db();
    $conn->set_charset('utf8mb4');

    $conn->query("CREATE TABLE IF NOT EXISTS family_chat_messages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        family_name VARCHAR(100) NOT NULL,
        user_id INT(10) UNSIGNED NOT NULL,
        full_name VARCHAR(120) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_family_created (family_name, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $conn->prepare("INSERT INTO family_chat_messages (family_name, user_id, full_name, message) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('siss', $family_name, $user_id, $full_name, $message);

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();

        $stmt2 = $conn->prepare("SELECT created_at FROM family_chat_messages WHERE id = ? LIMIT 1");
        $stmt2->bind_param('i', $new_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $row2 = $res2->fetch_assoc();
        $stmt2->close();

        echo json_encode([
            'success' => true,
            'message_data' => [
                'id' => (int) $new_id,
                'user_id' => $user_id,
                'full_name' => $full_name,
                'message' => $message,
                'created_at' => $row2['created_at'] ?? date('Y-m-d H:i:s'),
            ],
        ]);
    } else {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'insert_failed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
