<?php
// AJAX endpoint to fetch filtered login/logout times
header('Content-Type: application/json; charset=utf-8');
session_start();

// Basic auth check: allow admin or staff
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

// Read POST params
$date = isset($_POST['date']) && $_POST['date'] !== '' ? $_POST['date'] : null;
$subject_id = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? $_POST['subject_id'] : null;
$batch = isset($_POST['batch']) && $_POST['batch'] !== '' ? $_POST['batch'] : null;
$search = isset($_POST['search']) && $_POST['search'] !== '' ? $_POST['search'] : null;

// Build SQL with optional filters (aligned with dashboard logic: session_end / next login and is_active)
$sql = "SELECT 
            u.id AS user_id,
            u.name, 
            u.admission_number, 
            u.year,
            la1.login_time,
            COALESCE(us.session_end, la2.login_time, NOW()) AS logout_time,
            TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(us.session_end, la2.login_time, NOW())) AS duration,
            CASE WHEN us.session_end IS NULL AND la2.login_time IS NULL THEN 1 ELSE 0 END AS is_active,
            la1.ip_address as mac_address,
            us.subject_id,
            s.subject_name,
            DATE(la1.login_time) as login_date
        FROM (
                SELECT la_inner.*
                FROM login_activity la_inner
                JOIN (
                        SELECT user_id, DATE(login_time) AS login_date, MAX(login_time) AS max_login
                        FROM login_activity
                        GROUP BY user_id, DATE(login_time)
                ) lm 
                    ON lm.user_id = la_inner.user_id 
                    AND lm.login_date = DATE(la_inner.login_time)
                    AND lm.max_login = la_inner.login_time
        ) la1
        JOIN users u ON la1.user_id = u.id
        LEFT JOIN user_sessions us ON us.user_id = la1.user_id 
            AND DATE(us.session_start) = DATE(la1.login_time)
            AND ABS(TIMESTAMPDIFF(SECOND, us.session_start, la1.login_time)) <= 300
        LEFT JOIN subjects s ON us.subject_id = s.id
        LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id
            AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
        WHERE u.role = 'student'";

$params = [];
$types = '';

if ($date) {
    $sql .= " AND DATE(la1.login_time) = ?";
    $types .= 's';
    $params[] = $date;
}

if ($subject_id) {
    // Filter by subject_id coming from the joined user_sessions row
    $sql .= " AND us.subject_id = ?";
    $types .= 'i';
    $params[] = (int)$subject_id;
}

if ($batch) {
    $sql .= " AND u.year = ?";
    $types .= 's';
    $params[] = $batch;
}

if ($search) {
    $sql .= " AND (u.name LIKE ? OR u.admission_number LIKE ? OR u.username LIKE ? )";
    $like = "%" . $search . "%";
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY la1.login_time DESC LIMIT 500";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'DB prepare error']);
    exit;
}

if (!empty($params)) {
    // bind params dynamically
    $bind_names[] = $types;
    for ($i=0; $i<count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind_names);
}

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
