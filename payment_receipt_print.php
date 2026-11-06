<?php
// payment_receipt_print.php - Printable Customer Payment Receipt (Vasooli Rasid)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$payment_id = intval($_GET['id'] ?? 0);
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

if ($payment_id <= 0 || !isset($pdo)) {
    die("Invalid Payment ID or Database connection failed.");
}

// Fetch payment details
$stmt = $pdo->prepare("SELECT * FROM customer_payments WHERE id = ?");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment record not found.");
}

// Calculate customer's remaining due balance
$due_stmt = $pdo->prepare("SELECT COALESCE(SUM(due_amount), 0) as remaining_due FROM sales WHERE customer_name = ?");
$due_stmt->execute([$payment['customer_name']]);
$remaining_due = (float)$due_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?= htmlspecialchars($payment['receipt_no']) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        body {
            background-color: #f1f5f9;
            color: #0f172a;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 30px 0;
        }

        .receipt-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            max-width: 600px;
            margin: 0 auto;
            padding: 35px;
            border: 1px solid #e2e8f0;
        }

        .receipt-header {
            border-bottom: 2px dashed #cbd5e1;
            padding-bottom: 15px;
            margin-bottom: 20px;
            text-align: center;
        }

        .store-title {
            font-weight: 800;
            letter-spacing: 1px;
            font-size: 1.6rem;
            color: #0f172a;
        }

        .amount-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 2px solid #86efac;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }

        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .receipt-card {
                box-shadow: none !important;
                border: none !important;
                max-width: 100% !important;
                padding: 15px !important;
            }
        }
    </style>
</head>
<body>

    <!-- Action Bar -->
    <div class="container mb-4 no-print" style="max-width: 600px;">
        <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm border">
            <a href="customers_khata.php" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Khata
            </a>
            <button onclick="window.print()" class="btn btn-success fw-bold px-4">
                <i class="fa-solid fa-print me-2"></i> Print Receipt
            </button>
        </div>
    </div>

    <!-- Receipt Card -->
    <div class="receipt-card">
        
        <!-- Header -->
        <div class="receipt-header">
            <div class="store-title">IRON STORE & HARDWARE</div>
            <div class="text-muted small">Quality Steel, Girders, Gates, Saria & Iron Pipes</div>
            <div class="text-muted small"><i class="fa-solid fa-phone me-1"></i> Phone: 0300-1234567</div>
            <div class="badge bg-success fs-6 px-3 py-1 mt-2">OFFICIAL PAYMENT RECEIPT (VASOOLI RASID)</div>
        </div>

        <!-- Meta -->
        <div class="d-flex justify-content-between small text-muted mb-3">
            <div><strong>Receipt No:</strong> <span class="font-monospace text-dark fw-bold"><?= htmlspecialchars($payment['receipt_no']) ?></span></div>
            <div><strong>Date:</strong> <?= date('d-M-Y h:i A', strtotime($payment['created_at'])) ?></div>
        </div>

        <!-- Customer Info -->
        <div class="p-3 bg-light rounded-3 border mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small">Received With Thanks From:</span>
                <span class="fw-bold fs-5 text-dark"><?= htmlspecialchars($payment['customer_name']) ?></span>
            </div>
            <?php if (!empty($payment['customer_phone'])): ?>
                <div class="d-flex justify-content-between align-items-center small text-muted">
                    <span>Contact Phone:</span>
                    <span><?= htmlspecialchars($payment['customer_phone']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Big Amount Received Box -->
        <div class="amount-box">
            <div class="text-success fw-bold small text-uppercase mb-1">Amount Received (Wasool Shuda Raqam):</div>
            <div class="fs-1 fw-bold text-success">Rs. <?= number_format($payment['amount_paid'], 2) ?></div>
            <div class="text-muted small mt-1">Payment Method: <strong><?= htmlspecialchars($payment['payment_method']) ?></strong></div>
        </div>

        <!-- Remaining Balance Summary -->
        <div class="p-3 bg-light rounded-3 border mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold text-secondary">Remaining Balance Due (Baqi Udhar):</span>
                <span class="fw-bold fs-5 <?= $remaining_due > 0 ? 'text-danger' : 'text-success' ?>">
                    Rs. <?= number_format($remaining_due, 2) ?>
                </span>
            </div>
            <?php if ($remaining_due <= 0): ?>
                <div class="small text-success mt-1 fw-semibold text-center">
                    <i class="fa-solid fa-circle-check me-1"></i> Account is 100% Cleared! No pending dues.
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($payment['notes'])): ?>
            <div class="small text-muted mb-3">
                <strong>Remarks / Notes:</strong> <?= htmlspecialchars($payment['notes']) ?>
            </div>
        <?php endif; ?>

        <!-- Signatures & Footer -->
        <div class="row pt-4 mt-3 border-top text-center small text-muted">
            <div class="col-6">
                <div style="border-top: 1px solid #94a3b8; width: 80%; margin: 30px auto 5px auto;"></div>
                <div>Customer Signature</div>
            </div>
            <div class="col-6">
                <div style="border-top: 1px solid #94a3b8; width: 80%; margin: 30px auto 5px auto;"></div>
                <div>Authorized Signature (Iron Store)</div>
            </div>
        </div>

    </div>

    <?php if ($autoprint): ?>
        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    <?php endif; ?>

</body>
</html>

