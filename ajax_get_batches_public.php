<?php
header('Content-Type: application/json');

// Public endpoint to fetch batches (no auth) - read-only
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