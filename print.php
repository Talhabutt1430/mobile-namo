<?php
include 'db.php';
require 'auth.php';
$cid = $_SESSION['cid'] ?? 1;

$v_no = $_GET['v_no'] ?? 0;
if(!$v_no){
    die("Voucher number is required");
}

// Fetch sale order master
$stmt = $conn->prepare("SELECT sale_order_master.*, customers.name AS customer_name, customers.address AS customer_address, customers.mobile AS customer_phone FROM sale_order_master LEFT JOIN customers ON sale_order_master.customer_id = customers.id WHERE sale_order_master.v_no=? AND sale_order_master.cid=?");
$stmt->bind_param("ii", $v_no, $cid);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if(!$order){
    die("Order not found");
}

// Fetch sale order items
$stmt = $conn->prepare("SELECT sale_order_detail.*, item_masters.item_name FROM sale_order_detail LEFT JOIN item_masters ON sale_order_detail.item_id = item_masters.id WHERE sale_order_detail.v_no=? AND sale_order_detail.cid=?");
$stmt->bind_param("ii", $v_no, $cid);
$stmt->execute();
$items = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Sale Order #<?= htmlspecialchars($v_no) ?></title>
<style>
body {
    font-family: Arial, sans-serif;
    font-size: 18px;
    color: #333;
    margin: 20px;
}
h2 { text-align: center; margin-bottom: 5px; }
h4 { margin: 0; }
.header, .footer { text-align: center; margin-bottom: 20px; }
.table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.table th, .table td { border: 1px solid #333; padding: 8px; text-align: left; }
.table th { background: #f2f2f2; }
.text-right { text-align: right; }
@media print {
    .no-print { display: none; }
}
</style>
</head>
<body>

<div class="header">
    <h2>Sale Order</h2>
    <h4>Voucher #: <?= htmlspecialchars($order['v_no']) ?></h4>
    <p>Date: <?= htmlspecialchars($order['v_date']) ?></p>
</div>

<div>
    <strong>Customer:</strong> <?= htmlspecialchars($order['customer_name']) ?><br>
    <strong>Address:</strong> <?= htmlspecialchars($order['customer_address']) ?><br>
    <strong>Phone:</strong> <?= htmlspecialchars($order['customer_phone']) ?><br>
    <strong>Prepared By:</strong> <?= htmlspecialchars($order['preparedby']) ?>
</div>

<table class="table">
    <thead>
        <tr>
            <th>#</th>
            <th>Item</th>
            <th>Size</th>
            <th>Quantity</th>
        </tr>
    </thead>
    <tbody>
        <?php $i=1; while($item = $items->fetch_assoc()): ?>
        <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($item['item_name']) ?></td>
            <td><?= htmlspecialchars($item['size']) ?></td>
            <td><?= htmlspecialchars($item['quantity']) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<div class="footer">
    <p>Thank you for your business!</p>
</div>

<div class="no-print">
    <button onclick="window.print()">Print</button>
    <a href="index.php">Back</a>
</div>

</body>
</html>
