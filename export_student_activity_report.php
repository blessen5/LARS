<?php
session_start();

// Check if user is logged in and is admin or staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    die('Unauthorized');
}

require_once('TCPDF-main/tcpdf.php');

$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    die('Database connection failed');
}

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$type = $_GET['type'] ?? 'range';
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$date = $_GET['date'] ?? date('Y-m-d');

if ($student_id <= 0) {
    die('Invalid student ID');
}

// Get student information
$stmt = $conn->prepare("SELECT name, admission_number, year FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    die('Student not found');
}
$student_info = $result->fetch_assoc();
$stmt->close();

// Get logo path
$logo_path = '';
$result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_path' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if (!empty($row['setting_value']) && file_exists($row['setting_value'])) {
        $logo_path = $row['setting_value'];
    }
}

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

$pdf->SetCreator('Lab Activity Reporting System');
$pdf->SetAuthor('Sree Sankara Vidyapeetom College');
$pdf->SetTitle('Student Activity Report');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

$pdf->AddPage();

// Add logo if exists
if ($logo_path && file_exists($logo_path)) {
    $pdf->Image($logo_path, 15, 15, 25, 25, '', '', '', false, 300, '', false, false, 0);
}

// College Header
$pdf->SetY(15);
$pdf->SetFont('helvetica', 'B', 15);
$pdf->Cell(0, 8, 'Sree Sankara Vidyapeetom College', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'Valayanchirangara', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Department of Computer Science', 0, 1, 'C');

$pdf->Ln(8);

// Report Title
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(76, 81, 191);
$pdf->Cell(0, 8, 'Student Activity Report', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Date Range
$pdf->SetFont('helvetica', '', 10);
if ($type === 'daily') {
    $pdf->Cell(0, 5, 'Period: ' . date('d-m-Y', strtotime($date)), 0, 1, 'C');
} else {
    $pdf->Cell(0, 5, 'Period: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 0, 1, 'C');
}

$pdf->Ln(1);

// Generated timestamp
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'Generated on: ' . date('d/m/Y, h:i:s A'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(6);

// Student Information Box
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 7, 'Student Information', 1, 1, 'L', true);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(50, 6, 'Name:', 1, 0, 'L');
$pdf->Cell(0, 6, $student_info['name'], 1, 1, 'L');
$pdf->Cell(50, 6, 'Admission Number:', 1, 0, 'L');
$pdf->Cell(0, 6, $student_info['admission_number'], 1, 1, 'L');
$pdf->Cell(50, 6, 'Batch/Year:', 1, 0, 'L');
$pdf->Cell(0, 6, $student_info['year'] ?? 'N/A', 1, 1, 'L');
$pdf->Ln(5);

// Fetch activity logs
if ($type === 'daily') {
    $sql = "SELECT 
                DATE(al.created_at) as activity_date,
                TIME(al.created_at) as activity_time,
                s.subject_name,
                al.log_text
            FROM activity_logs al
            LEFT JOIN subjects s ON al.subject_id = s.id
            WHERE al.user_id = ? AND DATE(al.created_at) = ?
            ORDER BY al.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $student_id, $date);
} else {
    $sql = "SELECT 
                DATE(al.created_at) as activity_date,
                TIME(al.created_at) as activity_time,
                s.subject_name,
                al.log_text
            FROM activity_logs al
            LEFT JOIN subjects s ON al.subject_id = s.id
            WHERE al.user_id = ? AND DATE(al.created_at) BETWEEN ? AND ?
            ORDER BY al.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $student_id, $start_date, $end_date);
}

$stmt->execute();
$result = $stmt->get_result();
$activity_count = 0;

if ($result->num_rows > 0) {
    // Table header
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(76, 81, 191);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(30, 7, 'Date', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Time', 1, 0, 'C', true);
    $pdf->Cell(45, 7, 'Subject', 1, 0, 'C', true);
    $pdf->Cell(80, 7, 'Activity Description', 1, 1, 'C', true);
    
    // Table data
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(240, 240, 240);
    $fill = false;
    
    while ($row = $result->fetch_assoc()) {
        $activity_count++;
        
        // Check if we need a new page
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
        }
        
        $date_formatted = date('d-m-Y', strtotime($row['activity_date']));
        $time = $row['activity_time'];
        $subject = substr($row['subject_name'] ?? 'General', 0, 22);
        $activity = $row['log_text'];
        
        // Calculate row height based on activity text length
        $activityLines = $pdf->getNumLines($activity, 80);
        $rowHeight = max(6, $activityLines * 4);
        
        // Draw cells with proper height
        $pdf->MultiCell(30, $rowHeight, $date_formatted, 1, 'C', $fill, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        $pdf->MultiCell(25, $rowHeight, $time, 1, 'C', $fill, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        $pdf->MultiCell(45, $rowHeight, $subject, 1, 'L', $fill, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        $pdf->MultiCell(80, $rowHeight, $activity, 1, 'L', $fill, 1, '', '', true, 0, false, true, $rowHeight, 'T');
        
        $fill = !$fill;
    }
    
    // Summary Section
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 7, 'Activity Summary', 1, 1, 'L', true);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(60, 6, 'Total Activities Logged:', 1, 0, 'L');
    $pdf->Cell(0, 6, $activity_count, 1, 1, 'L');
    
    if ($type === 'daily') {
        $pdf->Cell(60, 6, 'Date:', 1, 0, 'L');
        $pdf->Cell(0, 6, date('d-m-Y', strtotime($date)), 1, 1, 'L');
    } else {
        $pdf->Cell(60, 6, 'Period:', 1, 0, 'L');
        $pdf->Cell(0, 6, date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 1, 1, 'L');
        
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = $start->diff($end);
        $total_days = $interval->days + 1;
        
        $pdf->Cell(60, 6, 'Days in Period:', 1, 0, 'L');
        $pdf->Cell(0, 6, $total_days . ' days', 1, 1, 'L');
        
        $avg_activities = $total_days > 0 ? round($activity_count / $total_days, 2) : 0;
        $pdf->Cell(60, 6, 'Average Activities/Day:', 1, 0, 'L');
        $pdf->Cell(0, 6, $avg_activities, 1, 1, 'L');
    }
    
} else {
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, 'No activity logs found for the selected period', 1, 1, 'C', true);
}

$stmt->close();

// Footer
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'This is a system-generated report. Keep logging your activities regularly.', 0, 1, 'C');

$conn->close();

// Output PDF
$filename = 'Activity_' . $student_info['admission_number'] . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
?>