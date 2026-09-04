<?php
session_start();
header('Content-Type: application/json');

// Check admin OR staff authentication
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get POST data
$student_id = intval($_POST['student_id'] ?? 0);
$subject_id = intval($_POST['subject_id'] ?? 0);
$status = $_POST['status'] ?? '';

// Validate inputs
if (!$student_id || !$subject_id || !in_array($status, ['present', 'late', 'absent'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

$today = date('Y-m-d');

// Check if attendance already exists
$stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND subject_id = ?");
$stmt->bind_param("isi", $student_id, $today, $subject_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Update existing attendance
    $stmt = $conn->prepare("UPDATE attendance SET status = ? WHERE user_id = ? AND date = ? AND subject_id = ?");
    $stmt->bind_param("sisi", $status, $student_id, $today, $subject_id);
} else {
    // Insert new attendance
    $stmt = $conn->prepare("INSERT INTO attendance (user_id, subject_id, date, status) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $student_id, $subject_id, $today, $status);
}

if ($stmt->execute()) {
    // After marking attendance, create a notification for the student
    try {
        // Get staff/admin name
        $staff_name = 'Staff';
        $sstmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
        if ($sstmt) {
            $sstmt->bind_param("i", $_SESSION['user_id']);
            $sstmt->execute();
            $sres = $sstmt->get_result();
            if ($sres && $sres->num_rows > 0) {
                $srow = $sres->fetch_assoc();
                if (!empty($srow['name'])) {
                    $staff_name = $srow['name'];
                }
            }
            $sstmt->close();
        }

        $status_text = ucfirst($status);
        $message = "Your attendance has been marked as {$status_text} by {$staff_name}";

        $nstmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        if ($nstmt) {
            $nstmt->bind_param("is", $student_id, $message);
            $nstmt->execute();
            $nstmt->close();
        }
    } catch (Exception $e) {
        // Don't block attendance marking if notification fails; log silently
    }

    echo json_encode(['success' => true, 'message' => 'Attendance marked']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to mark attendance']);
}

$stmt->close();
$conn->close();
?>