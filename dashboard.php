<?php
session_start();

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
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

// Get user information
$user_name = "Test Student";
$stmt = $conn->prepare("SELECT name, admission_number FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user_data = $result->fetch_assoc();
    $user_name = $user_data['name'] ?? $user_data['admission_number'];
}
$stmt->close();

// Get selected subject name
$selected_subject_name = "No Subject Selected";
if (isset($_SESSION['selected_subject_id'])) {
    $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['selected_subject_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $subject_data = $result->fetch_assoc();
        $selected_subject_name = $subject_data['subject_name'];
    }
    $stmt->close();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Submit Issue Report
    if (isset($_POST['submit_issue'])) {
        $system_number = $_POST['system_number'] ?? '';
        $description = $_POST['issue_description'] ?? '';
        
        if (!empty($system_number) && !empty($description)) {
            $stmt = $conn->prepare("INSERT INTO issues (user_id, system_number, description) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $system_number, $description);
            if ($stmt->execute()) {
                $success = "Issue reported successfully!";
            } else {
                $error = "Failed to submit issue report.";
            }
            $stmt->close();
        } else {
            $error = "Please fill all fields for issue report.";
        }
    }
    
    // Save Activity Log
    if (isset($_POST['save_activity'])) {
        $activity_text = $_POST['activity_text'] ?? '';
        $subject_id = $_SESSION['selected_subject_id'] ?? null;
        
        if (!empty($activity_text)) {
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, subject_id, log_text) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $user_id, $subject_id, $activity_text);
            if ($stmt->execute()) {
                $success = "Activity logged successfully!";
            } else {
                $error = "Failed to save activity log.";
            }
            $stmt->close();
        } else {
            $error = "Please describe your activity.";
        }
    }
    
    // Delete Activity Log
    if (isset($_POST['delete_activity'])) {
        $log_id = $_POST['log_id'] ?? 0;
        $stmt = $conn->prepare("DELETE FROM activity_logs WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $log_id, $user_id);
        if ($stmt->execute()) {
            $success = "Activity log deleted successfully!";
        } else {
            $error = "Failed to delete activity log.";
        }
        $stmt->close();
    }
    
    // Logout
    if (isset($_POST['logout'])) {
        // Record a logout marker in login_activity so dashboards can compute logout and duration correctly
        if (isset($_SESSION['user_id'])) {
            $logout_user_id = (int)$_SESSION['user_id'];
            $logout_ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $conn->prepare("INSERT INTO login_activity (user_id, ip_address, login_time) VALUES (?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("is", $logout_user_id, $logout_ip);
                $stmt->execute();
                $stmt->close();
            }
        }

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
}

// Fetch notifications
$notifications = [];
$sql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

// Get current session start time
$session_start = null;
$sql = "SELECT login_time FROM login_activity WHERE user_id = ? ORDER BY login_time DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $session_start = strtotime($row['login_time']);
} else {
    $session_start = time();
}
$stmt->close();

// Calculate elapsed time
$elapsed_seconds = 0;
if ($session_start) {
    $elapsed_seconds = time() - $session_start;
    if ($elapsed_seconds < 0) {
        $elapsed_seconds = 0;
    }
}

// Format initial display
$hours = floor($elapsed_seconds / 3600);
$minutes = floor(($elapsed_seconds % 3600) / 60);
$seconds = $elapsed_seconds % 60;
$usage_display = sprintf("%dh %02dm %02ds", $hours, $minutes, $seconds);

// Fetch recent activity logs
$activity_logs = [];
$sql = "SELECT al.*, s.subject_name FROM activity_logs al 
        LEFT JOIN subjects s ON al.subject_id = s.id 
        WHERE al.user_id = ? 
        ORDER BY al.created_at DESC LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activity_logs[] = $row;
}
$stmt->close();

// Fetch attendance records (fetch all records for filtering, not just last 10)
$attendance_records = [];
$sql = "SELECT a.*, s.subject_name FROM attendance a 
        LEFT JOIN subjects s ON a.subject_id = s.id 
        WHERE a.user_id = ? 
        ORDER BY a.date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $attendance_records[] = $row;
}
$stmt->close();

// Fetch all subjects for dropdown
$all_subjects = [];
$sql = "SELECT id, subject_name FROM subjects ORDER BY subject_name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_subjects[] = $row;
    }
}

// Get last login time
$last_login = "N/A";
$sql = "SELECT login_time FROM login_activity WHERE user_id = ? ORDER BY login_time DESC LIMIT 1, 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $last_login = date('d M Y, H:i', strtotime($row['login_time']));
}
$stmt->close();

$conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Lab Activity Reporting System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            color: white;
        }

        /* Ensure visible colors in normal (non B&W) mode */
        body:not(.bw-theme) .card-title { color: #ffffff !important; }
        body:not(.bw-theme) .card-content, body:not(.bw-theme) .activity-text, body:not(.bw-theme) .notification-item { color: #cbd5e1 !important; }
        body:not(.bw-theme) .attendance-table th { background: #1e293b; color: #cbd5e1 !important; }
        body:not(.bw-theme) .attendance-table td { color: #e2e8f0 !important; }
        body:not(.bw-theme) .form-group label { color: #f1f5f9 !important; }
        body:not(.bw-theme) .form-group input,
        body:not(.bw-theme) .form-group textarea,
        body:not(.bw-theme) .form-group select { background: #1e293b !important; color: #ffffff !important; border: 1px solid #334155 !important; }
        
        /* Attendance Filter Bar Styling - Theme Aware */
        body:not(.bw-theme) .attendance-filter-bar {
            background: #1e293b !important;
            border: 1px solid #334155 !important;
        }
        body:not(.bw-theme) .filter-main-label {
            color: #f1f5f9 !important;
        }
        body:not(.bw-theme) .filter-sub-label {
            color: #cbd5e1 !important;
        }
        body.bw-theme .attendance-filter-bar {
            background: #f8f9fa !important;
            border: 1px solid #e2e8f0 !important;
        }
        body.bw-theme .filter-main-label {
            color: #1e293b !important;
        }
        body.bw-theme .filter-sub-label {
            color: #475569 !important;
        }
        /* Light mode (default) */
        .attendance-filter-bar {
            background: #f8f9fa;
            border: 1px solid #e2e8f0;
        }
        .filter-main-label {
            color: #1e293b;
        }
        .filter-sub-label {
            color: #475569;
        }
        body:not(.bw-theme) .usage-display { color: #818cf8 !important; }
        body:not(.bw-theme) .btn-success { background: #16a34a !important; color: #ffffff !important; }
        body:not(.bw-theme) .btn-primary { background: #6366f1 !important; color: #ffffff !important; }
        body:not(.bw-theme) .btn-danger { background: #dc2626 !important; color: #ffffff !important; }
        body:not(.bw-theme) .btn-secondary { background: #64748b !important; color: #ffffff !important; }

        /* Stronger button color rules to override any earlier generic rules */
        body:not(.bw-theme) .btn { border: 1px solid transparent !important; }
        body:not(.bw-theme) .btn.btn-primary { background: #6366f1 !important; color: #ffffff !important; border-color: #6366f1 !important; }
        body:not(.bw-theme) .btn.btn-success { background: #16a34a !important; color: #ffffff !important; border-color: #16a34a !important; }
        body:not(.bw-theme) .btn.btn-danger { background: #dc2626 !important; color: #ffffff !important; border-color: #dc2626 !important; }
        body:not(.bw-theme) .btn.btn-secondary { background: #64748b !important; color: #ffffff !important; border-color: #64748b !important; }
        body:not(.bw-theme) .btn-small { padding: 6px 12px !important; }

        /* Also ensure buttons inside forms and inline-styled buttons pick up colors */
        body:not(.bw-theme) form .btn, body:not(.bw-theme) .card form .btn {
            color: #ffffff !important;
        }

        .header {
            background: #4c51bf;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .header h1 {
            font-size: 22px;
            font-weight: 600;
            color: white;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .user-info {
            font-size: 14px;
            color: white;
            font-weight: 500;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .btn-success {
            background: #16a34a;
            color: white;
        }

        .btn-success:hover {
            background: #15803d;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #6366f1;
            color: white;
        }

        .btn-primary:hover {
            background: #5558e3;
            transform: translateY(-1px);
        }

        /* Black & White theme overrides */
        body.bw-theme {
            background: #ffffff;
            color: #000000;
        }

    /* Keep header colored in white (B&W) theme but keep backgrounds white */
    body.bw-theme .header { background: #4c51bf; box-shadow: none; }
    body.bw-theme .header h1, body.bw-theme .user-info { color: #ffffff; }
    body.bw-theme .card { background: #ffffff; color: #000000; box-shadow: none; border: 1px solid #ddd; }
    body.bw-theme .card-title { color: #000000; }
    body.bw-theme .card-content, body.bw-theme .activity-text, body.bw-theme .notification-item { color: #000000; }
    /* Preserve original button and accent colors in white mode (carry colors from dark mode) */
        /* Accent variables for white mode */
        body.bw-theme {
            --accent-primary: #6366f1;
            --accent-success: #16a34a;
            --accent-danger: #dc2626;
            --accent-secondary: #64748b;
            --accent-usage: #818cf8;
            --accent-activity-border: #6366f1;
        }

        body.bw-theme .btn-success { background: var(--accent-success) !important; color: #ffffff !important; }
    body.bw-theme .btn-success:hover { background: #15803d !important; }
        body.bw-theme .btn-danger { background: var(--accent-danger) !important; color: #ffffff !important; }
    body.bw-theme .btn-danger:hover { background: #b91c1c !important; }
        body.bw-theme .btn-primary { background: var(--accent-primary) !important; color: #ffffff !important; }
        body.bw-theme .btn-primary:hover { background: #5558e3 !important; }
        body.bw-theme .btn-secondary { background: var(--accent-secondary) !important; color: #ffffff !important; }
        body.bw-theme .attendance-table th, body.bw-theme .attendance-table td { border-color: #000000; color: #000000; }
        body.bw-theme .usage-display { color: var(--accent-usage) !important; }

    /* Bring status colors and activity accents from dark mode into white mode */
    body.bw-theme .status-present { color: var(--accent-success) !important; }
    body.bw-theme .status-absent { color: var(--accent-danger) !important; }
    body.bw-theme .status-late { color: #eab308 !important; }
    body.bw-theme .activity-item { border-left-color: var(--accent-activity-border) !important; }
    body.bw-theme .notification-item { border-left: 4px solid var(--accent-activity-border) !important; background: #ffffff !important; }
    body.bw-theme .card-title { color: #000000 !important; }
    body.bw-theme .divider::before, body.bw-theme .divider::after { background: #334155 !important; }

    /* Also ensure links and small accents use primary */
    body.bw-theme a { color: var(--accent-primary) !important; }
    body.bw-theme .create-account a { color: var(--accent-primary) !important; }

        /* Form controls and text visibility in B&W */
        body.bw-theme .form-group input,
        body.bw-theme .form-group textarea,
        body.bw-theme .form-group select {
            background: #ffffff;
            color: #000000;
            border: 1px solid #000000;
        }

        body.bw-theme .form-group input::placeholder,
        body.bw-theme .form-group textarea::placeholder {
            color: #666666;
        }

        body.bw-theme .notification-item {
            background: #ffffff;
            color: #000000;
            border: 1px solid #000000;
        }

        body.bw-theme .attendance-table th {
            background: #ffffff;
            color: #000000;
        }

        body.bw-theme .attendance-table td {
            color: #000000;
        }

        body.bw-theme .empty-state { color: #000000; }

        body.bw-theme .divider::before,
        body.bw-theme .divider::after { background: #000000; }
        body.bw-theme .divider span { color: #000000; }

        /* Button variants in B&W: use accent variables so buttons keep their semantic colors */
        body.bw-theme .btn { border: 1px solid transparent !important; }
        body.bw-theme .btn.btn-primary { background: var(--accent-primary) !important; color: #ffffff !important; border-color: var(--accent-primary) !important; }
        body.bw-theme .btn.btn-success { background: var(--accent-success) !important; color: #ffffff !important; border-color: var(--accent-success) !important; }
        body.bw-theme .btn.btn-danger { background: var(--accent-danger) !important; color: #ffffff !important; border-color: var(--accent-danger) !important; }
        body.bw-theme .btn.btn-secondary { background: var(--accent-secondary) !important; color: #ffffff !important; border-color: var(--accent-secondary) !important; }

        /* Links in B&W theme should use primary accent */
        body.bw-theme a { color: var(--accent-primary) !important; text-decoration: underline; }

        /* Make text readable in B&W mode without forcing every element's background to transparent.
           Avoid global background overrides so components (header, cards, buttons) can keep explicit
           backgrounds set above. */
        body.bw-theme { color: #000000 !important; background: #ffffff !important; }

        body.bw-theme .greeting,
        body.bw-theme .card-title,
        body.bw-theme .card-content,
        body.bw-theme .activity-text,
        body.bw-theme .notification-item,
        body.bw-theme .activity-date,
        body.bw-theme .form-group label,
        body.bw-theme .last-login-box,
        body.bw-theme .timer-info,
        body.bw-theme .section-divider h4,
        body.bw-theme .empty-state {
            color: #000000 !important;
        }

    body.bw-theme .activity-item { border-left-color: var(--accent-activity-border) !important; }

        /* Ensure placeholders are readable */
        body.bw-theme .form-group input::placeholder,
        body.bw-theme .form-group textarea::placeholder {
            color: #666666 !important;
        }

        /* Make sure small metadata text is dark */
        body.bw-theme .activity-date,
        body.bw-theme .timer-info,
        body.bw-theme .user-info {
            color: #222222 !important;
        }

        /* Ensure buttons still readable text */
        body.bw-theme .btn { color: #ffffff !important; }


        .btn-secondary {
            background: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
            transform: translateY(-1px);
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .greeting {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 30px;
            color: white;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: #2d3748;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #ffffff;
        }

        .card-content {
            color: #cbd5e1;
        }

        .notification-item {
            padding: 12px;
            background: #1e293b;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 14px;
            color: #e2e8f0;
        }

        .usage-display {
            font-size: 48px;
            font-weight: 700;
            color: #818cf8;
            text-align: center;
            padding: 20px 0;
        }

        .activity-item {
            background: #1e293b;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 12px;
            border-left: 3px solid #6366f1;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .activity-date {
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 600;
        }

        .activity-text {
            color: #e2e8f0;
            font-size: 14px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #f1f5f9;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            color: white;
            font-size: 14px;
            outline: none;
            font-family: 'Poppins', sans-serif;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #6366f1;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #64748b;
        }

        .date-range {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .date-range input {
            flex: 1;
        }

        .date-range span {
            color: #cbd5e1;
            font-weight: 500;
        }

        .success-message {
            background: #16a34a;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .error-message {
            background: #dc2626;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            color: #94a3b8;
            padding: 30px;
            font-size: 14px;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .attendance-table th,
        .attendance-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #334155;
            font-size: 14px;
        }

        .attendance-table th {
            color: #cbd5e1;
            font-weight: 600;
            background: #1e293b;
        }
        /* Last-resort: force headings and any inline-styled elements to be readable in B&W mode.
           This runs after earlier rules. We then re-assert header/button colors so they remain
           as intended. */
        body.bw-theme h1,
        body.bw-theme h2,
        body.bw-theme h3,
        body.bw-theme h4,
        body.bw-theme h5,
        body.bw-theme h6,
        body.bw-theme strong,
        body.bw-theme b,
        body.bw-theme em,
        body.bw-theme i {
            color: #000000 !important;
        }

        /* Target any element with inline styles to override light text colors */
        body.bw-theme [style] {
            color: #000000 !important;
            background-color: transparent !important;
        }

        /* Re-assert header and button accents after the generic [style] rule */
        body.bw-theme .header { background: var(--accent-primary, #4c51bf) !important; }
        body.bw-theme .header h1,
        body.bw-theme .user-info { color: #ffffff !important; }
        body.bw-theme .btn.btn-primary { background: var(--accent-primary) !important; border-color: var(--accent-primary) !important; color: #ffffff !important; }
        body.bw-theme .btn.btn-success { background: var(--accent-success) !important; border-color: var(--accent-success) !important; color: #ffffff !important; }
        body.bw-theme .btn.btn-danger { background: var(--accent-danger) !important; border-color: var(--accent-danger) !important; color: #ffffff !important; }
        body.bw-theme .btn.btn-secondary { background: var(--accent-secondary) !important; border-color: var(--accent-secondary) !important; color: #ffffff !important; }

        /* Ensure cards keep a visible border in white mode */
        body.bw-theme .card { border: 1px solid var(--card-border, #ddd) !important; background: #ffffff !important; }
        /* Ensure activity items and notifications keep their left accent */
        body.bw-theme .activity-item { border-left-color: var(--accent-activity-border) !important; }
        body.bw-theme .notification-item { border-left: 4px solid var(--accent-activity-border) !important; background: #ffffff !important; }

        .attendance-table td {
            color: #e2e8f0;
        }

        .status-present {
            color: #22c55e;
            font-weight: 600;
        }

        .status-absent {
            color: #ef4444;
            font-weight: 600;
        }

        .status-late {
            color: #eab308;
            font-weight: 600;
        }

        .timer-paused-notice {
            text-align: center;
            color: #fbbf24;
            font-size: 16px;
            margin-top: 10px;
            font-weight: 600;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .section-divider {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #334155;
        }

        .section-divider h4 {
            color: #f1f5f9;
            font-size: 16px;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .last-login-box {
            margin-top: 30px;
            padding: 20px;
            background: #2d3748;
            border-radius: 12px;
            text-align: center;
            color: #cbd5e1;
            font-size: 14px;
        }

        .timer-info {
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            .header {
                text-align: center;
            }

            .header h1 {
                font-size: 18px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .greeting {
                font-size: 22px;
            }

            .usage-display {
                font-size: 36px;
            }

            .date-range {
                flex-direction: column;
            }

            .attendance-table {
                font-size: 12px;
            }

            .attendance-table th,
            .attendance-table td {
                padding: 8px;
            }
        }
        /* Final high-specificity overrides for B&W mode to ensure text is always readable.
           Placed at the end of the stylesheet so it overrides earlier rules and inline colors
           (uses !important). Exclude structural components so borders/accents remain visible. */
        body.bw-theme p,
        body.bw-theme span,
        body.bw-theme div,
        body.bw-theme td,
        body.bw-theme th,
        body.bw-theme label,
        body.bw-theme input,
        body.bw-theme textarea,
        body.bw-theme select,
        body.bw-theme .greeting,
        body.bw-theme .user-info,
        body.bw-theme .activity-text,
        body.bw-theme .activity-date,
        body.bw-theme .last-login-box,
        body.bw-theme .timer-info,
        body.bw-theme .section-divider h4,
        body.bw-theme .empty-state {
            color: #000000 !important;
            background-color: transparent !important;
        }

        /* Ensure paragraph placeholders / inline colored text are visible */
        body.bw-theme p[style], body.bw-theme span[style] { color: #000000 !important; }

        /* Re-assert header/button/card accents so they stay colored in B&W mode */
        body.bw-theme .header { background: var(--accent-primary, #4c51bf) !important; }
        body.bw-theme .header h1, body.bw-theme .user-info { color: #ffffff !important; }
        body.bw-theme .btn.btn-primary { background: var(--accent-primary) !important; border-color: var(--accent-primary) !important; color: #ffffff !important; }
        body.bw-theme .btn.btn-success { background: var(--accent-success) !important; border-color: var(--accent-success) !important; color: #ffffff !important; }
        body.bw-theme .btn.btn-danger { background: var(--accent-danger) !important; border-color: var(--accent-danger) !important; color: #ffffff !important; }
        body.bw-theme .btn.btn-secondary { background: var(--accent-secondary) !important; border-color: var(--accent-secondary) !important; color: #ffffff !important; }

        /* Ensure cards keep a visible border in white mode and activity/notification accents remain */
        body.bw-theme .card { border: 1px solid var(--card-border, #ddd) !important; background: #ffffff !important; }
        body.bw-theme .activity-item { border-left-color: var(--accent-activity-border) !important; }
        body.bw-theme .notification-item { border-left: 4px solid var(--accent-activity-border) !important; background: #ffffff !important; }

        /* Light-blue table hover in B&W (white) mode for readability */
        body.bw-theme table.table-hover tbody tr:hover,
        body.bw-theme .table.table-hover tbody tr:hover,
        body.bw-theme .attendance-table tbody tr:hover {
            background-color: #dbeafe !important; /* light blue */
            color: #000 !important;
        }
        /* Ensure table cells use same background and readable text */
        body.bw-theme table.table-hover tbody tr:hover td,
        body.bw-theme .table.table-hover tbody tr:hover td,
        body.bw-theme .attendance-table tbody tr:hover td {
            background-color: #dbeafe !important;
            color: #000 !important;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Lab Activity Reporting System</h1>
            <div class="header-right">
            <div class="user-info">
                User: <?php echo htmlspecialchars($user_name); ?> | Role: student
            </div>
            <button type="button" onclick="minimizeWindow()" class="btn btn-success">Continue</button>
                <!-- B&W theme toggle placed next to Continue -->
                <button type="button" id="bwToggle" class="btn btn-secondary btn-small" title="Toggle Black & White">B&amp;W</button>
                <button type="button" id="bwReset" class="btn btn-secondary btn-small" title="Reset theme preference" style="display:none;">Reset</button>
            <form method="POST" style="display: inline;" id="logoutForm">
                <button type="submit" name="logout" class="btn btn-danger" onclick="handleLogout(); return false;">Logout and Shutdown</button>
            </form>
        </div>
    </div>
    
    <!-- Minimized Bar (hidden by default) -->
    <div id="minimizedBar" style="display: none; position: fixed; bottom: 20px; left: 20px; 
         background: linear-gradient(135deg, #4c51bf 0%, #5b63d3 100%); color: white; 
         padding: 12px 24px; border-radius: 8px; cursor: pointer; z-index: 10000;
         box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-family: 'Poppins', sans-serif;
         align-items: center; gap: 8px; user-select: none; font-weight: 500;">
        📘 Lab Activity System — Click to Restore
    </div>

    <div id="mainContent" class="container">
        <h2 class="greeting">Hi, <?php echo htmlspecialchars($user_name); ?></h2>

        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="grid">
            <!-- My Notifications -->
            <div class="card">
                <h3 class="card-title">My Notifications</h3>
                <div class="card-content">
                    <?php if (empty($notifications)): ?>
                        <p style="color: #94a3b8;">No new notifications.</p>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notification-item">
                                <?php echo htmlspecialchars($notif['message']); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Total Lab Usage -->
            <div class="card">
                <h3 class="card-title">My Total Lab Usage</h3>
                <div class="usage-display" id="timerDisplay"><?php echo $usage_display; ?></div>
                <div class="timer-info">
                    Timer started at: <?php echo $session_start ? date('H:i:s', $session_start) : 'N/A'; ?>
                </div>
            </div>
        </div>

        <!-- My Recent Activity Logs -->
        <div class="card" style="margin-bottom: 30px;">
            <h3 class="card-title">My Recent Activity Logs</h3>
            <?php if (empty($activity_logs)): ?>
                <div class="empty-state">No activity logs yet.</div>
            <?php else: ?>
                <?php foreach ($activity_logs as $log): ?>
                    <div class="activity-item">
                        <div class="activity-header">
                            <div class="activity-date">
                                <?php echo date('d-m-Y, H:i', strtotime($log['created_at'])); ?>
                            </div>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                <button type="submit" name="delete_activity" class="btn btn-danger btn-small" onclick="return confirm('Are you sure you want to delete this log?');">Delete</button>
                            </form>
                        </div>
                        <div class="activity-text"><?php echo htmlspecialchars($log['log_text']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="grid">
            <!-- Report a System Issue -->
            <div class="card">
                <h3 class="card-title">Report a System Issue</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="system_number">System Number</label>
                        <input type="text" id="system_number" name="system_number" placeholder="e.g., PC-05" required>
                    </div>
                    <div class="form-group">
                        <label for="issue_description">Describe the Issue</label>
                        <textarea id="issue_description" name="issue_description" placeholder="e.g., Monitor is not turning on." required></textarea>
                    </div>
                    <button type="submit" name="submit_issue" class="btn btn-danger" style="width: 100%;">Submit Issue Report</button>
                </form>
            </div>

            <!-- Log Your Lab Activity -->
            <div class="card">
                <h3 class="card-title">Log Your Lab Activity</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="activity_text">Summary of Work Done</label>
                        <textarea id="activity_text" name="activity_text" placeholder="Describe the projects, assignments, or concepts you worked on today." required></textarea>
                    </div>
                    <button type="submit" name="save_activity" class="btn btn-primary" style="width: 100%;">Save Activity Log</button>
                </form>
            </div>
        </div>

        <!-- My Attendance Record -->
        <div class="card" style="margin-top: 30px;">
            <h3 class="card-title">My Attendance Record</h3>
            
            <!-- Date Filter Bar -->
            <div class="form-group attendance-filter-bar" style="margin-bottom: 20px; padding: 15px; border-radius: 8px;">
                <label class="filter-main-label" style="display: block; margin-bottom: 8px; font-weight: 600;">Filter by Date (Optional):</label>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 150px;">
                        <label class="filter-sub-label" style="font-size: 12px; margin-bottom: 4px; display: block;">Start Date:</label>
                        <input type="date" id="attendanceFilterStartDate" class="form-control" style="width: 100%;">
                    </div>
                    <div style="flex: 1; min-width: 150px;">
                        <label class="filter-sub-label" style="font-size: 12px; margin-bottom: 4px; display: block;">End Date:</label>
                        <input type="date" id="attendanceFilterEndDate" class="form-control" style="width: 100%;">
                    </div>
                    <div style="display: flex; gap: 8px; align-items: flex-end;">
                        <button type="button" onclick="filterAttendanceRecords()" class="btn btn-primary" style="padding: 8px 20px;">Filter</button>
                        <button type="button" onclick="clearAttendanceFilter()" class="btn btn-secondary" style="padding: 8px 20px;">Clear</button>
                    </div>
                </div>
            </div>
            
            <?php if (empty($attendance_records)): ?>
                <div class="empty-state">No data available yet.</div>
            <?php else: ?>
                <div id="attendanceTableContainer">
                    <table class="attendance-table" id="attendanceTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Subject</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <?php foreach ($attendance_records as $record): ?>
                                <tr data-date="<?php echo $record['date']; ?>" style="display: table-row;">
                                    <td><?php echo date('d-m-Y', strtotime($record['date'])); ?></td>
                                    <td><?php echo htmlspecialchars($record['subject_name'] ?? 'N/A'); ?></td>
                                    <td class="status-<?php echo $record['status']; ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="attendanceNoResults" style="display: none; text-align: center; padding: 20px; color: #666;">
                        No attendance records found for the selected date range.
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-divider">
                <h4>Download Attendance Report</h4>
                <form method="GET" action="download_student_attendance.php" onsubmit="return validateDates('attendance')">
                    <div class="form-group">
                        <label>Filter by Subject (Optional):</label>
                        <select name="subject_id" id="attendanceSubjectSelect" class="form-control" style="margin-bottom: 10px;">
                            <option value="">All Subjects</option>
                            <?php foreach ($all_subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Select Date Range:</label>
                        <div class="date-range">
                            <input type="date" name="start_date" id="attendance_start_date" required>
                            <span>to</span>
                            <input type="date" name="end_date" id="attendance_end_date" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary">Download Attendance PDF</button>
                </form>
            </div>
        </div>

        <!-- My Activity Reports -->
        <div class="card" style="margin-top: 30px;">
            <h3 class="card-title">My Activity Reports</h3>
            <form method="GET" action="download_student_activity.php" onsubmit="return validateDates('activity')">
                <div class="form-group">
                    <label>Filter by Subject (Optional):</label>
                    <select name="subject_id" id="activitySubjectSelect" class="form-control" style="margin-bottom: 10px;">
                        <option value="">All Subjects</option>
                        <?php foreach ($all_subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Range Report</label>
                    <div class="date-range">
                        <input type="date" name="start_date" id="activity_start_date" required>
                        <span>to</span>
                        <input type="date" name="end_date" id="activity_end_date" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Download Activity PDF</button>
            </form>
        </div>

        <div class="last-login-box">
            Last Login: <?php echo $last_login; ?>
        </div>
    </div>
    
    <script>
        // Timer functionality - Persistent Background Timer
        // This timer runs continuously even when window is minimized or out of focus
        // Uses localStorage to persist state across page refreshes
        // Stops only on Logout
        
        // Initialize timer state from localStorage or PHP
        const TIMER_STORAGE_KEY = 'student_timer_state';
        const TIMER_START_KEY = 'student_timer_start';
        const TIMER_PAUSED_KEY = 'student_timer_paused';
        
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
                isTimerPaused = false;
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
        
        function checkTimerStatus() {
            fetch('check_timer_status.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.paused && !isTimerPaused) {
                        isTimerPaused = true;
                        saveTimerState(); // Save paused state
                        showPauseNotification();
                    } else if (!data.paused && isTimerPaused) {
                        isTimerPaused = false;
                        saveTimerState(); // Save resumed state
                        hidePauseNotification();
                    }
                }
            })
            .catch(error => console.error('Error checking timer status:', error));
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
        
        setInterval(checkTimerStatus, 5000);
        checkTimerStatus();
        
        function showPauseNotification() {
            const timerDisplay = document.getElementById('timerDisplay');
            timerDisplay.style.color = '#fbbf24';
            if (!document.getElementById('pauseMessage')) {
                const pauseMsg = document.createElement('div');
                pauseMsg.id = 'pauseMessage';
                pauseMsg.className = 'timer-paused-notice';
                pauseMsg.innerHTML = '&#9208;&#65039; Timer Paused by Admin/Staff';
                timerDisplay.parentElement.appendChild(pauseMsg);
            }
        }
        
        function hidePauseNotification() {
            const timerDisplay = document.getElementById('timerDisplay');
            timerDisplay.style.color = '#818cf8';
            const pauseMsg = document.getElementById('pauseMessage');
            if (pauseMsg) {
                pauseMsg.remove();
            }
        }
        
        function validateDates(type) {
            const startDate = document.getElementById(type + '_start_date').value;
            const endDate = document.getElementById(type + '_end_date').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return false;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after end date');
                return false;
            }
            
            return true;
        }
        
        // Filter Attendance Records by Date
        function filterAttendanceRecords() {
            const startDate = document.getElementById('attendanceFilterStartDate').value;
            const endDate = document.getElementById('attendanceFilterEndDate').value;
            const tableBody = document.getElementById('attendanceTableBody');
            const noResults = document.getElementById('attendanceNoResults');
            const table = document.getElementById('attendanceTable');
            
            if (!tableBody) return;
            
            let visibleCount = 0;
            const rows = tableBody.querySelectorAll('tr');
            
            rows.forEach(row => {
                const rowDate = row.getAttribute('data-date');
                if (!rowDate) {
                    row.style.display = 'none';
                    return;
                }
                
                let showRow = true;
                
                // Filter by start date
                if (startDate && rowDate < startDate) {
                    showRow = false;
                }
                
                // Filter by end date
                if (endDate && rowDate > endDate) {
                    showRow = false;
                }
                
                if (showRow) {
                    row.style.display = 'table-row';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            if (visibleCount === 0 && (startDate || endDate)) {
                if (noResults) noResults.style.display = 'block';
                if (table) table.style.display = 'none';
            } else {
                if (noResults) noResults.style.display = 'none';
                if (table) table.style.display = 'table';
            }
        }
        
        // Clear Attendance Filter
        function clearAttendanceFilter() {
            document.getElementById('attendanceFilterStartDate').value = '';
            document.getElementById('attendanceFilterEndDate').value = '';
            
            const tableBody = document.getElementById('attendanceTableBody');
            const noResults = document.getElementById('attendanceNoResults');
            const table = document.getElementById('attendanceTable');
            
            if (tableBody) {
                const rows = tableBody.querySelectorAll('tr');
                rows.forEach(row => {
                    row.style.display = 'table-row';
                });
            }
            
            if (noResults) noResults.style.display = 'none';
            if (table) table.style.display = 'table';
        }

        // Black & White theme toggle
        const bwToggle = document.getElementById('bwToggle');
        function applyBwTheme(enable) {
            if (enable) {
                document.body.classList.add('bw-theme');
                localStorage.setItem('bwTheme', '1');
                bwToggle.textContent = 'Normal';
            } else {
                document.body.classList.remove('bw-theme');
                localStorage.removeItem('bwTheme');
                bwToggle.textContent = 'B&W';
            }
        }

        bwToggle.addEventListener('click', function() {
            const isBw = document.body.classList.contains('bw-theme');
            applyBwTheme(!isBw);
        });

        // Reset preference button: clears stored pref and reloads to normal
        const bwReset = document.getElementById('bwReset');
        bwReset.addEventListener('click', function() {
            try { localStorage.removeItem('bwTheme'); } catch(e) {}
            applyBwTheme(false);
        });

        // Initialize from preference
        (function() {
            try {
                const pref = localStorage.getItem('bwTheme');
                if (pref === '1') {
                    applyBwTheme(true);
                } else {
                    // ensure reset button hidden in normal mode
                    if (bwReset) bwReset.style.display = 'none';
                    if (bwToggle) bwToggle.textContent = 'B&W';
                }
            } catch(e) { /* ignore */ }
        })();

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