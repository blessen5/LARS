<?php
session_start();

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die('Unauthorized');
}

require_once('TCPDF-main/tcpdf.php');

$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    die('Database connection failed');
}

$user_id = $_SESSION['user_id'];
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$subject_id = isset($_GET['subject_id']) && $_GET['subject_id'] !== '' ? intval($_GET['subject_id']) : 0;

if (empty($start_date) || empty($end_date)) {
    die('Please provide valid date range');
}

// Get subject name if subject filter is applied
$subject_name = '';
if ($subject_id > 0) {
    $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $subject_name = $result->fetch_assoc()['subject_name'];
    }
    $stmt->close();
}

// Get student information
$stmt = $conn->prepare("SELECT name, admission_number, year FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
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

// Set document information
$pdf->SetCreator('Lab Activity Reporting System');
$pdf->SetAuthor('Sree Sankara Vidyapeetom College');
$pdf->SetTitle('Student Activity Report');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Add logo if exists (positioned on left side)
if ($logo_path && file_exists($logo_path)) {
    $pdf->Image($logo_path, 15, 15, 25, 25, '', '', '', false, 300, '', false, false, 0);
}

// College Header - Start from top with proper spacing for logo
$pdf->SetY(15);
$pdf->SetFont('helvetica', 'B', 15);
$pdf->Cell(0, 8, 'Sree Sankara Vidyapeetom College', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'Valayanchirangara', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Department of Computer Science', 0, 1, 'C');

// Space after header (removed line separator)
$pdf->Ln(8);

// Report Title
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(76, 81, 191);
$pdf->Cell(0, 8, 'Student Activity Report', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Date Range
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Period: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 0, 1, 'C');
if ($subject_id > 0 && !empty($subject_name)) {
    $pdf->Cell(0, 5, 'Subject: ' . $subject_name, 0, 1, 'C');
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
$sql = "SELECT 
            DATE(al.created_at) as activity_date,
            TIME(al.created_at) as activity_time,
            s.subject_name,
            al.log_text
        FROM activity_logs al
        LEFT JOIN subjects s ON al.subject_id = s.id
        WHERE al.user_id = ? 
            AND DATE(al.created_at) BETWEEN ? AND ?";
        
// Add subject filter if provided
if ($subject_id > 0) {
    $sql .= " AND al.subject_id = ?";
}

$sql .= " ORDER BY al.created_at DESC";

$stmt = $conn->prepare($sql);
if ($subject_id > 0) {
    $stmt->bind_param("issi", $user_id, $start_date, $end_date, $subject_id);
} else {
    $stmt->bind_param("iss", $user_id, $start_date, $end_date);
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
        
        $date = date('d-m-Y', strtotime($row['activity_date']));
        $time = $row['activity_time'];
        $subject = substr($row['subject_name'] ?? 'General', 0, 22);
        $activity = $row['log_text'];
        
        // Calculate row height based on activity text length
        $activityLines = $pdf->getNumLines($activity, 80);
        $rowHeight = max(6, $activityLines * 4);
        
        // Draw cells with proper height
        $pdf->MultiCell(30, $rowHeight, $date, 1, 'C', $fill, 0, '', '', true, 0, false, true, $rowHeight, 'M');
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
    
    $pdf->Cell(60, 6, 'Period:', 1, 0, 'L');
    $pdf->Cell(0, 6, date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 1, 1, 'L');
    
    // Calculate total days in period
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = $start->diff($end);
    $total_days = $interval->days + 1;
    
    $pdf->Cell(60, 6, 'Days in Period:', 1, 0, 'L');
    $pdf->Cell(0, 6, $total_days . ' days', 1, 1, 'L');
    
    $avg_activities = $total_days > 0 ? round($activity_count / $total_days, 2) : 0;
    $pdf->Cell(60, 6, 'Average Activities/Day:', 1, 0, 'L');
    $pdf->Cell(0, 6, $avg_activities, 1, 1, 'L');
    
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