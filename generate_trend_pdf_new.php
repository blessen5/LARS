<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized access');
}

// Include TCPDF library
require_once('TCPDF-main/tcpdf.php');

// Get parameters
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';

if (empty($start) || empty($end)) {
    die('Missing date parameters');
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch data
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

// 5. Daily login statistics
$sql = "SELECT DATE(la.login_time) as date, 
        COUNT(DISTINCT la.user_id) as unique_users,
        COUNT(*) as total_logins
        FROM login_activity la
        JOIN users u ON la.user_id = u.id
        WHERE DATE(la.login_time) BETWEEN ? AND ?
        AND u.role = 'student'
        GROUP BY DATE(la.login_time)
        ORDER BY date ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();
$daily_stats = [];
while ($row = $result->fetch_assoc()) {
    $daily_stats[] = $row;
}
$stmt->close();

$conn->close();

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('LARS');
$pdf->SetAuthor('Lab Activity Reporting System');
$pdf->SetTitle('Trend Report');
$pdf->SetSubject('Trend Analysis Report');

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 18);

// Title
$pdf->Cell(0, 10, 'Lab Activity Trend Report', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'Period: ' . date('d-m-Y', strtotime($start)) . ' to ' . date('d-m-Y', strtotime($end)), 0, 1, 'C');
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

// Add new page for more data
$pdf->AddPage();

// 3. Activity Engagement
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, '3. Student Activity Engagement', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(231, 76, 60);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(15, 8, 'Rank', 1, 0, 'C', true);
$pdf->Cell(90, 8, 'Student Name', 1, 0, 'L', true);
$pdf->Cell(40, 8, 'Admission No.', 1, 0, 'C', true);
$pdf->Cell(35, 8, 'Activities', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);

$rank = 1;
$fill = false;
foreach ($activity_engagement as $student) {
    $pdf->Cell(15, 7, $rank++, 1, 0, 'C', $fill);
    $pdf->Cell(90, 7, $student['name'], 1, 0, 'L', $fill);
    $pdf->Cell(40, 7, $student['admission_number'], 1, 0, 'C', $fill);
    $pdf->Cell(35, 7, $student['activity_count'], 1, 1, 'C', $fill);
    $fill = !$fill;
}

$pdf->Ln(8);

// 4. Daily Login Statistics
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, '4. Daily Login Statistics', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(155, 89, 182);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(60, 8, 'Date', 1, 0, 'C', true);
$pdf->Cell(60, 8, 'Unique Users', 1, 0, 'C', true);
$pdf->Cell(60, 8, 'Total Logins', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);

$fill = false;
foreach ($daily_stats as $stat) {
    $pdf->Cell(60, 7, date('d-m-Y', strtotime($stat['date'])), 1, 0, 'C', $fill);
    $pdf->Cell(60, 7, $stat['unique_users'], 1, 0, 'C', $fill);
    $pdf->Cell(60, 7, $stat['total_logins'], 1, 1, 'C', $fill);
    $fill = !$fill;
}

// Add new page for attendance
if (count($attendance_summary) > 0) {
    $pdf->AddPage();
    
    // 5. Attendance Summary
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, '5. Attendance Summary', 0, 1, 'L');
    $pdf->Ln(2);
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(45, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Present', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Late', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Absent', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    
    $fill = false;
    foreach ($attendance_summary as $att) {
        $pdf->Cell(45, 7, date('d-m-Y', strtotime($att['date'])), 1, 0, 'C', $fill);
        $pdf->Cell(45, 7, $att['present_count'], 1, 0, 'C', $fill);
        $pdf->Cell(45, 7, $att['late_count'], 1, 0, 'C', $fill);
        $pdf->Cell(45, 7, $att['absent_count'], 1, 1, 'C', $fill);
        $fill = !$fill;
    }
}

// Output PDF
$filename = 'trend_report_' . $start . '_to_' . $end . '.pdf';
$pdf->Output($filename, 'D');
exit();
?>