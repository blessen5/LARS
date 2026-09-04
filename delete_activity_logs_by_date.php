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

$date = $_POST['date'] ?? '';

if (empty($date) || !strtotime($date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date']);
    exit();
}

$stmt = $conn->prepare("DELETE FROM activity_logs WHERE DATE(created_at) = ?");
$stmt->bind_param("s", $date);

if ($stmt->execute()) {
    $count = $stmt->affected_rows;
    echo json_encode(['success' => true, 'message' => "Deleted {$count} logs", 'count' => $count]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete logs']);
}

$stmt->close();
$conn->close();
?>