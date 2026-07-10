<?php
session_start();
require_once('db.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}

$cid = $_SESSION['cid'];

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_name'])) {
    $name = trim($_POST['item_name']);
    $barcode = trim($_POST['barcode'] ?? '');
    $sale_rate = trim($_POST['sale_rate'] ?? '');
    if ($name !== '') {
        $stmt = $conn->prepare("INSERT INTO item_masters (item_name, barcode, sale_rate, type_id, brand_id, cid) VALUES (?, ?, ?, 0, 0, ?)");
        $stmt->bind_param("sssi", $name, $barcode, $sale_rate, $cid);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: item_masters.php");
    exit();
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM item_masters WHERE id = ? AND cid = ?");
    $stmt->bind_param("ii", $id, $cid);
    $stmt->execute();
    $stmt->close();
    header("Location: item_masters.php");
    exit();
}

// Fetch items
$items = [];
$stmt = $conn->prepare("SELECT id, item_name, barcode, sale_rate FROM item_masters WHERE cid = ? ORDER BY item_name");
$stmt->bind_param("i", $cid);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Items</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-box-seam-fill"></i> Manage Items</h2>
        <div>
            <a href="colors.php" class="btn btn-outline-secondary">Colors</a>
            <a href="orders.php" class="btn btn-outline-secondary">Back to Orders</a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Add New Item</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Item Name</label>
                            <input type="text" name="item_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sale Rate</label>
                            <input type="text" name="sale_rate" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg"></i> Add Item</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Items (<?= count($items) ?>)</h5>
                    <?php if (empty($items)): ?>
                        <p class="text-muted">No items added yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Barcode</th>
                                        <th>Sale Rate</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $it): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['item_name']) ?></td>
                                        <td><?= htmlspecialchars($it['barcode'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($it['sale_rate'] ?: '-') ?></td>
                                        <td>
                                            <a href="?delete=<?= $it['id'] ?>" class="text-danger" onclick="return confirm('Delete item &quot;<?= htmlspecialchars($it['item_name']) ?>&quot;?')">
                                                <i class="bi bi-x-circle-fill"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
