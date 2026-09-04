<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'lars';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "<h3>✅ Connected successfully to MySQL server</h3><br>";

// Check if table exists
$result = $conn->query("SHOW TABLES LIKE 'system_settings'");
if ($result->num_rows > 0) {
    echo "<h4>✅ system_settings table exists</h4><br>";
    
    // Try to insert test data
    $test_value = "Test College " . time();
    $insert = $conn->query("INSERT INTO system_settings (setting_key, setting_value) 
                          VALUES ('test_key', '$test_value') 
                          ON DUPLICATE KEY UPDATE setting_value = '$test_value'");
    
    if ($insert) {
        echo "<p>✅ Successfully inserted test data</p>";
    } else {
        echo "<p style='color:red'>❌ Error inserting test data: " . $conn->error . "</p>";
    }
    
    // Try to read data
    $result = $conn->query("SELECT * FROM system_settings WHERE setting_key = 'test_key'");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "<p>✅ Retrieved test data: " . htmlspecialchars($row['setting_value']) . "</p>";
    } else {
        echo "<p style='color:red'>❌ No test data found</p>";
    }
    
} else {
    echo "<h4 style='color:orange'>⚠️ system_settings table does not exist</h4>";
    
    // Try to create the table
    $create_table = "CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_table) === TRUE) {
        echo "<p>✅ Successfully created system_settings table</p>";
    } else {
        echo "<p style='color:red'>❌ Error creating table: " . $conn->error . "</p>";
    }
}

// Check if we can update college name
$college_name = "Test College " . time();
$update = $conn->query("INSERT INTO system_settings (setting_key, setting_value) 
                      VALUES ('college_name', '$college_name') 
                      ON DUPLICATE KEY UPDATE setting_value = '$college_name'");

if ($update) {
    echo "<h4>✅ Successfully updated college name to: " . htmlspecialchars($college_name) . "</h4>";
    
    // Verify the update
    $result = $conn->query("SELECT * FROM system_settings WHERE setting_key = 'college_name'");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "<p>✅ Verified college name in database: " . htmlspecialchars($row['setting_value']) . "</p>";
    }
} else {
    echo "<p style='color:red'>❌ Error updating college name: " . $conn->error . "</p>";
}

$conn->close();
?>

<h3>Test Complete</h3>
<p>If you see any red error messages above, please share them so I can help you fix the issues.</p>
