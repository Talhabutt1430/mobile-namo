<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once('db.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}

$cid = $_SESSION['cid'];
$user_id = $_SESSION['user_id'];
$name = $_SESSION['name'];

// Fetch customers for filter
$customers = [];
$stmt = $conn->prepare("SELECT id, name FROM customers WHERE cid = ? ORDER BY name");
$stmt->bind_param("i", $cid);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

// Fetch voucher numbers for filter
$vouchers = [];
$stmt = $conn->prepare("SELECT DISTINCT order_no FROM orders WHERE cid = ? ORDER BY order_no DESC");
$stmt->bind_param("i", $cid);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $vouchers[] = $row['order_no'];
}
$stmt->close();

// Filters
$selected_customer = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$selected_voucher = isset($_GET['voucher_no']) ? trim($_GET['voucher_no']) : '';
$selected_status = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build query
    $query = "
    SELECT o.id, o.order_no, o.v_date, o.total_qty, o.status,
           c.name AS customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.cid = ?
";
$params = [$cid];
$types = "i";

if ($selected_customer > 0) {
    $query .= " AND o.customer_id = ?";
    $params[] = $selected_customer;
    $types .= "i";
}

if ($selected_voucher !== '') {
    $query .= " AND o.order_no = ?";
    $params[] = $selected_voucher;
    $types .= "s";
}

if ($selected_status !== '') {
    $query .= " AND EXISTS (SELECT 1 FROM order_item_detail oid WHERE oid.order_id = o.id AND oid.item_status = ?)";
    $params[] = $selected_status;
    $types .= "s";
}

$query .= " ORDER BY o.v_date DESC, o.order_no DESC";

$stmt = $conn->prepare($query);
if ($stmt === false) die("Query failed: " . $conn->error);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$orders_result = $stmt->get_result();
$orders = [];
while ($order = $orders_result->fetch_assoc()) {
    $orders[$order['id']] = $order;
    $orders[$order['id']]['items'] = [];
}
$stmt->close();

// Fetch order items
if (!empty($orders)) {
    $order_ids = array_keys($orders);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $items_query = "
        SELECT oid.id, oid.order_id, oid.total_qty, oid.item_status, oid.color, oid.cup, im.item_name
        FROM order_item_detail oid
        LEFT JOIN item_masters im ON oid.item_id = im.id AND oid.cid = im.cid
        WHERE oid.cid = ? AND oid.order_id IN ($placeholders)
        ORDER BY oid.id
    ";
    $stmt = $conn->prepare($items_query);
    $bind_params = array_merge([$cid], $order_ids);
    $types = "i" . str_repeat('i', count($order_ids));
    $stmt->bind_param($types, ...$bind_params);
    $stmt->execute();
    $items_result = $stmt->get_result();
    while ($item = $items_result->fetch_assoc()) {
        $orders[$item['order_id']]['items'][] = $item;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .status-pending { background-color: #ffc107; color: #000; }
        .status-completed { background-color: #198754; color: #fff; }
        .status-rejected { background-color: #dc3545; color: #fff; }
        .supplier-card {
            border-left: 4px solid #0d6efd;
            transition: all 0.2s;
        }
        .supplier-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .badge-status { font-size: 0.8rem; padding: 5px 12px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-house-gear-fill"></i> Warehouse Dashboard</h2>
        <div>
            <span class="me-3">Welcome, <?= htmlspecialchars($name) ?></span>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Voucher No</label>
                    <select name="voucher_no" class="form-select">
                        <option value="">All Voucher Nos</option>
                        <?php foreach ($vouchers as $v): ?>
                            <option value="<?= htmlspecialchars($v) ?>" <?= $selected_voucher == $v ? 'selected' : '' ?>>
                                #<?= htmlspecialchars($v) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Customer</label>
                    <select name="customer_id" class="form-select">
                        <option value="0">All Customers</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $selected_customer == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?= $selected_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="completed" <?= $selected_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="rejected" <?= $selected_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <a href="warehouse.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    $total_items = 0;
    $pending = 0; $completed = 0; $rejected = 0;
    foreach ($orders as $o) {
        foreach ($o['items'] as $it) {
            $total_items++;
            $st = $it['item_status'] ?? 'pending';
            if ($st === 'completed') $completed++;
            elseif ($st === 'rejected') $rejected++;
            else $pending++;
        }
    }
    ?>
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card bg-warning bg-opacity-10 border-warning">
                <div class="card-body text-center">
                    <h5 class="text-warning">Pending</h5>
                    <h2 class="mb-0"><?= $pending ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card bg-success bg-opacity-10 border-success">
                <div class="card-body text-center">
                    <h5 class="text-success">Completed</h5>
                    <h2 class="mb-0"><?= $completed ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card bg-danger bg-opacity-10 border-danger">
                <div class="card-body text-center">
                    <h5 class="text-danger">Rejected</h5>
                    <h2 class="mb-0"><?= $rejected ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($orders)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                    <h4 class="mt-3">No orders found</h4>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Article</th>
                                <th>Color</th>
                                <th>Cup</th>
                                <th>Qty</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php foreach ($order['items'] as $it): ?>
                            <tr>
                                <td><strong>#<?= htmlspecialchars($order['order_no']) ?></strong></td>
                                <td><?= htmlspecialchars(date('d/m/Y', strtotime($order['v_date']))) ?></td>
                                <td><?= htmlspecialchars($order['customer_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($it['item_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($it['color'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($it['cup'] ?? '-') ?></td>
                                <td><?= (int)$it['total_qty'] ?></td>
                                <td>
                                    <span class="badge badge-status status-<?= $it['item_status'] ?? 'pending' ?>">
                                        <?= ucfirst($it['item_status'] ?? 'pending') ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit_order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-primary">View/Edit</a>
                                    <a href="print_recipt.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary">Print</a>
                                    <form method="POST" action="order_delete.php" style="display:inline" onsubmit="return confirm('Delete order #<?= $order['order_no'] ?>?')">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted">Total items: <?= $total_items ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
