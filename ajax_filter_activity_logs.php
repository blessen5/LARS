<?php
// AJAX endpoint to fetch filtered activity logs
header('Content-Type: application/json; charset=utf-8');
session_start();

// Allow admin or staff
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'staff')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection error']);
    exit;
}

// Params
$date = isset($_POST['date']) && $_POST['date'] !== '' ? $_POST['date'] : null;
$search = isset($_POST['search']) && $_POST['search'] !== '' ? $_POST['search'] : null;
$batch = isset($_POST['batch']) && $_POST['batch'] !== '' ? $_POST['batch'] : null;
$role = isset($_POST['role']) && $_POST['role'] !== '' ? $_POST['role'] : 'student'; // default student
$limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 500;

// Build SQL - include subject info to distinguish other activities
$sql = "SELECT al.id, al.created_at, al.log_text, u.name, u.admission_number, u.username, u.role, al.subject_id, s.subject_name
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        LEFT JOIN subjects s ON al.subject_id = s.id
        WHERE u.role = ?";
$params = [];
$types = '';
$types .= 's';
$params[] = $role;

if ($date) {
    $sql .= " AND DATE(al.created_at) = ?";
    $types .= 's';
    $params[] = $date;
}

if ($batch && $role === 'student') {
    $sql .= " AND u.year = ?";
    $types .= 's';
    $params[] = $batch;
}

if ($search) {
    $sql .= " AND (u.name LIKE ? OR u.admission_number LIKE ? OR al.log_text LIKE ? OR u.username LIKE ?)";
    $like = "%" . $search . "%";
    $types .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY al.created_at DESC LIMIT ?";
$types .= 'i';
$params[] = $limit;

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'DB prepare error']);
    exit;
}

// bind params
$bind_names[] = $types;
for ($i=0; $i<count($params); $i++) {
    $bind_name = 'bind' . $i;
    $$bind_name = $params[$i];
    $bind_names[] = &$$bind_name;
}
call_user_func_array(array($stmt, 'bind_param'), $bind_names);

$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

echo json_encode(['success' => true, 'rows' => $rows]);

$stmt->close();
$conn->close();
exit;
