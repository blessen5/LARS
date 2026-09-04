<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$success = '';
$error = '';

// Database configuration
 $db_host = 'localhost';
 $db_user = 'root';
 $db_pass = '';
 $db_name = 'LARS';
// Create DB connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Get college name from database
$college_name = 'Your College Name'; // Default value
$check_table = $conn->query("SHOW TABLES LIKE 'system_settings'");
if ($check_table && $check_table->num_rows > 0) {
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'college_name' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $college_name = $row['setting_value'];
    }
}

// Get admin information
$user_id = $_SESSION['user_id'];
$admin_name = "Admin";
$admin_username = "admin";
$stmt = $conn->prepare("SELECT name, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $user_data = $result->fetch_assoc();
    $admin_name = $user_data['name'] ?? $user_data['username'];
    if (!empty($user_data['username'])) { $admin_username = $user_data['username']; }
}
$stmt->close();

// Check current timer status
$timer_paused = false;
$check_table = "SHOW TABLES LIKE 'system_settings'";
$result = $conn->query($check_table);
if ($result && $result->num_rows > 0) {
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'timer_paused'";
    $res2 = $conn->query($sql);
    if ($res2 && $res2->num_rows > 0) {
        $row = $res2->fetch_assoc();
        $timer_paused = ($row['setting_value'] === '1');
    }
}

// Calculate admin total usage
$session_start = time();
$sql = "SELECT login_time FROM login_activity WHERE user_id = ? ORDER BY login_time DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
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
// Fetch currently active students - FIXED TO SHOW ONLY ONE ENTRY PER STUDENT
// Fetch currently active staff - ADD THIS SECTION
$active_staff = [];
$sql = "SELECT u.id, u.name, u.username, MAX(la.login_time) as login_time 
        FROM login_activity la 
        JOIN users u ON la.user_id = u.id 
        WHERE u.role = 'staff' 
        AND la.login_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) 
        GROUP BY u.id, u.name, u.username
        ORDER BY login_time DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $active_staff[] = $row;
    }
}
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

// Fetch pending staff registration requests (after DB connection established)
$pending_staff = [];
$check_pending_tbl = $conn->query("SHOW TABLES LIKE 'pending_staff_requests'");
if ($check_pending_tbl && $check_pending_tbl->num_rows > 0) {
    $res = $conn->query("SELECT id, name, username, requested_at FROM pending_staff_requests ORDER BY requested_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pending_staff[] = $row;
        }
    }
}

// Fetch students for attendance (today) - one row per student using latest login and latest subject session today
$attendance_students = [];
$today = date('Y-m-d');
$sql = "
    SELECT 
        u.id, 
        u.name, 
        u.admission_number,
        lus.subject_name,
        lus.subject_id,
        CASE 
            WHEN MAX(la.login_time) <= CONCAT(?, ' 09:00:00') THEN 'On Time'
            ELSE 'Late'
        END AS status,
        a.status AS marked_status
    FROM users u
    JOIN login_activity la 
        ON la.user_id = u.id 
       AND DATE(la.login_time) = ?
    LEFT JOIN (
        SELECT 
            us1.user_id,
            us1.subject_id,
            s.subject_name,
            us1.session_start AS last_session
        FROM user_sessions us1
        JOIN (
            SELECT user_id, MAX(session_start) AS max_session
            FROM user_sessions
            WHERE DATE(session_start) = ?
            GROUP BY user_id
        ) lm ON lm.user_id = us1.user_id AND lm.max_session = us1.session_start
        JOIN subjects s ON s.id = us1.subject_id
    ) lus ON lus.user_id = u.id
    LEFT JOIN attendance a 
        ON a.user_id = u.id 
       AND a.date = ? 
       AND a.subject_id = lus.subject_id
    WHERE u.role = 'student'
    GROUP BY u.id, u.name, u.admission_number, lus.subject_name, lus.subject_id, a.status
    ORDER BY MAX(la.login_time) DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssss", $today, $today, $today, $today);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $attendance_students[] = $row;
}
$stmt->close();

// Fetch login/logout times (include subject at time of login and login date)
$login_times = [];
$sql = "SELECT 
                u.id AS user_id,
                u.name,
                u.admission_number,
                u.year,
                la1.login_time,
                COALESCE(us.session_end, la2.login_time, NOW()) AS logout_time,
                /* duration is until logout if exists, else until now */
                TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(us.session_end, la2.login_time, NOW())) AS duration,
                /* mark active when both session_end and next login are missing */
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
                AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
        WHERE u.role = 'student'
        ORDER BY la1.login_time DESC
        LIMIT 200";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $login_times[] = $row;
    }
}

// Fetch active system issues
$active_issues = [];
$sql = "SELECT i.*, u.name, u.admission_number 
        FROM issues i 
        JOIN users u ON i.user_id = u.id 
        WHERE i.status = 'pending' 
        ORDER BY i.created_at DESC LIMIT 10";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $active_issues[] = $row;
    }
}

// Fetch password reset requests
$password_requests = [];
$sql = "SELECT prr.*, u.name, u.admission_number, u.username, u.role 
        FROM password_reset_requests prr
        JOIN users u ON prr.user_id = u.id 
        WHERE prr.status = 'pending' 
        ORDER BY prr.requested_at DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $password_requests[] = $row;
    }
}

// Fetch student activity logs only (including other activities where subject_id is NULL)
$student_activity_logs = [];
$sql = "SELECT al.id, al.created_at, u.name, u.admission_number, al.log_text, u.year, al.subject_id, s.subject_name
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

// Fetch admin activity logs
$admin_activity_logs = [];
$sql = "SELECT al.id, al.created_at, u.name, u.username, al.log_text 
        FROM activity_logs al 
        JOIN users u ON al.user_id = u.id 
        WHERE u.role = 'admin' AND DATE(al.created_at) = CURDATE()
        ORDER BY al.created_at DESC LIMIT 200";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $admin_activity_logs[] = $row;
    }
}

// Fetch all users
$all_users = [];
$sql = "SELECT u.id, u.name, u.admission_number, u.username, u.role, u.year,
        SUM(TIMESTAMPDIFF(SECOND, la1.login_time, COALESCE(la2.login_time, NOW()))) as total_seconds
        FROM users u
        LEFT JOIN login_activity la1 ON u.id = la1.user_id
        LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
            AND la2.id = (SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1)
        GROUP BY u.id
        ORDER BY u.role, u.name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_users[] = $row;
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


// Fetch live system usage data
$live_system_usage = [];
$check_table = "SHOW TABLES LIKE 'system_usage'";
$result = $conn->query($check_table);
if ($result && $result->num_rows > 0) {
    $sql = "SELECT * FROM system_usage ORDER BY last_active DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $live_system_usage[] = $row;
        }
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
    <title>Admin Dashboard - <?php echo htmlspecialchars($college_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        * {
            font-family: 'Poppins', sans-serif !important;
        }
        body {
            font-family: 'Poppins', sans-serif !important;
        }
        
        /* Light Mode Styles */
        :root {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --bg-card: #0f3460;
            --text-primary: #ffffff;
            --text-secondary: #e0e0e0;
            --border-color: #2d3748;
        }

        html[data-theme='light'] {
            --bg-primary: #ffffff;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #212529;
            --text-secondary: #495057;
            --border-color: #dee2e6;
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
            background-color: #6a7380 !important;
            color: #ffffff !important;
        }

        html[data-theme='light'] .table-dark tbody tr:hover td {
            background-color: #6a7380 !important;
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

        /* Modal theming to match dashboard colors */
        /* Dark / Normal mode */
        html:not([data-theme='light']) .modal-content,
        html:not([data-theme='light']) #editUserModal .modal-content {
            background-color: var(--bg-card) !important;
            color: var(--text-primary) !important;
            border: 1px solid var(--border-color) !important;
        }
        html:not([data-theme='light']) .modal-header,
        html:not([data-theme='light']) .modal-footer,
        html:not([data-theme='light']) #editUserModal .modal-header,
        html:not([data-theme='light']) #editUserModal .modal-footer {
            border-color: var(--border-color) !important;
            color: var(--text-primary) !important;
        }
        html:not([data-theme='light']) #editUserModal .form-control,
        html:not([data-theme='light']) #editUserModal .form-select {
            background-color: #1f2a44 !important;
            color: var(--text-primary) !important;
            border-color: var(--border-color) !important;
        }
        html:not([data-theme='light']) #editUserModal .form-control::placeholder {
            color: #94a3b8 !important;
        }

        /* Light mode modal overrides to ensure consistency */
        html[data-theme='light'] #editUserModal .modal-content {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }
        html[data-theme='light'] #editUserModal .form-control,
        html[data-theme='light'] #editUserModal .form-select {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #ced4da !important;
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

        /* Sharpen card borders and admin control lines */
        .card, .card-header, .card-body {
            border: 1px solid var(--border-color) !important;
            box-shadow: none !important;
            border-radius: 6px !important;
        }

        /* Admin controls accordion: crisp borders and no shadows */
        #adminControlsAccordion .accordion-item {
            border: 1px solid var(--border-color) !important;
            box-shadow: none !important;
            border-radius: 6px !important;
            overflow: hidden;
        }

        #adminControlsAccordion .accordion-button {
            border-bottom: 1px solid var(--border-color) !important;
        }

        /* Light mode: force dark text inside Admin Controls */
        html[data-theme='light'] #adminControlsAccordion .accordion-body,
        html[data-theme='light'] #adminControlsAccordion .accordion-body p,
        html[data-theme='light'] #adminControlsAccordion .accordion-body h1,
        html[data-theme='light'] #adminControlsAccordion .accordion-body h2,
        html[data-theme='light'] #adminControlsAccordion .accordion-body h3,
        html[data-theme='light'] #adminControlsAccordion .accordion-body h4,
        html[data-theme='light'] #adminControlsAccordion .accordion-body h5,
        html[data-theme='light'] #adminControlsAccordion .accordion-body h6,
        html[data-theme='light'] #adminControlsAccordion .accordion-body label,
        html[data-theme='light'] #adminControlsAccordion .accordion-body small,
        html[data-theme='light'] #adminControlsAccordion .accordion-body strong,
        html[data-theme='light'] #adminControlsAccordion .accordion-body span,
        html[data-theme='light'] #adminControlsAccordion .accordion-body .text-white,
        html[data-theme='light'] #adminControlsAccordion .accordion-body .text-light {
            color: #212529 !important;
        }
        /* Light mode: any dark backgrounds inside Admin Controls should be white */
        html[data-theme='light'] #adminControlsAccordion .accordion-body .bg-dark,
        html[data-theme='light'] #adminControlsAccordion .accordion-body .bg-secondary {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }

        /* Make table cell separators crisper */
        .table th, .table td {
            border-top: 1px solid var(--border-color) !important;
            border-bottom: 1px solid var(--border-color) !important;
        }

        /* Remove subtle translucency so lines appear solid */
        .card, .accordion-item, .table {
            background-clip: padding-box !important;
        }

        html[data-theme='light'] .border-secondary {
            border-color: var(--border-color) !important;
        }

        html[data-theme='light'] .request-item {
            background-color: #e9ecef !important;
            color: var(--text-primary) !important;
        }

        /* Modal improvements for light mode */
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

        /* Manage Subjects table styling - Modern UI with dark/light mode support */
        /* Base table styling with rounded corners and shadow */
        #manageSubjects .table {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-collapse: separate;
            border-spacing: 0;
        }
        
        /* Table header with blue accent (#0078d7) */
        #manageSubjects .table thead th {
            background-color: #0078d7 !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            padding: 12px 16px !important;
            border: none !important;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.5px;
        }
        
        /* Table body cells - padding and borders */
        #manageSubjects .table tbody td {
            padding: 12px 16px !important;
            border-top: 1px solid rgba(0, 0, 0, 0.1) !important;
            vertical-align: middle;
        }
        
        /* Alternating row colors - Light mode */
        html[data-theme='light'] #manageSubjects .table tbody tr {
            background-color: #ffffff !important;
            color: #212529 !important;
        }
        
        html[data-theme='light'] #manageSubjects .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f8f9fa !important;
        }

        html[data-theme='light'] #manageSubjects .table-striped tbody tr:nth-of-type(even) {
            background-color: #ffffff !important;
        }
        
        html[data-theme='light'] #manageSubjects .table tbody tr:hover {
            background-color: #e3f2fd !important;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        html[data-theme='light'] #manageSubjects .table tbody tr:hover td {
            background-color: #e3f2fd !important;
        }
        
        html[data-theme='light'] #manageSubjects .table tbody td {
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }

        /* Alternating row colors - Dark mode (fixes black row issue) */
        html:not([data-theme='light']) #manageSubjects .table tbody tr {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
        }
        
        html:not([data-theme='light']) #manageSubjects .table-striped tbody tr:nth-of-type(odd) {
            background-color: #243445 !important;
        }
        
        html:not([data-theme='light']) #manageSubjects .table-striped tbody tr:nth-of-type(even) {
            background-color: #1e293b !important;
        }
        
        html:not([data-theme='light']) #manageSubjects .table tbody tr:hover {
            background-color: #2d3748 !important;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        html:not([data-theme='light']) #manageSubjects .table tbody tr:hover td {
            background-color: #2d3748 !important;
        }
        
        html:not([data-theme='light']) #manageSubjects .table tbody td {
            color: #e2e8f0 !important;
            border-color: #2d3748 !important;
        }
        
        /* Ensure no black rows appear in dark mode - strong override */
        html:not([data-theme='light']) #manageSubjects .table tbody tr,
        html:not([data-theme='light']) #manageSubjects .table tbody tr td {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
        }
        
        html:not([data-theme='light']) #manageSubjects .table-striped tbody tr:nth-of-type(odd),
        html:not([data-theme='light']) #manageSubjects .table-striped tbody tr:nth-of-type(odd) td {
            background-color: #243445 !important;
        }
        
        html:not([data-theme='light']) #manageSubjects .table-striped tbody tr:nth-of-type(even),
        html:not([data-theme='light']) #manageSubjects .table-striped tbody tr:nth-of-type(even) td {
            background-color: #1e293b !important;
        }
        
        /* Action buttons styling */
        #manageSubjects .table tbody .btn {
            border-radius: 4px;
            padding: 6px 12px;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }
        
        #manageSubjects .table tbody .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        /* Past Attendance improvements for light mode */
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

        html[data-theme='light'] #pastAttendanceResults h5,
        html[data-theme='light'] #pastAttendanceResults p {
            color: #212529 !important;
        }

        html[data-theme='light'] #pastAttendanceResults .alert-info {
            background-color: #cff4fc;
            color: #055160;
        }
/* Fix all table-dark tables in cards for light mode */
        html[data-theme='light'] .card .table-dark,
        html[data-theme='light'] .card .table-dark tbody,
        html[data-theme='light'] .card .table-dark tbody tr {
            background-color: #ffffff !important;
        }

        html[data-theme='light'] .card .table-dark tbody td {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }

        html[data-theme='light'] .card .table-dark thead {
            background-color: #0d6efd !important;
        }

        html[data-theme='light'] .card .table-dark thead th {
            background-color: #0d6efd !important;
            color: #ffffff !important;
            border-color: #0d6efd !important;
        }

         /* Table hover for all tables */
        html[data-theme='light'] table.table-hover tbody tr:hover,
        html[data-theme='light'] .table.table-hover tbody tr:hover,
        html[data-theme='light'] .table-hover tbody tr:hover,
        html[data-theme='light'] .table.table-hover.table-dark tbody tr:hover,
        html[data-theme='light'] .table.table-hover.table-dark tbody tr:hover td,
        html[data-theme='light'] .card .table-dark.table-hover tbody tr:hover,
        html[data-theme='light'] .card .table-dark.table-hover tbody tr:hover td {
            background-color: #6a7380 !important;
            color: #ffffff !important;
        }

        /* Fix striped tables in light mode */
        html[data-theme='light'] .table-striped tbody tr:nth-of-type(odd) {
            background-color: #ffffff !important;
        }

        html[data-theme='light'] .table-striped tbody tr:nth-of-type(even) {
            background-color: #f8f9fa !important;
        }

        html[data-theme='light'] .table-striped tbody tr:nth-of-type(odd) td,
        html[data-theme='light'] .table-striped tbody tr:nth-of-type(even) td {
            color: #212529 !important;
        }

        .request-item {
            background: #2d3748 !important;
            transition: background 0.2s ease;
        }
        .request-item:hover {
            background: #374151 !important;
        }
        .issue-item {
            background: #2d3748 !important;
            transition: all 0.3s ease;
            border: 1px solid #374151 !important;
        }
        .issue-item:hover {
            background: #374151 !important;
            border-color: #4b5563 !important;
            transform: translateX(2px);
        }
        /* Active System Issues text clarity in dark mode */
        html:not([data-theme='light']) .issue-item strong,
        html:not([data-theme='light']) .issue-item span,
        html:not([data-theme='light']) .issue-item small {
            color: #e2e8f0 !important;
        }
        html:not([data-theme='light']) .issue-item .text-muted {
            color: #cbd5e1 !important;
        }
        html[data-theme='light'] .issue-item {
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
        }
        html[data-theme='light'] .issue-item:hover {
            background-color: #e9ecef !important;
            border-color: #adb5bd !important;
        }
        .empty-state-small {
            padding: 1rem;
            text-align: center;
            color: #9ca3af;
        }
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

        /* Usage display styling */
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

        html[data-theme='light'] .empty-state {
            color: #6c757d;
        }

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

        /* Calendar Icon Visibility for Date Inputs */
        /* Make calendar icon visible in date input fields */
        input[type="date"] {
            position: relative;
            padding-right: 35px !important;
        }

        /* Webkit browsers (Chrome, Safari, Edge) */
        input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 1 !important;
            filter: invert(0) !important;
            width: 20px;
            height: 20px;
            margin-right: 5px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
        }

        /* Dark mode - make icon white/light */
        html:not([data-theme='light']) input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1) brightness(1.5) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
        }

        /* Light mode - make icon dark */
        html[data-theme='light'] input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(0) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
        }

        /* Firefox - calendar icon styling */
        input[type="date"]::-moz-calendar-picker-indicator {
            cursor: pointer;
            opacity: 1 !important;
            filter: invert(0) !important;
        }

        html:not([data-theme='light']) input[type="date"]::-moz-calendar-picker-indicator {
            filter: invert(1) brightness(1.5) !important;
        }

        html[data-theme='light'] input[type="date"]::-moz-calendar-picker-indicator {
            filter: invert(0) !important;
        }

        /* Ensure date inputs have enough padding for icon */
        input[type="date"].form-control {
            padding-right: 40px !important;
 }

        /* Pending Staff Requests: improve readability */
        #pendingStaffRequests .table {
            border: 1px solid var(--border-color) !important;
        }
        /* Dark (normal) mode */
        html:not([data-theme='light']) #pendingStaffRequests .table-dark thead {
            background-color: #0d6efd !important;
            color: #ffffff !important;
        }
        html:not([data-theme='light']) #pendingStaffRequests .table-dark thead th {
            color: #ffffff !important;
            border-color: #0d6efd !important;
        }
        html:not([data-theme='light']) #pendingStaffRequests .table-dark tbody tr {
            background-color: #1f2937 !important;
            color: #e2e8f0 !important;
        }
        html:not([data-theme='light']) #pendingStaffRequests .table-dark tbody tr:hover td {
            background-color: #374151 !important;
            color: #ffffff !important;
        }
        html:not([data-theme='light']) #pendingStaffRequests .table-dark td {
            border-color: #374151 !important;
        }
        /* Light mode */
        html[data-theme='light'] #pendingStaffRequests .table-dark {
            background-color: #ffffff !important;
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }
        html[data-theme='light'] #pendingStaffRequests .table-dark thead {
            background-color: #0d6efd !important;
            color: #ffffff !important;
        }
        html[data-theme='light'] #pendingStaffRequests .table-dark tbody td {
            color: #212529 !important;
            border-color: #dee2e6 !important;
        }
        #pendingStaffRequests h5 { font-weight: 600; }
        /* Dark mode: pending section empty state text should be white */
        html:not([data-theme='light']) #pendingStaffRequests .text-muted,
        html:not([data-theme='light']) #pendingStaffRequests .empty-state,
        html:not([data-theme='light']) #pendingStaffRequests {
            color: #e2e8f0 !important;
        }
    </style>
</head>
<body>
<!-- Toast Container - Add this right after <body> tag -->
<div id="toastContainer" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 11"></div>
    <!-- Header -->
    <header class="header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h1 class="header-title">Lab Activity Reporting System</h1>
                <div class="header-actions d-flex align-items-center gap-3 flex-wrap">
                    <span class="user-info">User: <?php echo htmlspecialchars($admin_name); ?> | Role: admin</span>
                    <button class="btn btn-secondary" id="themeToggle" onclick="toggleTheme()" title="Toggle Dark/Light Mode">
                        <span id="themeIcon">☀️</span>
                    </button>
                    <button class="btn btn-warning" id="pauseBtn" onclick="toggleTimer()">
                        <?php echo $timer_paused ? 'Resume Timer' : 'Pause Timer'; ?>
                    </button>
                    <button class="btn btn-success" onclick="minimizeWindow()">Continue</button>
                    <form method="POST" class="d-inline">
                        <button type="submit" name="logout" class="btn btn-danger" onclick="return confirm('Are you sure?');">Logout and Shutdown</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

   <!-- Main Content -->
    <div class="container-fluid mt-4 px-4">
        <h2 class="greeting">Hi, <?php echo htmlspecialchars($admin_name); ?></h2> 

<?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- My Total Lab Usage -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">My Total Lab Usage</h3>
                <div class="usage-display" id="timerDisplay"><?php echo $usage_display; ?></div>
                <?php if ($timer_paused): ?>
                <span class="timer-status" id="timerStatus">⏸️ PAUSED</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Currently Active Staff -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">Currently Active Staff</h3>
                <?php if (empty($active_staff)): ?>
                    <p class="empty-state">No staffs are currently active.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>Staff Name</th>
                                    <th>Username</th>
                                    <th>Login Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_staff as $staff): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($staff['name']); ?></td>
                                        <td><?php echo htmlspecialchars($staff['username']); ?></td>
                                        <td><?php echo date('H:i:s', strtotime($staff['login_time'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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

        <!-- Live System Usage -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="card-title mb-0">🖥️ Live System Usage</h3>
                    <small class="text-muted" id="systemUsageLastUpdate">Last updated: Just now</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover" id="liveSystemUsageTable">
                        <thead>
                            <tr>
                                <th>System ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>IP Address</th>
                                <th>Last Active</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="liveSystemUsageBody">
                            <?php if (empty($live_system_usage)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No system usage data available.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($live_system_usage as $usage): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($usage['system_id'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($usage['username'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $usage['role'] === 'student' ? 'info' : 'warning'; ?>">
                                                <?php echo ucfirst($usage['role']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($usage['ip_address'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            if ($usage['last_active']) {
                                                $dt_utc = new DateTime($usage['last_active'], new DateTimeZone('UTC'));
                                                $last_active = $dt_utc->getTimestamp();
                                                $time_diff = time() - $last_active;
                                                if ($time_diff < 60) {
                                                    echo 'Just now';
                                                } elseif ($time_diff < 3600) {
                                                    echo floor($time_diff / 60) . ' minutes ago';
                                                } elseif ($time_diff < 86400) {
                                                    echo floor($time_diff / 3600) . ' hours ago';
                                                } else {
                                                    $dt_local = new DateTime('@' . $last_active);
                                                    $dt_local->setTimezone(new DateTimeZone(date_default_timezone_get()));
                                                    echo $dt_local->format('d-m-Y H:i');
                                                }
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            // Determine status dynamically based on last_active time
                                            // If last_active is within 2 minutes, consider it active
                                            $is_really_active = false;
                                            if ($usage['last_active']) {
                                                $dt_utc = new DateTime($usage['last_active'], new DateTimeZone('UTC'));
                                                $last_active = $dt_utc->getTimestamp();
                                                $time_diff = time() - $last_active;
                                                $is_really_active = ($time_diff < 120); // 2 minutes = 120 seconds
                                            }
                                            
                                            // Use database status, but override if last_active is recent
                                            $display_status = ($usage['status'] === 'active' || $is_really_active);
                                            ?>
                                            <?php if ($display_status): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
                            // Use all users to build the batch list so the filter shows all defined batches
                            $user_batches = array_unique(array_filter(array_column($all_users, 'year')));
                            // Sort descending so recent batches appear first
                            rsort($user_batches);
                            foreach ($user_batches as $batch):
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
                                        if (!empty($time['is_active'])) {
                                            echo 'Active';
                                        } else {
                                            echo date('d-m-Y, H:i', strtotime($time['logout_time']));
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $dur = (int)($time['duration'] ?? 0);
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

        <!-- System Status & Requests -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">System Status & Requests</h3>
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="text-white">Active System Issues</h5>
                        <?php if (empty($active_issues)): ?>
                            <p class="empty-state-small">No active issues.</p>
                        <?php else: ?>
                            <?php foreach ($active_issues as $issue): ?>
                                <div class="issue-item mb-2 p-2 bg-dark rounded d-flex justify-content-between align-items-center" id="issue_<?php echo $issue['id']; ?>">
                                    <div class="flex-grow-1">
                                        <strong class="text-white"><?php echo htmlspecialchars($issue['system_number'] ?? ''); ?></strong>:
                                        <span class="text-light"><?php echo htmlspecialchars(substr((string)($issue['description'] ?? ''), 0, 50)) . '...'; ?></span>
                                        <br>
                                        <small class="text-muted">Reported by: <?php echo htmlspecialchars($issue['name'] ?? ''); ?> (<?php echo htmlspecialchars($issue['admission_number'] ?? ''); ?>)</small>
                                    </div>
                                    <button class="btn btn-sm btn-success ms-2" onclick="fixIssue(<?php echo (int)$issue['id']; ?>, this)" style="white-space: nowrap;">
                                        <i class="bi bi-check-circle"></i> Fixed
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <h5 class="text-white">Password Reset Requests 
                            <?php if (count($password_requests) > 0): ?>
                                <span class="badge bg-warning text-dark"><?php echo count($password_requests); ?></span>
                            <?php endif; ?>
                        </h5>
                        <?php if (empty($password_requests)): ?>
                            <p class="empty-state-small">No pending requests.</p>
                        <?php else: ?>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($password_requests as $request): ?>
                                    <div class="request-item mb-3 p-3 border border-secondary rounded">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong class="text-white" style="font-size: 1.1em;"><?php echo htmlspecialchars($request['name']); ?></strong>
                                                <br>
                                                <small class="text-light">
                                                    <?php 
                                                    if ($request['role'] === 'student') {
                                                        echo 'Admission: ' . htmlspecialchars($request['admission_number']);
                                                    } else {
                                                        echo 'Username: ' . htmlspecialchars($request['username']);
                                                    }
                                                    ?>
                                                    | Role: <?php echo htmlspecialchars(ucfirst($request['role'])); ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    Requested: <?php echo date('d-m-Y H:i', strtotime($request['requested_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="password" class="form-control bg-dark text-white border-secondary" 
                                                   id="newPassword_<?php echo $request['id']; ?>" 
                                                   placeholder="Enter new password (min 6 chars)"
                                                   style="font-size: 0.95em;">
                                            <button class="btn btn-outline-light" type="button"
                                                    onclick="togglePasswordVisibility(<?php echo $request['id']; ?>)"
                                                    style="border-color: #6c757d;">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                                                    <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                                    <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-success" 
                                                    onclick="approvePasswordReset(<?php echo $request['id']; ?>)"
                                                    style="min-width: 130px;">
                                                Approve & Reset
                                            </button>
                                            <button class="btn btn-sm btn-danger" 
                                                    onclick="rejectPasswordReset(<?php echo $request['id']; ?>)"
                                                    style="min-width: 80px;">
                                                Reject
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Activity Logs -->
        <div class="card mb-4">
            <div class="card-body">
                        <h3 class="card-title">Student Activity Logs</h3>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <input type="text" id="activitySearchStudent" class="form-control" placeholder="Search logs (student name, admission, text)" />
                            </div>
                            <div class="col-md-3">
                                <input type="date" id="activityDateStudent" class="form-control" value="<?php echo date('Y-m-d'); ?>" />
                            </div>
                            <div class="col-md-3">
                                <select class="form-select attendance-batch-select" id="activityBatchStudent">
                                    <option value="">All Batches</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex">
                                <button class="btn btn-primary ms-auto" type="button" onclick="filterActivityLogs('student')">Filter</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover" id="activityTableStudent">
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

        <!-- Admin Activity Logs -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title">Admin Activity Logs</h3>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <input type="text" id="activitySearchAdmin" class="form-control" placeholder="Search logs (admin name, username, text)" />
                    </div>
                    <div class="col-md-3">
                        <input type="date" id="activityDateAdmin" class="form-control" value="<?php echo date('Y-m-d'); ?>" />
                    </div>
                    <div class="col-md-3 d-flex">
                        <button class="btn btn-primary ms-auto" type="button" onclick="filterActivityLogs('admin')">Filter</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover" id="activityTableAdmin">
                        <thead>
                            <tr>
                                <th>TIMESTAMP</th>
                                <th>ADMIN</th>
                                <th>ACTIVITY</th>
                                <th>ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admin_activity_logs as $log): ?>
                                <tr>
                                    <td><?php echo date('d-m-Y, H:i', strtotime($log['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['name']); ?> (<?php echo htmlspecialchars($log['username']); ?>)</td>
                                    <td><?php echo htmlspecialchars($log['log_text']); ?></td>
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

        <!-- Admin Controls Accordion -->
        <div class="card mb-4">
            <div class="card-body">
                <h3 class="card-title mb-4">Admin Controls</h3>
                <div class="accordion" id="adminControlsAccordion">
                    
                    <!-- Manage College Name -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manageCollege">
                                Manage College Name
                            </button>
                        </h2>
                        <div id="manageCollege" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h5 class="card-title">Update College Name</h5>
                                        <div class="mb-3">
                                            <label for="collegeName" class="form-label">College Name</label>
                                            <input type="text" class="form-control" id="collegeName" value="<?php echo htmlspecialchars($college_name ?? 'Your College Name'); ?>">
                                        </div>
                                        <button class="btn btn-primary" onclick="updateCollegeName()">Update College Name</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- View and Manage Users -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manageUsers">
                                View and Manage Users
                            </button>
                        </h2>
                        <div id="manageUsers" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <!-- First row with search and role filters -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Search Users</label>
                                        <input type="text" class="form-control" id="searchUsers" placeholder="Search by name or username..." onkeyup="filterUsersTable()">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Select Role</label>
                                        <select class="form-select" id="filterUserRole" onchange="handleRoleFilter()">
                                            <option value="">All Roles</option>
                                            <option value="student">Student</option>
                                            <option value="staff">Staff</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- Second row for batch filter (visible only when student is selected) -->
                                <div class="row mb-3" id="batchFilterRow" style="display: none;">
                                    <div class="col-md-12">
                                        <label class="form-label">Select Batch</label>
                                        <select class="form-select" id="filterUserBatch" onchange="filterUsersTable()">
                                            <option value="">All Batches</option>
                                            <?php
                                            $user_batches = array_unique(array_filter(array_column($all_users, 'year')));
                                            sort($user_batches);
                                            foreach ($user_batches as $batch):
                                            ?>
                                                <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- Results count -->
                                <div class="mb-3">
                                    <span class="text-muted" id="userCount"></span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover" id="usersTable">
                                        <thead>
                                            <tr>
                                                <th>NAME</th>
                                                <th>USERNAME / ADM NO.</th>
                                                <th>ROLE</th>
                                                <th>BATCH</th>
                                                <th>TOTAL HOURS</th>
                                                <th>ACTIONS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_users as $user): ?>
                                                <tr data-batch="<?php echo htmlspecialchars($user['year'] ?? ''); ?>">
                                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['admission_number'] ?? $user['username']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['year'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php 
                                                        $total = $user['total_seconds'] ?? 0;
                                                        $h = floor($total / 3600);
                                                        $m = floor(($total % 3600) / 60);
                                                        $s = $total % 60;
                                                        echo "{$h}h {$m}m {$s}s";
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary" onclick="viewUserDetails(<?php echo $user['id']; ?>)">View</button>
                                                        <?php if ($user['id'] != $user_id && $user['role'] != 'admin'): ?>
                                                            <button class="btn btn-sm btn-secondary" onclick="editUser(<?php echo $user['id']; ?>)">Edit</button>
                                                            <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">Delete</button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pending Staff Requests -->
                                <div id="pendingStaffRequests" class="mt-4 p-3 border border-warning rounded">
                                    <h5>Pending Staff Requests</h5>
                                    <?php if (!empty($pending_staff)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-dark table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Username</th>
                                                        <th>Requested At</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($pending_staff as $req): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($req['name']); ?></td>
                                                            <td><?php echo htmlspecialchars($req['username']); ?></td>
                                                            <td><?php echo htmlspecialchars($req['requested_at']); ?></td>
                                                            <td>
                                                                <button class="btn btn-sm btn-success" onclick="approveStaffRequest(<?php echo (int)$req['id']; ?>)">Approve</button>
                                                                <button class="btn btn-sm btn-outline-danger" onclick="rejectStaffRequest(<?php echo (int)$req['id']; ?>)">Reject</button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted">No pending staff requests.</div>
                                    <?php endif; ?>
                                </div>

                                                                <!-- User Details Panel (loads when admin clicks View) -->
                                                                <div id="userDetailsPanel" class="mt-3"></div>

                                                                <!-- Edit User Modal -->
                                                                <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
                                                                    <div class="modal-dialog modal-lg">
                                                                        <div class="modal-content">
                                                                            <div class="modal-header">
                                                                                <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                            </div>
                                                                            <div class="modal-body">
                                                                                <form id="editUserForm" onsubmit="return false;">
                                                                                        <input type="hidden" id="edit_user_id" name="id">
                                                                                        <div class="mb-3">
                                                                                                <label class="form-label">Name</label>
                                                                                                <input type="text" class="form-control" id="edit_name" name="name" required>
                                                                                        </div>
                                                                                        <div class="mb-3" id="edit_username_group">
                                                                                                <label class="form-label">Username</label>
                                                                                                <input type="text" class="form-control" id="edit_username" name="username">
                                                                                        </div>
                                                                                        <div class="mb-3" id="edit_adm_group">
                                                                                                <label class="form-label">Admission Number</label>
                                                                                                <input type="text" class="form-control" id="edit_admission_number" name="admission_number">
                                                                                        </div>
                                                                                        <div class="mb-3">
                                                                                                <label class="form-label">Role</label>
                                                                                                <select class="form-select" id="edit_role" name="role">
                                                                                                        <option value="student">Student</option>
                                                                                                        <option value="staff">Staff</option>
                                                                                                        <option value="admin">Admin</option>
                                                                                                </select>
                                                                                        </div>
                                                                                        <div class="mb-3" id="edit_batch_group">
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

                                <!-- Create Student Account -->
                                <div class="mt-4 p-3 border border-secondary rounded">
                                    <h5>Create Account</h5>
                                    <form id="createUserForm" onsubmit="createUser(event)">
                                        <div class="mb-3">
                                            <label class="form-label">Full Name</label>
                                            <input type="text" class="form-control" id="create_name" name="name" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Role</label>
                                            <select class="form-select" id="create_role" name="role" onchange="toggleCreateFields()" required>
                                                <option value="">-- Select Role --</option>
                                                <option value="student">Student</option>
                                                <option value="staff">Staff</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                        </div>

                                        <div class="mb-3" id="create_admission_group" style="display:none;">
                                            <label class="form-label">Admission Number</label>
                                            <input type="text" class="form-control" id="create_admission_number" name="admission_number">
                                        </div>

                                        <div class="mb-3" id="create_username_group" style="display:none;">
                                            <label class="form-label">Username</label>
                                            <input type="text" class="form-control" id="create_username" name="username">
                                        </div>

                                        <div class="mb-3" id="create_department_group" style="display:none;">
                                            <label class="form-label">Department</label>
                                            <input type="text" class="form-control" id="create_department" name="department" placeholder="e.g., Computer Science">
                                        </div>

                                        <div class="row" id="create_student_extras" style="display:none;">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Start Year</label>
                                                <input type="number" class="form-control" id="create_start_year" name="start_year" min="2000" max="2099" onchange="calculateCreateBatch()">
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Duration (Years)</label>
                                                <select class="form-select" id="create_duration" name="duration" onchange="calculateCreateBatch()">
                                                    <option value="">-- Select Duration --</option>
                                                    <option value="2">2 Years</option>
                                                    <option value="3">3 Years</option>
                                                    <option value="4">4 Years</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Batch (End Year)</label>
                                                <input type="text" class="form-control" id="create_batch" name="batch" readonly placeholder="Auto-calculated">
                                            </div>
                                        </div>

                                        <input type="hidden" id="create_year" name="year">

                                        <div class="mb-3">
                                            <label class="form-label">Password</label>
                                            <input type="password" class="form-control" id="create_password" name="password" required>
                                        </div>

                                        <button type="submit" class="btn btn-success w-100">Create Account</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reset Usage Time -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#resetUsage">
                                Reset Usage Time
                            </button>
                        </h2>
                        <div id="resetUsage" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Select Role</label>
                                        <select class="form-control" id="resetRoleSelect" onchange="handleRoleChange()">
                                            <option value="">-- Select Role --</option>
                                            <option value="staff">Staff</option>
                                            <option value="student">Student</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12" id="staffSelection" style="display: none;">
                                        <label class="form-label">Select Staff</label>
                                        <select class="form-control" id="staffSelect">
                                            <option value="">-- Loading Staff... --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12" id="batchSelection" style="display: none;">
                                        <label class="form-label">Select Batch</label>
                                        <select class="form-control" id="batchSelect" onchange="handleBatchChange()">
                                            <option value="">-- Loading Batches... --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12" id="studentSelection" style="display: none;">
                                        <label class="form-label">Select Student</label>
                                        <select class="form-control" id="studentSelect">
                                            <option value="">-- Select Batch First --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
                                        <button class="btn btn-danger w-100" id="resetUsageBtn" onclick="resetUsageTime()" disabled>
                                            Reset Usage Time
                                        </button>
                                    </div>
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
                        <div id="pastAttendance" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
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
                                        <label class="form-label text-light">Batch / Year (optional)</label>
                                        <select id="pastAttendanceBatchSelect" class="form-select bg-dark text-white border-secondary attendance-batch-select">
                                            <option value="">-- Select Batch (optional) --</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-light">Select Student (optional)</label>
                                        <select id="pastStudentSelect" class="form-select bg-dark text-white border-secondary">
                                            <option value="">-- Select Student (optional) --</option>
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
                        <div id="activityReports" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <div class="mb-3">
                                    <label class="form-label">Filter by Student</label>
                                    <input id="activityStudentSearch" type="text" class="form-control" placeholder="Type to search for a student...">
                                </div>
                                <div class="mb-3 row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Subject (optional)</label>
                                        <select id="activitySubjectSelect" class="form-select">
                                            <option value="">All Subjects</option>
                                            <?php foreach ($all_subjects as $sub): ?>
                                                <option value="<?php echo $sub['id']; ?>"><?php echo htmlspecialchars($sub['subject_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Batch / Year (optional)</label>
                                        <select id="activityBatchSelect" class="form-select">
                                            <option value="">All Batches</option>
                                        </select>
                                    </div>
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
                        <div id="attendanceReports" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <div class="mb-3">
                                    <label class="form-label">Filter by Student (Optional)</label>
                                    <input id="attendanceStudentSearch" type="text" class="form-control" placeholder="Type to search...">
                                </div>
                                <div class="mb-3 row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Subject (optional)</label>
                                        <select id="attendanceSubjectSelect" class="form-select">
                                            <option value="">All Subjects</option>
                                            <?php foreach ($all_subjects as $sub): ?>
                                                <option value="<?php echo $sub['id']; ?>"><?php echo htmlspecialchars($sub['subject_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Batch / Year (optional)</label>
                                        <select id="attendanceBatchSelect" class="form-select attendance-batch-select">
                                            <option value="">All Batches</option>
                                        </select>
                                    </div>
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
                        <div id="systemUsage" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
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

                    <!-- Trend Report -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#trendReport">
                                Trend Report
                            </button>
                        </h2>
                        <div id="trendReport" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label">From Date</label>
                                        <input type="date" class="form-control" id="trendStartDate">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">To Date</label>
                                        <input type="date" class="form-control" id="trendEndDate">
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button class="btn btn-purple w-100" onclick="generateTrend()">Generate</button>
                                    </div>
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
                        <div id="manageSubjects" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <h5 class="mb-3">Add New Subject</h5>
                                <div class="mb-3">
                                    <input type="text" class="form-control mb-2" id="newSubjectName" placeholder="Subject Name (required)">
                                    <input type="text" class="form-control mb-2" id="newSubjectCode" placeholder="Subject Code">
                                    <button class="btn btn-success w-100" onclick="addSubject()">Add Subject</button>
                                </div>
                                
                                <h5 class="mt-4 mb-3">Existing Subjects</h5>
                                <div class="table-responsive">
                                    <table class="table table-dark table-striped">
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

                    <!-- Manage College Logo -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manageLogo">
                                Manage College Logo
                            </button>
                        </h2>
                        <div id="manageLogo" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="logo-preview">
                                        <div class="logo-circle">Logo</div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <label class="form-label">Choose Image</label>
                                        <input type="file" class="form-control" id="logoFile" accept="image/png,image/jpeg,image/jpg">
                                        <button class="btn btn-primary w-100 mt-2" onclick="uploadLogo(this)">Update Logo</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Update My Account -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#updateAccount">
                                Update My Account
                            </button>
                        </h2>
                        <div id="updateAccount" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
                            <div class="accordion-body">
                                <form id="updateAccountForm" onsubmit="updateAccount(event)">
                                    <div class="mb-3">
                                        <label class="form-label">My Name</label>
                                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($admin_name); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">My Username</label>
                                        <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($admin_username); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Current Password</label>
                                        <input type="password" class="form-control" name="current_password">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">New Password (leave blank to keep current)</label>
                                        <input type="password" class="form-control" name="new_password">
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">Save My Changes</button>
                                </form>
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
                        <div id="manageLogs" class="accordion-collapse collapse" data-bs-parent="#adminControlsAccordion">
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

                </div>
            </div>
        </div>
    </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to update college name
        function updateCollegeName() {
            const collegeName = document.getElementById('collegeName').value.trim();
            if (!collegeName) {
                showToast('warning', 'Warning', 'Please enter a college name');
                return;
            }

            // Show loading state
            const btn = document.querySelector('#manageCollege button[onclick="updateCollegeName()"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...';

            // Log the request being sent
            console.log('Sending request to update college name:', collegeName);
            
            // Send AJAX request to update college name
            fetch('update_college_name.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'college_name=' + encodeURIComponent(collegeName),
                credentials: 'same-origin' // Ensure cookies are sent with the request
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    return response.text().then(text => {
                        console.error('Error response:', text);
                        throw new Error(`HTTP error! status: ${response.status}, body: ${text}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data && data.success) {
                    showToast('success', 'Success', 'College name updated successfully');
                    // Update the page title to reflect the new college name
                    document.title = 'Admin Dashboard - ' + collegeName;
                } else {
                    throw new Error(data ? data.message : 'Invalid response from server');
                }
            })
            .catch(error => {
                console.error('Error details:', error);
                const errorMessage = error.message || 'Failed to update college name. Please check the console for details.';
                console.error('Full error:', error);
                showToast('danger', 'Error', errorMessage);
            })
            .finally(() => {
                // Reset button state
                btn.disabled = false;
                btn.innerHTML = 'Update College Name';
            });
        }

        // Show toast notification
        function showToast(type, title, message) {
            const toastContainer = document.getElementById('toastContainer');
            const toastId = 'toast-' + Date.now();
            
            const toast = document.createElement('div');
            toast.className = `toast show align-items-center text-bg-${type} border-0`;
            toast.role = 'alert';
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            toast.id = toastId;
            
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <strong>${title}</strong><br>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            // Auto-remove toast after 5 seconds
            setTimeout(() => {
                const toastElement = document.getElementById(toastId);
                if (toastElement) {
                    toastElement.remove();
                }
            }, 5000);
        }

        // Define editUser and deleteUser functions early to ensure they're available
        window.editUser = function(userId) {
            console.log('editUser called with userId:', userId);
            if (!userId) {
                alert('Invalid user ID');
                return;
            }
            fetch(`ajax_get_user.php?id=${userId}`)
                .then(resp => {
                    if (!resp.ok) {
                        throw new Error('Network response was not ok: ' + resp.status);
                    }
                    return resp.json();
                })
                .then(data => {
                    console.log('User data received:', data);
                    if (!data.success) {
                        alert(data.message || 'Error fetching user');
                        return;
                    }
                    const u = data.user;
                    if (!u) {
                        alert('User data not found');
                        return;
                    }
                    const editUserIdEl = document.getElementById('edit_user_id');
                    const editNameEl = document.getElementById('edit_name');
                    const editUsernameEl = document.getElementById('edit_username');
                    const editAdmissionNumberEl = document.getElementById('edit_admission_number');
                    const editRoleEl = document.getElementById('edit_role');
                    const editYearEl = document.getElementById('edit_year');
                    
                    if (!editUserIdEl || !editNameEl || !editRoleEl) {
                        alert('Edit form elements not found. Please refresh the page.');
                        return;
                    }
                    
                    editUserIdEl.value = u.id || '';
                    editNameEl.value = u.name || '';
                    if (editUsernameEl) editUsernameEl.value = u.username || '';
                    if (editAdmissionNumberEl) editAdmissionNumberEl.value = u.admission_number || '';
                    editRoleEl.value = u.role || 'student';
                    if (editYearEl) editYearEl.value = u.year || '';

                    // Toggle fields based on role
                    if (typeof toggleEditFields === 'function') {
                        toggleEditFields();
                    }

                    const modalEl = document.getElementById('editUserModal');
                    if (!modalEl) {
                        alert('Edit modal not found. Please refresh the page.');
                        return;
                    }
                    
                    // Use Bootstrap Modal
                    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        const modal = new bootstrap.Modal(modalEl);
                        modal.show();
                    } else {
                        // Fallback: show modal manually
                        modalEl.style.display = 'block';
                        modalEl.classList.add('show');
                        document.body.classList.add('modal-open');
                        const backdrop = document.createElement('div');
                        backdrop.className = 'modal-backdrop fade show';
                        backdrop.id = 'editModalBackdrop';
                        document.body.appendChild(backdrop);
                    }
                })
                .catch(err => {
                    console.error('Edit user error:', err);
                    alert('Error fetching user details: ' + err.message);
                });
        };

        window.deleteUser = function(userId) {
            console.log('deleteUser called with userId:', userId);
            if (!userId) {
                alert('Invalid user ID');
                return;
            }
            if (!confirm('Delete this user? This action cannot be undone.')) return;
            fetch('delete_user.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `user_id=${userId}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    if (typeof showToast === 'function') {
                        showToast('User deleted successfully', 'success');
                    } else {
                        alert('User deleted successfully');
                    }
                    setTimeout(() => location.reload(), 1000);
                } else {
                    if (typeof showToast === 'function') {
                        showToast('Error: ' + (data.message || 'Failed to delete user'), 'danger');
                    } else {
                        alert('Error: ' + (data.message || 'Failed to delete user'));
                    }
                }
            })
            .catch(error => {
                console.error('Delete user error:', error);
                if (typeof showToast === 'function') {
                    showToast('Error deleting user: ' + error.message, 'danger');
                } else {
                    alert('Error deleting user: ' + error.message);
                }
            });
        };

        // Define handleSaveEdit function early to ensure it's available
        window.handleSaveEdit = function(event) {
            // Prevent any default form submission
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            alert('Save button clicked!'); // Immediate feedback
            console.log('Save button clicked - handleSaveEdit function called');
            
            const form = document.getElementById('editUserForm');
            if (!form) {
                alert('Edit form not found');
                console.error('Edit form element not found');
                return false;
            }
            
            // Prevent form default submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }, true);
            
            // Validate form
            const formData = new FormData(form);
            const userId = formData.get('id');
            const name = formData.get('name');
            const role = formData.get('role');
            
            console.log('Form data extracted:', {
                id: userId,
                name: name,
                role: role,
                username: formData.get('username'),
                admission_number: formData.get('admission_number'),
                year: formData.get('year')
            });
            
            if (!userId || !name || !role) {
                alert('Please fill in all required fields (ID: ' + userId + ', Name: ' + name + ', Role: ' + role + ')');
                console.error('Validation failed - missing required fields');
                return false;
            }
            
            // Disable save button to prevent double submission
            const saveBtn = document.getElementById('saveEditBtn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
            }
            
            console.log('Sending fetch request to ajax_update_user.php');
            
            fetch('ajax_update_user.php', { 
                method: 'POST', 
                body: formData 
            })
            .then(resp => {
                console.log('Response received, status:', resp.status, resp.statusText);
                if (!resp.ok) {
                    return resp.text().then(text => {
                        console.error('Error response text:', text);
                        throw new Error('Network response was not ok: ' + resp.status + ' - ' + text);
                    });
                }
                return resp.json();
            })
            .then(data => {
                console.log('Response data parsed:', data);
                if (data.success) {
                    alert('User updated successfully!');
                    // hide modal
                    const modalEl = document.getElementById('editUserModal');
                    if (modalEl) {
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const modal = bootstrap.Modal.getInstance(modalEl);
                            if (modal) {
                                modal.hide();
                            } else {
                                // Fallback: hide manually
                                modalEl.style.display = 'none';
                                modalEl.classList.remove('show');
                                document.body.classList.remove('modal-open');
                                const backdrop = document.getElementById('editModalBackdrop');
                                if (backdrop) backdrop.remove();
                            }
                        } else {
                            // Fallback: hide manually
                            modalEl.style.display = 'none';
                            modalEl.classList.remove('show');
                            document.body.classList.remove('modal-open');
                            const backdrop = document.getElementById('editModalBackdrop');
                            if (backdrop) backdrop.remove();
                        }
                    }
                    // refresh page to reflect changes
                    setTimeout(() => location.reload(), 1000);
                } else {
                    alert('Update failed: ' + (data.message || 'Unknown error'));
                    console.error('Update failed:', data);
                    // Re-enable save button
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save changes';
                    }
                }
            })
            .catch(err => {
                console.error('Update user error:', err);
                alert('Error updating user: ' + err.message);
                // Re-enable save button
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save changes';
                }
            });
            
            return false;
        };
    </script>
    <script>
          // Timer functionality - Persistent Background Timer
        // This timer runs continuously even when window is minimized or out of focus
        // Uses localStorage to persist state across page refreshes
        // Stops only on Logout or Shutdown
        
        // Initialize timer state from localStorage or PHP
        const TIMER_STORAGE_KEY = 'admin_timer_state';
        const TIMER_START_KEY = 'admin_timer_start';
        const TIMER_PAUSED_KEY = 'admin_timer_paused';
        
        let timerInterval = null;
        let startTime = null;
        let isTimerPaused = false;
        
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

        // Initialize create-user fields on load
        document.addEventListener('DOMContentLoaded', function() {
            try { toggleCreateFields(); } catch (e) { console.error('toggleCreateFields init error', e); }
        });
        
        // Save timer state to localStorage
        function saveTimerState() {
            if (startTime) {
                localStorage.setItem(TIMER_START_KEY, startTime.toString());
                localStorage.setItem(TIMER_PAUSED_KEY, isTimerPaused.toString());
            }
        }
        
        // Clear timer state from localStorage (called on logout/shutdown)
        function clearTimerState() {
            localStorage.removeItem(TIMER_START_KEY);
            localStorage.removeItem(TIMER_PAUSED_KEY);
            localStorage.removeItem(TIMER_STORAGE_KEY);
        }
        
        // Update timer display - runs every second
        function updateTimer() {
            if (!isTimerPaused && startTime) {
                // Calculate elapsed time using current Date() - works even when tab is in background
                const now = Date.now();
                const elapsedMs = now - startTime;
                const totalSeconds = Math.floor(elapsedMs / 1000);
                
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
            const btn = document.getElementById('pauseBtn');
        if (btn) {
            if (isTimerPaused) {
            btn.textContent = 'Resume Timer';
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-success');
                
                // Show pause status
                if (!document.getElementById('timerStatus')) {
                    const status = document.createElement('span');
                    status.id = 'timerStatus';
                    status.className = 'timer-status';
                    status.textContent = '⏸️ PAUSED';
                    const timerDisplay = document.getElementById('timerDisplay');
                    if (timerDisplay && timerDisplay.parentElement) {
                        timerDisplay.parentElement.appendChild(status);
                    }
                }
            }
        }
        
        // Toggle timer pause/resume
        function toggleTimer() {
            const btn = document.getElementById('pauseBtn');
            if (!btn) return;
            
            isTimerPaused = !isTimerPaused;
            saveTimerState();
            
            if (isTimerPaused) {
                // Pause timer - adjust start time to account for paused duration
                // This ensures when resumed, it continues from where it paused
                const now = Date.now();
                const elapsedMs = now - startTime;
                startTime = now - elapsedMs; // Keep elapsed time but don't increment
                
                btn.textContent = 'Resume Timer';
                btn.classList.remove('btn-warning');
                btn.classList.add('btn-success');
                
                // Show pause status
                if (!document.getElementById('timerStatus')) {
                    const status = document.createElement('span');
                    status.id = 'timerStatus';
                    status.className = 'timer-status';
                    status.textContent = '⏸️ PAUSED';
                    const timerDisplay = document.getElementById('timerDisplay');
                    if (timerDisplay && timerDisplay.parentElement) {
                        timerDisplay.parentElement.appendChild(status);
                    }
                }
                
                // Send pause command to server
                fetch('toggle_system_timer.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=pause'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('All student and staff timers have been paused');
                    }
                })
                .catch(error => console.error('Error:', error));
            } else {
                // Resume timer - adjust start time to continue from where it was
                const now = Date.now();
                const elapsedMs = now - startTime;
                startTime = now - elapsedMs; // Resume from current elapsed time
                
                btn.textContent = 'Pause Timer';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-warning');
                
                // Hide pause status
                const status = document.getElementById('timerStatus');
                if (status) status.remove();
                
                // Send resume command to server
                fetch('toggle_system_timer.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=resume'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('All student and staff timers have been resumed');
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }
        
        // Clear timer on page unload if logout was clicked
        let logoutClicked = false;
        document.addEventListener('DOMContentLoaded', function() {
            const logoutForm = document.querySelector('form[method="POST"]');
            if (logoutForm) {
                const logoutBtn = logoutForm.querySelector('button[name="logout"]');
                if (logoutBtn) {
                    logoutBtn.addEventListener('click', function() {
                        logoutClicked = true;
                        // Clear timer state
                        clearTimerState();
                        // Stop timer interval
                        if (timerInterval) {
                            clearInterval(timerInterval);
                            timerInterval = null;
                        }
                    });
                }
            }
        });
        
        // Also clear on page unload as backup
        window.addEventListener('beforeunload', function() {
            if (logoutClicked) {
                clearTimerState();
                if (timerInterval) {
                    clearInterval(timerInterval);
                }
            }
        });

        function minimizeWindow() {
            if (window.blur) window.blur();
            try {
                window.resizeTo(0, 0);
                window.moveTo(screen.width, screen.height);
            } catch(e) {
                window.blur();
            }
        }

        // Filter functions (server-side fetch)
        function formatDateTime(dtStr) {
            if (!dtStr) return 'N/A';
            // Create a JS Date from 'YYYY-MM-DD HH:MM:SS'
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
                const rows = data.rows || [];
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6">No records found for selected filters.</td></tr>';
                    return;
                }

                let html = '';
                rows.forEach(r => {
                    const loginFormatted = formatDateTime(r.login_time);
                    const logoutFormatted = r.logout_time ? formatDateTime(r.logout_time) : 'N/A';
                    const durationText = r.duration ? `${Math.floor(r.duration/3600)}h ${Math.floor((r.duration%3600)/60)}m ${r.duration%60}s` : 'N/A';
                    html += `<tr data-batch="${r.year || ''}" data-date="${r.login_date || ''}" data-subject="${r.subject_id || ''}">`;
                    html += `<td>${escapeHtml(r.name)}</td>`;
                    html += `<td>${escapeHtml(r.subject_name || 'N/A')}</td>`;
                    html += `<td>${escapeHtml(loginFormatted)}</td>`;
                    html += `<td>${escapeHtml(logoutFormatted)}</td>`;
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

        function approveStaffRequest(id) {
            if (!confirm('Approve this staff request?')) return;
            fetch('approve_staff_request.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(id)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Approved successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to approve'));
                }
            })
            .catch(err => { console.error(err); alert('Network error'); });
        }

        function rejectStaffRequest(id) {
            if (!confirm('Reject this staff request?')) return;
            fetch('reject_staff_request.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(id)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Rejected successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to reject'));
                }
            })
            .catch(err => { console.error(err); alert('Network error'); });
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('create_role')) {
                toggleCreateFields();
            }
            // Send/update System ID for Live System Usage
            try {
                sendSystemId();
                setInterval(sendSystemId, 60000);
            } catch (e) { console.warn('System ID init failed', e); }
        });

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        async function sha256(str) {
            const enc = new TextEncoder();
            const data = enc.encode(str);
            const hash = await crypto.subtle.digest('SHA-256', data);
            return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        async function computeSystemId() {
            const nav = navigator || {};
            const scr = screen || {};
            const parts = [
                nav.userAgent || '',
                nav.platform || '',
                nav.language || '',
                (nav.hardwareConcurrency || ''),
                (nav.deviceMemory || ''),
                (scr.width || '') + 'x' + (scr.height || ''),
                (scr.colorDepth || '')
            ].join('|');
            return await sha256(parts);
        }

        async function sendSystemId() {
            try {
                const systemId = await computeSystemId();
                const params = new URLSearchParams();
                params.set('system_id', systemId);
                params.set('user_agent', navigator.userAgent || '');
                fetch('update_system_id.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                }).catch(() => {});
            } catch (e) {
                console.error('sendSystemId error', e);
            }
        }

        /* Reset button removed per user request; default behavior is to show today's logins on page load. */

        function handleRoleFilter() {
            const roleSelect = document.getElementById('filterUserRole');
            const batchContainer = document.getElementById('batchFilterContainer');
            
            // Show/hide batch filter based on role selection
            if (roleSelect.value === 'student') {
                batchContainer.style.display = 'block';
            } else {
                batchContainer.style.display = 'none';
                document.getElementById('filterUserBatch').value = ''; // Reset batch selection
            }
            
            filterUsersTable(); // Apply filters
        }

        function filterUsersTable() {
            const searchValue = document.getElementById('searchUsers').value.toUpperCase();
            const roleValue = document.getElementById('filterUserRole').value;
            const batchValue = document.getElementById('filterUserBatch').value;
            const table = document.getElementById('usersTable');
            const rows = table.getElementsByTagName('tr');
            
            let visibleCount = 0;
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const name = row.cells[0].textContent.toUpperCase();
                const username = row.cells[1].textContent.toUpperCase();
                const role = row.cells[2].textContent.toLowerCase();
                const batch = row.getAttribute('data-batch');
                
                const matchesSearch = name.indexOf(searchValue) > -1 || username.indexOf(searchValue) > -1;
                const matchesRole = !roleValue || role === roleValue;
                const matchesBatch = !batchValue || batch === batchValue;
                
                const shouldShow = matchesSearch && matchesRole && (!batchValue || (roleValue === 'student' && matchesBatch));
                
                row.style.display = shouldShow ? '' : 'none';
                if (shouldShow) visibleCount++;
            }
            
            // Update count display
            const countDisplay = document.getElementById('userCount');
            countDisplay.textContent = `Showing ${visibleCount} user${visibleCount !== 1 ? 's' : ''}`;
        }

        // Password Reset Functions
        function togglePasswordVisibility(requestId) {
            const input = document.getElementById('newPassword_' + requestId);
            if (input.type === 'password') {
                input.type = 'text';
            } else {
                input.type = 'password';
            }
        }

        function approvePasswordReset(requestId) {
            const passwordInput = document.getElementById('newPassword_' + requestId);
            const newPassword = passwordInput.value.trim();
            
            if (!newPassword) {
                alert('Please enter a new password');
                return;
            }
            
            if (newPassword.length < 6) {
                alert('Password must be at least 6 characters long');
                return;
            }
            
            if (!confirm('Approve this password reset request and set the new password?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('action', 'approve');
            formData.append('new_password', newPassword);
            
            fetch('process_password_reset.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Password reset approved! User can now login with the new password.');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error processing request: ' + error);
                console.error('Error:', error);
            });
        }

        function rejectPasswordReset(requestId) {
            if (!confirm('Reject this password reset request?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('action', 'reject');
            
            fetch('process_password_reset.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Password reset request rejected');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error processing request: ' + error);
                console.error('Error:', error);
            });
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

        // Activity logs filtering via AJAX (student or admin)
        function filterActivityLogs(role) {
            const isStudent = role === 'student';
            const searchEl = document.getElementById(isStudent ? 'activitySearchStudent' : 'activitySearchAdmin');
            const dateEl = document.getElementById(isStudent ? 'activityDateStudent' : 'activityDateAdmin');
            const batchEl = document.getElementById(isStudent ? 'activityBatchStudent' : null);
            const tableId = isStudent ? '#activityTableStudent' : '#activityTableAdmin';

            const searchValue = searchEl ? searchEl.value.trim() : '';
            const dateValue = dateEl ? dateEl.value : '';
            const batchValue = batchEl ? batchEl.value.trim() : '';

            const formData = new URLSearchParams();
            formData.append('role', role || 'student');
            if (dateValue) formData.append('date', dateValue);
            if (searchValue) formData.append('search', searchValue);
            if (batchValue) formData.append('batch', batchValue);

            fetch('ajax_filter_activity_logs.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(resp => resp.json())
            .then(data => {
                const tbody = document.querySelector(tableId + ' tbody');
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
                    if (isStudent) {
                        html += '<td>' + escapeHtml(r.name || '') + '</td>';
                        html += '<td>' + escapeHtml(r.admission_number || '') + '</td>';
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
                    } else {
                        html += '<td>' + escapeHtml(r.name || '') + (r.username ? ' (' + escapeHtml(r.username) + ')' : '') + '</td>';
                        html += '<td>' + escapeHtml(r.log_text || '') + '</td>';
                        html += '<td><button class="btn btn-sm btn-danger" onclick="deleteActivityLog(' + (r.id || 0) + ')">Delete</button></td>';
                    }
                    html += '</tr>';
                });
                tbody.innerHTML = html;
            })
            .catch(err => {
                const tbody = document.querySelector(tableId + ' tbody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="5">Error fetching activity logs</td></tr>';
                console.error('Fetch error:', err);
            });
        }

        // deleteUser is now defined earlier in the script

        function toggleCreateFields() {
            const role = document.getElementById('create_role').value;
            const admissionGroup = document.getElementById('create_admission_group');
            const usernameGroup = document.getElementById('create_username_group');
            const departmentGroup = document.getElementById('create_department_group');
            const studentExtras = document.getElementById('create_student_extras');

            const admissionInput = document.getElementById('create_admission_number');
            const usernameInput = document.getElementById('create_username');
            const departmentInput = document.getElementById('create_department');
            const startYearInput = document.getElementById('create_start_year');
            const durationInput = document.getElementById('create_duration');
            const batchInput = document.getElementById('create_batch');
            const yearInput = document.getElementById('create_year');
            const nameInput = document.getElementById('create_name');
            const passwordInput = document.getElementById('create_password');

            if (role === 'student') {
                admissionGroup.style.display = 'block';
                departmentGroup.style.display = 'block';
                studentExtras.style.display = 'flex';
                usernameGroup.style.display = 'none';

                admissionInput.required = true;
                departmentInput.required = true;
                startYearInput.required = true;
                durationInput.required = true;
                usernameInput.required = false;
                usernameInput.value = '';
            } else if (role === 'staff' || role === 'admin') {
                usernameGroup.style.display = 'block';
                admissionGroup.style.display = 'none';
                departmentGroup.style.display = 'none';
                studentExtras.style.display = 'none';

                usernameInput.required = true;
                admissionInput.required = false;
                departmentInput.required = false;
                startYearInput.required = false;
                durationInput.required = false;

                admissionInput.value = '';
                departmentInput.value = '';
                startYearInput.value = '';
                durationInput.value = '';
                batchInput.value = '';
                yearInput.value = '';

                // Ensure inputs are enabled and editable for admin/staff
                if (usernameInput) {
                    usernameInput.disabled = false;
                    usernameInput.readOnly = false;
                    // Focus the username field to encourage typing
                    try { usernameInput.focus(); } catch (e) {}
                }
                if (nameInput) { nameInput.disabled = false; nameInput.readOnly = false; }
                if (passwordInput) { passwordInput.disabled = false; passwordInput.readOnly = false; }
            } else {
                admissionGroup.style.display = 'none';
                usernameGroup.style.display = 'none';
                departmentGroup.style.display = 'none';
                studentExtras.style.display = 'none';

                admissionInput.required = false;
                usernameInput.required = false;
                departmentInput.required = false;
                startYearInput.required = false;
                durationInput.required = false;
            }
        }

        function calculateCreateBatch() {
            const startYear = document.getElementById('create_start_year').value;
            const duration = document.getElementById('create_duration').value;
            const batchInput = document.getElementById('create_batch');
            const yearInput = document.getElementById('create_year');
            if (startYear && duration) {
                const endYear = parseInt(startYear) + parseInt(duration);
                const batchValue = startYear + '-' + endYear;
                batchInput.value = batchValue;
                yearInput.value = batchValue;
            } else {
                batchInput.value = '';
                yearInput.value = '';
            }
        }

        function createUser(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);

            fetch('create_user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('User created successfully!');
                    form.reset();
                    toggleCreateFields();
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error creating user');
            });
        }

        // Simple in-memory cache to avoid refetching details repeatedly
        window.userDetailsCache = window.userDetailsCache || {};

        function openUserDetailsSkeleton(userId) {
            // Remove existing modal if any
            const existing = document.getElementById('userDetailsModal');
            if (existing) existing.remove();

            const skeleton = `
                <div class="modal fade" id="userDetailsModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content bg-dark text-white">
                            <div class="modal-header border-secondary">
                                <h5 class="modal-title">Loading user details...</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body d-flex align-items-center justify-content-center" style="min-height:200px;">
                                <div class="text-center">
                                    <div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>
                                    <div class="mt-2">Loading details...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', skeleton);
            const modalEl = document.getElementById('userDetailsModal');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            // If modal closed, remove from DOM
            modalEl.addEventListener('hidden.bs.modal', function () { this.remove(); });
        }

        function populateUserDetailsModal(user, attendance, sessions, issues) {
            const modalEl = document.getElementById('userDetailsModal');
            if (!modalEl) return;

            const content = `
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content bg-dark text-white">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title">User Details - ${user.name}</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Basic Information -->
                            <div class="card mb-3 bg-dark text-white border-secondary">
                                <div class="card-body">
                                    <h6 class="card-title text-white mb-3">Basic Information</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="text-white"><strong>Name:</strong> ${user.name}</p>
                                            <p class="text-white"><strong>Role:</strong> <span class="badge bg-primary">${user.role.toUpperCase()}</span></p>
                                            ${user.role === 'student' ? 
                                                `<p class="text-white"><strong>Admission Number:</strong> ${user.admission_number || 'N/A'}</p>
                                                 <p class="text-white"><strong>Batch:</strong> ${user.year || 'N/A'}</p>` : 
                                                `<p class="text-white"><strong>Username:</strong> ${user.username || 'N/A'}</p>`
                                            }
                                        </div>
                                        <div class="col-md-6">
                                            <p class="text-white"><strong>Total Lab Hours:</strong> ${user.total_hours}</p>
                                            <p class="text-white"><strong>Total Sessions:</strong> ${user.total_sessions}</p>
                                            <p class="text-white"><strong>Last Login:</strong> ${user.last_login || 'Never'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            ${user.role === 'student' ? `
                            <!-- Attendance Summary -->
                            <div class="card mb-3 bg-dark text-white border-secondary">
                                <div class="card-body">
                                    <h6 class="card-title text-white mb-3">Attendance Summary</h6>
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <div class="p-3 bg-success text-white rounded">
                                                <h4 class="mb-0">${attendance.present || 0}</h4>
                                                <small class="text-white">Present</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="p-3 bg-warning text-dark rounded">
                                                <h4 class="mb-0">${attendance.late || 0}</h4>
                                                <small class="text-dark">Late</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="p-3 bg-danger text-white rounded">
                                                <h4 class="mb-0">${attendance.absent || 0}</h4>
                                                <small class="text-white">Absent</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="p-3 bg-info text-white rounded">
                                                <h4 class="mb-0">${attendance.total || 0}</h4>
                                                <small class="text-white">Total Days</small>
                                            </div>
                                        </div>
                                    </div>
                                    ${attendance.percentage !== null ? `<div class="mt-3 text-center"><h5 class="text-white">Attendance Percentage: <span class="badge ${attendance.percentage >= 75 ? 'bg-success' : 'bg-danger'}">${attendance.percentage}%</span></h5></div>` : ''}
                                </div>
                            </div>
                            ` : ''}

                            <!-- Recent Sessions -->
                            <div class="card mb-3 bg-dark text-white border-secondary">
                                <div class="card-body">
                                    <h6 class="card-title text-white mb-3">Recent Login Sessions</h6>
                                    ${sessions.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-dark table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Login Time</th>
                                                        <th>Logout Time</th>
                                                        <th>Duration</th>
                                                        ${user.role === 'student' ? '<th>Subject</th>' : ''}
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${sessions.map(session => `
                                                        <tr class="text-white">
                                                            <td>${session.date}</td>
                                                            <td>${session.login_time}</td>
                                                            <td>${session.logout_time || 'N/A'}</td>
                                                            <td>${session.duration}</td>
                                                            ${user.role === 'student' ? `<td>${session.subject_name || 'N/A'}</td>` : ''}
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<p class="text-muted">No sessions found</p>'}
                                </div>
                            </div>

                            ${user.role === 'student' && issues.length > 0 ? `
                            <!-- Reported Issues -->
                            <div class="card mb-3 bg-dark text-white border-secondary">
                                <div class="card-body">
                                    <h6 class="card-title text-white mb-3">Reported Issues</h6>
                                    ${issues.map(issue => `
                                        <div class="alert ${issue.status === 'pending' ? 'alert-warning' : 'alert-success'} mb-2">
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
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            `;

            // replace modal content
            const modalContent = modalEl.querySelector('.modal-content');
            if (modalContent) modalContent.outerHTML = content;

            // Refresh modal reference (Bootstrap attaches listeners to original element)
            const newModalEl = document.getElementById('userDetailsModal');
            // ensure it is shown
            try {
                const bsModal = bootstrap.Modal.getInstance(newModalEl) || new bootstrap.Modal(newModalEl);
                bsModal.show();
            } catch (e) {
                console.error('Error showing populated modal', e);
            }
        }

        function viewUserDetails(userId) {
            // immediate feedback
            openUserDetailsSkeleton(userId);

            // use cache if available
            if (window.userDetailsCache[userId]) {
                setTimeout(() => {
                    const d = window.userDetailsCache[userId];
                    populateUserDetailsModal(d.user, d.attendance, d.sessions, d.issues);
                }, 200); // small delay so skeleton is visible briefly
                return;
            }

            fetch('get_user_details.php?user_id=' + userId)
            .then(response => response.text())
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        // cache and populate
                        window.userDetailsCache[userId] = data;
                        populateUserDetailsModal(data.user, data.attendance, data.sessions, data.issues);
                    } else {
                        const modalEl = document.getElementById('userDetailsModal');
                        if (modalEl) modalEl.querySelector('.modal-body').innerHTML = `<div class="alert alert-danger">Error: ${data.message}</div>`;
                    }
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    const modalEl = document.getElementById('userDetailsModal');
                    if (modalEl) modalEl.querySelector('.modal-body').innerHTML = `<div class="alert alert-danger">Error parsing server response</div>`;
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                const modalEl = document.getElementById('userDetailsModal');
                if (modalEl) modalEl.querySelector('.modal-body').innerHTML = `<div class="alert alert-danger">Network error while loading details</div>`;
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


        function uploadLogo(btn) {
            const fileInput = document.getElementById('logoFile');
            if (!fileInput.files[0]) {
                alert('Please select an image');
                return;
            }
            
            const file = fileInput.files[0];
            
            if (file.size > 200000) {
                alert('File too large. Maximum 200KB');
                return;
            }
            
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!validTypes.includes(file.type)) {
                alert('Invalid file type. Only JPG and PNG allowed');
                return;
            }
            
            const formData = new FormData();
            formData.append('logo', file);
            
            const originalText = btn.textContent;
            btn.textContent = 'Uploading...';
            btn.disabled = true;
            
            fetch('upload_logo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btn.textContent = originalText;
                btn.disabled = false;
                
                if (data.success) {
                    alert('Logo uploaded successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                btn.textContent = originalText;
                btn.disabled = false;
                alert('Upload failed: ' + error);
                console.error('Error:', error);
            });
        }

        function updateAccount(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('update_admin_account.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Account updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
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

        function downloadActivityReport(type) {
            const subjectId = document.getElementById('activitySubjectSelect') ? document.getElementById('activitySubjectSelect').value : '';
            const batch = document.getElementById('activityBatchSelect') ? document.getElementById('activityBatchSelect').value : '';

            if (type === 'daily') {
                const date = document.getElementById('dailyActivityDate').value;
                if (!date) return alert('Please select a date to download the report for.');
                let url = `export_report_pdf.php?type=activity&period=daily&date=${date}`;
                if (subjectId) url += `&subject_id=${encodeURIComponent(subjectId)}`;
                if (batch) url += `&batch=${encodeURIComponent(batch)}`;
                window.location.href = url;
            } else {
                const start = document.getElementById('activityStartDate').value;
                const end = document.getElementById('activityEndDate').value;
                if (!(start && end)) return alert('Please select both start and end dates.');
                let url = `export_report_pdf.php?type=activity&period=range&start_date=${start}&end_date=${end}`;
                if (subjectId) url += `&subject_id=${encodeURIComponent(subjectId)}`;
                if (batch) url += `&batch=${encodeURIComponent(batch)}`;
                window.location.href = url;
            }
        }

        // Mark system issue as fixed and notify reporter - make it globally accessible
        window.fixIssue = function(issueId, btn) {
            console.log('fixIssue called with issueId:', issueId);
            
            if (!issueId) {
                alert('Invalid issue ID');
                return;
            }
            
            if (!confirm('Mark this issue as fixed and notify the reporter?')) return;

            // Disable button and show progress
            if (btn) {
                btn.disabled = true;
                const prevHTML = btn.innerHTML;
                btn.setAttribute('data-prev', prevHTML);
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Fixing...';
            }

            console.log('Sending request to ajax_fix_issue.php with issue_id:', issueId);

            fetch('ajax_fix_issue.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'issue_id=' + encodeURIComponent(issueId),
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(resp => {
                console.log('Response status:', resp.status, resp.statusText);
                // Handle 500 errors specifically
                if (resp.status === 500) {
                    return resp.text().then(text => {
                        console.error('Server Error 500 - Response text:', text);
                        throw new Error('Server error (500). Check PHP error logs. Response: ' + (text.substring(0, 200) || 'No response'));
                    });
                }
                return resp.text().then(text => {
                    console.log('Response text:', text);
                    return { text, resp };
                });
            })
            .then(({ text, resp }) => {
                try {
                    // Trim whitespace from response
                    text = text.trim();
                    
                    // Check if response is empty
                    if (!text) {
                        throw new Error('Empty response from server');
                    }
                    
                    // Try to parse as JSON
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (parseError) {
                        // If JSON parsing fails, show the actual response
                        console.error('JSON parse error:', parseError);
                        console.error('Raw response text:', text);
                        console.error('Response length:', text.length);
                        console.error('First 500 chars:', text.substring(0, 500));
                        
                        // Check for common issues and provide helpful error message
                        var errorMsg = 'Invalid JSON response. Raw response: ' + text.substring(0, 200);
                        var checkPhp = text.indexOf('&lt;?php') >= 0 || text.indexOf('&lt;?') >= 0;
                        var checkHtml = text.indexOf('&lt;html') >= 0 || text.indexOf('&lt;!DOCTYPE') >= 0;
                        var checkError = text.indexOf('Warning:') >= 0 || text.indexOf('Notice:') >= 0 || text.indexOf('Fatal error:') >= 0;
                        
                        if (checkPhp) {
                            errorMsg = 'PHP code returned instead of JSON. Check server configuration.';
                        } else if (checkHtml) {
                            errorMsg = 'HTML page returned instead of JSON. Check if file exists.';
                        } else if (checkError) {
                            errorMsg = 'PHP error in response: ' + text.substring(0, 300);
                        }
                        throw new Error(errorMsg);
                    }
                    
                    console.log('Parsed data:', data);
                    
                    if (data.success) {
                        const el = document.getElementById('issue_' + issueId);
                        if (el) {
                            // Add fade-out animation before removing
                            el.style.transition = 'opacity 0.3s ease-out';
                            el.style.opacity = '0';
                            setTimeout(() => {
                                el.remove();
                                console.log('Issue element removed from DOM');
                                
                                // Check if there are no more issues
                                const issuesContainer = el.parentElement;
                                if (issuesContainer && issuesContainer.children.length === 0) {
                                    issuesContainer.innerHTML = '<p class="empty-state-small">No active issues.</p>';
                                }
                            }, 300);
                        } else {
                            console.warn('Issue element not found in DOM');
                        }
                        const notified = (data.notification_created === true);
                        
                        // Show success toast instead of alert
                        showToast('Issue marked as fixed.' + (notified ? ' The student has been notified.' : ''), 'success');
                    } else {
                        alert('Error: ' + (data.message || 'Failed to update issue'));
                        console.error('Update failed:', data);
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = btn.getAttribute('data-prev') || '<i class="bi bi-check-circle"></i> Fixed';
                        }
                    }
                } catch (e) {
                    console.error('Error processing response:', e);
                    console.error('Full response text:', text);
                    alert('Error: ' + e.message + '\n\nPlease check the browser console (F12) for full details.');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = btn.getAttribute('data-prev') || '<i class="bi bi-check-circle"></i> Fixed';
                    }
                }
            })
            .catch(err => {
                console.error('Network error:', err);
                alert('Network error: ' + err.message + '\n\nPlease check your connection and try again.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = btn.getAttribute('data-prev') || '<i class="bi bi-check-circle"></i> Fixed';
                }
            });
        };

        // Populate batches for attendance filter (robust: handles errors and public fallback)
        function populateAttendanceBatches() {
            // populate all batch selects marked with the attendance-batch-select class
            const batchSelects = Array.from(document.querySelectorAll('.attendance-batch-select'));
            const activitySelect = document.getElementById('activityBatchSelect');
            if (batchSelects.length === 0 && !activitySelect) return;
            const batchErrorEl = document.getElementById('attendanceBatchError');
            if (batchErrorEl) { batchErrorEl.style.display = 'none'; batchErrorEl.textContent = ''; }

            fetch('ajax_get_batches.php', { credentials: 'same-origin' })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
                    return response.json();
                })
                .then(data => {
                    if (!data) throw new Error('Empty JSON response');
                    if (!data.success) throw new Error(data.message || 'Failed to load batches');
                    const batches = data.batches || [];
                    if (batches.length === 0) {
                        if (batchErrorEl) { batchErrorEl.style.display = ''; batchErrorEl.textContent = 'No batches found.'; }
                        return;
                    }

                    // For each batch select, preserve its first default option if present
                    batchSelects.forEach(sel => {
                        const defaultOpt = sel.options && sel.options.length ? sel.options[0].outerHTML : '<option value="">-- Select Batch --</option>';
                        let html = defaultOpt;
                        batches.forEach(b => { html += `<option value="${b}">${b}</option>`; });
                        sel.innerHTML = html;
                    });

                    if (activitySelect) {
                        // Preserve "All Batches" option for activity batch select
                        const activityDefault = '<option value="">All Batches</option>';
                        let html2 = activityDefault;
                        batches.forEach(b => { html2 += `<option value="${b}">${b}</option>`; });
                        activitySelect.innerHTML = html2;
                    }
                })
                .catch(err => {
                    console.error('Failed to load batches', err);
                    if (batchErrorEl) { batchErrorEl.style.display = ''; batchErrorEl.textContent = 'Error loading batches: ' + err.message; }
                    // Fallback: try public endpoint
                    fetch('ajax_get_batches_public.php')
                        .then(r => r.json())
                        .then(data => {
                            if (!data || !data.success) {
                                if (batchErrorEl) { batchErrorEl.style.display = ''; batchErrorEl.textContent = 'Public fallback failed'; }
                                return;
                            }
                            const batches = data.batches || [];
                            if (batches.length === 0) {
                                if (batchErrorEl) { batchErrorEl.style.display = ''; batchErrorEl.textContent = 'No batches found (public)'; }
                                return;
                            }

                            batchSelects.forEach(sel => {
                                const defaultOpt = sel.options && sel.options.length ? sel.options[0].outerHTML : '<option value="">-- Select Batch --</option>';
                                let html = defaultOpt;
                                batches.forEach(b => { html += `<option value="${b}">${b}</option>`; });
                                sel.innerHTML = html;
                            });

                            if (activitySelect) {
                                // Preserve "All Batches" option for activity batch select
                                const activityDefault = '<option value="">All Batches</option>';
                                let html2 = activityDefault;
                                batches.forEach(b => { html2 += `<option value="${b}">${b}</option>`; });
                                activitySelect.innerHTML = html2;
                            }
                        })
                        .catch(e2 => {
                            console.error('Public batches fallback failed', e2);
                            if (batchErrorEl) { batchErrorEl.style.display = ''; batchErrorEl.textContent = 'Public fallback error: ' + e2.message; }
                        });
                });
        }

        // Ensure Manage Subjects table uses white background in light (b/w) mode
        function adjustManageSubjectsTableForLight() {
            try {
                const html = document.documentElement;
                const isLight = html.getAttribute('data-theme') === 'light' || localStorage.getItem('theme') === 'light';
                const container = document.getElementById('manageSubjects');
                if (!container) return;
                const table = container.querySelector('table');
                if (!table) return;

                if (isLight) {
                    // Remove table-dark which applies dark backgrounds
                    if (table.classList.contains('table-dark')) table.classList.remove('table-dark');
                    // Ensure table has base .table and light-friendly classes
                    table.classList.add('table');
                    // Force inline styles as a last-resort override
                    table.style.backgroundColor = '#ffffff';
                    table.style.color = '#212529';
                    // Also force cells
                    table.querySelectorAll('th, td, tr').forEach(el => {
                        el.style.backgroundColor = '#ffffff';
                        el.style.color = '#212529';
                        el.style.borderColor = '#dee2e6';
                    });
                } else {
                    // If returning to dark mode, remove forced inline styles so CSS can manage it
                    table.style.backgroundColor = '';
                    table.style.color = '';
                    table.querySelectorAll('th, td, tr').forEach(el => {
                        el.style.backgroundColor = '';
                        el.style.color = '';
                        el.style.borderColor = '';
                    });
                }
            } catch (e) {
                console.error('adjustManageSubjectsTableForLight error', e);
            }
        }

        function downloadAttendanceReport(type) {
            const subjectEl = document.getElementById('attendanceSubjectSelect');
            const attendanceSubject = subjectEl ? (subjectEl.value || '').trim() : '';
            // Prefer the report batch select, fall back to pastAttendanceBatchSelect if needed
            const reportBatchEl = document.getElementById('attendanceBatchSelect');
            const pastBatchEl = document.getElementById('pastAttendanceBatchSelect');
            const batch = (reportBatchEl && (reportBatchEl.value || '').trim()) || (pastBatchEl && (pastBatchEl.value || '').trim()) || '';

            if (type === 'daily') {
                const date = document.getElementById('attendanceDayDate').value;
                if (!date) return alert('Please select a date to download the report for.');
                const params = new URLSearchParams({ type: 'attendance', period: 'daily', date: date });
                if (attendanceSubject) params.set('subject_id', attendanceSubject);
                if (batch) params.set('batch', batch);
                const url = `export_report_pdf.php?${params.toString()}`;
                if (!confirm('Download attendance report for the selected options?')) return;
                window.location.href = url;
            } else {
                const start = document.getElementById('attendanceStartDate').value;
                const end = document.getElementById('attendanceEndDate').value;
                if (!(start && end)) return alert('Please select both start and end dates.');
                const params = new URLSearchParams({ type: 'attendance', period: 'range', start_date: start, end_date: end });
                if (attendanceSubject) params.set('subject_id', attendanceSubject);
                if (batch) params.set('batch', batch);
                const url = `export_report_pdf.php?${params.toString()}`;
                if (!confirm('Download attendance report for the selected options?')) return;
                window.location.href = url;
            }
        }

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
async function generateTrend() {
    console.log('=== TREND GENERATION STARTED ===');
    const start = document.getElementById('trendStartDate').value;
    const end = document.getElementById('trendEndDate').value;
    
    console.log('Selected dates:', { start, end });
    
    if (!start || !end) {
        alert('Please select both start and end dates');
        return;
    }
    
    // Validate dates
    const startDate = new Date(start);
    const endDate = new Date(end);
    
    if (startDate > endDate) {
        alert('Start date must be before end date');
        return;
    }
    
    showToast('Generating trend report... Please wait.', 'info');
    
    try {
        // Simply redirect to a new PHP file that generates the PDF with data only
        window.location.href = `generate_trend_pdf_simple.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
        
        showToast('Trend report is being generated...', 'success');
        console.log('=== TREND GENERATION COMPLETED ===');
        
    } catch (error) {
        console.error('=== TREND GENERATION FAILED ===');
        console.error('Error details:', error);
        showToast('Error generating trend report: ' + error.message, 'error');
    }
}
     // RESET USAGE TIME FUNCTIONS
        function handleRoleChange() {
            const role = document.getElementById('resetRoleSelect').value;
            const staffSelection = document.getElementById('staffSelection');
            const batchSelection = document.getElementById('batchSelection');
            const studentSelection = document.getElementById('studentSelection');
            const resetBtn = document.getElementById('resetUsageBtn');
            
            // Reset all selections
            staffSelection.style.display = 'none';
            batchSelection.style.display = 'none';
            studentSelection.style.display = 'none';
            document.getElementById('staffSelect').value = '';
            document.getElementById('batchSelect').value = '';
            document.getElementById('studentSelect').value = '';
            resetBtn.disabled = true;
            
            if (role === 'staff') {
                staffSelection.style.display = 'block';
                loadStaffList();
            } else if (role === 'student') {
                batchSelection.style.display = 'block';
                loadBatches();
            }
        }
        
        function loadStaffList() {
            const staffSelect = document.getElementById('staffSelect');
            staffSelect.innerHTML = '<option value="">-- Loading Staff... --</option>';
            
            fetch('ajax_get_staff_list.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        staffSelect.innerHTML = '<option value="">-- Select Staff --</option>';
                        data.staff.forEach(staff => {
                            const option = document.createElement('option');
                            option.value = staff.id;
                            option.textContent = staff.username + (staff.name ? ' (' + staff.name + ')' : '');
                            staffSelect.appendChild(option);
                        });
                        staffSelect.onchange = checkResetButtonState;
                    } else {
                        staffSelect.innerHTML = '<option value="">-- Error loading staff --</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading staff:', error);
                    staffSelect.innerHTML = '<option value="">-- Error loading staff --</option>';
                });
        }
        
        function loadBatches() {
            const batchSelect = document.getElementById('batchSelect');
            batchSelect.innerHTML = '<option value="">-- Loading Batches... --</option>';
            
            fetch('ajax_get_batches.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        batchSelect.innerHTML = '<option value="">-- Select Batch --</option>';
                        data.batches.forEach(batch => {
                            const option = document.createElement('option');
                            option.value = batch;
                            option.textContent = batch;
                            batchSelect.appendChild(option);
                        });
                    } else {
                        batchSelect.innerHTML = '<option value="">-- Error loading batches --</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading batches:', error);
                    batchSelect.innerHTML = '<option value="">-- Error loading batches --</option>';
                });
        }
        
        function handleBatchChange() {
            const batch = document.getElementById('batchSelect').value;
            const studentSelection = document.getElementById('studentSelection');
            const studentSelect = document.getElementById('studentSelect');
            
            if (batch) {
                studentSelection.style.display = 'block';
                loadStudentsByBatch(batch);
            } else {
                studentSelection.style.display = 'none';
                studentSelect.innerHTML = '<option value="">-- Select Batch First --</option>';
                checkResetButtonState();
            }
        }
        
        function loadStudentsByBatch(batch) {
            const studentSelect = document.getElementById('studentSelect');
            studentSelect.innerHTML = '<option value="">-- Loading Students... --</option>';
            
            fetch(`ajax_get_students_by_batch.php?batch=${encodeURIComponent(batch)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        studentSelect.innerHTML = '<option value="">-- Select Student --</option>';
                        data.students.forEach(student => {
                            const option = document.createElement('option');
                            option.value = student.id;
                            option.textContent = student.name + ' (' + student.admission_number + ')';
                            studentSelect.appendChild(option);
                        });
                        studentSelect.onchange = checkResetButtonState;
                    } else {
                        studentSelect.innerHTML = '<option value="">-- Error loading students --</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading students:', error);
                    studentSelect.innerHTML = '<option value="">-- Error loading students --</option>';
                });
        }
        
        function checkResetButtonState() {
            const role = document.getElementById('resetRoleSelect').value;
            const resetBtn = document.getElementById('resetUsageBtn');
            
            if (role === 'staff') {
                const staffId = document.getElementById('staffSelect').value;
                resetBtn.disabled = !staffId;
            } else if (role === 'student') {
                const studentId = document.getElementById('studentSelect').value;
                resetBtn.disabled = !studentId;
            } else {
                resetBtn.disabled = true;
            }
        }
        
        function resetUsageTime() {
            const role = document.getElementById('resetRoleSelect').value;
            let userId = null;
            let userName = '';
            
            if (role === 'staff') {
                userId = document.getElementById('staffSelect').value;
                const staffSelect = document.getElementById('staffSelect');
                userName = staffSelect.options[staffSelect.selectedIndex].textContent;
            } else if (role === 'student') {
                userId = document.getElementById('studentSelect').value;
                const studentSelect = document.getElementById('studentSelect');
                userName = studentSelect.options[studentSelect.selectedIndex].textContent;
            }
            
            if (!userId) {
                alert('Please select a user');
                return;
            }
            
            if (!confirm(`Are you sure you want to reset usage time for ${userName}? This action cannot be undone.`)) {
                return;
            }
            
            showToast('Resetting usage time...', 'info');
            
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('role', role);
            
            fetch('ajax_reset_usage_time.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Usage time reset successfully!', 'success');
                    // Reset form
                    document.getElementById('resetRoleSelect').value = '';
                    handleRoleChange();
                } else {
                    showToast('Error: ' + (data.message || 'Failed to reset usage time'), 'error');
                }
            })
            .catch(error => {
                console.error('Error resetting usage time:', error);
                showToast('Error resetting usage time. Please try again.', 'error');
            });
        }

        // MANAGE PAST ATTENDANCE FUNCTIONS
        function loadPastAttendance() {
            const subjectId = document.getElementById('pastSubject').value;
            const startDate = document.getElementById('pastDate').value;
            const searchQuery = document.getElementById('searchPastStudent').value;
            const batchEl = document.getElementById('pastAttendanceBatchSelect');
            const batch = batchEl ? (batchEl.value || '').trim() : '';
            const studentEl = document.getElementById('pastStudentSelect');
            const studentId = studentEl ? (studentEl.value || '').trim() : '';
            
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
            formData.append('search_query', searchQuery);
            if (batch) formData.append('batch', batch);
            if (studentId) formData.append('student_id', studentId);
            
            window.lastAttendanceLoad = { subjectId, startDate, searchQuery };
            
            fetch('ajax_past_attendance.php', {
                method: 'POST',
                credentials: 'same-origin',
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

        // Populate students when a batch is selected for past attendance
        function populatePastStudents(batch) {
            const studentSelect = document.getElementById('pastStudentSelect');
            if (!studentSelect) return;
            // Clear existing
            studentSelect.innerHTML = '<option value="">-- Select Student (optional) --</option>';
            if (!batch) return;

            fetch(`ajax_get_students_by_batch.php?batch=${encodeURIComponent(batch)}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) return;
                    const students = data.students || [];
                    students.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s.id;
                        opt.textContent = `${s.name} (${s.admission_number})`;
                        studentSelect.appendChild(opt);
                    });
                })
                .catch(err => {
                    console.error('Failed to load students for batch', err);
                    // try public fallback
                    fetch(`ajax_get_students_by_batch_public.php?batch=${encodeURIComponent(batch)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (!data || !data.success) return;
                            const students = data.students || [];
                            students.forEach(s => {
                                const opt = document.createElement('option');
                                opt.value = s.id;
                                opt.textContent = `${s.name} (${s.admission_number})`;
                                studentSelect.appendChild(opt);
                            });
                        })
                        .catch(e2 => console.error('Public students fallback failed', e2));
                });
        }

        function displayPastAttendance(attendance, dates, startDate, subjectName) {
            const resultsDiv = document.getElementById('pastAttendanceResults');
            
            if (!attendance || attendance.length === 0) {
                resultsDiv.innerHTML = '<div class="alert alert-info">No attendance records found for the selected period and subject</div>';
                return;
            }
            
            let html = `
                <div class="bg-dark p-3 rounded mt-3">
                    <h5 class="text-white mb-1">Week Attendance (${startDate} onwards - 7 days)</h5>
                    <p class="text-light mb-3"><strong>Subject:</strong> ${subjectName}</p>
                    <div class="table-responsive" style="overflow-x: auto;">
                        <table class="table table-dark table-hover table-sm" style="font-size: 13px; min-width: 100%;">
                            <thead>
                                <tr style="position: sticky; top: 0; background: #1e293b; z-index: 10;">
                                    <th style="position: sticky; left: 0; background: #1e293b; z-index: 11; min-width: 120px; max-width: 150px;">Student Name</th>
                                    <th style="background: #1e293b; min-width: 100px; max-width: 120px;">Admission No.</th>
            `;
            
            dates.forEach(date => {
                const dateObj = new Date(date);
                const formattedDate = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit' });
                html += `<th class="text-center" style="min-width: 150px;">${formattedDate}</th>`;
            });
            
            html += `
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            attendance.forEach(student => {
                html += `
                    <tr>
                        <td style="position: sticky; left: 0; background: #2d3748; font-weight: 600; min-width: 120px; max-width: 150px;">${student.name}</td>
                        <td style="background: #2d3748; min-width: 100px; max-width: 120px;">${student.admission_number}</td>
                `;
                
                dates.forEach(date => {
                    const record = student.records[date];
                    
                    html += `<td class="text-center p-2">`;
                    
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
                                badgeClass = 'bg-warning';
                                statusText = 'LATE';
                            } else if (statusLower === 'absent') {
                                badgeClass = 'bg-danger';
                                statusText = 'ABSENT';
                            } else {
                                badgeClass = 'bg-secondary';
                                statusText = String(record.status).toUpperCase();
                            }
                            
                            statusBadge = `<div class="mb-2"><span class="badge ${badgeClass}">${statusText}</span></div>`;
                        }
                        
                        html += statusBadge;
                        html += `
                            <div class="btn-group btn-group-sm" role="group">
                                <button class="btn btn-success" style="padding: 4px 8px; font-size: 11px;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'present')">P</button>
                                <button class="btn btn-warning" style="padding: 4px 8px; font-size: 11px;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'late')">L</button>
                                <button class="btn btn-danger" style="padding: 4px 8px; font-size: 11px;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'absent')">A</button>
                                ${record.status ? `<button class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" 
                                    onclick="markPastAttendance(${student.id}, ${record.subject_id}, '${date}', 'unmark')">U</button>` : ''}
                            </div>
                        `;
                    } else {
                        html += '<span class="text-muted" style="font-size: 12px;">No login</span>';
                    }
                    
                    html += `</td>`;
                });
                
                html += `</tr>`;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
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
        // Add Dark/Light Mode Toggle at the end of the script section
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

            // Populate attendance/activity batches dropdowns and adjust Manage Subjects table for light mode
            try { populateAttendanceBatches(); } catch (e) { console.error('populateAttendanceBatches error', e); }
            try { adjustManageSubjectsTableForLight(); } catch (e) { console.error('adjustManageSubjectsTableForLight error', e); }
            try {
                const batchSelect = document.getElementById('pastAttendanceBatchSelect');
                const studentSelect = document.getElementById('pastStudentSelect');
                if (batchSelect) {
                    batchSelect.addEventListener('change', function () {
                        const b = (this.value || '').trim();
                        populatePastStudents(b);
                        const resultsDiv = document.getElementById('pastAttendanceResults');
                        if (resultsDiv) resultsDiv.innerHTML = '';
                        // Auto-load if subject and date are selected
                        const subj = document.getElementById('pastSubject');
                        const dt = document.getElementById('pastDate');
                        if (subj && dt && subj.value && dt.value) {
                            try { loadPastAttendance(); } catch (e) { console.error('auto loadPastAttendance error', e); }
                        }
                    });
                }
                if (studentSelect) {
                    studentSelect.addEventListener('change', function () {
                        const resultsDiv = document.getElementById('pastAttendanceResults');
                        if (resultsDiv) resultsDiv.innerHTML = '';
                        // Auto-load when student filter changes
                        const subj = document.getElementById('pastSubject');
                        const dt = document.getElementById('pastDate');
                        if (subj && dt && subj.value && dt.value) {
                            try { loadPastAttendance(); } catch (e) { console.error('auto loadPastAttendance error', e); }
                        }
                    });
                }
            } catch (e) { console.error('attach batch/student listeners error', e); }
        });

        // Show toast notification function (if not already present)
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

        // Ensure role/batch filter behaviors are consistent — override/ensure functions
        (function () {
            function safeGet(id) { return document.getElementById(id); }

            // Show/hide batch row when role changes
            window.handleRoleFilter = function() {
                const roleSelect = safeGet('filterUserRole');
                const batchRow = safeGet('batchFilterRow');
                const batchSelect = safeGet('filterUserBatch');
                if (!roleSelect || !batchRow) return;

                if (roleSelect.value === 'student') {
                    batchRow.style.display = '';
                } else {
                    batchRow.style.display = 'none';
                    if (batchSelect) batchSelect.value = '';
                }
                // apply filter after toggling
                if (typeof window.filterUsersTable === 'function') window.filterUsersTable();
            };

            // Filtering function: search + role + batch
            window.filterUsersTable = function() {
                const searchEl = safeGet('searchUsers');
                const roleEl = safeGet('filterUserRole');
                const batchEl = safeGet('filterUserBatch');
                const table = safeGet('usersTable');
                const countEl = safeGet('userCount');

                const searchValue = (searchEl && searchEl.value) ? searchEl.value.toUpperCase() : '';
                const roleValue = (roleEl && roleEl.value) ? roleEl.value.toLowerCase() : '';
                const batchValue = (batchEl && batchEl.value) ? batchEl.value : '';

                if (!table) return;
                const rows = table.getElementsByTagName('tr');
                let visible = 0;

                for (let i = 1; i < rows.length; i++) {
                    const row = rows[i];
                    const cells = row.getElementsByTagName('td');
                    if (!cells || cells.length < 3) continue;
                    const name = (cells[0].textContent || '').toUpperCase();
                    const username = (cells[1].textContent || '').toUpperCase();
                    const role = (cells[2].textContent || '').toLowerCase();
                    const batch = row.getAttribute('data-batch') || '';

                    const matchesSearch = !searchValue || name.indexOf(searchValue) > -1 || username.indexOf(searchValue) > -1;
                    const matchesRole = !roleValue || role === roleValue;
                    const matchesBatch = !batchValue || batch === batchValue;

                    const shouldShow = matchesSearch && matchesRole && (!batchValue || (roleValue === 'student' && matchesBatch));

                    row.style.display = shouldShow ? '' : 'none';
                    if (shouldShow) visible++;
                }

                if (countEl) countEl.textContent = `Showing ${visible} user${visible !== 1 ? 's' : ''}`;
            };

            // Attach listeners on DOM ready (in case inline handlers are missing)
            document.addEventListener('DOMContentLoaded', function() {
                const roleEl = safeGet('filterUserRole');
                const searchEl = safeGet('searchUsers');
                const batchEl = safeGet('filterUserBatch');

                if (roleEl) roleEl.addEventListener('change', handleRoleFilter);
                if (searchEl) searchEl.addEventListener('input', function() { window.filterUsersTable(); });
                if (batchEl) batchEl.addEventListener('change', function() { window.filterUsersTable(); });

                // Initialize visibility state
                try { handleRoleFilter(); } catch (e) { console.error(e); }
                try { filterUsersTable(); } catch (e) { console.error(e); }
            });
        })();
    </script>
    <script>
        // editUser is now defined earlier in the script

        function toggleEditFields() {
            const role = document.getElementById('edit_role').value;
            const admGroup = document.getElementById('edit_adm_group');
            const usernameGroup = document.getElementById('edit_username_group');
            const batchGroup = document.getElementById('edit_batch_group');

            if (role === 'student') {
                admGroup.style.display = '';
                usernameGroup.style.display = 'none';
                batchGroup.style.display = '';
            } else {
                admGroup.style.display = 'none';
                usernameGroup.style.display = '';
                batchGroup.style.display = 'none';
            }
        }

        // handleSaveEdit is now defined earlier in the script

        // Attach event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.getElementById('edit_role');
            if (roleSelect) {
                roleSelect.addEventListener('change', toggleEditFields);
            }

            const saveBtn = document.getElementById('saveEditBtn');
            if (saveBtn) {
                // Remove any existing listeners and add new one
                const newSaveBtn = saveBtn.cloneNode(true);
                saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
                newSaveBtn.addEventListener('click', handleSaveEdit);
                console.log('Save button event listener attached');
                        } else {
                console.error('Save button not found on page load');
            }
        });
        
        // Also attach listener when modal is shown (in case it's added dynamically)
        document.addEventListener('shown.bs.modal', function(event) {
            if (event.target.id === 'editUserModal') {
                const saveBtn = document.getElementById('saveEditBtn');
                if (saveBtn && !saveBtn.dataset.listenerAttached) {
                    saveBtn.dataset.listenerAttached = 'true';
                    saveBtn.addEventListener('click', handleSaveEdit);
                    console.log('Save button event listener attached on modal show');
                }
            }
        });

        // ============================================
        // LIVE SYSTEM USAGE AUTO-REFRESH
        // ============================================
        // Auto-refreshes the Live System Usage table every 30 seconds
        
        function refreshSystemUsageTable() {
            fetch('ajax_get_system_usage.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        const tbody = document.getElementById('liveSystemUsageBody');
                        if (!tbody) return;
                        
                        if (data.data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No system usage data available.</td></tr>';
                            return;
                        }
                        
                        let html = '';
                        data.data.forEach(usage => {
                            // Calculate time difference
                            const lastActive = parseMySQLToLocal(usage.last_active);
                            const now = new Date();
                            const timeDiff = Math.floor((now - lastActive) / 1000); // seconds
                            
                            let timeAgo = 'N/A';
                            if (timeDiff < 60) {
                                timeAgo = 'Just now';
                            } else if (timeDiff < 3600) {
                                timeAgo = Math.floor(timeDiff / 60) + ' minutes ago';
                            } else if (timeDiff < 86400) {
                                timeAgo = Math.floor(timeDiff / 3600) + ' hours ago';
                            } else {
                                timeAgo = lastActive.toLocaleDateString('en-GB') + ' ' + 
                                          lastActive.toLocaleTimeString('en-GB', {hour: '2-digit', minute: '2-digit'});
                            }
                            
                            const roleBadge = usage.role === 'student' ? 'info' : 'warning';
                            
                            // Determine status dynamically based on last_active time
                            // If last_active is within 2 minutes, consider it active
                            const lastActiveTime = parseMySQLToLocal(usage.last_active);
                            const timeDiff2 = Math.floor((now - lastActiveTime) / 1000); // seconds
                            const isReallyActive = timeDiff2 < 120; // 2 minutes = 120 seconds
                            
                            // Use database status, but override if last_active is recent
                            const displayStatus = (usage.status === 'active' || isReallyActive);
                            const statusBadge = displayStatus ? 'success' : 'danger';
                            const statusText = displayStatus ? 'Active' : 'Inactive';
                            
                            html += `
                                <tr>
                                    <td><strong>${escapeHtml(usage.system_id || 'N/A')}</strong></td>
                                    <td>${escapeHtml(usage.username || 'N/A')}</td>
                                    <td><span class="badge bg-${roleBadge}">${escapeHtml(usage.role ? usage.role.charAt(0).toUpperCase() + usage.role.slice(1) : 'N/A')}</span></td>
                                    <td>${escapeHtml(usage.ip_address || 'N/A')}</td>
                                    <td>${timeAgo}</td>
                                    <td><span class="badge bg-${statusBadge}">${statusText}</span></td>
                                </tr>
                            `;
                        });
                        
                        tbody.innerHTML = html;
                        
                        // Update last updated timestamp
                        const lastUpdateEl = document.getElementById('systemUsageLastUpdate');
                        if (lastUpdateEl) {
                            const now = new Date();
                            lastUpdateEl.textContent = 'Last updated: ' + now.toLocaleTimeString();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error refreshing system usage:', error);
                });
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Robustly parse MySQL DATETIME (YYYY-MM-DD HH:MM:SS) assuming UTC, return local Date
        function parseMySQLToLocal(dt) {
            if (!dt || typeof dt !== 'string') return new Date(NaN);
            const parts = dt.trim().split(' ');
            if (parts.length !== 2) return new Date(NaN);
            const [datePart, timePart] = parts;
            const d = datePart.split('-').map(n => parseInt(n, 10));
            const t = timePart.split(':').map(n => parseInt(n, 10));
            if (d.length !== 3 || t.length < 2) return new Date(NaN);
            const year = d[0], month = (d[1] || 1) - 1, day = d[2] || 1;
            const hour = t[0] || 0, minute = t[1] || 0, second = t[2] || 0;
            // Treat the incoming timestamp as UTC and convert to a local Date
            const ms = Date.UTC(year, month, day, hour, minute, second);
            return new Date(ms);
        }
        
        // Auto-refresh every 30 seconds
        setInterval(refreshSystemUsageTable, 30000);
        
        // Initial refresh after page load (wait 2 seconds for page to fully load)
        setTimeout(refreshSystemUsageTable, 2000);
    </script>
        <script src="assets/js/electron-bridge.js"></script>
    <div style="margin-top:40px;background:#020617;border-top:1px solid #1f2937;padding:12px 0;text-align:center;font-size:13px;color:#e5e7eb;letter-spacing:0.08em;text-transform:uppercase;">
	    &copy; <?php echo date('Y'); ?> All rights reserved - Team BBAJ
	    </div>
    </body>
    </html>