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
        die("Items query preparation failed: " . $conn->error);
    }

    $stmt->bind_param($param_types, ...$order_ids);

    if (!$stmt->execute()) {
        die("Items execute failed: " . $stmt->error);
    }

    $items_result = $stmt->get_result();
    if (!$items_result) {
        die("Items get result failed: " . $stmt->error);
    }

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
$vouchers = [];
while ($voucher = $vouchers_result->fetch_assoc()) {
    $vouchers[] = $voucher['order_no'];
}
$vouchers_stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Orders - Hashmi Brothers</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #0dcaf0;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-card {
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0,0,0,0.08);
            border: none;
        }

        .section-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #0a58ca 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .filter-label {
            font-weight: 600;
            color: var(--secondary-color);
            font-size: 0.875rem;
            margin-bottom: 5px;
        }

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }

        .table th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: var(--secondary-color);
            padding: 12px 15px;
        }

        .table td {
            padding: 12px 15px;
            vertical-align: middle;
        }

        .table tbody tr {
            transition: all 0.2s;
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .order-row {
            cursor: pointer;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-completed { background-color: #d1e7dd; color: #0f5132; }
        .status-rejected { background-color: #f8d7da; color: #842029; }

        .btn-action {
            padding: 6px 12px;
            font-size: 0.85rem;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .voucher-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .voucher-link:hover {
            color: #0a58ca;
            text-decoration: underline;
        }

        .filter-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
        }

        .page-header h2 {
            font-weight: 700;
            color: #212529;
        }

        .user-badge {
            font-size: 0.75rem;
        }

        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .btn-action {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        .grand-total {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }

        .grand-total-value {
            font-size: 2.5rem;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
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
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="text-muted small">
                            <?= htmlspecialchars($user_name) ?> 
                            <span class="badge bg-<?= $user_role === 'admin' ? 'danger' : ($user_role === 'warehouse' ? 'warning text-dark' : 'info') ?>">
                                <?= ucfirst($user_role) ?>
                            </span>
                        </span>
                        
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
                        <?php if (in_array($user_role, ['admin', 'warehouse'])): ?>
                        <a href="cashRecipt_index.php" class="btn btn-outline-secondary btn-warning">
                            <i class="bi bi-cash-stack"></i> Cash Recipt
                        </a>
                        <?php endif; ?>
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
        <div class="alert alert-info">
            <strong>Debug:</strong> CID=<?= $cid ?>, User=<?= htmlspecialchars($user_name) ?>, Role=<?= $user_role ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card filter-card mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-funnel-fill me-2"></i>Filters</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="filter-label">Voucher No</label>
                        <select name="voucher_no" class="form-select filter-select">
                            <option value="">All Vouchers</option>
                            <?php foreach ($vouchers as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>" <?= $voucher_no === $v ? 'selected' : '' ?>>
                                    #<?= htmlspecialchars($v) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="filter-label">Customer</label>
                        <select name="customer_id" class="form-select filter-select">
                            <option value="">All Customers</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $customer_id == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">From Date</label>
                        <input type="date" name="sdate" class="form-control filter-select" value="<?= htmlspecialchars($sdate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">To Date</label>
                        <input type="date" name="edate" class="form-control filter-select" value="<?= htmlspecialchars($edate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">Status</label>
                        <select name="status" class="form-select filter-select">
                            <option value="">All Status</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i> Filter
                        </button>
                        <a href="orders.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card main-card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Voucher No</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th style="width: 100px;">Qty</th>
                                <th style="width: 130px;">Status</th>
                                <th style="width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <i class="bi bi-inbox-fill" style="font-size: 3rem; color: #dee2e6;"></i>
                                        <p class="text-muted mt-2 mb-0">No orders found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($orders as $order): ?>
                                    <tr class="order-row" onclick="window.location.href='edit_order.php?id=<?= $order['id'] ?>'">
                                        <td><?= $counter++ ?></td>
                                        <td>
                                            <a href="edit_order.php?id=<?= $order['id'] ?>" class="voucher-link">
                                                #<?= htmlspecialchars($order['order_no']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d M Y', strtotime($order['v_date'])) ?></td>
                                        <td><?= htmlspecialchars($order['customer_name'] ?? 'N/A') ?></td>
                                        <td class="fw-bold"><?= $order['total_qty'] ?></td>
                                        <td>
                                            <?php 
                                                $status_class = '';
                                                switch ($order['status']) {
                                                    case 'pending': $status_class = 'status-pending'; break;
                                                    case 'completed': $status_class = 'status-completed'; break;
                                                    case 'rejected': $status_class = 'status-rejected'; break;
                                                }
                                            ?>
                                            <span class="status-badge <?= $status_class ?>">
                                                <?= ucfirst($order['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <a href="edit_order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-primary btn-action" onclick="event.stopPropagation()">
                                                    <i class="bi bi-pencil-fill"></i> Edit
                                                </a>
                                                <a href="print_recipt.php?id=<?= $order['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info btn-action" onclick="event.stopPropagation()">
                                                    <i class="bi bi-printer-fill"></i> Print
                                                </a>
                                                <?php if (in_array($user_role, ['admin', 'warehouse'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-action" onclick="event.stopPropagation(); confirmDelete(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_no']) ?>')">
                                                    <i class="bi bi-trash-fill"></i> Delete
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete order <strong id="deleteOrderNo"></strong>?</p>
                    <p class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="order_delete.php" style="display: inline;">
                        <input type="hidden" name="order_id" id="deleteOrderId">
                        <button type="submit" class="btn btn-danger">Delete Order</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize Select2 for better dropdowns
            $('.filter-select').select2({
                width: '100%',
                placeholder: 'Select...'
            });

            // Delete confirmation
            window.confirmDelete = function(orderId, orderNo) {
                $('#deleteOrderId').val(orderId);
                $('#deleteOrderNo').text('#' + orderNo);
                $('#deleteModal').modal('show');
            };

            // Keyboard navigation for order rows
            $('.order-row').on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).click();
                }
            });
        });
    </script>
</body>
</html>