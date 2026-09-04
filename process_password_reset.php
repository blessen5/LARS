<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
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

// Get POST data
$request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if (!$request_id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Verify the request exists and is pending
$stmt = $conn->prepare("SELECT prr.*, u.id as user_id, u.username, u.admission_number 
                        FROM password_reset_requests prr 
                        JOIN users u ON prr.user_id = u.id 
                        WHERE prr.id = ? AND prr.status = 'pending'");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Password reset request not found or already processed']);
    exit();
}

$request = $result->fetch_assoc();
$stmt->close();

// Start transaction
$conn->begin_transaction();

try {
    if ($action === 'approve') {
        // Get the new password
        $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
        
        if (empty($new_password)) {
            throw new Exception('New password is required');
        }
        
        if (strlen($new_password) < 6) {
            throw new Exception('Password must be at least 6 characters long');
        }
        
        // Hash the new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update the user's password
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $request['user_id']);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update password');
        }
        $stmt->close();
        
        // Update the request status to approved
        $stmt = $conn->prepare("UPDATE password_reset_requests 
                               SET status = 'approved', 
                                   processed_at = NOW(), 
                                   processed_by = ? 
                               WHERE id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $request_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update request status');
        }
        $stmt->close();
        
        // Log the activity
        $log_text = "Password reset approved by admin for " . 
                    ($request['admission_number'] ? "Adm: " . $request['admission_number'] : "Username: " . $request['username']);
        
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_text, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("is", $_SESSION['user_id'], $log_text);
        $stmt->execute();
        $stmt->close();
        // Create a notification for the student informing them that password was reset
        try {
            $admin_name = 'Admin';
            $sstmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
            if ($sstmt) {
                $sstmt->bind_param("i", $_SESSION['user_id']);
                $sstmt->execute();
                $sres = $sstmt->get_result();
                if ($sres && $sres->num_rows > 0) {
                    $srow = $sres->fetch_assoc();
                    if (!empty($srow['name'])) {
                        $admin_name = $srow['name'];
                    }
                }
                $sstmt->close();
            }

            $notif_message = "Your password has been reset by {$admin_name}.";
            $nstmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
            if ($nstmt) {
                $nstmt->bind_param("is", $request['user_id'], $notif_message);
                $nstmt->execute();
                $nstmt->close();
            }
        } catch (Exception $e) {
            // ignore notification failures
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Password reset approved and updated successfully'
        ]);
        
    } elseif ($action === 'reject') {
        // Update the request status to rejected
        $stmt = $conn->prepare("UPDATE password_reset_requests 
                               SET status = 'rejected', 
                                   processed_at = NOW(), 
                                   processed_by = ? 
                               WHERE id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $request_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update request status');
        }
        $stmt->close();
        
        // Log the activity
        $log_text = "Password reset rejected by admin for " . 
                    ($request['admission_number'] ? "Adm: " . $request['admission_number'] : "Username: " . $request['username']);
        
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_text, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("is", $_SESSION['user_id'], $log_text);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Password reset request rejected'
        ]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>