<?php
session_start();

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

header('Content-Type: application/json');

$search = $_POST['search'] ?? '';
$batch = $_POST['batch'] ?? '';

// Debug log
error_log("Received batch filter: " . $batch);
error_log("Received search filter: " . $search);

// Initialize params array
$params = [];
$types = '';

// Base SQL query that counts total lab sessions
$sql = "SELECT 
            u.id,
            u.name,
            u.admission_number,
            u.year,
            COUNT(DISTINCT la.login_time) as total_sessions
        FROM users u
        LEFT JOIN login_activity la ON u.id = la.user_id
        WHERE u.role = 'student'";

// Add batch filter
if ($batch !== '') {
    $sql .= " AND u.year = ?";
    $params[] = $batch;
    $types .= 's';
    error_log("Added batch filter to SQL: year = " . $batch);
}

// Add search filter
if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR u.admission_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

// Add group by and order by
$sql .= " GROUP BY u.id ORDER BY u.name ASC";

try {
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param($types, ...$params);
        $success = $stmt->execute();
        if ($success === false) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
        if ($result === false) {
            throw new Exception("Query failed: " . $conn->error);
        }
    }

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'id' => $row['id'],
            'name' => htmlspecialchars($row['name']),
            'admission_number' => htmlspecialchars($row['admission_number']),
            'year' => htmlspecialchars($row['year'] ?? 'N/A'),
            'total_sessions' => (int)$row['total_sessions']
        ];
    }

    // Send success response with debug info
    echo json_encode([
        'success' => true,
        'students' => $students,
        'debug' => [
            'batch' => $batch,
            'search' => $search,
            'total_records' => count($students),
            'query' => $sql
        ]
    ]);

} catch (Exception $e) {
    error_log("Error in ajax_filter_students.php: " . $e->getMessage());
    echo json_encode([
        'error' => 'Database error occurred',
        'debug_message' => $e->getMessage(),
        'sql' => $sql ?? 'No query executed'
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}