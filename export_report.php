<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized');
}

$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    die('Database connection failed');
}

$type = $_GET['type'] ?? '';
$period = $_GET['period'] ?? '';
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$batch = isset($_GET['batch']) ? trim($_GET['batch']) : '';

// Debug mode flag
$debug_mode = isset($_GET['debug']) && $_GET['debug'] === '1';

// Allow batch values from UI without strict clearing — different formats exist (e.g. 2023-26).
if ($batch !== '' && !preg_match('/^\d{4}-[0-9]{2,4}$/', $batch)) {
    error_log("export_report.php: received non-standard batch parameter: {$batch}");
}

if ($type === 'activity') {
    // Activity Reports
    if ($period === 'daily') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $sql = "SELECT DATE(al.created_at) as date, TIME(al.created_at) as time, 
                u.name, u.admission_number, u.year, s.subject_name, al.log_text, al.duration_minutes
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN subjects s ON al.subject_id = s.id
                WHERE DATE(al.created_at) = ?"
                . ($subject_id > 0 ? " AND al.subject_id = ?" : "")
                . ($batch !== '' ? " AND u.year = ?" : "")
                . " ORDER BY al.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($subject_id > 0 && $batch !== '') {
            $stmt->bind_param("sis", $date, $subject_id, $batch);
            $bound_info = ['types' => 'sis', 'values' => [$date, $subject_id, $batch]];
        } elseif ($subject_id > 0) {
            $stmt->bind_param("si", $date, $subject_id);
            $bound_info = ['types' => 'si', 'values' => [$date, $subject_id]];
        } elseif ($batch !== '') {
            $stmt->bind_param("ss", $date, $batch);
            $bound_info = ['types' => 'ss', 'values' => [$date, $batch]];
        } else {
            $stmt->bind_param("s", $date);
            $bound_info = ['types' => 's', 'values' => [$date]];
        }
        $filename = "activity_report_" . $date . ".csv";
    } else {
        $start = $_GET['start_date'] ?? '';
        $end = $_GET['end_date'] ?? '';
        $sql = "SELECT DATE(al.created_at) as date, TIME(al.created_at) as time,
                u.name, u.admission_number, u.year, s.subject_name, al.log_text, al.duration_minutes
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN subjects s ON al.subject_id = s.id
                WHERE DATE(al.created_at) BETWEEN ? AND ?"
                . ($subject_id > 0 ? " AND al.subject_id = ?" : "")
                . ($batch !== '' ? " AND u.year = ?" : "")
                . " ORDER BY al.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($subject_id > 0 && $batch !== '') {
            $stmt->bind_param("ssis", $start, $end, $subject_id, $batch);
            $bound_info = ['types' => 'ssis', 'values' => [$start, $end, $subject_id, $batch]];
        } elseif ($subject_id > 0) {
            $stmt->bind_param("ssi", $start, $end, $subject_id);
            $bound_info = ['types' => 'ssi', 'values' => [$start, $end, $subject_id]];
        } elseif ($batch !== '') {
            $stmt->bind_param("sss", $start, $end, $batch);
            $bound_info = ['types' => 'sss', 'values' => [$start, $end, $batch]];
        } else {
            $stmt->bind_param("ss", $start, $end);
            $bound_info = ['types' => 'ss', 'values' => [$start, $end]];
        }
        $filename = "activity_report_{$start}_to_{$end}.csv";
    }
    
    if ($debug_mode) {
        header('Content-Type: application/json');
        echo json_encode(['phase' => 'activity', 'sql' => $sql, 'bound' => isset($bound_info) ? $bound_info : null, 'GET' => $_GET], JSON_PRETTY_PRINT);
        $conn->close();
        exit;
    }
    if ($debug_mode) {
        header('Content-Type: application/json');
        echo json_encode(['phase' => 'attendance', 'sql' => $sql, 'bound' => isset($bound_info) ? $bound_info : null, 'GET' => $_GET], JSON_PRETTY_PRINT);
        $conn->close();
        exit;
    }
    $stmt->execute();
    $result = $stmt->get_result();

    // Fallback: if no rows and batch provided, try matching by start-year prefix
    if ($result->num_rows === 0 && $batch !== '') {
        $start_year = substr($batch, 0, 4);
        if (preg_match('/^\d{4}$/', $start_year)) {
            $like = $start_year . '%';
            $fallback_sql = preg_replace('/u\.year = \?/', 'u.year LIKE ?', $sql);
            $fallback_stmt = $conn->prepare($fallback_sql);
            if ($period === 'daily') {
                if ($subject_id > 0) {
                    $fallback_stmt->bind_param("sis", $date, $subject_id, $like);
                } else {
                    $fallback_stmt->bind_param("ss", $date, $like);
                }
            } else {
                if ($subject_id > 0) {
                    $fallback_stmt->bind_param("ssis", $start, $end, $subject_id, $like);
                } else {
                    $fallback_stmt->bind_param("sss", $start, $end, $like);
                }
            }
            // Log fallback usage for debugging
            error_log("export_report: fallback match used for batch={$batch}, start_year={$start_year}, fallback_sql=" . $fallback_sql);
            if (isset($like)) {
                error_log("export_report: fallback bound values: " . json_encode([$start ?? null, $end ?? null, $subject_id ?? null, $like]));
            }
            $fallback_stmt->execute();
            $result = $fallback_stmt->get_result();
            $stmt = $fallback_stmt;
        }
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Time', 'Student Name', 'Admission No', 'Subject', 'Activity', 'Duration (min)']);
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['date'],
            $row['time'],
            $row['name'],
            $row['admission_number'] ?? 'N/A',
            $row['subject_name'] ?? 'N/A',
            $row['log_text'],
            $row['duration_minutes'] ?? 0
        ]);
    }
    
    fclose($output);
    $stmt->close();
    
} elseif ($type === 'attendance') {
    // Attendance Reports
    if ($period === 'daily') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $sql = "SELECT a.date, u.name, u.admission_number, u.year, s.subject_name, a.status
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN subjects s ON a.subject_id = s.id
                WHERE a.date = ?"
                . ($subject_id > 0 ? " AND a.subject_id = ?" : "")
                . ($batch !== '' ? " AND u.year = ?" : "")
                . " ORDER BY u.name";
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
        $filename = "attendance_report_" . $date . ".csv";
    } else {
        $start = $_GET['start_date'] ?? '';
        $end = $_GET['end_date'] ?? '';
        $sql = "SELECT a.date, u.name, u.admission_number, u.year, s.subject_name, a.status
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN subjects s ON a.subject_id = s.id
                WHERE a.date BETWEEN ? AND ?"
                . ($subject_id > 0 ? " AND a.subject_id = ?" : "")
                . ($batch !== '' ? " AND u.year = ?" : "")
                . " ORDER BY a.date DESC, u.name";
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
        $filename = "attendance_report_{$start}_to_{$end}.csv";
    }
    
    if ($debug_mode) {
        header('Content-Type: application/json');
        echo json_encode(['phase' => 'attendance', 'sql' => $sql, 'bound' => isset($bound_info) ? $bound_info : null, 'GET' => $_GET], JSON_PRETTY_PRINT);
        $conn->close();
        exit;
    }
    if ($debug_mode) {
        header('Content-Type: application/json');
        echo json_encode(['phase' => 'attendance', 'sql' => $sql, 'bound' => isset($bound_info) ? $bound_info : null, 'GET' => $_GET], JSON_PRETTY_PRINT);
        $conn->close();
        exit;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Student Name', 'Admission No', 'Subject', 'Status']);
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['date'],
            $row['name'],
            $row['admission_number'] ?? 'N/A',
            $row['subject_name'] ?? 'N/A',
            ucfirst($row['status'])
        ]);
    }
    
    fclose($output);
    $stmt->close();
}

$conn->close();
?>