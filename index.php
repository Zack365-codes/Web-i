<?php
// index.php - Dashboard
$page_title = "Dashboard - Overview & Profit";
require_once __DIR__ . '/includes/header.php';

// Initialize stats
$total_products = 0;
$total_stock_kg = 0;
$total_cost_value = 0;
$total_retail_value = 0;
$total_projected_profit = 0;
$low_stock_items = [];
$recent_products = [];

// Sales & Billing Stats
$today_sales_total = 0;
$today_invoices_count = 0;
$total_invoices_count = 0;
$total_realized_profit = 0;
$recent_invoices = [];

if (isset($pdo)) {
    try {
        // Fetch all products for statistical calculations
        $stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
        $all_products = $stmt->fetchAll();

        $total_products = count($all_products);
        $recent_products = array_slice($all_products, 0, 5);

        foreach ($all_products as $p) {
            $stock_kg = (float)$p['total_stock_kg'];
            $cost_kg  = (float)$p['cost_price_per_kg'];
            $sell_kg  = (float)$p['sell_price_per_kg'];
            $alert_kg = (float)$p['alert_quantity_kg'];
            $sell_pc  = (float)$p['sell_price_per_piece'];
            $sell_ft  = (float)$p['sell_price_per_foot'];
            $w_foot   = (float)$p['weight_per_foot'];
            $w_piece  = (float)($p['weight_per_piece'] ?: ($w_foot * ($p['standard_length_feet'] ?: 20)));

            $item_cost_val = $stock_kg * $cost_kg;
            $item_sell_val = 0;

            if ($sell_kg > 0) {
                $item_sell_val = $stock_kg * $sell_kg;
            } elseif ($sell_pc > 0 && $w_piece > 0) {
                $item_sell_val = ($stock_kg / $w_piece) * $sell_pc;
            } elseif ($sell_ft > 0 && $w_foot > 0) {
                $item_sell_val = ($stock_kg / $w_foot) * $sell_ft;
            } else {
                $item_sell_val = $item_cost_val;
            }

            $total_stock_kg += $stock_kg;
            $total_cost_value += $item_cost_val;
            $total_retail_value += $item_sell_val;

            if ($stock_kg <= $alert_kg) {
                $low_stock_items[] = $p;
            }
        }

        $total_projected_profit = max(0, $total_retail_value - $total_cost_value);

        // Fetch sales stats
        $sales_today_stmt = $pdo->query("SELECT COALESCE(SUM(grand_total), 0) as today_rev, COUNT(*) as today_count FROM sales WHERE DATE(created_at) = CURDATE()");
        $today_data = $sales_today_stmt->fetch();
        $today_sales_total = (float)$today_data['today_rev'];
        $today_invoices_count = (int)$today_data['today_count'];

        $sales_all_stmt = $pdo->query("SELECT COUNT(*) as total_count, COALESCE(SUM(total_profit), 0) as total_profit, COALESCE(SUM(due_amount), 0) as total_due FROM sales");
        $all_sales_data = $sales_all_stmt->fetch();
        $total_invoices_count = (int)$all_sales_data['total_count'];
        $total_realized_profit = (float)$all_sales_data['total_profit'];
        $total_market_due = (float)$all_sales_data['total_due'];

        // Recent Invoices
        $rec_inv_stmt = $pdo->query("SELECT * FROM sales ORDER BY id DESC LIMIT 5");
        $recent_invoices = $rec_inv_stmt->fetchAll();

    } catch (PDOException $e) {
        $error = "Failed to load dashboard stats: " . $e->getMessage();
    }
}
?>

<!-- Top Welcome & Quick Actions -->
<div class="row mb-4 align-items-center">
    <div class="col-md-6 mb-2 mb-md-0">
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-chart-pie text-primary me-2"></i>Iron Store POS & Dashboard
        </h3>
        <p class="text-muted mb-0">Overview of inventory, sales revenue, realized profits, and billing.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="pos.php" class="btn btn-success btn-lg shadow-sm me-2 fw-bold">
            <i class="fa-solid fa-cash-register me-2"></i> Make New Bill (POS)
        </a>
        <a href="product_add.php" class="btn btn-outline-primary">
            <i class="fa-solid fa-plus me-1"></i> Add Product
        </a>
    </div>
</div>

<!-- Sales Highlights (Today & Total Realized Profit) -->
<div class="row g-3 mb-4">
    <!-- Today's Sales -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border-color: #86efac;">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-success fw-bold">Today's Sales Revenue</span>
                <div class="stat-icon bg-success text-white">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
            </div>
            <div class="stat-value text-success"><?= format_currency($today_sales_total, 0) ?></div>
            <div class="small text-muted mt-2">
                <i class="fa-solid fa-receipt me-1"></i> <strong><?= $today_invoices_count ?></strong> bills created today
            </div>
        </div>
    </div>

    <!-- Total Invoices / Bills -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Invoices Created</span>
                <div class="stat-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-file-invoice"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= number_format($total_invoices_count) ?></div>
            <div class="small text-muted mt-2">
                <a href="invoices.php" class="text-decoration-none">View All Bills &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Total Realized Net Profit (Shielded) -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-success fw-bold">Realized Net Profit</span>
                <div class="stat-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-arrow-trend-up"></i>
                </div>
            </div>
            <div class="stat-value"><?= show_profit($total_realized_profit, false, 0) ?></div>
            <div class="small text-muted mt-2">
                <a href="profit_report.php" class="text-decoration-none">View Profit Report &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Stock Projected Profit (Shielded) -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Stock Expected Profit</span>
                <div class="stat-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-chart-pie text-dark"></i>
                </div>
            </div>
            <div class="stat-value"><?= show_profit($total_projected_profit, false, 0) ?></div>
            <div class="small text-muted mt-2">
                Stock: <strong><?= number_format($total_stock_kg, 1) ?> KG</strong> (~<?= number_format($total_stock_kg / 1000, 2) ?> Ton)
            </div>
        </div>
    </div>
</div>

<!-- Market Udhar / Credit Ledger Alert Banner -->
<?php if ($total_market_due > 0): ?>
    <div class="card card-custom mb-4 border-danger" style="background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);">
        <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center">
                <div class="stat-icon bg-danger text-white me-3" style="width: 44px; height: 44px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
                <div>
                    <div class="small text-danger fw-bold text-uppercase">Total Market Udhar (Receivables Pending)</div>
                    <div class="fs-4 fw-bold text-danger"><?= format_currency($total_market_due, 2) ?></div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="customers_khata.php" class="btn btn-danger btn-sm fw-bold px-3 shadow-sm">
                    <i class="fa-solid fa-book-bookmark me-1"></i> Open Customer Khata
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Recent Invoices with 1-Click Print -->
<div class="card card-custom mb-4 border-success">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <span class="fw-bold fs-6 text-dark">
            <i class="fa-solid fa-clock-rotate-left text-success me-2"></i>Recent Customer Invoices
        </span>
        <div class="d-flex gap-2">
            <a href="pos.php" class="btn btn-success btn-sm fw-bold">
                <i class="fa-solid fa-plus me-1"></i> Make Bill
            </a>
            <a href="invoices.php" class="btn btn-outline-secondary btn-sm">All Invoices</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom align-middle mb-0">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Customer Name</th>
                    <th>Grand Total</th>
                    <th>Paid Amount</th>
                    <th>Due (Udhar)</th>
                    <th>Status</th>
                    <th class="text-center">1-Click Print</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_invoices)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fa-solid fa-receipt fs-3 mb-2 d-block text-secondary"></i>
                            No sales created yet. Click "Make Bill" to generate your first invoice!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_invoices as $ri): ?>
                        <tr>
                            <td class="fw-bold font-monospace text-primary"><?= htmlspecialchars($ri['invoice_no']) ?></td>
                            <td class="small text-muted"><?= date('d-M-Y h:i A', strtotime($ri['created_at'])) ?></td>
                            <td class="fw-semibold text-dark"><?= htmlspecialchars($ri['customer_name'] ?: 'Walk-in Customer') ?></td>
                            <td class="fw-bold text-dark"><?= format_currency($ri['grand_total']) ?></td>
                            <td class="text-success fw-semibold"><?= format_currency($ri['paid_amount']) ?></td>
                            <td class="fw-bold <?= $ri['due_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= format_currency($ri['due_amount']) ?>
                            </td>
                            <td>
                                <?php if ($ri['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">PAID</span>
                                <?php elseif ($ri['payment_status'] === 'partial'): ?>
                                    <span class="badge bg-warning text-dark">PARTIAL</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">DUE</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="invoice_print.php?id=<?= $ri['id'] ?>&autoprint=1" target="_blank" class="btn btn-success btn-sm shadow-sm" title="Print Invoice Now">
                                    <i class="fa-solid fa-print me-1"></i> Print Bill
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Low Stock Alerts Section (If any) -->
<?php if (!empty($low_stock_items)): ?>
    <div class="card card-custom mb-4 border-warning">
        <div class="card-header bg-warning-subtle text-dark fw-bold d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Low Stock Warnings</span>
            <span class="badge bg-danger"><?= count($low_stock_items) ?> Items</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Alert Threshold</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($low_stock_items as $low): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($low['product_name']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($low['category']) ?></span></td>
                            <td class="text-danger fw-bold"><?= format_weight($low['total_stock_kg']) ?></td>
                            <td><?= number_format($low['alert_quantity_kg'], 1) ?> KG</td>
                            <td>
                                <a href="product_edit.php?id=<?= $low['id'] ?>" class="btn btn-outline-warning btn-sm">
                                    <i class="fa-solid fa-plus me-1"></i> Update Stock
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
