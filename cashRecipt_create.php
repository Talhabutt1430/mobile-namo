<?php
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
    die("Database connection failed: " . $e->getMessage());
}

/* ===============================
   SESSION CHECK
================================ */
if (!isset($_SESSION['user_id']) || !isset($_SESSION['cid'])) {
    header("Location: login.php");
    exit();
}
$cid = $_SESSION['cid'] ?? 0;
if (!$cid) die("Company not selected");

/* ===============================
   FETCH CUSTOMERS
================================ */
$stmt = $pdo->prepare("SELECT id, name FROM customers WHERE cid=? ORDER BY name");
$stmt->execute([$cid]);
$accounts = $stmt->fetchAll();

/* ===============================
   NEXT VOUCHER NO
================================ */
$stmt = $pdo->prepare("SELECT IFNULL(MAX(v_no),0)+1 FROM trn_dtl WHERE cid=?");
$stmt->execute([$cid]);
$nextVno = $stmt->fetchColumn();

/* ===============================
   SAVE FORM
================================ */
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date        = $_POST['date'] ?? '';
    $customer_id = $_POST['account_id'] ?? '';
    $amount      = $_POST['amount'] ?? 0;
    $remarks     = $_POST['remarks'] ?? '';
    $receivedby     = $_POST['receivedby'] ?? '';

    if ($date && $customer_id && $amount > 0) {

        // Get account_id from customer
        $stmt = $pdo->prepare("SELECT acc_id FROM customers WHERE id=:cust_id AND cid=:cid");
        $stmt->execute(['cust_id'=>$customer_id,'cid'=>$cid]);
        $aid = $stmt->fetchColumn();
         $stmtc = $pdo->prepare("SELECT cash_acc FROM erp_params WHERE cid=:cid");
        $stmtc->execute(['cid'=>$cid]);
        $cashid = $stmtc->fetchColumn();
        
        
        
        $stmtclos = $pdo->prepare("
    SELECT 
        SUM(debit) AS total_debit, 
        SUM(credit) AS total_credit
    FROM trn_dtl
    WHERE cid = :cid 
    and account_id=:account_id
      AND date <= :toDate
");
$stmtclos->execute([
    'cid' => $cid,
        'account_id' => $aid,
    'toDate' => $date,
    
]);
$result = $stmtclos->fetch();
$totalDebit  = $result['total_debit'] ?? 0;
$totalCredit = $result['total_credit'] ?? 0;

$closingBalance = $totalDebit - $totalCredit;






        if (!$aid) die("Account mapping not found");

        // Insert voucher
        $insert = $pdo->prepare("
            INSERT INTO trn_dtl
            (date, v_type, v_no, account_id, credit, description, cid, preparedby,cash_id,pre_bal)
            VALUES
            (:date, 'CRV', :vno, :acc, :amt, :remarks, :cid, :preparedby,:cashid,:closingBalance)
        ");
        $insert->execute([
            'date'    => $date,
            'vno'     => $nextVno,
            'acc'     => $aid,
            'amt'     => $amount,
            'remarks' => $remarks,
            'cashid' => $cashid,
            'cid'     => $cid,
            'preparedby' => $_SESSION['name'] ?? 'Admin',
            'closingBalance'=>$closingBalance,
        ]);

        $success = "Voucher #$nextVno created successfully!";
     
        // Redirect based on button clicked
if ($_POST['action'] === 'print') {
    header("Location: printCashRecipt.php?v_no=" . $nextVno);
    exit;
}

if ($_POST['action'] === 'exit') {
    header("Location: cashRecipt_index.php"); // main page
    exit;
}

    } else {
        $error = "Please fill all required fields";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create Cash Receipt Voucher</title>
<!-- jQuery CDN -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Select2 CSS -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-5-theme/1.3.0/select2-bootstrap-5-theme.min.css" />

<style>
body { 
    background: #f5f7fa; 
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.card-header { 
    background: linear-gradient(135deg, #198754 0%, #157347 100%); 
    color: #fff; 
    font-weight: 600;
}
.container-fluid {
    max-width: 800px;
}
.form-label {
    font-weight: 500;
    color: #495057;
}
.select2-container--bootstrap-5 .select2-selection {
    min-height: 42px;
    display: flex;
    align-items: center;
}
.select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
    line-height: 1.5;
    padding-left: 0.5rem;
}
.select2-container--bootstrap-5 .select2-dropdown {
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
}
.select2-container--bootstrap-5 .select2-selection--single {
    height: 42px;
}
.select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow {
    height: 40px;
}
.form-control:focus, .select2-container--bootstrap-5.select2-container--focus .select2-selection {
    border-color: #198754;
    box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
}
.alert {
    border-radius: 8px;
}
</style>
</head>
<body>
    <!-- Loader Overlay -->
<div id="loaderOverlay" style="
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(255,255,255,0.7);
    z-index: 1050;
    justify-content: center;
    align-items: center;
">
    <div class="spinner-border text-success" role="status" style="width: 4rem; height: 4rem;">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>


<div class="container-fluid mt-4 px-3">

    <div class="card shadow-lg border-0">
        <div class="card-header py-3">
            <h5 class="mb-0 d-flex align-items-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-cash-stack me-2" viewBox="0 0 16 16">
                    <path d="M1 3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1H1zm7 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/>
                    <path d="M0 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1V5zm3 0a2 2 0 0 1-2 2v4a2 2 0 0 1 2 2h10a2 2 0 0 1 2-2V7a2 2 0 0 1-2-2H3z"/>
                </svg>
                Create Cash Receipt Voucher (CRV)
            </h5>
        </div>

        <div class="card-body p-4">

            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" id="voucherForm" class="needs-validation" novalidate>

                <div class="row g-3">
                    <!-- Voucher Number -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Voucher Number</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-123" viewBox="0 0 16 16">
                                    <path d="M4.5 5.5A.5.5 0 0 1 5 5h2a.5.5 0 0 1 0 1H5.5v1h1a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H5V6h-1a.5.5 0 0 1-.5-.5z"/>
                                    <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H2z"/>
                                </svg>
                            </span>
                            <input type="text" class="form-control fw-bold" value="CRV-<?= $nextVno ?>" readonly style="background-color: #e9ecef;">
                        </div>
                    </div>

                    <!-- Date -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-calendar-date" viewBox="0 0 16 16">
                                    <path d="M6.445 11.688V6.354h-.633A12.6 12.6 0 0 0 4.5 7.16v.695c.375-.257.969-.62 1.258-.777h.012v4.61h.675zm1.188-1.305c.047.64.594 1.406 1.703 1.406 1.258 0 2-.829 2-1.875 0-1.094-.781-1.562-1.578-1.562-.688 0-1.203.406-1.406.969h-.094l-.281-1.531h.937V7.89h-.625c-.094 0-.156.016-.219.031-.063.016-.11.047-.141.078l-.219.203h-.562l.125-1.484h1.75v-.875c0-.219-.141-.359-.375-.359-.219 0-.375.125-.375.359v.875h-1.531v.875h.969c.156 0 .234.094.234.188v.562c0 .125-.078.219-.234.219h-.75v1.141c0 .125.078.219.172.219h.578l-.109.703z"/>
                                    <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/>
                                </svg>
                            </span>
                            <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>" readonly>
                            <div class="invalid-feedback">Please select a date</div>
                        </div>
                    </div>

                    <!-- Customer Selection -->
                    <div class="col-12">
                        <label class="form-label fw-bold">Customer <span class="text-danger">*</span></label>
                        <select name="account_id" id="customerSelect" class="form-select" required style="width: 100%;">
                            <option value="">Select Customer...</option>
                            <?php foreach($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a customer</div>
                    </div>
<div class="col-md-8">
    <label class="form-label fw-bold">Current Balance</label>
    <input type="text" id="currentBalance" class="form-control fw-bold text-end" readonly>
</div>

                    <!-- Amount -->
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">RS</span>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                            <div class="invalid-feedback">Please enter a valid amount</div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Received By <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text"name="receivedby" class="form-control"  required value="<?= $_SESSION['name']?>" readonly>
                            <div class="invalid-feedback">Please enter a Received Name</div>
                        </div>
                    </div>

                    <!-- Payment Mode (Optional) -->
                   

                    <!-- Remarks -->
                    <div class="col-12">
                        <label class="form-label fw-bold">Description / Remarks</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chat-left-text" viewBox="0 0 16 16">
                                    <path d="M14 1a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H4.414A2 2 0 0 0 3 11.586l-2 2V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12.793a.5.5 0 0 0 .854.353l2.853-2.853A1 1 0 0 1 4.414 12H14a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
                                    <path d="M3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5zM3 6a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9A.5.5 0 0 1 3 6zm0 2.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/>
                                </svg>
                            </span>
                            <textarea name="remarks" class="form-control" rows="2" placeholder="Enter remarks or description..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <a href="cashRecipt_index.php" class="btn btn-secondary px-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left me-2" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                        </svg>
                        Back to List
                    </a>
                    <button type="submit" name="action" value="print" class="btn btn-success">
    💾 Save & Print
</button>

<button type="submit" name="action" value="exit" class="btn btn-secondary">
    💾 Save & Exit
</button>

                </div>

            </form>

        </div>
    </div>

</div>

<!-- JavaScript Libraries -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('#customerSelect').select2({
        theme: 'bootstrap-5',
        placeholder: 'Select Customer...',
        width: '100%',
        allowClear: true,
        dropdownParent: $('#customerSelect').parent(),
        minimumResultsForSearch: 5,
        templateResult: function(data) {
            if (!data.id) {
                return data.text;
            }
            var $result = $(
                '<div class="select2-result-repository clearfix">' +
                '<div class="select2-result-repository__title">' + data.text + '</div>' +
                '</div>'
            );
            return $result;
        },
        templateSelection: function(data) {
            return data.text;
        }
    });
    
    // Fix Select2 dropdown positioning
    $(document).on('select2:open', () => {
        document.querySelector('.select2-container--open .select2-search__field').focus();
    });
    
    // Form validation
    (function () {
        'use strict'
        var forms = document.querySelectorAll('.needs-validation')
        Array.prototype.slice.call(forms)
            .forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
    })();
    
    // Set today's date if empty
    if (!$('input[name="date"]').val()) {
        $('input[name="date"]').val(new Date().toISOString().split('T')[0]);
    }
    
    // Auto-focus on amount field
    $('input[name="amount"]').focus();
    
    // Format amount on blur
    $('input[name="amount"]').on('blur', function() {
        var value = parseFloat($(this).val());
        if (!isNaN(value)) {
            $(this).val(value.toFixed(2));
        }
    });
});
$('#customerSelect').on('change', function () {
    let customerId = $(this).val();

    if (!customerId) {
        $('#currentBalance').val('');
        return;
    }

    $.ajax({
        url: 'get_customer_balance.php',
        type: 'GET',
        data: { customer_id: customerId },
        dataType: 'json',
        success: function (res) {
            if (res.balance !== undefined) {
                let bal = parseFloat(res.balance).toFixed(2);
                $('#currentBalance').val(bal);
            } else {
                $('#currentBalance').val('0.00');
            }
        },
        error: function () {
            $('#currentBalance').val('0.00');
        }
    });
});
$('#voucherForm').on('submit', function() {
    if (this.checkValidity()) {
        // Show loader
        $('#loaderOverlay').css('display', 'flex');
    }
});

</script>




</body>
</html>