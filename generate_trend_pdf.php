<?php
session_start();

// Staff only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    die('Unauthorized');
}

require_once('TCPDF-main/tcpdf.php');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die('Database connection failed');
}

$start = $_POST['start'] ?? $_GET['start'] ?? null;
$end = $_POST['end'] ?? $_GET['end'] ?? null;
if (!$start || !$end) die('Missing dates');
$start_date = date('Y-m-d', strtotime($start));
$end_date = date('Y-m-d', strtotime($end));

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create logs directory if it doesn't exist
$logDir = dirname(__FILE__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// Log function for debugging
function logDebug($message) {
    global $logDir;
    $logFile = $logDir . '/trend_report_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    error_log($logMessage, 3, $logFile);
}

// Log start of processing
logDebug("Starting trend report generation for period: $start_date to $end_date");

// full-day boundaries for clipping sessions
$start_dt = $start_date . ' 00:00:00';
$end_dt = $end_date . ' 23:59:59';

// Fetch college name and logo
$collegeName = 'Sree Sankara Vidyapeetom College, Valayanchirangara';
$logoPath = '';
$res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'college_name' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $r = $res->fetch_assoc();
    if (!empty($r['setting_value'])) $collegeName = $r['setting_value'];
}
$res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_path' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $r = $res->fetch_assoc();
    if (!empty($r['setting_value']) && file_exists($r['setting_value'])) {
        $logoPath = $r['setting_value'];
    }
}

// 1) Top user by time — compute session durations clipped to the requested range for accuracy
$topUserByTime = null;
$sql = "SELECT u.id, u.name, u.admission_number,
               SUM(
                   CASE WHEN LEAST(COALESCE(la2.login_time, NOW()), ?) > GREATEST(la1.login_time, ?) 
                        THEN TIMESTAMPDIFF(SECOND, GREATEST(la1.login_time, ?), LEAST(COALESCE(la2.login_time, NOW()), ?))
                        ELSE 0 END
               ) AS total_seconds
        FROM login_activity la1
        JOIN users u ON la1.user_id = u.id
        LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id
            AND la2.id = (
                SELECT id FROM login_activity WHERE user_id = la1.user_id AND id > la1.id ORDER BY id ASC LIMIT 1
            )
        WHERE u.role = 'student' 
          AND la1.login_time <= ? 
          AND COALESCE(la2.login_time, NOW()) >= ?
        GROUP BY u.id
        HAVING total_seconds > 0
        ORDER BY total_seconds DESC
        LIMIT 1";
$stmt = $conn->prepare($sql);
if ($stmt) {
    // bind: end_dt, start_dt, start_dt, end_dt, end_dt, start_dt
    $stmt->bind_param('ssssss', $end_dt, $start_dt, $start_dt, $end_dt, $end_dt, $start_dt);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) $topUserByTime = $row;
    $stmt->close();
}

// 2) Top user by "other activities" — prefer a dedicated column if present, otherwise fall back to text matching
$topUserByOther = null;
$useColumn = false;
$colCheckSql = "SELECT COUNT(*) as cnt FROM information_schema.columns WHERE table_schema = ? AND table_name = 'activity_logs' AND column_name IN ('activity_type','activity_category','activity_kind')";
$colStmt = $conn->prepare($colCheckSql);
if ($colStmt) {
    $colStmt->bind_param('s', $db_name);
    $colStmt->execute();
    $colRes = $colStmt->get_result();
    if ($r = $colRes->fetch_assoc()) {
        $useColumn = ($r['cnt'] > 0);
    }
    $colStmt->close();
}

if ($useColumn) {
    // check which column exists and use it
    $whichCol = null;
    $cols = ['activity_type','activity_category','activity_kind'];
    foreach ($cols as $c) {
        $q = "SELECT COUNT(*) as c FROM information_schema.columns WHERE table_schema = ? AND table_name = 'activity_logs' AND column_name = ?";
        $ps = $conn->prepare($q);
        if ($ps) {
            $ps->bind_param('ss', $db_name, $c);
            $ps->execute();
            $rr = $ps->get_result()->fetch_assoc();
            $ps->close();
            if ($rr && $rr['c'] > 0) { $whichCol = $c; break; }
        }
    }
    if ($whichCol) {
        $sql = "SELECT u.id, u.name, u.admission_number, COUNT(al.id) AS other_count
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                WHERE DATE(al.created_at) BETWEEN ? AND ?
                  AND (al." . $whichCol . " = 'other' OR al." . $whichCol . " = 'Other' OR al." . $whichCol . " = 'other_activity')
                GROUP BY u.id
                ORDER BY other_count DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $start_date, $end_date);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) $topUserByOther = $row;
            $stmt->close();
        }
    }
}

if (!$topUserByOther) {
    $sql = "SELECT u.id, u.name, u.admission_number, COUNT(al.id) AS other_count
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        WHERE DATE(al.created_at) BETWEEN ? AND ?
          AND (al.log_text LIKE '%other%' OR al.log_text LIKE '%Other%' OR al.log_text LIKE '%other activity%')
        GROUP BY u.id
        ORDER BY other_count DESC
        LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) $topUserByOther = $row;
        $stmt->close();
    }
}

// 3) Activity engagement (top 50)
$activityEngagement = [];
$sql = "SELECT u.id, u.name, u.admission_number, COUNT(al.id) AS activity_count
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        WHERE DATE(al.created_at) BETWEEN ? AND ?
        GROUP BY al.user_id
        ORDER BY activity_count DESC
        LIMIT 50";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $activityEngagement[] = $row;
}
$stmt->close();

// 4) Total distinct students who logged in
$totalStudents = 0;
$sql = "SELECT COUNT(DISTINCT user_id) AS cnt FROM login_activity WHERE DATE(login_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) $totalStudents = (int)$row['cnt'];
$stmt->close();

// 5) Total activities based on activity_logs
$totalActivities = 0;
$sql = "SELECT COUNT(*) AS cnt FROM activity_logs WHERE DATE(created_at) BETWEEN ? AND ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $totalActivities = (int)$r['cnt'];
    $stmt->close();
}

// 6) Subject usage - distinct students per subject from user_sessions
$subjectUsage = [];
$sql = "SELECT s.id, s.subject_name, COUNT(DISTINCT us.user_id) AS student_count
        FROM user_sessions us
        JOIN subjects s ON us.subject_id = s.id
        WHERE DATE(us.session_start) BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY student_count DESC
        LIMIT 50";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $subjectUsage[] = $r;
    }
    $stmt->close();
}

$subjectMostUsed = count($subjectUsage) ? $subjectUsage[0] : null;

// 7) Top user by activity logs (most activities)
$topUserByActivities = null;
$sql = "SELECT u.id, u.name, u.admission_number, COUNT(al.id) AS activity_count
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        WHERE DATE(al.created_at) BETWEEN ? AND ?
        GROUP BY al.user_id
        ORDER BY activity_count DESC
        LIMIT 1";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $topUserByActivities = $r;
    $stmt->close();
}

// Handle chart images and save them to temp files for embedding
$tmpFiles = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $imgFields = [
        'img_top' => ['name' => 'top', 'title' => 'User Engagement'],
        'img_pie' => ['name' => 'pie', 'title' => 'Usage Distribution'],
        'img_eng' => ['name' => 'eng', 'title' => 'Activity Patterns'],
        'img_monthly' => ['name' => 'monthly', 'title' => 'Monthly Usage'],
        'img_attendance' => ['name' => 'attendance', 'title' => 'Attendance']
    ];

    // Ensure temp directory exists and is writable
    $tempDir = sys_get_temp_dir();
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }

    // Process each chart image
    foreach ($imgFields as $field => $info) {
        if (!empty($_POST[$field])) {
            $data = $_POST[$field];
            
            // Handle both base64 and raw image data
            if (strpos($data, 'base64,') !== false) {
                list(, $data) = explode('base64,', $data);
            }
            
            $decoded = base64_decode($data);
            if ($decoded !== false) {
                // Generate a unique filename
                $filename = 'trend_' . $info['name'] . '_' . uniqid() . '.png';
                $filepath = $tempDir . DIRECTORY_SEPARATOR . $filename;
                
                // Save the image file
                if (file_put_contents($filepath, $decoded) !== false) {
                    $tmpFiles[$info['name']] = [
                        'path' => $filepath,
                        'title' => $info['title'],
                        'size' => strlen($decoded)
                    ];
                }
            }
        }
    }

    // Log debug information
    $debugLog = [
        'time' => date('c'),
        'files' => array_map(function($file) {
            return [
                'size' => $file['size'],
                'exists' => file_exists($file['path']),
                'readable' => is_readable($file['path'])
            ];
        }, $tmpFiles),
        'post_keys' => array_keys($_POST),
        'temp_dir' => $tempDir,
        'temp_dir_writable' => is_writable($tempDir)
    ];
    
    // Save debug log
    $debugLogPath = $tempDir . DIRECTORY_SEPARATOR . 'trend_pdf_debug_' . uniqid() . '.log';
    file_put_contents($debugLogPath, json_encode($debugLog, JSON_PRETTY_PRINT));
}

// Build PDF using TCPDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('Lab Activity Reporting System');
$pdf->SetAuthor($collegeName);
$pdf->SetTitle('Trend Report');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();

// Logo and Header Section
$pdf->SetY(15);

// Logo on the left
if ($logoPath && file_exists($logoPath)) {
    $pdf->Image($logoPath, 15, $pdf->GetY(), 25, 25, '', '', '', false, 300, '', false, false, 0);
    $headerX = 45; // Start text after logo
} else {
    $headerX = 15;
}

// College Name and Report Title
$pdf->SetX($headerX);
$pdf->SetFont('helvetica', 'B', 16);
$pdf->MultiCell(180 - ($headerX - 15), 10, $collegeName, 0, 'C', false, 1, '', '', true, 0, false, true, 15, 'M');

$pdf->SetX($headerX);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(180 - ($headerX - 15), 8, 'Lab Activity Trend Report', 0, 1, 'C');
$pdf->Ln(4);

// Period
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 6, 'Period: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 0, 1, 'C');
$pdf->Ln(6);

// Key Statistics Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'KEY STATISTICS', 0, 1, 'L');
$pdf->SetFillColor(240, 240, 240);
$pdf->Rect(15, $pdf->GetY(), 180, 40, 'F');
$pdf->SetFont('helvetica', '', 10);
$pdf->Ln(2);
// Top user by time
if ($topUserByTime) {
    $hours = floor($topUserByTime['total_seconds'] / 3600);
    $minutes = floor(($topUserByTime['total_seconds'] % 3600) / 60);
    $seconds = $topUserByTime['total_seconds'] % 60;
    $timeStr = sprintf("%d hours, %d minutes, %d seconds", $hours, $minutes, $seconds);
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, '1. Most Active Lab User:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, '    ' . ($topUserByTime['name'] ?: $topUserByTime['admission_number']), 0, 1, 'L');
    $pdf->Cell(0, 6, '    Total Time: ' . $timeStr, 0, 1, 'L');
    $pdf->Ln(4);
}

// Top user by other activities
if ($topUserByOther) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'User who used the lab for other activities most:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, ($topUserByOther['name'] ?: $topUserByOther['admission_number']) . ' — ' . ($topUserByOther['other_count'] ?? 0) . ' activity logs', 0, 1, 'L');
    $pdf->Ln(2);
}

// Total students and otherActivitiesCount
$pdf->Cell(0, 6, 'Total distinct students in period: ' . $totalStudents, 0, 1, 'L');

$pdf->Cell(0, 6, 'Total activities logged in period: ' . $totalActivities, 0, 1, 'L');

// Show top user by activity logs
if ($topUserByActivities) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, '2. Most Active User (By Activity Count):', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, '    ' . ($topUserByActivities['name'] ?: $topUserByActivities['admission_number']), 0, 1, 'L');
    $pdf->Cell(0, 6, '    Total Activities: ' . ($topUserByActivities['activity_count'] ?? 0) . ' logs', 0, 1, 'L');
    $pdf->Ln(4);
}

// Show most-used subject
if ($subjectMostUsed) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, '3. Most Popular Subject:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, '    ' . ($subjectMostUsed['subject_name'] ?: 'N/A'), 0, 1, 'L');
    $pdf->Cell(0, 6, '    Used by ' . ($subjectMostUsed['student_count'] ?? 0) . ' distinct students', 0, 1, 'L');
    $pdf->Ln(4);
}

// Summary line
$pdf->Ln(4);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 180, $pdf->GetY());
$pdf->Ln(8);

// Additional summary stats
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Additional Statistics:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// Activity engagement table (top 20)
$pdf->Ln(6);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, 'Top Activity Engagement (by logs)', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

// table header
$pdf->SetFillColor(76, 81, 191);
$pdf->SetTextColor(255,255,255);
$pdf->Cell(80, 7, 'User', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Admission No.', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Activity Logs', 1, 1, 'C', true);

$pdf->SetTextColor(0,0,0);
$rows = 0;
foreach ($activityEngagement as $r) {
    if ($rows++ >= 20) break;
    $pdf->Cell(80, 6, substr($r['name'] ?: $r['admission_number'], 0, 30), 1, 0, 'L');
    $pdf->Cell(40, 6, $r['admission_number'] ?? '', 1, 0, 'L');
    $pdf->Cell(40, 6, $r['activity_count'], 1, 1, 'C');
}

$pdf->Ln(8);
$pdf->Ln(8);
// Subject usage table (top subjects by distinct students)
if (!empty($subjectUsage)) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'Subject Usage (by distinct students)', 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(76, 81, 191);
    $pdf->SetTextColor(255,255,255);
    $pdf->Cell(140, 7, 'Subject', 1, 0, 'C', true);
    $pdf->Cell(40, 7, 'Students', 1, 1, 'C', true);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('helvetica', '', 9);
    foreach ($subjectUsage as $s) {
        $pdf->Cell(140, 6, substr($s['subject_name'] ?? 'N/A', 0, 70), 1, 0, 'L');
        $pdf->Cell(40, 6, $s['student_count'], 1, 1, 'C');
    }
    $pdf->Ln(8);
}

// Process and insert charts
try {
    // Log chart processing start
    logDebug("Starting chart processing");
    
    // Verify if we have chart data in POST
    if (empty($_POST)) {
        logDebug("No POST data received for charts");
    }
    
    $chartFields = ['img_top', 'img_pie', 'img_eng', 'img_monthly', 'img_attendance'];
    foreach ($chartFields as $field) {
        if (isset($_POST[$field])) {
            logDebug("Found data for chart: $field");
        }
    }
    
    // Process chart images if available
    if (!empty($tmpFiles)) {
        logDebug("Starting chart insertion into PDF");
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(240, 240, 245);
        $pdf->Rect(15, $pdf->GetY(), 180, 20, 'F');
        $pdf->Cell(0, 12, 'LAB ENGAGEMENT ANALYSIS', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 8, date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date)), 0, 1, 'C');
        $pdf->Ln(8);
        
        // Log chart files status
        foreach ($tmpFiles as $key => $file) {
            if (is_array($file)) {
                $path = $file['path'] ?? 'N/A';
                $exists = file_exists($path) ? 'Yes' : 'No';
                $size = file_exists($path) ? filesize($path) : 0;
                logDebug("Chart '$key': Path=$path, Exists=$exists, Size=$size bytes");
            } else {
                $exists = file_exists($file) ? 'Yes' : 'No';
                $size = file_exists($file) ? filesize($file) : 0;
                logDebug("Chart '$key': Path=$file, Exists=$exists, Size=$size bytes");
            }
        }

    // Calculate page dimensions
    $margins = $pdf->getMargins();
    $pageWidth = $pdf->getPageWidth() - $margins['left'] - $margins['right'];
    
    // Function to add a chart with proper formatting
    $addChart = function($chartKey, $defaultTitle, $fullWidth = true) use ($pdf, $tmpFiles, $margins, $pageWidth) {
        logDebug("Attempting to add chart: $chartKey");
        
        // Get the chart file path
        $chartPath = '';
        if (!empty($tmpFiles[$chartKey])) {
            if (is_array($tmpFiles[$chartKey])) {
                $chartPath = $tmpFiles[$chartKey]['path'] ?? '';
            } else {
                $chartPath = $tmpFiles[$chartKey];
            }
        }
        
        // Verify chart file
        if (!empty($chartPath) && file_exists($chartPath)) {
            logDebug("Processing chart '$chartKey' from path: $chartPath");
            
            try {
            // Chart section background
            $pdf->SetFillColor(248, 248, 252);
            $pdf->Rect($margins['left'], $pdf->GetY(), $pageWidth, 10, 'F');
            
            // Chart title
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetTextColor(50, 50, 50);
            $title = $tmpFiles[$chartKey]['title'] ?? $defaultTitle;
            $pdf->Cell(0, 10, $title, 0, 1, 'C');
            
            // Add chart image
            $width = $fullWidth ? $pageWidth : ($pageWidth / 2 - 5);
            $height = $fullWidth ? 80 : 60;
            
            try {
                $pdf->Image(
                    $tmpFiles[$chartKey]['path'],
                    $fullWidth ? $margins['left'] : ($pdf->GetX()),
                    $pdf->GetY(),
                    $width,
                    $height,
                    'PNG'
                );
                $pdf->Ln($height + 10);
                return true;
            } catch (Exception $e) {
                $pdf->SetFont('helvetica', '', 10);
                $pdf->Cell(0, 6, '[' . $title . ' chart could not be loaded]', 0, 1, 'C');
                $pdf->Ln(4);
                return false;
            }
        }
        return false;
    };

    // Add main engagement chart
    if (!empty($tmpFiles['top'])) {
        $addChart('top', 'Daily Lab Usage Pattern', true);
    }

    // Add detailed metrics section if we have pie or engagement charts
    if (!empty($tmpFiles['pie']) || !empty($tmpFiles['eng'])) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Detailed Engagement Metrics', 0, 1, 'C');
        $pdf->Ln(4);

        // Save current Y position for side-by-side charts
        $startY = $pdf->GetY();
        
        // Place charts side by side if both exist
        if (!empty($tmpFiles['pie']) && !empty($tmpFiles['eng'])) {
            // First chart (left)
            $pdf->SetX($margins['left']);
            $addChart('pie', 'Usage Distribution', false);
            
            // Second chart (right)
            $pdf->SetXY($margins['left'] + $pageWidth/2 + 5, $startY);
            $addChart('eng', 'Activity Pattern', false);
            
            // Reset position
            $pdf->SetY($startY + 70);
        } else {
            // Single chart - full width
            if (!empty($tmpFiles['pie'])) {
                $addChart('pie', 'Usage Distribution', true);
            }
            if (!empty($tmpFiles['eng'])) {
                $addChart('eng', 'Activity Pattern', true);
            }
        }
        
        $pdf->Ln(10);
    }
}

    // Additional charts: monthly engagement and attendance
    if (!empty($tmpFiles['monthly']) || !empty($tmpFiles['attendance'])) {
        $pdf->AddPage();
        
        // Section header
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(240, 240, 245);
        $pdf->Rect(15, $pdf->GetY(), 180, 15, 'F');
        $pdf->Cell(0, 15, 'DETAILED ANALYSIS CHARTS', 0, 1, 'C');
        $pdf->Ln(8);
        
        // Monthly Engagement Chart
        if (!empty($tmpFiles['monthly'])) {
            $addChart('monthly', 'Monthly Engagement Trends', true);
            $pdf->Ln(5);
        }
        
        // Attendance Chart
        if (!empty($tmpFiles['attendance'])) {
            $addChart('attendance', 'Daily Attendance Patterns', true);
            $pdf->Ln(5);
        }
    }
    
    // Cleanup temp files after PDF is generated
    if (!empty($tmpFiles)) {
        foreach ($tmpFiles as $fileInfo) {
            if (is_array($fileInfo) && !empty($fileInfo['path']) && file_exists($fileInfo['path'])) {
                @unlink($fileInfo['path']);
            }
        }
    }
}

// Footer note
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 5, 'Generated by Lab Activity Reporting System on ' . date('d/m/Y, H:i:s'), 0, 1, 'C');

$conn->close();

// Output PDF
$filename = 'trend_report_' . $start_date . '_' . $end_date . '.pdf';
$pdf->Output($filename, 'D');

// cleanup temp files
if (!empty($tmpFiles)) {
    foreach ($tmpFiles as $f) {
        if (file_exists($f)) @unlink($f);
    }
}

exit;
