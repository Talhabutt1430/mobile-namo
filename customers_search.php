<?php

session_start();
require_once('db.php');

if (!isset($_SESSION['cid'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$cid = $_SESSION['cid'];
$search_term = $_GET['q'] ?? '';

$sql = "SELECT id, name, mobile FROM customers WHERE cid = ?";
$params = [$cid];
$types = "i";

if (!empty($search_term)) {
    $sql .= " AND (name LIKE ? OR mobile LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$sql .= " ORDER BY name LIMIT 50";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

$stmt->execute();
$result = $stmt->get_result();
$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}

$stmt->close();

header('Content-Type: application/json');
echo json_encode($customers);

$conn->close();
?>