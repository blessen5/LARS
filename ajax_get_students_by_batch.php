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

// Get batch parameter
$batch = $_GET['batch'] ?? '';

if (empty($batch)) {
    echo json_encode(['success' => false, 'message' => 'Batch is required']);
    $conn->close();
    exit();
}

// Fetch students by batch
$sql = "SELECT id, name, admission_number, year FROM users WHERE role = 'student' AND year = ? ORDER BY name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $batch);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'students' => $students
]);
?>

