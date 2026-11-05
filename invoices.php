<?php
// invoices.php - Invoices History & List
$page_title = "Invoices & Sales History";
require_once __DIR__ . '/includes/header.php';

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$query = "SELECT s.*, (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as total_items FROM sales s WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (s.invoice_no LIKE ? OR s.customer_name LIKE ? OR s.customer_phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($status_filter)) {
    $query .= " AND s.payment_status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY s.id DESC";

$invoices = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to load invoices: " . $e->getMessage();
    }
}
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-6 mb-3 mb-md-0">
        <h3 class="fw-bold mb-1"><i class="fa-solid fa-file-invoice text-primary me-2"></i>Sales & Invoices History</h3>
        <p class="text-muted mb-0">View all past customer bills, check payment status, and print receipts with 1-click.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="pos.php" class="btn btn-success shadow-sm fw-bold">
            <i class="fa-solid fa-plus-circle me-1"></i> New Sale / Make Bill
        </a>
    </div>
</div>

<!-- Search & Filter -->
<div class="card card-custom mb-4">
    <div class="card-body p-3">
        <form method="GET" action="invoices.php" class="row g-2 align-items-center">
            <div class="col-md-7">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search by Invoice No, Customer Name, or Phone..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Payment Statuses</option>
                    <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid in Full</option>
                    <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partial Paid</option>
                    <option value="due" <?= $status_filter === 'due' ? 'selected' : '' ?>>Due / Udhar</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-dark w-100"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                <?php if (!empty($search) || !empty($status_filter)): ?>
                    <a href="invoices.php" class="btn btn-outline-secondary"><i class="fa-solid fa-rotate"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Invoices Table -->
<div class="card card-custom">
    <div class="table-responsive">
        <table class="table table-hover table-custom align-middle">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date & Time</th>
                    <th>Customer</th>
                    <th>Total Items</th>
                    <th>Grand Total</th>
                    <th>Paid Amount</th>
                    <th>Balance Due</th>
                    <th>Status</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5">
                            <i class="fa-solid fa-file-invoice-dollar text-muted fs-1 mb-3 d-block"></i>
                            <h5 class="fw-bold text-secondary">No Invoices Found!</h5>
                            <p class="text-muted small">Click "New Sale / Make Bill" to create your first customer receipt.</p>
                            <a href="pos.php" class="btn btn-success btn-sm mt-2">
                                <i class="fa-solid fa-cart-plus me-1"></i> Make First Bill
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-primary font-monospace"><?= htmlspecialchars($inv['invoice_no']) ?></span>
                            </td>

                            <td class="small text-muted">
                                <?= date('d-M-Y', strtotime($inv['created_at'])) ?><br>
                                <span class="text-secondary"><?= date('h:i A', strtotime($inv['created_at'])) ?></span>
                            </td>

                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($inv['customer_name'] ?: 'Walk-in Customer') ?></div>
                                <?php if (!empty($inv['customer_phone'])): ?>
                                    <div class="small text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($inv['customer_phone']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge bg-light text-secondary border"><?= $inv['total_items'] ?> Items</span>
                            </td>

                            <td class="fw-bold text-dark fs-6">
                                <?= format_currency($inv['grand_total']) ?>
                            </td>

                            <td class="fw-semibold text-success">
                                <?= format_currency($inv['paid_amount']) ?>
                            </td>

                            <td class="fw-bold <?= $inv['due_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= format_currency($inv['due_amount']) ?>
                            </td>

                            <td>
                                <?php if ($inv['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">PAID</span>
                                <?php elseif ($inv['payment_status'] === 'partial'): ?>
                                    <span class="badge bg-warning text-dark">PARTIAL</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">UDHAR / DUE</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center">
                                <?php if ($inv['due_amount'] > 0 && !empty($inv['customer_name'])): ?>
                                    <a href="customers_khata.php?search=<?= urlencode($inv['customer_name']) ?>" class="btn btn-outline-warning btn-sm me-1" title="Receive Payment in Khata">
                                        <i class="fa-solid fa-hand-holding-dollar"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="invoice_print.php?id=<?= $inv['id'] ?>&autoprint=1" target="_blank" class="btn btn-outline-success btn-sm me-1" title="Print Bill">
                                    <i class="fa-solid fa-print me-1"></i> Print
                                </a>
                                <a href="invoice_print.php?id=<?= $inv['id'] ?>" class="btn btn-outline-primary btn-sm" title="View Bill">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

