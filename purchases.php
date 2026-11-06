<?php
// purchases.php - Purchase History & Inward Stock Log
$page_title = "Purchases & Inward Stock";
require_once __DIR__ . '/includes/header.php';

// Search and Filter parameters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$supplier_filter = trim($_GET['supplier'] ?? '');

// Base Query
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(p.purchase_no LIKE ? OR p.supplier_name LIKE ? OR p.bilty_no LIKE ? OR p.notes LIKE ?)";
    $s_term = "%{$search}%";
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
}

if (!empty($status_filter) && in_array($status_filter, ['paid', 'partial', 'due'])) {
    $where[] = "p.payment_status = ?";
    $params[] = $status_filter;
}

if (!empty($supplier_filter)) {
    $where[] = "p.supplier_name = ?";
    $params[] = $supplier_filter;
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch Purchases
$purchases = [];
$stats = [
    'count' => 0,
    'total_weight_kg' => 0,
    'total_amount' => 0,
    'total_paid' => 0,
    'total_due' => 0
];

if (isset($pdo)) {
    try {
        // Overall Summary Statistics (ignoring pagination)
        $stat_stmt = $pdo->query("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(total_weight_kg), 0) as total_weight_kg,
                COALESCE(SUM(grand_total), 0) as total_amount,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due
            FROM purchases
        ");
        $stats = $stat_stmt->fetch() ?: $stats;

        // Fetch Purchases with item counts
        $query = "
            SELECT 
                p.*,
                (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.id) as item_count,
                (SELECT GROUP_CONCAT(CONCAT(pi.quantity, ' ', pi.purchase_unit, ' ', pi.product_name) SEPARATOR ', ') 
                 FROM purchase_items pi WHERE pi.purchase_id = p.id) as items_summary
            FROM purchases p
            {$where_sql}
            ORDER BY p.id DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $purchases = $stmt->fetchAll();

        // Get unique supplier list for dropdown
        $sup_stmt = $pdo->query("SELECT DISTINCT supplier_name FROM purchases WHERE supplier_name IS NOT NULL AND supplier_name != '' ORDER BY supplier_name ASC");
        $suppliers_list = $sup_stmt->fetchAll(PDO::FETCH_COLUMN);

    } catch (PDOException $e) {
        $error = "Failed to load purchases: " . $e->getMessage();
    }
}
?>

<!-- Header & Quick Actions -->
<div class="row mb-4 align-items-center">
    <div class="col-md-6 mb-2 mb-md-0">
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-truck-ramp-box text-primary me-2"></i>Purchases & Stock Inward
        </h3>
        <p class="text-muted mb-0">Record factory shipments, mill bilty entries, and track supplier payables.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="purchase_add.php" class="btn btn-success btn-lg shadow-sm fw-bold me-2">
            <i class="fa-solid fa-plus-circle me-1"></i> New Stock Inward
        </a>
        <a href="suppliers_khata.php" class="btn btn-outline-primary btn-lg shadow-sm">
            <i class="fa-solid fa-building-columns me-1"></i> Supplier Khata
        </a>
    </div>
</div>

<!-- Key Metric Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Inward Stock</span>
                <div class="stat-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-weight-hanging"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= number_format($stats['total_weight_kg'] / 1000, 2) ?> Ton</div>
            <div class="small text-muted mt-2">
                Total weight: <strong><?= number_format($stats['total_weight_kg'], 1) ?> KG</strong>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Purchases Value</span>
                <div class="stat-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= format_currency($stats['total_amount'], 0) ?></div>
            <div class="small text-muted mt-2">
                Across <strong><?= number_format($stats['count']) ?></strong> factory shipments
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-success">Total Amount Paid</span>
                <div class="stat-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-check-double"></i>
                </div>
            </div>
            <div class="stat-value text-success"><?= format_currency($stats['total_paid'], 0) ?></div>
            <div class="small text-muted mt-2">
                Paid to suppliers & freight
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100 <?= $stats['total_due'] > 0 ? 'border-danger' : '' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-danger fw-bold">Supplier Payables (Due)</span>
                <div class="stat-icon bg-danger-subtle text-danger">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
            </div>
            <div class="stat-value text-danger"><?= format_currency($stats['total_due'], 0) ?></div>
            <div class="small text-muted mt-2">
                <a href="suppliers_khata.php" class="text-danger fw-bold text-decoration-none">Manage Payables &rarr;</a>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-body p-3">
        <form method="GET" action="purchases.php" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fa-solid fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search Purchase #, Supplier, Bilty #..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-md-3">
                <select name="supplier" class="form-select">
                    <option value="">All Suppliers / Mills</option>
                    <?php if (!empty($suppliers_list)): ?>
                        <?php foreach ($suppliers_list as $sup): ?>
                            <option value="<?= htmlspecialchars($sup) ?>" <?= $supplier_filter === $sup ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sup) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Payment Statuses</option>
                    <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Fully Paid</option>
                    <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="due" <?= $status_filter === 'due' ? 'selected' : '' ?>>Fully Due (Unpaid)</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="fa-solid fa-filter me-1"></i> Filter
                </button>
                <?php if (!empty($search) || !empty($status_filter) || !empty($supplier_filter)): ?>
                    <a href="purchases.php" class="btn btn-outline-secondary" title="Reset Filters">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Purchases List Table -->
<div class="card card-custom shadow-sm border-0">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="fa-solid fa-list-check text-primary me-2"></i>Stock Inward Records
            <span class="badge bg-secondary ms-2"><?= count($purchases) ?> entries</span>
        </h5>
        <div>
            <a href="purchase_add.php" class="btn btn-sm btn-success">
                <i class="fa-solid fa-plus me-1"></i> Add Inward
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Purchase #</th>
                    <th>Date & Time</th>
                    <th>Supplier / Mill</th>
                    <th>Bilty #</th>
                    <th>Inward Weight</th>
                    <th>Items</th>
                    <th class="text-end">Total Amount</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance Due</th>
                    <th class="text-center">Status</th>
                    <th class="text-center pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <tr>
                        <td colspan="11" class="text-center py-5">
                            <div class="text-muted">
                                <i class="fa-solid fa-truck-ramp-box fa-3x mb-3 text-secondary opacity-50"></i>
                                <h5>No Purchases Found</h5>
                                <p class="mb-3">No stock inward records match your criteria.</p>
                                <a href="purchase_add.php" class="btn btn-primary">
                                    <i class="fa-solid fa-plus me-1"></i> Record First Purchase
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($purchases as $p): ?>
                        <tr>
                            <td class="ps-3 fw-bold text-dark">
                                <a href="purchase_print.php?id=<?= $p['id'] ?>" class="text-decoration-none text-primary">
                                    <?= htmlspecialchars($p['purchase_no']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= date('d M, Y', strtotime($p['created_at'])) ?></div>
                                <small class="text-muted"><?= date('h:i A', strtotime($p['created_at'])) ?></small>
                            </td>
                            <td>
                                <div class="fw-bold text-dark">
                                    <a href="supplier_ledger.php?name=<?= urlencode($p['supplier_name']) ?>" class="text-decoration-none text-dark">
                                        <?= htmlspecialchars($p['supplier_name']) ?>
                                    </a>
                                </div>
                                <?php if (!empty($p['supplier_phone'])): ?>
                                    <small class="text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($p['supplier_phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['bilty_no'])): ?>
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <i class="fa-solid fa-receipt me-1 text-secondary"></i><?= htmlspecialchars($p['bilty_no']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6">
                                    <?= number_format($p['total_weight_kg'] / 1000, 3) ?> Ton
                                </span>
                                <div class="small text-muted mt-1"><?= number_format($p['total_weight_kg'], 1) ?> KG</div>
                            </td>
                            <td style="max-width: 220px;">
                                <div class="text-truncate small text-dark" title="<?= htmlspecialchars($p['items_summary'] ?? '') ?>">
                                    <?= htmlspecialchars($p['items_summary'] ?? 'Items recorded') ?>
                                </div>
                                <small class="text-muted"><?= $p['item_count'] ?> item(s)</small>
                            </td>
                            <td class="text-end fw-bold text-dark fs-6">
                                <?= format_currency($p['grand_total'], 2) ?>
                                <?php if ($p['extra_cost'] > 0): ?>
                                    <div class="small text-muted" title="Carriage / Freight included">+<?= format_currency($p['extra_cost'], 0) ?> Freight</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-success fw-semibold">
                                <?= format_currency($p['paid_amount'], 2) ?>
                            </td>
                            <td class="text-end fw-bold <?= $p['due_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= format_currency($p['due_amount'], 2) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($p['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="fa-solid fa-circle-check me-1"></i> Paid
                                    </span>
                                <?php elseif ($p['payment_status'] === 'partial'): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                                        <i class="fa-solid fa-circle-half-stroke me-1"></i> Partial
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                        <i class="fa-solid fa-circle-exclamation me-1"></i> Due
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-3">
                                <div class="btn-group">
                                    <a href="purchase_print.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View & Print Inward Note">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                    <a href="supplier_ledger.php?name=<?= urlencode($p['supplier_name']) ?>" class="btn btn-sm btn-outline-primary" title="Supplier Khata Ledger">
                                        <i class="fa-solid fa-book-bookmark"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
