<?php
session_start();
header('Content-Type: application/json');

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'pause') {
    // Check if system_settings table exists
    $check_table = "SHOW TABLES LIKE 'system_settings'";
    $result = $conn->query($check_table);
    
    if ($result->num_rows === 0) {
        $create_table = "CREATE TABLE system_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $conn->query($create_table);
    }
    
    $sql = "INSERT INTO system_settings (setting_key, setting_value) 
            VALUES ('timer_paused', '1') 
            ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()";
    
    if ($conn->query($sql)) {
        $admin_id = $_SESSION['user_id'];
        $log_text = "Admin paused all student timers";
        $log_sql = "INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)";
        $stmt = $conn->prepare($log_sql);
        $stmt->bind_param("is", $admin_id, $log_text);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'All timers paused']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to pause timers']);
    }
    
} elseif ($action === 'resume') {
    $sql = "INSERT INTO system_settings (setting_key, setting_value) 
            VALUES ('timer_paused', '0') 
            ON DUPLICATE KEY UPDATE setting_value = '0', updated_at = NOW()";
    
    if ($conn->query($sql)) {
        $admin_id = $_SESSION['user_id'];
        $log_text = "Admin resumed all student timers";
        $log_sql = "INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)";
        $stmt = $conn->prepare($log_sql);
        $stmt->bind_param("is", $admin_id, $log_text);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'All timers resumed']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to resume timers']);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>