<?php
session_start();
header('Content-Type: application/json');

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';

if (empty($start) || empty($end)) {
    echo json_encode(['success' => false, 'message' => 'Start and end dates are required']);
    exit();
}

// Validate dates
$startDate = DateTime::createFromFormat('Y-m-d', $start);
$endDate = DateTime::createFromFormat('Y-m-d', $end);

if (!$startDate || !$endDate) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit();
}

if ($startDate > $endDate) {
    echo json_encode(['success' => false, 'message' => 'Start date must be before end date']);
    exit();
}

try {
    // 1. Top Users by Time
    $sql = "SELECT u.name, u.admission_number, 
            SUM(TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(la2.login_time, NOW()))) as total_seconds
            FROM users u
            JOIN login_activity la1 ON u.id = la1.user_id
            LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
                AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
            WHERE u.role = 'student' 
            AND DATE(la1.login_time) BETWEEN ? AND ?
            GROUP BY u.id, u.name, u.admission_number
            ORDER BY total_seconds DESC
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $top_users = [];
    while ($row = $result->fetch_assoc()) {
        $top_users[] = $row;
    }
    $stmt->close();

    // 2. Total Students
    $sql = "SELECT COUNT(DISTINCT id) as total FROM users WHERE role = 'student'";
    $result = $conn->query($sql);
    $total_students = $result->fetch_assoc()['total'];

    // 3. Other Activities Count
    $sql = "SELECT COUNT(DISTINCT user_id) as count 
            FROM activity_logs 
            WHERE DATE(created_at) BETWEEN ? AND ?
            AND user_id IN (SELECT id FROM users WHERE role = 'student')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $other_activities_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // 4. Activity Engagement
    $sql = "SELECT u.name, u.admission_number, COUNT(al.id) as activity_count
            FROM users u
            LEFT JOIN activity_logs al ON u.id = al.user_id AND DATE(al.created_at) BETWEEN ? AND ?
            WHERE u.role = 'student'
            GROUP BY u.id, u.name, u.admission_number
            HAVING activity_count > 0
            ORDER BY activity_count DESC
            LIMIT 15";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $activity_engagement = [];
    while ($row = $result->fetch_assoc()) {
        $activity_engagement[] = $row;
    }
    $stmt->close();

    // 5. Monthly Engagement by Role
    $sql = "SELECT DATE_FORMAT(la.login_time, '%Y-%m') as month, u.role, COUNT(DISTINCT la.user_id) as user_count
            FROM login_activity la
            JOIN users u ON la.user_id = u.id
            WHERE DATE(la.login_time) BETWEEN ? AND ?
            GROUP BY month, u.role
            ORDER BY month ASC, u.role ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $monthly_data = [];
    $roles = [];
    while ($row = $result->fetch_assoc()) {
        $month = $row['month'];
        $role = $row['role'];
        if (!isset($monthly_data[$month])) {
            $monthly_data[$month] = [];
        }
        $monthly_data[$month][$role] = $row['user_count'];
        if (!in_array($role, $roles)) {
            $roles[] = $role;
        }
    }
    $stmt->close();

    // Format monthly data for chart
    $months = array_keys($monthly_data);
    $series = [];
    foreach ($roles as $role) {
        $data = [];
        foreach ($months as $month) {
            $data[] = $monthly_data[$month][$role] ?? 0;
        }
        $series[] = ['role' => $role, 'data' => $data];
    }

    // 6. Attendance Summary
    $sql = "SELECT DATE(a.date) as date,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
            FROM attendance a
            WHERE a.date BETWEEN ? AND ?
            GROUP BY DATE(a.date)
            ORDER BY date ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $attendance_summary = [];
    while ($row = $result->fetch_assoc()) {
        $attendance_summary[] = $row;
    }
    $stmt->close();

    $response = [
        'success' => true,
        'top_users' => $top_users,
        'total_students' => $total_students,
        'other_activities_count' => $other_activities_count,
        'activity_engagement' => $activity_engagement,
        'monthly_engagement' => [
            'months' => $months,
            'roles' => $roles,
            'series' => $series
        ],
        'attendance_summary' => $attendance_summary
    ];

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>