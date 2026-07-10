<?php
session_start();
require_once('db.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}

$cid = $_SESSION['cid'];

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['color_name'])) {
    $name = trim($_POST['color_name']);
    if ($name !== '') {
        $stmt = $conn->prepare("INSERT IGNORE INTO colors (name, cid) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $cid);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            $error = "Color '$name' already exists.";
        }
        $stmt->close();
    }
    header("Location: colors.php");
    exit();
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM colors WHERE id = ? AND cid = ?");
    $stmt->bind_param("ii", $id, $cid);
    $stmt->execute();
    $stmt->close();
    header("Location: colors.php");
    exit();
}

// Fetch colors
$colors = [];
$stmt = $conn->prepare("SELECT id, name FROM colors WHERE cid = ? ORDER BY id");
$stmt->bind_param("i", $cid);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $colors[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Colors</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-palette-fill"></i> Manage Colors</h2>
        <a href="orders.php" class="btn btn-outline-secondary">Back to Orders</a>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Add New Color</h5>
                    <form method="POST">
                        <div class="input-group">
                            <input type="text" name="color_name" class="form-control" placeholder="Enter color name" required>
                            <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg"></i> Add</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Saved Colors (<?= count($colors) ?>)</h5>
                    <?php if (empty($colors)): ?>
                        <p class="text-muted">No colors added yet.</p>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($colors as $c): ?>
                            <div class="col-6 col-md-4 mb-2">
                                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2">
                                    <span><?= htmlspecialchars($c['name']) ?></span>
                                    <a href="?delete=<?= $c['id'] ?>" class="text-danger" onclick="return confirm('Delete this color?')">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
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
