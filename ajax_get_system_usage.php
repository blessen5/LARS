<?php
/**
 * AJAX endpoint to fetch live system usage data
 * Used by admin dashboard to auto-refresh the Live System Usage table
 */

session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Fetch live system usage data
$live_system_usage = [];
$check_table = "SHOW TABLES LIKE 'system_usage'";
$result = $conn->query($check_table);
if ($result && $result->num_rows > 0) {
    $sql = "SELECT * FROM system_usage ORDER BY last_active DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $live_system_usage[] = $row;
        }
    }
}

// Also mark records as inactive if last_active is more than 2 minutes old (safety check)
// Heartbeat runs every 60 seconds, so 2 minutes gives buffer for network delays
$conn->query("UPDATE system_usage SET status = 'inactive' 
    WHERE status = 'active' 
    AND last_active < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
    
// Also mark records as active if they have recent activity (within 2 minutes) but status is inactive
// This handles cases where status wasn't updated properly
$conn->query("UPDATE system_usage SET status = 'active' 
    WHERE status = 'inactive' 
    AND last_active >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)");

$conn->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode(['success' => true, 'data' => $live_system_usage]);

