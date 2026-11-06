<?php
// products.php
$page_title = "Products & Inventory";
require_once __DIR__ . '/includes/header.php';

$search = trim($_GET['search'] ?? '');
$category_filter = trim($_GET['category'] ?? '');

$query = "SELECT * FROM products WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (product_name LIKE ? OR product_code LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($category_filter)) {
    $query .= " AND category = ?";
    $params[] = $category_filter;
}

$query .= " ORDER BY id DESC";

$products = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to load products: " . $e->getMessage();
    }
}
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-6 mb-3 mb-md-0">
        <h3 class="fw-bold mb-1"><i class="fa-solid fa-boxes-stacked text-primary me-2"></i>All Products & Inventory</h3>
        <p class="text-muted mb-0">Stock tracked in both <strong>Pieces</strong> and <strong>Weight (KG)</strong> with profit breakdown.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="product_add.php" class="btn btn-primary-custom shadow-sm">
            <i class="fa-solid fa-plus-circle me-1"></i> Add New Product
        </a>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card card-custom mb-4">
    <div class="card-body p-3">
        <form method="GET" action="products.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search by Product Name or Code..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <option value="Saria (Deformed Bar)" <?= $category_filter === 'Saria (Deformed Bar)' ? 'selected' : '' ?>>Saria (Deformed Bar)</option>
                    <option value="Square Pipe (Chakor)" <?= $category_filter === 'Square Pipe (Chakor)' ? 'selected' : '' ?>>Square Pipe (Chakor)</option>
                    <option value="Round Pipe (Gol)" <?= $category_filter === 'Round Pipe (Gol)' ? 'selected' : '' ?>>Round Pipe (Gol)</option>
                    <option value="Angle Iron (L-Shape)" <?= $category_filter === 'Angle Iron (L-Shape)' ? 'selected' : '' ?>>Angle Iron (L-Shape)</option>
                    <option value="Patti (Flat Iron)" <?= $category_filter === 'Patti (Flat Iron)' ? 'selected' : '' ?>>Patti (Flat Iron)</option>
                    <option value="Girder / Beam" <?= $category_filter === 'Girder / Beam' ? 'selected' : '' ?>>Girder / Beam</option>
                    <option value="T-Iron" <?= $category_filter === 'T-Iron' ? 'selected' : '' ?>>T-Iron</option>
                    <option value="Chadar (Sheet/Plate)" <?= $category_filter === 'Chadar (Sheet/Plate)' ? 'selected' : '' ?>>Chadar (Sheet/Plate)</option>
                    <option value="Gate / Door / Grill" <?= $category_filter === 'Gate / Door / Grill' ? 'selected' : '' ?>>Gate / Door / Grill</option>
                    <option value="Other Hardware" <?= $category_filter === 'Other Hardware' ? 'selected' : '' ?>>Other Hardware</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-dark w-100"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                <?php if (!empty($search) || !empty($category_filter)): ?>
                    <a href="products.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card card-custom">
    <div class="table-responsive">
        <table class="table table-hover table-custom align-middle">
            <thead>
                <tr>
                    <th>Item Info</th>
                    <th>Piece & Unit Weight</th>
                    <?php if (is_super_admin()): ?>
                        <th>Purchase Cost</th>
                        <th>Selling Rates & Profit</th>
                    <?php else: ?>
                        <th>Selling Rates & Bargain Limits (Kam se Kam)</th>
                    <?php endif; ?>
                    <th>Stock in Store (Pieces & KG)</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <i class="fa-solid fa-box-open text-muted fs-1 mb-3 d-block"></i>
                            <h5 class="fw-bold text-secondary">No Products Found!</h5>
                            <p class="text-muted small">Click "Add New Product" to start building your store inventory.</p>
                            <a href="product_add.php" class="btn btn-primary btn-sm mt-2">
                                <i class="fa-solid fa-plus me-1"></i> Add First Item
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): 
                        // Calculations
                        $cost_kg = (float)$p['cost_price_per_kg'];
                        $sell_kg = (float)$p['sell_price_per_kg'];
                        $sell_foot = (float)$p['sell_price_per_foot'];
                        $sell_piece = (float)$p['sell_price_per_piece'];
                        
                        $weight_foot = (float)$p['weight_per_foot'];
                        $weight_piece = (float)($p['weight_per_piece'] ?: ($weight_foot * $p['standard_length_feet']));
                        $stock_kg = (float)$p['total_stock_kg'];

                        // Profit per KG
                        $profit_kg = calc_profit_kg($cost_kg, $sell_kg);
                        $margin_kg = calc_margin_percent($cost_kg, $sell_kg);

                        // Profit per Piece / Gate
                        $cost_piece = $weight_piece * $cost_kg;
                        $profit_piece = $sell_piece - $cost_piece;
                        $margin_piece = calc_margin_percent($cost_piece, $sell_piece);

                        // Profit per Foot
                        $cost_foot = calc_cost_foot($cost_kg, $weight_foot);
                        $profit_foot = calc_profit_foot($cost_kg, $weight_foot, $sell_foot);
                        $margin_foot = calc_margin_percent($cost_foot, $sell_foot);

                        // Stock Pieces Count
                        $pieces_count = $weight_piece > 0 ? ($stock_kg / $weight_piece) : 0;
                        $is_low_stock = $stock_kg <= (float)$p['alert_quantity_kg'];
                    ?>
                        <tr>
                            <!-- 1. Product Info -->
                            <td>
                                <a href="product_view.php?id=<?= $p['id'] ?>" class="fw-bold fs-6 text-dark text-decoration-none hover-primary">
                                    <?= htmlspecialchars($p['product_name']) ?>
                                </a>
                                <div class="small text-muted d-flex align-items-center gap-2 mt-1">
                                    <span class="badge bg-light text-secondary border"><?= htmlspecialchars($p['category']) ?></span>
                                    <code><?= htmlspecialchars($p['product_code']) ?></code>
                                </div>
                                <?php if (!empty($p['description'])): ?>
                                    <div class="small text-muted mt-1 fst-italic"><?= htmlspecialchars($p['description']) ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- 2. Piece & Unit Weight -->
                            <td>
                                <?php if ($weight_piece > 0): ?>
                                    <div class="fw-bold text-primary">
                                        <i class="fa-solid fa-weight-hanging me-1"></i>
                                        <?= number_format($weight_piece, 2) ?> KG <span class="small fw-normal text-muted">/ Piece</span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($weight_foot > 0): ?>
                                    <div class="small text-muted">
                                        <?= number_format($weight_foot, 4) ?> KG/ft &bull; <?= number_format($p['standard_length_feet'], 1) ?> ft
                                    </div>
                                <?php endif; ?>
                            </td>

                            <?php if (is_super_admin()): ?>
                                <!-- 3. Purchase Cost (Super Admin Only) -->
                                <td>
                                    <div>
                                        <?= show_cost($cost_kg, '<span class="small text-muted">/ kg</span>') ?>
                                    </div>
                                    <?php if ($cost_piece > 0): ?>
                                        <div class="small text-muted mt-1">
                                            Piece Cost: <?= show_cost($cost_piece) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. Selling Rates & Profit (Super Admin) -->
                                <td>
                                    <!-- Per Full Piece Rate -->
                                    <?php if ($sell_piece > 0): ?>
                                        <div class="mb-1">
                                            <span class="badge bg-primary text-white me-1">Piece:</span>
                                            <span class="fw-bold text-dark"><?= format_currency($sell_piece) ?></span>
                                            <span class="ms-1"><?= show_profit($profit_piece) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Per KG Rate -->
                                    <?php if ($sell_kg > 0): ?>
                                        <div class="mb-1">
                                            <span class="badge bg-success-subtle text-success me-1">KG:</span>
                                            <span class="fw-semibold text-dark"><?= format_currency($sell_kg) ?></span>
                                            <span class="ms-1"><?= show_profit($profit_kg) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Per Foot Rate -->
                                    <?php if ($sell_foot > 0): ?>
                                        <div>
                                            <span class="badge bg-secondary-subtle text-secondary me-1">Foot:</span>
                                            <span class="fw-semibold text-dark"><?= format_currency($sell_foot) ?></span>
                                            <span class="ms-1"><?= show_profit($profit_foot) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <!-- Salesman View: Only Selling Rates & Minimum Bargain Limits (No Profit/Cost) -->
                                <td>
                                    <?php 
                                        $min_piece = (float)($p['min_price_per_piece'] ?: $sell_piece * 0.95);
                                        $min_kg = (float)($p['min_price_per_kg'] ?: $sell_kg * 0.95);
                                        $min_foot = (float)($p['min_price_per_foot'] ?: $sell_foot * 0.95);
                                    ?>
                                    <?php if ($sell_piece > 0): ?>
                                        <div class="mb-2">
                                            <span class="badge bg-primary text-white me-1">Piece:</span>
                                            <span class="fw-bold text-dark fs-6"><?= format_currency($sell_piece) ?></span>
                                            <span class="badge bg-warning text-dark border ms-1 font-monospace">Min: <?= format_currency($min_piece) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($sell_kg > 0): ?>
                                        <div class="mb-2">
                                            <span class="badge bg-success-subtle text-success me-1">KG:</span>
                                            <span class="fw-bold text-dark"><?= format_currency($sell_kg) ?></span>
                                            <span class="badge bg-warning text-dark border ms-1 font-monospace">Min: <?= format_currency($min_kg) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($sell_foot > 0): ?>
                                        <div>
                                            <span class="badge bg-secondary-subtle text-secondary me-1">Foot:</span>
                                            <span class="fw-bold text-dark"><?= format_currency($sell_foot) ?></span>
                                            <span class="badge bg-warning text-dark border ms-1 font-monospace">Min: <?= format_currency($min_foot) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                            <!-- 5. Stock in Pieces & KG -->
                            <td>
                                <!-- Pieces Count Badge -->
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 px-2 py-1">
                                        <i class="fa-solid fa-shapes me-1"></i>
                                        <strong><?= number_format($pieces_count, 1) ?></strong> Pieces
                                    </span>
                                    <?php if ($is_low_stock): ?>
                                        <span class="badge bg-danger">Low Stock</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Total KG -->
                                <div class="small fw-bold text-dark">
                                    Total Weight: <?= format_weight($stock_kg) ?>
                                </div>
                            </td>

                            <!-- 6. Action -->
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="product_view.php?id=<?= $p['id'] ?>" class="btn btn-outline-info" title="View Details (Read)">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <a href="product_edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="Edit Product">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <a href="product_delete.php?id=<?= $p['id'] ?>" class="btn btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete this product?');" title="Delete Product">
                                        <i class="fa-solid fa-trash-can"></i>
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
