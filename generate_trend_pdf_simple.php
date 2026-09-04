<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    die('Unauthorized access');
}

// Include TCPDF library
require_once('TCPDF-main/tcpdf.php');

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get all settings from database
$logo_path = 'assets/images/images.jpg';
$college_name = 'Sree Sankara Vidyapeetom College';
$college_location = 'Valayanchirangara, Kerala';
$department = 'Department of Computer Science';

$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($row['setting_key'] === 'logo_path' && !empty($row['setting_value'])) {
            $logo_path = $row['setting_value'];
        } elseif ($row['setting_key'] === 'college_name' && !empty($row['setting_value'])) {
            $college_name = $row['setting_value'];
        } elseif ($row['setting_key'] === 'college_location' && !empty($row['setting_value'])) {
            $college_location = $row['setting_value'];
        } elseif ($row['setting_key'] === 'department' && !empty($row['setting_value'])) {
            $department = $row['setting_value'];
        }
    }
}

$type = $_GET['type'] ?? '';
$period = $_GET['period'] ?? '';

// Get parameters
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';

if (empty($start) || empty($end)) {
    die('Missing date parameters');
}

try {
    // Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Lab Activity Reporting System');
$pdf->SetAuthor('Sree Sankara Vidyapeetom College');
$pdf->SetTitle('Report');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 18);

// Add logo and college name
if (file_exists($logo_path)) {
    $pdf->Image($logo_path, 15, 10, 20, '', '', '', 'T', false, 300, '', false, false, 0, false, false, false);
} else {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 10, 'Logo not found', 0, 1, 'L');
}

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, $college_name, 0, 1, 'C');
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 6, $college_location, 0, 1, 'C');
$pdf->Cell(0, 6, $department, 0, 1, 'C');
$pdf->Ln(10);

// Title
$pdf->SetFont('helvetica', 'B', 18);
$pdf->Cell(0, 10, 'Lab Activity Trend Report', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'Period: ' . date('d-m-Y', strtotime($start)) . ' to ' . date('d-m-Y', strtotime($end)), 0, 1, 'C');
$pdf->Ln(8);

// Fetch data
// 1. Top Users by Time
$sql = "SELECT u.name, u.admission_number, 
        SUM(TIMESTAMPDIFF(SECOND, us.session_start, COALESCE(us.session_end, NOW()))) as total_seconds
        FROM users u
        JOIN user_sessions us ON u.id = us.user_id
        WHERE u.role = 'student' 
        AND DATE(us.session_start) BETWEEN ? AND ?
        GROUP BY u.id, u.name, u.admission_number
        ORDER BY total_seconds DESC
        LIMIT 15";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();
$top_users = [];
while ($row = $result->fetch_assoc()) {
    $top_users[] = $row;
}
$stmt->close();

// 2. Activity Engagement
$sql = "SELECT u.name, u.admission_number, COUNT(al.id) as activity_count
        FROM users u
        LEFT JOIN activity_logs al ON u.id = al.user_id AND DATE(al.created_at) BETWEEN ? AND ?
        WHERE u.role = 'student'
        GROUP BY u.id, u.name, u.admission_number
        HAVING activity_count > 0
        ORDER BY activity_count DESC
        LIMIT 20";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();
$activity_engagement = [];
while ($row = $result->fetch_assoc()) {
    $activity_engagement[] = $row;
}
$stmt->close();

// 3. Attendance Summary
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

// 4. Subject-wise usage
$sql = "SELECT s.subject_name, COUNT(DISTINCT us.user_id) as student_count,
        SUM(TIMESTAMPDIFF(SECOND, us.session_start, COALESCE(us.session_end, NOW()))) as total_seconds
        FROM user_sessions us
        JOIN subjects s ON us.subject_id = s.id
        WHERE DATE(us.session_start) BETWEEN ? AND ?
        GROUP BY s.id, s.subject_name
        ORDER BY total_seconds DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();
$subject_usage = [];
while ($row = $result->fetch_assoc()) {
    $subject_usage[] = $row;
}
$stmt->close();

// 5. Monthly login statistics
$sql = "SELECT YEAR(la.login_time) as year, MONTHNAME(la.login_time) as month,
        COUNT(DISTINCT la.user_id) as unique_users,
        COUNT(*) as total_logins
        FROM login_activity la
        JOIN users u ON la.user_id = u.id
        WHERE la.login_time BETWEEN ? AND ?
        AND u.role = 'student'
        GROUP BY YEAR(la.login_time), MONTH(la.login_time)
        ORDER BY year, MONTH(la.login_time) ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();
$monthly_stats = [];
while ($row = $result->fetch_assoc()) {
    $monthly_stats[] = $row;
}
$stmt->close();

$conn->close();

if (empty($top_users) && empty($activity_engagement) && empty($attendance_summary) && empty($subject_usage) && empty($monthly_stats)) {
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 20, 'No data available for the selected date range.', 0, 1, 'C');
} else {
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'Key Metrics', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetFillColor(245, 245, 245);

$total_logins = array_sum(array_column($monthly_stats, 'total_logins'));

if (!empty($top_users)) {
    $hours = floor($top_users[0]['total_seconds'] / 3600);
    $minutes = floor(($top_users[0]['total_seconds'] % 3600) / 60);
    $most_active_user = $top_users[0]['name'] . ' (' . $hours . 'h ' . $minutes . 'm)';
} else {
    $most_active_user = 'N/A';
}

if (!empty($subject_usage)) {
    $hours = floor($subject_usage[0]['total_seconds'] / 3600);
    $minutes = floor(($subject_usage[0]['total_seconds'] % 3600) / 60);
    $most_used_subject = $subject_usage[0]['subject_name'] . ' (' . $hours . 'h ' . $minutes . 'm)';
} else {
    $most_used_subject = 'N/A';
}

$pdf->Cell(50, 8, 'Most Active User:', 1, 0, 'L', true);
$pdf->Cell(130, 8, $most_active_user, 1, 1, 'L', true);
$pdf->Cell(50, 8, 'Most Used Subject:', 1, 0, 'L', true);
$pdf->Cell(130, 8, $most_used_subject, 1, 1, 'L', true);
$pdf->Cell(50, 8, 'Total Logins:', 1, 0, 'L', true);
$pdf->Cell(130, 8, $total_logins, 1, 1, 'L', true);

$pdf->Ln(8);

// 1. Top Users by Lab Time
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, '1. Top Users by Lab Time', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(52, 152, 219);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(15, 8, 'Rank', 1, 0, 'C', true);
$pdf->Cell(80, 8, 'Student Name', 1, 0, 'L', true);
$pdf->Cell(45, 8, 'Admission No.', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'Total Time', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(240, 240, 240);

$rank = 1;
foreach ($top_users as $user) {
    $hours = floor($user['total_seconds'] / 3600);
    $minutes = floor(($user['total_seconds'] % 3600) / 60);
    $time_str = $hours . 'h ' . $minutes . 'm';
    
    $pdf->Cell(15, 7, $rank++, 1, 0, 'C', $rank % 2 == 0);
    $pdf->Cell(80, 7, $user['name'], 1, 0, 'L', $rank % 2 == 0);
    $pdf->Cell(45, 7, $user['admission_number'], 1, 0, 'C', $rank % 2 == 0);
    $pdf->Cell(40, 7, $time_str, 1, 1, 'C', $rank % 2 == 0);
}

$pdf->Ln(8);

// Add new page for more data
$pdf->AddPage();

// 2. Subject-wise Usage
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, '2. Subject-wise Lab Usage', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(46, 204, 113);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(90, 8, 'Subject Name', 1, 0, 'L', true);
$pdf->Cell(45, 8, 'Students', 1, 0, 'C', true);
$pdf->Cell(45, 8, 'Total Time', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);

$fill = false;
foreach ($subject_usage as $subject) {
    $hours = floor($subject['total_seconds'] / 3600);
    $minutes = floor(($subject['total_seconds'] % 3600) / 60);
    $time_str = $hours . 'h ' . $minutes . 'm';
    
    $pdf->Cell(90, 7, $subject['subject_name'], 1, 0, 'L', $fill);
    $pdf->Cell(45, 7, $subject['student_count'], 1, 0, 'C', $fill);
    $pdf->Cell(45, 7, $time_str, 1, 1, 'C', $fill);
    $fill = !$fill;
}

$pdf->Ln(8);

// 3. Monthly Login Statistics
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, '3. Monthly Login Statistics', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(155, 89, 182);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(60, 8, 'Month', 1, 0, 'C', true);
$pdf->Cell(60, 8, 'Unique Users', 1, 0, 'C', true);
$pdf->Cell(60, 8, 'Total Logins', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);

$fill = false;
foreach ($monthly_stats as $stat) {
    $pdf->Cell(60, 7, $stat['month'] . ' ' . $stat['year'], 1, 0, 'C', $fill);
    $pdf->Cell(60, 7, $stat['unique_users'], 1, 0, 'C', $fill);
    $pdf->Cell(60, 7, $stat['total_logins'], 1, 1, 'C', $fill);
    $fill = !$fill;
}

$pdf->Ln(8);

// 4. Actively Engaged Users
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, '4. Actively Engaged Users', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(243, 156, 18); // Orange
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(80, 8, 'Student Name', 1, 0, 'L', true);
$pdf->Cell(50, 8, 'Admission No.', 1, 0, 'C', true);
$pdf->Cell(50, 8, 'Activity Count', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);

$fill = false;
foreach ($activity_engagement as $user) {
    $pdf->Cell(80, 7, $user['name'], 1, 0, 'L', $fill);
    $pdf->Cell(50, 7, $user['admission_number'], 1, 0, 'C', $fill);
    $pdf->Cell(50, 7, $user['activity_count'], 1, 1, 'C', $fill);
    $fill = !$fill;
}

}

// Output PDF
$filename = 'lab_activity_trend_report_' . $start . '_to_' . $end . '.pdf';
$pdf->Output($filename, 'D');
} catch (Exception $e) {
    error_log('PDF Generation Error: ' . $e->getMessage());
    die('An error occurred while generating the PDF report. Please try again later.');
}

exit();
?>