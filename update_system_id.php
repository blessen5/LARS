<?php
session_start();
header('Content-Type: application/json');

// Require any logged-in user (admin/staff/student)
if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int)($_SESSION['user_id']);
$role = $_SESSION['role'];
$system_id = isset($_POST['system_id']) ? trim($_POST['system_id']) : '';
$user_agent = isset($_POST['user_agent']) ? trim($_POST['user_agent']) : '';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

if ($system_id === '') {
    echo json_encode(['success' => false, 'message' => 'Missing system_id']);
    exit;
}

// DB config
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// Ensure system_usage table exists
$create = "CREATE TABLE IF NOT EXISTS system_usage (
    system_id VARCHAR(128) NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(191) NULL,
    role VARCHAR(32) NOT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    last_active DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$conn->query($create);

// Fetch username for display
$username = null;
$stmt = $conn->prepare("SELECT username, name FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row = $res->fetch_assoc()) {
    // prefer username; if empty (students), fall back to name
    $username = $row['username'];
    if (!$username || $username === '') { $username = $row['name']; }
}
$stmt->close();

// Upsert into system_usage
$stmt = $conn->prepare("INSERT INTO system_usage (system_id, user_id, username, role, ip_address, user_agent, last_active)
VALUES (?, ?, ?, ?, ?, ?, NOW())
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), username = VALUES(username), role = VALUES(role), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), last_active = NOW()");
$stmt->bind_param('sissss', $system_id, $user_id, $username, $role, $ip_address, $user_agent);
$ok = $stmt->execute();
$stmt->close();

$conn->close();

echo json_encode(['success' => (bool)$ok]);
