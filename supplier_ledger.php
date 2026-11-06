<?php
// supplier_ledger.php - Supplier Account Statement & Khata History
$page_title = "Supplier Account Statement";
require_once __DIR__ . '/includes/header.php';

$supplier_name = trim($_GET['name'] ?? '');

if (empty($supplier_name) || !isset($pdo)) {
    set_flash('danger', 'Supplier name is required.');
    echo "<script>window.location.href = 'suppliers_khata.php';</script>";
    exit;
}

// Fetch all purchases for this supplier
$pur_stmt = $pdo->prepare("
    SELECT 'purchase' as trans_type, id, purchase_no as ref_no, bilty_no, created_at, grand_total as debit, paid_amount as initial_paid, due_amount, payment_status, notes
    FROM purchases 
    WHERE supplier_name = ?
    ORDER BY created_at ASC
");
$pur_stmt->execute([$supplier_name]);
$purchases = $pur_stmt->fetchAll();

// Fetch all direct payments made to this supplier
$pay_stmt = $pdo->prepare("
    SELECT 'payment' as trans_type, id, voucher_no as ref_no, created_at, 0 as debit, amount_paid as credit, 0 as due_amount, payment_method, notes
    FROM supplier_payments 
    WHERE supplier_name = ?
    ORDER BY created_at ASC
");
$pay_stmt->execute([$supplier_name]);
$payments = $pay_stmt->fetchAll();

// Merge and sort transactions chronologically
$ledger = [];
$total_inward = 0;
$total_paid = 0;
$supplier_phone = '';

foreach ($purchases as $p) {
    $total_inward += (float)$p['debit'];
    $total_paid += (float)$p['initial_paid'];

    $ledger[] = [
        'type'        => 'purchase',
        'ref_no'      => $p['ref_no'],
        'bilty_no'    => $p['bilty_no'],
        'date'        => $p['created_at'],
        'description' => "Stock Inward GRN # " . $p['ref_no'] . (!empty($p['bilty_no']) ? " (Bilty: " . $p['bilty_no'] . ")" : "") . " [" . strtoupper($p['payment_status']) . "]",
        'debit'       => (float)$p['debit'],
        'credit'      => (float)$p['initial_paid'],
        'id'          => $p['id']
    ];
}

foreach ($payments as $pay) {
    $total_paid += (float)$pay['credit'];

    $ledger[] = [
        'type'        => 'payment',
        'ref_no'      => $pay['ref_no'],
        'bilty_no'    => '',
        'date'        => $pay['created_at'],
        'description' => "Payment Paid to Mill (Voucher: " . $pay['ref_no'] . ") - " . $pay['payment_method'],
        'debit'       => 0,
        'credit'      => (float)$pay['credit'],
        'id'          => $pay['id']
    ];
}

// Sort all ledger rows by date
usort($ledger, function($a, $b) {
    return strtotime($a['date']) <=> strtotime($b['date']);
});

// Calculate running balance (debit increases what we owe; credit decreases what we owe)
$running_balance = 0;
foreach ($ledger as &$entry) {
    $running_balance += ($entry['debit'] - $entry['credit']);
    $entry['balance'] = $running_balance;
}
unset($entry);

$current_outstanding = $running_balance;

// Fetch supplier phone from last purchase
$phone_stmt = $pdo->prepare("SELECT supplier_phone FROM purchases WHERE supplier_name = ? AND supplier_phone != '' ORDER BY id DESC LIMIT 1");
$phone_stmt->execute([$supplier_name]);
$supplier_phone = $phone_stmt->fetchColumn() ?: '';
?>

<!-- Header & Breadcrumbs -->
<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="suppliers_khata.php" class="text-decoration-none">Supplier Khata</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($supplier_name) ?></li>
            </ol>
        </nav>
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-industry text-warning me-2"></i><?= htmlspecialchars($supplier_name) ?>
        </h3>
        <p class="text-muted mb-0">Supplier Account Statement & Factory Ledger Transactions</p>
    </div>

    <div class="col-md-5 text-md-end">
        <button onclick="window.print()" class="btn btn-outline-secondary me-2">
            <i class="fa-solid fa-print me-1"></i> Print Statement
        </button>
        <button type="button" class="btn btn-warning text-dark fw-bold me-2" data-bs-toggle="modal" data-bs-target="#paySupplierModal">
            <i class="fa-solid fa-money-bill-transfer me-1"></i> Pay Mill
        </button>
        <a href="purchase_add.php?supplier=<?= urlencode($supplier_name) ?>" class="btn btn-success fw-bold">
            <i class="fa-solid fa-plus me-1"></i> New Inward
        </a>
    </div>
</div>

<!-- Financial Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Stock Inward (Purchased)</span>
                <div class="stat-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-cart-flatbed"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= format_currency($total_inward) ?></div>
            <div class="small text-muted mt-2">
                <?= count($purchases) ?> factory shipments received
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-success">Total Amount Paid</span>
                <div class="stat-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-check-double"></i>
                </div>
            </div>
            <div class="stat-value text-success"><?= format_currency($total_paid) ?></div>
            <div class="small text-muted mt-2">
                Initial payments & subsequent vouchers
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="stat-card shadow-sm h-100 <?= $current_outstanding > 0 ? 'border-danger' : '' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label <?= $current_outstanding > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                    Current Balance Due (Payable)
                </span>
                <div class="stat-icon <?= $current_outstanding > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
            </div>
            <div class="stat-value <?= $current_outstanding > 0 ? 'text-danger' : 'text-success' ?>">
                <?= format_currency($current_outstanding) ?>
            </div>
            <div class="small text-muted mt-2">
                <?= $current_outstanding > 0 ? 'Pending payment to mill' : 'Account is fully settled' ?>
            </div>
        </div>
    </div>
</div>

<!-- Ledger Statement Table -->
<div class="card card-custom shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <div>
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-book-journal-whills text-primary me-2"></i>Account Ledger Transactions
            </h5>
            <small class="text-muted">Chronological statement of goods inward, payments, and running balance</small>
        </div>
        <div>
            <span class="badge bg-secondary"><?= count($ledger) ?> entries</span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Date & Time</th>
                    <th>Reference #</th>
                    <th>Transaction Description</th>
                    <th class="text-end text-dark">Debit (Inward +)</th>
                    <th class="text-end text-success">Credit (Paid -)</th>
                    <th class="text-end fw-bold">Running Balance</th>
                    <th class="text-center pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-receipt fa-3x mb-3 text-secondary opacity-50"></i>
                            <h5>No Transactions Found</h5>
                            <p>No purchases or payments recorded for this supplier.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ledger as $row): ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-semibold text-dark"><?= date('d M, Y', strtotime($row['date'])) ?></div>
                                <small class="text-muted"><?= date('h:i A', strtotime($row['date'])) ?></small>
                            </td>
                            <td>
                                <?php if ($row['type'] === 'purchase'): ?>
                                    <a href="purchase_print.php?id=<?= $row['id'] ?>" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none font-monospace p-2">
                                        <i class="fa-solid fa-cart-flatbed me-1"></i><?= htmlspecialchars($row['ref_no']) ?>
                                    </a>
                                <?php else: ?>
                                    <a href="supplier_payment_receipt.php?id=<?= $row['id'] ?>" class="badge bg-success-subtle text-success border border-success-subtle text-decoration-none font-monospace p-2">
                                        <i class="fa-solid fa-receipt me-1"></i><?= htmlspecialchars($row['ref_no']) ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($row['description']) ?></div>
                                <?php if (!empty($row['bilty_no'])): ?>
                                    <small class="text-muted"><i class="fa-solid fa-truck me-1"></i>Bilty #: <?= htmlspecialchars($row['bilty_no']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold text-dark">
                                <?= $row['debit'] > 0 ? format_currency($row['debit']) : '-' ?>
                            </td>
                            <td class="text-end fw-bold text-success">
                                <?= $row['credit'] > 0 ? format_currency($row['credit']) : '-' ?>
                            </td>
                            <td class="text-end fw-bold fs-6 <?= $row['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= format_currency($row['balance']) ?>
                            </td>
                            <td class="text-center pe-3">
                                <?php if ($row['type'] === 'purchase'): ?>
                                    <a href="purchase_print.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View Inward Note">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="supplier_payment_receipt.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-success" title="View Payment Receipt">
                                        <i class="fa-solid fa-receipt"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Pay Supplier -->
<div class="modal fade" id="paySupplierModal" tabindex="-1" aria-labelledby="paySupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <form method="POST" action="suppliers_khata.php">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold" id="paySupplierModalLabel">
                        <i class="fa-solid fa-building-columns me-2"></i>Pay <?= htmlspecialchars($supplier_name) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="supplier_name" value="<?= htmlspecialchars($supplier_name) ?>">
                    <input type="hidden" name="supplier_phone" value="<?= htmlspecialchars($supplier_phone) ?>">

                    <div class="mb-3 p-3 bg-light rounded-3 border">
                        <div class="small text-muted">Current Outstanding Balance:</div>
                        <div class="fs-4 fw-bold text-danger"><?= format_currency($current_outstanding) ?></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Amount (Rs.) <span class="text-danger">*</span></label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white fw-bold">Rs.</span>
                            <input type="number" step="0.01" min="1" name="amount_paid" class="form-control fw-bold text-success" required value="<?= $current_outstanding > 0 ? $current_outstanding : '' ?>" placeholder="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="Bank Transfer">Bank Transfer / Online</option>
                            <option value="Cheque">Bank Cheque</option>
                            <option value="Cash">Cash</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes / Transaction Reference</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Paid via Online Bank Transfer"></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="pay_supplier" class="btn btn-warning text-dark fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i> Save Payment & Print Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

