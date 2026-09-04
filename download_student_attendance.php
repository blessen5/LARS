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
$pdf->SetTitle('Student Attendance Report');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);

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
$pdf->Ln(6);

// Report Title
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(76, 81, 191);
$pdf->Cell(0, 8, 'Student Attendance Report', 0, 1, 'C');
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

// Fetch attendance data with staff/admin who marked it
$sql = "SELECT 
            a.date,
            a.status,
            s.subject_name,
            la.login_time,
            u_marker.name as marked_by_name,
            u_marker.role as marked_by_role
        FROM attendance a
        LEFT JOIN subjects s ON a.subject_id = s.id
        LEFT JOIN login_activity la ON a.user_id = la.user_id 
            AND DATE(la.login_time) = a.date
        LEFT JOIN users u_marker ON a.marked_by = u_marker.id
        WHERE a.user_id = ? 
            AND a.date BETWEEN ? AND ?";
        
// Add subject filter if provided
if ($subject_id > 0) {
    $sql .= " AND a.subject_id = ?";
}

$sql .= " ORDER BY a.date DESC, la.login_time DESC";

$stmt = $conn->prepare($sql);
if ($subject_id > 0) {
    $stmt->bind_param("issi", $user_id, $start_date, $end_date, $subject_id);
} else {
    $stmt->bind_param("iss", $user_id, $start_date, $end_date);
}
$stmt->execute();
$result = $stmt->get_result();

// Table header
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(76, 81, 191);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(30, 7, 'Date', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'Time', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Subject', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Status', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'Marked By', 1, 1, 'C', true);

// Table data
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$fill = false;

$total_present = 0;
$total_late = 0;
$total_absent = 0;
$record_count = 0;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $record_count++;
        
        // Count status
        if ($row['status'] == 'present') $total_present++;
        elseif ($row['status'] == 'late') $total_late++;
        elseif ($row['status'] == 'absent') $total_absent++;
        
        // Set fill color based on status
        if ($row['status'] == 'present') {
            $pdf->SetFillColor(220, 252, 231);
        } elseif ($row['status'] == 'late') {
            $pdf->SetFillColor(254, 249, 195);
        } elseif ($row['status'] == 'absent') {
            $pdf->SetFillColor(254, 226, 226);
        } else {
            $pdf->SetFillColor(240, 240, 240);
        }
        
        $pdf->Cell(30, 6, date('d-m-Y', strtotime($row['date'])), 1, 0, 'C', true);
        $pdf->Cell(25, 6, $row['login_time'] ? date('H:i', strtotime($row['login_time'])) : 'N/A', 1, 0, 'C', true);
        $pdf->Cell(50, 6, substr($row['subject_name'] ?? 'N/A', 0, 25), 1, 0, 'L', true);
        $pdf->Cell(30, 6, ucfirst($row['status']), 1, 0, 'C', true);
        $pdf->Cell(45, 6, substr($row['marked_by_name'] ?? 'System', 0, 28), 1, 1, 'L', true);
    }
} else {
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 8, 'No attendance records found for the selected period', 1, 1, 'C', true);
}

$stmt->close();

// Summary Section
if ($record_count > 0) {
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 7, 'Attendance Summary', 1, 1, 'L', true);
    
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(60, 6, 'Total Days Marked:', 1, 0, 'L');
    $pdf->Cell(0, 6, $record_count, 1, 1, 'L');
    
    $pdf->SetFillColor(220, 252, 231);
    $pdf->Cell(60, 6, 'Present:', 1, 0, 'L', true);
    $pdf->Cell(0, 6, $total_present . ' days', 1, 1, 'L', true);
    
    $pdf->SetFillColor(254, 249, 195);
    $pdf->Cell(60, 6, 'Late:', 1, 0, 'L', true);
    $pdf->Cell(0, 6, $total_late . ' days', 1, 1, 'L', true);
    
    $pdf->SetFillColor(254, 226, 226);
    $pdf->Cell(60, 6, 'Absent:', 1, 0, 'L', true);
    $pdf->Cell(0, 6, $total_absent . ' days', 1, 1, 'L', true);
    
    // Calculate percentage
    $total_days = $record_count;
    $attendance_percentage = $total_days > 0 ? round((($total_present + $total_late) / $total_days) * 100, 2) : 0;
    
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(60, 6, 'Attendance Percentage:', 1, 0, 'L', true);
    $pdf->Cell(0, 6, $attendance_percentage . '%', 1, 1, 'L', true);
}

// Footer
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'This is a system-generated report. For any discrepancies, please contact the lab administrator.', 0, 1, 'C');

$conn->close();

// Output PDF
$filename = 'Attendance_' . $student_info['admission_number'] . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
?>