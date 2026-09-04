<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session with secure settings
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true
]);

// Set headers for JSON response
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

try {
    // Create DB connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Set charset to utf8mb4
    $conn->set_charset("utf8mb4");

    // Get the college name from POST data
    $college_name = isset($_POST['college_name']) ? trim($_POST['college_name']) : '';

    // Validate input
    if (empty($college_name)) {
        throw new Exception('College name cannot be empty');
    }

    // Ensure settings and system_settings tables exist
    $conn->query("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Insert or update college name in system_settings
    $stmt1 = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                             VALUES ('college_name', ?) 
                             ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
    if ($stmt1) {
        $stmt1->bind_param("ss", $college_name, $college_name);
        $stmt1->execute();
        $stmt1->close();
    }

    // Insert or update college name in settings table for PDF and reports compatibility
    $stmt2 = $conn->prepare("INSERT INTO settings (setting_key, setting_value) 
                             VALUES ('college_name', ?) 
                             ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
    if ($stmt2) {
        $stmt2->bind_param("ss", $college_name, $college_name);
        $stmt2->execute();
        $stmt2->close();
    }
    
    $conn->close();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'College name updated successfully',
        'college_name' => $college_name
    ]);
    
} catch (Exception $e) {
    // Log the error
    error_log("Error updating college name: " . $e->getMessage());
    
    // Close connection if it's still open
    if (isset($conn)) {
        $conn->close();
    }
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>