<?php
session_start();
header('Content-Type: application/json');

// Ensure staff is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// Validate input
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$year = isset($_POST['year']) ? trim($_POST['year']) : '';
$password = isset($_POST['password']) ? (string)$_POST['password'] : '';

if ($id <= 0 || $name === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    $conn->close();
    exit;
}

// Ensure the target user is a student
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    $stmt->close();
    $conn->close();
    exit;
}
$row = $res->fetch_assoc();
$stmt->close();
if (($row['role'] ?? '') !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Only students can be edited by staff']);
    $conn->close();
    exit;
}

// Build update query
if ($password !== '') {
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE users SET name = ?, year = ?, password = ? WHERE id = ?");
    $stmt->bind_param('sssi', $name, $year, $password_hash, $id);
} else {
    $stmt = $conn->prepare("UPDATE users SET name = ?, year = ? WHERE id = ?");
    $stmt->bind_param('ssi', $name, $year, $id);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}
$stmt->close();
$conn->close();
