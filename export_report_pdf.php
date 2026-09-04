<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    die('Unauthorized');
}

require_once('TCPDF-main/tcpdf.php');

$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    die('Database connection failed');
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
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$batch = isset($_GET['batch']) ? trim($_GET['batch']) : '';

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

// Add College Header
addCollegeHeader($pdf, $logo_path, $college_name, $college_location, $department);

if ($type === 'activity') {
    // Activity Report
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(76, 81, 191);
    
    // Get batch filter if provided
    $batch = isset($_GET['batch']) ? trim($_GET['batch']) : '';
    
    if ($period === 'daily') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $pdf->Cell(0, 10, "Student Activity Report - " . date('d-m-Y (l)', strtotime($date)), 0, 1, 'C');
        
        // Build SQL with optional batch filter
        $sql = "SELECT DATE(al.created_at) as date, TIME(al.created_at) as time, 
                u.name, u.admission_number, u.year, s.subject_name, al.log_text
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN subjects s ON al.subject_id = s.id
                WHERE u.role = 'student' AND DATE(al.created_at) = ?";
        
        // Add batch filter if provided
        if ($batch !== '') {
            $sql .= " AND u.year = ?";
        }
        
        $sql .= " ORDER BY al.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        if ($batch !== '') {
            $stmt->bind_param("ss", $date, $batch);
        } else {
            $stmt->bind_param("s", $date);
        }
    } else {
        $start = $_GET['start_date'] ?? '';
        $end = $_GET['end_date'] ?? '';
        $pdf->Cell(0, 10, "Student Activity Report from " . date('d-m-Y', strtotime($start)) . " to " . date('d-m-Y', strtotime($end)), 0, 1, 'C');
        
        // Build SQL with optional batch filter
        $sql = "SELECT DATE(al.created_at) as date, TIME(al.created_at) as time,
                u.name, u.admission_number, u.year, s.subject_name, al.log_text
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN subjects s ON al.subject_id = s.id
                WHERE u.role = 'student' AND DATE(al.created_at) BETWEEN ? AND ?";
        
        // Add batch filter if provided
        if ($batch !== '') {
            $sql .= " AND u.year = ?";
        }
        
        $sql .= " ORDER BY al.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        if ($batch !== '') {
            $stmt->bind_param("sss", $start, $end, $batch);
        } else {
            $stmt->bind_param("ss", $start, $end);
        }
    }
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, 'Generated on: ' . date('d/m/Y, h:i:s A'), 0, 1, 'C');
    $pdf->Ln(5);
    
    $stmt->execute();
    $result = $stmt->get_result();
    $total_records = $result->num_rows;
    
    if ($total_records > 0) {
        // Table header
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(76, 81, 191);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(22, 7, 'Date', 1, 0, 'C', true);
        $pdf->Cell(18, 7, 'Time', 1, 0, 'C', true);
        $pdf->Cell(25, 7, 'Adm. No.', 1, 0, 'C', true);
        $pdf->Cell(40, 7, 'Student Name', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Subject', 1, 0, 'C', true);
        $pdf->Cell(55, 7, 'Activity Description', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(240, 240, 240);
        $fill = false;
        
        while ($row = $result->fetch_assoc()) {
            // Check if we need a new page
            if ($pdf->GetY() > 260) {
                $pdf->AddPage();
                addCollegeHeader($pdf, $logo_path, $college_name, $college_location, $department);
                
                // Repeat table header
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(76, 81, 191);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell(22, 7, 'Date', 1, 0, 'C', true);
                $pdf->Cell(18, 7, 'Time', 1, 0, 'C', true);
                $pdf->Cell(25, 7, 'Adm. No.', 1, 0, 'C', true);
                $pdf->Cell(40, 7, 'Student Name', 1, 0, 'C', true);
                $pdf->Cell(30, 7, 'Subject', 1, 0, 'C', true);
                $pdf->Cell(55, 7, 'Activity Description', 1, 1, 'C', true);
                
                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFillColor(240, 240, 240);
                $fill = false;
            }
            
            $pdf->Cell(22, 6, date('d-m-Y', strtotime($row['date'])), 1, 0, 'C', $fill);
            $pdf->Cell(18, 6, $row['time'], 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, $row['admission_number'] ?? 'N/A', 1, 0, 'C', $fill);
            $pdf->Cell(40, 6, substr($row['name'], 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(30, 6, substr($row['subject_name'] ?? 'N/A', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(55, 6, substr($row['log_text'], 0, 35), 1, 1, 'L', $fill);
            $fill = !$fill;
        }
        
        // Summary Section
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 7, 'Activity Summary', 1, 1, 'L', true);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(60, 6, 'Total Activities Logged:', 1, 0, 'L');
        $pdf->Cell(0, 6, $total_records, 1, 1, 'L');
        
        if ($period === 'daily') {
            $pdf->Cell(60, 6, 'Date:', 1, 0, 'L');
            $pdf->Cell(0, 6, date('d-m-Y (l)', strtotime($date)), 1, 1, 'L');
        } else {
            $pdf->Cell(60, 6, 'Period:', 1, 0, 'L');
            $pdf->Cell(0, 6, date('d-m-Y', strtotime($start)) . ' to ' . date('d-m-Y', strtotime($end)), 1, 1, 'L');
            
            $start_obj = new DateTime($start);
            $end_obj = new DateTime($end);
            $interval = $start_obj->diff($end_obj);
            $total_days = $interval->days + 1;
            
            $pdf->Cell(60, 6, 'Days in Period:', 1, 0, 'L');
            $pdf->Cell(0, 6, $total_days . ' days', 1, 1, 'L');
            
            $avg_activities = $total_days > 0 ? round($total_records / $total_days, 2) : 0;
            $pdf->Cell(60, 6, 'Average Activities/Day:', 1, 0, 'L');
            $pdf->Cell(0, 6, $avg_activities, 1, 1, 'L');
        }
    } else {
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'No student activity logs found for the selected period', 1, 1, 'C', true);
    }
    
    $stmt->close();
    
} elseif ($type === 'attendance') {
    // Attendance Report
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(76, 81, 191);
    
    if ($period === 'daily') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $pdf->Cell(0, 10, "Student Attendance Report - " . date('d-m-Y (l)', strtotime($date)), 0, 1, 'C');
        
        $sql = "SELECT a.date, u.name, u.admission_number, u.year, s.subject_name, a.status
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN subjects s ON a.subject_id = s.id
                WHERE u.role = 'student' AND a.date = ?
                " . ($subject_id > 0 ? " AND a.subject_id = ?" : "") .
                ($batch !== '' ? " AND u.year = ?" : "") . "
                ORDER BY u.name";
        $stmt = $conn->prepare($sql);
        if ($subject_id > 0 && $batch !== '') {
            $stmt->bind_param("sis", $date, $subject_id, $batch);
        } elseif ($subject_id > 0) {
            $stmt->bind_param("si", $date, $subject_id);
        } elseif ($batch !== '') {
            $stmt->bind_param("ss", $date, $batch);
        } else {
            $stmt->bind_param("s", $date);
        }
    } else {
        $start = $_GET['start_date'] ?? '';
        $end = $_GET['end_date'] ?? '';
        $pdf->Cell(0, 10, "Student Attendance Report from " . date('d-m-Y', strtotime($start)) . " to " . date('d-m-Y', strtotime($end)), 0, 1, 'C');
        
        $sql = "SELECT a.date, u.name, u.admission_number, u.year, s.subject_name, a.status
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN subjects s ON a.subject_id = s.id
                WHERE u.role = 'student' AND a.date BETWEEN ? AND ?
                " . ($subject_id > 0 ? " AND a.subject_id = ?" : "") .
                ($batch !== '' ? " AND u.year = ?" : "") . "
                ORDER BY a.date DESC, u.name";
        $stmt = $conn->prepare($sql);
        if ($subject_id > 0 && $batch !== '') {
            $stmt->bind_param("ssis", $start, $end, $subject_id, $batch);
        } elseif ($subject_id > 0) {
            $stmt->bind_param("ssi", $start, $end, $subject_id);
        } elseif ($batch !== '') {
            $stmt->bind_param("sss", $start, $end, $batch);
        } else {
            $stmt->bind_param("ss", $start, $end);
        }
    }
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, 'Generated on: ' . date('d/m/Y, h:i:s A'), 0, 1, 'C');
    $pdf->Ln(5);
    
    $stmt->execute();
    $result = $stmt->get_result();
    $total_records = $result->num_rows;
    $total_present = 0;
    $total_late = 0;
    $total_absent = 0;
    
    if ($total_records > 0) {
        // Table header
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(76, 81, 191);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(22, 7, 'Date', 1, 0, 'C', true);
        $pdf->Cell(25, 7, 'Adm. No.', 1, 0, 'C', true);
        $pdf->Cell(45, 7, 'Student Name', 1, 0, 'C', true);
        $pdf->Cell(35, 7, 'Subject', 1, 0, 'C', true);
        $pdf->Cell(43, 7, 'Status', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(240, 240, 240);
        $fill = false;
        
        while ($row = $result->fetch_assoc()) {
            $statusLower = strtolower($row['status'] ?? '');
            if ($statusLower === 'present') $total_present++;
            elseif ($statusLower === 'late') $total_late++;
            elseif ($statusLower === 'absent') $total_absent++;
            // Check if we need a new page
            if ($pdf->GetY() > 260) {
                $pdf->AddPage();
                addCollegeHeader($pdf, $logo_path);
                
                // Repeat table header
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(76, 81, 191);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell(22, 7, 'Date', 1, 0, 'C', true);
                $pdf->Cell(25, 7, 'Adm. No.', 1, 0, 'C', true);
                $pdf->Cell(45, 7, 'Student Name', 1, 0, 'C', true);
                $pdf->Cell(35, 7, 'Subject', 1, 0, 'C', true);
                $pdf->Cell(43, 7, 'Status', 1, 1, 'C', true);
                
                $pdf->SetFont('helvetica', '', 9);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFillColor(240, 240, 240);
                $fill = false;
            }
            
            $statusBg = '#ffffff';
            if (strtolower($row['status']) === 'present') {
                $pdf->SetFillColor(144, 238, 144); // Light green
            } elseif (strtolower($row['status']) === 'late') {
                $pdf->SetFillColor(255, 255, 150); // Light yellow
            } elseif (strtolower($row['status']) === 'absent') {
                $pdf->SetFillColor(255, 200, 200); // Light red
            } else {
                $pdf->SetFillColor(240, 240, 240);
            }
            
            $pdf->Cell(22, 6, date('d-m-Y', strtotime($row['date'])), 1, 0, 'C', true);
            $pdf->Cell(25, 6, $row['admission_number'] ?? 'N/A', 1, 0, 'C', true);
            $pdf->Cell(45, 6, substr($row['name'], 0, 25), 1, 0, 'L', true);
            $pdf->Cell(35, 6, substr($row['subject_name'] ?? 'N/A', 0, 18), 1, 0, 'L', true);
            $pdf->Cell(43, 6, strtoupper($row['status'] ?? 'N/A'), 1, 1, 'C', true);
            
            $pdf->SetFillColor(240, 240, 240);
        }
        
        // Summary Section
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 7, 'Attendance Summary', 1, 1, 'L', true);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(60, 6, 'Total Records:', 1, 0, 'L');
        $pdf->Cell(0, 6, $total_records, 1, 1, 'L');

        // Totals by status
        $pdf->SetFillColor(220, 252, 231);
        $pdf->Cell(60, 6, 'Present:', 1, 0, 'L', true);
        $pdf->Cell(0, 6, $total_present . ' days', 1, 1, 'L', true);
        $pdf->SetFillColor(254, 249, 195);
        $pdf->Cell(60, 6, 'Late:', 1, 0, 'L', true);
        $pdf->Cell(0, 6, $total_late . ' days', 1, 1, 'L', true);
        $pdf->SetFillColor(254, 226, 226);
        $pdf->Cell(60, 6, 'Absent:', 1, 0, 'L', true);
        $pdf->Cell(0, 6, $total_absent . ' days', 1, 1, 'L', true);

        $attendance_percentage = $total_records > 0 ? round((($total_present + $total_late) / $total_records) * 100, 2) : 0;
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(60, 6, 'Attendance Percentage:', 1, 0, 'L', true);
        $pdf->Cell(0, 6, $attendance_percentage . '%', 1, 1, 'L', true);
        
        if ($period === 'daily') {
            $pdf->Cell(60, 6, 'Date:', 1, 0, 'L');
            $pdf->Cell(0, 6, date('d-m-Y (l)', strtotime($date)), 1, 1, 'L');
        } else {
            $pdf->Cell(60, 6, 'Period:', 1, 0, 'L');
            $pdf->Cell(0, 6, date('d-m-Y', strtotime($start)) . ' to ' . date('d-m-Y', strtotime($end)), 1, 1, 'L');
        }
    } else {
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'No attendance records found for the selected period', 1, 1, 'C', true);
    }
    
    $stmt->close();
}

// Footer
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'This is a system-generated report from Lab Activity Reporting System', 0, 1, 'C');
$pdf->Cell(0, 4, 'Sree Sankara Vidyapeetom College, Valayanchirangara', 0, 1, 'C');

$conn->close();

// Output PDF
$filename = $type . '_report_' . date('Y-m-d_H-i-s') . '.pdf';
$pdf->Output($filename, 'D');

/**
 * Add College Header to PDF
 */
function addCollegeHeader($pdf, $logo_path, $college_name = '', $college_location = '', $department = '') {
    // Set defaults if not provided
    if (empty($college_name)) $college_name = 'Sree Sankara Vidyapeetom College';
    if (empty($college_location)) $college_location = 'Valayanchirangara, Kerala';
    if (empty($department)) $department = 'Department of Computer Science';
    // Resolve absolute path for logo
    $absolute_logo_path = '';
    
    // Try multiple path variations
    $paths_to_try = [
        $logo_path, // Original path from database
        __DIR__ . '/' . $logo_path, // Absolute path from root
        $_SERVER['DOCUMENT_ROOT'] . '/' . $logo_path, // Server root
        'assets/images/college_logo.png',
        __DIR__ . '/assets/images/college_logo.png',
        $_SERVER['DOCUMENT_ROOT'] . '/assets/images/college_logo.png',
    ];
    
    foreach ($paths_to_try as $path) {
        if (!empty($path) && file_exists($path)) {
            $absolute_logo_path = $path;
            break;
        }
    }
    
    // Add logo if exists
    if (!empty($absolute_logo_path)) {
        try {
            $pdf->Image($absolute_logo_path, 15, 15, 20, 20, '', '', '', false, 300, '', false, false, 0);
        } catch (Exception $e) {
            // Logo failed to load, continue without it
        }
    }
    
    // College Header - centered
    $pdf->SetY(15);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(76, 81, 191);
    $pdf->Cell(0, 8, $college_name, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5, $college_location, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, $department, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 4, 'Lab Activity Reporting System', 0, 1, 'C');
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    
    // Add separator line
    $pdf->SetDrawColor(76, 81, 191);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(4);
}
?>