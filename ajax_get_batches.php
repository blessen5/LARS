<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin or staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
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

// Fetch distinct batches (years) from students
$sql = "SELECT DISTINCT year FROM users WHERE role = 'student' AND year IS NOT NULL AND year != '' ORDER BY year DESC";
$result = $conn->query($sql);

$batches = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $batches[] = $row['year'];
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'batches' => $batches
]);
?>

