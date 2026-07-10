<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once('db.php');
session_start();

if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}

$cid      = $_SESSION['cid'];
$name     = $_SESSION['name'];
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($order_id <= 0) die("Invalid Order ID");

// ── Order master ──────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT o.order_no, o.v_date, c.name AS customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id AND o.cid = c.cid
    WHERE o.cid = ? AND o.id = ? AND o.preparedby = ?
");
$stmt->bind_param("iis", $cid, $order_id, $name);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) die("Order not found");

// ── Items (order_item_detail) ─────────────────────────────────
$stmt = $conn->prepare("
    SELECT oid.*, im.item_name
    FROM order_item_detail oid
    LEFT JOIN item_masters im ON oid.item_id = im.id AND oid.cid = im.cid
    WHERE oid.cid = ? AND oid.order_id = ?
    ORDER BY oid.id
");
$stmt->bind_param("ii", $cid, $order_id);
$stmt->execute();
$items_result = $stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

// ── Company name ──────────────────────────────────────────────
$company_name = 'Your Company';
$stmt = $conn->prepare("SELECT name FROM workspace WHERE cid = ?");
if ($stmt) {
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $company_name = $row['name'] ?? $company_name;
    $stmt->close();
}

$sizes = ['32','34','36','38','40','42','44','46','48','50'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - Order #<?= htmlspecialchars($order['order_no']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39&display=swap" rel="stylesheet">
    <style>
        /* ── Screen ───────────────────────────────────────────── */
        @media screen {
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .receipt-wrapper {
                max-width: 900px;
                margin: 0 auto;
                background: white;
                padding: 30px 40px;
                border-radius: 6px;
                box-shadow: 0 2px 10px rgba(0,0,0,.12);
                font-family: 'Courier New', monospace;
            }
        }

        /* ── Print (A4) ───────────────────────────────────────── */
        @media print {
            @page { size: A4; margin: 1.5cm; }
            body  { margin: 0; padding: 0; background: #fff; }
            .no-print { display: none !important; }
            .receipt-wrapper { width: 100%; margin: 0; padding: 0; font-size: 13px; }
        }

        /* ── Shared ───────────────────────────────────────────── */
        body { font-family: 'Courier New', monospace; color: #000; }

        .company-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .company-name  { font-size: 20px; font-weight: bold; }
        .order-title   { text-align: center; font-weight: bold; font-size: 16px; margin: 10px 0; }

        .info-table { width: 100%; font-size: 14px; margin-bottom: 12px; }
        .info-table td { padding: 4px 8px; }
        .info-table td:last-child { text-align: right; font-weight: bold; }

        /* Items table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin: 12px 0;
        }
        .items-table th {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 8px 6px;
            text-align: center;
            background: #f2f2f2;
        }
        .items-table th.left { text-align: left; }
        .items-table td {
            padding: 6px 6px;
            border-bottom: 1px solid #ddd;
            text-align: center;
            vertical-align: middle;
        }
        .items-table td.left { text-align: left; }
        .items-table .zero { color: #bbb; }

        /* Grand-total row */
        .grand-total td {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            font-weight: bold;
            background: #f9f9f9;
            padding: 8px 6px;
            text-align: center;
        }
        .grand-total td.left { text-align: left; }

        .total-box {
            text-align: center;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 10px 0;
            font-weight: bold;
            font-size: 16px;
            margin: 12px 0;
        }
        .barcode {
            text-align: center;
            font-family: 'Libre Barcode 39', monospace;
            font-size: 36px;
            margin: 12px 0;
        }
        .footer {
            text-align: center;
            border-top: 2px solid #000;
            padding-top: 8px;
            font-size: 12px;
            color: #555;
            margin-top: 12px;
        }
        .no-print {
            text-align: center;
            margin-bottom: 18px;
        }
        .no-print button, .no-print a {
            padding: 9px 22px; margin: 0 6px; border: none;
            border-radius: 4px; cursor: pointer; font-size: 13px;
            text-decoration: none; display: inline-block;
        }
        .btn-print { background: #28a745; color: white; }
        .btn-back  { background: #6c757d; color: white; }
    </style>
</head>
<body>

<!-- Controls (screen only) -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Print Receipt</button>
    <a class="btn-back" href="index.php">← Back</a>
    <p style="margin-top:8px;color:#666;font-size:12px;">
        Receipt preview for Order #<?= htmlspecialchars($order['order_no']) ?>
    </p>
</div>

<div class="receipt-wrapper">

    <!-- Company -->
    <div class="company-header">
        <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
    </div>

    <div class="order-title">ORDER RECEIPT</div>

    <!-- Order info -->
    <table class="info-table">
        <tr>
            <td>Order No:</td>
            <td>#<?= htmlspecialchars($order['order_no']) ?></td>
        </tr>
        <tr>
            <td>Date:</td>
            <td><?= date('d/m/Y', strtotime($order['v_date'])) ?></td>
        </tr>
        <tr>
            <td>Customer:</td>
            <td><?= htmlspecialchars($order['customer_name'] ?? '-') ?></td>
        </tr>
    </table>

    <!-- Items -->
    <table class="items-table">
        <thead>
            <tr>
                <th class="left">#</th>
                <th class="left">Article</th>
                <th>Cup</th>
                <th>Color</th>
                <?php foreach ($sizes as $s): ?>
                    <th><?= $s ?></th>
                <?php endforeach; ?>
                <th>Qty</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr>
                <td colspan="<?= 4 + count($sizes) + 1 ?>" style="text-align:center;padding:12px;font-style:italic;">
                    No items in this order
                </td>
            </tr>
        <?php else: ?>
            <?php
            $total_qty   = 0;
            $size_totals = array_fill_keys($sizes, 0);
            $i = 1;
            foreach ($items as $item):
                $row_total = (int)($item['total_qty'] ?? 0);
                $total_qty += $row_total;
                foreach ($sizes as $s) {
                    $size_totals[$s] += (int)($item['size_'.$s] ?? 0);
                }
            ?>
            <tr>
                <td class="left"><?= $i++ ?></td>
                <td class="left"><?= htmlspecialchars($item['item_name'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($item['cup'] ?? '-') ?></td>
                <td><?= htmlspecialchars($item['color'] ?? '-') ?></td>
                <?php foreach ($sizes as $s):
                    $val = (int)($item['size_'.$s] ?? 0); ?>
                    <td class="<?= $val === 0 ? 'zero' : '' ?>">
                        <?= $val ?: '-' ?>
                    </td>
                <?php endforeach; ?>
                <td><strong><?= $row_total ?></strong></td>
            </tr>
            <?php endforeach; ?>

            <!-- Grand total row -->
            <tr class="grand-total">
                <td class="left" colspan="4">TOTAL</td>
                <?php foreach ($sizes as $s): ?>
                    <td><?= $size_totals[$s] ?: '-' ?></td>
                <?php endforeach; ?>
                <td><?= $total_qty ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Total box -->
    <div class="total-box">
        TOTAL QUANTITY: <?= $total_qty ?? 0 ?> PCS
    </div>

    <!-- Barcode -->
    <div class="barcode">*<?= htmlspecialchars($order['order_no']) ?>*</div>

    <!-- Footer -->
    <div class="footer">
        <div>Thank you for your business</div>
        <div style="margin-top:3px;">Generated: <?= date('d/m/Y H:i') ?></div>
    </div>

</div>

<script>
// Auto-print after 1.5s
window.onload = function() {
    setTimeout(function() { window.print(); }, 1500);
};
window.onafterprint = function() { window.close(); };
</script>

</body>
</html>
<?php $conn->close(); ?>