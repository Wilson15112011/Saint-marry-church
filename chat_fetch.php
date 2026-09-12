<?php
// ================================================
// chat_fetch.php – Poll family chat messages (AJAX, JSON)
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

$family_name = $_SESSION['family_name'] ?? '';
$since_id = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

if ($family_name === '') {
    echo json_encode(['success' => true, 'messages' => []]);
    exit;
}

try {
    $conn = get_db();
    $conn->set_charset('utf8mb4');


    $stmt = $conn->prepare("SELECT id, user_id, full_name, message, created_at FROM family_chat_messages WHERE family_name = ? AND id > ? ORDER BY id ASC LIMIT 100");
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
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
