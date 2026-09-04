<?php
session_start();

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Get staff information
$staff_name = "Test Staff";
$stmt = $conn->prepare("SELECT name, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user_data = $result->fetch_assoc();
    $staff_name = $user_data['name'] ?? $user_data['username'];
}
$stmt->close();

// Check current timer status
$timer_paused = false;
$check_table = "SHOW TABLES LIKE 'system_settings'";
$result = $conn->query($check_table);
if ($result->num_rows > 0) {
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'timer_paused'";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $timer_paused = ($row['setting_value'] === '1');
    }
}

// Calculate staff total usage
$session_start = time();
$sql = "SELECT login_time FROM login_activity WHERE user_id = ? ORDER BY login_time DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $session_start = strtotime($row['login_time']);
}
$stmt->close();

$elapsed_seconds = time() - $session_start;
if ($elapsed_seconds < 0) $elapsed_seconds = 0;
$hours = floor($elapsed_seconds / 3600);
$minutes = floor(($elapsed_seconds % 3600) / 60);
$seconds = $elapsed_seconds % 60;
$usage_display = sprintf("%dh %02dm %02ds", $hours, $minutes, $seconds);

// Notifications: read now, remove on next login (DB-persistent)
// 1) Ensure notifications.is_read exists
$colCheck = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
}
// 2) Remove previously read notifications for this user (from prior sessions)
$stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}
// 3) Fetch only unread notifications to show now
$notifications = [];
$stmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $idsToMark = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $notifications[] = $row;
        $idsToMark[] = (int)$row['id'];
    }
    $stmt->close();
    // 4) Mark shown notifications as read so they are removed on the next login
    if (!empty($idsToMark)) {
        $placeholders = implode(',', array_fill(0, count($idsToMark), '?'));
        $types = str_repeat('i', count($idsToMark));
        $sql = "UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$idsToMark);
            $stmt->execute();
            $stmt->close();
        }

        
 
    }
}

// Fetch currently active students - FIXED TO SHOW ONLY ONE ENTRY PER STUDENT
$active_students = [];
$sql = "SELECT u.id, u.name, u.admission_number, 
        (SELECT s.subject_name 
         FROM user_sessions us 
         JOIN subjects s ON us.subject_id = s.id 
         WHERE us.user_id = u.id 
         ORDER BY us.session_start DESC 
         LIMIT 1) as subject_name,
        MAX(la.login_time) as login_time 
        FROM login_activity la 
        JOIN users u ON la.user_id = u.id 
        WHERE u.role = 'student' 
        AND la.login_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) 
        GROUP BY u.id, u.name, u.admission_number
        ORDER BY login_time DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $active_students[] = $row;
    }
}

// Fetch students for attendance (today)
$attendance_students = [];
$today = date('Y-m-d');
$sql = "SELECT u.id, u.name, u.admission_number, s.subject_name, s.id as subject_id,
        CASE 
            WHEN la.login_time <= CONCAT(?, ' 09:00:00') THEN 'On Time'
            ELSE 'Late'
        END as status,
        a.status as marked_status
        FROM users u
        LEFT JOIN user_sessions us ON u.id = us.user_id AND DATE(us.session_start) = ?
        LEFT JOIN subjects s ON us.subject_id = s.id
        LEFT JOIN login_activity la ON u.id = la.user_id AND DATE(la.login_time) = ?
        LEFT JOIN attendance a ON u.id = a.user_id AND a.date = ? AND a.subject_id = s.id
        WHERE u.role = 'student' AND la.login_time IS NOT NULL
        GROUP BY u.id, s.id
        ORDER BY u.name";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssss", $today, $today, $today, $today);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $attendance_students[] = $row;
}
$stmt->close();

// Fetch student activity logs (default: today's logs) - including other activities where subject_id is NULL
$student_activity_logs = [];
$sql = "SELECT al.id, al.created_at, u.name, u.admission_number, al.log_text, al.subject_id, s.subject_name
        FROM activity_logs al 
        JOIN users u ON al.user_id = u.id 
        LEFT JOIN subjects s ON al.subject_id = s.id
        WHERE u.role = 'student' AND DATE(al.created_at) = CURDATE()
        ORDER BY al.created_at DESC LIMIT 200";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $student_activity_logs[] = $row;
    }
}

// Fetch login/logout times (default: today's logins) - aligned with admin logic
$login_times = [];
$sql = "SELECT 
            u.id AS user_id,
            u.name, 
            u.admission_number, 
            u.year,
            la1.login_time,
            COALESCE(us.session_end, la2.login_time, NOW()) AS logout_time,
            TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(us.session_end, la2.login_time, NOW())) AS duration,
            CASE WHEN us.session_end IS NULL AND la2.login_time IS NULL THEN 1 ELSE 0 END AS is_active,
            la1.ip_address AS mac_address,
            us.subject_id,
            s.subject_name,
            DATE(la1.login_time) AS login_date
        FROM (
                SELECT la_inner.*
                FROM login_activity la_inner
                JOIN (
                        SELECT user_id, DATE(login_time) AS login_date, MAX(login_time) AS max_login
                        FROM login_activity
                        WHERE DATE(login_time) = CURDATE()
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
            AND la2.id = (
                SELECT id FROM login_activity 
                WHERE user_id = la1.user_id AND id > la1.id 
                ORDER BY id ASC LIMIT 1
            )
        WHERE u.role = 'student' 
        ORDER BY la1.login_time DESC
        LIMIT 200";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $login_times[] = $row;
    }
}

// Fetch all students
$all_students = [];
$sql = "SELECT u.id, u.name, u.admission_number, u.year,
        SUM(TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(la2.login_time, NOW()))) as total_seconds
        FROM users u
        LEFT JOIN login_activity la1 ON u.id = la1.user_id
        LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
            AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
        WHERE u.role = 'student'
        GROUP BY u.id
        ORDER BY u.name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_students[] = $row;
    }
}

// Fetch all subjects
$all_subjects = [];
$sql = "SELECT * FROM subjects ORDER BY subject_name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_subjects[] = $row;
    }
}

// Fetch system usage report
$system_usage = [];
$sql = "SELECT ip_address as mac_address, COUNT(*) as login_count 
        FROM login_activity 
        WHERE ip_address IS NOT NULL 
        GROUP BY ip_address 
        ORDER BY login_count DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $system_usage[] = $row;
    }
}

// Handle logout
if (isset($_POST['logout'])) {
    // Clear all session variables
    $_SESSION = array();
    
    // Destroy the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }
    
    // Destroy the session
    session_destroy();
    
    header("Location: login.php");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Lab Activity Reporting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* All existing styles from your original file */
        * {
            font-family: 'Poppins', sans-serif;
        }
   /* Light Mode Styles */
        :root {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --bg-card: #0f3460;
            --text-primary: #ffffff;
            --text-secondary: #e0e0e0;
            --border-color: #2d3748;
            --border-strong: #475569;
        }

        html[data-theme='light'] {
            --bg-primary: #ffffff;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #212529;
            --text-secondary: #495057;
            --border-color: #dee2e6;
            --border-strong: #adb5bd;
        }

        html[data-theme='light'] body {
            background-color: #ffffff !important;
            color: #000000 !important;
        }

        html[data-theme='light'] .header {
            background-color: #ffffff !important;
            color: #000000 !important;
            border-bottom: 1px solid #dee2e6 !important;
        }
        html[data-theme='light'] .header .header-title,
        html[data-theme='light'] .header .user-info { color: #000000 !important; }

        html[data-theme='light'] .card {
            background-color: #ffffff !important;
            border-color: #dee2e6 !important;
            color: #000000 !important;
        }

        html[data-theme='light'] .card-body {
            color: var(--text-primary);
        }

        html[data-theme='light'] .card-title {
            color: var(--text-primary);
        }

        html[data-theme='light'] .table-dark {
            background-color: #ffffff !important;
            color: #212529 !important;
        }

        html[data-theme='light'] .table-dark thead {
            background-color: #0d6efd !important;
            color: #ffffff !important;
        }

        html[data-theme='light'] .table-dark thead th {
            color: #ffffff !important;
            font-weight: 600 !important;
            border-color: #0d6efd !important;
        }

        html[data-theme='light'] .table-dark tbody tr {
            background-color: #ffffff !important;
            border-color: #dee2e6 !important;
        }

        html[data-theme='light'] .table-dark tbody tr:hover {
            background-color: #6a7380 !important; /* hover gray */
            color: #ffffff !important;
        }

        html[data-theme='light'] .table-dark tbody td {
            color: #212529 !important;
            font-weight: 500 !important;
            border-color: #dee2e6 !important;
        }

        html[data-theme='light'] .accordion-item {
            background-color: #ffffff !important;
            border-color: #dee2e6 !important;
        }

        html[data-theme='light'] .accordion-button {
            background-color: #ffffff !important;
            color: #212529 !important;
            font-weight: 600 !important;
        }

        html[data-theme='light'] .accordion-button:not(.collapsed) {
            background-color: #0d6efd !important;
            color: #ffffff !important;
        }

        html[data-theme='light'] .accordion-body {
            background-color: #ffffff !important;
            color: #212529 !important;
        }

        html[data-theme='light'] .accordion-body p,
        html[data-theme='light'] .accordion-body h5,
        html[data-theme='light'] .accordion-body label,
        html[data-theme='light'] .accordion-body strong {
            color: #212529 !important;
        }

        html[data-theme='light'] .form-control,
        html[data-theme='light'] .form-select {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #ced4da !important;
        }

        html[data-theme='light'] .form-control::placeholder {
            color: #6c757d !important;
        }

        html[data-theme='light'] .form-control:focus,
        html[data-theme='light'] .form-select:focus {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #86b7fe !important;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
        }

        html[data-theme='light'] .bg-dark {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }

        html[data-theme='light'] .bg-secondary {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }

        html[data-theme='light'] .bg-dark p,
        html[data-theme='light'] .bg-dark h1,
        html[data-theme='light'] .bg-dark h2,
        html[data-theme='light'] .bg-dark h3,
        html[data-theme='light'] .bg-dark h4,
        html[data-theme='light'] .bg-dark h5,
        html[data-theme='light'] .bg-dark h6,
        html[data-theme='light'] .bg-dark strong,
        html[data-theme='light'] .bg-dark span,
        html[data-theme='light'] .bg-dark small {
            color: #212529 !important;
        }

        html[data-theme='light'] .bg-secondary p,
        html[data-theme='light'] .bg-secondary h1,
        html[data-theme='light'] .bg-secondary h2,
        html[data-theme='light'] .bg-secondary h3,
        html[data-theme='light'] .bg-secondary h4,
        html[data-theme='light'] .bg-secondary h5,
        html[data-theme='light'] .bg-secondary h6,
        html[data-theme='light'] .bg-secondary strong,
        html[data-theme='light'] .bg-secondary span,
        html[data-theme='light'] .bg-secondary small {
            color: #212529 !important;
        }

        html[data-theme='light'] .text-white {
            color: var(--text-primary) !important;
        }

        html[data-theme='light'] .text-light {
            color: var(--text-secondary) !important;
        }

        html[data-theme='light'] .border-secondary {
            border-color: var(--border-color) !important;
        }

        html[data-theme='light'] .subject-item {
            background-color: #e9ecef !important;
            color: var(--text-primary) !important;
        }

        /* Improved contrast for better visibility */
        html[data-theme='light'] .modal-content {
            background-color: #ffffff !important;
            color: #212529 !important;
        }

        html[data-theme='light'] .modal-header,
        html[data-theme='light'] .modal-footer {
            border-color: #dee2e6 !important;
        }

        html[data-theme='light'] .modal-body {
            color: #212529 !important;
        }

        html[data-theme='light'] .modal-body .card {
            background-color: #ffffff !important;
            border: 1px solid #dee2e6 !important;
        }

        html[data-theme='light'] .modal-body .card-body {
            color: #212529 !important;
        }

        html[data-theme='light'] .modal-body .card-title {
            color: #212529 !important;
        }

        html[data-theme='light'] .modal-body p,
        html[data-theme='light'] .modal-body strong {
            color: #212529 !important;
        }

        html[data-theme='light'] .modal-body .table-dark {
            background-color: #ffffff !important;
        }

        html[data-theme='light'] .modal-body .table-dark thead {
            background-color: #0d6efd !important;
        }

        html[data-theme='light'] .modal-body .table-dark thead th {
            color: #ffffff !important;
            font-weight: 600 !important;
        }

        html[data-theme='light'] .modal-body .table-dark tbody td {
            color: #212529 !important;
            font-weight: 500 !important;
            background-color: #ffffff !important;
            border: 1px solid #dee2e6 !important;
        }

        html[data-theme='light'] .modal-body .table-dark tbody tr:hover td {
            background-color: #6a7380 !important; /* hover gray */
            color: #ffffff !important;
        }

        /* Better visibility for form labels */
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: inherit;
        }

        html[data-theme='light'] .form-label {
            color: var(--text-primary);
        }

        /* Better table contrast */
        html[data-theme='light'] .table-dark th {
            font-weight: 600;
            color: #ffffff !important;
            background-color: #0d6efd !important;
        }

        html[data-theme='light'] .table-dark td {
            color: #212529 !important;
            font-weight: 500 !important;
            background-color: #ffffff !important;
        }

        html[data-theme='light'] .table-dark tbody tr:hover {
            background-color: #6a7380 !important; /* hover gray */
            color: #ffffff !important;
        }

        html[data-theme='light'] .table-dark tbody tr:hover td {
            background-color: #ffffff !important;
        }

        /* Accordion improvements */
        html[data-theme='light'] .accordion-button {
            font-weight: 500;
        }

        /* Sharpen lines inside Staff Controls */
        #staffControlsAccordion .accordion-item {
            border: 2px solid var(--border-strong) !important;
            border-radius: .5rem !important;
            overflow: hidden;
        }
        #staffControlsAccordion .accordion-button {
            border-bottom: 2px solid var(--border-strong) !important;
            box-shadow: none !important;
        }
        #staffControlsAccordion .accordion-button:not(.collapsed) {
            box-shadow: inset 0 -2px 0 0 var(--border-strong) !important;
        }
        #staffControlsAccordion .accordion-body {
            border-top: 0 !important;
        }
        #staffControlsAccordion .table {
            border: 2px solid var(--border-strong) !important;
        }
        #staffControlsAccordion .table thead th,
        #staffControlsAccordion .table tbody td {
            border-color: var(--border-strong) !important;
        }
        #staffControlsAccordion .form-control,
        #staffControlsAccordion .form-select {
            border: 2px solid var(--border-strong) !important;
        }

        /* Better visibility for text in dark backgrounds */
        .bg-dark .text-white,
        .bg-dark .text-light,
        .bg-dark .form-label {
            color: #ffffff !important;
        }

        /* Ensure all text is visible */
        h1, h2, h3, h4, h5, h6 {
            color: inherit;
            font-weight: 600;
        }

        /* Card improvements */
        .card-title {
            font-weight: 600;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        /* Better button contrast */
        .btn {
            font-weight: 500;
            border: none;
            padding: 0.5rem 1rem;
        }

        /* Timer and Usage styling (match admin dashboard) */
        .timer-status {
            display: inline-block;
            margin-left: 15px;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            background: #fbbf24;
            color: #000;
            animation: pulse 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        /* Usage display styling (same as admin) */
        .usage-display {
            font-size: 48px;
            font-weight: 700;
            color: #818cf8;
            text-align: center;
            padding: 20px 0;
        }

        /* Empty state styling */
        .empty-state {
            color: #6c757d;
            font-style: italic;
            font-weight: 400;
        }

        /* Notification item visibility (dark mode default) */
        .notification-item {
            padding: 12px;
            background: #1e293b;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 14px;
            color: #e2e8f0;
            border-left: 4px solid #6366f1;
        }
        .notification-item .text-muted { color: #94a3b8 !important; }

        html[data-theme='light'] .empty-state {
            color: #6c757d;
        }

        /* Light theme overrides for notifications */
        html[data-theme='light'] .notification-item {
            background: #ffffff !important;
            color: #000000 !important;
            border: 1px solid #dee2e6 !important;
            border-left: 4px solid #6366f1 !important;
        }
        html[data-theme='light'] .notification-item .text-muted { color: #495057 !important; }

        /* Greeting styling */
        .greeting {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        /* Header improvements */
        .header-title {
            font-weight: 700;
            font-size: 1.5rem;
        }

        .user-info {
            font-weight: 500;
            font-size: 0.95rem;
        }

        /* Badge improvements */
        .badge {
            font-weight: 600;
            padding: 0.35em 0.65em;
            font-size: 0.85em;
        }

        /* Alert improvements */
        .alert {
            font-weight: 500;
        }

        /* Past Attendance Table Improvements */
        #pastAttendanceResults .table {
            font-size: 14px;
        }

        #pastAttendanceResults .table th {
            background-color: #1e293b !important;
            color: #ffffff !important;
            font-weight: 600;
            padding: 12px 8px;
        }

        #pastAttendanceResults .table td {
            background-color: #2d3748 !important;
            color: #ffffff !important;
            padding: 10px 8px;
            vertical-align: middle;
        }

        html[data-theme='light'] #pastAttendanceResults .bg-dark {
            background-color: #ffffff !important;
            border: 1px solid #dee2e6 !important;
        }

        html[data-theme='light'] #pastAttendanceResults .table {
            border: 1px solid #dee2e6 !important;
        }

        html[data-theme='light'] #pastAttendanceResults .table th {
            background-color: #0d6efd !important;
            color: #ffffff !important;
        }

        html[data-theme='light'] #pastAttendanceResults .table td {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }

        html[data-theme='light'] #pastAttendanceResults .table tbody tr:hover td {
            background-color: #ffffff !important;
        }

        /* Week Attendance Header */
        #pastAttendanceResults h5,
        #pastAttendanceResults p {
            color: #ffffff !important;
            font-weight: 600;
        }

        html[data-theme='light'] #pastAttendanceResults h5,
        html[data-theme='light'] #pastAttendanceResults p {
            color: #212529 !important;
        }

        /* Legend styling */
        #pastAttendanceResults .alert-info {
            background-color: #0dcaf0;
            color: #000;
            border: none;
            font-weight: 500;
        }

        html[data-theme='light'] #pastAttendanceResults .alert-info {
            background-color: #cff4fc;
            color: #055160;
        }

        /* Button group improvements */
        .btn-group-sm .btn {
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Sticky column improvements */
        #pastAttendanceResults .table td:first-child,
        #pastAttendanceResults .table th:first-child {
            position: sticky;
            left: 0;
            z-index: 5;
            font-weight: 600;
        }

        /* No login text visibility */
        .text-muted {
            opacity: 0.7;
        }

        html[data-theme='light'] .text-muted {
            color: #6c757d !important;
        }

        :root {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --bg-card: #0f3460;
            --text-primary: #ffffff;
            --text-secondary: #e0e0e0;
            --border-color: #2d3748;
            --border-strong: #475569;
        }

            html[data-theme='light'] {
            --bg-primary: #ffffff;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #212529;
            --text-secondary: #495057;
            --border-color: #dee2e6;
            --border-strong: #adb5bd;
        }

        html[data-theme='light'] body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        html[data-theme='light'] .header {
            background-color: #007bff;
        }

        html[data-theme='light'] .card {
            background-color: var(--bg-card);
            border-color: var(--border-color);
        }

        /* Timer and usage styles are defined earlier to match admin dashboard */

        /* Toast notification styles */
        .toast-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
        }

        .toast {
            min-width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        /* Table hover: use #6a7380 with white text for good contrast */
        html[data-theme='light'] table.table-hover tbody tr:hover,
        html[data-theme='light'] .table.table-hover tbody tr:hover,
        html[data-theme='light'] .table-hover tbody tr:hover,
        html[data-theme='light'] .table.table-hover.table-dark tbody tr:hover,
        html[data-theme='light'] .table.table-hover.table-dark tbody tr:hover td,
        html[data-theme='light'] #pastAttendanceResults .table tbody tr:hover,
        html[data-theme='light'] #pastAttendanceResults .table tbody tr:hover td,
        html[data-theme='light'] .container table tbody tr:hover,
        html[data-theme='light'] .container .table tbody tr:hover,
        html[data-theme='light'] .container table tbody tr:hover td,
        table.table-hover tbody tr:hover,
        .table.table-hover tbody tr:hover,
        .table-hover tbody tr:hover,
        table tbody tr:hover,
        .table tbody tr:hover {
            background-color: #6a7380 !important; /* new hover color */
            color: #ffffff !important;
        }
        /* B&W (light) mode: ensure Manage Subjects table is white */
        html[data-theme='light'] #manageSubjects .table {
            background-color: #ffffff !important;
            color: #000000 !important;
            border-color: #dee2e6 !important;
        }
        html[data-theme='light'] #manageSubjects .table thead th {
            background-color: #f8f9fa !important;
            color: #000000 !important;
            border-color: #dee2e6 !important;
        }
        html[data-theme='light'] #manageSubjects .table tbody td {
            background-color: #ffffff !important;
            color: #000000 !important;
            border-color: #dee2e6 !important;
        }
        html[data-theme='light'] #manageSubjects .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f8f9fa !important;
        }
        /* Normal (dark) mode: ensure Manage Subjects table is dark */
        html:not([data-theme='light']) #manageSubjects .table {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
            border-color: #2d3748 !important;
        }
        html:not([data-theme='light']) #manageSubjects .table thead th {
            background-color: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #2d3748 !important;
        }
        html:not([data-theme='light']) #manageSubjects .table tbody td {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
            border-color: #2d3748 !important;
        }
        html:not([data-theme='light']) #manageSubjects .table-striped tbody tr:nth-of-type(odd) {
            background-color: #243445 !important;
        }
        /* Force override of Bootstrap .table-dark in B&W (light) mode */
        html[data-theme='light'] #manageSubjects .table-dark,
        html[data-theme='light'] #manageSubjects .table-dark thead th,
        html[data-theme='light'] #manageSubjects .table-dark tbody tr,
        html[data-theme='light'] #manageSubjects .table-dark tbody td {
            background-color: #ffffff !important;
            color: #000000 !important;
        }

        /* Modal theming (dark) to match admin dashboard for all modals, including User Details and Edit Student */
        html:not([data-theme='light']) .modal-content,
        html:not([data-theme='light']) #userDetailsModal .modal-content {
            background-color: #0f172a !important;
            color: #e2e8f0 !important;
            border: 2px solid var(--border-strong) !important;
        }
        html:not([data-theme='light']) .modal-header,
        html:not([data-theme='light']) .modal-footer,
        html:not([data-theme='light']) #userDetailsModal .modal-header,
        html:not([data-theme='light']) #userDetailsModal .modal-footer {
            border-color: var(--border-strong) !important;
        }
        html:not([data-theme='light']) .modal-body .card.bg-secondary,
        html:not([data-theme='light']) #userDetailsModal .card.bg-secondary {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
            border: 2px solid var(--border-strong) !important;
        }
        html:not([data-theme='light']) .modal-body .card-title,
        html:not([data-theme='light']) #userDetailsModal .card-title,
        html:not([data-theme='light']) #userDetailsModal .card-body,
        html:not([data-theme='light']) #userDetailsModal .table,
        html:not([data-theme='light']) #userDetailsModal .table th,
        html:not([data-theme='light']) #userDetailsModal .table td {
            color: #e2e8f0 !important;
        }
        /* Inputs inside modals (dark) */
        html:not([data-theme='light']) .modal-content .form-control,
        html:not([data-theme='light']) .modal-content .form-select {
            background-color: #1f2a44 !important;
            color: #e2e8f0 !important;
            border-color: var(--border-strong) !important;
        }

        html:not([data-theme='light']) #userDetailsModal .table {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
            border-color: #2d3748 !important;
        }

        html:not([data-theme='light']) #userDetailsModal h5 {
            color: #ffffff !important;
        }

        /* Light theme overrides for all modals to match admin dashboard */
        html[data-theme='light'] .modal-content,
        html[data-theme='light'] #userDetailsModal .modal-content {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }
        html[data-theme='light'] .modal-header,
        html[data-theme='light'] .modal-footer,
        html[data-theme='light'] #userDetailsModal .modal-header,
        html[data-theme='light'] #userDetailsModal .modal-footer {
            border-color: #dee2e6 !important;
        }
        html[data-theme='light'] .modal-body .card.bg-secondary,
        html[data-theme='light'] #userDetailsModal .card.bg-secondary {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }
        html[data-theme='light'] .modal-body .card-title,
        html[data-theme='light'] #userDetailsModal .card-title,
        html[data-theme='light'] #userDetailsModal .card-body,
        html[data-theme='light'] #userDetailsModal .table,
        html[data-theme='light'] #userDetailsModal .table th,
        html[data-theme='light'] #userDetailsModal .table td {
            color: #212529 !important;
        }
        /* Inputs inside modals (light) */
        html[data-theme='light'] .modal-content .form-control,
        html[data-theme='light'] .modal-content .form-select {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #ced4da !important;
        }

        html[data-theme='light'] #userDetailsModal .table {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }

        html[data-theme='light'] #userDetailsModal .table thead th {
            background-color: #f8f9fa !important;
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }
        html[data-theme='light'] #userDetailsModal .table tbody td {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }
        html[data-theme='light'] #userDetailsModal .badge.bg-primary {
            background-color: #0d6efd !important;
        }
    </style>
</head>
<body>
    <!-- Toast Container for notifications -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Header -->
    <header class="header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h1 class="header-title">Lab Activity Reporting System</h1>
                <div class="header-actions d-flex align-items-center gap-3 flex-wrap">
                    <span class="user-info">User: <?php echo htmlspecialchars($staff_name); ?> | Role: staff</span>
                    <button class="btn btn-secondary" id="themeToggle" onclick="toggleTheme()" title="Toggle Dark/Light Mode">
                        <span id="themeIcon">☀️</span>
                    </button>
                    <button class="btn <?php echo $timer_paused ? 'btn-info' : 'btn-warning'; ?>" id="pauseTimerBtn" onclick="togglePauseTimer()">
                        <span id="pauseIcon"><?php echo $timer_paused ? '▶️' : '⏸️'; ?></span> 
                        <span id="pauseText"><?php echo $timer_paused ? 'Resume Timer' : 'Pause Timer'; ?></span>
                    </button>
                    <button class="btn btn-success" onclick="minimizeWindow()">Continue</button>
                    <form method="POST" class="d-inline" id="logoutForm">
                        <button type="submit" name="logout" class="btn btn-danger" onclick="handleLogout(); return false;">Logout and Shutdown</button>
                    </form>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Minimized Bar (hidden by default) -->
    <div id="minimizedBar" style="display: none; position: fixed; bottom: 20px; left: 20px; 
         background: linear-gradient(135deg, #4c51bf 0%, #5b63d3 100%); color: white; 
         padding: 12px 24px; border-radius: 8px; cursor: pointer; z-index: 10000;
         box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-family: 'Poppins', sans-serif;
         align-items: center; gap: 8px; user-select: none; font-weight: 500;">
        📘 Lab Activity System — Click to Restore
    </div>

    <!-- Main Content -->
    <div id="mainContent" class="container-fluid mt-4 px-4">
        <h2 class="greeting">Hi, <?php echo htmlspecialchars($staff_name); ?></h2>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- My Notifications -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">My Notifications</h3>
                <?php if (empty($notifications)): ?>
                    <p class="empty-state">No new notifications.</p>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                        <div class="notification-item mb-2 p-2 bg-dark rounded">
                            <?php echo htmlspecialchars($notif['message']); ?>
                            <div class="text-muted small"><?php echo date('d-m-Y H:i', strtotime($notif['created_at'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- My Total Lab Usage -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">My Total Lab Usage</h3>
                <div class="usage-display" id="timerDisplay"><?php echo $usage_display; ?></div>
                <span class="timer-status timer-paused" id="timerStatus" style="display: <?php echo $timer_paused ? 'inline-block' : 'none'; ?>;">⏸️ PAUSED</span>
            </div>
        </div>

        <!-- Currently Active Students -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">Currently Active Students</h3>
                <?php if (empty($active_students)): ?>
                    <p class="empty-state">No students are currently active.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Admission No.</th>
                                    <th>Subject</th>
                                    <th>Login Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                        <td><?php echo htmlspecialchars($student['subject_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('H:i:s', strtotime($student['login_time'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

               <!-- Verify Student Attendance (Today) -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">Verify Student Attendance (Today)</h3>
                <?php if (empty($attendance_students)): ?>
                    <p class="empty-state">No students logged in today.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>STUDENT NAME</th>
                                    <th>REPORTED SUBJECT</th>
                                    <th>STATUS</th>
                                    <th>ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['subject_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($student['marked_status']): ?>
                                                <span class="badge bg-<?php echo $student['marked_status'] == 'present' ? 'success' : ($student['marked_status'] == 'late' ? 'warning' : 'danger'); ?>">
                                                    <?php echo ucfirst($student['marked_status']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-<?php echo $student['status'] == 'On Time' ? 'success' : 'warning'; ?>">
                                                    <?php echo $student['status']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-success" onclick="markAttendance(<?php echo $student['id']; ?>, <?php echo $student['subject_id']; ?>, 'present')">Present</button>
                                            <button class="btn btn-sm btn-warning" onclick="markAttendance(<?php echo $student['id']; ?>, <?php echo $student['subject_id']; ?>, 'late')">Late</button>
                                            <button class="btn btn-sm btn-danger" onclick="markAttendance(<?php echo $student['id']; ?>, <?php echo $student['subject_id']; ?>, 'absent')">Absent</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Student Login/Logout Times -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">Student Login/Logout Times</h3>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control" id="searchLogin" placeholder="Filter by student name/username..." onkeyup="filterLoginTable()">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" id="filterDate" onchange="filterLoginTable()" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="filterSubject" onchange="filterLoginTable()">
                            <option value="">Filter by Subject</option>
                            <?php foreach ($all_subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <select class="form-select" id="filterBatch" onchange="filterLoginTable()">
                            <option value="">Filter by Batch</option>
                            <?php
                            $batches = array_unique(array_column($login_times, 'year'));
                            foreach ($batches as $batch):
                                if ($batch):
                            ?>
                                <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </select>
                        <button class="btn btn-primary" type="button" onclick="filterLoginTable()">Filter</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover" id="loginTable">
                        <thead>
                            <tr>
                                <th>STUDENT NAME</th>
                                <th>SUBJECT</th>
                                <th>LOGIN TIME</th>
                                <th>LOGOUT TIME</th>
                                <th>DURATION</th>
                                <th>MAC ADDRESS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($login_times as $time): ?>
                                <tr data-batch="<?php echo htmlspecialchars($time['year'] ?? ''); ?>"
                                    data-date="<?php echo htmlspecialchars($time['login_date'] ?? ''); ?>"
                                    data-subject="<?php echo htmlspecialchars($time['subject_id'] ?? ''); ?>">
                                    <td><?php echo htmlspecialchars($time['name']); ?></td>
                                    <td><?php echo htmlspecialchars($time['subject_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('d-m-Y, H:i', strtotime($time['login_time'])); ?></td>
                                    <td>
                                        <?php
                                        $isActive = isset($time['is_active']) && (int)$time['is_active'] === 1;
                                        $logoutTs = !empty($time['logout_time']) ? strtotime($time['logout_time']) : null;
                                        if ($isActive || $logoutTs === false || $logoutTs === null) {
                                            echo 'Active';
                                        } else {
                                            echo date('d-m-Y, H:i', $logoutTs);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $loginTs = strtotime($time['login_time']);
                                        $isActive = isset($time['is_active']) && (int)$time['is_active'] === 1;
                                        if ($isActive) {
                                            // active session -> duration till now
                                            $dur = time() - $loginTs;
                                        } else {
                                            // completed session -> use SQL-provided duration when available
                                            $dur = isset($time['duration']) && $time['duration'] !== null
                                                ? (int)$time['duration']
                                                : ($logoutTs ? max(0, $logoutTs - $loginTs) : 0);
                                        }
                                        $dur = max(0, (int)$dur);
                                        $h = floor($dur / 3600);
                                        $m = floor(($dur % 3600) / 60);
                                        $s = $dur % 60;
                                        echo "{$h}h {$m}m {$s}s";
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($time['mac_address'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Student Activity Logs -->
        <div class="card mb-4">
            <div class="card-body">
                        <h3 class="card-title">Student Activity Logs</h3>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <input type="text" id="activitySearch" class="form-control" placeholder="Search logs (student name, admission, text)" />
                            </div>
                            <div class="col-md-3">
                                <input type="date" id="activityDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" />
                            </div>
                            <div class="col-md-3 d-flex">
                                <button class="btn btn-primary ms-auto" type="button" onclick="filterActivityLogs('student')">Filter</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover" id="activityTable">
                                <thead>
                                    <tr>
                                        <th>TIMESTAMP</th>
                                        <th>STUDENT</th>
                                        <th>ADMISSION NO.</th>
                                        <th>ACTIVITY</th>
                                        <th>ACTION</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($student_activity_logs as $log): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y, H:i', strtotime($log['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($log['name']); ?></td>
                                            <td><?php echo htmlspecialchars($log['admission_number']); ?></td>
                                            <td>
                                                <?php if ($log['subject_id'] === null): ?>
                                                    <span style="color: #fbbf24; font-weight: 600;">[Other Activities]</span> 
                                                <?php elseif (!empty($log['subject_name'])): ?>
                                                    <span style="color: #94a3b8; font-size: 12px;">[<?php echo htmlspecialchars($log['subject_name']); ?>]</span> 
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($log['log_text']); ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-danger" onclick="deleteActivityLog(<?php echo $log['id']; ?>)">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
            </div>
        </div>

        <!-- Staff Controls Accordion -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title mb-4">Staff Controls</h3>
                <div class="accordion" id="staffControlsAccordion">
                    
                    <!-- View and Manage Students -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manageStudents">
                                View and Manage Students
                            </button>
                        </h2>
                        <div id="manageStudents" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <div class="row mb-3">
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="searchStudents" placeholder="Search by name or username..." onkeyup="filterStudentsTable()">
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-select" id="filterStudentBatch" onchange="filterStudentsTable()">
                                            <option value="">Filter by Batch</option>
                                            <?php
                                            $student_batches = array_unique(array_filter(array_column($all_students, 'year')));
                                            foreach ($student_batches as $batch):
                                            ?>
                                                <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover" id="studentsTable">
                                        <thead>
                                            <tr>
                                                <th>NAME</th>
                                                <th>ADMISSION NO.</th>
                                                <th>BATCH</th>
                                                <th>TOTAL HOURS</th>
                                                <th>ACTIONS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_students as $student): ?>
                                                <tr data-batch="<?php echo htmlspecialchars($student['year'] ?? ''); ?>">
                                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['year'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php 
                                                        $total = $student['total_seconds'] ?? 0;
                                                        $h = floor($total / 3600);
                                                        $m = floor(($total % 3600) / 60);
                                                        $s = $total % 60;
                                                        echo "{$h}h {$m}m {$s}s";
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary" onclick="viewUserDetails(<?php echo $student['id']; ?>)">View</button>
                                                        <button class="btn btn-sm btn-secondary" onclick="editUser(<?php echo $student['id']; ?>)">Edit</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manage Past Attendance -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pastAttendance">
                                Manage Past Attendance
                            </button>
                        </h2>
                        <div id="pastAttendance" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <div class="p-4 bg-dark rounded">
                                    <h5 class="text-white mb-3">Load One Week Attendance</h5>
                                    <div class="mb-3">
                                        <label class="form-label text-light">Select Subject <span class="text-danger">*</span></label>
                                        <select class="form-select bg-dark text-white border-secondary" id="pastSubject" required>
                                            <option value="">-- Select a Subject --</option>
                                            <?php foreach ($all_subjects as $subject): ?>
                                                <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-light">Select Start Date (shows 7 days from selected date) <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control bg-dark text-white border-secondary" id="pastDate" max="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-light">Filter by Batch (Optional)</label>
                                        <select class="form-select bg-dark text-white border-secondary" id="pastBatchSelect">
                                            <option value="">All Batches</option>
                                            <?php
                                            $student_batches = array_unique(array_filter(array_column($all_students, 'year')));
                                            foreach ($student_batches as $batch):
                                            ?>
                                                <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-light">Search Student (Optional)</label>
                                        <input type="text" class="form-control bg-dark text-white border-secondary" id="searchPastStudent" placeholder="Search by name or admission no...">
                                    </div>
                                    <button class="btn btn-primary w-100" onclick="loadPastAttendance()">Load Week Attendance</button>
                                    <div id="pastAttendanceResults" class="mt-3"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Reports -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#activityReports">
                                Activity Reports
                            </button>
                        </h2>
                        <div id="activityReports" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <div class="mb-3">
                                    <label class="form-label">Filter by Subject (Optional)</label>
                                    <select class="form-select" id="activitySubjectSelect">
                                        <option value="">All Subjects</option>
                                        <?php foreach ($all_subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Filter by Batch (Optional)</label>
                                    <select class="form-select" id="activityBatchSelect">
                                        <option value="">All Batches</option>
                                        <?php
                                        $student_batches = array_unique(array_filter(array_column($all_students, 'year')));
                                        foreach ($student_batches as $batch):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Filter by Student (Optional)</label>
                                    <input type="text" class="form-control" placeholder="Type to search for a student...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Daily Report</label>
                                    <div class="input-group">
                                        <input type="date" class="form-control" id="dailyActivityDate">
                                        <button class="btn btn-primary" onclick="downloadActivityReport('daily')">Download</button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Range Report</label>
                                    <div class="row g-2">
                                        <div class="col-5">
                                            <input type="date" class="form-control" id="activityStartDate">
                                        </div>
                                        <div class="col-5">
                                            <input type="date" class="form-control" id="activityEndDate">
                                        </div>
                                        <div class="col-2">
                                            <button class="btn btn-primary w-100" onclick="downloadActivityReport('range')">Download</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Usage Reports removed (merged into single block below) -->

                    <!-- Attendance Reports -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#attendanceReports">
                                Attendance Reports
                            </button>
                        </h2>
                        <div id="attendanceReports" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <div class="mb-3">
                                    <label class="form-label">Filter by Subject (Optional)</label>
                                    <select class="form-select" id="attendanceSubjectSelect">
                                        <option value="">All Subjects</option>
                                        <?php foreach ($all_subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Filter by Student (Optional)</label>
                                    <input type="text" class="form-control" placeholder="Type to search...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Filter by Batch (Optional)</label>
                                    <select class="form-select" id="attendanceBatchSelect">
                                        <option value="">All Batches</option>
                                        <?php
                                        $student_batches = array_unique(array_filter(array_column($all_students, 'year')));
                                        foreach ($student_batches as $batch):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Download for a day:</label>
                                    <div class="input-group">
                                        <input type="date" class="form-control" id="attendanceDayDate">
                                        <button class="btn btn-success" onclick="downloadAttendanceReport('daily')">Download</button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Download for a range:</label>
                                    <div class="row g-2">
                                        <div class="col-5">
                                            <input type="date" class="form-control" id="attendanceStartDate">
                                        </div>
                                        <div class="col-5">
                                            <input type="date" class="form-control" id="attendanceEndDate">
                                        </div>
                                        <div class="col-2">
                                            <button class="btn btn-success w-100" onclick="downloadAttendanceReport('range')">Download</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Usage Report -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#systemUsage">
                                System Usage Report
                            </button>
                        </h2>
                        <div id="systemUsage" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <div class="mb-3">
                                    <label class="form-label">Daily Report</label>
                                    <div class="input-group">
                                        <input type="date" class="form-control" id="dailySystemUsageDate" value="<?php echo date('Y-m-d'); ?>">
                                        <button class="btn btn-primary" onclick="downloadSystemUsageReport('daily')">
                                            <i class="bi bi-download me-1"></i> Download PDF
                                        </button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Range Report</label>
                                    <div class="row g-2">
                                        <div class="col-5">
                                            <input type="date" class="form-control" id="systemUsageStartDate" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
                                        </div>
                                        <div class="col-5">
                                            <input type="date" class="form-control" id="systemUsageEndDate" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-2">
                                            <button class="btn btn-primary w-100" onclick="downloadSystemUsageReport('range')">
                                                <i class="bi bi-download me-1"></i> Download PDF
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive mt-3">
                                    <table class="table table-dark table-hover">
                                        <thead>
                                            <tr>
                                                <th>MAC ADDRESS (PC)</th>
                                                <th>NUMBER OF LOGINS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($system_usage as $usage): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($usage['mac_address']); ?></td>
                                                    <td><?php echo $usage['login_count']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>



                    <!-- Manage Subjects -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manageSubjects">
                                Manage Subjects
                            </button>
                        </h2>
                        <div id="manageSubjects" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <h5 class="mb-3">Add New Subject</h5>
                                <div class="mb-3">
                                    <input type="text" class="form-control mb-2" id="newSubjectName" placeholder="Subject Name (required)">
                                    <input type="text" class="form-control mb-2" id="newSubjectCode" placeholder="Subject Code">
                                    <button class="btn btn-success w-100" onclick="addSubject()">Add Subject</button>
                                </div>
                                
                                <h5 class="mt-4 mb-3">Existing Subjects</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_subjects as $subject): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                <td><?php echo htmlspecialchars($subject['subject_code'] ?? ''); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteSubject(<?php echo $subject['id']; ?>)">Delete</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manage Activity Logs -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manageLogs">
                                Manage Activity Logs
                            </button>
                        </h2>
                        <div id="manageLogs" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <div class="mb-4">
                                    <h5>Delete all logs on a specific day:</h5>
                                    <div class="input-group">
                                        <input type="date" class="form-control" id="deleteLogsDay">
                                        <button class="btn btn-danger" onclick="deleteLogsByDay()">Delete by Day</button>
                                    </div>
                                </div>
                                <div>
                                    <h5>Delete all logs in a date range:</h5>
                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" class="form-control" id="deleteLogsStart">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">End Date</label>
                                            <input type="date" class="form-control" id="deleteLogsEnd">
                                        </div>
                                    </div>
                                    <button class="btn btn-danger w-100" onclick="deleteLogsByRange()">Delete by Range</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#reportIssue">
                                Report a System Issue
                            </button>
                        </h2>
                        <div id="reportIssue" class="accordion-collapse collapse" data-bs-parent="#staffControlsAccordion">
                            <div class="accordion-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">System Number</label>
                                        <input type="text" class="form-control" id="staffSystemNumber" placeholder="e.g., PC-05">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Describe the Issue</label>
                                        <textarea class="form-control" id="staffIssueDescription" rows="2" placeholder="e.g., Monitor not turning on"></textarea>
                                    </div>
                                </div>
                                <div class="mt-3 d-flex justify-content-end">
                                    <button class="btn btn-danger" id="staffReportBtn" onclick="submitStaffIssue()">Submit Issue</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingTrendReport">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTrendReport" aria-expanded="false" aria-controls="collapseTrendReport">
                                Trend Report
                            </button>
                        </h2>
                        <div id="collapseTrendReport" class="accordion-collapse collapse" aria-labelledby="headingTrendReport" data-bs-parent="#dashboard-accordions">
                            <div class="accordion-body">
                                <p>Generate a trend report for a specific date range.</p>
                                <div class="row">
                                    <div class="col-md-5">
                                        <label for="trend_start_date">Start Date</label>
                                        <input type="date" id="trend_start_date" class="form-control">
                                    </div>
                                    <div class="col-md-5">
                                        <label for="trend_end_date">End Date</label>
                                        <input type="date" id="trend_end_date" class="form-control">
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button class="btn btn-primary w-100" onclick="generateTrend()">Generate</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit User Modal (reused pattern from admin) -->
        <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editUserModalLabel">Edit Student</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="editUserForm" onsubmit="return false;">
                            <input type="hidden" id="edit_user_id" name="id">
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" class="form-control" id="edit_name" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Admission Number</label>
                                <input type="text" class="form-control" id="edit_admission_number" name="admission_number" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Batch (YYYY-YYYY)</label>
                                <input type="text" class="form-control" id="edit_year" name="year">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New Password (leave blank to keep current)</label>
                                <input type="password" class="form-control" id="edit_password" name="password">
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="saveEditBtn" onclick="handleSaveEdit()">Save changes</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Charts and PDF libraries -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        // Timer functionality - Persistent Background Timer
        // This timer runs continuously even when window is minimized or out of focus
        // Uses localStorage to persist state across page refreshes
        // Stops only on Logout
        
        // Initialize timer state from localStorage or PHP
        const TIMER_STORAGE_KEY = 'staff_timer_state';
        const TIMER_START_KEY = 'staff_timer_start';
        const TIMER_PAUSED_KEY = 'staff_timer_paused';
        
        let timerInterval = null;
        let startTime = null;
        let isTimerPaused = false;
        let pausedElapsedTime = 0; // Store elapsed time when paused
        
        // Load timer state from localStorage
        function loadTimerState() {
            const savedStart = localStorage.getItem(TIMER_START_KEY);
            const savedPaused = localStorage.getItem(TIMER_PAUSED_KEY);
            
            if (savedStart) {
                // Resume from saved start time
                startTime = parseInt(savedStart);
                isTimerPaused = (savedPaused === 'true');
            } else {
                // Initialize with current PHP elapsed time
                startTime = Date.now() - (<?php echo $elapsed_seconds; ?> * 1000);
                isTimerPaused = <?php echo $timer_paused ? 'true' : 'false'; ?>;
                saveTimerState();
            }
        }
        
        // Save timer state to localStorage
        function saveTimerState() {
            if (startTime) {
                localStorage.setItem(TIMER_START_KEY, startTime.toString());
                localStorage.setItem(TIMER_PAUSED_KEY, isTimerPaused.toString());
            }
        }
        
        // Clear timer state from localStorage (called on logout)
        function clearTimerState() {
            localStorage.removeItem(TIMER_START_KEY);
            localStorage.removeItem(TIMER_PAUSED_KEY);
            localStorage.removeItem(TIMER_STORAGE_KEY);
        }
        
        // Update timer display - runs every second
        function updateTimer() {
            if (startTime) {
                let totalSeconds;
                
                if (isTimerPaused) {
                    // Use stored elapsed time when paused
                    totalSeconds = pausedElapsedTime;
                } else {
                    // Calculate elapsed time using current Date() - works even when tab is in background
                    const now = Date.now();
                    const elapsedMs = now - startTime;
                    totalSeconds = Math.floor(elapsedMs / 1000);
                    // Update stored elapsed time
                    pausedElapsedTime = totalSeconds;
                }
                
                // Format as HH:MM:SS
                const hours = Math.floor(totalSeconds / 3600);
                const minutes = Math.floor((totalSeconds % 3600) / 60);
                const seconds = totalSeconds % 60;
                
                const display = document.getElementById('timerDisplay');
                if (display) {
                    display.textContent = 
                        String(hours).padStart(2, '0') + ':' + 
                        String(minutes).padStart(2, '0') + ':' + 
                        String(seconds).padStart(2, '0');
                }
            }
        }
        
        // Initialize timer on page load
        loadTimerState();
        
        // Start timer interval - runs continuously in background
        timerInterval = setInterval(updateTimer, 1000);
        
        // Update display immediately
        updateTimer();
        
        // Update button state on load
        const pauseBtn = document.getElementById('pauseTimerBtn');
        if (pauseBtn) {
            if (isTimerPaused) {
                pauseBtn.classList.remove('btn-warning');
                pauseBtn.classList.add('btn-info');
                const pauseIcon = document.getElementById('pauseIcon');
                const pauseText = document.getElementById('pauseText');
                if (pauseIcon) pauseIcon.textContent = '▶️';
                if (pauseText) pauseText.textContent = 'Resume Timer';
                const timerStatus = document.getElementById('timerStatus');
                if (timerStatus) timerStatus.style.display = 'inline-block';
            }
        }
        
        // Handle logout - clear timer state
        function handleLogout() {
            if (confirm('Are you sure you want to logout?')) {
                // Clear timer state from localStorage
                clearTimerState();
                
                // Clear interval
                if (timerInterval) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }
                
                // Send logout action to system usage tracking
                if (typeof updateSystemUsage === 'function') {
                    updateSystemUsage('logout');
                }
                
                // Clear heartbeat interval if exists
                if (typeof heartbeatInterval !== 'undefined' && heartbeatInterval) {
                    clearInterval(heartbeatInterval);
                }
                
                // Submit the logout form
                const form = document.getElementById('logoutForm');
                if (form) {
                    // Mark form as handled to prevent double processing
                    form.dataset.logoutHandled = 'true';
                    
                    // Ensure logout parameter is set
                    const existingLogout = form.querySelector('input[name="logout"]');
                    if (!existingLogout) {
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'logout';
                        hiddenInput.value = '1';
                        form.appendChild(hiddenInput);
                    }
                    
                    // Submit the form - use requestSubmit for better compatibility
                    if (form.requestSubmit) {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                } else {
                    // Fallback: create form and submit
                    const fallbackForm = document.createElement('form');
                    fallbackForm.method = 'POST';
                    fallbackForm.action = window.location.href;
                    const logoutInput = document.createElement('input');
                    logoutInput.type = 'hidden';
                    logoutInput.name = 'logout';
                    logoutInput.value = '1';
                    fallbackForm.appendChild(logoutInput);
                    document.body.appendChild(fallbackForm);
                    fallbackForm.submit();
                }
            }
        }

        // Toggle pause timer function
        function togglePauseTimer() {
            const pauseBtn = document.getElementById('pauseTimerBtn');
            const pauseIcon = document.getElementById('pauseIcon');
            const pauseText = document.getElementById('pauseText');
            const timerStatus = document.getElementById('timerStatus');
            
            // Disable button during request
            pauseBtn.disabled = true;
            
            if (!isTimerPaused) {
                // Pause the timer
                // Calculate and store current elapsed time
                if (startTime) {
                    const now = Date.now();
                    const elapsedMs = now - startTime;
                    pausedElapsedTime = Math.floor(elapsedMs / 1000);
                }
                
                const pauseStartTime = new Date().toISOString();
                
                fetch('ajax_pause_timer.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=pause&pause_time=' + encodeURIComponent(pauseStartTime)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        isTimerPaused = true;
                        // Adjust startTime so timer shows paused elapsed time
                        if (startTime) {
                            const now = Date.now();
                            startTime = now - (pausedElapsedTime * 1000);
                        }
                        saveTimerState(); // Save paused state to localStorage
                        pauseBtn.classList.remove('btn-warning');
                        pauseBtn.classList.add('btn-info');
                        pauseIcon.textContent = '▶️';
                        pauseText.textContent = 'Resume Timer';
                        if (timerStatus) timerStatus.style.display = 'inline-block';
                        showToast('Timer paused for all students', 'warning');
                        console.log('Timer paused successfully');
                    } else {
                        showToast('Failed to pause timer: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error pausing timer:', error);
                    showToast('Error pausing timer', 'danger');
                })
                .finally(() => {
                    pauseBtn.disabled = false;
                });
            } else {
                // Resume the timer
                // Adjust startTime to continue from where it was paused
                if (startTime && pausedElapsedTime > 0) {
                    const now = Date.now();
                    startTime = now - (pausedElapsedTime * 1000);
                }
                
                fetch('ajax_pause_timer.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=resume&resume_time=' + encodeURIComponent(new Date().toISOString())
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        isTimerPaused = false;
                        saveTimerState(); // Save resumed state to localStorage
                        pauseBtn.classList.remove('btn-info');
                        pauseBtn.classList.add('btn-warning');
                        pauseIcon.textContent = '⏸️';
                        pauseText.textContent = 'Pause Timer';
                        if (timerStatus) timerStatus.style.display = 'none';
                        showToast('Timer resumed for all students', 'success');
                        console.log('Timer resumed successfully');
                    } else {
                        showToast('Failed to resume timer: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error resuming timer:', error);
                    showToast('Error resuming timer', 'danger');
                })
                .finally(() => {
                    pauseBtn.disabled = false;
                });
            }
        }

        // Submit a system issue (staff)
        function submitStaffIssue() {
            const btn = document.getElementById('staffReportBtn');
            const systemNumber = document.getElementById('staffSystemNumber').value.trim();
            const description = document.getElementById('staffIssueDescription').value.trim();

            if (!systemNumber || !description) {
                showToast('Please provide both system number and issue description', 'warning');
                return;
            }

            btn.disabled = true;
            const prevText = btn.textContent;
            btn.textContent = 'Submitting...';

            fetch('ajax_report_issue.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'system_number=' + encodeURIComponent(systemNumber) + '&issue_description=' + encodeURIComponent(description),
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(resp => resp.text().then(text => ({ text, resp })))
            .then(({ text, resp }) => {
                try {
                    const ct = (resp.headers.get('content-type') || '').toLowerCase();
                    if (!ct.includes('application/json')) throw new Error('Non-JSON response');
                    const data = JSON.parse(text);
                    if (data.success) {
                        showToast('Issue reported successfully', 'success');
                        document.getElementById('staffSystemNumber').value = '';
                        document.getElementById('staffIssueDescription').value = '';
                    } else {
                        showToast(data.message || 'Failed to report issue', 'danger');
                    }
                } catch (e) {
                    console.error('Invalid response:', text);
                    showToast('Unexpected server response while reporting issue', 'danger');
                }
            })
            .catch(err => {
                showToast('Network error: ' + err.message, 'danger');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = prevText;
            });
        }

        // Show toast notification
        function showToast(message, type) {
            const toastContainer = document.getElementById('toastContainer');
            const toastId = 'toast_' + Date.now();
            
            const toastHtml = `
                <div class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true" id="${toastId}">
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, { delay: 4000 });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', function () {
                toastElement.remove();
            });
        }

        // Dark/Light Mode Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const themeIcon = document.getElementById('themeIcon');
            
            if (currentTheme === 'light') {
                html.setAttribute('data-theme', 'dark');
                themeIcon.textContent = '☀️';
                localStorage.setItem('theme', 'dark');
            } else {
                html.setAttribute('data-theme', 'light');
                themeIcon.textContent = '🌙';
                localStorage.setItem('theme', 'light');
            }
        }

        // Load saved theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'dark';
            const html = document.documentElement;
            const themeIcon = document.getElementById('themeIcon');
            
            html.setAttribute('data-theme', savedTheme);
            if (savedTheme === 'light') {
                themeIcon.textContent = '🌙';
            } else {
                themeIcon.textContent = '☀️';
            }
        });

        // Filter functions (server-side fetch)
        function formatDateTime(dtStr) {
            if (!dtStr) return 'N/A';
            try {
                const d = new Date(dtStr.replace(' ', 'T'));
                const dd = ('0' + d.getDate()).slice(-2);
                const mm = ('0' + (d.getMonth() + 1)).slice(-2);
                const yyyy = d.getFullYear();
                const HH = ('0' + d.getHours()).slice(-2);
                const MM = ('0' + d.getMinutes()).slice(-2);
                return `${dd}-${mm}-${yyyy}, ${HH}:${MM}`;
            } catch (e) {
                return dtStr;
            }
        }

        function filterLoginTable() {
            const searchValue = document.getElementById('searchLogin').value.trim();
            const batchValue = document.getElementById('filterBatch').value;
            const dateValue = document.getElementById('filterDate').value;
            const subjectValue = document.getElementById('filterSubject').value;

            const formData = new URLSearchParams();
            if (dateValue) formData.append('date', dateValue);
            if (subjectValue) formData.append('subject_id', subjectValue);
            if (batchValue) formData.append('batch', batchValue);
            if (searchValue) formData.append('search', searchValue);

            fetch('ajax_filter_login_times.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(resp => resp.json())
            .then(data => {
                const tbody = document.querySelector('#loginTable tbody');
                if (!tbody) return;
                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="6">Error loading data</td></tr>';
                    console.error('Filter error:', data.message || data);
                    return;
                }
                let rows = data.rows || [];
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6">No records found for selected filters.</td></tr>';
                    return;
                }

                // Deduplicate by user_id, similar to PHP rendering
                const seen = new Set();
                let html = '';
                rows.forEach(r => {
                    const uid = r.user_id || r.id;
                    if (uid && seen.has(uid)) return;
                    if (uid) seen.add(uid);

                    const loginFormatted = formatDateTime(r.login_time);

                    const isActive = Number(r.is_active) === 1;
                    let logoutText;
                    if (isActive || !r.logout_time) {
                        logoutText = 'Active';
                    } else {
                        logoutText = formatDateTime(r.logout_time);
                    }

                    let durSeconds;
                    if (isActive) {
                        // For active sessions, approximate duration using current time on client
                        try {
                            const loginDate = new Date(r.login_time.replace(' ', 'T'));
                            durSeconds = Math.max(0, Math.floor((Date.now() - loginDate.getTime()) / 1000));
                        } catch (e) {
                            durSeconds = r.duration ? Number(r.duration) : 0;
                        }
                    } else {
                        durSeconds = r.duration ? Number(r.duration) : 0;
                    }

                    const h = Math.floor(durSeconds / 3600);
                    const m = Math.floor((durSeconds % 3600) / 60);
                    const s = durSeconds % 60;
                    const durationText = `${h}h ${m}m ${s}s`;

                    html += `<tr data-batch="${r.year || ''}" data-date="${r.login_date || ''}" data-subject="${r.subject_id || ''}">`;
                    html += `<td>${escapeHtml(r.name)}</td>`;
                    html += `<td>${escapeHtml(r.subject_name || 'N/A')}</td>`;
                    html += `<td>${escapeHtml(loginFormatted)}</td>`;
                    html += `<td>${escapeHtml(logoutText)}</td>`;
                    html += `<td>${escapeHtml(durationText)}</td>`;
                    html += `<td>${escapeHtml(r.mac_address || 'N/A')}</td>`;
                    html += `</tr>`;
                });
                tbody.innerHTML = html;
            })
            .catch(err => {
                const tbody = document.querySelector('#loginTable tbody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="6">Error fetching data</td></tr>';
                console.error('Fetch error:', err);
            });
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Activity logs filtering via AJAX
        function filterActivityLogs(role) {
            const searchValue = document.getElementById('activitySearch') ? document.getElementById('activitySearch').value.trim() : '';
            const dateValue = document.getElementById('activityDate') ? document.getElementById('activityDate').value : '';

            const formData = new URLSearchParams();
            formData.append('role', role || 'student');
            if (dateValue) formData.append('date', dateValue);
            if (searchValue) formData.append('search', searchValue);

            fetch('ajax_filter_activity_logs.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(resp => resp.json())
            .then(data => {
                const tbody = document.querySelector('#activityTable tbody');
                if (!tbody) return;
                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="5">Error loading activity logs</td></tr>';
                    return;
                }
                const rows = data.rows || [];
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5">No logs found for selected filters.</td></tr>';
                    return;
                }
                let html = '';
                rows.forEach(r => {
                    html += '<tr>';
                    html += '<td>' + escapeHtml(r.created_at ? r.created_at.replace(' ', ', ') : r.created_at) + '</td>';
                    html += '<td>' + escapeHtml(r.name || '') + '</td>';
                    html += '<td>' + escapeHtml(r.admission_number || r.username || '') + '</td>';
                    html += '<td>';
                    // Show label for other activities (subject_id is null)
                    if (r.subject_id === null || r.subject_id === '') {
                        html += '<span style="color: #fbbf24; font-weight: 600;">[Other Activities]</span> ';
                    } else if (r.subject_name) {
                        html += '<span style="color: #94a3b8; font-size: 12px;">[' + escapeHtml(r.subject_name) + ']</span> ';
                    }
                    html += escapeHtml(r.log_text || '');
                    html += '</td>';
                    html += '<td><button class="btn btn-sm btn-danger" onclick="deleteActivityLog(' + (r.id || 0) + ')">Delete</button></td>';
                    html += '</tr>';
                });
                tbody.innerHTML = html;
            })
            .catch(err => {
                const tbody = document.querySelector('#activityTable tbody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="5">Error fetching activity logs</td></tr>';
                console.error('Fetch error:', err);
            });
        }

        function filterStudentsTable() {
            const searchValue = document.getElementById('searchStudents').value.toUpperCase();
            const batchValue = document.getElementById('filterStudentBatch').value;
            const table = document.getElementById('studentsTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const name = row.cells[0].textContent.toUpperCase();
                const admissionNo = row.cells[1].textContent.toUpperCase();
                const batch = row.getAttribute('data-batch');
                
                const matchesSearch = name.indexOf(searchValue) > -1 || admissionNo.indexOf(searchValue) > -1;
                const matchesBatch = !batchValue || batch === batchValue;
                
                row.style.display = (matchesSearch && matchesBatch) ? '' : 'none';
            }
        }

        // AJAX Functions
        function markAttendance(studentId, subjectId, status) {
            fetch('ajax_mark_attendance.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `student_id=${studentId}&subject_id=${subjectId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Attendance marked successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        function deleteActivityLog(logId) {
            if (!confirm('Delete this activity log?')) return;
            fetch('delete_activity_log.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `log_id=${logId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Log deleted!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        function viewUserDetails(userId) {
            console.log('Fetching details for user ID:', userId);
            
            fetch('get_user_details.php?user_id=' + userId)
            .then(response => {
                console.log('Response status:', response.status);
                return response.text();
            })
            .then(text => {
                console.log('Response text:', text);
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        displayUserDetailsModal(data.user, data.attendance, data.sessions, data.issues);
                    } else {
                        alert('Error: ' + data.message);
                    }
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    console.error('Response was:', text);
                    alert('Error parsing response. Check console for details.');
                }
            })
            .catch(error => {
                alert('Error loading user details: ' + error);
                console.error('Fetch Error:', error);
            });
        }

        // Populate and show Edit modal for a student
        function editUser(userId) {
            if (!userId) {
                alert('Invalid user ID');
                return;
            }
            fetch('staff_get_user.php?user_id=' + encodeURIComponent(userId))
                .then(resp => resp.json())
                .then(data => {
                    if (!data || !data.success || !data.user) {
                        throw new Error((data && data.message) || 'Failed to load user');
                    }
                    const u = data.user;
                    const idEl = document.getElementById('edit_user_id');
                    const nameEl = document.getElementById('edit_name');
                    const admEl = document.getElementById('edit_admission_number');
                    const yearEl = document.getElementById('edit_year');
                    const pwdEl = document.getElementById('edit_password');
                    if (!idEl || !nameEl || !admEl || !yearEl) {
                        alert('Edit form not found');
                        return;
                    }
                    idEl.value = u.id || '';
                    nameEl.value = u.name || '';
                    admEl.value = u.admission_number || '';
                    yearEl.value = u.year || '';
                    if (pwdEl) pwdEl.value = '';

                    const modalEl = document.getElementById('editUserModal');
                    if (!modalEl) {
                        alert('Edit modal not found');
                        return;
                    }
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                })
                .catch(err => {
                    console.error('editUser error:', err);
                    alert('Error fetching user details: ' + err.message);
                });
        }

        // Save edits to student via staff endpoint
        function handleSaveEdit() {
            const form = document.getElementById('editUserForm');
            if (!form) {
                alert('Edit form not found');
                return false;
            }
            const id = document.getElementById('edit_user_id').value;
            const name = document.getElementById('edit_name').value.trim();
            const adm = document.getElementById('edit_admission_number').value.trim();
            const year = document.getElementById('edit_year').value.trim();
            const pwdEl = document.getElementById('edit_password');
            const password = pwdEl ? pwdEl.value : '';

            if (!id || !name) {
                alert('Please fill required fields');
                return false;
            }

            const fd = new FormData();
            fd.append('id', id);
            fd.append('name', name);
            fd.append('admission_number', adm);
            fd.append('year', year);
            if (password) fd.append('password', password);

            const saveBtn = document.getElementById('saveEditBtn');
            if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }

            fetch('staff_update_student.php', {
                method: 'POST',
                body: fd
            })
            .then(resp => resp.json())
            .then(data => {
                if (data && data.success) {
                    showToast('Student updated successfully', 'success');
                    const modalEl = document.getElementById('editUserModal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                        modal.hide();
                    }
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    showToast((data && data.message) || 'Update failed', 'danger');
                }
            })
            .catch(err => {
                console.error('handleSaveEdit error:', err);
                showToast('Network error: ' + err.message, 'danger');
            })
            .finally(() => {
                if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save changes'; }
            });

            return false;
        }

        

        function displayUserDetailsModal(user, attendance, sessions, issues) {
            const modalHtml = `
                <div class="modal fade" id="userDetailsModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">User Details - ${user.name}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Basic Information -->
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">Basic Information</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Name:</strong> ${user.name}</p>
                                                <p><strong>Role:</strong> <span class="badge bg-primary">${user.role.toUpperCase()}</span></p>
                                                <p><strong>Admission Number:</strong> ${user.admission_number || 'N/A'}</p>
                                                <p><strong>Batch:</strong> ${user.year || 'N/A'}</p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Total Lab Hours:</strong> ${user.total_hours}</p>
                                                <p><strong>Total Sessions:</strong> ${user.total_sessions}</p>
                                                <p><strong>Last Login:</strong> ${user.last_login || 'Never'}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Attendance Summary -->
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">Attendance Summary</h6>
                                        <div class="row text-center">
                                            <div class="col-md-3">
                                                <div class="p-3 bg-success rounded">
                                                    <h4 class="mb-0">${attendance.present || 0}</h4>
                                                    <small>Present</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="p-3 bg-warning rounded">
                                                    <h4 class="mb-0">${attendance.late || 0}</h4>
                                                    <small>Late</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="p-3 bg-danger rounded">
                                                    <h4 class="mb-0">${attendance.absent || 0}</h4>
                                                    <small>Absent</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="p-3 bg-info rounded">
                                                    <h4 class="mb-0">${attendance.total || 0}</h4>
                                                    <small>Total Days</small>
                                                </div>
                                            </div>
                                        </div>
                                        ${attendance.percentage !== null ? 
                                            `<div class="mt-3 text-center">
                                                <h5>Attendance Percentage: <span class="badge ${attendance.percentage >= 75 ? 'bg-success' : 'bg-danger'}">${attendance.percentage}%</span></h5>
                                            </div>` : ''
                                        }
                                    </div>
                                </div>

                                <!-- Recent Sessions -->
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">Recent Login Sessions</h6>
                                        ${sessions.length > 0 ? `
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Login Time</th>
                                                            <th>Logout Time</th>
                                                            <th>Duration</th>
                                                            <th>Subject</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        ${sessions.map(session => `
                                                            <tr>
                                                                <td>${session.date}</td>
                                                                <td>${session.login_time}</td>
                                                                <td>${session.logout_time || 'N/A'}</td>
                                                                <td>${session.duration}</td>
                                                                <td>${session.subject_name || 'N/A'}</td>
                                                            </tr>
                                                        `).join('')}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ` : '<p class="text-muted">No sessions found</p>'}
                                    </div>
                                </div>

                                ${issues.length > 0 ? `
                                <!-- Reported Issues -->
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">Reported Issues</h6>
                                        ${issues.map(issue => `
                                            <div class="alert alert-${issue.status === 'pending' ? 'warning' : 'success'} mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <strong>System ${issue.system_number}</strong> - ${issue.description}
                                                    </div>
                                                    <span class="badge bg-${issue.status === 'pending' ? 'warning' : 'success'}">${issue.status}</span>
                                                </div>
                                                <small class="text-muted">Reported: ${issue.created_at}</small>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('userDetailsModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('userDetailsModal'));
            modal.show();
            
            // Remove modal from DOM when closed
            document.getElementById('userDetailsModal').addEventListener('hidden.bs.modal', function () {
                this.remove();
            });
        }

        function addSubject() {
            const name = document.getElementById('newSubjectName').value.trim();
            const code = document.getElementById('newSubjectCode').value.trim();
            
            if (!name) {
                showToast('Please enter a subject name', 'error');
                return;
            }
            
            fetch('manage_subjects.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=add_subject&subject_name=${encodeURIComponent(name)}&subject_code=${encodeURIComponent(code)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Subject added successfully!', 'success');
                    location.reload();
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            });
        }

        function deleteSubject(subjectId) {
            if (!confirm('Delete this subject?')) return;
            fetch('manage_subjects.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_subject&subject_id=${subjectId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Subject deleted successfully!', 'success');
                    location.reload();
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            });
        }

        function deleteLogsByDay() {
            const date = document.getElementById('deleteLogsDay').value;
            if (!date) {
                alert('Please select a date');
                return;
            }
            if (!confirm(`Delete all logs from ${date}?`)) return;
            
            fetch('delete_activity_logs_by_date.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `date=${date}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Deleted ${data.count} logs`);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        function deleteLogsByRange() {
            const start = document.getElementById('deleteLogsStart').value;
            const end = document.getElementById('deleteLogsEnd').value;
            if (!start || !end) {
                alert('Please select both dates');
                return;
            }
            if (!confirm(`Delete all logs from ${start} to ${end}?`)) return;
            
            fetch('delete_activity_logs_by_range.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `start_date=${start}&end_date=${end}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Deleted ${data.count} logs`);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }


        function downloadAttendanceReport(type) {
            if (type === 'daily') {
                const date = document.getElementById('attendanceDayDate').value;
                const subjectId = document.getElementById('attendanceSubjectSelect').value || '';
                const batch = document.getElementById('attendanceBatchSelect').value || '';
                if (date) window.location.href = `export_report_pdf.php?type=attendance&period=daily&date=${date}${subjectId ? `&subject_id=${subjectId}` : ''}${batch ? `&batch=${encodeURIComponent(batch)}` : ''}`;
            } else {
                const start = document.getElementById('attendanceStartDate').value;
                const end = document.getElementById('attendanceEndDate').value;
                const subjectId = document.getElementById('attendanceSubjectSelect').value || '';
                const batch = document.getElementById('attendanceBatchSelect').value || '';
                if (start && end) window.location.href = `export_report_pdf.php?type=attendance&period=range&start_date=${start}&end_date=${end}${subjectId ? `&subject_id=${subjectId}` : ''}${batch ? `&batch=${encodeURIComponent(batch)}` : ''}`;
            }
        }

        // MANAGE PAST ATTENDANCE FUNCTIONS
        function loadPastAttendance() {
            const subjectId = document.getElementById('pastSubject').value;
            const startDate = document.getElementById('pastDate').value;
            const batch = document.getElementById('pastBatchSelect') ? document.getElementById('pastBatchSelect').value : '';
            const searchQuery = document.getElementById('searchPastStudent').value;
            
            if (!subjectId) {
                alert('Please select a subject');
                return;
            }
            
            if (!startDate) {
                alert('Please select a start date');
                return;
            }
            
            const resultsDiv = document.getElementById('pastAttendanceResults');
            resultsDiv.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            
            const start = new Date(startDate);
            const end = new Date(start);
            end.setDate(start.getDate() + 6);
            const endDate = end.toISOString().split('T')[0];
            
            const formData = new FormData();
            formData.append('action', 'load_past_attendance');
            formData.append('subject_id', subjectId);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            formData.append('batch', batch);
            formData.append('search_query', searchQuery);
            
            window.lastAttendanceLoad = { subjectId, startDate, batch, searchQuery };
            
            fetch('ajax_past_attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.text();
            })
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    
                    if (data.success) {
                        displayPastAttendance(data.attendance, data.dates, startDate, data.subject_name || 'Unknown Subject');
                    } else {
                        resultsDiv.innerHTML = '<div class="alert alert-warning">' + data.message + '</div>';
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Full response:', text);
                    resultsDiv.innerHTML = '<div class="alert alert-danger">Error: Server returned invalid response. Check console for details.</div>';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                resultsDiv.innerHTML = '<div class="alert alert-danger">Error loading attendance data: ' + error.message + '</div>';
            });
        }

        function displayPastAttendance(attendance, dates, startDate, subjectName) {
            const resultsDiv = document.getElementById('pastAttendanceResults');
            
            if (!attendance || attendance.length === 0) {
                resultsDiv.innerHTML = '<div class="alert alert-info mt-3">No attendance records found for the selected period and subject</div>';
                return;
            }
            
            let html = `
                <div class="bg-dark p-4 rounded mt-3">
                    <h5 class="text-white mb-2" style="font-weight: 700; font-size: 1.25rem;">Week Attendance (${startDate} onwards - 7 days)</h5>
                    <p class="text-white mb-4" style="font-weight: 600;"><strong>Subject:</strong> ${subjectName}</p>
                    <div class="table-responsive" style="overflow-x: auto;">
                        <table class="table table-dark table-hover table-bordered" style="font-size: 14px; min-width: 100%;">
                            <thead>
                                <tr style="position: sticky; top: 0; background: #1e293b; z-index: 10;">
                                    <th style="position: sticky; left: 0; background: #1e293b; z-index: 11; min-width: 150px; font-weight: 700; color: #fff;">STUDENT NAME</th>
                                    <th style="background: #1e293b; min-width: 120px; font-weight: 700; color: #fff;">ADMISSION NO.</th>
            `;
            
            dates.forEach(date => {
                const dateObj = new Date(date);
                const formattedDate = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit' });
                html += `<th class="text-center" style="min-width: 160px; background: #1e293b; font-weight: 700; color: #fff;">${formattedDate}</th>`;
            });
            
            html += `
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            attendance.forEach(student => {
                html += `
                    <tr>
                        <td style="position: sticky; left: 0; background: #2d3748; font-weight: 700; min-width: 150px; color: #fff;">${student.name}</td>
                        <td style="background: #2d3748; min-width: 120px; font-weight: 600; color: #fff;">${student.admission_number}</td>
                `;
                
                dates.forEach(date => {
                    const record = student.records[date];
                    
                    html += `<td class="text-center p-2" style="background: #2d3748;">`;
                    
                    if (record && record.subject_id) {
                        let statusBadge = '';
                        if (record.status) {
                            let badgeClass = '';
                            let statusText = '';
                            
                            const statusLower = String(record.status).toLowerCase().trim();
                            
                            if (statusLower === 'present') {
                                badgeClass = 'bg-success';
                                statusText = 'PRESENT';
                            } else if (statusLower === 'late') {
                                badgeClass = 'bg-warning text-dark';
                                statusText = 'LATE';
                            } else if (statusLower === 'absent') {
                                badgeClass = 'bg-danger';
                                statusText = 'ABSENT';
                            } else {
                                badgeClass = 'bg-secondary';
                                statusText = String(record.status).toUpperCase();
                            }
                            
                            statusBadge = `<div class="mb-2"><span class="badge ${badgeClass}" style="font-weight: 700; font-size: 0.75rem;">${statusText}</span></div>`;
                        }
                        
                        html += statusBadge;
                        html += `
                            <div class="btn-group btn-group-sm" role="group">
                                <button class="btn btn-success" style="padding: 5px 10px; font-size: 0.75rem; font-weight: 700;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'present')">P</button>
                                <button class="btn btn-warning" style="padding: 5px 10px; font-size: 0.75rem; font-weight: 700; color: #000;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'late')">L</button>
                                <button class="btn btn-danger" style="padding: 5px 10px; font-size: 0.75rem; font-weight: 700;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'absent')">A</button>
                                ${record.status ? `<button class="btn btn-secondary" style="padding: 5px 10px; font-size: 0.75rem; font-weight: 700;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'unmark')">U</button>` : ''}
                            </div>
                        `;
                    } else {
                        html += '<span class="text-muted" style="font-size: 0.85rem; font-weight: 500; color: #adb5bd !important;">No login</span>';
                    }
                    
                    html += `</td>`;
                });
                
                html += `</tr>`;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info mt-3 mb-0" style="font-weight: 600; font-size: 0.95rem;">
                        <strong>Legend:</strong> P = Present | L = Late | A = Absent | U = Unmark
                    </div>
                </div>
            `;
            
            resultsDiv.innerHTML = html;
        }

        function markPastAttendance(studentId, subjectId, date, status) {
            if (!subjectId || subjectId === 0 || subjectId === '0') {
                alert('Cannot mark attendance: No subject ID found. Student may not have logged in on this date.');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'mark_past_attendance');
            formData.append('student_id', studentId);
            formData.append('subject_id', subjectId);
            formData.append('date', date);
            formData.append('status', status);
            
            fetch('ajax_past_attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        setTimeout(() => {
                            loadPastAttendance();
                        }, 500);
                    } else {
                        alert('Error: ' + data.message);
                        console.error('Server error:', data.message);
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Response was:', text);
                    alert('Server response error. Check console for details.');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                alert('Error marking attendance: ' + error.message);
            });
        }

        function downloadActivityReport(period) {
            // Get filter values
            const subjectId = document.getElementById('activitySubjectSelect') ? document.getElementById('activitySubjectSelect').value : '';
            const batch = document.getElementById('activityBatchSelect') ? document.getElementById('activityBatchSelect').value : '';
            
            if (period === 'daily') {
                const date = document.getElementById('dailyActivityDate').value;
                if (!date) {
                    showToast('Please select a date', 'error');
                    return;
                }
                let url = `export_report_pdf.php?type=activity&period=daily&date=${date}`;
                if (subjectId) url += `&subject_id=${encodeURIComponent(subjectId)}`;
                if (batch) url += `&batch=${encodeURIComponent(batch)}`;
                window.location.href = url;
            } else if (period === 'range') {
                const start = document.getElementById('activityStartDate').value;
                const end = document.getElementById('activityEndDate').value;
                if (!start || !end) {
                    showToast('Please select both start and end dates', 'error');
                    return;
                }
                let url = `export_report_pdf.php?type=activity&period=range&start_date=${start}&end_date=${end}`;
                if (subjectId) url += `&subject_id=${encodeURIComponent(subjectId)}`;
                if (batch) url += `&batch=${encodeURIComponent(batch)}`;
                window.location.href = url;
            }
        }

        function downloadSystemUsageReport(period) {
            if (period === 'daily') {
                const date = document.getElementById('dailySystemUsageDate').value;
                if (!date) {
                    showToast('Please select a date', 'error');
                    return;
                }
                window.location.href = `export_system_usage_report.php?period=daily&date=${date}`;
            } else if (period === 'range') {
                const start = document.getElementById('systemUsageStartDate').value;
                const end = document.getElementById('systemUsageEndDate').value;
                if (!start || !end) {
                    showToast('Please select both start and end dates', 'error');
                    return;
                }
                window.location.href = `export_system_usage_report.php?period=range&start_date=${start}&end_date=${end}`;
            }
        }
        // Function to generate trend report
        function generateTrend() {
            const startDate = document.getElementById('trend_start_date').value;
            const endDate = document.getElementById('trend_end_date').value;

            if (!startDate || !endDate) {
                alert('Please select both a start and end date.');
                return;
            }

            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after the end date.');
                return;
            }

            // Redirect to the PDF generation script
            window.location.href = `generate_trend_pdf_simple.php?start=${startDate}&end=${endDate}`;
        }
            document.addEventListener('DOMContentLoaded', function() {
                const today = new Date();
                const year = today.getFullYear();
                const month = today.getMonth();

                const startDate = new Date(year, month, 1).toISOString().split('T')[0];
                const endDate = today.toISOString().split('T')[0];

                document.getElementById('trend_start_date').value = startDate;
                document.getElementById('trend_end_date').value = endDate;
            });

        // ============================================
        // SYSTEM USAGE TRACKING
        // ============================================
        // Generates and stores a unique System ID for this computer
        // Tracks login, heartbeat, and logout actions
        
        // Generate or retrieve System ID from localStorage
        function getSystemId() {
            let systemId = localStorage.getItem('system_id');
            if (!systemId) {
                // Generate a unique System ID: SYS- followed by random alphanumeric string
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                let randomPart = '';
                for (let i = 0; i < 8; i++) {
                    randomPart += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                systemId = 'SYS-' + randomPart;
                localStorage.setItem('system_id', systemId);
            }
            return systemId;
        }
        
        // Get IP address (will be captured server-side, but we can try client-side detection)
        function getIpAddress() {
            // IP will be captured server-side via $_SERVER['REMOTE_ADDR']
            return '';
        }
        
        // Send system usage update to server
        function updateSystemUsage(action) {
            const systemId = getSystemId();
            const formData = new FormData();
            formData.append('action', action);
            formData.append('system_id', systemId);
            
            // Use sendBeacon for logout (more reliable when page is closing)
            if (action === 'logout') {
                const data = new URLSearchParams();
                data.append('action', action);
                data.append('system_id', systemId);
                
                if (navigator.sendBeacon) {
                    navigator.sendBeacon('update_usage.php', data);
                } else {
                    // Fallback to fetch if sendBeacon not available
                    fetch('update_usage.php', {
                        method: 'POST',
                        body: formData,
                        keepalive: true
                    }).catch(err => console.error('Logout tracking error:', err));
                }
            } else {
                // Use fetch for login and heartbeat
                fetch('update_usage.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        console.error('System usage tracking error:', data.message);
                    }
                })
                .catch(error => {
                    console.error('System usage tracking error:', error);
                });
            }
        }
        
        // Initialize: Send login action on page load
        updateSystemUsage('login');
        
        // Set up heartbeat: Update every 60 seconds
        const heartbeatInterval = setInterval(() => {
            updateSystemUsage('heartbeat');
        }, 60000); // 60 seconds
        
        // Handle logout: Send logout action when user logs out
        const logoutForms = document.querySelectorAll('form[action*="logout"], form[method="POST"]');
        logoutForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                // Only handle if it's the logout form and not already handled by handleLogout
                if (form.id === 'logoutForm' && !form.dataset.logoutHandled) {
                    updateSystemUsage('logout');
                    if (typeof heartbeatInterval !== 'undefined' && heartbeatInterval) {
                        clearInterval(heartbeatInterval);
                    }
                }
            });
        });
        
        // Handle browser/tab close: Send logout action
        window.addEventListener('beforeunload', function() {
            updateSystemUsage('logout');
            clearInterval(heartbeatInterval);
        });
        
        // Handle page visibility change: If page becomes hidden, stop heartbeat
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden, but keep heartbeat running (user might return)
                // We'll mark as inactive if no heartbeat for 5 minutes (handled server-side)
            } else {
                // Page is visible again, send heartbeat immediately
                updateSystemUsage('heartbeat');
            }
        });
        </script>
        <script src="assets/js/electron-bridge.js"></script>
    <div style="margin-top:40px;background:#020617;border-top:1px solid #1f2937;padding:12px 0;text-align:center;font-size:13px;color:#e5e7eb;letter-spacing:0.08em;text-transform:uppercase;">
	    &copy; <?php echo date('Y'); ?> All rights reserved - Team BBAJ
	    </div>
    </body>
</html>