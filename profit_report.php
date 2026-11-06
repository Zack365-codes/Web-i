<?php
// profit_report.php - Financial Profit & Margin Analytics (Password Protected - Super Admin Only)
$page_title = "Profit Report & Financial Analytics";
require_once __DIR__ . '/includes/header.php';

// Strictly restrict access to Super Admin only
require_super_admin();

// Check if profit is unlocked
$unlocked = is_profit_unlocked();

// Fetch overall financials if unlocked
$total_realized_profit = 0;
$total_sales_revenue = 0;
$total_invoices = 0;
$total_stock_cost = 0;
$total_stock_value = 0;
$total_projected_profit = 0;

$category_profits = [];
$top_profitable_items = [];

if (isset($pdo)) {
    try {
        // Sales Profit & Revenue
        $s_stmt = $pdo->query("SELECT COUNT(*) as inv_count, COALESCE(SUM(grand_total), 0) as total_rev, COALESCE(SUM(total_profit), 0) as total_prof FROM sales");
        $s_data = $s_stmt->fetch();
        $total_invoices = (int)$s_data['inv_count'];
        $total_sales_revenue = (float)$s_data['total_rev'];
        $total_realized_profit = (float)$s_data['total_prof'];

        // Inventory Stock & Projected Profit
        $p_stmt = $pdo->query("SELECT * FROM products");
        $all_prods = $p_stmt->fetchAll();

        foreach ($all_prods as $p) {
            $stock_kg = (float)$p['total_stock_kg'];
            $cost_kg  = (float)$p['cost_price_per_kg'];
            $sell_kg  = (float)$p['sell_price_per_kg'];
            $sell_pc  = (float)$p['sell_price_per_piece'];
            $w_piece  = (float)($p['weight_per_piece'] ?: ($p['weight_per_foot'] * ($p['standard_length_feet'] ?: 20)));

            $cost_val = $stock_kg * $cost_kg;
            $sell_val = 0;
            if ($sell_kg > 0) {
                $sell_val = $stock_kg * $sell_kg;
            } elseif ($sell_pc > 0 && $w_piece > 0) {
                $sell_val = ($stock_kg / $w_piece) * $sell_pc;
            }

            $total_stock_cost += $cost_val;
            $total_stock_value += $sell_val;

            // Track category
            $cat = $p['category'];
            if (!isset($category_profits[$cat])) {
                $category_profits[$cat] = ['cost' => 0, 'value' => 0, 'stock_kg' => 0];
            }
            $category_profits[$cat]['cost'] += $cost_val;
            $category_profits[$cat]['value'] += $sell_val;
            $category_profits[$cat]['stock_kg'] += $stock_kg;
        }

        $total_projected_profit = $total_stock_value - $total_stock_cost;

        // Top Sold Items by Realized Profit
        $top_stmt = $pdo->query("
            SELECT product_name, sale_unit, SUM(quantity) as total_qty, SUM(subtotal) as total_sales, SUM(item_profit) as total_profit
            FROM sale_items
            GROUP BY product_id, product_name, sale_unit
            ORDER BY total_profit DESC
            LIMIT 10
        ");
        $top_profitable_items = $top_stmt->fetchAll();

    } catch (PDOException $e) {
        $error = "Failed to load financial report: " . $e->getMessage();
    }
}
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-chart-line text-success me-2"></i>Profit & Financial Reports
        </h3>
        <p class="text-muted mb-0">Protected dashboard showing realized sales profit, margins, and inventory valuation.</p>
    </div>
    <div class="col-md-5 text-md-end">
        <?php if ($unlocked): ?>
            <a href="?lock_profit=1" class="btn btn-outline-danger me-2 shadow-sm">
                <i class="fa-solid fa-lock me-1"></i> Lock Profit Immediately
            </a>
        <?php else: ?>
            <button type="button" class="btn btn-warning shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#profitPinModal">
                <i class="fa-solid fa-key me-1"></i> Enter PIN to Unlock
            </button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-chart-pie me-1"></i> Dashboard
        </a>
    </div>
</div>

<?php if (!$unlocked): ?>
    <!-- Locked Privacy Gate -->
    <div class="card card-custom py-5 my-3 shadow-sm border-warning text-center">
        <div class="card-body py-4">
            <div class="mb-3">
                <div class="d-inline-flex align-items-center justify-content-center bg-warning-subtle text-warning rounded-circle" style="width: 80px; height: 80px; font-size: 2.5rem;">
                    <i class="fa-solid fa-shield-halved text-dark"></i>
                </div>
            </div>
            <h4 class="fw-bold text-dark mb-2">Profit Reports Are Password Protected</h4>
            <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                Financial profit reports and purchase costs are shielded to maintain privacy at the counter. Please enter your secret PIN to view this tab.
            </p>

            <div style="max-width: 320px; margin: 0 auto;">
                <form method="POST" action="">
                    <div class="input-group input-group-lg mb-3">
                        <span class="input-group-text bg-white"><i class="fa-solid fa-key text-muted"></i></span>
                        <input type="password" name="profit_pin" class="form-control text-center fw-bold" placeholder="Enter PIN (e.g. 1234)" required autofocus maxlength="10">
                    </div>
                    <button type="submit" name="verify_profit_pin" class="btn btn-warning btn-lg w-100 fw-bold shadow-sm">
                        <i class="fa-solid fa-lock-open me-2"></i> Reveal Profit Report
                    </button>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>

    <!-- Unlocked: Full Financial Report -->

    <!-- 4 Big Financial KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- Realized Net Profit -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card shadow-sm h-100 border-success" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="stat-label text-success fw-bold">Realized Net Profit</span>
                    <div class="stat-icon bg-success text-white">
                        <i class="fa-solid fa-arrow-trend-up"></i>
                    </div>
                </div>
                <div class="stat-value text-success"><?= format_currency($total_realized_profit) ?></div>
                <div class="small text-muted mt-2">
                    Actual profit from <strong><?= $total_invoices ?></strong> completed sales
                </div>
            </div>
        </div>

        <!-- Total Sales Revenue -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="stat-label">Total Sales Revenue</span>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-sack-dollar"></i>
                    </div>
                </div>
                <div class="stat-value text-dark"><?= format_currency($total_sales_revenue) ?></div>
                <div class="small text-muted mt-2">
                    <?php 
                    $overall_sales_margin = $total_sales_revenue > 0 ? ($total_realized_profit / $total_sales_revenue) * 100 : 0;
                    ?>
                    Realized Margin: <strong class="text-success"><?= number_format($overall_sales_margin, 1) ?>%</strong>
                </div>
            </div>
        </div>

        <!-- Inventory Cost (Investment) -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="stat-label">Current Stock Cost</span>
                    <div class="stat-icon bg-danger-subtle text-danger">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="stat-value text-danger"><?= format_currency($total_stock_cost) ?></div>
                <div class="small text-muted mt-2">
                    Total investment in godown/store
                </div>
            </div>
        </div>

        <!-- Expected Stock Profit -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="stat-label">Expected Stock Profit</span>
                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="fa-solid fa-chart-pie"></i>
                    </div>
                </div>
                <div class="stat-value text-primary"><?= format_currency($total_projected_profit) ?></div>
                <div class="small text-muted mt-2">
                    Potential profit if all stock is sold
                </div>
            </div>
        </div>
    </div>

    <!-- Category Breakdown Table -->
    <div class="card card-custom mb-4">
        <div class="card-header card-header-clean fw-bold">
            <i class="fa-solid fa-layer-group text-primary me-2"></i>Profit & Stock Breakdown by Category
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Stock Weight</th>
                        <th>Purchase Cost (Lagat)</th>
                        <th>Retail Value (Farokht)</th>
                        <th>Expected Profit</th>
                        <th>Profit Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($category_profits as $cname => $cdata): 
                        $cprofit = $cdata['value'] - $cdata['cost'];
                        $cmargin = $cdata['cost'] > 0 ? ($cprofit / $cdata['cost']) * 100 : 0;
                    ?>
                        <tr>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($cname) ?></td>
                            <td><?= number_format($cdata['stock_kg'], 1) ?> KG</td>
                            <td class="text-danger fw-semibold"><?= format_currency($cdata['cost']) ?></td>
                            <td class="fw-semibold"><?= format_currency($cdata['value']) ?></td>
                            <td class="fw-bold <?= $cprofit >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= ($cprofit >= 0 ? '+' : '') . format_currency($cprofit) ?>
                            </td>
                            <td>
                                <span class="badge <?= $cprofit >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> px-2 py-1">
                                    <?= number_format($cmargin, 1) ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Profitable Items Table -->
    <div class="card card-custom">
        <div class="card-header card-header-clean fw-bold">
            <i class="fa-solid fa-trophy text-warning me-2"></i>Top Sold Products by Realized Profit
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Name</th>
                        <th>Total Quantity Sold</th>
                        <th>Total Sales Value</th>
                        <th>Net Profit Generated</th>
                        <th>Profit Contribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_profitable_items)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                No sales transactions recorded yet. Make bills in POS to see profit analytics.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($top_profitable_items as $tidx => $ti): 
                            $share = $total_realized_profit > 0 ? ($ti['total_profit'] / $total_realized_profit) * 100 : 0;
                        ?>
                            <tr>
                                <td><?= $tidx + 1 ?></td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($ti['product_name']) ?></td>
                                <td><?= number_format($ti['total_qty'], 2) ?> <?= strtoupper($ti['sale_unit']) ?></td>
                                <td><?= format_currency($ti['total_sales']) ?></td>
                                <td class="fw-bold text-success">+<?= format_currency($ti['total_profit']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?= min(100, max(0, $share)) ?>%"></div>
                                        </div>
                                        <span class="small text-muted"><?= number_format($share, 1) ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

