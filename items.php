<?php
session_start();
require_once('db.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}

$cid = $_SESSION['cid'];
$name = $_SESSION['name'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $item_name = trim($_POST['item_name'] ?? '');
    if ($item_name) {
        $stmt = $conn->prepare("INSERT INTO item_masters (item_name, cid) VALUES (?, ?)");
        $stmt->bind_param("si", $item_name, $cid);
        if ($stmt->execute()) {
            $success = "Item added successfully!";
        } else {
            $error = "Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = "Item name is required.";
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM item_masters WHERE id = ? AND cid = ?");
    $stmt->bind_param("ii", $id, $cid);
    $stmt->execute();
    $stmt->close();
    header("Location: items.php");
    exit();
}

$items = [];
$stmt = $conn->prepare("SELECT id, item_name FROM item_masters WHERE cid = ? ORDER BY item_name");
$stmt->bind_param("i", $cid);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();
?>
<?php
$user_role = $_SESSION['role'] ?? 'employee';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Items</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .card { border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .btn-custom { border-radius: 8px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-box-seam-fill"></i> Manage Items</h2>
        <div>
            <a href="orders.php" class="btn btn-outline-secondary btn-sm me-2">
                <i class="bi bi-arrow-left"></i> Back to Orders
            </a>
            <?php if (in_array($user_role, ['admin', 'warehouse'])): ?>
            <a href="warehouse.php" class="btn btn-outline-secondary btn-sm me-2">
                <i class="bi bi-house-gear-fill"></i> Warehouse
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-plus-circle-fill"></i> Add New Item</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Item Name</label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g. T-Shirt, Jeans, Shirt" required>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" name="add_item" class="btn btn-primary w-100 btn-custom">
                        <i class="bi bi-plus-lg"></i> Add Item
                    </button>
                </div>
            </form>
            <?php if ($success): ?>
                <div class="alert alert-success mt-3"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Existing Items</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Item Name</th>
                            <th class="text-center" style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No items added yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $i => $item): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                                    <td class="text-center">
                                        <a href="items.php?delete=<?= $item['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this item?')">
                                            <i class="bi bi-trash-fill"></i>
                                        </a>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>