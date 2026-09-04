<?php
session_start();
header('Content-Type: application/json');

// Return basic session info for debugging (temporary)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['logged_in' => false]);
    exit();
}

echo json_encode([
    'logged_in' => true,
    'user_id' => $_SESSION['user_id'] ?? null,
    'role' => $_SESSION['role'] ?? null,
    'username' => $_SESSION['username'] ?? null
]);
?>