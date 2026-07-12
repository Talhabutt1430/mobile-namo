<?php
session_start();
require_once('db.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}

$cid = $_SESSION['cid'];
$name = $_SESSION['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if (!$order_id || $order_id <= 0) {
        $_SESSION['error'] = "Invalid order ID.";
        header("Location: orders.php");
        exit();
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("DELETE FROM order_item_detail WHERE order_id = ? AND cid = ?");
        $stmt->bind_param("ii", $order_id, $cid);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM orders WHERE id = ? AND cid = ?");
        $stmt->bind_param("ii", $order_id, $cid);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Order not found or you don't have permission.");
        }
        $stmt->close();

        $conn->commit();
        $_SESSION['success'] = "Order deleted successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error deleting order: " . $e->getMessage();
    }

    header("Location: orders.php");
    exit();
} else {
    header("Location: orders.php");
    exit();
}
