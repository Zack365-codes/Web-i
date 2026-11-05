<?php
// customer_ledger.php - Customer Account Statement & Khata History
$page_title = "Customer Account Statement";
require_once __DIR__ . '/includes/header.php';

$customer_name = trim($_GET['name'] ?? '');

if (empty($customer_name) || !isset($pdo)) {
    set_flash('danger', 'Customer name is required.');
    echo "<script>window.location.href = 'customers_khata.php';</script>";
    exit;
}

// Fetch all sales for this customer
$sales_stmt = $pdo->prepare("
    SELECT 'sale' as trans_type, id, invoice_no as ref_no, created_at, grand_total as debit, paid_amount as initial_paid, due_amount, payment_status, notes
    FROM sales 
    WHERE customer_name = ?
    ORDER BY created_at ASC
");
$sales_stmt->execute([$customer_name]);
$sales = $sales_stmt->fetchAll();

// Fetch all direct payments received from this customer
$pay_stmt = $pdo->prepare("
    SELECT 'payment' as trans_type, id, receipt_no as ref_no, created_at, 0 as debit, amount_paid as credit, 0 as due_amount, payment_method, notes
    FROM customer_payments 
    WHERE customer_name = ?
    ORDER BY created_at ASC
");
$pay_stmt->execute([$customer_name]);
$payments = $pay_stmt->fetchAll();

// Merge and sort transactions chronologically
$ledger = [];
$total_billed = 0;
$total_paid = 0;
$total_due = 0;
$customer_phone = '';

foreach ($sales as $s) {
    $total_billed += (float)$s['debit'];
    $total_due += (float)$s['due_amount'];
    // In sales, initial_paid was collected at bill time
    $total_paid += (float)$s['initial_paid'];

    $ledger[] = [
        'type'        => 'invoice',
        'ref_no'      => $s['ref_no'],
        'date'        => $s['created_at'],
        'description' => "Invoice / Bill # " . $s['ref_no'] . " (" . strtoupper($s['payment_status']) . ")",
        'debit'       => (float)$s['debit'],
        'credit'      => (float)$s['initial_paid'],
        'id'          => $s['id']
    ];
}

foreach ($payments as $p) {
    $ledger[] = [
        'type'        => 'payment',
        'ref_no'      => $p['ref_no'],
        'date'        => $p['created_at'],
        'description' => "Cash / Recovery Received (Receipt: " . $p['ref_no'] . ") - " . $p['payment_method'],
        'debit'       => 0,
        'credit'      => (float)$p['credit'],
        'id'          => $p['id']
    ];
}

// Sort all ledger rows by date
usort($ledger, function($a, $b) {
    return strtotime($a['date']) <=> strtotime($b['date']);
});

// Calculate running balance
$running_balance = 0;
foreach ($ledger as &$entry) {
    $running_balance += ($entry['debit'] - $entry['credit']);
    $entry['balance'] = $running_balance;
}
unset($entry);

$current_outstanding = $running_balance;
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="customers_khata.php" class="text-decoration-none">Credit Ledger</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($customer_name) ?></li>
            </ol>
        </nav>
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-user text-primary me-2"></i><?= htmlspecialchars($customer_name) ?>
        </h3>
        <p class="text-muted mb-0">Customer Account Statement & Khata Transaction History</p>
    </div>

    <div class="col-md-5 text-md-end">
        <?php if ($current_outstanding > 0): ?>
            <a href="customers_khata.php?search=<?= urlencode($customer_name) ?>" class="btn btn-success fw-bold shadow-sm me-2">
                <i class="fa-solid fa-hand-holding-dollar me-1"></i> Receive Payment
            </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-dark shadow-sm me-2">
            <i class="fa-solid fa-print me-1"></i> Print Statement
        </button>
        <a href="customers_khata.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Ledger
        </a>
    </div>
</div>

<!-- Account Summary Cards -->
<div class="row g-3 mb-4">
    <!-- Current Outstanding Due (Udhar) -->
    <div class="col-md-4">
        <div class="stat-card shadow-sm h-100 border-<?= $current_outstanding > 0 ? 'danger' : 'success' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label <?= $current_outstanding > 0 ? 'text-danger' : 'text-success' ?> fw-bold">Current Balance Due (Baqi Udhar)</span>
                <div class="stat-icon bg-<?= $current_outstanding > 0 ? 'danger' : 'success' ?> text-white">
                    <i class="fa-solid fa-<?= $current_outstanding > 0 ? 'scale-unbalanced' : 'circle-check' ?>"></i>
                </div>
            </div>
            <div class="stat-value <?= $current_outstanding > 0 ? 'text-danger' : 'text-success' ?>">
                <?= format_currency($current_outstanding) ?>
            </div>
            <div class="small text-muted mt-2">
                <?= $current_outstanding > 0 ? 'Customer owes this amount' : 'Account is fully settled' ?>
            </div>
        </div>
    </div>

    <!-- Total Purchases (Debits) -->
    <div class="col-md-4">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Purchases (Billed)</span>
                <div class="stat-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-cart-shopping"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= format_currency($total_billed) ?></div>
            <div class="small text-muted mt-2">
                Total iron & hardware purchased
            </div>
        </div>
    </div>

    <!-- Total Recovered (Credits) -->
    <div class="col-md-4">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Payments Received</span>
                <div class="stat-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-receipt"></i>
                </div>
            </div>
            <div class="stat-value text-success"><?= format_currency($total_billed - $current_outstanding) ?></div>
            <div class="small text-muted mt-2">
                Total cash/bank collected to date
            </div>
        </div>
    </div>
</div>

<!-- Ledger Transactions Table -->
<div class="card card-custom">
    <div class="card-header card-header-clean d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fa-solid fa-list-check text-primary me-2"></i>Chronological Account Statement</span>
        <span class="badge bg-light text-secondary border"><?= count($ledger) ?> Transactions</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom align-middle mb-0">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Ref #</th>
                    <th>Description / Transaction Details</th>
                    <th class="text-end">Debit (Bill Total)</th>
                    <th class="text-end">Credit (Paid)</th>
                    <th class="text-end">Balance (Baqi Udhar)</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            No transactions found for this customer.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ledger as $row): ?>
                        <tr>
                            <td class="small text-muted">
                                <?= date('d-M-Y', strtotime($row['date'])) ?><br>
                                <span class="text-secondary"><?= date('h:i A', strtotime($row['date'])) ?></span>
                            </td>

                            <td class="fw-bold font-monospace">
                                <?= htmlspecialchars($row['ref_no']) ?>
                            </td>

                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($row['description']) ?></div>
                            </td>

                            <td class="text-end fw-bold text-danger">
                                <?= $row['debit'] > 0 ? format_currency($row['debit']) : '-' ?>
                            </td>

                            <td class="text-end fw-bold text-success">
                                <?= $row['credit'] > 0 ? format_currency($row['credit']) : '-' ?>
                            </td>

                            <td class="text-end fw-bold fs-6 <?= $row['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= format_currency($row['balance']) ?>
                            </td>

                            <td class="text-center">
                                <?php if ($row['type'] === 'invoice'): ?>
                                    <a href="invoice_print.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm" title="View Bill">
                                        <i class="fa-solid fa-eye me-1"></i> Bill
                                    </a>
                                <?php else: ?>
                                    <a href="payment_receipt_print.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-outline-success btn-sm" title="View Receipt">
                                        <i class="fa-solid fa-receipt me-1"></i> Receipt
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>

