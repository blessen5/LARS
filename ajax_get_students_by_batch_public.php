<?php
header('Content-Type: application/json');

// Public endpoint to fetch students by batch (no auth) - read-only
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$batch = $_GET['batch'] ?? '';
if (empty($batch)) {
    echo json_encode(['success' => false, 'message' => 'Batch is required']);
    $conn->close();
    exit();
}

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