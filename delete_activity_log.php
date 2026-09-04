<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$log_id = intval($_POST['log_id'] ?? 0);

if (!$log_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid log ID']);
    exit();
}

$stmt = $conn->prepare("DELETE FROM activity_logs WHERE id = ?");
$stmt->bind_param("i", $log_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Activity log deleted']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete log']);
}

$stmt->close();
$conn->close();
?>