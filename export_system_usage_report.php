<?php
/**
 * System Usage Report PDF Export
 * Shows System ID, Number of Logins, and Total Usage Time
 * Includes college logo and name
 */

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

$period = $_GET['period'] ?? '';
$date = $_GET['date'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Lab Activity Reporting System');
$pdf->SetAuthor($college_name);
$pdf->SetTitle('System Usage Report');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Add College Header with Logo
addCollegeHeader($pdf, $logo_path, $college_name, $college_location, $department);

// Report Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(76, 81, 191);
$pdf->Cell(0, 10, 'System Usage Report', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Report Period
$pdf->SetFont('helvetica', '', 9);
if ($period === 'daily') {
    $pdf->Cell(0, 5, 'Date: ' . date('F j, Y', strtotime($date)), 0, 1, 'C');
    $date_filter = "DATE(la.login_time) = '$date'";
} elseif ($period === 'range') {
    $pdf->Cell(0, 5, 'Period: ' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)), 0, 1, 'C');
    $date_filter = "DATE(la.login_time) BETWEEN '$start_date' AND '$end_date'";
} else {
    $pdf->Cell(0, 5, 'All Time Report', 0, 1, 'C');
    $date_filter = "1=1";
}

$pdf->Ln(5);

// Query to get system usage data
// Get unique system IDs and calculate login count and total usage time
// Join system_usage with login_activity to get System ID, Login Count, and Total Usage Time
$query = "SELECT 
            su.system_id,
            COUNT(DISTINCT la.id) as login_count,
            COALESCE(SUM(TIMESTAMPDIFF(SECOND, la.login_time, 
                COALESCE(
                    (SELECT la2.login_time 
                     FROM login_activity la2 
                     WHERE la2.user_id = la.user_id 
                     AND la2.id > la.id 
                     ORDER BY la2.id ASC 
                     LIMIT 1), 
                    NOW()
                )
            )), 0) as total_seconds
          FROM 
            (SELECT DISTINCT system_id, ip_address FROM system_usage WHERE system_id IS NOT NULL AND system_id != '') su
          LEFT JOIN 
            login_activity la ON su.ip_address = la.ip_address 
            AND $date_filter
          GROUP BY 
            su.system_id
          ORDER BY 
            login_count DESC, su.system_id ASC";

$result = $conn->query($query);

// Table header
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(76, 81, 191);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(15, $pdf->GetY());
$pdf->Cell(60, 10, 'System ID', 1, 0, 'C', true);
$pdf->Cell(50, 10, 'Number of Logins', 1, 0, 'C', true);
$pdf->Cell(70, 10, 'Total Usage', 1, 1, 'C', true);

// Table data
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$y = $pdf->GetY();
$total_logins = 0;
$total_usage_seconds = 0;
$row_count = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $system_id = $row['system_id'] ?? 'N/A';
        $login_count = intval($row['login_count'] ?? 0);
        $total_seconds = intval($row['total_seconds'] ?? 0);
        
        // Format total usage time
        $hours = floor($total_seconds / 3600);
        $minutes = floor(($total_seconds % 3600) / 60);
        $seconds = $total_seconds % 60;
        $usage_display = sprintf("%dh %02dm %02ds", $hours, $minutes, $seconds);
        
        $total_logins += $login_count;
        $total_usage_seconds += $total_seconds;
        
        // Alternate row colors
        $fill = ($row_count % 2 == 0) ? false : true;
        $fill_color = $fill ? array(245, 245, 245) : false;
        
        $pdf->SetXY(15, $y);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Cell(60, 8, $system_id, 1, 0, 'L', $fill);
        $pdf->Cell(50, 8, $login_count, 1, 0, 'C', $fill);
        $pdf->Cell(70, 8, $usage_display, 1, 1, 'C', $fill);
        
        $y = $pdf->GetY();
        $row_count++;
        
        // Add new page if needed
        if ($y > 250) {
            $pdf->AddPage();
            // Re-add header on new page
            addCollegeHeader($pdf, $logo_path, $college_name, $college_location, $department);
            $pdf->SetY(60);
            
            // Re-print table header
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetFillColor(76, 81, 191);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(15, $pdf->GetY());
            $pdf->Cell(60, 10, 'System ID', 1, 0, 'C', true);
            $pdf->Cell(50, 10, 'Number of Logins', 1, 0, 'C', true);
            $pdf->Cell(70, 10, 'Total Usage', 1, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $y = $pdf->GetY();
        }
    }
    
    // Add summary row
    $y += 5;
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetXY(15, $y);
    $total_hours = floor($total_usage_seconds / 3600);
    $total_minutes = floor(($total_usage_seconds % 3600) / 60);
    $total_seconds_remainder = $total_usage_seconds % 60;
    $total_usage_display = sprintf("%dh %02dm %02ds", $total_hours, $total_minutes, $total_seconds_remainder);
    
    $pdf->Cell(60, 10, 'TOTAL', 1, 0, 'C', true);
    $pdf->Cell(50, 10, $total_logins, 1, 0, 'C', true);
    $pdf->Cell(70, 10, $total_usage_display, 1, 1, 'C', true);
    
} else {
    $pdf->SetXY(15, $y);
    $pdf->Cell(180, 10, 'No system usage data found for the selected period', 1, 1, 'C');
}

// Summary Statistics
$y = $pdf->GetY() + 15;
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetXY(15, $y);
$pdf->Cell(0, 10, 'Report Summary', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$y += 10;

$total_systems = $row_count;
$pdf->SetXY(15, $y);
$pdf->Cell(0, 8, '• Total Systems: ' . $total_systems, 0, 1, 'L');
$y += 8;
$pdf->SetXY(15, $y);
$pdf->Cell(0, 8, '• Total Logins: ' . $total_logins, 0, 1, 'L');
$y += 8;
$pdf->SetXY(15, $y);
$pdf->Cell(0, 8, '• Total Usage Time: ' . $total_usage_display, 0, 1, 'L');

// Footer
$y += 15;
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetXY(15, $y);
$pdf->Cell(0, 8, 'Generated on: ' . date('F j, Y, h:i A'), 0, 1, 'L');
$y += 8;
$pdf->SetXY(15, $y);
$pdf->Cell(0, 8, 'Generated by: ' . ($_SESSION['username'] ?? 'System'), 0, 1, 'L');

// Output PDF
$filename = 'System_Usage_Report_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');

$conn->close();

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
        $logo_path,
        __DIR__ . '/' . $logo_path,
        $_SERVER['DOCUMENT_ROOT'] . '/' . $logo_path,
        'assets/images/college_logo.png',
        __DIR__ . '/assets/images/college_logo.png',
        $_SERVER['DOCUMENT_ROOT'] . '/assets/images/college_logo.png',
        'assets/images/images.jpg',
        __DIR__ . '/assets/images/images.jpg',
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
}
