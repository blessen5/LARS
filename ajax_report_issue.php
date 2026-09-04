<?php
// Start buffered JSON-only response
if (function_exists('ob_get_level') && ob_get_level() === 0) { @ob_start(); }
session_start();
header('Content-Type: application/json; charset=utf-8');
if (function_exists('ini_set')) { @ini_set('display_errors', '0'); }
@error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	if (function_exists('ob_get_length') && ob_get_length() !== false) { @ob_end_clean(); }
	echo json_encode(['success' => false, 'message' => 'Invalid request method']);
	exit;
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff','admin'])) {
	if (function_exists('ob_get_length') && ob_get_length() !== false) { @ob_end_clean(); }
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit;
}

$user_id = (int)$_SESSION['user_id'];
$system_number = isset($_POST['system_number']) ? trim($_POST['system_number']) : '';
$description = isset($_POST['issue_description']) ? trim($_POST['issue_description']) : '';

if ($system_number === '' || $description === '') {
	if (function_exists('ob_get_length') && ob_get_length() !== false) { @ob_end_clean(); }
	echo json_encode(['success' => false, 'message' => 'Missing required fields']);
	exit;
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
	if (function_exists('ob_get_length') && ob_get_length() !== false) { @ob_end_clean(); }
	echo json_encode(['success' => false, 'message' => 'DB connection failed']);
	exit;
}
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("INSERT INTO issues (user_id, system_number, description, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
if (!$stmt) {
	$conn->close();
	if (function_exists('ob_get_length') && ob_get_length() !== false) { @ob_end_clean(); }
	echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
	exit;
}
$stmt->bind_param('iss', $user_id, $system_number, $description);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

if (function_exists('ob_get_length') && ob_get_length() !== false) { @ob_end_clean(); }
echo json_encode(['success' => $ok]);
