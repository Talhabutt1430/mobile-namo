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

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid order ID.";
    header("Location: index.php");
    exit();
}

$order_id = intval($_GET['id']);

// Predefined options
$cups = ['A', 'B', 'C', 'D', 'DD', 'E'];
$sizes_group1 = ['32', '34', '36', '38', '40', '42'];
$sizes_group2 = ['44', '46', '48', '50'];

// Fetch customers
$customers = [];
$stmt = $conn->prepare("SELECT id, name FROM customers ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

// Fetch items
$items = [];
$stmt = $conn->prepare("SELECT id, item_name FROM item_masters ORDER BY item_name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

// Fetch suppliers
$suppliers = [];
$stmt = $conn->prepare("SELECT id, name, company_name FROM suppliers ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}
$stmt->close();

// Fetch colors
$colors = [];
$stmt = $conn->prepare("SELECT name FROM colors WHERE cid = ? ORDER BY name");
$stmt->bind_param("i", $cid);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $colors[] = $row['name'];
}
$stmt->close();

// Fetch order
$stmt = $conn->prepare("
    SELECT o.*, c.name as customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_result = $stmt->get_result();

if ($order_result->num_rows === 0) {
    $_SESSION['error'] = "Order not found.";
    header("Location: index.php");
    exit();
}

$redirect_from = isset($_GET['from']) ? $_GET['from'] : 'admin';
$order = $order_result->fetch_assoc();
$stmt->close();

// Fetch order items (grouped by article)
$order_items = [];
$stmt = $conn->prepare("
    SELECT 
        oid.item_id,
        im.item_name,
        GROUP_CONCAT(DISTINCT oid.id) as detail_ids
    FROM order_item_detail oid
    LEFT JOIN item_masters im ON oid.item_id = im.id
    WHERE oid.order_id = ?
    GROUP BY oid.item_id, im.item_name
    ORDER BY oid.item_id
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

while ($item = $result->fetch_assoc()) {
    // Fetch all rows for this item
    $detail_stmt = $conn->prepare("
        SELECT * FROM order_item_detail 
        WHERE order_id = ? AND item_id = ?
        ORDER BY id
    ");
    $detail_stmt->bind_param("ii", $order_id, $item['item_id']);
    $detail_stmt->execute();
    $detail_result = $detail_stmt->get_result();
    
    $item['rows'] = [];
    while ($detail = $detail_result->fetch_assoc()) {
        $item['rows'][] = $detail;
    }
    $detail_stmt->close();
    
    $order_items[] = $item;
}
$stmt->close();

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    try {
        $customer_id = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
        $v_date = $_POST['v_date'] ?? '';
        $order_status = $_POST['order_status'] ?? 'pending';
        $supplier_id = !empty($_POST['supplier_id']) ? filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT) : null;
        $items_data = $_POST['items'] ?? [];

        if (!$customer_id || $customer_id <= 0) {
            throw new Exception("Valid customer is required.");
        }
        
        if (empty($v_date)) {
            throw new Exception("Date is required.");
        }

        $conn->begin_transaction();

        // Update order basic info
        $stmt = $conn->prepare("
            UPDATE orders 
            SET customer_id = ?, supplier_id = ?, v_date = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("iissi", $customer_id, $supplier_id, $v_date, $order_status, $order_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update order: " . $stmt->error);
        }
        $stmt->close();

        // Delete all existing order_item_detail for this order
        $stmt = $conn->prepare("DELETE FROM order_item_detail WHERE order_id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();

        // Insert new data
        $totalOrderQty = 0;
        $stmtDetail = $conn->prepare("INSERT INTO order_item_detail 
            (order_id, order_no, item_id, color, cup, size_32, size_34, size_36, size_38, size_40, size_42, 
             size_44, size_46, size_48, size_50, total_qty, item_status, cid, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

        foreach ($items_data as $itemData) {
            $item_id = filter_var($itemData['item_id'] ?? 0, FILTER_VALIDATE_INT);
            $rows = $itemData['rows'] ?? [];

            if (!$item_id || $item_id <= 0) {
                throw new Exception("Invalid item selected.");
            }

            foreach ($rows as $row) {
                $color = htmlspecialchars($row['color'] ?? '', ENT_QUOTES);
                $cup = htmlspecialchars($row['cup'] ?? 'B', ENT_QUOTES);
                $item_status = $row['item_status'] ?? 'pending';
                
                $size_32 = filter_var($row['size_32'] ?? 0, FILTER_VALIDATE_INT);
                $size_34 = filter_var($row['size_34'] ?? 0, FILTER_VALIDATE_INT);
                $size_36 = filter_var($row['size_36'] ?? 0, FILTER_VALIDATE_INT);
                $size_38 = filter_var($row['size_38'] ?? 0, FILTER_VALIDATE_INT);
                $size_40 = filter_var($row['size_40'] ?? 0, FILTER_VALIDATE_INT);
                $size_42 = filter_var($row['size_42'] ?? 0, FILTER_VALIDATE_INT);
                $size_44 = filter_var($row['size_44'] ?? 0, FILTER_VALIDATE_INT);
                $size_46 = filter_var($row['size_46'] ?? 0, FILTER_VALIDATE_INT);
                $size_48 = filter_var($row['size_48'] ?? 0, FILTER_VALIDATE_INT);
                $size_50 = filter_var($row['size_50'] ?? 0, FILTER_VALIDATE_INT);
                
                $row_total = $size_32 + $size_34 + $size_36 + $size_38 + $size_40 + $size_42 + 
                            $size_44 + $size_46 + $size_48 + $size_50;
                
                if ($row_total <= 0) {
                    continue; // Skip empty rows
                }
                
                $stmtDetail->bind_param("iiissiiiiiiiiiiisi", 
                    $order_id, $order['order_no'], $item_id, $color, $cup,
                    $size_32, $size_34, $size_36, $size_38, $size_40, $size_42,
                    $size_44, $size_46, $size_48, $size_50, $row_total, $item_status, $cid
                );
                
                if (!$stmtDetail->execute()) {
                    throw new Exception("Failed to add item details: " . $stmtDetail->error);
                }
                
                $totalOrderQty += $row_total;
            }
        }

        // Update total quantity
        $stmt = $conn->prepare("UPDATE orders SET total_qty = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $totalOrderQty, $order_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update order total: " . $stmt->error);
        }

        $conn->commit();
        
        $stmtDetail->close();
        $stmt->close();

        $redirect_page = ($redirect_from === 'warehouse') ? 'warehouse.php' : 'orders.php';
        echo json_encode([
            'success' => true,
            'message' => "Order #{$order['order_no']} updated successfully!",
            'redirect' => $redirect_page
        ]);
        exit;

    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Error updating order: " . $e->getMessage()
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Order #<?= htmlspecialchars($order['order_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
        }
        
        body {
            background-color: #f8f9fa;
        }
        
        .main-card {
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0,0,0,0.1);
        }
        
        .item-card {
            border-left: 5px solid var(--primary-color);
            background: #fff;
            transition: all 0.3s;
        }
        
        .item-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .row-detail {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 3px solid var(--success-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .size-input {
            width: 70px;
            text-align: center;
            font-weight: 600;
        }
        
        .size-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 5px;
        }
        
        .required::after {
            content: " *";
            color: var(--danger-color);
        }
        
        .grand-total-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
        }
        
        .grand-total-value {
            font-size: 3rem;
            font-weight: bold;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .row-total-badge {
            font-size: 1.1rem;
            padding: 8px 15px;
        }
        
        .section-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #0a58ca 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .btn-add-item {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-add-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-add-row {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
        }
        
        @media (max-width: 768px) {
            .size-input {
                width: 60px;
                font-size: 0.85rem;
            }
            
            .grand-total-value {
                font-size: 2rem;
            }
            
            .size-label {
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 576px) {
            .size-input {
                width: 50px;
                font-size: 0.8rem;
            }
        }
        
        .loading-overlay {
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
        }
        
        .spinner-wrapper {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="card main-card">
        <div class="card-header section-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h3 class="mb-0">
                    <i class="bi bi-pencil-square"></i> Edit Order #<?= htmlspecialchars($order['order_no']) ?>
                </h3>
                <a href="<?= ($redirect_from === 'warehouse') ? 'warehouse.php' : 'orders.php' ?>" class="btn btn-light btn-sm mt-2 mt-md-0">
                    <i class="bi bi-arrow-left"></i> Back to Orders
                </a>
            </div>
        </div>
        
        <div class="card-body p-3 p-md-4">
            <form id="orderForm" method="POST" action="">
                <!-- Customer and Date Selection -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Customer</label>
                        <select name="customer_id" class="form-select select2-customer" required>
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= htmlspecialchars($c['id']) ?>" 
                                    <?= $order['customer_id'] == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a customer.</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Order Date</label>
                        <input type="date" name="v_date" class="form-control" 
                               value="<?= htmlspecialchars($order['v_date']) ?>" 
                               max="<?= date('Y-m-d') ?>" required>
                        <div class="invalid-feedback">Please select a valid date.</div>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Status</label>
                        <select name="order_status" class="form-select">
                            <option value="pending" <?= $order['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="completed" <?= $order['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="rejected" <?= $order['status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                </div>

                <!-- Items Section -->



                <div class="mb-4">
                    <div class="section-header">
                        <h5 class="mb-0">
                            <i class="bi bi-box-seam-fill"></i> Order Articles
                        </h5>
                    </div>
                    
                    <div id="items-container">
                        <?php foreach ($order_items as $idx => $item): ?>
                        <div class="card mb-4 item-card" data-item="<?= $idx ?>" id="item-<?= $idx ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0 text-primary">
                                        <i class="bi bi-box-fill"></i> Article #<?= $idx + 1 ?>
                                    </h5>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(<?= $idx ?>)">
                                        <i class="bi bi-trash-fill"></i> Remove Article
                                    </button>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required">Select Article</label>
                                    <select name="items[<?= $idx ?>][item_id]" 
                                            class="form-select item-select" 
                                            onchange="validateItem(<?= $idx ?>)" required>
                                        <option value="">-- Select Article --</option>
                                        <?php foreach ($items as $it): ?>
                                        <option value="<?= $it['id'] ?>" 
                                            <?= $item['item_id'] == $it['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($it['item_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select an article.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label mb-2 required">Article Details (Color, Cup & Sizes)</label>
                                    <div id="rows-<?= $idx ?>" class="mt-2">
                                        <?php foreach ($item['rows'] as $rowIdx => $row): ?>
                                        <div class="row-detail" data-row="<?= $rowIdx ?>" id="row-<?= $idx ?>-<?= $rowIdx ?>">
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-3 col-lg-2">
                                                    <label class="form-label required small">Color</label>
                                                    <select name="items[<?= $idx ?>][rows][<?= $rowIdx ?>][color]" 
                                                            class="form-select form-select-sm" required>
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($colors as $color): ?>
                                                        <option value="<?= $color ?>" 
                                                            <?= $row['color'] == $color ? 'selected' : '' ?>>
                                                            <?= $color ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                
                                                <div class="col-md-2 col-lg-2">
                                                    <label class="form-label required small">Cup</label>
                                                    <select name="items[<?= $idx ?>][rows][<?= $rowIdx ?>][cup]" 
                                                            class="form-select form-select-sm" required>
                                                        <?php foreach ($cups as $cup): ?>
                                                        <option value="<?= $cup ?>" 
                                                            <?= $row['cup'] == $cup ? 'selected' : '' ?>>
                                                            <?= $cup ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-md-3 col-lg-2">
                                                    <label class="form-label small">Status</label>
                                                    <select name="items[<?= $idx ?>][rows][<?= $rowIdx ?>][item_status]" class="form-select form-select-sm">
                                                        <option value="pending" <?= $row['item_status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                        <option value="completed" <?= $row['item_status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                                        <option value="rejected" <?= $row['item_status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                                    </select>
                                                </div>
                                                
                                                <div class="col-md-4 col-lg-6 d-flex align-items-end">
                                                    <button type="button" class="btn btn-sm btn-outline-danger w-100" 
                                                            onclick="removeRow(<?= $idx ?>, <?= $rowIdx ?>)">
                                                        <i class="bi bi-x-circle-fill"></i> Remove Row
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <label class="form-label mb-0 small">
                                                        <strong>Sizes (32-42: Standard | 44-50: Plus Size)</strong>
                                                    </label>
                                                    <span class="badge row-total-badge bg-success" id="row-total-<?= $idx ?>-<?= $rowIdx ?>">
                                                        Total: <?= $row['total_qty'] ?> pcs
                                                    </span>
                                                </div>
                                                <div class="row g-2">
                                                    <?php 
                                                    $allSizes = array_merge($sizes_group1, $sizes_group2);
                                                    foreach ($allSizes as $size): 
                                                        $isPlus = in_array($size, $sizes_group2);
                                                        $bgClass = $isPlus ? 'bg-warning bg-opacity-10' : '';
                                                    ?>
                                                    <div class="col-4 col-sm-3 col-md-2 col-lg-1 mb-2">
                                                        <div class="size-label text-center"><?= $size ?></div>
                                                        <input type="number" 
                                                               name="items[<?= $idx ?>][rows][<?= $rowIdx ?>][size_<?= $size ?>]" 
                                                               class="form-control size-input <?= $bgClass ?>" 
                                                               value="<?= $row['size_' . $size] ?>" 
                                                               min="0"
                                                               oninput="calculateTotals()">
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="text-center mt-2">
                                        <button type="button" class="btn btn-add-row" onclick="addRow(<?= $idx ?>)">
                                            <i class="bi bi-plus-lg"></i> Add Row
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info mb-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong><i class="bi bi-info-circle-fill"></i> Article Total:</strong>
                                        <span class="badge bg-primary fs-5" id="item-total-<?= $idx ?>">
                                            <?php 
                                            $itemTotal = 0;
                                            foreach ($item['rows'] as $r) {
                                                $itemTotal += $r['total_qty'];
                                            }
                                            echo $itemTotal;
                                            ?> pcs
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="button" class="btn btn-add-item" onclick="addItem()">
                        <i class="bi bi-plus-circle-fill"></i> Add Article
                    </button>
                </div>

                <!-- Grand Total -->
                <div class="grand-total-card mb-4">
                    <div class="text-center">
                        <h5 class="mb-3">Grand Total Quantity</h5>
                        <div class="grand-total-value" id="grand-total"><?= $order['total_qty'] ?></div>
                        <small>Total pieces across all items</small>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                    <a href="<?= ($redirect_from === 'warehouse') ? 'warehouse.php' : 'orders.php' ?>" class="btn btn-secondary px-4">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-success px-5" id="submitBtn">
                        <i class="bi bi-check-circle-fill"></i> Update Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Loading Spinner -->
<div id="loadingSpinner" class="d-none position-fixed top-0 start-0 w-100 h-100 loading-overlay" style="z-index: 9999;">
    <div class="d-flex justify-content-center align-items-center h-100">
        <div class="spinner-wrapper text-center">
            <div class="spinner-border text-primary mb-3" style="width: 4rem; height: 4rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h5 class="text-dark">Updating Your Order...</h5>
            <p class="text-muted mb-0">Please wait</p>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const colors = <?= json_encode($colors) ?>;
const cups = <?= json_encode($cups) ?>;
const sizesGroup1 = <?= json_encode($sizes_group1) ?>;
const sizesGroup2 = <?= json_encode($sizes_group2) ?>;
const itemsData = <?= json_encode($items) ?>;

let itemIndex = <?= count($order_items) ?>;
let rowCounters = <?= json_encode(array_map(function($item) { 
    return count($item['rows']); 
}, $order_items)) ?>;
let totalItems = <?= count($order_items) ?>;

function addItem() {
    rowCounters[itemIndex] = 0;
    
    const itemOptions = itemsData.map(i => 
        `<option value="${i.id}">${i.item_name}</option>`
    ).join('');
    
    const html = `
    <div class="card mb-4 item-card" data-item="${itemIndex}" id="item-${itemIndex}">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 text-primary">
                    <i class="bi bi-box-fill"></i> Article #${itemIndex + 1}
                </h5>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${itemIndex})">
                    <i class="bi bi-trash-fill"></i> Remove Article
                </button>
            </div>
            
            <div class="mb-3">
                <label class="form-label required">Select Article</label>
                <select name="items[${itemIndex}][item_id]" 
                        class="form-select item-select" 
                        onchange="validateItem(${itemIndex})" required>
                    <option value="">-- Select Article --</option>
                    ${itemOptions}
                </select>
                <div class="invalid-feedback">Please select an article.</div>
            </div>
            
            <div class="mb-3">
                <label class="form-label mb-2 required">Article Details (Color, Cup & Sizes)</label>
                <div id="rows-${itemIndex}" class="mt-2"></div>
                <div class="text-center mt-2">
                    <button type="button" class="btn btn-add-row" onclick="addRow(${itemIndex})">
                        <i class="bi bi-plus-lg"></i> Add Row
                    </button>
                </div>
            </div>
            
            <div class="alert alert-info mb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-info-circle-fill"></i> Article Total:</strong>
                    <span class="badge bg-primary fs-5" id="item-total-${itemIndex}">0 pcs</span>
                </div>
            </div>
        </div>
    </div>`;
    
    $('#items-container').append(html);
    $(`#item-${itemIndex} .item-select`).select2({ 
        width: '100%',
        placeholder: 'Select an article'
    });
    itemIndex++;
    totalItems++;
    updateSubmitButton();
}

function addRow(itemIdx) {
    const rowId = rowCounters[itemIdx]++;
    
    const colorOptions = colors.map(c => 
        `<option value="${c}">${c}</option>`
    ).join('');
    
    const cupOptions = cups.map((c, idx) => 
        `<option value="${c}" ${c === 'B' ? 'selected' : ''}>${c}</option>`
    ).join('');
    
    const allSizes = [...sizesGroup1, ...sizesGroup2];
    const sizeFields = allSizes.map((size, idx) => {
        const isGroup2 = sizesGroup2.includes(size);
        const bgClass = isGroup2 ? 'bg-warning bg-opacity-10' : '';
        return `
        <div class="col-4 col-sm-3 col-md-2 col-lg-1 mb-2">
            <div class="size-label text-center">${size}</div>
            <input type="number" 
                   name="items[${itemIdx}][rows][${rowId}][size_${size}]" 
                   class="form-control size-input ${bgClass}" 
                   placeholder="0" 
                   min="0" 
                   value="0"
                   oninput="calculateTotals()">
        </div>`;
    }).join('');
    
    const html = `
    <div class="row-detail" data-row="${rowId}" id="row-${itemIdx}-${rowId}">
        <div class="row g-2 mb-3">
            <div class="col-md-3 col-lg-2">
                <label class="form-label required small">Color</label>
                <select name="items[${itemIdx}][rows][${rowId}][color]" 
                        class="form-select form-select-sm" required>
                    <option value="">-- Select --</option>
                    ${colorOptions}
                </select>
            </div>
            
            <div class="col-md-2 col-lg-2">
                <label class="form-label required small">Cup</label>
                <select name="items[${itemIdx}][rows][${rowId}][cup]" 
                        class="form-select form-select-sm" required>
                    ${cupOptions}
                </select>
            </div>

            <div class="col-md-3 col-lg-2">
                <label class="form-label small">Status</label>
                <select name="items[${itemIdx}][rows][${rowId}][item_status]" class="form-select form-select-sm">
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            
            <div class="col-md-4 col-lg-6 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-danger w-100" 
                        onclick="removeRow(${itemIdx}, ${rowId})">
                    <i class="bi bi-x-circle-fill"></i> Remove Row
                </button>
            </div>
        </div>
        
        <div class="mb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0 small">
                    <strong>Sizes (32-42: Standard | 44-50: Plus Size)</strong>
                </label>
                <span class="badge row-total-badge bg-success" id="row-total-${itemIdx}-${rowId}">
                    Total: 0 pcs
                </span>
            </div>
            <div class="row g-2">
                ${sizeFields}
            </div>
        </div>
    </div>`;
    
    $(`#rows-${itemIdx}`).append(html);
    calculateTotals();
}

function removeRow(itemIdx, rowId) {
    if (confirm('Remove this row?')) {
        $(`#row-${itemIdx}-${rowId}`).remove();
        rowCounters[itemIdx]--;
        calculateTotals();
    }
}

function removeItem(idx) {
    if (confirm('Remove this entire article and all its rows?')) {
        $(`#item-${idx}`).remove();
        delete rowCounters[idx];
        totalItems--;
        calculateTotals();
        updateSubmitButton();
    }
}

function validateItem(idx) {
    const select = $(`#item-${idx} select[name*="[item_id]"]`);
    if (!select.val()) {
        select.addClass('is-invalid');
        return false;
    }
    select.removeClass('is-invalid');
    return true;
}

function calculateTotals() {
    let grandTotal = 0;
    
    $('.item-card').each(function() {
        const itemIdx = $(this).data('item');
        let itemTotal = 0;
        
        $(this).find('.row-detail').each(function() {
            const rowId = $(this).data('row');
            let rowTotal = 0;
            
            $(this).find('.size-input').each(function() {
                const val = parseInt($(this).val()) || 0;
                rowTotal += val;
            });
            
            $(`#row-total-${itemIdx}-${rowId}`).text(`Total: ${rowTotal} pcs`);
            itemTotal += rowTotal;
        });
        
        $(`#item-total-${itemIdx}`).text(`${itemTotal} pcs`);
        grandTotal += itemTotal;
    });
    
    $('#grand-total').text(grandTotal);
}

function updateSubmitButton() {
    const submitBtn = $('#submitBtn');
    if (totalItems === 0) {
        submitBtn.prop('disabled', true).addClass('disabled');
    } else {
        submitBtn.prop('disabled', false).removeClass('disabled');
    }
}

function validateForm() {
    let isValid = true;
    
    if (!$('.select2-customer').val()) {
        $('.select2-customer').addClass('is-invalid');
        isValid = false;
    }
    
    if (!$('input[name="v_date"]').val()) {
        $('input[name="v_date"]').addClass('is-invalid');
        isValid = false;
    }
    
    $('.item-card').each(function() {
        const idx = $(this).data('item');
        if (!validateItem(idx)) {
            isValid = false;
        }
        
        if ($(this).find('.row-detail').length === 0) {
            alert(`Article #${idx + 1} must have at least one row with color and sizes.`);
            isValid = false;
        }
        
        $(this).find('.row-detail').each(function() {
            const colorSelect = $(this).find('select[name*="[color]"]');
            if (!colorSelect.val()) {
                colorSelect.addClass('is-invalid');
                isValid = false;
            }
        });
    });
    
    if (totalItems === 0) {
        alert('At least one article is required.');
        isValid = false;
    }
    
    return isValid;
}

$(document).ready(function() {
    $('.select2').select2({
        width: '100%',
        placeholder: 'Select an option',
        allowClear: true
    });
    
    $('.item-select').select2({
        width: '100%',
        placeholder: 'Select an article'
    });
    
    calculateTotals();
    
    $('#orderForm').on('submit', function(e) {
        e.preventDefault();
        
        if (!validateForm()) {
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            return;
        }
        
        $('#loadingSpinner').removeClass('d-none');
        $('#submitBtn').prop('disabled', true);
        
        $.ajax({
            url: '',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    }
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                const response = xhr.responseJSON;
                if (response && response.message) {
                    alert('Error: ' + response.message);
                } else {
                    alert('Server error occurred. Please try again.');
                }
                console.error('AJAX Error:', error);
            },
            complete: function() {
                $('#loadingSpinner').addClass('d-none');
                $('#submitBtn').prop('disabled', false);
            }
        });
    });
    
    $('input[name="v_date"]').on('change', function() {
        if ($(this).val()) {
            $(this).removeClass('is-invalid');
        }
    });
    
    $('.select2-customer').on('change', function() {
        if ($(this).val()) {
            $(this).removeClass('is-invalid');
        }
    });
});
</script>
</body>
</html>