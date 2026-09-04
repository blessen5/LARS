<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

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

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user id']);
    $conn->close();
    exit;
}

$stmt = $conn->prepare("SELECT id, name, username, admission_number, role, year FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    $stmt->close();
    $conn->close();
    exit;
}
$user = $res->fetch_assoc();
$stmt->close();

if (($user['role'] ?? '') !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Only students can be edited by staff']);
    $conn->close();
    exit;
}

echo json_encode([
    'success' => true,
    'user' => [
        'id' => (int)$user['id'],
        'name' => (string)($user['name'] ?? ''),
        'username' => (string)($user['username'] ?? ''),
        'admission_number' => (string)($user['admission_number'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'year' => (string)($user['year'] ?? '')
    ]
]);

$conn->close();
