<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

// Create DB connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get form data
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$admission_number = isset($_POST['admission_number']) ? trim($_POST['admission_number']) : '';
$role = isset($_POST['role']) ? trim($_POST['role']) : '';
$year = isset($_POST['year']) ? trim($_POST['year']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

// Validate required fields
if (!$id || !$name || !$role) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Validate role
if (!in_array($role, ['student', 'staff', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    exit();
}

// Role-specific validation
if ($role === 'student') {
    if (empty($admission_number)) {
        echo json_encode(['success' => false, 'message' => 'Admission number is required for students']);
        exit();
    }
    // Check if admission number already exists for another user
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE admission_number = ? AND id != ?");
    $check_stmt->bind_param("si", $admission_number, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Admission number already exists']);
        exit();
    }
    $check_stmt->close();
} else {
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username is required for staff/admin']);
        exit();
    }
    // Check if username already exists for another user
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check_stmt->bind_param("si", $username, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit();
    }
    $check_stmt->close();
}

// Build update query
if (!empty($password)) {
    // Update with password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    if ($role === 'student') {
        $stmt = $conn->prepare("UPDATE users SET name = ?, admission_number = ?, role = ?, year = ?, password = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $name, $admission_number, $role, $year, $hashed_password, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name = ?, username = ?, role = ?, password = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $name, $username, $role, $hashed_password, $id);
    }
} else {
    // Update without password
    if ($role === 'student') {
        $stmt = $conn->prepare("UPDATE users SET name = ?, admission_number = ?, role = ?, year = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $name, $admission_number, $role, $year, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name = ?, username = ?, role = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $username, $role, $id);
    }
}

// Execute update
if ($stmt->execute()) {
    // Log the action
    $admin_id = $_SESSION['user_id'];
    $log_text = "Updated user: $name (ID: $id)";
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)");
    $log_stmt->bind_param("is", $admin_id, $log_text);
    $log_stmt->execute();
    $log_stmt->close();
    
    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>