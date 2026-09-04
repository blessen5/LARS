<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin OR staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
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

$user_id = intval($_GET['user_id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

// Fetch user basic information
$sql = "SELECT u.id, u.name, u.username, u.admission_number, u.role, u.year,
        (SELECT MAX(login_time) FROM login_activity WHERE user_id = u.id) as last_login,
        COUNT(DISTINCT la.id) as total_sessions,
        SUM(TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(la2.login_time, NOW()))) as total_seconds
        FROM users u
        LEFT JOIN login_activity la ON u.id = la.user_id
        LEFT JOIN login_activity la1 ON u.id = la1.user_id
        LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
            AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
        WHERE u.id = ?
        GROUP BY u.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    $stmt->close();
    $conn->close();
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

// Format total hours
$total_seconds = $user['total_seconds'] ?? 0;
$hours = floor($total_seconds / 3600);
$minutes = floor(($total_seconds % 3600) / 60);
$seconds = $total_seconds % 60;
$user['total_hours'] = sprintf("%dh %02dm %02ds", $hours, $minutes, $seconds);

// Format last login
if ($user['last_login']) {
    $user['last_login'] = date('d-m-Y H:i:s', strtotime($user['last_login']));
}

// Initialize response data
$attendance_summary = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'total' => 0,
    'percentage' => null
];
$recent_sessions = [];
$issues = [];

// If student, fetch attendance summary
if ($user['role'] === 'student') {
    $sql = "SELECT 
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            COUNT(*) as total
            FROM attendance
            WHERE user_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $attendance_summary = [
            'present' => (int)$row['present'],
            'late' => (int)$row['late'],
            'absent' => (int)$row['absent'],
            'total' => (int)$row['total']
        ];
        
        // Calculate percentage (present + late) / total
        if ($attendance_summary['total'] > 0) {
            $attendance_summary['percentage'] = round(
                (($attendance_summary['present'] + $attendance_summary['late']) / $attendance_summary['total']) * 100,
                2
            );
        }
    }
    $stmt->close();
}

// Fetch recent sessions (last 10)
if ($user['role'] === 'student') {
    $sql = "SELECT 
            DATE(la1.login_time) as date,
            TIME(la1.login_time) as login_time,
            TIME(la2.login_time) as logout_time,
            TIMESTAMPDIFF(SECOND, la1.login_time, la2.login_time) as duration_seconds,
            (SELECT s.subject_name 
             FROM user_sessions us 
             JOIN subjects s ON us.subject_id = s.id 
             WHERE us.user_id = la1.user_id 
             AND DATE(us.session_start) = DATE(la1.login_time)
             ORDER BY us.session_start DESC 
             LIMIT 1) as subject_name
            FROM login_activity la1
            LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
                AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
            WHERE la1.user_id = ?
            GROUP BY la1.id
            ORDER BY la1.login_time DESC
            LIMIT 10";
} else {
    $sql = "SELECT 
            DATE(la1.login_time) as date,
            TIME(la1.login_time) as login_time,
            TIME(la2.login_time) as logout_time,
            TIMESTAMPDIFF(SECOND, la1.login_time, la2.login_time) as duration_seconds
            FROM login_activity la1
            LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
                AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
            WHERE la1.user_id = ?
            GROUP BY la1.id
            ORDER BY la1.login_time DESC
            LIMIT 10";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Format duration
    $duration = 'N/A';
    if ($row['duration_seconds']) {
        $h = floor($row['duration_seconds'] / 3600);
        $m = floor(($row['duration_seconds'] % 3600) / 60);
        $s = $row['duration_seconds'] % 60;
        $duration = sprintf("%dh %02dm %02ds", $h, $m, $s);
    }
    
    $session = [
        'date' => date('d-m-Y', strtotime($row['date'])),
        'login_time' => $row['login_time'],
        'logout_time' => $row['logout_time'],
        'duration' => $duration
    ];
    
    if ($user['role'] === 'student') {
        $session['subject_name'] = $row['subject_name'];
    }
    
    $recent_sessions[] = $session;
}
$stmt->close();

// If student, fetch reported issues
if ($user['role'] === 'student') {
    $sql = "SELECT system_number, description, status, created_at
            FROM issues
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $issues[] = [
            'system_number' => $row['system_number'],
            'description' => $row['description'],
            'status' => $row['status'],
            'created_at' => date('d-m-Y H:i', strtotime($row['created_at']))
        ];
    }
    $stmt->close();
}

$conn->close();

// Return response
echo json_encode([
    'success' => true,
    'user' => $user,
    'attendance' => $attendance_summary,
    'sessions' => $recent_sessions,
    'issues' => $issues
]);
?>