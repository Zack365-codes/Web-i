<?php
// product_add.php
$page_title = "Add New Product";
require_once __DIR__ . '/includes/header.php';

// Form submission handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    if (!isset($pdo)) {
        set_flash('danger', 'Database is not connected. Please start MySQL in XAMPP.');
    } else {
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
        $min_price_per_kg    = floatval($_POST['min_price_per_kg'] ?? 0);
        $min_price_per_piece = floatval($_POST['min_price_per_piece'] ?? 0);
        $min_price_per_foot  = floatval($_POST['min_price_per_foot'] ?? 0);
        $total_stock_kg      = floatval($_POST['total_stock_kg'] ?? 0);
        $alert_quantity_kg   = floatval($_POST['alert_quantity_kg'] ?? 50);
        $description         = trim($_POST['description'] ?? '');

        // Auto default min rates if left empty
        if ($min_price_per_kg <= 0 && $sell_price_per_kg > 0) {
            $min_price_per_kg = max($cost_price_per_kg, round($sell_price_per_kg * 0.95, 2));
        }
        if ($min_price_per_piece <= 0 && $sell_price_per_piece > 0) {
            $min_price_per_piece = round($sell_price_per_piece * 0.95, 2);
        }
        if ($min_price_per_foot <= 0 && $sell_price_per_foot > 0) {
            $min_price_per_foot = round($sell_price_per_foot * 0.95, 2);
        }

        // Auto calculate piece weight or foot weight if one is missing
        if ($weight_per_piece <= 0 && $weight_per_foot > 0 && $standard_length > 0) {
            $weight_per_piece = $weight_per_foot * $standard_length;
        } elseif ($weight_per_foot <= 0 && $weight_per_piece > 0 && $standard_length > 0) {
            $weight_per_foot = $weight_per_piece / $standard_length;
        }

        // Basic validation
        if (empty($product_name)) {
            $error = "Product name is required.";
        } elseif ($weight_per_piece <= 0 && $weight_per_foot <= 0) {
            $error = "Weight is required! Please enter Weight Per Piece or Weight Per Foot.";
        } elseif ($cost_price_per_kg <= 0) {
            $error = "Cost Price per KG is required to calculate profit.";
        } else {
            try {
                // If product code is empty, generate an automatic code
                if (empty($product_code)) {
                    $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $category ?: 'ITM'), 0, 3));
                    $product_code = $prefix . '-' . rand(1000, 9999);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO products (
                        product_code, product_name, category, 
                        standard_length_feet, weight_per_foot, weight_per_piece,
                        cost_price_per_kg, sell_price_per_kg, min_price_per_kg,
                        sell_price_per_foot, min_price_per_foot,
                        sell_price_per_piece, min_price_per_piece,
                        total_stock_kg, alert_quantity_kg, description
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $product_code, $product_name, $category,
                    $standard_length, $weight_per_foot, $weight_per_piece,
                    $cost_price_per_kg, $sell_price_per_kg, $min_price_per_kg,
                    $sell_price_per_foot, $min_price_per_foot,
                    $sell_price_per_piece, $min_price_per_piece,
                    $total_stock_kg, $alert_quantity_kg, $description
                ]);

                set_flash('success', "Product '{$product_name}' was added successfully!");
                echo "<script>window.location.href = 'products.php';</script>";
                exit;

            } catch (PDOException $e) {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-circle-plus text-primary me-2"></i>Add New Product</h3>
            <p class="text-muted mb-0">Add any iron item with piece, feet, and KG tracking plus live profit calculation.</p>
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

<form action="product_add.php" method="POST" id="addProductForm">
    <div class="row g-4">
        
        <!-- Left Side: Form Inputs -->
        <div class="col-lg-8">
            
            <!-- Section 1: Basic Information -->
            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean d-flex align-items-center">
                    <span class="badge bg-primary me-2">1</span>
                    <span>Basic Information</span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label for="product_name" class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" id="product_name" name="product_name" 
                                   placeholder="e.g. 1x1 Square Pipe 16 Gauge or Main Entrance Gate" required
                                   value="<?= htmlspecialchars($_POST['product_name'] ?? '') ?>">
                            <div class="form-text">Item name that will appear on bills and stock lists.</div>
                        </div>

                        <div class="col-md-5">
                            <label for="category" class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select class="form-select form-select-lg" id="category" name="category" required>
                                <option value="">Select Category...</option>
                                <option value="Saria (Deformed Bar)">Saria (Deformed Bar)</option>
                                <option value="Square Pipe (Chakor)">Square Pipe (Chakor)</option>
                                <option value="Round Pipe (Gol)">Round Pipe (Gol)</option>
                                <option value="Angle Iron (L-Shape)">Angle Iron (L-Shape)</option>
                                <option value="Patti (Flat Iron)">Patti (Flat Iron)</option>
                                <option value="Girder / Beam">Girder / Beam</option>
                                <option value="T-Iron">T-Iron</option>
                                <option value="Chadar (Sheet/Plate)">Chadar (Sheet/Plate)</option>
                                <option value="Gate / Door / Grill">Gate / Door / Grill</option>
                                <option value="Other Hardware">Other Hardware</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="product_code" class="form-label fw-semibold">Product Code / SKU (Optional)</label>
                            <input type="text" class="form-control" id="product_code" name="product_code" 
                                   placeholder="Leave empty to auto-generate (e.g. ITM-101)"
                                   value="<?= htmlspecialchars($_POST['product_code'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="description" class="form-label fw-semibold">Description / Dimensions / Specs</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   placeholder="e.g. 16 Gauge, Prime Quality, 120 KG total"
                                   value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Weight & Measurement -->
            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <span class="badge bg-primary me-2">2</span>
                        <span>Weight & Measurement (Piece & Length)</span>
                    </div>
                    <span class="helper-pill"><i class="fa-solid fa-scale-balanced me-1"></i> Dual-Sale Helper</span>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i>
                        Enter the <strong>Weight of 1 Piece (KG)</strong>. If you enter 1 Foot Weight and Length, piece weight is auto-calculated!
                    </p>

                    <div class="row g-3">
                        <!-- Weight Per Piece -->
                        <div class="col-md-4">
                            <label for="weight_per_piece" class="form-label fw-semibold text-primary">
                                Weight of 1 Piece (KG) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0.01" class="form-control form-control-lg fw-bold border-primary" 
                                       id="weight_per_piece" name="weight_per_piece" placeholder="e.g. 6.20 or 120"
                                       value="<?= htmlspecialchars($_POST['weight_per_piece'] ?? '') ?>">
                                <span class="input-group-text bg-primary-subtle fw-bold">KG / Piece</span>
                            </div>
                            <div class="form-text">Total weight of 1 full item/bar/pipe.</div>
                        </div>

                        <!-- 1 Foot Weight -->
                        <div class="col-md-4">
                            <label for="weight_per_foot" class="form-label fw-semibold">
                                1 Foot Weight (KG)
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.0001" min="0" class="form-control form-control-lg fw-bold" 
                                       id="weight_per_foot" name="weight_per_foot" placeholder="0.3040"
                                       value="<?= htmlspecialchars($_POST['weight_per_foot'] ?? '') ?>">
                                <span class="input-group-text">KG / Foot</span>
                            </div>
                            <div class="form-text">Auto-calculated if length is given.</div>
                        </div>

                        <!-- Standard Length in Feet -->
                        <div class="col-md-4">
                            <label for="standard_length_feet" class="form-label fw-semibold">Length (Feet)</label>
                            <div class="input-group">
                                <input type="number" step="0.5" class="form-control form-control-lg" 
                                       id="standard_length_feet" name="standard_length_feet" value="20.00">
                                <span class="input-group-text">Feet</span>
                            </div>
                            <div class="form-text">Standard length (default 20 ft).</div>
                        </div>
                    </div>

                    <!-- Quick Helper: Auto-Calculate Weight/Foot if user doesn't know -->
                    <div class="mt-4 p-3 bg-light rounded-3 border">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <strong class="text-secondary small">
                                <i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> 
                                Quick Tool: Auto-calculate 1 foot weight from full piece weight:
                            </strong>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-4">
                                <label class="small text-muted mb-1">Piece Length (Feet):</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" id="helper_pipe_length" value="20" placeholder="20">
                            </div>
                            <div class="col-sm-4">
                                <label class="small text-muted mb-1">Total Piece Weight (KG):</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" id="helper_pipe_weight" placeholder="e.g. 6.2">
                            </div>
                            <div class="col-sm-4">
                                <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-bold" id="btn_apply_weight_foot">
                                    <i class="fa-solid fa-calculator me-1"></i> Calculate & Apply
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Pricing & Rates (With Minimum Allowed Bargain Limits) -->
            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <span class="badge bg-primary me-2">3</span>
                        <span>Purchase Cost, Selling Rates & Bargain Limits</span>
                    </div>
                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Kam se Kam Rate Limit</span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        
                        <!-- Purchase Cost per KG (Restricted to Super Admin) -->
                        <div class="col-md-12">
                            <?php if (is_super_admin()): ?>
                                <label for="cost_price_per_kg" class="form-label fw-semibold text-danger">
                                    <i class="fa-solid fa-cart-shopping me-1"></i> Purchase Cost (Cost / KG) <span class="text-danger">* (Owner Only)</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-danger-subtle text-danger fw-bold">Cost Rs.</span>
                                    <input type="number" step="0.5" class="form-control form-control-lg fw-bold text-danger" 
                                           id="cost_price_per_kg" name="cost_price_per_kg" placeholder="220.00" required
                                           value="<?= htmlspecialchars($_POST['cost_price_per_kg'] ?? '') ?>">
                                    <span class="input-group-text">/ KG</span>
                                </div>
                                <div class="form-text">Base raw iron cost paid to mill/factory. Hidden from staff.</div>
                            <?php else: ?>
                                <input type="hidden" name="cost_price_per_kg" value="0">
                                <div class="alert alert-secondary py-2 small mb-0">
                                    <i class="fa-solid fa-lock me-1 text-warning"></i> Purchase Cost Price is managed by Super Admin.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- 1. Per Piece Rates (Selling & Min Bargain Limit) -->
                        <div class="col-md-6">
                            <label for="sell_price_per_piece" class="form-label fw-semibold text-primary">
                                <i class="fa-solid fa-shapes me-1"></i> Full Piece Selling Rate
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="1" class="form-control form-control-lg fw-bold text-primary" 
                                       id="sell_price_per_piece" name="sell_price_per_piece" placeholder="e.g. 1750.00"
                                       value="<?= htmlspecialchars($_POST['sell_price_per_piece'] ?? '') ?>">
                                <span class="input-group-text">/ Piece</span>
                            </div>
                            <div class="form-text">Standard sale rate for 1 full piece/pipe/gate.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="min_price_per_piece" class="form-label fw-semibold text-danger">
                                <i class="fa-solid fa-shield-halved me-1"></i> Min Piece Rate (Kam se kam limit)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-warning-subtle text-dark fw-bold">Min Rs.</span>
                                <input type="number" step="1" class="form-control form-control-lg fw-bold text-dark border-warning" 
                                       id="min_price_per_piece" name="min_price_per_piece" placeholder="e.g. 1650.00"
                                       value="<?= htmlspecialchars($_POST['min_price_per_piece'] ?? '') ?>">
                                <span class="input-group-text">/ Piece</span>
                            </div>
                            <div class="form-text">Salesmen cannot sell below this minimum bargain rate.</div>
                        </div>

                        <!-- 2. Per KG Rates (Selling & Min Bargain Limit) -->
                        <div class="col-md-6">
                            <label for="sell_price_per_kg" class="form-label fw-semibold text-success">
                                <i class="fa-solid fa-weight-hanging me-1"></i> Weight Rate (Sale / KG)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold text-success" 
                                       id="sell_price_per_kg" name="sell_price_per_kg" placeholder="260.00"
                                       value="<?= htmlspecialchars($_POST['sell_price_per_kg'] ?? '') ?>">
                                <span class="input-group-text">/ KG</span>
                            </div>
                            <div class="form-text">When customer buys by weight (Kanta).</div>
                        </div>

                        <div class="col-md-6">
                            <label for="min_price_per_kg" class="form-label fw-semibold text-danger">
                                <i class="fa-solid fa-shield-halved me-1"></i> Min KG Rate (Kam se kam limit)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-warning-subtle text-dark fw-bold">Min Rs.</span>
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold text-dark border-warning" 
                                       id="min_price_per_kg" name="min_price_per_kg" placeholder="245.00"
                                       value="<?= htmlspecialchars($_POST['min_price_per_kg'] ?? '') ?>">
                                <span class="input-group-text">/ KG</span>
                            </div>
                            <div class="form-text">Minimum allowed bargain rate per KG.</div>
                        </div>

                        <!-- 3. Per Foot Rates (Selling & Min Bargain Limit) -->
                        <div class="col-md-6">
                            <label for="sell_price_per_foot" class="form-label fw-semibold text-secondary">
                                <i class="fa-solid fa-ruler me-1"></i> Running Foot Rate (Sale / Foot)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="0.5" class="form-control form-control-lg" 
                                       id="sell_price_per_foot" name="sell_price_per_foot" placeholder="85.00"
                                       value="<?= htmlspecialchars($_POST['sell_price_per_foot'] ?? '') ?>">
                                <span class="input-group-text">/ Foot</span>
                            </div>
                            <div class="form-text">When cut or sold per running foot.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="min_price_per_foot" class="form-label fw-semibold text-danger">
                                <i class="fa-solid fa-shield-halved me-1"></i> Min Foot Rate (Kam se kam limit)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-warning-subtle text-dark fw-bold">Min Rs.</span>
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold text-dark border-warning" 
                                       id="min_price_per_foot" name="min_price_per_foot" placeholder="80.00"
                                       value="<?= htmlspecialchars($_POST['min_price_per_foot'] ?? '') ?>">
                                <span class="input-group-text">/ Foot</span>
                            </div>
                            <div class="form-text">Minimum allowed bargain rate per foot.</div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Section 4: Current Stock (Pieces & KG Linked) -->
            <div class="card card-custom mb-4">
                <div class="card-header card-header-clean d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <span class="badge bg-primary me-2">4</span>
                        <span>Current Stock (Pieces & KG)</span>
                    </div>
                    <span class="helper-pill"><i class="fa-solid fa-arrows-rotate me-1"></i> Auto-Synced</span>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">
                        Enter either <strong>Pieces count</strong> or <strong>Total KG</strong>. Changing one automatically calculates the other!
                    </p>

                    <div class="row g-3">
                        <!-- Pieces count -->
                        <div class="col-md-6">
                            <label for="stock_pieces" class="form-label fw-semibold text-primary">
                                <i class="fa-solid fa-boxes-stacked me-1"></i> Available Pieces (Quantity in Stock)
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold" 
                                       id="stock_pieces" placeholder="e.g. 50">
                                <span class="input-group-text fw-bold">Pieces</span>
                            </div>
                            <div class="form-text">Total number of individual pieces.</div>
                        </div>

                        <!-- Total KG -->
                        <div class="col-md-6">
                            <label for="total_stock_kg" class="form-label fw-semibold text-dark">
                                <i class="fa-solid fa-weight-hanging me-1"></i> Total Stock Weight (KG) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.5" class="form-control form-control-lg fw-bold" 
                                       id="total_stock_kg" name="total_stock_kg" placeholder="600.00" required
                                       value="<?= htmlspecialchars($_POST['total_stock_kg'] ?? '0') ?>">
                                <span class="input-group-text fw-bold">KG</span>
                            </div>
                            <div class="form-text">Total warehouse weight in KG.</div>
                        </div>

                        <div class="col-12">
                            <label for="alert_quantity_kg" class="form-label fw-semibold">
                                Low Stock Alert Limit (KG)
                            </label>
                            <div class="input-group">
                                <input type="number" step="1" class="form-control" 
                                       id="alert_quantity_kg" name="alert_quantity_kg" value="50.00">
                                <span class="input-group-text">KG</span>
                            </div>
                            <div class="form-text">Alert warning when stock falls below this weight.</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Side: Live Profit Preview Box -->
        <div class="col-lg-4">
            <div class="position-sticky" style="top: 80px;">
                <div class="profit-preview-card shadow-sm">
                    <div class="profit-preview-title">
                        <i class="fa-solid fa-chart-line text-success fs-5"></i>
                        <span>Live Profit & Loss Preview</span>
                    </div>
                    <p class="text-muted small">Live profit or loss updates automatically for each selling option:</p>

                    <!-- 1. Per Piece Profit Box -->
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
                        <div class="text-muted small mt-1">Piece Selling Price - (Piece Weight &times; KG Cost)</div>
                    </div>

                    <!-- 2. KG Profit Box -->
                    <div class="profit-stat-item">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold text-secondary small"><i class="fa-solid fa-weight-hanging me-1"></i> Profit Per 1 KG:</span>
                            <span id="preview_margin_kg" class="badge bg-secondary">0% Margin</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div class="fs-4 fw-bold text-success" id="preview_profit_kg">Rs. 0.00</div>
                            <div class="small text-muted">Cost: <span id="preview_cost_kg" class="fw-semibold">Rs. 0.00</span></div>
                        </div>
                        <div class="text-muted small mt-1">Selling Price / KG - Purchase Cost / KG</div>
                    </div>

                    <!-- 3. Feet Profit Box -->
                    <div class="profit-stat-item">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold text-secondary small"><i class="fa-solid fa-ruler me-1"></i> Profit Per 1 Foot:</span>
                            <span id="preview_margin_foot" class="badge bg-secondary">0% Margin</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div class="fs-4 fw-bold text-success" id="preview_profit_foot">Rs. 0.00</div>
                            <div class="small text-muted">Cost: <span id="preview_cost_foot">Rs. 0.00</span></div>
                        </div>
                        <div class="text-muted small">1 Foot Sale Rate - (1 Foot Weight &times; KG Cost)</div>
                    </div>

                    <!-- 4. Total Stock Valuation -->
                    <div class="profit-stat-item bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold text-secondary small"><i class="fa-solid fa-boxes-stacked me-1"></i> Total Stock Valuation:</span>
                            <span id="preview_stock_pieces" class="badge bg-light text-secondary border">0 Pieces</span>
                        </div>
                        
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Total Purchase Cost:</span>
                            <span id="preview_stock_cost" class="fw-semibold text-dark">Rs. 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Total Selling Value:</span>
                            <span id="preview_stock_sell" class="fw-semibold text-dark">Rs. 0.00</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-success">Expected Total Profit:</span>
                            <span id="preview_stock_profit" class="fw-bold text-success fs-5">Rs. 0.00</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" name="save_product" class="btn btn-success btn-lg w-100 fw-bold shadow-sm py-3">
                            <i class="fa-solid fa-check-circle me-2"></i> Save Product
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
