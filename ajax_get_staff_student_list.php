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

// Get parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$batch = isset($_GET['batch']) ? $_GET['batch'] : '';
$offset = ($page - 1) * $limit;

// Base query
$query = "SELECT SQL_CALC_FOUND_ROWS 
            u.id, u.name, u.admission_number, u.year,
            COALESCE(SUM(TIMESTAMPDIFF(SECOND, la1.login_time, 
                COALESCE(la2.login_time, NOW()))), 0) as total_seconds";

// Add conditions
$conditions = ["u.role = 'student'"];
$params = [];
$types = "";

if ($search) {
    $conditions[] = "(u.name LIKE ? OR u.admission_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if ($batch) {
    $conditions[] = "u.year = ?";
    $params[] = $batch;
    $types .= "s";
}

$query .= " FROM users u
            LEFT JOIN login_activity la1 ON u.id = la1.user_id
            LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
                AND la2.id = (
                    SELECT id 
                    FROM login_activity 
                    WHERE user_id = la1.user_id AND id > la1.id 
                    ORDER BY id ASC LIMIT 1
                )
            WHERE " . implode(" AND ", $conditions) . "
            GROUP BY u.id
            ORDER BY u.name
            LIMIT ? OFFSET ?";

// Add pagination parameters
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Prepare and execute
$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get total count
$totalResult = $conn->query("SELECT FOUND_ROWS()");
$totalRow = $totalResult->fetch_row();
$total = $totalRow[0];

// Format results
$students = [];
while ($row = $result->fetch_assoc()) {
    $total = $row['total_seconds'] ?? 0;
    $h = floor($total / 3600);
    $m = floor(($total % 3600) / 60);
    $s = $total % 60;
    $row['formatted_time'] = sprintf("%02dh %02dm %02ds", $h, $m, $s);
    $students[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $students,
    'total' => $total,
    'pages' => ceil($total / $limit)
]);

$stmt->close();
$conn->close();
?>