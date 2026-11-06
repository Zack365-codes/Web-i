<?php
// invoice_print.php - Printable Receipt / Invoice
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$sale_id = intval($_GET['id'] ?? 0);
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

if ($sale_id <= 0 || !isset($pdo)) {
    die("Invalid Invoice ID or Database connection failed.");
}

// Fetch sale details
$stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    die("Invoice not found.");
}

// Fetch sale items
$item_stmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
$item_stmt->execute([$sale_id]);
$items = $item_stmt->fetchAll();

// Calculate total pieces and total weight on this bill
$total_bill_pieces = 0;
$total_bill_weight = 0;
foreach ($items as $it) {
    $total_bill_pieces += (float)($it['equivalent_pieces'] ?: $it['quantity']);
    $total_bill_weight += (float)$it['deducted_stock_kg'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?= htmlspecialchars($sale['invoice_no']) ?></title>
    
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
            padding: 40px;
            border: 1px solid #e2e8f0;
        }

        .invoice-header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .store-title {
            font-weight: 800;
            letter-spacing: 1px;
            font-size: 1.8rem;
            color: #0f172a;
        }

        .invoice-meta {
            background-color: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }

        .table-invoice th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            border-bottom: 2px solid #cbd5e1;
            padding: 10px 10px;
        }

        .table-invoice td {
            padding: 10px;
            vertical-align: middle;
            border-bottom: 1px solid #e2e8f0;
        }

        .total-box {
            background-color: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 15px 20px;
        }

        .grand-total-row {
            font-size: 1.3rem;
            font-weight: 800;
            color: #0f172a;
            border-top: 2px solid #cbd5e1;
            padding-top: 8px;
            margin-top: 8px;
        }

        .pieces-badge {
            background-color: #e0f2fe;
            color: #0369a1;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .invoice-card {
                box-shadow: none !important;
                border: none !important;
                max-width: 100% !important;
                padding: 15px !important;
            }
        }
    </style>
</head>
<body>

    <!-- Action Bar (Hidden when printing) -->
    <div class="container mb-4 no-print" style="max-width: 820px;">
        <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm border">
            <div>
                <a href="pos.php" class="btn btn-outline-secondary me-2">
                    <i class="fa-solid fa-plus me-1"></i> New Bill
                </a>
                <a href="invoices.php" class="btn btn-outline-secondary me-2">
                    <i class="fa-solid fa-list me-1"></i> All Bills
                </a>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-chart-pie me-1"></i> Dashboard
                </a>
            </div>

            <div>
                <button onclick="window.print()" class="btn btn-success fw-bold px-4 shadow-sm">
                    <i class="fa-solid fa-print me-2"></i> Print Bill (Print Karein)
                </button>
            </div>
        </div>
    </div>

    <!-- Printable Invoice Card -->
    <div class="invoice-card" id="printableArea">
        
        <!-- Header -->
        <div class="invoice-header d-flex justify-content-between align-items-start">
            <div>
                <div class="store-title">IRON STORE & HARDWARE</div>
                <div class="text-muted small">Quality Steel, Girders, Gates, Saria & Iron Pipes</div>
                <div class="text-muted small"><i class="fa-solid fa-phone me-1"></i> Phone: 0300-1234567 / 0321-7654321</div>
                <div class="text-muted small"><i class="fa-solid fa-location-dot me-1"></i> Main Iron Market, Pakistan</div>
            </div>

            <div class="text-end">
                <div class="badge bg-dark fs-6 px-3 py-2 mb-1">CASH / CREDIT MEMO</div>
                <div class="fw-bold fs-5 text-primary mt-1"><?= htmlspecialchars($sale['invoice_no']) ?></div>
                <div class="small text-muted">Date: <?= date('d-M-Y h:i A', strtotime($sale['created_at'])) ?></div>
            </div>
        </div>

        <!-- Meta / Customer Info -->
        <div class="invoice-meta">
            <div class="row g-2">
                <div class="col-6">
                    <div class="small text-muted">Customer Name:</div>
                    <div class="fw-bold fs-6"><?= htmlspecialchars($sale['customer_name'] ?: 'Walk-in Customer') ?></div>
                </div>
                <div class="col-6 text-end">
                    <div class="small text-muted">Customer Phone:</div>
                    <div class="fw-bold"><?= htmlspecialchars($sale['customer_phone'] ?: 'N/A') ?></div>
                </div>
            </div>
        </div>

        <!-- Items Table with Equivalent Pieces & Sold Unit -->
        <table class="table table-invoice mb-4">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 38%;">Item Description</th>
                    <th style="width: 17%;" class="text-center">Sold Qty</th>
                    <th style="width: 15%;" class="text-center">Pieces Count</th>
                    <th style="width: 12%;" class="text-end">Rate (Rs.)</th>
                    <th style="width: 13%;" class="text-end">Total (Rs.)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): 
                    $eq_pcs = (float)($it['equivalent_pieces'] ?: ($it['sale_unit'] === 'piece' ? $it['quantity'] : 0));
                ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($it['product_name']) ?></div>
                            <div class="small text-muted">Total Weight: <?= number_format($it['deducted_stock_kg'], 2) ?> KG</div>
                        </td>
                        <td class="text-center">
                            <span class="fw-bold"><?= number_format($it['quantity'], 2) ?></span>
                            <span class="badge bg-light text-dark border ms-1"><?= strtoupper($it['sale_unit']) ?></span>
                        </td>
                        <td class="text-center">
                            <span class="pieces-badge">
                                ~<?= number_format($eq_pcs, 2) ?> Pieces
                            </span>
                        </td>
                        <td class="text-end"><?= number_format($it['unit_price'], 2) ?></td>
                        <td class="text-end fw-bold"><?= number_format($it['subtotal'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals & Quantity Summary -->
        <div class="row align-items-end mb-4">
            <div class="col-6">
                <!-- Pieces & Weight Summary on Bill -->
                <div class="p-3 bg-light rounded-3 border mb-2">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-secondary small fw-bold">Total Pieces Delivered:</span>
                        <span class="fw-bold text-primary">~<?= number_format($total_bill_pieces, 2) ?> Pieces</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary small fw-bold">Total Net Weight:</span>
                        <span class="fw-bold text-dark"><?= number_format($total_bill_weight, 2) ?> KG</span>
                    </div>
                </div>

                <div class="p-3 bg-white rounded-3 border">
                    <div class="small text-muted mb-1"><strong>Payment Method:</strong> <?= htmlspecialchars($sale['payment_method']) ?></div>
                    <div class="small text-muted mb-1">
                        <strong>Payment Status:</strong> 
                        <?php if ($sale['payment_status'] === 'paid'): ?>
                            <span class="badge bg-success">PAID IN FULL</span>
                        <?php elseif ($sale['payment_status'] === 'partial'): ?>
                            <span class="badge bg-warning text-dark">PARTIALLY PAID</span>
                        <?php else: ?>
                            <span class="badge bg-danger">DUE / UDHAR</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($sale['notes'])): ?>
                        <div class="small text-muted mt-2"><strong>Notes:</strong> <?= htmlspecialchars($sale['notes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-6">
                <div class="total-box">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Subtotal:</span>
                        <span class="fw-semibold">Rs. <?= number_format($sale['subtotal'], 2) ?></span>
                    </div>

                    <?php if ($sale['discount'] > 0): ?>
                        <div class="d-flex justify-content-between text-danger mb-1">
                            <span>Discount / Kami:</span>
                            <span class="fw-semibold">- Rs. <?= number_format($sale['discount'], 2) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between grand-total-row">
                        <span>Grand Total:</span>
                        <span class="text-dark">Rs. <?= number_format($sale['grand_total'], 2) ?></span>
                    </div>

                    <div class="d-flex justify-content-between mt-2 pt-2 border-top">
                        <span class="text-muted">Paid Amount:</span>
                        <span class="fw-bold text-success">Rs. <?= number_format($sale['paid_amount'], 2) ?></span>
                    </div>

                    <div class="d-flex justify-content-between mt-1">
                        <span class="text-muted">Balance Due (Udhar):</span>
                        <span class="fw-bold <?= $sale['due_amount'] > 0 ? 'text-danger' : 'text-secondary' ?>">
                            Rs. <?= number_format($sale['due_amount'], 2) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Notice -->
        <div class="text-center pt-3 border-top text-muted small">
            <div>Thank you for your business! Please check weight and material before loading.</div>
            <div class="mt-1">Generated by Iron Store Management System</div>
        </div>

    </div>

    <!-- Auto Print Script if autoprint is set -->
    <?php if ($autoprint): ?>
        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    <?php endif; ?>

</body>
</html>
