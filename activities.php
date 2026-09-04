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

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Get user information
$user_name = "Student";
$stmt = $conn->prepare("SELECT name, admission_number FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user_data = $result->fetch_assoc();
    $user_name = $user_data['name'] ?? $user_data['admission_number'];
}
$stmt->close();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Save work/activity
    if (isset($_POST['save_work'])) {
        $work_text = $_POST['work_text'] ?? '';
        
        if (!empty($work_text)) {
            // Insert into activity_logs with subject_id as NULL (for other activities)
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, subject_id, log_text) VALUES (?, NULL, ?)");
            $stmt->bind_param("is", $user_id, $work_text);
            if ($stmt->execute()) {
                $success = "Work saved successfully!";
                // Clear the form
                $_POST['work_text'] = '';
            } else {
                $error = "Failed to save work.";
            }
            $stmt->close();
        } else {
            $error = "Please describe your work.";
        }
    }
    
    // Logout
    if (isset($_POST['logout'])) {
        // Clear all session variables
        $_SESSION = array();
        
        // Destroy the session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time()-3600, '/');
        }
        
        // Destroy the session
        session_destroy();
        
        header("Location: login.php");
        exit();
    }
}

// Fetch saved work entries (other activities - where subject_id is NULL)
$saved_works = [];
$sql = "SELECT id, log_text, created_at FROM activity_logs 
        WHERE user_id = ? AND subject_id IS NULL 
        ORDER BY created_at DESC LIMIT 20";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $saved_works[] = $row;
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Other Activities - Lab Activity Reporting System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            padding: 20px;
            color: white;
        }

        .header {
            background: #1e293b;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .header h1 {
            color: white;
            font-size: 24px;
            margin: 0;
        }

        .user-info {
            color: #cbd5e1;
            font-size: 14px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .card {
            background: #2d3748;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .card-title {
            color: white;
            font-size: 20px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #e2e8f0;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-group textarea {
            width: 100%;
            padding: 14px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            color: white;
            font-size: 15px;
            font-family: inherit;
            resize: vertical;
            min-height: 150px;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-group textarea:focus {
            border-color: #6366f1;
            background: #0f172a;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #6366f1;
            color: white;
        }

        .btn-primary:hover {
            background: #5558e3;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }

        .btn-success {
            background: #16a34a;
            color: white;
        }

        .btn-success:hover {
            background: #15803d;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(22, 163, 74, 0.4);
        }

        .btn-secondary {
            background: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .success-message {
            background: #16a34a;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .error-message {
            background: #dc2626;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .works-list {
            margin-top: 20px;
        }

        .work-item {
            background: #1e293b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #6366f1;
        }

        .work-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .work-date {
            color: #94a3b8;
            font-size: 12px;
        }

        .work-text {
            color: #e2e8f0;
            font-size: 14px;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .empty-state {
            text-align: center;
            color: #94a3b8;
            padding: 40px;
            font-size: 14px;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Other Activities</h1>
                <div class="user-info">User: <?php echo htmlspecialchars($user_name); ?> | Role: student</div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button type="button" onclick="minimizeWindow()" class="btn btn-success">Continue</button>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="logout" class="btn btn-danger" onclick="return confirm('Are you sure you want to logout?');">Logout</button>
                </form>
            </div>
        </div>
        
        <!-- Minimized Bar (hidden by default) -->
        <div id="minimizedBar" style="display: none; position: fixed; bottom: 20px; left: 20px; 
             background: linear-gradient(135deg, #4c51bf 0%, #5b63d3 100%); color: white; 
             padding: 12px 24px; border-radius: 8px; cursor: pointer; z-index: 10000;
             box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
             align-items: center; gap: 8px; user-select: none; font-weight: 500;">
            📘 Lab Activity System — Click to Restore
        </div>
        
        <div id="mainContent">

        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h3 class="card-title">Write Your Work</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="work_text">Describe the work you are doing (e.g., projects, assignments, research, etc.)</label>
                    <textarea id="work_text" name="work_text" placeholder="Enter details about your work here..." required><?php echo isset($_POST['work_text']) ? htmlspecialchars($_POST['work_text']) : ''; ?></textarea>
                </div>
                <button type="submit" name="save_work" class="btn btn-primary">Save Work</button>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title">Your Saved Work</h3>
            <?php if (empty($saved_works)): ?>
                <div class="empty-state">No work entries yet. Start by writing your work above.</div>
            <?php else: ?>
                <div class="works-list">
                    <?php foreach ($saved_works as $work): ?>
                        <div class="work-item">
                            <div class="work-item-header">
                                <div class="work-date">
                                    <?php echo date('d-m-Y, H:i', strtotime($work['created_at'])); ?>
                                </div>
                            </div>
                            <div class="work-text"><?php echo nl2br(htmlspecialchars($work['log_text'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        </div>
    </div>
    
    <script src="assets/js/electron-bridge.js"></script>
</body>
</html>
