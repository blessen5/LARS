<?php
// Centralized Database Connection for LARS
// Usage: require_once __DIR__ . '/db.php';

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'LARS';
$db_port = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306;

// Create MySQLi connection
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($conn->connect_error) {
    // If output buffer is clean or requested via AJAX, send structured error
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }
    die("Database connection failed. Please ensure MySQL is running: " . htmlspecialchars($conn->connect_error));
}

// Set standard charset
$conn->set_charset("utf8mb4");

/**
 * Helper function to safely fetch a system setting
 */
if (!function_exists('get_system_setting')) {
    function get_system_setting($conn, $key, $default = '') {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $stmt->close();
                return $row['setting_value'];
            }
            $stmt->close();
        }
        
        // Fallback to system_settings table if not found
        $stmt2 = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        if ($stmt2) {
            $stmt2->bind_param("s", $key);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            if ($result2 && $row2 = $result2->fetch_assoc()) {
                $stmt2->close();
                return $row2['setting_value'];
            }
            $stmt2->close();
        }
        
        return $default;
    }
}
?>
