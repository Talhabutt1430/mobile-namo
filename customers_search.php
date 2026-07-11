<?php

require_once('db.php');

$search_term = $_GET['q'] ?? '';

$sql = "SELECT id, name, mobile FROM customers";
$params = [];
$types = "";

if (!empty($search_term)) {
    $sql .= " WHERE (name LIKE ? OR mobile LIKE ?)";
    $search_param = "%$search_term%";
    $params = [$search_param, $search_param];
    $types = "ss";
}

$sql .= " ORDER BY name LIMIT 50";

$stmt = $conn->prepare($sql);

if (!empty($search_term) && $params) {
    $stmt->bind_param($types, ...$params);
}

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