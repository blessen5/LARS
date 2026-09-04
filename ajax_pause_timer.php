<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

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
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

if ($action === 'pause') {
    $pause_time = $_POST['pause_time'] ?? date('Y-m-d H:i:s');
    
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
    
    // Insert or update the timer_paused setting
    $sql = "INSERT INTO system_settings (setting_key, setting_value) 
            VALUES ('timer_paused', '1') 
            ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()";
    
    if ($conn->query($sql)) {
        // Log the action
        $log_text = $user_role === 'admin' ? "Admin paused all student timers" : "Staff paused all student timers";
        $log_sql = "INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)";
        $stmt = $conn->prepare($log_sql);
        $stmt->bind_param("is", $user_id, $log_text);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'All timers paused', 'pause_time' => $pause_time]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to pause timers']);
    }
    
} elseif ($action === 'resume') {
    $resume_time = $_POST['resume_time'] ?? date('Y-m-d H:i:s');
    
    // Update the timer_paused setting to 0 (resumed)
    $sql = "INSERT INTO system_settings (setting_key, setting_value) 
            VALUES ('timer_paused', '0') 
            ON DUPLICATE KEY UPDATE setting_value = '0', updated_at = NOW()";
    
    if ($conn->query($sql)) {
        // Log the action
        $log_text = $user_role === 'admin' ? "Admin resumed all student timers" : "Staff resumed all student timers";
        $log_sql = "INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)";
        $stmt = $conn->prepare($log_sql);
        $stmt->bind_param("is", $user_id, $log_text);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'All timers resumed', 'resume_time' => $resume_time]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to resume timers']);
    }
    
} elseif ($action === 'check') {
    // Check current timer status
    $check_table = "SHOW TABLES LIKE 'system_settings'";
    $result = $conn->query($check_table);
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => true, 'paused' => false]);
        $conn->close();
        exit();
    }
    
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'timer_paused'";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $is_paused = ($row['setting_value'] === '1');
        echo json_encode(['success' => true, 'paused' => $is_paused]);
    } else {
        echo json_encode(['success' => true, 'paused' => false]);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>