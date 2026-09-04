<?php
session_start();

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

$error = '';
$success = '';

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $role = $_POST['role'] ?? '';
    
    if ($conn->connect_error) {
        $error = "Database connection failed. Please try again later.";
    } else {
        if ($role == 'student') {
            $admission_number = $_POST['admission_number'] ?? '';
            
            if (empty($admission_number)) {
                $error = "Please enter your admission number.";
            } else {
                // Check if student exists
                $stmt = $conn->prepare("SELECT id, name FROM users WHERE admission_number = ? AND role = 'student'");
                $stmt->bind_param("s", $admission_number);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    
                    // Check if there's already a pending request
                    $check_stmt = $conn->prepare("SELECT id FROM password_reset_requests WHERE user_id = ? AND status = 'pending'");
                    $check_stmt->bind_param("i", $user['id']);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $error = "You already have a pending password reset request. Please wait for admin approval.";
                    } else {
                        // Create password reset request
                        $insert_stmt = $conn->prepare("INSERT INTO password_reset_requests (user_id, requested_at, status) VALUES (?, NOW(), 'pending')");
                        $insert_stmt->bind_param("i", $user['id']);
                        
                        if ($insert_stmt->execute()) {
                            $success = "Password reset request submitted successfully! An admin will review your request shortly.";
                        } else {
                            $error = "Failed to submit request. Please try again.";
                        }
                        $insert_stmt->close();
                    }
                    $check_stmt->close();
                } else {
                    $error = "No student found with this admission number.";
                }
                $stmt->close();
            }
        } else if ($role == 'staff') {
            $username = $_POST['username'] ?? '';
            
            if (empty($username)) {
                $error = "Please enter your username.";
            } else {
                // Check if user exists
                $stmt = $conn->prepare("SELECT id, name FROM users WHERE username = ? AND role = ?");
                $stmt->bind_param("ss", $username, $role);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    
                    // Check if there's already a pending request
                    $check_stmt = $conn->prepare("SELECT id FROM password_reset_requests WHERE user_id = ? AND status = 'pending'");
                    $check_stmt->bind_param("i", $user['id']);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $error = "You already have a pending password reset request. Please wait for admin approval.";
                    } else {
                        // Create password reset request
                        $insert_stmt = $conn->prepare("INSERT INTO password_reset_requests (user_id, requested_at, status) VALUES (?, NOW(), 'pending')");
                        $insert_stmt->bind_param("i", $user['id']);
                        
                        if ($insert_stmt->execute()) {
                            $success = "Password reset request submitted successfully! An admin will review your request shortly.";
                        } else {
                            $error = "Failed to submit request. Please try again.";
                        }
                        $insert_stmt->close();
                    }
                    $check_stmt->close();
                } else {
                    $error = "No user found with this username and role.";
                }
                $stmt->close();
            }
        } else if ($role == 'admin') {
            $error = "Admin accounts cannot use the forgot password feature. Please contact the system owner.";
        } else {
            $error = "Please select a role.";
        }
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Lab Activity Reporting System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 500px;
            width: 100%;
            background: #1e293b;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 20px;
            transition: color 0.3s ease;
        }

        .back-link:hover {
            color: #6366f1;
        }

        h2 {
            color: white;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .role-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
        }

        .role-tab {
            flex: 1;
            padding: 12px;
            background: #0f172a;
            border: none;
            color: #94a3b8;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .role-tab:hover {
            background: #334155;
        }

        .role-tab.active {
            background: #6366f1;
            color: white;
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

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            color: white;
            font-size: 15px;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            border-color: #6366f1;
            background: #1e293b;
        }

        .submit-btn {
            width: 100%;
            padding: 14px;
            background: #6366f1;
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .submit-btn:hover {
            background: #5558e3;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .error-message {
            background: #dc2626;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .success-message {
            background: #16a34a;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .info-box {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .info-box p {
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.6;
            margin: 0;
        }

        @media (max-width: 768px) {
            .container {
                padding: 30px 20px;
            }

            h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <!-- header include removed per revert request -->
    <div class="container" style="margin-top:20px;">
        <a href="login.php" class="back-link">← Back to Login</a>
        
        <h2>Forgot Password</h2>
        <p class="subtitle">Request a password reset from the administrator</p>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="info-box">
            <p>Enter your admission number or username to submit a password reset request. An administrator will review and process your request.</p>
        </div>

        <form method="POST" action="" id="resetForm">
            <div class="role-tabs">
                <button type="button" class="role-tab active" onclick="selectRole('student')">Student</button>
                <button type="button" class="role-tab" onclick="selectRole('staff')">Staff</button>
            </div>

            <input type="hidden" name="role" id="roleInput" value="student">

            <div class="form-group" id="admissionGroup">
                <label for="admission">Admission Number</label>
                <input type="text" id="admission" name="admission_number" autocomplete="off" required>
            </div>

            <div class="form-group" id="usernameGroup" style="display: none;">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" autocomplete="off">
            </div>

            <button type="submit" class="submit-btn">Request Password Reset</button>
        </form>
    </div>

    <script>
        function selectRole(role) {
            const tabs = document.querySelectorAll('.role-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');

            document.getElementById('roleInput').value = role;

            const admissionGroup = document.getElementById('admissionGroup');
            const usernameGroup = document.getElementById('usernameGroup');

            if (role === 'student') {
                admissionGroup.style.display = 'block';
                usernameGroup.style.display = 'none';
                document.getElementById('admission').required = true;
                document.getElementById('username').required = false;
                document.getElementById('username').value = '';
            } else {
                admissionGroup.style.display = 'none';
                usernameGroup.style.display = 'block';
                document.getElementById('admission').required = false;
                document.getElementById('username').required = true;
                document.getElementById('admission').value = '';
            }
        }
    </script>
    <!-- footer include removed per revert request -->
</body>
</html>