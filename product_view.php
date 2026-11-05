<?php
// product_view.php - Read / View Product Details & Audit
$page_title = "Product Details";
require_once __DIR__ . '/includes/header.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0 || !isset($pdo)) {
    set_flash('danger', 'Invalid product ID.');
    echo "<script>window.location.href = 'products.php';</script>";
    exit;
}

// Fetch product details
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('danger', 'Product not found.');
    echo "<script>window.location.href = 'products.php';</script>";
    exit;
}

// Calculations
$cost_kg = (float)$product['cost_price_per_kg'];
$sell_kg = (float)$product['sell_price_per_kg'];
$sell_foot = (float)$product['sell_price_per_foot'];
$sell_piece = (float)$product['sell_price_per_piece'];

$weight_foot = (float)$product['weight_per_foot'];
$standard_length = (float)($product['standard_length_feet'] ?: 20);
$weight_piece = (float)($product['weight_per_piece'] ?: ($weight_foot * $standard_length));
$stock_kg = (float)$product['total_stock_kg'];
$alert_kg = (float)$product['alert_quantity_kg'];

// Profit Margins
$profit_kg = calc_profit_kg($cost_kg, $sell_kg);
$margin_kg = calc_margin_percent($cost_kg, $sell_kg);

$cost_foot = calc_cost_foot($cost_kg, $weight_foot);
$profit_foot = calc_profit_foot($cost_kg, $weight_foot, $sell_foot);
$margin_foot = calc_margin_percent($cost_foot, $sell_foot);

$cost_piece = $weight_piece * $cost_kg;
$profit_piece = $sell_piece - $cost_piece;
$margin_piece = calc_margin_percent($cost_piece, $sell_piece);

// Stock quantities
$pieces_in_stock = $weight_piece > 0 ? ($stock_kg / $weight_piece) : 0;
$feet_in_stock = $weight_foot > 0 ? ($stock_kg / $weight_foot) : 0;

$total_cost_val = $stock_kg * $cost_kg;
$total_retail_val = $sell_kg > 0 ? ($stock_kg * $sell_kg) : ($sell_piece > 0 && $weight_piece > 0 ? ($pieces_in_stock * $sell_piece) : 0);
$total_expected_profit = $total_retail_val - $total_cost_val;

// Fetch sales history for this specific product
$sales_history = [];
try {
    $sh_stmt = $pdo->prepare("
        SELECT si.*, s.invoice_no, s.customer_name, s.created_at as sale_date, s.payment_status
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE si.product_id = ?
        ORDER BY si.id DESC
        LIMIT 10
    ");
    $sh_stmt->execute([$id]);
    $sales_history = $sh_stmt->fetchAll();
} catch (PDOException $e) {
    // Continue gracefully
}
?>

<!-- Breadcrumb & Action Header -->
<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="products.php" class="text-decoration-none">Products</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($product['product_name']) ?></li>
            </ol>
        </nav>
        <h3 class="fw-bold mb-1 text-dark">
            <?= htmlspecialchars($product['product_name']) ?>
            <span class="badge bg-primary-subtle text-primary border ms-2 fs-6"><?= htmlspecialchars($product['category']) ?></span>
        </h3>
        <p class="text-muted mb-0 font-monospace">Code: <?= htmlspecialchars($product['product_code']) ?></p>
    </div>

    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="product_edit.php?id=<?= $product['id'] ?>" class="btn btn-primary shadow-sm me-2">
            <i class="fa-solid fa-pen-to-square me-1"></i> Edit Product
        </a>
        <a href="pos.php" class="btn btn-success shadow-sm me-2">
            <i class="fa-solid fa-cart-shopping me-1"></i> Sell This Item
        </a>
        <a href="products.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to List
        </a>
    </div>
</div>

<div class="row g-4 mb-4">
    
    <!-- Left Column: Specs & Rates -->
    <div class="col-lg-8">
        
        <!-- Physical Specs Card -->
        <div class="card card-custom mb-4">
            <div class="card-header card-header-clean fw-bold">
                <i class="fa-solid fa-ruler-combined text-primary me-2"></i>Physical Specifications & Weights
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-sm-4 text-center p-3 bg-light rounded-3 border">
                        <div class="text-muted small mb-1">Weight of 1 Full Piece:</div>
                        <div class="fs-4 fw-bold text-primary"><?= number_format($weight_piece, 2) ?> <span class="fs-6">KG</span></div>
                        <div class="small text-muted">Per Bar / Piece</div>
                    </div>

                    <div class="col-sm-4 text-center p-3 bg-light rounded-3 border">
                        <div class="text-muted small mb-1">1 Foot Weight:</div>
                        <div class="fs-4 fw-bold text-dark"><?= number_format($weight_foot, 4) ?> <span class="fs-6">KG</span></div>
                        <div class="small text-muted">Per Linear Foot</div>
                    </div>

                    <div class="col-sm-4 text-center p-3 bg-light rounded-3 border">
                        <div class="text-muted small mb-1">Standard Length:</div>
                        <div class="fs-4 fw-bold text-secondary"><?= number_format($standard_length, 1) ?> <span class="fs-6">Feet</span></div>
                        <div class="small text-muted">Standard Bar Length</div>
                    </div>

                    <?php if (!empty($product['description'])): ?>
                        <div class="col-12 mt-3">
                            <label class="fw-bold small text-secondary">Description / Quality Notes:</label>
                            <p class="mb-0 text-dark bg-white p-2 rounded border small"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pricing & Profit Analysis (Per Unit Comparison) -->
        <div class="card card-custom mb-4">
            <div class="card-header card-header-clean fw-bold">
                <i class="fa-solid fa-calculator text-success me-2"></i>Unit Pricing & Profit Margins
            </div>
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Selling Unit</th>
                                <th>Purchase Cost</th>
                                <th>Selling Price</th>
                                <th>Profit Amount</th>
                                <th>Margin (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Per KG -->
                            <tr>
                                <td class="fw-bold text-start"><i class="fa-solid fa-weight-hanging text-secondary me-2"></i>Per 1 KG</td>
                                <td><?= show_cost($cost_kg) ?></td>
                                <td class="text-dark fw-bold"><?= format_currency($sell_kg) ?></td>
                                <td><?= show_profit($profit_kg) ?></td>
                                <td><?= show_margin($margin_kg) ?></td>
                            </tr>

                            <!-- Per Piece -->
                            <?php if ($sell_piece > 0 || $weight_piece > 0): ?>
                                <tr>
                                    <td class="fw-bold text-start"><i class="fa-solid fa-shapes text-primary me-2"></i>Per 1 Piece</td>
                                    <td><?= show_cost($cost_piece) ?></td>
                                    <td class="text-dark fw-bold"><?= format_currency($sell_piece) ?></td>
                                    <td><?= show_profit($profit_piece) ?></td>
                                    <td><?= show_margin($margin_piece) ?></td>
                                </tr>
                            <?php endif; ?>

                            <!-- Per Foot -->
                            <?php if ($sell_foot > 0 || $weight_foot > 0): ?>
                                <tr>
                                    <td class="fw-bold text-start"><i class="fa-solid fa-ruler text-info me-2"></i>Per 1 Foot</td>
                                    <td><?= show_cost($cost_foot) ?></td>
                                    <td class="text-dark fw-bold"><?= format_currency($sell_foot) ?></td>
                                    <td><?= show_profit($profit_foot) ?></td>
                                    <td><?= show_margin($margin_foot) ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Sales History of this Item -->
        <div class="card card-custom">
            <div class="card-header card-header-clean d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Recent Sales for this Item</span>
                <span class="badge bg-light text-secondary border"><?= count($sales_history) ?> Recent Sales</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Sold Qty</th>
                            <th>Rate</th>
                            <th>Subtotal</th>
                            <th>Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales_history)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    No sales recorded for this product yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales_history as $sh): ?>
                                <tr>
                                    <td>
                                        <a href="invoice_print.php?id=<?= $sh['sale_id'] ?>" target="_blank" class="fw-bold text-primary text-decoration-none">
                                            <?= htmlspecialchars($sh['invoice_no']) ?>
                                        </a>
                                    </td>
                                    <td class="small text-muted"><?= date('d-M-Y', strtotime($sh['sale_date'])) ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($sh['customer_name'] ?: 'Walk-in') ?></td>
                                    <td>
                                        <span class="fw-bold"><?= number_format($sh['quantity'], 2) ?></span>
                                        <span class="badge bg-light text-secondary border"><?= strtoupper($sh['sale_unit']) ?></span>
                                    </td>
                                    <td><?= format_currency($sh['unit_price']) ?></td>
                                    <td class="fw-bold"><?= format_currency($sh['subtotal']) ?></td>
                                    <td class="fw-bold text-success">+<?= format_currency($sh['item_profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Right Column: Current Stock & Total Valuation Card -->
    <div class="col-lg-4">
        <div class="position-sticky" style="top: 80px;">
            
            <!-- Stock Status Box -->
            <div class="card card-custom mb-4 border-<?= ($stock_kg <= $alert_kg) ? 'danger' : 'primary' ?>">
                <div class="card-header bg-<?= ($stock_kg <= $alert_kg) ? 'danger' : 'primary' ?> text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-boxes-stacked me-2"></i>Current Inventory</span>
                    <?php if ($stock_kg <= $alert_kg): ?>
                        <span class="badge bg-white text-danger">LOW STOCK</span>
                    <?php else: ?>
                        <span class="badge bg-white text-primary">IN STOCK</span>
                    <?php endif; ?>
                </div>

                <div class="card-body p-4">
                    <!-- Total Weight -->
                    <div class="mb-3 text-center pb-3 border-bottom">
                        <div class="text-muted small mb-1">Available Weight in Store:</div>
                        <div class="fs-2 fw-bold text-dark"><?= format_weight($stock_kg) ?></div>
                    </div>

                    <!-- Pieces & Feet breakdown -->
                    <div class="row g-2 mb-3 text-center">
                        <div class="col-6">
                            <div class="p-2 bg-light rounded border">
                                <div class="small text-muted">Pieces:</div>
                                <div class="fs-5 fw-bold text-primary">~<?= number_format($pieces_in_stock, 1) ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 bg-light rounded border">
                                <div class="small text-muted">Total Feet:</div>
                                <div class="fs-5 fw-bold text-dark">~<?= number_format($feet_in_stock, 0) ?> ft</div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Valuation -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Purchase Cost Value:</span>
                            <span><?= show_cost($total_cost_val) ?></span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Potential Retail Value:</span>
                            <span class="fw-bold text-dark"><?= format_currency($total_retail_val) ?></span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-success">Expected Total Profit:</span>
                            <span><?= show_profit($total_expected_profit, false) ?></span>
                        </div>
                    </div>

                    <!-- Alert Limit info -->
                    <div class="small text-muted d-flex justify-content-between">
                        <span>Low Stock Alert Limit:</span>
                        <span class="fw-bold"><?= number_format($alert_kg, 1) ?> KG</span>
                    </div>

                    <div class="mt-4 d-grid gap-2">
                        <a href="product_edit.php?id=<?= $product['id'] ?>" class="btn btn-outline-primary fw-bold">
                            <i class="fa-solid fa-pen-to-square me-1"></i> Edit Rates or Stock
                        </a>
                        <a href="pos.php" class="btn btn-success fw-bold">
                            <i class="fa-solid fa-cart-shopping me-1"></i> Sell This Product in POS
                        </a>
                    </div>
                </div>
            </div>

            <!-- Timestamps Info -->
            <div class="text-muted small text-center">
                <div>Added on: <?= date('d-M-Y h:i A', strtotime($product['created_at'])) ?></div>
                <?php if (!empty($product['updated_at'])): ?>
                    <div>Last Updated: <?= date('d-M-Y h:i A', strtotime($product['updated_at'])) ?></div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

