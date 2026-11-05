<?php
// product_edit.php
$page_title = "Edit Product";
require_once __DIR__ . '/includes/header.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('danger', 'Invalid product ID.');
    echo "<script>window.location.href = 'products.php';</script>";
    exit;
}

// Fetch existing product
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('danger', 'Product not found.');
    echo "<script>window.location.href = 'products.php';</script>";
    exit;
}

// Form update handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $product_name        = trim($_POST['product_name'] ?? '');
    $category            = trim($_POST['category'] ?? '');
    $product_code        = trim($_POST['product_code'] ?? '');
    $standard_length     = floatval($_POST['standard_length_feet'] ?? 20);
    $weight_per_foot     = floatval($_POST['weight_per_foot'] ?? 0);
    $weight_per_piece    = floatval($_POST['weight_per_piece'] ?? 0);
    $cost_price_per_kg   = floatval($_POST['cost_price_per_kg'] ?? 0);
    $sell_price_per_kg   = floatval($_POST['sell_price_per_kg'] ?? 0);
    $sell_price_per_foot = floatval($_POST['sell_price_per_foot'] ?? 0);
    $sell_price_per_piece= floatval($_POST['sell_price_per_piece'] ?? 0);
    $total_stock_kg      = floatval($_POST['total_stock_kg'] ?? 0);
    $alert_quantity_kg   = floatval($_POST['alert_quantity_kg'] ?? 50);
    $description         = trim($_POST['description'] ?? '');

    // Auto calculate piece weight or foot weight if one is missing
    if ($weight_per_piece <= 0 && $weight_per_foot > 0 && $standard_length > 0) {
        $weight_per_piece = $weight_per_foot * $standard_length;
    } elseif ($weight_per_foot <= 0 && $weight_per_piece > 0 && $standard_length > 0) {
        $weight_per_foot = $weight_per_piece / $standard_length;
    }

    if (empty($product_name)) {
        $error = "Product name is required.";
    } elseif ($weight_per_piece <= 0 && $weight_per_foot <= 0) {
        $error = "Weight is required! Please enter Weight Per Piece or Weight Per Foot.";
    } elseif ($cost_price_per_kg <= 0) {
        $error = "Purchase Cost per KG is required.";
    } else {
        try {
            $updateStmt = $pdo->prepare("
                UPDATE products SET
                    product_code = ?, product_name = ?, category = ?,
                    standard_length_feet = ?, weight_per_foot = ?, weight_per_piece = ?,
                    cost_price_per_kg = ?, sell_price_per_kg = ?, sell_price_per_foot = ?, sell_price_per_piece = ?,
                    total_stock_kg = ?, alert_quantity_kg = ?, description = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $product_code, $product_name, $category,
                $standard_length, $weight_per_foot, $weight_per_piece,
                $cost_price_per_kg, $sell_price_per_kg, $sell_price_per_foot, $sell_price_per_piece,
                $total_stock_kg, $alert_quantity_kg, $description,
                $id
            ]);

            set_flash('success', "Product '{$product_name}' was updated successfully!");
            echo "<script>window.location.href = 'products.php';</script>";
            exit;

        } catch (PDOException $e) {
            $error = "Update Error: " . $e->getMessage();
        }
    }
}

// Initial pieces count for edit form
$initial_piece_weight = (float)($product['weight_per_piece'] ?: ($product['weight_per_foot'] * $product['standard_length_feet']));
$initial_pieces = $initial_piece_weight > 0 ? round($product['total_stock_kg'] / $initial_piece_weight, 1) : 0;
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-pen-to-square text-primary me-2"></i>Edit Product</h3>
            <p class="text-muted mb-0">Update rates, weight conversion, pieces, or stock for "<?= htmlspecialchars($product['product_name']) ?>"</p>
        </div>
        <a href="products.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Products
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form action="product_edit.php?id=<?= $id ?>" method="POST" id="editProductForm">
    <div class="row g-4">
        
        <!-- Left Side: Inputs -->
        <div class="col-lg-8">
            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean">
                    <span class="badge bg-primary me-2">1</span> Basic Information
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label for="product_name" class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" id="product_name" name="product_name" required
                                   value="<?= htmlspecialchars($product['product_name']) ?>">
                        </div>
                        <div class="col-md-5">
                            <label for="category" class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select class="form-select form-select-lg" id="category" name="category" required>
                                <?php
                                $categories = [
                                    "Saria (Deformed Bar)", "Square Pipe (Chakor)", "Round Pipe (Gol)", 
                                    "Angle Iron (L-Shape)", "Patti (Flat Iron)", "Girder / Beam", 
                                    "T-Iron", "Chadar (Sheet/Plate)", "Gate / Door / Grill", "Other Hardware"
                                ];
                                foreach ($categories as $cat) {
                                    $sel = ($product['category'] === $cat) ? 'selected' : '';
                                    echo "<option value=\"{$cat}\" {$sel}>{$cat}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="product_code" class="form-label fw-semibold">Product Code / SKU</label>
                            <input type="text" class="form-control" id="product_code" name="product_code" 
                                   value="<?= htmlspecialchars($product['product_code']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="description" class="form-label fw-semibold">Description / Notes</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   value="<?= htmlspecialchars($product['description'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean">
                    <span class="badge bg-primary me-2">2</span> Weight & Measurement (Piece & Length)
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="weight_per_piece" class="form-label fw-semibold text-primary">
                                Weight of 1 Piece (KG) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0.01" class="form-control form-control-lg fw-bold border-primary" 
                                       id="weight_per_piece" name="weight_per_piece" required
                                       value="<?= htmlspecialchars($product['weight_per_piece'] ?: $initial_piece_weight) ?>">
                                <span class="input-group-text bg-primary-subtle fw-bold">KG / Piece</span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="weight_per_foot" class="form-label fw-semibold">1 Foot Weight (KG)</label>
                            <div class="input-group">
                                <input type="number" step="0.0001" min="0" class="form-control form-control-lg fw-bold" 
                                       id="weight_per_foot" name="weight_per_foot" 
                                       value="<?= htmlspecialchars($product['weight_per_foot']) ?>">
                                <span class="input-group-text">KG / Foot</span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="standard_length_feet" class="form-label fw-semibold">Length (Feet)</label>
                            <div class="input-group">
                                <input type="number" step="0.5" class="form-control form-control-lg" 
                                       id="standard_length_feet" name="standard_length_feet" 
                                       value="<?= htmlspecialchars($product['standard_length_feet']) ?>">
                                <span class="input-group-text">Feet</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean">
                    <span class="badge bg-primary me-2">3</span> Cost & Selling Rates
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="cost_price_per_kg" class="form-label fw-semibold text-danger">Purchase Cost (Cost / KG) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold" 
                                       id="cost_price_per_kg" name="cost_price_per_kg" required
                                       value="<?= htmlspecialchars($product['cost_price_per_kg']) ?>">
                                <span class="input-group-text">/ KG</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="sell_price_per_kg" class="form-label fw-semibold text-success">Selling Rate (Sale / KG)</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold text-success" 
                                       id="sell_price_per_kg" name="sell_price_per_kg" 
                                       value="<?= htmlspecialchars($product['sell_price_per_kg']) ?>">
                                <span class="input-group-text">/ KG</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="sell_price_per_piece" class="form-label fw-semibold text-primary">Selling Rate (Per Piece)</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="1" class="form-control form-control-lg fw-bold text-primary" 
                                       id="sell_price_per_piece" name="sell_price_per_piece" 
                                       value="<?= htmlspecialchars($product['sell_price_per_piece']) ?>">
                                <span class="input-group-text">/ Piece</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="sell_price_per_foot" class="form-label fw-semibold text-secondary">Selling Rate (Sale / Foot)</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="0.5" class="form-control form-control-lg" 
                                       id="sell_price_per_foot" name="sell_price_per_foot" 
                                       value="<?= htmlspecialchars($product['sell_price_per_foot']) ?>">
                                <span class="input-group-text">/ Foot</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean">
                    <span class="badge bg-primary me-2">4</span> Current Inventory Stock (Pieces & KG)
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="stock_pieces" class="form-label fw-semibold text-primary">Available Pieces (Quantity in Stock)</label>
                            <div class="input-group">
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold" 
                                       id="stock_pieces" value="<?= $initial_pieces ?>">
                                <span class="input-group-text fw-bold">Pieces</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="total_stock_kg" class="form-label fw-semibold text-dark">Total Stock Weight (KG) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold" 
                                       id="total_stock_kg" name="total_stock_kg" required
                                       value="<?= htmlspecialchars($product['total_stock_kg']) ?>">
                                <span class="input-group-text fw-bold">KG</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="alert_quantity_kg" class="form-label fw-semibold">Low Stock Alert Limit (KG)</label>
                            <div class="input-group">
                                <input type="number" step="1" class="form-control" 
                                       id="alert_quantity_kg" name="alert_quantity_kg" 
                                       value="<?= htmlspecialchars($product['alert_quantity_kg']) ?>">
                                <span class="input-group-text">KG</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Live Profit Preview -->
        <div class="col-lg-4">
            <div class="position-sticky" style="top: 80px;">
                <div class="profit-preview-card shadow-sm">
                    <div class="profit-preview-title">
                        <i class="fa-solid fa-chart-line text-success fs-5"></i>
                        <span>Live Profit & Loss Preview</span>
                    </div>

                    <!-- Per Piece Profit -->
                    <div class="profit-stat-item border-primary" style="background: #f8fafc;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-primary small">
                                <i class="fa-solid fa-shapes me-1"></i> Profit Per 1 Piece:
                            </span>
                            <span id="preview_margin_piece" class="badge bg-secondary">0% Margin</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div class="fs-4 fw-bold text-success" id="preview_profit_piece">Rs. 0.00</div>
                            <div class="small text-muted">Cost: <span id="preview_cost_piece" class="fw-semibold">Rs. 0.00</span></div>
                        </div>
                    </div>

                    <!-- KG Profit -->
                    <div class="profit-stat-item">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold text-secondary small"><i class="fa-solid fa-weight-hanging me-1"></i> Profit Per 1 KG:</span>
                            <span id="preview_margin_kg" class="badge bg-secondary">0% Margin</span>
                        </div>
                        <div class="fs-4 fw-bold text-success" id="preview_profit_kg">Rs. 0.00</div>
                    </div>

                    <!-- Foot Profit -->
                    <div class="profit-stat-item">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold text-secondary small"><i class="fa-solid fa-ruler me-1"></i> Profit Per 1 Foot:</span>
                            <span id="preview_margin_foot" class="badge bg-secondary">0% Margin</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div class="fs-4 fw-bold text-success" id="preview_profit_foot">Rs. 0.00</div>
                            <div class="small text-muted">Cost: <span id="preview_cost_foot">Rs. 0.00</span></div>
                        </div>
                    </div>

                    <!-- Total Stock Valuation -->
                    <div class="profit-stat-item bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold text-secondary small">Total Stock Valuation:</span>
                            <span id="preview_stock_pieces" class="badge bg-light text-secondary border">0 Pieces</span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Purchase Cost:</span>
                            <span id="preview_stock_cost" class="fw-semibold text-dark">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Selling Value:</span>
                            <span id="preview_stock_sell" class="fw-semibold text-dark">Rs. 0.00</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-success">Expected Total Profit:</span>
                            <span id="preview_stock_profit" class="fw-bold text-success fs-5">Rs. 0.00</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" name="update_product" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm py-3">
                            <i class="fa-solid fa-save me-2"></i> Update Product
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
