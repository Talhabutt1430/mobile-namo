<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'realerp_nano';

// Auto-detect local vs production
if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false) {
    $user = 'root';
    $pass = '';
}

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

