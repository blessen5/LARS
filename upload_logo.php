<?php
session_start();

// Return JSON response
function respond($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

// Check admin authentication
/*if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    respond(false, 'Unauthorized');
}*/

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

// Validate file size (200KB max)
if ($file['size'] > 200000) {
    respond(false, 'File too large. Maximum 200KB');
}

// Get file extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
    respond(false, 'Invalid file type. Only JPG and PNG allowed');
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
    unlink($filepath);
}

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    respond(false, 'Failed to save file');
}

// Save to database
$conn = new mysqli('localhost', 'root', '', 'LARS');
if ($conn->connect_error) {
    respond(false, 'Database error');
}

// Create settings table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Delete old entry and insert new
$conn->query("DELETE FROM settings WHERE setting_key = 'logo_path'");
$stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('logo_path', ?)");
$stmt->bind_param("s", $filepath);
$stmt->execute();
$stmt->close();
$conn->close();

respond(true, 'Logo uploaded successfully', ['path' => $filepath]);
?>