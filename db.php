<?php
$servername = "localhost";
$username = "realerp_probox";
$password = "S@ftix786";
$dbname = "realerp_nano";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

