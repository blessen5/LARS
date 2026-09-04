<?php
// Start session
session_start();

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'student') {
        header("Location: dashboard.php");
        exit();
    } elseif ($_SESSION['role'] === 'staff') {
        header("Location: staff_dashboard.php");
        exit();
    } elseif ($_SESSION['role'] === 'admin') {
        header("Location: admin_dashboard.php");
        exit();
    }
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Get logo path from settings table
$logo_path = '';
$logo_url = '';
$show_image = false;
if (!$conn->connect_error) {
    $result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_path' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (!empty($row['setting_value']) && file_exists($row['setting_value'])) {
            $logo_path = $row['setting_value'];
            $show_image = true;
            // Build cache-busted URL using file modification time
            $ver = @filemtime($logo_path);
            $logo_url = $logo_path . ($ver ? ('?v=' . $ver) : '');
        }
    }
}

$error = '';
$success = '';

// Handle login submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($conn->connect_error) {
        $error = "Database connection failed. Please try again later.";
    } else {
        if ($role == 'student') {
            $admission_number = $_POST['admission_number'] ?? '';
            
            if (empty($admission_number) || empty($password)) {
                $error = "Please fill in all fields.";
            } else {
                $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE admission_number = ? AND role = 'student'");
                $stmt->bind_param("s", $admission_number);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['password'])) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['admission_number'] = $admission_number;
                        
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $log_stmt = $conn->prepare("INSERT INTO login_activity (user_id, ip_address) VALUES (?, ?)");
                        $log_stmt->bind_param("is", $user['id'], $ip_address);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        header("Location: welcome.php");
                        exit();
                    } else {
                        $error = "Invalid credentials.";
                    }
                } else {
                    $error = "Invalid credentials.";
                }
                $stmt->close();
            }
        } else if ($role == 'staff' || $role == 'admin') {
            $username = $_POST['username'] ?? '';
            
            if (empty($username) || empty($password)) {
                $error = "Please fill in all fields.";
            } else {
                $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ? AND role = ?");
                $stmt->bind_param("ss", $username, $role);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['password'])) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['username'] = $username;
                        
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $log_stmt = $conn->prepare("INSERT INTO login_activity (user_id, ip_address) VALUES (?, ?)");
                        $log_stmt->bind_param("is", $user['id'], $ip_address);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        if ($role == 'staff') {
                            header("Location: staff_dashboard.php");
                        } else {
                            header("Location: admin_dashboard.php");
                        }
                        exit();
                    } else {
                        $error = "Invalid credentials.";
                    }
                } else {
                    $error = "Invalid credentials.";
                }
                $stmt->close();
            }
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
    <title>Login - Lab Activity Reporting System</title>
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
            overflow: auto;
        }
        
        /* Ensure form and inputs are always interactive */
        form, 
        form input, 
        form button, 
        form select, 
        form textarea {
            pointer-events: auto !important;
            user-select: auto !important;
        }

        .container {
            display: flex;
            max-width: 1200px;
            width: 100%;
            background: #1e293b;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }

        .left-panel {
            flex: 1;
            background: linear-gradient(135deg, #4c51bf 0%, #5b63d3 100%);
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
        }

        .logo-circle {
            width: 120px;
            height: 120px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logo-circle span {
            font-size: 28px;
            font-weight: 700;
            color: #4c51bf;
        }

        .left-panel h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.3;
        }

        .left-panel p {
            font-size: 16px;
            opacity: 0.95;
            margin-bottom: 10px;
        }

        .left-panel .subtitle {
            font-size: 14px;
            opacity: 0.85;
        }

        .right-panel {
            flex: 1;
            padding: 60px 50px;
            background: #0f172a;
        }

        .right-panel h2 {
            color: white;
            font-size: 32px;
            margin-bottom: 10px;
        }

        .right-panel .subtitle {
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
            background: #1e293b;
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

        .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            color: white;
            font-size: 15px;
            outline: none;
            transition: all 0.3s ease;
            pointer-events: auto !important;
            user-select: auto !important;
            -webkit-user-select: auto !important;
            -moz-user-select: auto !important;
            cursor: text !important;
        }
        
        .form-group input:disabled,
        .form-group input[disabled],
        .form-group input[readonly] {
            pointer-events: auto !important;
            user-select: auto !important;
            -webkit-user-select: auto !important;
            -moz-user-select: auto !important;
            cursor: text !important;
        }

        .form-group input:focus {
            border-color: #6366f1;
            background: #0f172a;
        }
        
        .form-group input:enabled,
        .form-group input:not([disabled]),
        .form-group input:not([readonly]) {
            cursor: text !important;
        }
        
        /* Force all inputs to be editable */
        input[type="text"],
        input[type="password"] {
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            appearance: none !important;
            pointer-events: auto !important;
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            font-size: 18px;
            padding: 4px;
            line-height: 1;
        }

        .password-toggle:hover {
            color: #94a3b8;
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 25px;
        }

        .forgot-password a {
            color: #6366f1;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .forgot-password a:hover {
            color: #818cf8;
        }

        .login-btn {
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
        }

        .login-btn:hover {
            background: #5558e3;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .create-account {
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            font-size: 14px;
        }

        .create-account a {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
        }

        .create-account a:hover {
            color: #818cf8;
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

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }

            .left-panel, .right-panel {
                padding: 40px 30px;
            }

            .left-panel h1 {
                font-size: 26px;
            }

            .right-panel h2 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <!-- header include removed per revert request -->
    <div class="container" style="margin-top:20px;">
        <div class="left-panel">
            <div class="logo-circle">
                <?php if ($show_image): ?>
                    <img src="<?php echo htmlspecialchars($logo_url ?: $logo_path); ?>" alt="College Logo">
                <?php else: ?>
                    <span>Logo</span>
                <?php endif; ?>
            </div>
            <h1>Sree Sankara<br>Vidyapeetom College<br>Valayanchirangara</h1>
            <p>Welcome to the central hub for all your needs.</p>
            <p class="subtitle">Department of Computer Science.</p>
        </div>

        <div class="right-panel">
            <h2>Log in</h2>
            <p class="subtitle">Select your role to continue.</p>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="role-tabs">
                    <button type="button" class="role-tab active" onclick="selectRole('student', this)">Student</button>
                    <button type="button" class="role-tab" onclick="selectRole('staff', this)">Staff</button>
                    <button type="button" class="role-tab" onclick="selectRole('admin', this)">Admin</button>
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

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" autocomplete="off" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <span id="eyeIcon">👁️</span>
                        </button>
                    </div>
                </div>

                <div class="forgot-password">
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>

                <button type="submit" class="login-btn">Login</button>

                <div class="create-account">
                    Don't have an account? <a href="register.php">Create Account</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Minimal, non-blocking functions
        function selectRole(role, btn) {
            var tabs = document.querySelectorAll('.role-tab');
            for (var j = 0; j < tabs.length; j++) {
                tabs[j].classList.remove('active');
            }
            if (btn) btn.classList.add('active');
            
            var roleInput = document.getElementById('roleInput');
            if (roleInput) roleInput.value = role;
            
            var admissionGroup = document.getElementById('admissionGroup');
            var usernameGroup = document.getElementById('usernameGroup');
            var admissionInput = document.getElementById('admission');
            var usernameInput = document.getElementById('username');
            var passwordInput = document.getElementById('password');
            
            if (role === 'student') {
                if (admissionGroup) admissionGroup.style.display = 'block';
                if (usernameGroup) usernameGroup.style.display = 'none';
                if (admissionInput) {
                    admissionInput.required = true;
                    admissionInput.disabled = false;
                }
                if (usernameInput) {
                    usernameInput.required = false;
                    usernameInput.value = '';
                }
            } else {
                if (admissionGroup) admissionGroup.style.display = 'none';
                if (usernameGroup) usernameGroup.style.display = 'block';
                if (usernameInput) {
                    usernameInput.required = true;
                    usernameInput.disabled = false;
                }
                if (admissionInput) {
                    admissionInput.required = false;
                    admissionInput.value = '';
                }
            }
            
            if (passwordInput) {
                passwordInput.disabled = false;
            }
        }

        function togglePassword() {
            var passwordInput = document.getElementById('password');
            var eyeIcon = document.getElementById('eyeIcon');
            if (passwordInput && eyeIcon) {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.textContent = '🙈';
                } else {
                    passwordInput.type = 'password';
                    eyeIcon.textContent = '👁️';
                }
            }
        }

        // Force enable all inputs - simple and direct
        function forceEnableInputs() {
            try {
                var inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
                for (var i = 0; i < inputs.length; i++) {
                    var inp = inputs[i];
                    if (inp) {
                        inp.disabled = false;
                        inp.readOnly = false;
                        inp.removeAttribute('disabled');
                        inp.removeAttribute('readonly');
                        inp.style.pointerEvents = 'auto';
                        inp.style.userSelect = 'auto';
                        inp.style.cursor = 'text';
                    }
                }
            } catch(e) {
                console.error('Error:', e);
            }
        }
        
        // Run when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', forceEnableInputs);
        } else {
            forceEnableInputs();
        }
    </script>
    <!-- footer include removed per revert request -->
</body>
</html>