<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    die('Unauthorized');
}

require_once('TCPDF-main/tcpdf.php');

$conn = new mysqli('localhost', 'root', '', 'lab_activity_system3');
if ($conn->connect_error) {
    die('Database connection failed');
}

// Get all settings from database
$logo_path = '';
$college_name = 'Sree Sankara Vidyapeetom College';
$college_location = 'Valayanchirangara, Kerala';
$department = 'Department of Computer Science';

$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        switch($row['setting_key']) {
            case 'logo_path':
                $logo_path = trim($row['setting_value']);
                break;
            case 'college_name':
                $college_name = trim($row['setting_value']);
                break;
            case 'college_location':
                $college_location = trim($row['setting_value']);
                break;
            case 'department':
                $department = trim($row['setting_value']);
                break;
        }
    }
}

$type = $_GET['type'] ?? 'activity';
$period = $_GET['period'] ?? 'range';
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$date = $_GET['date'] ?? date('Y-m-d');

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

$pdf->SetCreator('Lab Activity Reporting System');
$pdf->SetAuthor('Lab Administration');
$pdf->SetTitle('Report');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

$pdf->AddPage();

// Simple Header (without logo first to debug)
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(76, 81, 191);
$pdf->Cell(0, 8, $college_name, 0, 1, 'C');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 5, $college_location, 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, $department, 0, 1, 'C');

$pdf->Ln(3);

// Try to add logo with debug info
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(100, 100, 100);

// Check if logo exists
$logo_found = false;
$absolute_logo_path = '';

$paths_to_check = [
    $logo_path,
    __DIR__ . '/' . $logo_path,
    $_SERVER['DOCUMENT_ROOT'] . '/' . $logo_path,
    'assets/images/images.jpg',
    __DIR__ . '/assets/images/images.jpg',
    $_SERVER['DOCUMENT_ROOT'] . '/assets/images/images.jpg',
];

foreach ($paths_to_check as $path) {
    if (!empty($path) && file_exists($path)) {
        $absolute_logo_path = $path;
        $logo_found = true;
        break;
    }
}

if ($logo_found) {
    try {
        $pdf->Image($absolute_logo_path, 15, 15, 25, 25);
        $pdf->Cell(0, 5, 'Logo loaded from: ' . $absolute_logo_path, 0, 1);
    } catch (Exception $e) {
        $pdf->Cell(0, 5, 'Logo failed to load: ' . $e->getMessage(), 0, 1);
    }
} else {
    $pdf->Cell(0, 5, 'Logo NOT found. Database path: ' . $logo_path, 0, 1);
}

$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);

// Add simple activity data
if ($type === 'activity') {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, "Activity Report", 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    if ($period === 'daily') {
        $sql = "SELECT DATE(al.created_at) as date, TIME(al.created_at) as time, 
                u.name, u.admission_number, s.subject_name, al.log_text
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN subjects s ON al.subject_id = s.id
                WHERE u.role = 'student' AND DATE(al.created_at) = ?
                ORDER BY al.created_at DESC LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $date);
    } else {
        $sql = "SELECT DATE(al.created_at) as date, TIME(al.created_at) as time,
                u.name, u.admission_number, s.subject_name, al.log_text
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN subjects s ON al.subject_id = s.id
                WHERE u.role = 'student' AND DATE(al.created_at) BETWEEN ? AND ?
                ORDER BY al.created_at DESC LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $start_date, $end_date);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Simple table
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(76, 81, 191);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(20, 6, 'Date', 1, 0, 'C', true);
    $pdf->Cell(15, 6, 'Time', 1, 0, 'C', true);
    $pdf->Cell(35, 6, 'Student', 1, 0, 'C', true);
    $pdf->Cell(30, 6, 'Subject', 1, 0, 'C', true);
    $pdf->Cell(60, 6, 'Activity', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    
    while ($row = $result->fetch_assoc()) {
        $pdf->Cell(20, 5, date('d-m-Y', strtotime($row['date'])), 1, 0, 'C');
        $pdf->Cell(15, 5, $row['time'], 1, 0, 'C');
        $pdf->Cell(35, 5, substr($row['name'], 0, 15), 1, 0, 'L');
        $pdf->Cell(30, 5, substr($row['subject_name'] ?? 'N/A', 0, 15), 1, 0, 'L');
        $pdf->Cell(60, 5, substr($row['log_text'], 0, 30), 1, 1, 'L');
    }
    
    $stmt->close();
}

$conn->close();

// Output PDF
$filename = 'debug_report_' . date('Y-m-d_H-i-s') . '.pdf';
$pdf->Output($filename, 'D');
?>