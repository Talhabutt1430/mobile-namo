<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('db.php'); // Your DB connection
// Set timeout and memory limits
set_time_limit(60);
ini_set('memory_limit', '256M');
session_start();

/* ===============================
   DATABASE CONNECTION
================================ */
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
    die("Database connection failed");
}
// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}


/* ===============================
   COMPANY SESSION
================================ */
$cid = $_SESSION['cid'] ?? 0;
if (!$cid) {
    die("Company not selected");
}

/* ===============================
   FILTER INPUTS
================================ */
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';
$status     = $_GET['status'] ?? '';
$v_no       = $_GET['v_no'] ?? '';
$account_id = $_GET['account_id'] ?? '';
$name= $_SESSION['name'] ;
/* ===============================
   MAIN QUERY (JOIN)
================================ */
$sql = "SELECT 
            t.date,
            t.v_no,
            t.credit,
            t.status,
            a.title AS account_name
        FROM trn_dtl t
        INNER JOIN account_masters a ON a.id = t.account_id
        WHERE t.v_type='CRV'
       AND t.cid = :cid AND t.preparedby = :preparedby";


$params = ['cid' => $cid,'preparedby'=>$name];

if ($start_date && $end_date) {
    $sql .= " AND t.date BETWEEN :start AND :end";
    $params['start'] = $start_date;
    $params['end']   = $end_date;
}

if ($status !== '') {
    $sql .= " AND t.status=:status";
    $params['status'] = $status;
}

if ($v_no) {
    $sql .= " AND t.v_no=:vno";
    $params['vno'] = $v_no;
}


$sql .= " ORDER BY t.date DESC, t.v_no DESC ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();
$Grandtotal=0;
/* ===============================
   DROPDOWNS
================================ */
$vNoList = $pdo->query("
    SELECT DISTINCT v_no FROM trn_dtl
    WHERE v_type='CRV' AND cid=$cid
")->fetchAll(PDO::FETCH_COLUMN);

$accountList = $pdo->prepare("
    SELECT id, title FROM account_masters
    WHERE cid=? ORDER BY title
");
$accountList->execute([$cid]);
$accountList = $accountList->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cash Receipt Report</title>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- ================= BOOTSTRAP CSS ================= -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f5f7fa;
        }
        .card-header {
            background: #0d6efd;
            color: #fff;
        }
        .table thead th {
            background-color: #e9ecef;
        }
    </style>
    <!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<!-- Optional: Select2 Bootstrap theme -->
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.5.2/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

</head>

<body>

<div class="container-fluid mt-4">

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Cash Receipt Voucher Report</h5>
        </div>

        <div class="card-body">

            <!-- ================= FILTER FORM ================= -->
            <form method="GET" class="row g-3 mb-3">

                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                </div>

            

                <div class="col-md-2">
                    <label class="form-label">Voucher No</label>
                    <select name="v_no" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($vNoList as $v): ?>
                            <option value="<?= $v ?>" <?= $v_no==$v?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

<div class="col-md-3">
    <label class="form-label">Account</label>
    <select name="account_id" class="form-select">
        <option value="">All</option>
        <?php foreach ($accountList as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $account_id==$a['id']?'selected':'' ?>>
                <?= htmlspecialchars($a['title']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>


                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Search</button>
                </div>

            </form>
            <a href='cashRecipt_create.php' class="btn btn-success" style='width:300px'>Create</a>
              <a href='orders.php' class="btn btn-success">Back to Main PAGE</a>
            <!-- ================= REPORT TABLE ================= -->
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Voucher #</th>
                            <th>Account</th>
                            <th class="text-end">credit</th>
                             <th class="text-end">Action</th>
                          
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($records): ?>
                            <?php foreach ($records as $row): ?>
                             <?php $Grandtotal+=$row['credit']?>
                                <tr>
                                    <td><?= $row['date'] ?></td>
                                    <td><?= $row['v_no'] ?></td>
                                    <td><?= htmlspecialchars($row['account_name']) ?></td>
                                    <td class="text-end"><?= number_format($row['credit'],2) ?></td>
                                    <td><a href="printCashRecipt.php?v_no=<?= $row['v_no'] ?>" target="_blank">Print</a></td>
                                   
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No records found</td>
                            </tr>
                        <?php endif; ?>
                        <tfoot>
                            <tr>
                                <td colspan="3" class='text-end'>Grand Total</td>
                                <td  class="text-end"><?= number_format($Grandtotal,2) ?></td>
                            </tr>
                        </tfoot>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<!-- ================= BOOTSTRAP JS ================= -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

</body>
</html>
