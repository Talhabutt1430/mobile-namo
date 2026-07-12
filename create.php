<?php
session_start();
require_once('db.php');

if (!isset($_SESSION['cid']) || empty($_SESSION['cid'])) {
    die("Company ID not found in session. Please log in.");
}

$cid = $_SESSION['cid'];
$name = $_SESSION['name'];

// Fetch customers and items
$customers = [];
$items = [];

try {
    $stmt = $conn->prepare("SELECT id, name FROM customers WHERE cid = ? ORDER BY name");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $customers_result = $stmt->get_result();
    while ($row = $customers_result->fetch_assoc()) {
        $customers[] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, item_name FROM item_masters WHERE cid = ? ORDER BY item_name");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $items_result = $stmt->get_result();
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, name, company_name FROM suppliers WHERE cid = ? ORDER BY name");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $suppliers_result = $stmt->get_result();
    $suppliers = [];
    while ($row = $suppliers_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT name FROM colors WHERE cid = ? ORDER BY name");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $colors_result = $stmt->get_result();
    $colors = [];
    while ($row = $colors_result->fetch_assoc()) {
        $colors[] = $row['name'];
    }
    $stmt->close();
} catch (Exception $e) {
    die("Error fetching data: " . $e->getMessage());
}

// Predefined cups
$cups = ['A', 'B', 'C', 'D', 'DD', 'E'];
$sizes_group1 = ['32', '34', '36', '38', '40', '42']; // Same rate
$sizes_group2 = ['44', '46', '48', '50']; // Same rate

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
        
        if (empty($items_data) || !is_array($items_data)) {
            throw new Exception("At least one item is required.");
        }

        $conn->begin_transaction();

        // Generate order number
        $stmt = $conn->prepare("SELECT MAX(order_no) as max_order_no FROM orders WHERE cid = ?");
        $stmt->bind_param("i", $cid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        $orderNo = ($row['max_order_no'] ?? 0) + 1;

        // Insert into orders
        $totalOrderQty = 0;
        $stmtOrder = $conn->prepare("INSERT INTO orders (order_no, cid, customer_id, supplier_id, v_date, total_qty, preparedby, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmtOrder->bind_param("iiiisiss", $orderNo, $cid, $customer_id, $supplier_id, $v_date, $totalOrderQty, $name, $order_status);
        
        if (!$stmtOrder->execute()) {
            throw new Exception("Failed to create order: " . $stmtOrder->error);
        }
        
        $orderId = $conn->insert_id;
        $stmtOrder->close();

        // Prepare statement for order_item_detail with all size fields
        $stmtDetail = $conn->prepare("INSERT INTO order_item_detail 
            (order_id, order_no, item_id, color, cup, size_32, size_34, size_36, size_38, size_40, size_42, 
             size_44, size_46, size_48, size_50, total_qty, item_status, cid) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($items_data as $itemData) {
            $item_id = filter_var($itemData['item_id'] ?? 0, FILTER_VALIDATE_INT);
            $rows = $itemData['rows'] ?? [];

            if (!$item_id || $item_id <= 0) {
                throw new Exception("Invalid item selected.");
            }
            
            if (empty($rows) || !is_array($rows)) {
                throw new Exception("Each item must have at least one row.");
            }

            // Process each row (color + cup + sizes)
            foreach ($rows as $row) {
                $color = htmlspecialchars($row['color'] ?? '', ENT_QUOTES);
                $cup = htmlspecialchars($row['cup'] ?? 'B', ENT_QUOTES);
                $item_status = $order_status;
                
                // Get quantities for each size
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
                    throw new Exception("At least one size quantity is required for each row.");
                }
                
                $stmtDetail->bind_param("iiissiiiiiiiiiiisi", 
                    $orderId, $orderNo, $item_id, $color, $cup,
                    $size_32, $size_34, $size_36, $size_38, $size_40, $size_42,
                    $size_44, $size_46, $size_48, $size_50, $row_total, $item_status, $cid
                );
                
                if (!$stmtDetail->execute()) {
                    throw new Exception("Failed to add item details: " . $stmtDetail->error);
                }
                
                $totalOrderQty += $row_total;
            }
        }

        // Update total quantity in orders
        $stmtUpdate = $conn->prepare("UPDATE orders SET total_qty = ? WHERE id = ? AND cid = ?");
        $stmtUpdate->bind_param("iii", $totalOrderQty, $orderId, $cid);
        
        if (!$stmtUpdate->execute()) {
            throw new Exception("Failed to update order total: " . $stmtUpdate->error);
        }

        $conn->commit();
        
        $stmtDetail->close();
        $stmtUpdate->close();

        echo json_encode([
            'success' => true,
            'message' => "Order #$orderNo created successfully!",
            'order_no' => $orderNo,
            'order_id' => $orderId,
            'redirect' => 'orders.php'
        ]);
        exit;

    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Error creating order: " . $e->getMessage()
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
    <title>Order Form - Hashmi Brothers</title>
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
                    <i class="bi bi-cart-plus-fill"></i> Create New Order - Hashmi Brothers
                </h3>
                <a href="orders.php" class="btn btn-light btn-sm mt-2 mt-md-0">
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
                                <option value="<?= htmlspecialchars($c['id']) ?>">
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a customer.</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Order Date</label>
                        <input type="date" name="v_date" class="form-control" 
                               value="<?= date('Y-m-d') ?>" 
                               max="<?= date('Y-m-d') ?>" required>
                        <div class="invalid-feedback">Please select a valid date.</div>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Status</label>
                        <select name="order_status" class="form-select">
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                            <option value="rejected">Rejected</option>
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
                    
                    <div id="items-container"></div>
                    
                    <button type="button" class="btn btn-add-item" onclick="addItem()">
                        <i class="bi bi-plus-circle-fill"></i> Add Article
                    </button>
                    
                    <div id="no-items-alert" class="alert alert-warning mt-3 d-none">
                        <i class="bi bi-exclamation-triangle-fill"></i> Please add at least one article to the order.
                    </div>
                </div>

                <!-- Grand Total -->
                <div class="grand-total-card mb-4">
                    <div class="text-center">
                        <h5 class="mb-3">Grand Total Quantity</h5>
                        <div class="grand-total-value" id="grand-total">0</div>
                        <small>Total pieces across all items</small>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                    <button type="button" class="btn btn-secondary px-4" onclick="resetForm()">
                        <i class="bi bi-arrow-clockwise"></i> Reset Form
                    </button>
                    <button type="submit" class="btn btn-success px-5" id="submitBtn">
                        <i class="bi bi-check-circle-fill"></i> Save Order
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
            <h5 class="text-dark">Processing Your Order...</h5>
            <p class="text-muted mb-0">Please wait</p>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Configuration
const colors = <?= json_encode($colors) ?>;
const cups = <?= json_encode($cups) ?>;
const sizesGroup1 = <?= json_encode($sizes_group1) ?>;
const sizesGroup2 = <?= json_encode($sizes_group2) ?>;
const itemsData = <?= json_encode($items) ?>;

let itemIndex = 0;
let rowCounters = {};
let totalItems = 0;

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
        placeholder: 'Select an article',
        dropdownAutoWidth: true,
        dropdownParent: $(document.body)
    });
    $('#no-items-alert').addClass('d-none');
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
    
    // Create size input fields
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
                    <strong>Sizes (32-42: Standard Rate | 44-50: Plus Size Rate)</strong>
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
        
        if (totalItems === 0) {
            $('#no-items-alert').removeClass('d-none');
        }
        
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

function resetForm() {
    if (confirm('Are you sure? All entered data will be lost.')) {
        $('#items-container').empty();
        itemIndex = 0;
        rowCounters = {};
        totalItems = 0;
        $('#orderForm')[0].reset();
        $('.select2').val(null).trigger('change');
        $('#grand-total').text('0');
        $('#no-items-alert').removeClass('d-none');
        updateSubmitButton();
    }
}

function validateForm() {
    let isValid = true;
    
    // Validate customer
    if (!$('.select2-customer').val()) {
        $('.select2-customer').addClass('is-invalid');
        isValid = false;
    }
    
    // Validate date
    if (!$('input[name="v_date"]').val()) {
        $('input[name="v_date"]').addClass('is-invalid');
        isValid = false;
    }
    
    // Validate items
    $('.item-card').each(function() {
        const idx = $(this).data('item');
        if (!validateItem(idx)) {
            isValid = false;
        }
        
        // Check if item has at least one row
        if ($(this).find('.row-detail').length === 0) {
            alert(`Article #${idx + 1} must have at least one row with color and sizes.`);
            isValid = false;
        }
        
        // Validate each row has color selected
        $(this).find('.row-detail').each(function() {
            const colorSelect = $(this).find('select[name*="[color]"]');
            if (!colorSelect.val()) {
                colorSelect.addClass('is-invalid');
                isValid = false;
            }
        });
    });
    
    if (totalItems === 0) {
        $('#no-items-alert').removeClass('d-none');
        isValid = false;
    }
    
    return isValid;
}

$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        placeholder: 'Select an option',
        allowClear: true,
        dropdownAutoWidth: true,
        dropdownParent: $(document.body)
    });
    
    // Handle form submission
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
                    } else {
                        resetForm();
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
    
    // Real-time validation
    $('input[name="v_date"]').on('change', function() {
        if ($(this).val()) {
            $(this).removeClass('is-invalid');
        }
    });
    
    $('.select2-customer').select2({
        placeholder: 'Search and select customer...',
        allowClear: true,
        minimumInputLength: 1,
        dropdownAutoWidth: true,
        dropdownParent: $(document.body),
        ajax: {
            url: 'customers_search.php',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term
                };
            },
            processResults: function (data) {
                return {
                    results: data.map(function(item) {
                        return {
                            id: item.id,
                            text: item.name + ' (' + item.mobile + ')'
                        };
                    })
                };
            },
            cache: true
        },
        escapeMarkup: function (markup) {
            return markup;
        }
    });
    
    // Load all customers on page load
    loadCustomers();
    
    function loadCustomers() {
        // This function is now kept for reference
        // The customer dropdown is populated via Select2 AJAX search
    }
    
    // Customer dropdown change handler
    $('.select2-customer').on('change', function() {
        if ($(this).val()) {
            $(this).removeClass('is-invalid');
        }
    });
    
    // Add first item on load
    addItem();
    addRow(0); // Add first row to first item
});
</script>
</body>
</html>