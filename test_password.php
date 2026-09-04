<?php
// This script will generate proper password hashes for your database

$password = "password123";
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Password Hash Generator</h2>";
echo "<p><strong>Plain Password:</strong> password123</p>";
echo "<p><strong>Generated Hash:</strong></p>";
echo "<textarea style='width:100%; height:100px;'>$hash</textarea>";
echo "<br><br>";

echo "<h3>SQL INSERT Statements with Correct Hash:</h3>";
echo "<p>Copy and run these in phpMyAdmin:</p>";
echo "<textarea style='width:100%; height:300px;'>";
echo "-- Delete old users\n";
echo "DELETE FROM users;\n\n";
echo "-- Insert new users with correct password hash\n";
echo "INSERT INTO users (admission_number, username, password, role) VALUES\n";
echo "('ADM001', NULL, '$hash', 'student'),\n";
echo "(NULL, 'staff001', '$hash', 'staff'),\n";
echo "(NULL, 'admin', '$hash', 'admin');\n\n";
echo "-- Verify inserted users\n";
echo "SELECT id, admission_number, username, role FROM users;";
echo "</textarea>";

echo "<br><br>";
echo "<h3>Test Password Verification:</h3>";

// Simulate what the login does
$test_input = "password123";
$stored_hash = $hash;

if (password_verify($test_input, $stored_hash)) {
    echo "<p style='color:green;'><strong>✓ Password verification works correctly!</strong></p>";
} else {
    echo "<p style='color:red;'><strong>✗ Password verification failed!</strong></p>";
}

echo "<br>";
echo "<h3>Database Connection Test:</h3>";

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'LARS';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo "<p style='color:red;'><strong>✗ Database connection failed:</strong> " . $conn->connect_error . "</p>";
} else {
    echo "<p style='color:green;'><strong>✓ Database connected successfully!</strong></p>";
    
    // Check if users exist
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<p><strong>Users in database:</strong> " . $row['count'] . "</p>";
        
        // Show all users
        $result = $conn->query("SELECT id, admission_number, username, role FROM users");
        if ($result->num_rows > 0) {
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
            echo "<tr><th>ID</th><th>Admission Number</th><th>Username</th><th>Role</th></tr>";
            while($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . ($row['admission_number'] ?? 'NULL') . "</td>";
                echo "<td>" . ($row['username'] ?? 'NULL') . "</td>";
                echo "<td>" . $row['role'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
    $conn->close();
}
?>
