<?php
session_start();
require_once 'includes/header.php';

// Check if user is admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Database connection
$conn = getDbConnection();

// Handle different actions
$action = $_POST['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'add':
            handleAddSystem($conn);
            break;
        case 'delete':
            handleDeleteSystem($conn);
            break;
        case 'export_csv':
            handleExportCSV($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

function handleAddSystem($conn) {
    $system_id = trim($_POST['system_id'] ?? '');
    $system_name = trim($_POST['system_name'] ?? '');
    
    if (empty($system_id) || empty($system_name)) {
        echo json_encode(['success' => false, 'message' => 'System ID and name are required']);
        return;
    }
    
    // Check if system already exists
    $stmt = $conn->prepare("SELECT id FROM systems WHERE system_id = ? OR system_name = ?");
    $stmt->bind_param("ss", $system_id, $system_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'System with this ID or name already exists']);
        $stmt->close();
        return;
    }
    $stmt->close();
    
    // Insert new system
    $stmt = $conn->prepare("INSERT INTO systems (system_id, system_name, login_count, usage_count, created_at) VALUES (?, ?, 0, 0, NOW())");
    $stmt->bind_param("ss", $system_id, $system_name);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'System added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add system']);
    }
    $stmt->close();
}

function handleDeleteSystem($conn) {
    $system_id = trim($_POST['system_id'] ?? '');
    
    if (empty($system_id)) {
        echo json_encode(['success' => false, 'message' => 'System ID is required']);
        return;
    }
    
    // Delete system
    $stmt = $conn->prepare("DELETE FROM systems WHERE system_id = ?");
    $stmt->bind_param("s", $system_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'System deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'System not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete system']);
    }
    $stmt->close();
}

function handleExportCSV($conn) {
    // Get all systems with their usage data
    $query = "SELECT system_id, system_name, login_count, usage_count, created_at 
              FROM systems 
              ORDER BY system_name ASC";
    
    $result = $conn->query($query);
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch system data']);
        return;
    }
    
    // Create CSV content
    $csv_content = "System ID,System Name,Login Count,Usage Count,Created At\n";
    
    while ($row = $result->fetch_assoc()) {
        $csv_content .= sprintf(
            "%s,%s,%d,%d,%s\n",
            $row['system_id'],
            $row['system_name'],
            $row['login_count'],
            $row['usage_count'],
            $row['created_at']
        );
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="system_usage_report_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output CSV content
    echo $csv_content;
    exit;
}

function getDbConnection() {
    $config = require 'config/database.php';
    $conn = new mysqli(
        $config['host'],
        $config['username'],
        $config['password'],
        $config['database']
    );
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}
?>