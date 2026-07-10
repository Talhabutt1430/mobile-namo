<?php
session_start();

/* ===============================
   DATABASE CONNECTION
================================ */
$pdo = new PDO(
    "mysql:host=localhost;dbname=realerp_nano;charset=utf8mb4",
    "realerp_probox",
    "S@ftix786",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

/* ===============================
   SESSION / COMPANY
================================ */
$cid = $_SESSION['cid'] ?? '';
if (!$cid) die("Company not selected");

/* ===============================
   GET VOUCHER NO
================================ */
$v_no = $_GET['v_no'] ?? '';
if (!$v_no) die("Voucher number missing");

/* ===============================
   COMPANY INFO
================================ */
$company = $pdo->prepare("SELECT * FROM workspace WHERE cid=? LIMIT 1");
$company->execute([$cid]);
$company = $company->fetch();

/* ===============================
   VOUCHER DETAILS
================================ */
$stmt = $pdo->prepare("
    SELECT t.*, a.title AS account_title
    FROM trn_dtl t
    LEFT JOIN account_masters a ON a.id = t.account_id
    WHERE t.v_no=? AND t.cid=? AND t.v_type='CRV'
    ORDER BY t.id
");
$stmt->execute([$v_no, $cid]);
$rows = $stmt->fetchAll();
if (!$rows) die("Voucher not found");

$first = $rows[0];

/* ===============================
   CUSTOMER
================================ */
$customer = $pdo->prepare("
    SELECT name, mobile, address
    FROM customers
    WHERE acc_id=? AND cid=?
    LIMIT 1
");
$customer->execute([$first['account_id'], $cid]);
$customer = $customer->fetch();

/* ===============================
   CALCULATIONS
================================ */
$received = (float)($first['credit'] ?? 0);
$previous = (float)($first['pre_bal'] ?? 0);
$balance  = max($previous - $received, 0);

/* ===============================
   LOGO PATH
================================ */
$logoPath = "logo.jpeg"; // Change this to your actual logo path
$hasLogo = file_exists($logoPath);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cash Receipt - 58mm</title>
<style>
/* 58mm Thermal Printer Styles - 32px Fonts */
@page {
    size: 58mm auto;
    margin: 0;
    padding: 0;
}
/* GLOBAL +2px FONT SIZE OVERRIDE */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: Arial, sans-serif;
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
    print-color-adjust: exact !important;
    color: #000000 !important; /* Force black text */
    font-weight: bold !important; /* Force bold for darkness */
    font-size: calc(1em + 2px) !important;
}


body {
    width: 100%;
    margin: 0 auto;
    padding: 1mm;
    background: white !important;
    -webkit-print-color-adjust: exact;
    color: #000000;
    text-shadow: none;
    filter: none;
}

/* Force all text to be dark/black */
body, div, span, td, th, p, h1, h2, h3, h4, h5, h6 {
    color: #000000 !important;
    text-shadow: 0 0 0 #000000 !important;
    -webkit-text-stroke: 0.3px #000000 !important; /* Make text darker */
    font-weight: 900 !important; /* Maximum boldness */
}

/* Main container */
.container {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

/* PRINT BUTTON - Large and Visible */
.print-button-container {
    text-align: center;
    margin: 5mm 0 10mm 0;
    padding: 2mm;
    background: linear-gradient(to bottom, #f8f8f8, #e0e0e0);
    border: 3px solid #000;
    border-radius: 10px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.3);
}

.print-button {
    background: linear-gradient(to bottom, #007bff, #0056b3) !important;
    color: white !important;
    border: none !important;
    border-radius: 8px !important;
    padding: 8mm 5mm !important;
    font-size: 34px !important;
    font-weight: 900 !important;
    cursor: pointer !important;
    width: 90% !important;
    margin: 0 auto !important;
    display: block !important;
    text-transform: uppercase !important;
    letter-spacing: 1px !important;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.5) !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.4) !important;
    transition: all 0.3s ease !important;
    -webkit-text-stroke: 0px !important;
    border: 2px solid #000 !important;
}

.print-button:hover {
    background: linear-gradient(to bottom, #0056b3, #004085) !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 8px rgba(0,0,0,0.5) !important;
}

.print-button:active {
    transform: translateY(1px) !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.3) !important;
}

.print-instruction {
    font-size: 26px !important;
    font-weight: 900 !important;
    margin-top: 3mm;
    color: #000 !important;
    -webkit-text-stroke: 0.3px #000 !important;
}

/* Hide button when printing */
@media print {
    .print-button-container,
    .print-button,
    .print-instruction {
        display: none !important;
    }
    body, div, span, td, th, p, h1, h2, h3, h4, h5, h6 {
    color: #000000 !important;
    text-shadow: 0 0 0 #000000 !important;
    -webkit-text-stroke: 0.3px #000000 !important; /* Make text darker */
    font-weight: 900 !important; /* Maximum boldness */
}

}

/* LOGO */
.logo-container {
    text-align: center;
    margin: 2mm 0 3mm 0;
    padding: 1mm;
}

.company-logo {
    max-width: 100% !important;
    max-height: 200px !important;
    height: auto;
    display: block;
    margin: 0 auto;
}

/* HEADER - 32px */
.header {
    text-align: center;
    margin-bottom: 4mm;
    width: 100%;
}

.company-name {
    font-size: 34px !important;
    font-weight: 900 !important;
    text-transform: uppercase;
    margin-bottom: 2mm;
    line-height: 1.1;
    letter-spacing: -0.5px;
    -webkit-text-stroke: 0.5px #000 !important;
    color: #000000 !important;
}

.company-address {
    font-size: 30px !important;
    font-weight: 900 !important;
    margin-bottom: 3mm;
    line-height: 1.1;
    -webkit-text-stroke: 0.4px #000 !important;
    color: #000000 !important;
}

/* TITLE - 32px */
.receipt-title {
    font-size: 34px !important;
    font-weight: 900 !important;
    text-transform: uppercase;
    text-align: center;
    margin: 3mm 0;
    padding: 1mm 0;
    -webkit-text-stroke: 0.6px #000 !important;
    color: #000000 !important;
    border-top: 2px solid #000;
    border-bottom: 2px solid #000;
}

/* DIVIDER */
.divider {
    border-top: 3px solid #000000 !important;
    margin: 4mm 0;
    clear: both;
    height: 0;
}

/* VOUCHER INFO - 32px */
.voucher-info {
    margin-bottom: 4mm;
    width: 100%;
}

.voucher-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 3mm;
    font-size: 34px !important;
    font-weight: 900 !important;
    -webkit-text-stroke: 0.4px #000 !important;
    color: #000000 !important;
}

.voucher-label {
    font-size: 34px !important;
    font-weight: 900 !important;
    -webkit-text-stroke: 0.4px #000 !important;
}

.voucher-value {
    font-size: 34px !important;
    font-weight: 900 !important;
    text-align: right;
    -webkit-text-stroke: 0.4px #000 !important;
}

/* CUSTOMER SECTION - 32px */
.customer-section {
    margin: 4mm 0;
    width: 100%;
}

.section-title {
    font-size: 34px !important;
    font-weight: 900 !important;
    text-align: center;
    margin-bottom: 3mm;
    text-decoration: underline;
    -webkit-text-stroke: 0.5px #000 !important;
    color: #000000 !important;
}

.customer-details {
    font-size: 34px !important;
}

.customer-row {
    display: flex;
    margin-bottom: 3mm;
    align-items: flex-start;
    font-size: 34px !important;
    font-weight: 900 !important;
    -webkit-text-stroke: 0.4px #000 !important;
    color: #000000 !important;
}

.customer-label {
    min-width: 25mm;
    font-weight: 900 !important;
    font-size: 34x !important;
    -webkit-text-stroke: 0.4px #000 !important;
}

.customer-value {
    flex: 1;
    font-size: 34px !important;
    font-weight: 900 !important;
    text-align: left;
    word-break: break-all;
    -webkit-text-stroke: 0.4px #000 !important;
}

/* AMOUNTS TABLE - 32px */
.amounts-table {
    width: 100%;
    margin: 5mm 0;
}

.amount-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 3mm 0;
    font-size: 32px !important;
    font-weight: 900 !important;
    -webkit-text-stroke: 0.4px #000 !important;
    color: #000000 !important;
}

.amount-row.total {
    border-top: 2px solid #000 !important;
    border-bottom: 2px solid #000 !important;
}

.amount-row.grand {
    font-size: 38px !important; /* Slightly larger for grand total */
    font-weight: 900 !important;
    border-top: 3px solid #000 !important;
    border-bottom: 3px solid #000 !important;
    margin-top: 2mm;
    -webkit-text-stroke: 0.6px #000 !important;
}

.amount-label {
    text-transform: uppercase;
    font-size: 34px !important;
    font-weight: 900 !important;
    -webkit-text-stroke: 0.4px #000 !important;
}

.amount-value {
    font-size: 32px !important;
    font-weight: 900 !important;
    -webkit-text-stroke: 0.4px #000 !important;
}

/* FOOTER - 32px */
.footer {
    margin-top: 5mm;
    text-align: center;
    width: 100%;
}

.thankyou {
    font-size: 34px !important;
    font-weight: 900 !important;
    margin: 3mm 0;
    text-transform: uppercase;
    -webkit-text-stroke: 0.5px #000 !important;
    color: #000000 !important;
}

.print-time {
    font-size: 30px !important; /* Slightly smaller */
    font-weight: 900 !important;
    margin-bottom: 3mm;
    -webkit-text-stroke: 0.4px #000 !important;
    color: #000000 !important;
}

.prepared-by {
    font-size: 30px !important;
    font-weight: 900 !important;
    border-top: 2px dashed #000 !important;
    padding-top: 3mm;
    margin-top: 3mm;
    -webkit-text-stroke: 0.4px #000 !important;
    color: #000000 !important;
}

/* PRINT SPECIFIC STYLES */
@media print {
    @page {
        size: 58mm auto;
        margin: 0;
        padding: 0;
    }
    
    body {
        padding: 1mm !important;
        background: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Force all elements to show */
    .container, .header, .voucher-info, .customer-section, .amounts-table, .footer {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    
    /* Ensure text is dark/black */
    * {
        color: #000000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Prevent text shrinkage */
    .company-name,
    .receipt-title,
    .voucher-row,
    .customer-row,
    .amount-row {
        font-size: 34px !important;
        -webkit-text-size-adjust: 100% !important;
        text-size-adjust: 100% !important;
    }
}

/* For very small width - force scaling */
@media (max-width: 58mm) {
    .container {
        transform: scale(0.95);
        transform-origin: top left;
        width: 58mm;
    }
}

/* Make sure logo prints dark */
@media print {
    .company-logo {
           max-width: 100% !important;
    max-height: 200px !important;
    height: auto;
    display: block;
    margin: 0 auto;
    }
}
</style>
</head>

<body>

<div class="container">

<!-- LARGE PRINT BUTTON -->
<div class="print-button-container">
    <button class="print-button" onclick="window.print()">
        🖨️ PRINT RECEIPT
    </button>
    <div class="print-instruction">
        Click to print on 58mm thermal paper
    </div>
</div>

<!-- LOGO -->
<?php if ($hasLogo): ?>
<div class="logo-container">
    <img src="<?= $logoPath ?>" alt="Company Logo" class="company-logo">
</div>
<?php endif; ?>

<!-- COMPANY HEADER -->


<!-- RECEIPT TITLE -->
<div class="receipt-title bold">
    CASH RECEIPT
</div>

<div class="divider"></div>

<!-- VOUCHER INFO -->
<div class="voucher-info">
    <div class="voucher-row">
        <span class="voucher-label bold">Voucher No: <?= htmlspecialchars($first['v_no']) ?></span>
       
    </div>
    <div class="voucher-row">
        <span class="voucher-label bold">Date: <?= date('d-m-Y', strtotime($first['date'])) ?></span>
    </div>
</div>

<div class="divider"></div>
<?php
$name = $customer['name'] ?? 'N/A';

/* Remove (B<number>) like (B1), (B21), (B101) */
$name = preg_replace('/\s*\(B\d+\)/', '', $name);

/* Escape for HTML */
$name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
?>

<!-- CUSTOMER DETAILS -->
<div class="customer-section">
    <div class="section-title bold">CUSTOMER DETAILS</div>
    
    <div class="customer-details">
        <div class="customer-row">
            <span class="customer-label bold">Name:</span>
            <span class="customer-value bold">
  
                 <?= $name ?>
                
                </span>
        </div>
        <div class="customer-row">
            <span class="customer-label bold">Mobile:</span>
            <span class="customer-value bold"><?= htmlspecialchars($customer['mobile'] ?? '') ?></span>
        </div>
        <div class="customer-row">
            <span class="customer-label bold">Address:</span>
            <span class="customer-value bold"><?= htmlspecialchars($customer['address'] ?? '') ?></span>
        </div>
    </div>
</div>

<div class="divider"></div>

<!-- AMOUNTS -->
<div class="amounts-table">
    <div class="amount-row">
        <span class="amount-label bold">Previous:</span>
        <span class="amount-value bold"><?= number_format($previous, 2) ?></span>
    </div>
    <div class="amount-row">
        <span class="amount-label bold">Received:</span>
        <span class="amount-value bold"><?= number_format($received, 2) ?></span>
    </div>
    <div class="amount-row grand">
        <span class="amount-label bold">Balance:</span>
        <span class="amount-value bold"><?= number_format($balance, 2) ?></span>
    </div>
</div>

<div class="divider"></div>

<!-- FOOTER -->
<div class="footer">
    <div class="thankyou bold">
        THANK YOU
    </div>
    <div class="print-time bold">
        Printed: <?= date('d-m-Y H:i') ?>
    </div>
    <div class="prepared-by bold">
        Prepared By: <?= htmlspecialchars($first['preparedby'] ?? 'N/A') ?>
    </div>
</div>

</div>

<script>
// Force dark printing
document.addEventListener('DOMContentLoaded', function() {
    // Add CSS to force dark text
    var style = document.createElement('style');
    style.innerHTML = `
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
                print-color-adjust: exact !important;
                color: #000000 !important;
                text-shadow: 0 0 1px #000000 !important;
            }
        }
    `;
    document.head.appendChild(style);
    
    // Add keyboard shortcut (Ctrl+P)
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
    });
    
    // Auto-print option (uncomment if needed)
    // setTimeout(function() {
    //     if(confirm("Print receipt now?")) {
    //         window.print();
    //     }
    // }, 500);
});

// Add cut mark for thermal printer
window.onbeforeprint = function() {
    var cutMark = document.createElement('div');
    cutMark.style.textAlign = 'center';
    cutMark.style.marginTop = '20mm';
    cutMark.style.fontSize = '32px';
    cutMark.style.fontWeight = '900';
    cutMark.innerHTML = '══════════════════════════════════════';
    cutMark.style.display = 'none';
    document.body.appendChild(cutMark);
};

// Print button click animation
document.querySelector('.print-button').addEventListener('click', function(e) {
    var btn = e.target;
    btn.innerHTML = '🖨️ PRINTING...';
    btn.style.background = 'linear-gradient(to bottom, #0056b3, #004085) !important';
    
    setTimeout(function() {
        window.print();
        setTimeout(function() {
            btn.innerHTML = '🖨️ PRINT RECEIPT';
            btn.style.background = 'linear-gradient(to bottom, #007bff, #0056b3) !important';
        }, 1000);
    }, 500);
});
</script>

</body>
</html>