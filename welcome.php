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

$error = '';
$subjects = [];

// Fetch all subjects
$sql = "SELECT id, subject_name, subject_code FROM subjects ORDER BY subject_name";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['continue'])) {
        $subject_id = $_POST['subject'] ?? '';
        
        if (empty($subject_id) || $subject_id == '') {
            $error = "Please select a subject to continue.";
        } else {
            // Store subject in session
            $_SESSION['selected_subject_id'] = $subject_id;
            
            // Log session start
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO user_sessions (user_id, subject_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $subject_id);
            $stmt->execute();
            $stmt->close();
            
            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        }
    } elseif (isset($_POST['other_activities'])) {
        // Record login time for other activities
        $user_id = $_SESSION['user_id'];
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $stmt = $conn->prepare("INSERT INTO login_activity (user_id, ip_address) VALUES (?, ?)");
        $stmt->bind_param("is", $user_id, $ip_address);
        $stmt->execute();
        $stmt->close();
        
        // Set flag to minimize window
        $_SESSION['minimize_window'] = true;
        
        header("Location: activities.php");
        exit();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Lab Activity Reporting System</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .welcome-container {
            background: #2d3748;
            border-radius: 16px;
            padding: 50px 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            text-align: center;
        }

        h1 {
            color: white;
            font-size: 32px;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #94a3b8;
            font-size: 15px;
            margin-bottom: 35px;
        }

        .form-group {
            margin-bottom: 25px;
            text-align: left;
        }

        .form-group label {
            display: block;
            color: #e2e8f0;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-group select {
            width: 100%;
            padding: 14px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            color: white;
            font-size: 15px;
            outline: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .form-group select:focus {
            border-color: #6366f1;
            background: #0f172a;
        }

        .form-group select option {
            background: #1e293b;
            color: white;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
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

        .btn-secondary {
            background: #0d9488;
            color: white;
        }

        .btn-secondary:hover {
            background: #0f766e;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(13, 148, 136, 0.4);
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #64748b;
            font-size: 14px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #334155;
        }

        .divider span {
            padding: 0 15px;
        }

        .error-message {
            background: #dc2626;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .welcome-container {
                padding: 40px 30px;
            }

            h1 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <!-- header include removed per revert request -->
    <div class="welcome-container" style="margin-top:20px;">
        <h1>Welcome!</h1>
        <p class="subtitle">Please select a subject or choose another activity.</p>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="subject">Subject</label>
                <select name="subject" id="subject">
                    <option value="">-- Select a Subject --</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>">
                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                            <?php if ($subject['subject_code']): ?>
                                (<?php echo htmlspecialchars($subject['subject_code']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" name="continue" class="btn btn-primary">
                Continue to Dashboard
            </button>

            <div class="divider">
                <span>OR</span>
            </div>

            <button type="button" onclick="handleOtherActivities()" class="btn btn-secondary">
                Other Activities
            </button>
        </form>
    </div>
    
    <!-- Minimized Bar (hidden by default) -->
    <div id="minimizedBar" style="display: none; position: fixed; bottom: 20px; left: 20px; 
         background: linear-gradient(135deg, #4c51bf 0%, #5b63d3 100%); color: white; 
         padding: 12px 24px; border-radius: 8px; cursor: pointer; z-index: 10000;
         box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
         align-items: center; gap: 8px; user-select: none; font-weight: 500;">
        📘 Lab Activity System — Click to Restore
    </div>

    <script>
        // Add click handler to minimized bar to restore
        document.addEventListener('DOMContentLoaded', function() {
            const minimizedBar = document.getElementById('minimizedBar');
            if (minimizedBar) {
                minimizedBar.addEventListener('click', function() {
                    restoreWindow();
                });
            }
        });
        function handleOtherActivities() {
            // Minimize window using the same method as dashboard Continue button
            const mainContent = document.querySelector('.welcome-container');
            const minimizedBar = document.getElementById('minimizedBar');
            
            if (mainContent && minimizedBar) {
                // Hide main content
                mainContent.style.display = 'none';
                // Show minimized bar
                minimizedBar.style.display = 'flex';
                
                // Also try to blur the window
                if (window.blur) {
                    window.blur();
                }
            } else {
                // Fallback: try window resize
                if (window.blur) {
                    window.blur();
                }
                try {
                    window.resizeTo(0, 0);
                    window.moveTo(screen.width, screen.height);
                } catch(e) {
                    window.blur();
                }
            }
            
            // Submit form to record activity and redirect
            setTimeout(function() {
                const form = document.querySelector('form');
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'other_activities';
                input.value = '1';
                form.appendChild(input);
                form.submit();
            }, 300);
        }
        
        // Restore window function
        function restoreWindow() {
            const mainContent = document.querySelector('.welcome-container');
            const minimizedBar = document.getElementById('minimizedBar');
            
            if (mainContent && minimizedBar) {
                mainContent.style.display = 'block';
                minimizedBar.style.display = 'none';
            }
        }
    </script>
    <!-- footer include removed per revert request -->
</body>
</html>