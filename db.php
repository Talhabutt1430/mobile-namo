<?php
$host = 'localhost';
$db   = 'realerp_nano';

// Production credentials (cPanel)
$user = 'realerp_probox';
$pass = 'S@ftix786';

// Override for local development
if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false) {
    $user = 'root';
    $pass = '';
}

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

