<?php
session_start();
header('Content-Type: application/json');

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Check if user is logged in and is admin or staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset("utf8");
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

$action = $_POST['action'] ?? '';

// ==================== LOAD PAST ATTENDANCE ====================
if ($action === 'load_past_attendance') {
    try {
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $search_query = trim($_POST['search_query'] ?? '');
        $student_id = intval($_POST['student_id'] ?? 0);
        $batch = trim($_POST['batch'] ?? '');

        if (!$subject_id || !$start_date || !$end_date) {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            exit();
        }

        // Validate dates
        if (!strtotime($start_date) || !strtotime($end_date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit();
        }

        // Get subject name
        $subject_name = '';
        $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $subject_name = $row['subject_name'];
        }
        $stmt->close();

        if (!$subject_name) {
            echo json_encode(['success' => false, 'message' => 'Subject not found']);
            exit();
        }

        // Generate date range (7 days)
        $dates = [];
        $current_date = new DateTime($start_date);
        $end_date_obj = new DateTime($end_date);

        while ($current_date <= $end_date_obj) {
            $dates[] = $current_date->format('Y-m-d');
            $current_date->modify('+1 day');
        }

        // Limit to 7 days
        if (count($dates) > 7) {
            $dates = array_slice($dates, 0, 7);
            $end_date = $dates[6];
        }

        // Build search condition
        $search_condition = '';
        $search_params = [];
        if ($search_query) {
            $search_condition = " AND (u.name LIKE ? OR u.admission_number LIKE ?)";
            $search_param = "%{$search_query}%";
            $search_params = [$search_param, $search_param];
        }

        // If a specific student_id was supplied, fetch only that student (optionally check batch/year)
        $students = [];
        if ($student_id > 0) {
            $sql = "SELECT id, name, admission_number, year FROM users WHERE id = ? AND role = 'student'";
            if ($batch) {
                $sql .= " AND year = ?";
            }
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            if ($batch) {
                $stmt->bind_param("is", $student_id, $batch);
            } else {
                $stmt->bind_param("i", $student_id);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $students[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'admission_number' => $row['admission_number'],
                    'records' => []
                ];
            }
            $stmt->close();
        } else {
            // Fetch all students who logged in during this period for this subject
            $sql = "SELECT DISTINCT u.id, u.name, u.admission_number 
                    FROM users u
                    INNER JOIN user_sessions us ON u.id = us.user_id
                    WHERE u.role = 'student' 
                    AND us.subject_id = ?
                    AND DATE(us.session_start) BETWEEN ? AND ?";

            // Append batch condition if provided
            if ($batch) {
                $sql .= " AND u.year = ?";
            }

            // Append search condition if provided
            if ($search_query) {
                $sql .= " AND (u.name LIKE ? OR u.admission_number LIKE ? )";
            }

            $sql .= " ORDER BY u.name";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }

            // Build bind types and params dynamically
            $types = '';
            $params = [];
            // subject_id, start_date, end_date
            $types .= 'iss';
            $params[] = $subject_id;
            $params[] = $start_date;
            $params[] = $end_date;

            if ($batch) {
                $types .= 's';
                $params[] = $batch;
            }

            if ($search_query) {
                $types .= 'ss';
                $params[] = $search_params[0];
                $params[] = $search_params[1];
            }

            // Prepare parameters for bind_param (needs references)
            $bind_params = [];
            $bind_params[] = $types;
            for ($i = 0; $i < count($params); $i++) {
                $bind_params[] = &$params[$i];
            }

            call_user_func_array([$stmt, 'bind_param'], $bind_params);

            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $students[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'admission_number' => $row['admission_number'],
                    'records' => []
                ];
            }
            $stmt->close();
        }

        if (empty($students)) {
            echo json_encode([
                'success' => true,
                'attendance' => [],
                'dates' => $dates,
                'subject_name' => $subject_name,
                'message' => 'No students found for this subject in the selected date range'
            ]);
            exit();
        }

        // For each student, fetch their attendance for each date
        foreach ($students as &$student) {
            foreach ($dates as $date) {
                // Check if student logged in on this date for this subject
                $sql = "SELECT us.id as session_id, us.subject_id
                        FROM user_sessions us
                        WHERE us.user_id = ? 
                        AND us.subject_id = ?
                        AND DATE(us.session_start) = ?
                        LIMIT 1";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $conn->error);
                }

                $stmt->bind_param("iis", $student['id'], $subject_id, $date);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($session = $result->fetch_assoc()) {
                    // Student logged in - check attendance status
                    $sql_att = "SELECT status FROM attendance 
                                WHERE user_id = ? 
                                AND subject_id = ?
                                AND date = ?";

                    $stmt_att = $conn->prepare($sql_att);
                    if (!$stmt_att) {
                        throw new Exception('Prepare failed: ' . $conn->error);
                    }

                    $stmt_att->bind_param("iis", $student['id'], $subject_id, $date);
                    $stmt_att->execute();
                    $result_att = $stmt_att->get_result();

                    $attendance_status = null;
                    if ($att_row = $result_att->fetch_assoc()) {
                        $attendance_status = $att_row['status'];
                    }
                    $stmt_att->close();

                    $student['records'][$date] = [
                        'subject_id' => intval($session['subject_id']),
                        'status' => $attendance_status
                    ];
                } else {
                    // Student didn't log in on this date for this subject
                    $student['records'][$date] = null;
                }

                $stmt->close();
            }
        }

        echo json_encode([
            'success' => true,
            'attendance' => $students,
            'dates' => $dates,
            'subject_name' => $subject_name
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    
    $conn->close();
    exit();
}

// ==================== MARK PAST ATTENDANCE ====================
if ($action === 'mark_past_attendance') {
    try {
        $student_id = intval($_POST['student_id'] ?? 0);
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $date = $_POST['date'] ?? '';
        $status = $_POST['status'] ?? '';
        
        // Validate inputs
        if (!$student_id || !$subject_id || !$date) {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            exit();
        }
        
        // Validate date format
        if (!strtotime($date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit();
        }
        
        // Validate status
        $valid_statuses = ['present', 'late', 'absent', 'unmark'];
        if (!in_array($status, $valid_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status: ' . $status]);
            exit();
        }
        
        // Check if student logged in on this date for this subject
        $sql = "SELECT id FROM user_sessions 
                WHERE user_id = ? 
                AND subject_id = ?
                AND DATE(session_start) = ?
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("iis", $student_id, $subject_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Student did not log in on this date for this subject']);
            $stmt->close();
            $conn->close();
            exit();
        }
        $stmt->close();
        
        // Handle unmark action
        if ($status === 'unmark') {
            // Delete attendance record
            $sql = "DELETE FROM attendance WHERE user_id = ? AND subject_id = ? AND date = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("iis", $student_id, $subject_id, $date);
            
            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                echo json_encode([
                    'success' => true, 
                    'message' => 'Attendance unmarked successfully',
                    'affected_rows' => $affected
                ]);
            } else {
                throw new Exception('Failed to unmark attendance: ' . $stmt->error);
            }
            $stmt->close();
            $conn->close();
            exit();
        }
        
        // Handle mark actions (present, late, absent)
        // Check if a record already exists
        $check_sql = "SELECT id FROM attendance WHERE user_id = ? AND subject_id = ? AND date = ?";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $check_stmt->bind_param("iis", $student_id, $subject_id, $date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $record_exists = $check_result->num_rows > 0;
        $check_stmt->close();
        
        if ($record_exists) {
            // Update existing record
            $sql = "UPDATE attendance 
                    SET status = ?
                    WHERE user_id = ? AND subject_id = ? AND date = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("siis", $status, $student_id, $subject_id, $date);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Attendance updated successfully to ' . $status,
                    'action' => 'updated',
                    'status' => $status
                ]);
            } else {
                throw new Exception('Failed to update attendance: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            // Insert new record
            $sql = "INSERT INTO attendance (user_id, subject_id, date, status) 
                    VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("iiss", $student_id, $subject_id, $date, $status);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Attendance marked successfully as ' . $status,
                    'action' => 'inserted',
                    'status' => $status
                ]);
            } else {
                throw new Exception('Failed to insert attendance: ' . $stmt->error);
            }
            $stmt->close();
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    $conn->close();
    exit();
}

// ==================== INVALID ACTION ====================
echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
$conn->close();
?>