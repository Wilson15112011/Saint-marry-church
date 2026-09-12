<?php
// ================================================
// db_config.php – Database Configuration for InfinityFree
// ================================================

define('DB_HOST',     'sql106.infinityfree.com');
define('DB_USER',     'if0_42129362');
define('DB_PASS',     'fmKbFjTxvXlv7B');
define('DB_NAME',     'if0_42129362_church_login_db');

/**
 * Get database connection
 */
function get_db(): mysqli {
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            error_log("Database Connection Error: " . $conn->connect_error);
            die("❌ خطأ في الاتصال بقاعدة البيانات. يرجى التحقق من الإعدادات أو المحاولة لاحقاً.");
        }

        // Support Arabic characters
        $conn->set_charset("utf8mb4");
    }

    return $conn;
}