<?php
session_start();
include 'db.php';
require 'auth.php'; // Ensure user is logged in

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v_no = $_POST['v_no'] ?? '';

    if (!empty($v_no)) {
        // Delete order safely using prepared statements
        $stmt = $conn->prepare("DELETE FROM sale_order_master WHERE v_no = ? AND cid = ?");
        $stmt->bind_param("ii", $v_no, $_SESSION['cid']); // Only delete orders of current company
        $stmt->execute();

        // Optionally delete details too
        $stmt2 = $conn->prepare("DELETE FROM sale_order_detail WHERE v_no = ? and cid = ?");
        $stmt2->bind_param("ii", $v_no, $_SESSION['cid']);
        $stmt2->execute();

        header("Location: index.php"); // Redirect back to list
        exit;
    } else {
        echo "Invalid order number!";
    }
} else {
    header("Location: index.php");
    exit;
}
