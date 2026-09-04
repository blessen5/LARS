<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
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

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

// Get user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit();
}

// Get last login
$stmt = $conn->prepare("
    SELECT login_time 
    FROM login_activity 
    WHERE user_id = ? 
    ORDER BY login_time DESC 
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$last_login = $result->fetch_assoc();
$stmt->close();

// Get total usage time
$stmt = $conn->prepare("
    SELECT SUM(TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(la2.login_time, NOW()))) as total_seconds
    FROM login_activity la1
    LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
        AND la2.id = (
            SELECT id 
            FROM login_activity 
            WHERE user_id = la1.user_id AND id > la1.id 
            ORDER BY id ASC LIMIT 1
        )
    WHERE la1.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$usage = $result->fetch_assoc();
$stmt->close();

// Format total time
$total = $usage['total_seconds'] ?? 0;
$hours = floor($total / 3600);
$minutes = floor(($total % 3600) / 60);
$seconds = $total % 60;
$formatted_time = sprintf("%02dh %02dm %02ds", $hours, $minutes, $seconds);

// Get recent activity (last 5 entries)
$stmt = $conn->prepare("
    SELECT * FROM activity_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'success' => true,
    'data' => [
        'user' => $user,
        'last_login' => $last_login['login_time'] ?? null,
        'total_time' => $formatted_time,
        'recent_activity' => $recent_activity
    ]
]);

$conn->close();
?>