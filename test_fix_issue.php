<?php
/**
 * Test script to verify the Fix Issue functionality
 * This script helps diagnose issues with the Fixed button
 */

session_start();

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

echo "<h1>Fix Issue Functionality Test</h1>";
echo "<style>body { font-family: Arial, sans-serif; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";

// Test 1: Database Connection
echo "<h2>Test 1: Database Connection</h2>";
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo "<p class='error'>❌ Failed: " . $conn->connect_error . "</p>";
    exit;
} else {
    echo "<p class='success'>✓ Connected successfully</p>";
}

// Test 2: Check if issues table exists
echo "<h2>Test 2: Issues Table Structure</h2>";
$result = $conn->query("SHOW TABLES LIKE 'issues'");
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Issues table exists</p>";
    
    // Check table structure
    $result = $conn->query("DESCRIBE issues");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check for required columns
    $required_columns = ['id', 'user_id', 'system_number', 'description', 'status', 'created_at'];
    $result = $conn->query("DESCRIBE issues");
    $existing_columns = [];
    while ($row = $result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }
    
    echo "<h3>Required Columns Check:</h3>";
    foreach ($required_columns as $col) {
        if (in_array($col, $existing_columns)) {
            echo "<p class='success'>✓ Column '$col' exists</p>";
        } else {
            echo "<p class='error'>❌ Column '$col' is missing</p>";
        }
    }
    
    // Check for optional fixed_at column
    if (in_array('fixed_at', $existing_columns)) {
        echo "<p class='success'>✓ Optional column 'fixed_at' exists</p>";
    } else {
        echo "<p class='info'>ℹ Optional column 'fixed_at' is missing (not critical)</p>";
    }
    
} else {
    echo "<p class='error'>❌ Issues table does not exist</p>";
    echo "<p class='info'>Run the issues_table_fix.sql script to create it</p>";
}

// Test 3: Check if notifications table exists
echo "<h2>Test 3: Notifications Table</h2>";
$result = $conn->query("SHOW TABLES LIKE 'notifications'");
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Notifications table exists</p>";
} else {
    echo "<p class='error'>❌ Notifications table does not exist</p>";
    echo "<p class='info'>Notifications will not be sent to users</p>";
}

// Test 4: Check for pending issues
echo "<h2>Test 4: Pending Issues</h2>";
$result = $conn->query("SELECT COUNT(*) as count FROM issues WHERE status = 'pending'");
if ($result) {
    $row = $result->fetch_assoc();
    $count = $row['count'];
    if ($count > 0) {
        echo "<p class='success'>✓ Found $count pending issue(s)</p>";
        
        // Show the issues
        $result = $conn->query("SELECT i.*, u.name, u.admission_number FROM issues i JOIN users u ON i.user_id = u.id WHERE i.status = 'pending' LIMIT 5");
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>System</th><th>Reporter</th><th>Description</th><th>Created</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['system_number']) . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . " (" . htmlspecialchars($row['admission_number']) . ")</td>";
            echo "<td>" . htmlspecialchars(substr($row['description'], 0, 50)) . "...</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ No pending issues found</p>";
    }
} else {
    echo "<p class='error'>❌ Error querying issues: " . $conn->error . "</p>";
}

// Test 5: Check ajax_fix_issue.php file
echo "<h2>Test 5: AJAX Handler File</h2>";
if (file_exists('ajax_fix_issue.php')) {
    echo "<p class='success'>✓ ajax_fix_issue.php exists</p>";
    if (is_readable('ajax_fix_issue.php')) {
        echo "<p class='success'>✓ File is readable</p>";
    } else {
        echo "<p class='error'>❌ File is not readable (check permissions)</p>";
    }
} else {
    echo "<p class='error'>❌ ajax_fix_issue.php does not exist</p>";
}

// Test 6: Session check
echo "<h2>Test 6: Session Status</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p class='success'>✓ User is logged in (ID: " . $_SESSION['user_id'] . ")</p>";
    echo "<p class='info'>Role: " . ($_SESSION['role'] ?? 'Not set') . "</p>";
} else {
    echo "<p class='error'>❌ No active session (user not logged in)</p>";
    echo "<p class='info'>You need to be logged in as admin to fix issues</p>";
}

$conn->close();

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p>If all tests pass, the Fix Issue functionality should work correctly.</p>";
echo "<p>If any tests fail, follow the recommendations above to fix them.</p>";
echo "<p><a href='admin_dashboard.php'>Go to Admin Dashboard</a></p>";
?>
