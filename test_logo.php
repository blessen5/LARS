<?php
$conn = new mysqli('localhost', 'root', '', 'LARS');
$result = $conn->query("SELECT * FROM settings WHERE setting_key = 'logo_path'");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "Logo path in DB: " . $row['setting_value'] . "<br>";
    echo "File exists: " . (file_exists($row['setting_value']) ? 'Yes' : 'No');
} else {
    echo "No logo path found in database";
}
?>