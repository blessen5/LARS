<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'lab_activity_system3');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';

if (empty($start_date) || empty($end_date) || !strtotime($start_date) || !strtotime($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid dates']);
    exit();
}

$stmt = $conn->prepare("DELETE FROM activity_logs WHERE DATE(created_at) BETWEEN ? AND ?");
$stmt->bind_param("ss", $start_date, $end_date);

if ($stmt->execute()) {
    $count = $stmt->affected_rows;
    echo json_encode(['success' => true, 'message' => "Deleted {$count} logs", 'count' => $count]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete logs']);
}

$stmt->close();
$conn->close();
?>