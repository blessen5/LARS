<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$user_id = $_SESSION['user_id'];
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if (empty($start_date) || empty($end_date)) {
    die("Please provide both start and end dates.");
}

// Fetch attendance records for the date range
$sql = "SELECT a.date, s.subject_name, a.status 
        FROM attendance a 
        LEFT JOIN subjects s ON a.subject_id = s.id 
        WHERE a.user_id = ? AND a.date BETWEEN ? AND ? 
        ORDER BY a.date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $user_id, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=attendance_report_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, array('Date', 'Subject', 'Status'));

// Add data rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, array(
        date('d-m-Y', strtotime($row['date'])),
        $row['subject_name'] ?? 'N/A',
        ucfirst($row['status'])
    ));
}

// Add summary
fputcsv($output, array()); // Empty row
fputcsv($output, array('Report Generated:', date('d-m-Y H:i:s')));
fputcsv($output, array('Date Range:', date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date))));

fclose($output);
$stmt->close();
$conn->close();
exit();
?>