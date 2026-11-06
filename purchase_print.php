<?php
// purchase_print.php - Printable Goods Inward Receipt / Mill Bilty Note
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$purchase_id = intval($_GET['id'] ?? 0);
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

if ($purchase_id <= 0 || !isset($pdo)) {
    die("Invalid Purchase ID or Database connection failed.");
}

// Fetch purchase details
$stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
$stmt->execute([$purchase_id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    die("Purchase record not found.");
}

// Fetch purchase items
$item_stmt = $pdo->prepare("
    SELECT pi.*, p.weight_per_foot, p.standard_length_feet, p.weight_per_piece 
    FROM purchase_items pi
    LEFT JOIN products p ON pi.product_id = p.id
    WHERE pi.purchase_id = ?
");
$item_stmt->execute([$purchase_id]);
$items = $item_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Inward Note - <?= htmlspecialchars($purchase['purchase_no']) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        body {
            background-color: #f1f5f9;
            color: #0f172a;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 30px 0;
        }

        .purchase-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            max-width: 800px;
            margin: 0 auto;
            padding: 35px;
            border: 1px solid #e2e8f0;
        }

        .purchase-header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .store-title {
            font-weight: 800;
            letter-spacing: 1px;
            font-size: 1.8rem;
            color: #0f172a;
        }

        .table-items th {
            background-color: #f8fafc;
            border-bottom: 2px solid #cbd5e1;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        .total-box {
            background-color: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #e2e8f0;
        }

        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .purchase-card {
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
    <div class="container mb-4 no-print" style="max-width: 800px;">
        <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm border">
            <a href="purchases.php" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Purchases
            </a>
            <div class="d-flex gap-2">
                <a href="supplier_ledger.php?name=<?= urlencode($purchase['supplier_name']) ?>" class="btn btn-outline-primary">
                    <i class="fa-solid fa-book-bookmark me-1"></i> Supplier Ledger
                </a>
                <button onclick="window.print()" class="btn btn-primary fw-bold shadow-sm">
                    <i class="fa-solid fa-print me-1"></i> Print Inward Note
                </button>
            </div>
        </div>
    </div>

    <!-- Printable Inward Voucher -->
    <div class="purchase-card">
        
        <!-- Header -->
        <div class="purchase-header d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <div class="store-title"><i class="fa-solid fa-cubes-stacked text-primary me-2"></i>IRON STORE</div>
                <div class="text-muted small">Steel, Iron Pipes, Girders & Hardware Wholesale & Retail</div>
                <div class="badge bg-dark mt-1 px-3 py-1 text-uppercase">Stock Inward / Goods Receipt Note (GRN)</div>
            </div>
            <div class="text-end mt-2 mt-sm-0">
                <h4 class="fw-bold text-primary mb-0 font-monospace"><?= htmlspecialchars($purchase['purchase_no']) ?></h4>
                <div class="text-muted small">Date: <strong><?= date('d-M-Y h:i A', strtotime($purchase['created_at'])) ?></strong></div>
                <?php if (!empty($purchase['bilty_no'])): ?>
                    <div class="badge bg-secondary font-monospace mt-1">Bilty #: <?= htmlspecialchars($purchase['bilty_no']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Supplier & Shipment Details -->
        <div class="row mb-4">
            <div class="col-sm-6">
                <h6 class="text-uppercase fw-bold text-muted small mb-2">Supplier / Mill Details:</h6>
                <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($purchase['supplier_name']) ?></div>
                <?php if (!empty($purchase['supplier_phone'])): ?>
                    <div class="text-muted"><i class="fa-solid fa-phone me-1 text-secondary"></i><?= htmlspecialchars($purchase['supplier_phone']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                <h6 class="text-uppercase fw-bold text-muted small mb-2">Payment Details:</h6>
                <div>Method: <strong><?= htmlspecialchars($purchase['payment_method']) ?></strong></div>
                <div>Status: 
                    <?php if ($purchase['payment_status'] === 'paid'): ?>
                        <span class="badge bg-success">FULLY PAID</span>
                    <?php elseif ($purchase['payment_status'] === 'partial'): ?>
                        <span class="badge bg-warning text-dark">PARTIALLY PAID</span>
                    <?php else: ?>
                        <span class="badge bg-danger">PAYMENT DUE</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Inward Items Table -->
        <table class="table table-bordered table-items mb-4 align-middle">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 45%;">Product Description</th>
                    <th class="text-center" style="width: 15%;">Inward Qty</th>
                    <th class="text-center" style="width: 15%;">Added Stock</th>
                    <th class="text-end" style="width: 10%;">Rate</th>
                    <th class="text-end" style="width: 15%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($items as $item): ?>
                    <tr>
                        <td class="text-center text-muted"><?= $i++ ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                            <small class="text-muted">Unit: <?= strtoupper($item['purchase_unit']) ?></small>
                        </td>
                        <td class="text-center fw-semibold">
                            <?= number_format($item['quantity'], 2) ?> <?= strtoupper($item['purchase_unit']) ?>
                        </td>
                        <td class="text-center text-primary fw-bold">
                            <?= number_format($item['added_stock_kg'], 1) ?> KG
                            <div class="small text-muted font-monospace">(<?= number_format($item['added_stock_kg'] / 1000, 3) ?> Ton)</div>
                        </td>
                        <td class="text-end">
                            Rs. <?= number_format($item['rate_per_unit'], 2) ?>
                        </td>
                        <td class="text-end fw-bold">
                            Rs. <?= number_format($item['subtotal'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Summary & Totals -->
        <div class="row">
            <div class="col-sm-6 mb-3 mb-sm-0">
                <div class="total-box h-100">
                    <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-scale-balanced me-1 text-primary"></i> Shipment Weight Summary:</h6>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total Net Weight:</span>
                        <strong class="text-dark"><?= number_format($purchase['total_weight_kg'], 1) ?> KG</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Equivalent in Tons:</span>
                        <strong class="text-primary fs-6"><?= number_format($purchase['total_weight_kg'] / 1000, 3) ?> Metric Ton</strong>
                    </div>
                    <?php if (!empty($purchase['notes'])): ?>
                        <div class="mt-2 pt-2 border-top small text-muted">
                            <strong>Notes:</strong> <?= nl2br(htmlspecialchars($purchase['notes'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-sm-6">
                <div class="total-box">
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Goods Subtotal:</span>
                        <span class="fw-semibold">Rs. <?= number_format($purchase['subtotal'], 2) ?></span>
                    </div>

                    <?php if ($purchase['extra_cost'] > 0): ?>
                        <div class="d-flex justify-content-between py-1">
                            <span class="text-muted">Carriage / Freight / Labor:</span>
                            <span class="fw-semibold">+ Rs. <?= number_format($purchase['extra_cost'], 2) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between py-2 my-1 border-top border-bottom">
                        <span class="fs-5 fw-bold text-dark">Grand Total:</span>
                        <span class="fs-5 fw-bold text-primary">Rs. <?= number_format($purchase['grand_total'], 2) ?></span>
                    </div>

                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Amount Paid:</span>
                        <span class="fw-bold text-success">Rs. <?= number_format($purchase['paid_amount'], 2) ?></span>
                    </div>

                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Balance Due:</span>
                        <span class="fw-bold <?= $purchase['due_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                            Rs. <?= number_format($purchase['due_amount'], 2) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Receiving Signatures -->
        <div class="row mt-5 pt-4 border-top">
            <div class="col-4 text-center">
                <div class="border-top border-dark pt-1 small fw-bold">Stock Received By</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top border-dark pt-1 small fw-bold">Weighbridge (Kanta) Checked</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top border-dark pt-1 small fw-bold">Store Manager Signature</div>
            </div>
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

