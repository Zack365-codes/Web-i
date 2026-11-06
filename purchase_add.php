<?php
// purchase_add.php - Stock Inward / Purchase from Factory or Supplier
$page_title = "New Purchase / Stock Inward";
require_once __DIR__ . '/includes/header.php';

// Fetch all available products
$products = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT * FROM products ORDER BY product_name ASC");
        $products = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to load products: " . $e->getMessage();
    }
}

// Handle Purchase Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_purchase'])) {
    if (!isset($pdo)) {
        set_flash('danger', 'Database is not connected.');
    } else {
        $supplier_name   = trim($_POST['supplier_name'] ?? '');
        $supplier_phone  = trim($_POST['supplier_phone'] ?? '');
        $bilty_no        = trim($_POST['bilty_no'] ?? '');
        $payment_method  = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $extra_cost      = floatval($_POST['extra_cost'] ?? 0); // Carriage / Freight / Labor
        $paid_amount     = floatval($_POST['paid_amount'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');
        $update_cost     = isset($_POST['update_product_cost']) ? 1 : 0;
        $items_json      = $_POST['items_json'] ?? '[]';
        $cart_items      = json_decode($items_json, true) ?: [];

        if (empty($supplier_name)) {
            $error = "Please enter Supplier / Mill Name.";
        } elseif (empty($cart_items)) {
            $error = "Please add at least 1 item to the purchase entry.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Generate unique purchase number: PUR-YYYYMMDD-XXX
                $date_prefix = date('Ymd');
                $count_stmt = $pdo->query("SELECT COUNT(*) FROM purchases WHERE DATE(created_at) = CURDATE()");
                $daily_count = $count_stmt->fetchColumn() + 1;
                $purchase_no = "PUR-" . $date_prefix . "-" . str_pad($daily_count, 3, '0', STR_PAD_LEFT);

                $subtotal = 0;
                $total_weight_kg = 0;
                $processed_items = [];

                foreach ($cart_items as $item) {
                    $pid   = intval($item['product_id']);
                    $unit  = $item['purchase_unit']; // 'ton', 'kg', 'piece'
                    $qty   = floatval($item['quantity']);
                    $rate  = floatval($item['rate_per_unit']);

                    // Fetch product details
                    $p_stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                    $p_stmt->execute([$pid]);
                    $prod = $p_stmt->fetch();

                    if (!$prod) {
                        throw new Exception("Product ID {$pid} not found.");
                    }

                    $weight_foot  = (float)$prod['weight_per_foot'];
                    $length_feet  = (float)($prod['standard_length_feet'] ?: 20);
                    $weight_piece = (float)($prod['weight_per_piece'] ?: ($weight_foot * $length_feet));

                    // Convert inward quantity to KG
                    $added_kg = 0;
                    $line_total = 0;
                    $cost_per_kg = 0;

                    if ($unit === 'ton') {
                        $added_kg = $qty * 1000;
                        $line_total = $qty * $rate;
                        $cost_per_kg = $rate / 1000;
                    } elseif ($unit === 'kg') {
                        $added_kg = $qty;
                        $line_total = $qty * $rate;
                        $cost_per_kg = $rate;
                    } elseif ($unit === 'piece') {
                        $added_kg = $qty * $weight_piece;
                        $line_total = $qty * $rate;
                        $cost_per_kg = $weight_piece > 0 ? ($rate / $weight_piece) : 0;
                    }

                    $subtotal += $line_total;
                    $total_weight_kg += $added_kg;

                    // 1. Increment Stock in products table
                    $stock_update = $pdo->prepare("UPDATE products SET total_stock_kg = total_stock_kg + ? WHERE id = ?");
                    $stock_update->execute([$added_kg, $pid]);

                    // Optional: update master purchase cost per kg if selected
                    if ($update_cost && $cost_per_kg > 0) {
                        $cost_update = $pdo->prepare("UPDATE products SET cost_price_per_kg = ? WHERE id = ?");
                        $cost_update->execute([$cost_per_kg, $pid]);
                    }

                    $processed_items[] = [
                        'product_id'    => $pid,
                        'product_name'  => $prod['product_name'],
                        'purchase_unit' => $unit,
                        'quantity'      => $qty,
                        'rate_per_unit' => $rate,
                        'subtotal'      => $line_total,
                        'added_stock_kg'=> $added_kg
                    ];
                }

                $grand_total = $subtotal + $extra_cost;
                $due_amount = max(0, $grand_total - $paid_amount);

                $payment_status = 'paid';
                if ($due_amount > 0) {
                    $payment_status = ($paid_amount > 0) ? 'partial' : 'due';
                }

                // 2. Insert into purchases table
                $insert_pur = $pdo->prepare("
                    INSERT INTO purchases (
                        purchase_no, supplier_name, supplier_phone, bilty_no,
                        total_weight_kg, subtotal, extra_cost, grand_total,
                        paid_amount, due_amount, payment_status, payment_method, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert_pur->execute([
                    $purchase_no, $supplier_name, $supplier_phone, $bilty_no,
                    $total_weight_kg, $subtotal, $extra_cost, $grand_total,
                    $paid_amount, $due_amount, $payment_status, $payment_method, $notes
                ]);
                $purchase_id = $pdo->lastInsertId();

                // 3. Insert into purchase_items table
                $insert_item = $pdo->prepare("
                    INSERT INTO purchase_items (
                        purchase_id, product_id, product_name, purchase_unit,
                        quantity, rate_per_unit, subtotal, added_stock_kg
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($processed_items as $p_it) {
                    $insert_item->execute([
                        $purchase_id, $p_it['product_id'], $p_it['product_name'], $p_it['purchase_unit'],
                        $p_it['quantity'], $p_it['rate_per_unit'], $p_it['subtotal'], $p_it['added_stock_kg']
                    ]);
                }

                $pdo->commit();
                set_flash('success', "Purchase '{$purchase_no}' saved successfully! Added " . number_format($total_weight_kg, 1) . " KG to inventory.");
                echo "<script>window.location.href = 'purchases.php';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to record purchase: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold mb-1"><i class="fa-solid fa-truck-ramp-box text-primary me-2"></i>New Stock Inward / Purchase Entry</h3>
        <p class="text-muted mb-0">Record incoming steel/iron trucks from factories (Mughal, Amreli, etc.) and auto-increment warehouse stock.</p>
    </div>
    <div class="col-md-5 text-md-end">
        <a href="purchases.php" class="btn btn-outline-secondary me-2">
            <i class="fa-solid fa-clock-rotate-left me-1"></i> Purchases History
        </a>
        <a href="suppliers_khata.php" class="btn btn-outline-primary">
            <i class="fa-solid fa-building-columns me-1"></i> Suppliers Khata
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form action="purchase_add.php" method="POST" id="purchaseForm">
    <div class="row g-4">
        
        <!-- Left Column: Supplier Info & Items Cart -->
        <div class="col-lg-8">
            
            <!-- Step 1: Supplier & Shipment Info -->
            <div class="card card-custom mb-3">
                <div class="card-header card-header-clean bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fa-solid fa-truck-moving text-primary me-2"></i>1. Supplier & Mill Shipment Details</span>
                    <span class="helper-pill">Factory Khata</span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                Supplier / Mill Name <span class="text-danger">*</span>:
                            </label>
                            <input type="text" name="supplier_name" class="form-control" placeholder="e.g. Mughal Steel / Agha Steel / Wholesale Trader" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary mb-1">Supplier Contact:</label>
                            <input type="text" name="supplier_phone" class="form-control" placeholder="0300-1234567">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary mb-1">Bilty / Mill Challan #:</label>
                            <input type="text" name="bilty_no" class="form-control" placeholder="e.g. BLT-9921 / Truck # LES-102">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Add Item to Inward Cart -->
            <div class="card card-custom mb-3 border-primary">
                <div class="card-header bg-primary-subtle text-primary fw-bold py-2">
                    <i class="fa-solid fa-box-open me-1"></i> 2. Add Stock Items to Shipment
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <!-- Select Product -->
                        <div class="col-md-12">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold mb-0">Select Item to Inward <span class="text-danger">*</span></label>
                                <a href="product_add.php" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" title="Create a new item if not in inventory">
                                    <i class="fa-solid fa-plus me-1"></i> Create New Product First
                                </a>
                            </div>
                            <select id="pur_product_select" class="form-select form-select-lg">
                                <option value="">-- Choose Item from Inventory --</option>
                                <?php foreach ($products as $pr): 
                                    $w_piece = (float)($pr['weight_per_piece'] ?: ($pr['weight_per_foot'] * ($pr['standard_length_feet'] ?: 20)));
                                ?>
                                    <option value="<?= $pr['id'] ?>"
                                            data-name="<?= htmlspecialchars($pr['product_name']) ?>"
                                            data-cost-kg="<?= $pr['cost_price_per_kg'] ?>"
                                            data-weight-piece="<?= $w_piece ?>"
                                            data-weight-foot="<?= $pr['weight_per_foot'] ?>"
                                            data-current-stock="<?= $pr['total_stock_kg'] ?>">
                                        <?= htmlspecialchars($pr['product_name']) ?> &bull; (Current Stock: <?= number_format($pr['total_stock_kg'], 1) ?> KG)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Purchase Unit (Ton / KG / Piece) -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Purchase Unit <span class="text-danger">*</span></label>
                            <select id="pur_unit_select" class="form-select fw-bold text-primary">
                                <option value="ton">Ton (1000 KG)</option>
                                <option value="kg">KG (Weight)</option>
                                <option value="piece">Pieces / Bars</option>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Quantity Inward <span class="text-danger">*</span></label>
                            <input type="number" step="0.001" min="0.001" id="pur_qty" class="form-control fw-bold" placeholder="e.g. 5">
                            <div class="form-text small" id="pur_qty_hint">Ton (5,000 KG)</div>
                        </div>

                        <!-- Rate per unit -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">
                                Purchase Rate (Rs.) <span class="text-danger">*</span>
                            </label>
                            <input type="number" step="0.5" id="pur_rate" class="form-control fw-bold text-danger" placeholder="230000.00">
                            <div class="form-text small" id="pur_rate_hint">Per Ton (e.g. Rs. 230,000)</div>
                        </div>

                        <!-- Add Button -->
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" id="btn_add_to_pur_cart" class="btn btn-primary w-100 fw-bold py-2">
                                <i class="fa-solid fa-plus me-1"></i> Add Item
                            </button>
                        </div>

                        <!-- Dynamic Weight & Rate Preview Helper -->
                        <div class="col-12">
                            <div id="pur_preview_box" class="p-2 rounded bg-light border d-flex justify-content-between align-items-center small flex-wrap gap-2">
                                <span class="text-muted"><i class="fa-solid fa-calculator me-1 text-primary"></i> Select an item and quantity to see total added weight and rate breakdown.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inward Items Table -->
            <div class="card card-custom">
                <div class="card-header card-header-clean d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fa-solid fa-list-check text-primary me-2"></i>Items Inwarded in this Shipment</span>
                    <span id="pur_cart_badge" class="badge bg-primary">0 Items</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Inward Qty</th>
                                <th class="text-center">Added Weight</th>
                                <th>Rate (Rs.)</th>
                                <th>Total Cost (Rs.)</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="pur_cart_tbody">
                            <tr id="pur_empty_cart_row">
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-truck-ramp-box fs-3 mb-2 d-block text-secondary"></i>
                                    No items added to this shipment yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Right Column: Shipment Summary & Payable -->
        <div class="col-lg-4">
            <div class="position-sticky" style="top: 80px;">
                <div class="card card-custom shadow-sm border-primary">
                    <div class="card-header bg-primary text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-receipt me-2"></i> Purchase Summary</span>
                        <span id="pur_weight_badge" class="badge bg-white text-primary fw-bold">0 KG</span>
                    </div>
                    <div class="card-body p-4">
                        
                        <!-- Weight Inward Card -->
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small fw-bold"><i class="fa-solid fa-weight-hanging me-1 text-primary"></i> Total Weight Added:</span>
                                <span class="fw-bold fs-6 text-primary" id="pur_display_total_weight">0.00 KG</span>
                            </div>
                            <div class="small text-muted" id="pur_display_ton_weight">
                                (~0.00 Ton added to store stock)
                            </div>
                        </div>

                        <!-- Subtotal -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Items Subtotal:</span>
                            <span class="fw-bold fs-5 text-dark" id="pur_display_subtotal">Rs. 0.00</span>
                        </div>

                        <!-- Extra Carriage / Freight -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                + Carriage / Kiraya / Mazdoori (Rs.):
                            </label>
                            <input type="number" step="1" min="0" id="pur_extra_cost" name="extra_cost" class="form-control" value="0">
                            <div class="form-text small">Freight/loading charges paid on truck.</div>
                        </div>

                        <hr class="my-2">

                        <!-- Grand Total -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold fs-5 text-dark">Grand Total:</span>
                            <span class="fw-bold fs-3 text-primary" id="pur_display_grand_total">Rs. 0.00</span>
                        </div>

                        <!-- Payment Method -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">Payment Method:</label>
                            <select name="payment_method" class="form-select">
                                <option value="Bank Transfer">Bank Transfer / Online</option>
                                <option value="Cash">Cash (Naqd)</option>
                                <option value="Cheque">Bank Cheque</option>
                                <option value="Credit / Payable">Supplier Udhar (Payable Later)</option>
                            </select>
                        </div>

                        <!-- Amount Paid to Supplier -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                Amount Paid to Factory / Supplier:
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="1" min="0" id="pur_paid" name="paid_amount" class="form-control fw-bold text-success" value="0">
                            </div>
                        </div>

                        <!-- Balance Due to Supplier -->
                        <div class="p-2 rounded bg-light border d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-semibold text-secondary">Payable to Factory (Udhar):</span>
                            <span class="fw-bold fs-5" id="pur_display_due">Rs. 0.00</span>
                        </div>

                        <!-- Option: Update master product cost -->
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="update_product_cost" id="update_product_cost" value="1" checked>
                            <label class="form-check-label small fw-semibold text-dark" for="update_product_cost">
                                Automatically update product purchase cost (Lagat) to this new rate
                            </label>
                        </div>

                        <!-- Notes -->
                        <div class="mb-3">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional purchase notes (e.g. Grade 60 batch)">
                        </div>

                        <!-- Hidden Cart Items JSON -->
                        <input type="hidden" name="items_json" id="pur_items_json_input" value="[]">

                        <!-- Submit Button -->
                        <button type="submit" name="save_purchase" id="btn_save_purchase" class="btn btn-primary btn-lg w-100 fw-bold py-3 shadow-sm" disabled>
                            <i class="fa-solid fa-circle-check me-2"></i> Save Purchase & Add Stock
                        </button>

                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
// Purchases Inward JS Logic
document.addEventListener('DOMContentLoaded', function () {
    const prodSelect    = document.getElementById('pur_product_select');
    const unitSelect    = document.getElementById('pur_unit_select');
    const qtyInput      = document.getElementById('pur_qty');
    const rateInput     = document.getElementById('pur_rate');
    const qtyHint       = document.getElementById('pur_qty_hint');
    const rateHint      = document.getElementById('pur_rate_hint');
    const previewBox    = document.getElementById('pur_preview_box');
    const btnAddToCart  = document.getElementById('btn_add_to_pur_cart');

    const cartTbody     = document.getElementById('pur_cart_tbody');
    const emptyRow      = document.getElementById('pur_empty_cart_row');
    const cartBadge     = document.getElementById('pur_cart_badge');
    const weightBadge   = document.getElementById('pur_weight_badge');
    const itemsJsonInput= document.getElementById('pur_items_json_input');
    const btnSave       = document.getElementById('btn_save_purchase');

    const displaySubtotal   = document.getElementById('pur_display_subtotal');
    const displayGrandTotal = document.getElementById('pur_display_grand_total');
    const displayDue        = document.getElementById('pur_display_due');
    const displayTotalWeight= document.getElementById('pur_display_total_weight');
    const displayTonWeight  = document.getElementById('pur_display_ton_weight');

    const extraCostInput    = document.getElementById('pur_extra_cost');
    const paidInput         = document.getElementById('pur_paid');

    let cart = [];

    function updateProductHints() {
        const option = prodSelect.options[prodSelect.selectedIndex];
        if (!option || !option.value) {
            previewBox.innerHTML = '<span class="text-muted"><i class="fa-solid fa-calculator me-1 text-primary"></i> Select an item and quantity to see total added weight and rate breakdown.</span>';
            return;
        }

        const unit = unitSelect.value;
        const costKg = parseFloat(option.dataset.costKg) || 0;
        const weightPiece = parseFloat(option.dataset.weightPiece) || 0;

        if (unit === 'ton') {
            qtyHint.textContent = 'Ton (1 Ton = 1,000 KG)';
            rateHint.textContent = `Rate per Ton (Current: Rs. ${(costKg * 1000).toFixed(0)})`;
            if (!rateInput.value || rateInput.dataset.autoRate === "1") {
                rateInput.value = (costKg * 1000).toFixed(0);
                rateInput.dataset.autoRate = "1";
            }
        } else if (unit === 'kg') {
            qtyHint.textContent = 'KG (Direct Weight)';
            rateHint.textContent = `Rate per KG (Current: Rs. ${costKg.toFixed(2)})`;
            if (!rateInput.value || rateInput.dataset.autoRate === "1") {
                rateInput.value = costKg.toFixed(2);
                rateInput.dataset.autoRate = "1";
            }
        } else if (unit === 'piece') {
            qtyHint.textContent = `Pieces (1 Piece = ${weightPiece} KG)`;
            const costPiece = (costKg * weightPiece).toFixed(0);
            rateHint.textContent = `Rate per Piece (Current: Rs. ${costPiece})`;
            if (!rateInput.value || rateInput.dataset.autoRate === "1") {
                rateInput.value = costPiece;
                rateInput.dataset.autoRate = "1";
            }
        }

        updatePreview();
    }

    function updatePreview() {
        const option = prodSelect.options[prodSelect.selectedIndex];
        if (!option || !option.value) return;

        const unit = unitSelect.value;
        const qty = parseFloat(qtyInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        const weightPiece = parseFloat(option.dataset.weightPiece) || 0;

        let addedKg = 0;
        let lineTotal = qty * rate;

        if (unit === 'ton') addedKg = qty * 1000;
        else if (unit === 'kg') addedKg = qty;
        else if (unit === 'piece') addedKg = qty * weightPiece;

        const effectiveCostKg = addedKg > 0 ? (lineTotal / addedKg).toFixed(2) : '0.00';

        previewBox.innerHTML = `
            <div class="fw-semibold text-primary">
                <i class="fa-solid fa-weight-hanging me-1"></i> Stock Added: <strong>${addedKg.toFixed(1)} KG</strong> (~${(addedKg/1000).toFixed(2)} Ton)
            </div>
            <div class="fw-bold text-dark">
                Cost per KG: Rs. ${effectiveCostKg} | Line Total: Rs. ${lineTotal.toLocaleString('en-US', {minimumFractionDigits: 2})}
            </div>
        `;
    }

    prodSelect.addEventListener('change', updateProductHints);
    unitSelect.addEventListener('change', function () {
        rateInput.dataset.autoRate = "1";
        updateProductHints();
    });
    qtyInput.addEventListener('input', updatePreview);
    rateInput.addEventListener('input', function () {
        rateInput.dataset.autoRate = "0";
        updatePreview();
    });

    // Add Item to Purchase Cart
    btnAddToCart.addEventListener('click', function () {
        const option = prodSelect.options[prodSelect.selectedIndex];
        if (!option || !option.value) {
            alert('Please select a product first.');
            return;
        }

        const qty = parseFloat(qtyInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;

        if (qty <= 0) {
            alert('Please enter a valid quantity.');
            return;
        }

        const pid = option.value;
        const pname = option.dataset.name;
        const unit = unitSelect.value;
        const weightPiece = parseFloat(option.dataset.weightPiece) || 0;

        let addedKg = 0;
        if (unit === 'ton') addedKg = qty * 1000;
        else if (unit === 'kg') addedKg = qty;
        else if (unit === 'piece') addedKg = qty * weightPiece;

        const lineTotal = qty * rate;

        cart.push({
            product_id: pid,
            product_name: pname,
            purchase_unit: unit,
            quantity: qty,
            rate_per_unit: rate,
            subtotal: lineTotal,
            added_stock_kg: addedKg
        });

        renderCart();

        // Reset inputs
        prodSelect.value = '';
        qtyInput.value = '';
        rateInput.value = '';
        updateProductHints();
    });

    function renderCart() {
        if (cart.length === 0) {
            cartTbody.innerHTML = `
                <tr id="pur_empty_cart_row">
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="fa-solid fa-truck-ramp-box fs-3 mb-2 d-block text-secondary"></i>
                        No items added to this shipment yet.
                    </td>
                </tr>
            `;
            cartBadge.textContent = '0 Items';
            weightBadge.textContent = '0 KG';
            btnSave.disabled = true;
            displaySubtotal.textContent = 'Rs. 0.00';
            displayGrandTotal.textContent = 'Rs. 0.00';
            displayDue.textContent = 'Rs. 0.00';
            displayTotalWeight.textContent = '0.00 KG';
            displayTonWeight.textContent = '(~0.00 Ton added to store stock)';
            itemsJsonInput.value = '[]';
            return;
        }

        btnSave.disabled = false;
        cartBadge.textContent = `${cart.length} Items`;

        let html = '';
        let subtotal = 0;
        let totalWeightKg = 0;

        cart.forEach((item, index) => {
            subtotal += item.subtotal;
            totalWeightKg += item.added_stock_kg;

            let unitBadge = 'bg-primary-subtle text-primary';
            if (item.purchase_unit === 'ton') unitBadge = 'bg-warning-subtle text-dark';
            if (item.purchase_unit === 'kg') unitBadge = 'bg-success-subtle text-success';

            html += `
                <tr>
                    <td>${index + 1}</td>
                    <td class="fw-bold text-dark">${item.product_name}</td>
                    <td>
                        <span class="fw-bold">${item.quantity}</span> 
                        <span class="badge ${unitBadge} ms-1">${item.purchase_unit.toUpperCase()}</span>
                    </td>
                    <td class="text-center fw-bold text-primary">
                        +${item.added_stock_kg.toFixed(1)} KG
                    </td>
                    <td>Rs. ${item.rate_per_unit.toFixed(2)}</td>
                    <td class="fw-bold text-dark">Rs. ${item.subtotal.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 btn-remove-item" data-index="${index}">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </td>
                </tr>
            `;
        });

        cartTbody.innerHTML = html;
        itemsJsonInput.value = JSON.stringify(cart);

        // Update Weight Display
        weightBadge.textContent = `${totalWeightKg.toFixed(0)} KG`;
        displayTotalWeight.textContent = `${totalWeightKg.toLocaleString('en-US', {minimumFractionDigits: 1})} KG`;
        displayTonWeight.textContent = `(~${(totalWeightKg / 1000).toFixed(2)} Ton added to store stock)`;

        // Calculate Totals
        const extraCost = parseFloat(extraCostInput.value) || 0;
        const grandTotal = subtotal + extraCost;

        // Auto fill paid input if not modified
        if (parseFloat(paidInput.value) === 0 || paidInput.dataset.autoFilled === "1") {
            paidInput.value = grandTotal;
            paidInput.dataset.autoFilled = "1";
        }

        const paid = parseFloat(paidInput.value) || 0;
        const due = Math.max(0, grandTotal - paid);

        displaySubtotal.textContent = 'Rs. ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2 });
        displayGrandTotal.textContent = 'Rs. ' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2 });
        
        displayDue.textContent = 'Rs. ' + due.toLocaleString('en-US', { minimumFractionDigits: 2 });
        displayDue.className = due > 0 ? 'fw-bold fs-5 text-danger' : 'fw-bold fs-5 text-success';

        // Attach remove item
        document.querySelectorAll('.btn-remove-item').forEach(btn => {
            btn.addEventListener('click', function () {
                const idx = parseInt(this.dataset.index);
                cart.splice(idx, 1);
                renderCart();
            });
        });
    }

    extraCostInput.addEventListener('input', function () {
        paidInput.dataset.autoFilled = "0";
        renderCart();
    });

    paidInput.addEventListener('input', function () {
        paidInput.dataset.autoFilled = "0";
        renderCart();
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
