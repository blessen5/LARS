<?php
session_start();

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if TCPDF exists
if (!file_exists('TCPDF-main/tcpdf.php')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'TCPDF library not found']);
    exit();
}

require_once('TCPDF-main/tcpdf.php');

// Database connection
$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get date range
$start = $_POST['start'] ?? $_GET['start'] ?? null;
$end = $_POST['end'] ?? $_GET['end'] ?? null;

if (!$start || !$end) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing dates']);
    exit();
}

$start_date = date('Y-m-d', strtotime($start));
$end_date = date('Y-m-d', strtotime($end));
$start_dt = $start_date . ' 00:00:00';
$end_dt = $end_date . ' 23:59:59';

// Fetch college details
$collegeName = 'Sree Sankara Vidyapeetom College, Valayanchirangara';
$logoPath = '';

$res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'college_name' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    if (!empty($row['setting_value'])) {
        $collegeName = $row['setting_value'];
    }
}

$res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_path' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    if (!empty($row['setting_value']) && file_exists($row['setting_value'])) {
        $logoPath = $row['setting_value'];
    }
}

// 1. Get top user by total time
$topUserByTime = null;
$sql = "SELECT u.id, u.name, u.admission_number,
        SUM(TIMESTAMPDIFF(SECOND, 
            GREATEST(la1.login_time, ?), 
            LEAST(COALESCE(la2.login_time, NOW()), ?)
        )) AS total_seconds
        FROM login_activity la1
        JOIN users u ON la1.user_id = u.id
        LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
            AND la2.id = (SELECT MIN(id) FROM login_activity WHERE user_id = la1.user_id AND id > la1.id)
        WHERE u.role = 'student' 
          AND la1.login_time <= ?
          AND COALESCE(la2.login_time, NOW()) >= ?
        GROUP BY u.id
        HAVING total_seconds > 0
        ORDER BY total_seconds DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ssss', $start_dt, $end_dt, $end_dt, $start_dt);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $topUserByTime = $row;
}
$stmt->close();

// 2. Get top user by activity logs
$topUserByActivities = null;
$sql = "SELECT u.id, u.name, u.admission_number, COUNT(al.id) AS activity_count
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        WHERE DATE(al.created_at) BETWEEN ? AND ?
        GROUP BY al.user_id
        ORDER BY activity_count DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $topUserByActivities = $row;
}
$stmt->close();

// 3. Get most used subject
$mostUsedSubject = null;
$sql = "SELECT s.id, s.subject_name, COUNT(DISTINCT us.user_id) AS student_count
        FROM user_sessions us
        JOIN subjects s ON us.subject_id = s.id
        WHERE DATE(us.session_start) BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY student_count DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $mostUsedSubject = $row;
}
$stmt->close();

// 4. Get statistics
$totalStudents = 0;
$sql = "SELECT COUNT(DISTINCT user_id) AS cnt FROM login_activity WHERE DATE(login_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $totalStudents = (int)$row['cnt'];
}
$stmt->close();

$totalActivities = 0;
$sql = "SELECT COUNT(*) AS cnt FROM activity_logs WHERE DATE(created_at) BETWEEN ? AND ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $totalActivities = (int)$row['cnt'];
}
$stmt->close();

// 5. Get top 10 users by time
$topUsers = [];
$sql = "SELECT u.name, u.admission_number,
        SUM(TIMESTAMPDIFF(SECOND, 
            GREATEST(la1.login_time, ?), 
            LEAST(COALESCE(la2.login_time, NOW()), ?)
        )) AS total_seconds
        FROM login_activity la1
        JOIN users u ON la1.user_id = u.id
        LEFT JOIN login_activity la2 ON la1.user_id = la2.user_id 
            AND la2.id = (SELECT MIN(id) FROM login_activity WHERE user_id = la1.user_id AND id > la1.id)
        WHERE u.role = 'student'
          AND la1.login_time <= ?
          AND COALESCE(la2.login_time, NOW()) >= ?
        GROUP BY u.id
        HAVING total_seconds > 0
        ORDER BY total_seconds DESC
        LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ssss', $start_dt, $end_dt, $end_dt, $start_dt);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $topUsers[] = $row;
}
$stmt->close();

// 6. Subject usage
$subjectUsage = [];
$sql = "SELECT s.subject_name, COUNT(DISTINCT us.user_id) AS student_count
        FROM user_sessions us
        JOIN subjects s ON us.subject_id = s.id
        WHERE DATE(us.session_start) BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY student_count DESC
        LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $subjectUsage[] = $row;
}
$stmt->close();

$conn->close();

// Create PDF
try {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    $pdf->SetCreator('Lab Activity Reporting System');
    $pdf->SetAuthor($collegeName);
    $pdf->SetTitle('Lab Activity Trend Report');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    
    // Header with logo
    $headerY = 15;
    if ($logoPath && file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, $headerY, 25, 25, '', '', '', false, 300);
        $headerX = 45;
    } else {
        $headerX = 15;
    }
    
    $pdf->SetY($headerY);
    $pdf->SetX($headerX);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, $collegeName, 0, 1, 'C');
    
    $pdf->SetX($headerX);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'Lab Activity Trend Report', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Period: ' . date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Key Statistics
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 10, 'KEY STATISTICS', 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
    
    // 1. Most Active User by Time
    if ($topUserByTime) {
        $hours = floor($topUserByTime['total_seconds'] / 3600);
        $minutes = floor(($topUserByTime['total_seconds'] % 3600) / 60);
        $seconds = $topUserByTime['total_seconds'] % 60;
        
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, '1. Most Active Lab User (By Duration):', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, '   Student: ' . ($topUserByTime['name'] ?: $topUserByTime['admission_number']), 0, 1);
        $pdf->Cell(0, 6, '   Admission No: ' . $topUserByTime['admission_number'], 0, 1);
        $pdf->Cell(0, 6, sprintf('   Total Time: %d hours, %d minutes, %d seconds', $hours, $minutes, $seconds), 0, 1);
        $pdf->Ln(4);
    }
    
    // 2. Most Active User by Activities
    if ($topUserByActivities) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, '2. Most Active User (By Activity Count):', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, '   Student: ' . ($topUserByActivities['name'] ?: $topUserByActivities['admission_number']), 0, 1);
        $pdf->Cell(0, 6, '   Admission No: ' . $topUserByActivities['admission_number'], 0, 1);
        $pdf->Cell(0, 6, '   Total Activities: ' . $topUserByActivities['activity_count'] . ' logs', 0, 1);
        $pdf->Ln(4);
    }
    
    // 3. Most Used Subject
    if ($mostUsedSubject) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, '3. Most Popular Subject:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, '   Subject: ' . $mostUsedSubject['subject_name'], 0, 1);
        $pdf->Cell(0, 6, '   Used by: ' . $mostUsedSubject['student_count'] . ' distinct students', 0, 1);
        $pdf->Ln(4);
    }
    
    // 4. General Statistics
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, '4. General Statistics:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, '   Total Students: ' . $totalStudents, 0, 1);
    $pdf->Cell(0, 6, '   Total Activities Logged: ' . $totalActivities, 0, 1);
    $pdf->Ln(8);
    
    // Top 10 Users Table
    if (!empty($topUsers)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Top 10 Users by Lab Time', 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(52, 152, 219);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(80, 7, 'Student Name', 1, 0, 'C', true);
        $pdf->Cell(50, 7, 'Admission No', 1, 0, 'C', true);
        $pdf->Cell(50, 7, 'Total Time', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        
        foreach ($topUsers as $user) {
            $hours = floor($user['total_seconds'] / 3600);
            $minutes = floor(($user['total_seconds'] % 3600) / 60);
            $timeStr = sprintf('%dh %dm', $hours, $minutes);
            
            $pdf->Cell(80, 6, substr($user['name'] ?: $user['admission_number'], 0, 35), 1, 0);
            $pdf->Cell(50, 6, $user['admission_number'], 1, 0, 'C');
            $pdf->Cell(50, 6, $timeStr, 1, 1, 'C');
        }
        $pdf->Ln(8);
    }
    
    // Subject Usage Table
    if (!empty($subjectUsage)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Subject Usage Summary', 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(52, 152, 219);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(130, 7, 'Subject Name', 1, 0, 'C', true);
        $pdf->Cell(50, 7, 'Student Count', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        
        foreach ($subjectUsage as $subject) {
            $pdf->Cell(130, 6, substr($subject['subject_name'], 0, 60), 1, 0);
            $pdf->Cell(50, 6, $subject['student_count'], 1, 1, 'C');
        }
    }
    
    // Process chart images if available
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $chartFields = ['img_top', 'img_pie', 'img_eng', 'img_monthly', 'img_attendance'];
        $hasCharts = false;
        
        foreach ($chartFields as $field) {
            if (!empty($_POST[$field])) {
                $hasCharts = true;
                break;
            }
        }
        
        if ($hasCharts) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 13);
            $pdf->SetFillColor(41, 128, 185);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 10, 'GRAPHICAL ANALYSIS', 0, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(5);
            
            $chartTitles = [
                'img_top' => 'Top Users by Lab Time',
                'img_pie' => 'Activity Distribution',
                'img_eng' => 'Activity Engagement',
                'img_monthly' => 'Monthly Trends',
                'img_attendance' => 'Attendance Patterns'
            ];
            
            foreach ($chartFields as $field) {
                if (!empty($_POST[$field])) {
                    $imageData = $_POST[$field];
                    
                    if (strpos($imageData, 'base64,') !== false) {
                        list(, $imageData) = explode('base64,', $imageData);
                    }
                    
                    $decoded = base64_decode($imageData);
                    
                    if ($decoded !== false && strlen($decoded) > 100) {
                        // Use @file_get_contents to create image resource without saving
                        try {
                            $pdf->SetFont('helvetica', 'B', 11);
                            $pdf->Cell(0, 8, $chartTitles[$field], 0, 1);
                            
                            // Use Image with @
                            @$pdf->Image('@' . $decoded, 15, $pdf->GetY(), 180, 70, 'PNG');
                            $pdf->Ln(75);
                            
                            if ($pdf->GetY() > 240) {
                                $pdf->AddPage();
                            }
                        } catch (Exception $e) {
                            // Skip chart if error
                        }
                    }
                }
            }
        }
    }
    
    // Footer
    $pdf->SetY(-20);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 10, 'Generated by Lab Activity Reporting System on ' . date('d M Y, h:i A'), 0, 0, 'C');
    
    // Output PDF
    $filename = 'trend_report_' . $start_date . '_to_' . $end_date . '.pdf';
    $pdf->Output($filename, 'D');
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'PDF Error: ' . $e->getMessage()]);
}
?>