<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed', 'paused' => false]);
    exit();
}

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

$conn->close();
?>