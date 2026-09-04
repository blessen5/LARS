<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'add_subject') {
    $subject_name = trim($_POST['subject_name'] ?? '');
    $subject_code = trim($_POST['subject_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($subject_name)) {
        echo json_encode(['success' => false, 'message' => 'Subject name is required']);
        exit();
    }
    
    // Check if subject already exists
    $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name = ?");
    $stmt->bind_param("s", $subject_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Subject with this name already exists']);
        $stmt->close();
        exit();
    }
    $stmt->close();
    
    // Insert new subject
    $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code) VALUES (?, ?)");
    $stmt->bind_param("ss", $subject_name, $subject_code);
    
    if ($stmt->execute()) {
        $subject_id = $conn->insert_id;
        
        // Log the action
        $user_id = $_SESSION['user_id'];
        $log_text = "Added new subject: $subject_name";
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)");
        $stmt_log->bind_param("is", $user_id, $log_text);
        $stmt_log->execute();
        $stmt_log->close();
        
        // Return the new subject data
        $subject = [
            'id' => $subject_id,
            'subject_name' => $subject_name,
            'subject_code' => $subject_code,
            'description' => $description
        ];
        
        echo json_encode(['success' => true, 'message' => 'Subject added successfully', 'subject' => $subject]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add subject']);
    }
    $stmt->close();
    
} elseif ($action === 'get_subject') {
    $subject_id = intval($_POST['subject_id'] ?? 0);
    
    if (!$subject_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid subject ID']);
        exit();
    }
    
    $stmt = $conn->prepare("SELECT id, subject_name, subject_code, description FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $subject = $result->fetch_assoc();
        echo json_encode(['success' => true, 'subject' => $subject]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Subject not found']);
    }
    $stmt->close();
    
} elseif ($action === 'update_subject') {
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $subject_name = trim($_POST['subject_name'] ?? '');
    $subject_code = trim($_POST['subject_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (!$subject_id || empty($subject_name)) {
        echo json_encode(['success' => false, 'message' => 'Subject ID and name are required']);
        exit();
    }
    
    // Check if another subject with the same name exists
    $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name = ? AND id != ?");
    $stmt->bind_param("si", $subject_name, $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Another subject with this name already exists']);
        $stmt->close();
        exit();
    }
    $stmt->close();
    
    // Update the subject
    $stmt = $conn->prepare("UPDATE subjects SET subject_name = ?, subject_code = ?, description = ? WHERE id = ?");
    $stmt->bind_param("sssi", $subject_name, $subject_code, $description, $subject_id);
    
    if ($stmt->execute()) {
        // Log the action
        $user_id = $_SESSION['user_id'];
        $log_text = "Updated subject: $subject_name";
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)");
        $stmt_log->bind_param("is", $user_id, $log_text);
        $stmt_log->execute();
        $stmt_log->close();
        
        echo json_encode(['success' => true, 'message' => 'Subject updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update subject']);
    }
    $stmt->close();
    
} elseif ($action === 'delete_subject') {
    $subject_id = intval($_POST['subject_id'] ?? 0);
    
    if (!$subject_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid subject ID']);
        exit();
    }
    
    // Get subject name for logging
    $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $subject_name = ($result->num_rows > 0) ? $result->fetch_assoc()['subject_name'] : 'Unknown';
    $stmt->close();
    
    // Check if subject is in use
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_sessions WHERE subject_id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    
    if ($count > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete subject that is in use. Please remove all references first.']);
        exit();
    }
    
    // Delete the subject
    $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $subject_id);
    
    if ($stmt->execute()) {
        // Log the action
        $user_id = $_SESSION['user_id'];
        $log_text = "Deleted subject: $subject_name";
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)");
        $stmt_log->bind_param("is", $user_id, $log_text);
        $stmt_log->execute();
        $stmt_log->close();
        
        echo json_encode(['success' => true, 'message' => 'Subject deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete subject']);
    }
    $stmt->close();
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>