<?php
include 'db.php';
require 'auth.php'; // Session check

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $v_no = $_POST['v_no'] ?? null;
    $customer_id = $_POST['customer_id'] ?? null;
    $v_date = $_POST['v_date'] ?? null;
    $items = $_POST['items'] ?? [];
    $sizes = $_POST['sizes'] ?? [];
    $quantities = $_POST['quantities'] ?? [];

    if (!$v_no || !$customer_id || !$v_date || empty($items)) {
        die("Invalid input data!");
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // 1️⃣ Update master
        $stmt = $conn->prepare("
            UPDATE sale_order_master 
            SET customer_id = ?, v_date = ? 
            WHERE v_no = ? AND cid = ?
        ");
        $stmt->bind_param("isii", $customer_id, $v_date, $v_no, $_SESSION['cid']);
        $stmt->execute();

        // 2️⃣ Delete old details
        $stmtDel = $conn->prepare("DELETE FROM sale_order_detail WHERE v_no = ? and cid = ?");
        $stmtDel->bind_param("ii", $v_no, $_SESSION['cid']);
        $stmtDel->execute();

        // 3️⃣ Insert new details
        $stmtIns = $conn->prepare("
            INSERT INTO sale_order_detail (v_no, item_id, size, quantity, cid) 
            VALUES (?, ?, ?, ?, ?)
        ");
        for ($i = 0; $i < count($items); $i++) {
            $item_id = $items[$i];
            $size = $sizes[$i];
            $quantity = $quantities[$i];
            $stmtIns->bind_param("iisii", $v_no, $item_id, $size, $quantity, $_SESSION['cid']);
            $stmtIns->execute();
        }

        $conn->commit();

        header("Location: index.php?msg=Order updated successfully");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        die("Error updating order: " . $e->getMessage());
    }

} else {
    header("Location: index.php");
    exit;
}
