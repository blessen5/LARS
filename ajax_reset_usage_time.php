<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin
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

// Get user ID and role
$user_id = intval($_POST['user_id'] ?? 0);
$role = $_POST['role'] ?? '';

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    $conn->close();
    exit();
}

if (!in_array($role, ['staff', 'student'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    $conn->close();
    exit();
}

// Verify user exists and role matches
$sql = "SELECT id, role FROM users WHERE id = ? AND role = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('is', $user_id, $role);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found or role mismatch']);
    $stmt->close();
    $conn->close();
    exit();
}

$stmt->close();

// Delete all login_activity records for this user to reset usage time
$sql = "DELETE FROM login_activity WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);

if ($stmt->execute()) {
    $deleted = $stmt->affected_rows;
    echo json_encode([
        'success' => true,
        'message' => "Usage time reset successfully. Deleted $deleted login record(s)."
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to reset usage time: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>

