<?php
// returns.php - Sales Returns History & Management
$page_title = "Sales Returns & Maal Wapsi";
require_once __DIR__ . '/includes/header.php';

// Search and Filter
$search = trim($_GET['search'] ?? '');
$method_filter = trim($_GET['method'] ?? '');

$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(r.return_no LIKE ? OR r.invoice_no LIKE ? OR r.customer_name LIKE ? OR r.customer_phone LIKE ?)";
    $s_term = "%{$search}%";
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
}

if (!empty($method_filter) && in_array($method_filter, ['Cash', 'Deduct from Khata', 'Credit Balance'])) {
    $where[] = "r.refund_method = ?";
    $params[] = $method_filter;
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch Summary Stats
$stats = [
    'today_count'        => 0,
    'today_refund'       => 0,
    'today_weight_kg'    => 0,
    'all_time_refund'    => 0,
    'all_time_weight_kg' => 0
];

$returns = [];

if (isset($pdo)) {
    try {
        // Today's Stats
        $today_stmt = $pdo->query("
            SELECT 
                COUNT(*) as today_count,
                COALESCE(SUM(total_refund_amount), 0) as today_refund,
                COALESCE(SUM(total_returned_weight_kg), 0) as today_weight_kg
            FROM sales_returns 
            WHERE DATE(created_at) = CURDATE()
        ");
        $today_res = $today_stmt->fetch();
        if ($today_res) {
            $stats['today_count'] = (int)$today_res['today_count'];
            $stats['today_refund'] = (float)$today_res['today_refund'];
            $stats['today_weight_kg'] = (float)$today_res['today_weight_kg'];
        }

        // All-Time Stats
        $all_stmt = $pdo->query("
            SELECT 
                COALESCE(SUM(total_refund_amount), 0) as all_time_refund,
                COALESCE(SUM(total_returned_weight_kg), 0) as all_time_weight_kg
            FROM sales_returns
        ");
        $all_res = $all_stmt->fetch();
        if ($all_res) {
            $stats['all_time_refund'] = (float)$all_res['all_time_refund'];
            $stats['all_time_weight_kg'] = (float)$all_res['all_time_weight_kg'];
        }

        // Fetch Returns List with item count
        $query = "
            SELECT 
                r.*,
                (SELECT COUNT(*) FROM sales_return_items sri WHERE sri.return_id = r.id) as item_count,
                (SELECT GROUP_CONCAT(CONCAT(sri.quantity_returned, ' ', sri.sale_unit, ' ', sri.product_name) SEPARATOR ', ')
                 FROM sales_return_items sri WHERE sri.return_id = r.id) as items_summary
            FROM sales_returns r
            {$where_sql}
            ORDER BY r.id DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $returns = $stmt->fetchAll();

    } catch (PDOException $e) {
        $error = "Failed to load sales returns: " . $e->getMessage();
    }
}
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-6">
        <h3 class="fw-bold mb-1"><i class="fa-solid fa-rotate-left text-danger me-2"></i>Sales Returns (Maal Wapsi)</h3>
        <p class="text-muted mb-0">Manage customer returns, stock restorations, and credit notes.</p>
    </div>
    <div class="col-md-6 text-md-end mt-2 mt-md-0">
        <a href="return_add.php" class="btn btn-danger btn-lg shadow-sm fw-bold">
            <i class="fa-solid fa-plus me-1"></i> New Sales Return
        </a>
    </div>
</div>

<!-- Metrics Cards -->
<div class="row g-3 mb-4">
    <!-- Today's Returns -->
    <div class="col-md-3">
        <div class="card card-custom p-3 border-danger shadow-sm">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">Today's Returns</span>
                    <h3 class="fw-bold mb-0 text-danger"><?= $stats['today_count'] ?></h3>
                    <small class="text-muted">returns processed today</small>
                </div>
                <div class="p-3 bg-danger-subtle text-danger rounded-circle">
                    <i class="fa-solid fa-rotate-left fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Refund Amount -->
    <div class="col-md-3">
        <div class="card card-custom p-3 border-danger shadow-sm">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">Today's Refunds Settled</span>
                    <h3 class="fw-bold mb-0 text-dark">Rs. <?= number_format($stats['today_refund'], 0) ?></h3>
                    <small class="text-muted">cash / khata adjustments</small>
                </div>
                <div class="p-3 bg-danger-subtle text-danger rounded-circle">
                    <i class="fa-solid fa-money-bill-transfer fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Stock Restored -->
    <div class="col-md-3">
        <div class="card card-custom p-3 border-success shadow-sm">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">Today's Restored Stock</span>
                    <h3 class="fw-bold mb-0 text-success"><?= number_format($stats['today_weight_kg'], 1) ?> KG</h3>
                    <small class="text-muted">added back to inventory</small>
                </div>
                <div class="p-3 bg-success-subtle text-success rounded-circle">
                    <i class="fa-solid fa-boxes-stacked fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- All-Time Refunds -->
    <div class="col-md-3">
        <div class="card card-custom p-3 border-secondary shadow-sm">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">All-Time Total Returns</span>
                    <h3 class="fw-bold mb-0 text-secondary">Rs. <?= number_format($stats['all_time_refund'], 0) ?></h3>
                    <small class="text-muted"><?= number_format($stats['all_time_weight_kg'], 1) ?> KG total returned</small>
                </div>
                <div class="p-3 bg-secondary-subtle text-secondary rounded-circle">
                    <i class="fa-solid fa-clock-rotate-left fs-4"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card card-custom mb-4 shadow-sm">
    <div class="card-body p-3">
        <form method="GET" action="returns.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search by Return #, Invoice #, Customer..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="method" class="form-select">
                    <option value="">All Settlement Methods</option>
                    <option value="Cash" <?= $method_filter === 'Cash' ? 'selected' : '' ?>>Cash (Naqd Wapsi)</option>
                    <option value="Deduct from Khata" <?= $method_filter === 'Deduct from Khata' ? 'selected' : '' ?>>Deduct from Khata (Udhar Adjustment)</option>
                    <option value="Credit Balance" <?= $method_filter === 'Credit Balance' ? 'selected' : '' ?>>Credit Balance</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold">Filter</button>
                <?php if (!empty($search) || !empty($method_filter)): ?>
                    <a href="returns.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-xmark"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Returns Table -->
<div class="card card-custom shadow-sm">
    <div class="card-header card-header-clean d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fa-solid fa-list-check text-danger me-2"></i>Sales Returns Log</span>
        <span class="badge bg-danger"><?= count($returns) ?> Returns</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom align-middle mb-0">
            <thead>
                <tr>
                    <th>Return Voucher #</th>
                    <th>Date & Time</th>
                    <th>Original Invoice</th>
                    <th>Customer Name</th>
                    <th>Items Returned</th>
                    <th>Restored Stock</th>
                    <th>Refund Settled</th>
                    <th>Method</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-rotate-left fs-2 mb-2 d-block text-secondary"></i>
                            No sales returns found. Click <strong>"New Sales Return"</strong> to process goods return.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($returns as $ret): ?>
                        <tr>
                            <td class="fw-bold text-danger">
                                <a href="return_print.php?id=<?= $ret['id'] ?>" class="text-decoration-none text-danger fw-bold">
                                    <i class="fa-solid fa-receipt me-1"></i><?= htmlspecialchars($ret['return_no']) ?>
                                </a>
                            </td>
                            <td class="small text-muted">
                                <?= date('d M Y, h:i A', strtotime($ret['created_at'])) ?>
                            </td>
                            <td>
                                <?php if ($ret['sale_id']): ?>
                                    <a href="invoice_print.php?id=<?= $ret['sale_id'] ?>" target="_blank" class="badge bg-light text-primary border text-decoration-none">
                                        <?= htmlspecialchars($ret['invoice_no']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Walk-in</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="text-dark"><?= htmlspecialchars($ret['customer_name'] ?: 'Walk-in') ?></strong>
                                <?php if (!empty($ret['customer_phone'])): ?>
                                    <div class="small text-muted"><?= htmlspecialchars($ret['customer_phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary border"><?= $ret['item_count'] ?> Item(s)</span>
                                <div class="small text-muted text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($ret['items_summary']) ?>">
                                    <?= htmlspecialchars($ret['items_summary']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-success-subtle text-success fw-bold px-2 py-1">
                                    +<?= number_format($ret['total_returned_weight_kg'], 2) ?> KG
                                </span>
                            </td>
                            <td class="fw-bold text-danger fs-6">
                                Rs. <?= number_format($ret['total_refund_amount'], 2) ?>
                            </td>
                            <td>
                                <?php if ($ret['refund_method'] === 'Cash'): ?>
                                    <span class="badge bg-danger">Cash Refund</span>
                                <?php elseif ($ret['refund_method'] === 'Deduct from Khata'): ?>
                                    <span class="badge bg-primary">Khata Adjusted</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Credit Balance</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="return_print.php?id=<?= $ret['id'] ?>&autoprint=1" target="_blank" class="btn btn-outline-danger btn-sm me-1" title="Print Credit Note">
                                    <i class="fa-solid fa-print"></i>
                                </a>
                                <a href="return_print.php?id=<?= $ret['id'] ?>" class="btn btn-outline-primary btn-sm" title="View Credit Note">
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

