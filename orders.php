<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('db.php'); // Your DB connection
// Set timeout and memory limits
set_time_limit(60);
ini_set('memory_limit', '256M');

// Start session
session_start();



// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}

$cid = $_SESSION['cid'];
$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? 0;
$user_name = $_SESSION['name'];
$user_role = $_SESSION['role'] ?? 'employee';

$name = $_SESSION['name'];

// Initialize filter variables
$voucher_no = isset($_GET['voucher_no']) ? trim($_GET['voucher_no']) : '';
$customer_id = isset($_GET['customer_id']) ? trim($_GET['customer_id']) : '';
$sdate = isset($_GET['sdate']) ? $_GET['sdate'] : '';
$edate = isset($_GET['edate']) ? $_GET['edate'] : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build query for orders
    $query = "
    SELECT 
        o.id, 
        o.order_no, 
        o.v_date, 
        o.total_qty,
        o.status,
        c.id as customer_id,
        c.name as customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.cid = ?
";
$params = [$cid];
$param_types = "i";

// Add filters
if (!empty($voucher_no)) {
    $query .= " AND o.order_no = ?";
    $params[] = $voucher_no;
    $param_types .= "s";
}

if ($customer_id !== '') {
    $query .= " AND o.customer_id = ?";
    $params[] = $customer_id;
    $param_types .= "s";
}

if (!empty($sdate)) {
    if (!empty($edate)) {
        $query .= " AND o.v_date BETWEEN ? AND ?";
        $params[] = $sdate;
        $params[] = $edate;
        $param_types .= "ss";
    } else {
        $query .= " AND o.v_date >= ?";
        $params[] = $sdate;
        $param_types .= "s";
    }
} elseif (!empty($edate)) {
    $query .= " AND o.v_date <= ?";
    $params[] = $edate;
    $param_types .= "s";
}

if (!empty($status_filter)) {
    $query .= " AND EXISTS (SELECT 1 FROM order_item_detail oid WHERE oid.order_id = o.id AND oid.item_status = ?)";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Role-based filtering: admin/warehouse see all, employees see only their orders
if (!in_array($user_role, ['admin', 'warehouse'])) {
    $query .= " AND o.preparedby = ?";
    $params[] = $user_name;
    $param_types .= "s";
}

$query .= " ORDER BY o.v_date DESC, o.order_no DESC";

// Prepare and execute main query
$stmt = $conn->prepare($query);

if ($stmt === false) {
    die("Query preparation failed: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

$orders_result = $stmt->get_result();
if (!$orders_result) {
    die("Get result failed: " . $stmt->error);
}

$orders = [];

// Fetch all orders
while ($order = $orders_result->fetch_assoc()) {
    $orders[$order['id']] = $order;
    $orders[$order['id']]['items'] = [];
}

$stmt->close();

// If we have orders, fetch their items and details
// If we have orders, fetch their items and details
if (!empty($orders)) {
    $order_ids = array_keys($orders);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $param_types = str_repeat('i', count($order_ids));

    $items_query = "
        SELECT 
            oid.id,
            oid.order_id,
            oid.item_id,
            oid.color,
            oid.cup,
            oid.size_32, oid.size_34, oid.size_36, oid.size_38, oid.size_40, oid.size_42,
            oid.size_44, oid.size_46, oid.size_48, oid.size_50,
            oid.total_qty,
            oid.item_status,
            im.item_name
        FROM order_item_detail oid
        LEFT JOIN item_masters im ON oid.item_id = im.id
        WHERE oid.order_id IN ($placeholders)
        ORDER BY oid.id
    ";

    $stmt = $conn->prepare($items_query);
    if ($stmt === false) {
        die("Items query failed: " . $conn->error);
    }

    $bind_params = $order_ids;
    $types = $param_types;
    $stmt->bind_param($types, ...$bind_params);

    if (!$stmt->execute()) {
        die("Items execute failed: " . $stmt->error);
    }

    $items_result = $stmt->get_result();
    $order_items = [];
    while ($item = $items_result->fetch_assoc()) {
        $order_items[$item['order_id']][] = $item;
    }
    $stmt->close();

    foreach ($orders as $order_id => $order) {
        $orders[$order_id]['items'] = $order_items[$order_id] ?? [];
    }
}

// Fetch customers for filter dropdown
$customers_stmt = $conn->prepare("SELECT id, name FROM customers WHERE cid = ? ORDER BY name");
if ($customers_stmt === false) {
    die("Customers query preparation failed: " . $conn->error);
}
$customers_stmt->bind_param("i", $cid);
if (!$customers_stmt->execute()) {
    die("Customers execute failed: " . $customers_stmt->error);
}
$customers_result = $customers_stmt->get_result();
if (!$customers_result) {
    die("Customers get result failed: " . $customers_stmt->error);
}
$customers = [];
while ($customer = $customers_result->fetch_assoc()) {
    $customers[] = $customer;
}
$customers_stmt->close();

// Fetch voucher numbers for filter dropdown
$vouchers_stmt = $conn->prepare("SELECT DISTINCT order_no FROM orders WHERE cid = ? ORDER BY order_no DESC");
if ($vouchers_stmt === false) {
    die("Vouchers query preparation failed: " . $conn->error);
}
$vouchers_stmt->bind_param("i", $cid);
if (!$vouchers_stmt->execute()) {
    die("Vouchers execute failed: " . $vouchers_stmt->error);
}
$vouchers_result = $vouchers_stmt->get_result();
if (!$vouchers_result) {
    die("Vouchers get result failed: " . $vouchers_stmt->error);
}
$voucher_nos = [];
while ($voucher = $vouchers_result->fetch_assoc()) {
    $voucher_nos[] = $voucher['order_no'];
}
$vouchers_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }
        .table tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.05);
        }
        .rowspan-cell {
            vertical-align: middle !important;
        }
        .filter-card {
            border-left: 4px solid #0d6efd;
        }
        .badge-order {
            font-size: 0.9em;
        }
        .action-buttons {
            min-width: 150px;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
    </style>
</head>
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<!-- Optional: Select2 Bootstrap theme -->
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.5.2/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<body>
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2 class="mb-0">
                        <i class="bi bi-cart-check me-2"></i>Sale Orders
                    </h2>
                    <div>
                   

                        <a href="logout.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left"></i> Logout
                        </a>
                        <a href="create.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Create New Order
                        </a>
                        <a href="items.php" class="btn btn-outline-info">
                            <i class="bi bi-box-seam-fill"></i> Items
                        </a>
                        <a href="colors.php" class="btn btn-outline-secondary">
                            <i class="bi bi-palette-fill"></i> Colors
                        </a>
                        <a href="cashRecipt_index.php" class="btn btn-outline-secondary btn-warning" >
                            </i> Cash Recipt
                        </a>
                    </div>
                </div>
                <hr>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Debug Info (Remove in production) -->
        <?php if (isset($_GET['debug'])): ?>
            <div class="alert alert-warning">
                <h5>Debug Information:</h5>
                <p>Orders Count: <?= count($orders) ?></p>
                <p>Order IDs: <?= implode(', ', array_keys($orders)) ?></p>
                <p>Customer ID Filter: <?= $customer_id ?></p>
                <p>Date Range: <?= $sdate ?> to <?= $edate ?></p>
            </div>
        <?php endif; ?>

        <!-- Filter Form -->
        <div class="card filter-card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="bi bi-funnel"></i> Filter Orders
                </h5>
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3">
                        <!-- Voucher No -->
                        <div class="col-md-3">
                            <label for="voucher_no" class="form-label">Voucher No</label>
                            <select name="voucher_no" id="voucher_no" class="form-select select2-filter">
                                <option value="">All Voucher Nos</option>
                                <?php foreach ($voucher_nos as $vno): ?>
                                    <option value="<?= htmlspecialchars($vno) ?>" 
                                        <?= ($voucher_no == $vno) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($vno) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Customer -->
                        <div class="col-md-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select name="customer_id" id="customer_id" class="form-select select2-filter">
                                <option value="">All Customers</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= htmlspecialchars($customer['id']) ?>" 
                                        <?= ($customer_id == $customer['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($customer['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Date Range -->
                        <div class="col-md-4">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label for="sdate" class="form-label">Start Date</label>
                                    <input type="date" name="sdate" id="sdate" class="form-control" 
                                           value="<?= htmlspecialchars($sdate) ?>">
                                </div>
                                <div class="col-6">
                                    <label for="edate" class="form-label">End Date</label>
                                    <input type="date" name="edate" id="edate" class="form-control" 
                                           value="<?= htmlspecialchars($edate) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="col-md-2">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>

                        <!-- Action Buttons -->
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="d-grid gap-2 w-100">
                                <button type="submit" class="btn btn-success" id="filterBtn">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="orders.php" class="btn btn-outline-secondary" id="resetBtn">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card shadow">
            <div class="card-body">
                <?php if (empty($orders)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                        <h4 class="mt-3">No orders found</h4>
                        <p class="text-muted">No sale orders match your criteria.</p>
                        <a href="create.php" class="btn btn-primary mt-2">
                            <i class="bi bi-plus-circle"></i> Create Your Order
                        </a>
                        <a href="cashRecipt_index.php" class="btn btn-outline-secondary" >
                                    <i class="bi bi-arrow-clockwise"></i> Cash Recipt
                                </a>
                    </div>
                <?php else: ?>
<div class="table-responsive">
    <style>
        .table-orders {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Segoe UI', Roboto, sans-serif;
            font-size: 0.85rem;
            background: white;
        }
        .table-orders th {
            background: #eef2ff;
            color: #1e293b;
            padding: 12px 8px;
            font-weight: 600;
            font-size: 0.8rem;
            border: 1px solid #d4dcec;
            text-align: center;
        }
        .table-orders td {
            padding: 10px 8px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
            background: white;
        }
        .table-orders th:first-child,
        .table-orders td:first-child,
        .table-orders th:nth-child(2),
        .table-orders td:nth-child(2),
        .table-orders th:nth-child(3),
        .table-orders td:nth-child(3) {
            text-align: left;
            padding-left: 12px;
        }
        .table-orders td:nth-child(n+4),
        .table-orders th:nth-child(n+4) {
            text-align: left;
        }
        .badge-order {
            background: #2563eb;
            color: white;
            padding: 3px 10px;
            border-radius: 30px;
            font-size: 0.75rem;
            display: inline-block;
        }
        .badge-total {
            background: #10b981;
            color: white;
            padding: 4px 8px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-block;
            min-width: 45px;
        }
        .btn-order {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            text-decoration: none;
            background: white;
            border: 1px solid;
        }
        .btn-edit {
            border-color: #3b82f6;
            color: #2563eb;
        }
        .btn-view {
            border-color: #ef4444;
            color: #dc2626;
        }
        .order-group-separator {
            border-top: 2px solid #94a3b8;
        }
        .order-group-first {
            border-top: none;
        }
    </style>

    <table class="table-orders">
        <thead>
            <tr>
                <th>Order No</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Article</th>
                <th>Cup</th>
                <th>Color</th>
                <th>Item Status</th>
                <th>32</th><th>34</th><th>36</th><th>38</th><th>40</th><th>42</th>
                <th>44</th><th>46</th><th>48</th><th>50</th>
                <th>Total</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $orderCount = 0;
            foreach ($orders as $order): 
                if (!empty($order['items'])): 
                    $rowspan = count($order['items']);
                    $orderCount++;
                    $firstRowClass = ($orderCount === 1) ? 'order-group-first' : 'order-group-separator';
                    
                    // First row of this order (shows Order No, Date, Customer, Actions)
                    $firstItem = $order['items'][0];
                    ?>
                    <tr class="<?= $firstRowClass ?>">
                        <td rowspan="<?= $rowspan ?>" style="vertical-align: middle;">
                            <span class="badge-order">#<?= htmlspecialchars($order['order_no']) ?></span>
                        </td>
                        <td rowspan="<?= $rowspan ?>" style="vertical-align: middle;">
                            <?= htmlspecialchars(date('d/m/Y', strtotime($order['v_date']))) ?>
                        </td>
                        <td rowspan="<?= $rowspan ?>" style="vertical-align: middle;">
                            <?= htmlspecialchars($order['customer_name'] ?? '-') ?>
                        </td>
                        <td style="text-align: left;"><?= htmlspecialchars($firstItem['item_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($firstItem['cup'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($firstItem['color'] ?? '-') ?></td>
                        <td>
                            <?php
                            $st = $firstItem['item_status'] ?? 'pending';
                            $cls = $st === 'completed' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                            ?>
                            <span class="badge <?= $cls ?>" style="font-size:0.7rem;"><?= ucfirst($st) ?></span>
                        </td>
                        <td><?= (int)($firstItem['size_32'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_34'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_36'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_38'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_40'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_42'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_44'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_46'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_48'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($firstItem['size_50'] ?? 0) ?: '-' ?></td>
                        <td><span class="badge-total"><?= (int)($firstItem['total_qty'] ?? 0) ?></span></td>
                        <td rowspan="<?= $rowspan ?>" style="vertical-align: middle; white-space: nowrap;">
                            <a href="edit_order.php?id=<?= $order['id'] ?>" class="btn-order btn-edit">✏️ Edit</a>
                            <a href="print_recipt.php?id=<?= $order['id'] ?>" class="btn-order btn-view">👁️ View</a>
                            <form method="POST" action="order_delete.php" style="display:inline" onsubmit="return confirm('Delete order #<?= $order['order_no'] ?>?')">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit" class="btn-order" style="border-color:#dc3545;color:#dc3545;">🗑️ Delete</button>
                            </form>
                        </td>
                    </tr>
                    
                    <!-- Remaining items (2nd, 3rd, etc.) - no rowspan cells, just Item, Cup, Color, sizes -->
                    <?php for ($i = 1; $i < $rowspan; $i++): 
                        $item = $order['items'][$i];
                    ?>
                        <tr>
                            <!-- No Order No, Date, Customer, Actions cells here -->
                            <td style="text-align: left;"><?= htmlspecialchars($item['item_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($item['cup'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($item['color'] ?? '-') ?></td>
                            <td>
                                <?php
                                $st = $item['item_status'] ?? 'pending';
                                $cls = $st === 'completed' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                                ?>
                                <span class="badge <?= $cls ?>" style="font-size:0.7rem;"><?= ucfirst($st) ?></span>
                            </td>
                            <td><?= (int)($item['size_32'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_34'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_36'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_38'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_40'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_42'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_44'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_46'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_48'] ?? 0) ?: '-' ?></td>
                            <td><?= (int)($item['size_50'] ?? 0) ?: '-' ?></td>
                            <td><span class="badge-total"><?= (int)($item['total_qty'] ?? 0) ?></span></td>
                        </tr>
                    <?php endfor; ?>
                    
                <?php else: ?>
                    <?php $orderCount++; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
                    
                    <!-- Summary -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-info-circle me-2"></i>
                                        Showing <strong><?= count($orders) ?></strong> orders
                                    </div>
                                    <div>
                                        Total Quantity: <strong>
                                            <?= array_sum(array_column($orders, 'total_qty')) ?>
                                        </strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // Initialize Select2 for your dropdowns
    $(document).ready(function() {
        $('select[name="account_id"]').select2({
            theme: 'bootstrap-5', // matches Bootstrap 5 styling
            placeholder: 'Select Account',
            allowClear: true
        });

        $('select[name="v_no"]').select2({
            theme: 'bootstrap-5',
            placeholder: 'Select Voucher No',
            allowClear: true
        });
    });
</script>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize Select2 for filter dropdowns
        $('.select2-filter').select2({
            width: '100%',
            placeholder: 'Select option',
            allowClear: true
        });
        
        // Show loading overlay on form submit
        $('#filterForm').on('submit', function() {
            $('#loadingOverlay').show();
        });
        
        // Show loading overlay on reset
        $('#resetBtn').on('click', function() {
            $('#loadingOverlay').show();
        });
        
        // Date validation
        $('#filterForm').on('submit', function(e) {
            const sdate = $('#sdate').val();
            const edate = $('#edate').val();
            
            if (sdate && edate && sdate > edate) {
                e.preventDefault();
                alert('Start date cannot be after end date.');
                $('#loadingOverlay').hide();
                return false;
            }
        });
        
        // Clear filters when reset button is clicked
        $('#resetBtn').on('click', function(e) {
            e.preventDefault();
            window.location.href = 'orders.php';
        });
        
        // Hide loading overlay when page is fully loaded
        $(window).on('load', function() {
            $('#loadingOverlay').hide();
        });
        
        // Auto-hide loading overlay after 5 seconds (fallback)
        setTimeout(function() {
            $('#loadingOverlay').hide();
        }, 5000);
    });
    </script>
</body>
</html>
<?php
$conn->close();
?>



