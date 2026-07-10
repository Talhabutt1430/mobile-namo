<?php
session_start();

if (!isset($_SESSION['cid'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$cid = $_SESSION['cid'];

$host = "localhost";
$user = "realerp_probox";
$pass = "S@ftix786";
$db   = "realerp_nano";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB error']);
    exit;
}

$customer_id = $_GET['customer_id'] ?? 0;

if (!$customer_id) {
    echo json_encode(['balance' => 0]);
    exit;
}

/* 🔹 Get acc_id from customer */
$stmt = $pdo->prepare("
    SELECT acc_id 
    FROM customers 
    WHERE id = :id AND cid = :cid
");
$stmt->execute([
    'id'  => $customer_id,
    'cid' => $cid
]);
$acc_id = $stmt->fetchColumn();

if (!$acc_id) {
    echo json_encode(['balance' => 0]);
    exit;
}

/* 🔹 Calculate closing balance */
$stmt = $pdo->prepare("
    SELECT 
        IFNULL(SUM(debit),0) - IFNULL(SUM(credit),0) AS balance
    FROM trn_dtl
    WHERE cid = :cid 
      AND account_id = :acc_id
");
$stmt->execute([
    'cid'    => $cid,
    'acc_id' => $acc_id
]);

$balance = $stmt->fetchColumn();

echo json_encode([
    'balance' => round($balance, 2)
]);
