<?php
// return_print.php - Printable Return Receipt / Credit Note
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$return_id = intval($_GET['id'] ?? 0);
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

if ($return_id <= 0 || !isset($pdo)) {
    die("Invalid Return ID or Database connection failed.");
}

// Fetch return details
$stmt = $pdo->prepare("SELECT * FROM sales_returns WHERE id = ?");
$stmt->execute([$return_id]);
$return = $stmt->fetch();

if (!$return) {
    die("Return record not found.");
}

// Fetch return items
$item_stmt = $pdo->prepare("SELECT * FROM sales_return_items WHERE return_id = ?");
$item_stmt->execute([$return_id]);
$items = $item_stmt->fetchAll();

// Calculate total restored pieces
$total_restored_pcs = 0;
foreach ($items as $it) {
    $total_restored_pcs += (float)$it['restored_pieces'];
}

// Fetch associated sale for context
$sale = null;
if ($return['sale_id']) {
    $s_stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $s_stmt->execute([$return['sale_id']]);
    $sale = $s_stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Credit Note - <?= htmlspecialchars($return['return_no']) ?></title>
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        body {
            background-color: #f1f5f9;
            color: #0f172a;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 20px 0;
        }

        .invoice-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            max-width: 820px;
            margin: 0 auto;
            padding: 35px 40px;
            border-top: 6px solid #dc2626;
        }

        .bill-header-title {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
        }

        .table-invoice th {
            background-color: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            padding: 10px 12px;
        }

        .table-invoice td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .badge-return {
            background-color: #fee2e2;
            color: #991b1b;
            font-size: 13px;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 6px;
        }

        @media print {
            body {
                background: white !important;
                padding: 0 !important;
            }
            .invoice-card {
                box-shadow: none !important;
                border-radius: 0 !important;
                max-width: 100% !important;
                padding: 15px !important;
                border-top: none !important;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- Action Bar (Hidden in Print) -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print max-width-container" style="max-width: 820px; margin: 0 auto;">
        <a href="returns.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Returns
        </a>
        <div class="d-flex gap-2">
            <button onclick="window.print();" class="btn btn-danger fw-bold shadow-sm">
                <i class="fa-solid fa-print me-1"></i> Print Credit Note
            </button>
            <a href="return_add.php" class="btn btn-primary fw-bold shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> New Return
            </a>
        </div>
    </div>

    <!-- Credit Note Document -->
    <div class="invoice-card">
        
        <!-- Header -->
        <div class="row align-items-center border-bottom pb-3 mb-3">
            <div class="col-sm-7">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge-return"><i class="fa-solid fa-rotate-left me-1"></i> SALES RETURN VOUCHER</span>
                    <span class="badge bg-secondary-subtle text-secondary">Credit Note</span>
                </div>
                <h1 class="bill-header-title mb-0">IRON & STEEL STORE</h1>
                <p class="text-muted small mb-0">Deformed Bars &bull; Pipes &bull; Angles &bull; Girders &bull; Hardware</p>
                <p class="text-muted small mb-0"><i class="fa-solid fa-location-dot me-1"></i> Main Iron Market, Warehouse Road &bull; Ph: 0300-1234567</p>
            </div>
            <div class="col-sm-5 text-sm-end mt-3 mt-sm-0">
                <div class="text-muted small">Return Voucher #</div>
                <h4 class="fw-bold text-danger mb-1"><?= htmlspecialchars($return['return_no']) ?></h4>
                <div class="small text-muted">Original Invoice: <strong><?= htmlspecialchars($return['invoice_no'] ?: 'Walk-in') ?></strong></div>
                <div class="small text-muted">Date: <?= date('d M Y, h:i A', strtotime($return['created_at'])) ?></div>
                <div class="small text-muted">Processed By: <?= htmlspecialchars($return['created_by'] ?? 'Staff') ?></div>
            </div>
        </div>

        <!-- Customer & Settlement Details -->
        <div class="row g-3 mb-4 p-3 bg-light rounded-3 border">
            <div class="col-sm-6">
                <span class="text-muted small d-block mb-1">Customer Details:</span>
                <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($return['customer_name'] ?: 'Walk-in Customer') ?></h5>
                <?php if (!empty($return['customer_phone'])): ?>
                    <div class="small text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($return['customer_phone']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-sm-6 text-sm-end">
                <span class="text-muted small d-block mb-1">Settlement Method:</span>
                <span class="badge bg-danger fs-6 px-3 py-2"><?= htmlspecialchars($return['refund_method']) ?></span>
                <?php if (!empty($return['reason'])): ?>
                    <div class="small text-muted mt-1">Reason: <em>"<?= htmlspecialchars($return['reason']) ?>"</em></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Returned Items Table -->
        <div class="table-responsive mb-4">
            <table class="table table-invoice mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Returned</th>
                        <th>Sold Unit</th>
                        <th>Returned Qty</th>
                        <th class="text-center">Restored Stock</th>
                        <th>Rate (Rs.)</th>
                        <th class="text-end">Refund (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $it): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><strong class="text-dark"><?= htmlspecialchars($it['product_name']) ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?= strtoupper($it['sale_unit']) ?></span></td>
                            <td class="fw-bold"><?= number_format($it['quantity_returned'], 2) ?></td>
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success fw-bold">
                                    +<?= number_format($it['restored_stock_kg'], 2) ?> KG
                                    <?php if ($it['restored_pieces'] > 0): ?>
                                        (~<?= number_format($it['restored_pieces'], 1) ?> Pcs)
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>Rs. <?= number_format($it['unit_price'], 2) ?></td>
                            <td class="text-end fw-bold text-danger fs-6">Rs. <?= number_format($it['refund_subtotal'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Summary Totals -->
        <div class="row align-items-center border-top pt-3 mb-4">
            <div class="col-sm-7">
                <div class="p-2 bg-light rounded border small">
                    <div><i class="fa-solid fa-boxes-stacked me-1 text-primary"></i> <strong>Inventory Restored:</strong> Total +<?= number_format($return['total_returned_weight_kg'], 2) ?> KG added back to store stock.</div>
                    <?php if ($return['refund_method'] === 'Deduct from Khata' && $sale): ?>
                        <div class="text-success mt-1"><i class="fa-solid fa-circle-check me-1"></i> Customer's Udhar on Invoice <?= htmlspecialchars($sale['invoice_no']) ?> adjusted! Remaining Due: <strong>Rs. <?= number_format($sale['due_amount'], 2) ?></strong></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-sm-5 text-sm-end">
                <div class="text-muted small">Total Refund Settled:</div>
                <h2 class="fw-bold text-danger mb-0">Rs. <?= number_format($return['total_refund_amount'], 2) ?></h2>
            </div>
        </div>

        <!-- Signatures -->
        <div class="row mt-5 pt-4 text-center text-muted small border-top">
            <div class="col-4">
                <div class="border-top border-secondary pt-1 mx-3">Customer Signature</div>
            </div>
            <div class="col-4">
                <div class="border-top border-secondary pt-1 mx-3">Store Keeper / Stamp</div>
            </div>
            <div class="col-4">
                <div class="border-top border-secondary pt-1 mx-3">Authorized Signature</div>
            </div>
        </div>

        <!-- Footer Note -->
        <div class="text-center text-muted small mt-4 pt-2">
            This is an official Credit Note / Goods Return Receipt. Thank you!
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

