<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$name = trim($_POST['name'] ?? '');
$username = trim($_POST['username'] ?? '');
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';

if (empty($name) || empty($username)) {
    echo json_encode(['success' => false, 'message' => 'Name and username required']);
    exit();
}

// Ensure username is unique (excluding current user)
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("si", $username, $user_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Username already taken']);
        exit();
    }
    $stmt->close();
}

// If trying to change password, verify current password
if (!empty($new_password)) {
    if (empty($current_password)) {
        echo json_encode(['success' => false, 'message' => 'Current password required']);
        exit();
    }
    
    // Get current password from database
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!password_verify($current_password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password incorrect']);
        exit();
    }
    
    if (strlen($new_password) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters']);
        exit();
    }
    
    // Update with new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET name = ?, username = ?, password = ? WHERE id = ?");
    $stmt->bind_param("sssi", $name, $username, $hashed_password, $user_id);
} else {
    // Update without changing password
    $stmt = $conn->prepare("UPDATE users SET name = ?, username = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $username, $user_id);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Account updated']);
} else {
    $err = $conn->error;
    echo json_encode(['success' => false, 'message' => 'Failed to update account' . ($err ? (': ' . $err) : '')]);
}

$stmt->close();
$conn->close();
?>