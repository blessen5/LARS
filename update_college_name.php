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
$db_name = 'lars';

try {
    // Create DB connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Set charset to utf8mb4
    $conn->set_charset("utf8mb4");

    // Get the college name from POST data
    $college_name = isset($_POST['college_name']) ? trim($conn->real_escape_string($_POST['college_name'])) : '';

    // Validate input
    if (empty($college_name)) {
        throw new Exception('College name cannot be empty');
    }

    // Check if system_settings table exists, create if it doesn't
    $check_table = $conn->query("SHOW TABLES LIKE 'system_settings'");
    
    if (!$check_table) {
        throw new Exception("Error checking for table: " . $conn->error);
    }
    
    if ($check_table->num_rows == 0) {
        // Create system_settings table if it doesn't exist
        $create_table = "CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if (!$conn->query($create_table)) {
            throw new Exception("Failed to create system_settings table: " . $conn->error);
        }
    }
    
    // Insert or update college name using prepared statement
    $query = "INSERT INTO system_settings (setting_key, setting_value) 
              VALUES ('college_name', ?) 
              ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $college_name, $college_name);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $stmt->close();
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