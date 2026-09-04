<?php
// Suppress all errors and warnings to prevent them from breaking JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any errors
ob_start();

session_start();
header('Content-Type: application/json');

// Simple error handler
function sendError($message) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $message]);
    ob_end_flush();
    exit;
}

function sendSuccess($notificationCreated = false) {
    ob_clean();
    echo json_encode(['success' => true, 'notification_created' => $notificationCreated]);
    ob_end_flush();
    exit;
}

// Try-catch wrapper for the entire script
try {
    // Check POST method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Invalid request method');
    }

    // Check authentication
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
        sendError('Unauthorized');
    }

    // Database connection
    $conn = new mysqli('localhost', 'root', '', 'LARS');
    if ($conn->connect_error) {
        sendError('Database connection failed');
    }
    $conn->set_charset('utf8mb4');

    // Get issue ID
    $issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    if ($issue_id <= 0) {
        $conn->close();
        sendError('Invalid issue ID');
    }

    // Fetch issue
    $stmt = $conn->prepare("SELECT id, user_id, system_number, status FROM issues WHERE id = ? LIMIT 1");
    if (!$stmt) {
        $conn->close();
        sendError('Failed to prepare query');
    }

    $stmt->bind_param("i", $issue_id);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        sendError('Failed to execute query');
    }

    // Get result
    $issue = null;
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        $issue = $res ? $res->fetch_assoc() : null;
    } else {
        $stmt->bind_result($rid, $ruser_id, $rsystem_number, $rstatus);
        if ($stmt->fetch()) {
            $issue = [
                'id' => $rid,
                'user_id' => $ruser_id,
                'system_number' => $rsystem_number,
                'status' => $rstatus,
            ];
        }
    }
    $stmt->close();

    if (!$issue) {
        $conn->close();
        sendError('Issue not found');
    }

    // Update issue status - try with fixed_at, fallback without it
    $updateSuccess = false;

    // First try with fixed_at
    $stmt = $conn->prepare("UPDATE issues SET status = 'fixed', fixed_at = NOW() WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $issue_id);
        if ($stmt->execute()) {
            $updateSuccess = true;
        }
        $stmt->close();
    }

    // If that failed, try without fixed_at
    if (!$updateSuccess) {
        $stmt = $conn->prepare("UPDATE issues SET status = 'fixed' WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $issue_id);
            if ($stmt->execute()) {
                $updateSuccess = true;
            }
            $stmt->close();
        }
    }

    if (!$updateSuccess) {
        $conn->close();
        sendError('Failed to update issue');
    }

    // Create notification (optional - don't fail if this fails)
    $notificationCreated = false;
    $systemNum = isset($issue['system_number']) && !empty($issue['system_number']) ? $issue['system_number'] : 'Unknown';
    $message = 'Your reported system (' . $systemNum . ') has been marked as fixed.';

    $nstmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
    if ($nstmt) {
        $nstmt->bind_param("is", $issue['user_id'], $message);
        if ($nstmt->execute()) {
            $notificationCreated = true;
        }
        $nstmt->close();
    }

    $conn->close();
    sendSuccess($notificationCreated);

} catch (Exception $e) {
    // Catch any unexpected errors and return JSON
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
} catch (Error $e) {
    // Catch fatal errors (PHP 7+)
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'A fatal error occurred: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
}
