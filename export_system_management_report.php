<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized');
}

require_once('TCPDF-main/tcpdf.php');

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
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

// Fetch all systems from database
$all_systems = [];
$check_table = "SHOW TABLES LIKE 'systems'";
$result = $conn->query($check_table);
if ($result && $result->num_rows > 0) {
    $sql = "SELECT * FROM systems ORDER BY system_name";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $all_systems[] = $row;
        }
    }
}

// Calculate system usage report summary
$total_systems = count($all_systems);
$total_logins = 0;
$total_usage = 0;
foreach ($all_systems as $sys) {
    $total_logins += intval($sys['login_count'] ?? 0);
    $total_usage += intval($sys['usage_count'] ?? 0);
}

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

// Add College Header
addCollegeHeader($pdf, $logo_path, $college_name, $college_location, $department);

// Report Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(76, 81, 191);
$pdf->Cell(0, 10, 'System Usage Report', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Generated date
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 5, 'Generated on: ' . date('d/m/Y, h:i:s A'), 0, 1, 'C');
$pdf->Ln(5);

// Summary Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 8, 'Summary Statistics', 0, 1, 'L', true);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetFillColor(255, 255, 255);

// Summary boxes
$pdf->Ln(2);
$box_width = 55;
$box_height = 25;
$start_x = 15;
$spacing = 5;

// Total Systems
$pdf->SetFillColor(230, 240, 255);
$pdf->Rect($start_x, $pdf->GetY(), $box_width, $box_height, 'F');
$pdf->SetXY($start_x, $pdf->GetY() + 2);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($box_width, 5, 'Total Systems', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY($start_x, $pdf->GetY());
$pdf->Cell($box_width, 8, $total_systems, 0, 1, 'C');

// Total Logins
$pdf->SetXY($start_x + $box_width + $spacing, $pdf->GetY() - 15);
$pdf->SetFillColor(255, 240, 230);
$pdf->Rect($start_x + $box_width + $spacing, $pdf->GetY(), $box_width, $box_height, 'F');
$pdf->SetXY($start_x + $box_width + $spacing, $pdf->GetY() + 2);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($box_width, 5, 'Total Logins', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY($start_x + $box_width + $spacing, $pdf->GetY());
$pdf->Cell($box_width, 8, $total_logins, 0, 1, 'C');

// Total Usage
$pdf->SetXY($start_x + ($box_width + $spacing) * 2, $pdf->GetY() - 15);
$pdf->SetFillColor(240, 255, 240);
$pdf->Rect($start_x + ($box_width + $spacing) * 2, $pdf->GetY(), $box_width, $box_height, 'F');
$pdf->SetXY($start_x + ($box_width + $spacing) * 2, $pdf->GetY() + 2);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($box_width, 5, 'Total Usage Count', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY($start_x + ($box_width + $spacing) * 2, $pdf->GetY());
$pdf->Cell($box_width, 8, $total_usage, 0, 1, 'C');

$pdf->Ln(10);

// Systems Table
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'System Details', 0, 1, 'L');
$pdf->Ln(2);

if (empty($all_systems)) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'No systems found in the database.', 0, 1, 'C');
} else {
    // Table Header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(76, 81, 191);
    $pdf->SetTextColor(255, 255, 255);
    
    $col_widths = [40, 70, 40, 40];
    $headers = ['System ID', 'System Name', 'Logins', 'Usages'];
    $x = 15;
    
    for ($i = 0; $i < count($headers); $i++) {
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->Cell($col_widths[$i], 8, $headers[$i], 1, 0, 'C', true);
        $x += $col_widths[$i];
    }
    $pdf->Ln();
    
    // Table Data
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 9);
    $fill = false;
    
    foreach ($all_systems as $system) {
        // Check if we need a new page
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
            // Re-add header on new page
            addCollegeHeader($pdf, $logo_path, $college_name, $college_location, $department);
            $pdf->SetY(60);
            
            // Re-print table header
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(76, 81, 191);
            $pdf->SetTextColor(255, 255, 255);
            $x = 15;
            for ($i = 0; $i < count($headers); $i++) {
                $pdf->SetXY($x, $pdf->GetY());
                $pdf->Cell($col_widths[$i], 8, $headers[$i], 1, 0, 'C', true);
                $x += $col_widths[$i];
            }
            $pdf->Ln();
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 9);
        }
        
        $pdf->SetFillColor(245, 245, 245);
        $x = 15;
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->Cell($col_widths[0], 7, htmlspecialchars($system['system_id']), 1, 0, 'L', $fill);
        $x += $col_widths[0];
        
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->Cell($col_widths[1], 7, htmlspecialchars($system['system_name']), 1, 0, 'L', $fill);
        $x += $col_widths[1];
        
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->Cell($col_widths[2], 7, intval($system['login_count'] ?? 0), 1, 0, 'C', $fill);
        $x += $col_widths[2];
        
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->Cell($col_widths[3], 7, intval($system['usage_count'] ?? 0), 1, 1, 'C', $fill);
        
        $fill = !$fill;
    }
}

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
        $logo_path, // Original path from database
        __DIR__ . '/' . $logo_path, // Absolute path from root
        $_SERVER['DOCUMENT_ROOT'] . '/' . $logo_path, // Server root
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

