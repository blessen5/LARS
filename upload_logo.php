<?php
session_start();

// Return JSON response
function respond($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    respond(false, 'Unauthorized access');
}

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method');
}

// Check if file was uploaded
if (!isset($_FILES['logo'])) {
    respond(false, 'No file uploaded');
}

$file = $_FILES['logo'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    respond(false, 'Upload error: ' . $file['error']);
}

// Validate file size (500KB max)
if ($file['size'] > 500000) {
    respond(false, 'File too large. Maximum 500KB allowed');
}

// Get file extension and validate
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
    respond(false, 'Invalid file type. Only JPG, PNG, and WebP allowed');
}

// Validate actual image content
$image_info = @getimagesize($file['tmp_name']);
if ($image_info === false) {
    respond(false, 'Uploaded file is not a valid image');
}

$allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($image_info['mime'], $allowed_mimes)) {
    respond(false, 'Invalid image MIME type');
}

// Create upload directory
$upload_dir = 'assets/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate filename
$filename = 'college_logo.' . $ext;
$filepath = $upload_dir . $filename;

// Delete old logo if exists
if (file_exists($filepath)) {
    @unlink($filepath);
}

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    respond(false, 'Failed to save uploaded file');
}

// Save to database
$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    respond(false, 'Database connection failed');
}
$conn->set_charset('utf8mb4');

// Ensure settings tables exist
$conn->query("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Update both settings tables for full system compatibility
$stmt1 = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('logo_path', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
if ($stmt1) {
    $stmt1->bind_param("ss", $filepath, $filepath);
    $stmt1->execute();
    $stmt1->close();
}

$stmt2 = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('logo_path', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
if ($stmt2) {
    $stmt2->bind_param("ss", $filepath, $filepath);
    $stmt2->execute();
    $stmt2->close();
}

$conn->close();

respond(true, 'Logo uploaded successfully', ['path' => $filepath]);
?>