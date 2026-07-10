<?php
require_once('db.php');
$conn->query("ALTER TABLE orders ADD COLUMN preparedby VARCHAR(255) DEFAULT '' AFTER total_qty");
echo "Column 'preparedby' added successfully.";
$conn->close();
