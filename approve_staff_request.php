<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request id']);
    exit;
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS pending_staff_requests (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, username VARCHAR(191) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");

// Fetch the pending request
$stmt = $conn->prepare("SELECT id, name, username, password FROM pending_staff_requests WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$request = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    $conn->close();
    exit;
}

// Ensure username not already existing in users
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt->bind_param('s', $request['username']);
$stmt->execute();
$existsRes = $stmt->get_result();
$stmt->close();
if ($existsRes && $existsRes->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Username already exists']);
    $conn->close();
    exit;
}

// Create the staff user
$stmt = $conn->prepare("INSERT INTO users (name, admission_number, username, password, role, department, year, start_year, duration) VALUES (?, NULL, ?, ?, 'staff', NULL, NULL, NULL, NULL)");
$stmt->bind_param('sss', $request['name'], $request['username'], $request['password']);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to create user']);
    $conn->close();
    exit;
}

// Delete the pending request
$stmt = $conn->prepare("DELETE FROM pending_staff_requests WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

$conn->close();

echo json_encode(['success' => true]);
