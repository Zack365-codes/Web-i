<?php
// supplier_payment_receipt.php - Printable Supplier Payment Voucher (Adaigi Rasid)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$payment_id = intval($_GET['id'] ?? 0);
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

if ($payment_id <= 0 || !isset($pdo)) {
    die("Invalid Payment ID or Database connection failed.");
}

// Fetch payment details
$stmt = $pdo->prepare("SELECT * FROM supplier_payments WHERE id = ?");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment record not found.");
}

// Calculate supplier's remaining due balance
$due_stmt = $pdo->prepare("SELECT COALESCE(SUM(due_amount), 0) as remaining_due FROM purchases WHERE supplier_name = ?");
$due_stmt->execute([$payment['supplier_name']]);
$remaining_due = (float)$due_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Payment Voucher - <?= htmlspecialchars($payment['voucher_no']) ?></title>
    
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
            max-width: 620px;
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
            background: linear-gradient(135deg, #fefce8 0%, #fef08a 100%);
            border: 2px solid #facc15;
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
    <div class="container mb-4 no-print" style="max-width: 620px;">
        <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm border">
            <a href="suppliers_khata.php" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Supplier Khata
            </a>
            <div class="d-flex gap-2">
                <a href="supplier_ledger.php?name=<?= urlencode($payment['supplier_name']) ?>" class="btn btn-outline-primary">
                    <i class="fa-solid fa-book-bookmark me-1"></i> View Statement
                </a>
                <button onclick="window.print()" class="btn btn-warning text-dark fw-bold shadow-sm">
                    <i class="fa-solid fa-print me-1"></i> Print Voucher
                </button>
            </div>
        </div>
    </div>

    <!-- Printable Receipt Card -->
    <div class="receipt-card">
        
        <!-- Header -->
        <div class="receipt-header">
            <div class="store-title"><i class="fa-solid fa-cubes-stacked text-warning me-2"></i>IRON STORE</div>
            <div class="text-muted small">Wholesale & Retail Steel, Pipes & Hardware</div>
            <div class="badge bg-warning text-dark mt-2 px-3 py-1 text-uppercase fw-bold">Supplier Payment Voucher (Adaigi Rasid)</div>
        </div>

        <!-- Receipt Info -->
        <div class="row mb-3">
            <div class="col-6">
                <div class="text-muted small">Voucher No:</div>
                <div class="fw-bold font-monospace text-dark fs-6"><?= htmlspecialchars($payment['voucher_no']) ?></div>
            </div>
            <div class="col-6 text-end">
                <div class="text-muted small">Date & Time:</div>
                <div class="fw-semibold text-dark"><?= date('d-M-Y h:i A', strtotime($payment['created_at'])) ?></div>
            </div>
        </div>

        <!-- Supplier Info -->
        <div class="p-3 bg-light rounded-3 border mb-3">
            <div class="text-muted small text-uppercase fw-bold">Paid To (Supplier / Mill):</div>
            <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($payment['supplier_name']) ?></div>
            <?php if (!empty($payment['supplier_phone'])): ?>
                <div class="text-muted small"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($payment['supplier_phone']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Paid Amount Box -->
        <div class="amount-box">
            <div class="text-uppercase fw-bold text-dark small mb-1">Amount Paid to Factory:</div>
            <div class="display-6 fw-bold text-dark">Rs. <?= number_format($payment['amount_paid'], 2) ?></div>
            <div class="small text-muted mt-1">Payment Method: <strong><?= htmlspecialchars($payment['payment_method']) ?></strong></div>
        </div>

        <!-- Remaining Balance Summary -->
        <div class="p-3 rounded-3 border mb-4 <?= $remaining_due > 0 ? 'bg-danger-subtle border-danger-subtle' : 'bg-success-subtle border-success-subtle' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold <?= $remaining_due > 0 ? 'text-danger' : 'text-success' ?>">
                    Remaining Balance Due to Supplier:
                </span>
                <span class="fs-5 fw-bold <?= $remaining_due > 0 ? 'text-danger' : 'text-success' ?>">
                    Rs. <?= number_format($remaining_due, 2) ?>
                </span>
            </div>
        </div>

        <?php if (!empty($payment['notes'])): ?>
            <div class="mb-4 p-2 bg-light rounded border small">
                <strong>Remarks / Reference:</strong> <?= nl2br(htmlspecialchars($payment['notes'])) ?>
            </div>
        <?php endif; ?>

        <!-- Signature lines -->
        <div class="row mt-5 pt-3">
            <div class="col-6 text-center">
                <div class="border-top border-dark pt-1 small fw-bold">Authorized By (Iron Store)</div>
            </div>
            <div class="col-6 text-center">
                <div class="border-top border-dark pt-1 small fw-bold">Received By / Mill Sign</div>
            </div>
        </div>

        <div class="text-center mt-4 small text-muted">
            Computer generated supplier payment voucher &bull; Iron Store Management System
        </div>

    </div>

    <?php if ($autoprint): ?>
        <script>
            window.addEventListener('load', function() {
                window.print();
            });
        </script>
    <?php endif; ?>
</body>
</html>

