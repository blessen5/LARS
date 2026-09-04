<?php
/**
 * Create User Account (Student or Staff)
 * Handles both student and staff account creation from admin dashboard
 */
session_start();
header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get common form data
$name = trim($_POST['name'] ?? '');
$role = $_POST['role'] ?? '';
$password = $_POST['password'] ?? '';

// Validate common fields
if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Name is required']);
    exit();
}

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Password is required']);
    exit();
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long']);
    exit();
}

if (!in_array($role, ['student', 'staff', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid role specified']);
    exit();
}

// Hash password for security
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Handle student account creation
if ($role === 'student') {
    $admission_number = trim($_POST['admission_number'] ?? '');
    $year = trim($_POST['year'] ?? '');
    
    // Validate student-specific fields
    if (empty($admission_number)) {
        echo json_encode(['success' => false, 'message' => 'Admission number is required for students']);
        exit();
    }
    
    if (empty($year)) {
        echo json_encode(['success' => false, 'message' => 'Batch/Year is required for students']);
        exit();
    }

    // NO VALIDATION ON BATCH FORMAT - Accept any format
    // The batch can be auto-calculated (e.g., 2024-2028) or manually entered
    
    // Check if admission number already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE admission_number = ?");
    $stmt->bind_param("s", $admission_number);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Admission number already exists']);
        $stmt->close();
        $conn->close();
        exit();
    }
    $stmt->close();
    
    // Insert student into database
    $stmt = $conn->prepare("INSERT INTO users (name, admission_number, password, role, year) VALUES (?, ?, ?, 'student', ?)");
    $stmt->bind_param("ssss", $name, $admission_number, $hashed_password, $year);
    
    if ($stmt->execute()) {
        // Log the activity
        $admin_id = $_SESSION['user_id'];
        $log_text = "Created student account: $name (Admission: $admission_number, Batch: $year)";
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)");
        $log_stmt->bind_param("is", $admin_id, $log_text);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Student account created successfully',
            'user_id' => $stmt->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create student account']);
    }
    
    $stmt->close();
}

// Handle staff account creation
elseif ($role === 'staff') {
    $username = trim($_POST['username'] ?? '');
    
    // Validate staff-specific fields
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username is required for staff']);
        exit();
    }
    
    // Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        $stmt->close();
        $conn->close();
        exit();
    }
    $stmt->close();
    
    // Insert staff into database
    $stmt = $conn->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, 'staff')");
    $stmt->bind_param("sss", $name, $username, $hashed_password);
    
    if ($stmt->execute()) {
        // Log the activity
        $admin_id = $_SESSION['user_id'];
        $log_text = "Created staff account: $name (Username: $username)";
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)");
        $log_stmt->bind_param("is", $admin_id, $log_text);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Staff account created successfully',
            'user_id' => $stmt->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create staff account']);
    }
    
    $stmt->close();
}

// Handle admin account creation
elseif ($role === 'admin') {
    $username = trim($_POST['username'] ?? '');
    
    // Validate admin-specific fields
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username is required for admin']);
        exit();
    }
    
    // Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        $stmt->close();
        $conn->close();
        exit();
    }
    $stmt->close();
    
    // Insert admin into database
    $stmt = $conn->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, 'admin')");
    $stmt->bind_param("sss", $name, $username, $hashed_password);
    
    if ($stmt->execute()) {
        // Log the activity
        $admin_id = $_SESSION['user_id'];
        $log_text = "Created admin account: $name (Username: $username)";
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_text) VALUES (?, ?)");
        $log_stmt->bind_param("is", $admin_id, $log_text);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Admin account created successfully',
            'user_id' => $stmt->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create admin account']);
    }
    
    $stmt->close();
}

$conn->close();
?>