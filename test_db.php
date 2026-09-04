<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'lars';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "Connected successfully to MySQL server\n";

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS `$db_name`";
if ($conn->query($sql) === TRUE) {
    echo "Database '$db_name' created successfully or already exists\n";
} else {
    die("Error creating database: " . $conn->error);
}

// Select the database
$conn->select_db($db_name);

// Create system_settings table if not exists
$sql = "CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "Table 'system_settings' created successfully or already exists\n";
    
    // Test insert
    $key = 'college_name';
    $value = 'Test College';
    
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("sss", $key, $value, $value);
    
    if ($stmt->execute()) {
        echo "Test data inserted/updated successfully\n";
    } else {
        echo "Error inserting test data: " . $stmt->error . "\n";
    }
    $stmt->close();
    
    // Test select
    $result = $conn->query("SELECT * FROM system_settings WHERE setting_key = 'college_name'");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "Retrieved college name: " . $row['setting_value'] . "\n";
    } else {
        echo "No data found in system_settings table\n";
    }
    
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$conn->close();
?>
