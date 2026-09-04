<?php
session_start();

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Get logo path from settings table
$logo_path = 'assets/uploads/default_logo.png'; // Default fallback
$logo_url = $logo_path;
if (!$conn->connect_error) {
    $result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_path' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (!empty($row['setting_value']) && file_exists($row['setting_value'])) {
            $logo_path = $row['setting_value'];
            $ver = @filemtime($logo_path);
            $logo_url = $logo_path . ($ver ? ('?v=' . $ver) : '');
        }
    }
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("CREATE TABLE IF NOT EXISTS pending_staff_requests (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, username VARCHAR(191) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';
    $admission_number = trim($_POST['admission_number'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $department = trim($_POST['department'] ?? '');
    $start_year = trim($_POST['start_year'] ?? '');
    $duration = trim($_POST['duration'] ?? '');
    $year = trim($_POST['year'] ?? '');
    
    // Validation
    if (empty($name)) {
        $error = "Name is required.";
    } elseif (empty($role) || !in_array($role, ['student', 'staff'])) {
        $error = "Please select a valid role.";
    } elseif ($role == 'student' && empty($admission_number)) {
        $error = "Admission number is required for students.";
    } elseif (($role == 'staff') && empty($username)) {
        $error = "Username is required for staff.";
    } elseif (empty($password)) {
        $error = "Password is required.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif ($role == 'student' && empty($department)) {
        $error = "Department is required for students.";
    } elseif ($role == 'student' && empty($start_year)) {
        $error = "Start year is required for students.";
    } elseif ($role == 'student' && (!is_numeric($start_year) || intval($start_year) < 2000 || intval($start_year) > 2099)) {
        $error = "Please enter a valid start year (2000-2099).";
    } elseif ($role == 'student' && empty($duration)) {
        $error = "Duration is required for students.";
    } elseif ($role == 'student' && !in_array($duration, ['2', '3', '4'])) {
        $error = "Please select a valid duration.";
    } elseif ($role == 'student' && empty($year)) {
        $error = "Batch calculation failed. Please check start year and duration.";
    } else {
        // Check if admission number or username already exists
        if ($role == 'student') {
            $stmt = $conn->prepare("SELECT id FROM users WHERE admission_number = ?");
            $stmt->bind_param("s", $admission_number);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = "This admission number is already registered.";
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error = "This username is already taken.";
            }
            $stmt->close();
            if (empty($error)) {
                $stmt = $conn->prepare("SELECT id FROM pending_staff_requests WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $res2 = $stmt->get_result();
                if ($res2->num_rows > 0) {
                    $error = "A request with this username is already pending.";
                }
                $stmt->close();
            }
        }
        
        // If no errors, insert the user
        if (empty($error)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            if ($role == 'student') {
                $stmt = $conn->prepare("INSERT INTO users (name, admission_number, username, password, role, department, year, start_year, duration) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssss", $name, $admission_number, $hashed_password, $role, $department, $year, $start_year, $duration);
            } else {
                $stmt = $conn->prepare("INSERT INTO pending_staff_requests (name, username, password) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $username, $hashed_password);
            }
            
            if ($stmt->execute()) {
                if ($role == 'student') {
                    $success = "Registration successful! You can now login.";
                } else {
                    $success = "Request submitted. An admin will review and approve your account.";
                }
                // Clear form fields
                $name = $admission_number = $username = $department = $year = $start_year = $duration = $batch = '';
            } else {
                $error = "Registration failed. Please try again.";
            }
            $stmt->close();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Lab Activity Reporting System</title>
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
            overflow-y: auto;
            max-height: 90vh;
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

        .form-group label .required {
            color: #ef4444;
        }

        .input-wrapper {
            position: relative;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            color: white;
            font-size: 15px;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #6366f1;
            background: #0f172a;
        }

        .form-group select {
            cursor: pointer;
        }

        .form-group select option {
            background: #1e293b;
            color: white;
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

        .register-btn {
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

        .register-btn:hover {
            background: #5558e3;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .register-btn:active {
            transform: translateY(0);
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            font-size: 14px;
        }

        .login-link a {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
        }

        .login-link a:hover {
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

        .hidden {
            display: none;
        }

        /* Dark theme for batch field */
        #batchGroup input {
            background-color: #1f2937;
            color: #d1d5db;
            border: 1px solid #374151;
            cursor: not-allowed;
            opacity: 0.8;
        }

        #batchGroup input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2);
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

            .right-panel {
                max-height: none;
            }
        }
    </style>
</head>
<body>
    <!-- header include removed per revert request -->
    <div class="container" style="margin-top:20px;">
        <div class="left-panel">
            <div class="logo-circle">
                <?php if (file_exists($logo_path)): ?>
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
            <h2>Create Account</h2>
            <p class="subtitle">Fill in your details to register.</p>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($success); ?>
                    <br><a href="login.php" style="color: white; text-decoration: underline;">Go to Login</a>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="registerForm">
                <div class="form-group">
                    <label for="name">Full Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="role">Role <span class="required">*</span></label>
                    <select name="role" id="role" onchange="toggleFields()" required>
                        <option value="">-- Select Role --</option>
                        <option value="student">Student</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>

                <div class="form-group" id="admissionGroup" style="display: none;">
                    <label for="admission_number">Admission Number <span class="required">*</span></label>
                    <input type="text" id="admission_number" name="admission_number" value="<?php echo htmlspecialchars($admission_number ?? ''); ?>">
                </div>

                <div class="form-group" id="usernameGroup" style="display: none;">
                    <label for="username">Username <span class="required">*</span></label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username ?? ''); ?>">
                </div>

                <div class="form-group" id="departmentGroup" style="display: none;">
                    <label for="department">Department <span class="required">*</span></label>
                    <input type="text" id="department" name="department" value="<?php echo htmlspecialchars($department ?? ''); ?>" placeholder="e.g., Computer Science">
                </div>

                <div class="form-group" id="startYearGroup" style="display: none;">
                    <label for="start_year">Start Year <span class="required">*</span></label>
                    <input type="number" id="start_year" name="start_year" value="<?php echo htmlspecialchars($start_year ?? ''); ?>" placeholder="e.g., 2021" min="2000" max="2099" onchange="calculateBatch()">
                </div>

                <div class="form-group" id="durationGroup" style="display: none;">
                    <label for="duration">Duration (Years) <span class="required">*</span></label>
                    <select id="duration" name="duration" onchange="calculateBatch()">
                        <option value="">-- Select Duration --</option>
                        <option value="2">2 Years</option>
                        <option value="3">3 Years</option>
                        <option value="4">4 Years</option>
                    </select>
                </div>

                <div class="form-group" id="batchGroup" style="display: none;">
                    <label for="batch">Batch (End Year)</label>
                    <input type="text" id="batch" name="batch" value="<?php echo htmlspecialchars($batch ?? ''); ?>" readonly placeholder="Auto-calculated">
                </div>

                <input type="hidden" id="year" name="year" value="<?php echo htmlspecialchars($year ?? ''); ?>">

                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('password', 'eyeIcon1')">
                            <span id="eyeIcon1">👁️</span>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'eyeIcon2')">
                            <span id="eyeIcon2">👁️</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="register-btn">Create Account</button>

                <div class="login-link">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleFields() {
            const role = document.getElementById('role').value;
            const admissionGroup = document.getElementById('admissionGroup');
            const usernameGroup = document.getElementById('usernameGroup');
            const departmentGroup = document.getElementById('departmentGroup');
            const startYearGroup = document.getElementById('startYearGroup');
            const durationGroup = document.getElementById('durationGroup');
            const batchGroup = document.getElementById('batchGroup');
            
            const admissionInput = document.getElementById('admission_number');
            const usernameInput = document.getElementById('username');
            const departmentInput = document.getElementById('department');
            const startYearInput = document.getElementById('start_year');
            const durationInput = document.getElementById('duration');
            const batchInput = document.getElementById('batch');
            const yearInput = document.getElementById('year');

            if (role === 'student') {
                // Show student fields
                admissionGroup.style.display = 'block';
                departmentGroup.style.display = 'block';
                startYearGroup.style.display = 'block';
                durationGroup.style.display = 'block';
                batchGroup.style.display = 'block';
                usernameGroup.style.display = 'none';
                
                // Set required attributes
                admissionInput.required = true;
                departmentInput.required = true;
                startYearInput.required = true;
                durationInput.required = true;
                batchInput.required = false; // Not required as it's auto-calculated
                usernameInput.required = false;
                usernameInput.value = '';
            } else if (role === 'staff') {
                // Show staff/admin fields
                usernameGroup.style.display = 'block';
                admissionGroup.style.display = 'none';
                departmentGroup.style.display = 'none';
                startYearGroup.style.display = 'none';
                durationGroup.style.display = 'none';
                batchGroup.style.display = 'none';
                
                // Set required attributes
                usernameInput.required = true;
                admissionInput.required = false;
                departmentInput.required = false;
                startYearInput.required = false;
                durationInput.required = false;
                batchInput.required = false;
                admissionInput.value = '';
                departmentInput.value = '';
                startYearInput.value = '';
                durationInput.value = '';
                batchInput.value = '';
                yearInput.value = '';
            } else {
                // Hide all conditional fields
                admissionGroup.style.display = 'none';
                usernameGroup.style.display = 'none';
                departmentGroup.style.display = 'none';
                startYearGroup.style.display = 'none';
                durationGroup.style.display = 'none';
                batchGroup.style.display = 'none';
                
                admissionInput.required = false;
                usernameInput.required = false;
                departmentInput.required = false;
                startYearInput.required = false;
                durationInput.required = false;
                batchInput.required = false;
            }
        }

        function calculateBatch() {
            const startYear = document.getElementById('start_year').value;
            const duration = document.getElementById('duration').value;
            const batchInput = document.getElementById('batch');
            const yearInput = document.getElementById('year');
            
            if (startYear && duration) {
                const endYear = parseInt(startYear) + parseInt(duration);
                const batchValue = startYear + '-' + endYear;
                batchInput.value = batchValue;
                yearInput.value = batchValue; // Also update hidden field for database
            } else {
                batchInput.value = '';
                yearInput.value = '';
            }
        }

        function togglePassword(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const eyeIcon = document.getElementById(iconId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.textContent = '🙈';
            } else {
                passwordInput.type = 'password';
                eyeIcon.textContent = '👁️';
            }
        }

        // Initialize on page load if role is already selected
        window.onload = function() {
            toggleFields();
        };
    </script>
    <!-- footer include removed per revert request -->
</body>
</html>
