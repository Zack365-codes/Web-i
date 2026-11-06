<?php
// pos.php - Billing & Invoice Generator
$page_title = "New Sale / Billing POS";
require_once __DIR__ . '/includes/header.php';

// Fetch all available products with stock
$products = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT * FROM products ORDER BY product_name ASC");
        $products = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to load products: " . $e->getMessage();
    }
}

// Handle Invoice Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice'])) {
    if (!isset($pdo)) {
        set_flash('danger', 'Database is not connected.');
    } else {
        $customer_name   = trim($_POST['customer_name'] ?? '');
        $customer_phone  = trim($_POST['customer_phone'] ?? '');
        $payment_method  = trim($_POST['payment_method'] ?? 'Cash');
        $discount        = floatval($_POST['discount'] ?? 0);
        $paid_amount     = floatval($_POST['paid_amount'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');
        $items_json      = $_POST['items_json'] ?? '[]';
        $cart_items      = json_decode($items_json, true) ?: [];

        if (empty($cart_items)) {
            $error = "Please add at least 1 item to the bill.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Generate unique invoice number: INV-YYYYMMDD-XXXX
                $date_prefix = date('Ymd');
                $count_stmt = $pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at) = CURDATE()");
                $daily_count = $count_stmt->fetchColumn() + 1;
                $invoice_no = "INV-" . $date_prefix . "-" . str_pad($daily_count, 3, '0', STR_PAD_LEFT);

                // 2. Calculate subtotal, grand total, and profit
                $subtotal = 0;
                $total_profit = 0;
                $processed_items = [];

                foreach ($cart_items as $item) {
                    $pid   = intval($item['product_id']);
                    $unit  = $item['sale_unit']; // 'kg', 'piece', 'feet'
                    $qty   = floatval($item['quantity']);
                    $price = floatval($item['unit_price']);

                    // Fetch latest product details from DB for security and stock check
                    $p_stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                    $p_stmt->execute([$pid]);
                    $prod = $p_stmt->fetch();

                    if (!$prod) {
                        throw new Exception("Product ID {$pid} not found.");
                    }

                    $weight_foot  = (float)$prod['weight_per_foot'];
                    $length_feet  = (float)($prod['standard_length_feet'] ?: 20);
                    $weight_piece = (float)($prod['weight_per_piece'] ?: ($weight_foot * $length_feet));
                    $cost_kg      = (float)$prod['cost_price_per_kg'];

                    // Calculate deducted KG, equivalent pieces, and unit cost based on selected unit
                    $deducted_kg = 0;
                    $unit_cost = 0;
                    $equiv_pieces = 0;

                    if ($unit === 'piece') {
                        $deducted_kg = $qty * $weight_piece;
                        $unit_cost = $cost_kg * $weight_piece;
                        $equiv_pieces = $qty;
                    } elseif ($unit === 'kg') {
                        $deducted_kg = $qty;
                        $unit_cost = $cost_kg;
                        $equiv_pieces = $weight_piece > 0 ? ($qty / $weight_piece) : 0;
                    } elseif ($unit === 'feet') {
                        $deducted_kg = $qty * $weight_foot;
                        $unit_cost = $cost_kg * $weight_foot;
                        $equiv_pieces = $length_feet > 0 ? ($qty / $length_feet) : 0;
                    }

                    $item_subtotal = $qty * $price;
                    $item_profit = ($price - $unit_cost) * $qty;

                    $subtotal += $item_subtotal;
                    $total_profit += $item_profit;

                    // Deduct stock from products table
                    $deduct_stmt = $pdo->prepare("UPDATE products SET total_stock_kg = total_stock_kg - ? WHERE id = ?");
                    $deduct_stmt->execute([$deducted_kg, $pid]);

                    $processed_items[] = [
                        'product_id'        => $pid,
                        'product_name'      => $prod['product_name'],
                        'sale_unit'         => $unit,
                        'quantity'          => $qty,
                        'unit_cost'         => $unit_cost,
                        'unit_price'        => $price,
                        'subtotal'          => $item_subtotal,
                        'item_profit'       => $item_profit,
                        'deducted_stock_kg' => $deducted_kg,
                        'equivalent_pieces' => $equiv_pieces
                    ];
                }

                $grand_total = max(0, $subtotal - $discount);
                $total_profit = $total_profit - $discount; // discount reduces realized profit

                // Determine payment status
                $due_amount = max(0, $grand_total - $paid_amount);
                $payment_status = 'paid';
                if ($due_amount > 0) {
                    $payment_status = ($paid_amount > 0) ? 'partial' : 'due';
                }

                // 3. Insert into sales table
                $insert_sale = $pdo->prepare("
                    INSERT INTO sales (
                        invoice_no, customer_name, customer_phone,
                        subtotal, discount, grand_total, paid_amount, due_amount,
                        payment_status, payment_method, total_profit, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert_sale->execute([
                    $invoice_no, $customer_name, $customer_phone,
                    $subtotal, $discount, $grand_total, $paid_amount, $due_amount,
                    $payment_status, $payment_method, $total_profit, $notes
                ]);
                $sale_id = $pdo->lastInsertId();

                // 4. Insert into sale_items table
                $insert_item = $pdo->prepare("
                    INSERT INTO sale_items (
                        sale_id, product_id, product_name, sale_unit,
                        quantity, unit_cost, unit_price, subtotal, item_profit, deducted_stock_kg, equivalent_pieces
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($processed_items as $pi) {
                    $insert_item->execute([
                        $sale_id, $pi['product_id'], $pi['product_name'], $pi['sale_unit'],
                        $pi['quantity'], $pi['unit_cost'], $pi['unit_price'], $pi['subtotal'],
                        $pi['item_profit'], $pi['deducted_stock_kg'], $pi['equivalent_pieces']
                    ]);
                }

                $pdo->commit();

                // Redirect directly to the print view with auto-print
                echo "<script>window.location.href = 'invoice_print.php?id={$sale_id}&autoprint=1';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to create invoice: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold mb-1"><i class="fa-solid fa-cash-register text-success me-2"></i>New Sale / Billing POS</h3>
        <p class="text-muted mb-0">Sell in Feet, KG, or Pieces — the bill displays both the sold unit and <strong>exact pieces count</strong>.</p>
    </div>
    <div class="col-md-5 text-md-end">
        <a href="invoices.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-invoice me-1"></i> Past Invoices
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form action="pos.php" method="POST" id="billingForm">
    <div class="row g-4">
        
        <!-- Left Side: Product Selection & Cart Table -->
        <div class="col-lg-8">
            
            <!-- Step 1: Customer Info -->
            <div class="card card-custom mb-3">
                <div class="card-body p-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                <i class="fa-solid fa-user me-1 text-primary"></i> Customer Name:
                            </label>
                            <input type="text" name="customer_name" class="form-control" placeholder="Walk-in Customer (or Name)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                <i class="fa-solid fa-phone me-1 text-primary"></i> Customer Phone:
                            </label>
                            <input type="text" name="customer_phone" class="form-control" placeholder="0300-1234567">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Add Item to Bill Box -->
            <div class="card card-custom mb-3 border-primary">
                <div class="card-header bg-primary-subtle text-primary fw-bold py-2">
                    <i class="fa-solid fa-cart-plus me-1"></i> Add Item to Invoice
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <!-- Product Selection -->
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Select Product <span class="text-danger">*</span></label>
                            <select id="pos_product_select" class="form-select form-select-lg">
                                <option value="">-- Choose Product from Inventory --</option>
                                <?php foreach ($products as $pr): 
                                    $w_piece = (float)($pr['weight_per_piece'] ?: ($pr['weight_per_foot'] * $pr['standard_length_feet']));
                                    $length_ft = (float)($pr['standard_length_feet'] ?: 20);
                                    $pcs = $w_piece > 0 ? floor($pr['total_stock_kg'] / $w_piece) : 0;

                                    $sell_piece = (float)$pr['sell_price_per_piece'];
                                    $sell_kg    = (float)$pr['sell_price_per_kg'];
                                    $sell_foot  = (float)$pr['sell_price_per_foot'];

                                    // Auto-derive missing selling rates across all units
                                    if ($sell_kg <= 0 && $sell_piece > 0 && $w_piece > 0) {
                                        $sell_kg = round($sell_piece / $w_piece, 2);
                                    }
                                    if ($sell_piece <= 0 && $sell_kg > 0 && $w_piece > 0) {
                                        $sell_piece = round($sell_kg * $w_piece, 2);
                                    }
                                    if ($sell_foot <= 0) {
                                        if ($sell_piece > 0 && $length_ft > 0) {
                                            $sell_foot = round($sell_piece / $length_ft, 2);
                                        } elseif ($sell_kg > 0 && (float)$pr['weight_per_foot'] > 0) {
                                            $sell_foot = round($sell_kg * (float)$pr['weight_per_foot'], 2);
                                        }
                                    }

                                    // Minimum allowed rates (bargain limit)
                                    $min_piece = (float)$pr['min_price_per_piece'];
                                    $min_kg    = (float)$pr['min_price_per_kg'];
                                    $min_foot  = (float)$pr['min_price_per_foot'];

                                    if ($min_piece <= 0 && $sell_piece > 0) {
                                        $min_piece = round($sell_piece * 0.95, 2);
                                    }
                                    if ($min_kg <= 0) {
                                        if ($min_piece > 0 && $w_piece > 0) {
                                            $min_kg = round($min_piece / $w_piece, 2);
                                        } elseif ($sell_kg > 0) {
                                            $min_kg = round($sell_kg * 0.95, 2);
                                        }
                                    }
                                    if ($min_foot <= 0) {
                                        if ($min_piece > 0 && $length_ft > 0) {
                                            $min_foot = round($min_piece / $length_ft, 2);
                                        } elseif ($sell_foot > 0) {
                                            $min_foot = round($sell_foot * 0.95, 2);
                                        }
                                    }
                                    
                                    // Security: Only Super Admin gets cost price in data attribute
                                    $cost_kg_attr = is_super_admin() ? (float)$pr['cost_price_per_kg'] : 0;
                                ?>
                                    <option value="<?= $pr['id'] ?>"
                                            data-name="<?= htmlspecialchars($pr['product_name']) ?>"
                                            data-cost-kg="<?= $cost_kg_attr ?>"
                                            data-sell-kg="<?= $sell_kg ?>"
                                            data-sell-piece="<?= $sell_piece ?>"
                                            data-sell-foot="<?= $sell_foot ?>"
                                            data-min-kg="<?= $min_kg ?>"
                                            data-min-piece="<?= $min_piece ?>"
                                            data-min-foot="<?= $min_foot ?>"
                                            data-weight-piece="<?= $w_piece ?>"
                                            data-weight-foot="<?= (float)$pr['weight_per_foot'] ?>"
                                            data-length-feet="<?= $length_ft ?>"
                                            data-stock-kg="<?= (float)$pr['total_stock_kg'] ?>"
                                            data-stock-pieces="<?= $pcs ?>">
                                        <?= htmlspecialchars($pr['product_name']) ?> &bull; (Stock: <?= $pcs ?> Pcs / <?= number_format($pr['total_stock_kg'], 1) ?> KG)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Sale Unit -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Selling Unit <span class="text-danger">*</span></label>
                            <select id="pos_sale_unit" class="form-select fw-bold text-primary">
                                <option value="piece">Per Piece</option>
                                <option value="kg">Per KG (Weight)</option>
                                <option value="feet">Per Foot (Length)</option>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" id="pos_quantity" class="form-control fw-bold" placeholder="1" value="1">
                            <div class="form-text small" id="pos_qty_hint">Pieces</div>
                        </div>

                        <!-- Rate (Editable on the spot) -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">
                                Unit Rate (Rs.) <span class="text-danger">*</span>
                            </label>
                            <input type="number" step="0.5" id="pos_unit_rate" class="form-control fw-bold text-success" placeholder="0.00">
                            <div class="form-text small text-muted">Editable on bargaining</div>
                        </div>

                        <!-- Add Button -->
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" id="btn_add_to_cart" class="btn btn-primary w-100 fw-bold py-2">
                                <i class="fa-solid fa-plus me-1"></i> Add to Bill
                            </button>
                        </div>

                        <!-- Live Piece Conversion & Profit Guard -->
                        <div class="col-12">
                            <div id="pos_profit_guard" class="p-2 rounded bg-light border d-flex justify-content-between align-items-center small flex-wrap gap-2">
                                <span class="text-muted"><i class="fa-solid fa-shield-halved me-1 text-primary"></i> <strong>Profit Guard:</strong> Select a product and unit to check profit margin.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cart Table -->
            <div class="card card-custom">
                <div class="card-header card-header-clean d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fa-solid fa-list-check text-primary me-2"></i>Items on Bill</span>
                    <span id="cart_badge" class="badge bg-primary">0 Items</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Sold Qty</th>
                                <th class="text-center">Equivalent Pieces</th>
                                <th>Rate (Rs.)</th>
                                <th>Total (Rs.)</th>
                                <th>Stock Minus</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="cart_tbody">
                            <tr id="empty_cart_row">
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-basket-shopping fs-3 mb-2 d-block"></i>
                                    No items added to this bill yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Right Side: Invoice Summary & Quick Print -->
        <div class="col-lg-4">
            <div class="position-sticky" style="top: 80px;">
                <div class="card card-custom shadow-sm border-success">
                    <div class="card-header bg-success text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-file-invoice-dollar me-2"></i> Bill Summary</span>
                        <span id="summary_pieces_badge" class="badge bg-white text-success fw-bold">0 Pieces</span>
                    </div>
                    <div class="card-body p-4">
                        
                        <!-- Pieces & Weight Totals Banner -->
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small fw-bold"><i class="fa-solid fa-shapes me-1 text-primary"></i> Total Pieces Sold:</span>
                                <span class="fw-bold fs-6 text-primary" id="summary_total_pieces">0.00 Pieces</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small fw-bold"><i class="fa-solid fa-weight-hanging me-1 text-secondary"></i> Total Weight:</span>
                                <span class="fw-bold text-dark" id="summary_total_weight">0.00 KG</span>
                            </div>
                        </div>

                        <!-- Subtotal -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Bill Subtotal:</span>
                            <span class="fw-bold fs-5 text-dark" id="display_subtotal">Rs. 0.00</span>
                        </div>

                        <!-- Discount (Bargaining / Round off) -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                Discount / Kami-Peshi (Flat Rs.):
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-danger fw-bold">- Rs.</span>
                                <input type="number" step="1" min="0" id="pos_discount" name="discount" class="form-control fw-bold text-danger" value="0">
                            </div>
                        </div>

                        <hr class="my-2">

                        <!-- Grand Total -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold fs-5 text-dark">Grand Total:</span>
                            <span class="fw-bold fs-3 text-success" id="display_grand_total">Rs. 0.00</span>
                        </div>

                        <!-- Payment Method -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">Payment Method:</label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash">Cash (Naqd)</option>
                                <option value="Bank Transfer">Bank Transfer / Online</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Credit / Udhar">Credit / Udhar</option>
                            </select>
                        </div>

                        <!-- Amount Paid -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                Amount Paid by Customer:
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" step="1" min="0" id="pos_paid" name="paid_amount" class="form-control fw-bold text-dark" value="0">
                            </div>
                        </div>

                        <!-- Balance Due -->
                        <div class="p-2 rounded bg-light border d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-semibold text-secondary">Balance Due (Udhar):</span>
                            <span class="fw-bold fs-5" id="display_due">Rs. 0.00</span>
                        </div>

                        <!-- Notes -->
                        <div class="mb-3">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional bill note (e.g. Delivery terms)">
                        </div>

                        <!-- Hidden Cart Items JSON -->
                        <input type="hidden" name="items_json" id="items_json_input" value="[]">

                        <!-- Submit Button -->
                        <button type="submit" name="save_invoice" id="btn_save_invoice" class="btn btn-success btn-lg w-100 fw-bold py-3 shadow-sm" disabled>
                            <i class="fa-solid fa-print me-2"></i> Generate & Print Bill
                        </button>

                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
// POS JavaScript Logic
document.addEventListener('DOMContentLoaded', function () {
    const productSelect = document.getElementById('pos_product_select');
    const unitSelect    = document.getElementById('pos_sale_unit');
    const qtyInput      = document.getElementById('pos_quantity');
    const rateInput     = document.getElementById('pos_unit_rate');
    const qtyHint       = document.getElementById('pos_qty_hint');
    const profitGuard   = document.getElementById('pos_profit_guard');
    const btnAddToCart  = document.getElementById('btn_add_to_cart');
    
    const cartTbody     = document.getElementById('cart_tbody');
    const emptyCartRow  = document.getElementById('empty_cart_row');
    const cartBadge     = document.getElementById('cart_badge');
    const itemsJsonInput= document.getElementById('items_json_input');
    const btnSaveInvoice= document.getElementById('btn_save_invoice');

    const displaySubtotal   = document.getElementById('display_subtotal');
    const displayGrandTotal = document.getElementById('display_grand_total');
    const displayDue        = document.getElementById('display_due');
    const discountInput     = document.getElementById('pos_discount');
    const paidInput         = document.getElementById('pos_paid');

    const summaryTotalPieces = document.getElementById('summary_total_pieces');
    const summaryTotalWeight = document.getElementById('summary_total_weight');
    const summaryPiecesBadge = document.getElementById('summary_pieces_badge');

    const isSuperAdmin = <?= is_super_admin() ? 'true' : 'false' ?>;
    let cart = [];

    // When Product or Unit changes, auto-fill unit rate and update Profit Guard & Conversion
    function updateSelectedProductDetails() {
        const unit = unitSelect.value;
        const option = productSelect.options[productSelect.selectedIndex];

        // Update default quantity hint even if no product is selected yet
        if (!option || !option.value) {
            rateInput.value = '';
            if (unit === 'piece') {
                qtyHint.innerHTML = '<span class="text-muted">Pieces (Full Bar / Pipe / Gate)</span>';
            } else if (unit === 'kg') {
                qtyHint.innerHTML = '<span class="text-muted">KG (Weight on Kanta)</span>';
            } else if (unit === 'feet') {
                qtyHint.innerHTML = '<span class="text-muted">Feet (Length in Running Feet)</span>';
            }
            profitGuard.innerHTML = '<span class="text-muted"><i class="fa-solid fa-circle-info text-primary me-1"></i> <strong>Piece & Weight Suggestion:</strong> Select a product to see how many Feet or KG make 1 piece and check allowed rates.</span>';
            btnAddToCart.disabled = true;
            return;
        }

        const costKg = parseFloat(option.dataset.costKg) || 0;
        const weightPiece = parseFloat(option.dataset.weightPiece) || 0;
        const weightFoot = parseFloat(option.dataset.weightFoot) || 0;
        const lengthFeet = parseFloat(option.dataset.lengthFeet) || 20;

        let sellPiece = parseFloat(option.dataset.sellPiece) || 0;
        let sellKg    = parseFloat(option.dataset.sellKg) || 0;
        let sellFoot  = parseFloat(option.dataset.sellFoot) || 0;

        // Auto fallback calculation if any unit rate is 0 in dataset
        if (sellKg <= 0 && sellPiece > 0 && weightPiece > 0) sellKg = sellPiece / weightPiece;
        if (sellPiece <= 0 && sellKg > 0 && weightPiece > 0) sellPiece = sellKg * weightPiece;
        if (sellFoot <= 0 && lengthFeet > 0 && sellPiece > 0) sellFoot = sellPiece / lengthFeet;

        let defaultRate = 0;
        let unitCost = 0;

        if (unit === 'piece') {
            defaultRate = sellPiece;
            unitCost = costKg * weightPiece;
            qtyHint.innerHTML = `<span class="text-primary fw-bold">1 Piece = ${weightPiece.toFixed(2)} KG</span> (${lengthFeet} Feet)`;
        } else if (unit === 'kg') {
            defaultRate = sellKg;
            unitCost = costKg;
            const pieceEq = weightPiece > 0 ? (1 / weightPiece).toFixed(2) : '0';
            qtyHint.innerHTML = `<span class="text-primary fw-bold">1 Piece = ${weightPiece.toFixed(2)} KG</span> (~${pieceEq} Pcs/KG)`;
        } else if (unit === 'feet') {
            defaultRate = sellFoot;
            unitCost = costKg * weightFoot;
            qtyHint.innerHTML = `<span class="text-primary fw-bold">${lengthFeet} Feet = 1 Piece</span> (~${weightPiece.toFixed(2)} KG)`;
        }

        rateInput.value = defaultRate > 0 ? defaultRate.toFixed(2) : '0.00';
        checkProfitGuardAndConversion();
    }

    function checkProfitGuardAndConversion() {
        const option = productSelect.options[productSelect.selectedIndex];
        if (!option || !option.value) {
            updateSelectedProductDetails();
            return;
        }

        const costKg = parseFloat(option.dataset.costKg) || 0;
        const weightPiece = parseFloat(option.dataset.weightPiece) || 0;
        const weightFoot = parseFloat(option.dataset.weightFoot) || 0;
        const lengthFeet = parseFloat(option.dataset.lengthFeet) || 20;
        const unit = unitSelect.value;
        const qty = parseFloat(qtyInput.value) || 0;
        const currentRate = parseFloat(rateInput.value) || 0;

        let sellPiece = parseFloat(option.dataset.sellPiece) || 0;
        let sellKg    = parseFloat(option.dataset.sellKg) || 0;
        let sellFoot  = parseFloat(option.dataset.sellFoot) || 0;

        if (sellKg <= 0 && sellPiece > 0 && weightPiece > 0) sellKg = sellPiece / weightPiece;
        if (sellPiece <= 0 && sellKg > 0 && weightPiece > 0) sellPiece = sellKg * weightPiece;
        if (sellFoot <= 0 && lengthFeet > 0 && sellPiece > 0) sellFoot = sellPiece / lengthFeet;

        let minPiece = parseFloat(option.dataset.minPiece) || (sellPiece * 0.95);
        let minKg    = parseFloat(option.dataset.minKg) || (weightPiece > 0 ? minPiece / weightPiece : sellKg * 0.95);
        let minFoot  = parseFloat(option.dataset.minFoot) || (lengthFeet > 0 ? minPiece / lengthFeet : sellFoot * 0.95);

        let unitCost = 0;
        let equivPieces = 0;
        let equivWeight = 0;
        let minRate = 0;
        let standardRate = 0;

        if (unit === 'piece') {
            unitCost = costKg * weightPiece;
            standardRate = sellPiece;
            minRate = minPiece;
            equivPieces = qty;
            equivWeight = qty * weightPiece;
            qtyHint.innerHTML = `<span class="text-primary fw-bold">1 Piece = ${weightPiece.toFixed(2)} KG</span> (${lengthFeet} Feet)`;
        } else if (unit === 'kg') {
            unitCost = costKg;
            standardRate = sellKg;
            minRate = minKg;
            equivPieces = weightPiece > 0 ? (qty / weightPiece) : 0;
            equivWeight = qty;
            const pieceEq = weightPiece > 0 ? (1 / weightPiece).toFixed(2) : '0';
            qtyHint.innerHTML = `<span class="text-primary fw-bold">1 Piece = ${weightPiece.toFixed(2)} KG</span> (~${pieceEq} Pcs/KG)`;
        } else if (unit === 'feet') {
            unitCost = costKg * weightFoot;
            standardRate = sellFoot;
            minRate = minFoot;
            equivPieces = lengthFeet > 0 ? (qty / lengthFeet) : 0;
            equivWeight = qty * weightFoot;
            qtyHint.innerHTML = `<span class="text-primary fw-bold">${lengthFeet} Feet = 1 Piece</span> (~${weightPiece.toFixed(2)} KG)`;
        }

        const profit = currentRate - unitCost;
        const margin = unitCost > 0 ? ((profit / unitCost) * 100).toFixed(1) : 0;

        let guardHtml = '';

        // Check if entered rate is below minimum allowed bargain rate or zero
        const isBelowMin = (currentRate <= 0) || (minRate > 0 && currentRate < minRate);

        if (isBelowMin) {
            // BLOCK / STOP: Rate is lower than minimum allowed limit!
            btnAddToCart.disabled = true;
            rateInput.classList.add('is-invalid');
            rateInput.classList.remove('is-valid');

            guardHtml = `
                <div class="text-danger fw-bold d-flex align-items-center">
                    <i class="fa-solid fa-ban me-2 fs-4 text-danger"></i>
                    <div>
                        <div>RATE TOO LOW: Kam se kam rate limit <strong>Rs. ${minRate.toFixed(2)}</strong> hai!</div>
                        <small class="text-danger fw-normal">You cannot sell below minimum allowed rate (Rs. ${minRate.toFixed(2)}).</small>
                    </div>
                </div>
            `;
        } else {
            // ALLOW: Rate is at or above minimum allowed limit
            btnAddToCart.disabled = false;
            rateInput.classList.remove('is-invalid');
            rateInput.classList.add('is-valid');

            if (!isSuperAdmin) {
                // Salesman view (No profit/cost)
                guardHtml = `
                    <div class="text-success fw-bold d-flex align-items-center">
                        <i class="fa-solid fa-circle-check me-2 fs-5"></i>
                        <div>
                            <div>Standard Rate: Rs. ${standardRate.toFixed(2)} &bull; Minimum Allowed Limit: <strong>Rs. ${minRate.toFixed(2)}</strong></div>
                        </div>
                    </div>
                `;
            } else {
                // Super Admin view (Shows profit, margin, and min bargain limit)
                if (profit >= 0) {
                    guardHtml = `
                        <div class="text-success fw-semibold d-flex align-items-center">
                            <i class="fa-solid fa-circle-check me-2 fs-5"></i>
                            <div>
                                <div>Cost: Rs. ${unitCost.toFixed(2)} | Profit: <strong>+Rs. ${profit.toFixed(2)}</strong> (${margin}%) | Min Bargain: <strong>Rs. ${minRate.toFixed(2)}</strong></div>
                            </div>
                        </div>
                    `;
                } else {
                    guardHtml = `
                        <div class="text-danger fw-bold d-flex align-items-center">
                            <i class="fa-solid fa-triangle-exclamation me-2 fs-5"></i>
                            <div>WARNING: Selling BELOW cost! Loss: -Rs. ${Math.abs(profit).toFixed(2)}/unit.</div>
                        </div>
                    `;
                }
            }
        }

        // Add Piece Specification & Conversion Suggestions in the guard
        guardHtml += `
            <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
                <span class="badge bg-dark text-white px-2 py-1" title="Standard Piece Specs">
                    <i class="fa-solid fa-ruler-combined text-warning me-1"></i> 1 Piece = ${lengthFeet} ft (${weightPiece.toFixed(2)} KG)
                </span>
                <span class="badge bg-primary text-white px-2 py-1" title="Converted Quantity">
                    <i class="fa-solid fa-shapes me-1"></i> Equal to: ~${equivPieces.toFixed(2)} Pieces (${equivWeight.toFixed(2)} KG)
                </span>
            </div>
        `;

        profitGuard.innerHTML = guardHtml;
    }

    productSelect.addEventListener('change', updateSelectedProductDetails);
    unitSelect.addEventListener('change', updateSelectedProductDetails);
    qtyInput.addEventListener('input', checkProfitGuardAndConversion);
    qtyInput.addEventListener('keyup', checkProfitGuardAndConversion);
    qtyInput.addEventListener('change', checkProfitGuardAndConversion);
    rateInput.addEventListener('input', checkProfitGuardAndConversion);
    rateInput.addEventListener('keyup', checkProfitGuardAndConversion);
    rateInput.addEventListener('change', checkProfitGuardAndConversion);

    // Add Item to Cart
    btnAddToCart.addEventListener('click', function () {
        const option = productSelect.options[productSelect.selectedIndex];
        if (!option || !option.value) {
            alert('Please select a product first.');
            return;
        }

        const qty = parseFloat(qtyInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        const unit = unitSelect.value;

        if (qty <= 0) {
            alert('Please enter a valid quantity.');
            qtyInput.focus();
            return;
        }

        if (rate <= 0) {
            alert('Please enter a valid unit rate.');
            rateInput.focus();
            return;
        }

        const weightPiece = parseFloat(option.dataset.weightPiece) || 0;
        const lengthFeet = parseFloat(option.dataset.lengthFeet) || 20;

        let sellPiece = parseFloat(option.dataset.sellPiece) || 0;
        let sellKg    = parseFloat(option.dataset.sellKg) || 0;
        let sellFoot  = parseFloat(option.dataset.sellFoot) || 0;

        if (sellKg <= 0 && sellPiece > 0 && weightPiece > 0) sellKg = sellPiece / weightPiece;
        if (sellPiece <= 0 && sellKg > 0 && weightPiece > 0) sellPiece = sellKg * weightPiece;
        if (sellFoot <= 0 && lengthFeet > 0 && sellPiece > 0) sellFoot = sellPiece / lengthFeet;

        let minPiece = parseFloat(option.dataset.minPiece) || (sellPiece * 0.95);
        let minKg    = parseFloat(option.dataset.minKg) || (weightPiece > 0 ? minPiece / weightPiece : sellKg * 0.95);
        let minFoot  = parseFloat(option.dataset.minFoot) || (lengthFeet > 0 ? minPiece / lengthFeet : sellFoot * 0.95);

        // Strict Check: Block adding if rate is lower than minimum allowed bargain rate
        let minRateCheck = 0;
        if (unit === 'piece') minRateCheck = minPiece;
        else if (unit === 'kg') minRateCheck = minKg;
        else if (unit === 'feet') minRateCheck = minFoot;

        if (minRateCheck > 0 && rate < minRateCheck) {
            alert('RATE BLOCKED: Kam se kam allowed rate Rs. ' + minRateCheck.toFixed(2) + ' hai! Aap is se kam rate pe nahi bech sakte.');
            rateInput.focus();
            return;
        }

        const pid = option.value;
        const pname = option.dataset.name;
        const weightFoot = parseFloat(option.dataset.weightFoot) || 0;

        let deductedKg = 0;
        let equivPieces = 0;

        if (unit === 'piece') {
            deductedKg = qty * weightPiece;
            equivPieces = qty;
        } else if (unit === 'kg') {
            deductedKg = qty;
            equivPieces = weightPiece > 0 ? (qty / weightPiece) : 0;
        } else if (unit === 'feet') {
            deductedKg = qty * weightFoot;
            equivPieces = lengthFeet > 0 ? (qty / lengthFeet) : 0;
        }

        const lineTotal = qty * rate;

        cart.push({
            product_id: pid,
            product_name: pname,
            sale_unit: unit,
            quantity: qty,
            unit_price: rate,
            line_total: lineTotal,
            deducted_kg: deductedKg,
            equivalent_pieces: equivPieces
        });

        renderCart();

        // Reset inputs
        productSelect.value = '';
        rateInput.value = '';
        qtyInput.value = '1';
        updateSelectedProductDetails();
    });

    // Render Cart Table
    function renderCart() {
        if (cart.length === 0) {
            cartTbody.innerHTML = `
                <tr id="empty_cart_row">
                    <td colspan="8" class="text-center py-4 text-muted">
                        <i class="fa-solid fa-basket-shopping fs-3 mb-2 d-block"></i>
                        No items added to this bill yet.
                    </td>
                </tr>
            `;
            cartBadge.textContent = '0 Items';
            btnSaveInvoice.disabled = true;
            displaySubtotal.textContent = 'Rs. 0.00';
            displayGrandTotal.textContent = 'Rs. 0.00';
            displayDue.textContent = 'Rs. 0.00';
            if (summaryTotalPieces) summaryTotalPieces.textContent = '0.00 Pieces';
            if (summaryTotalWeight) summaryTotalWeight.textContent = '0.00 KG';
            if (summaryPiecesBadge) summaryPiecesBadge.textContent = '0 Pieces';
            itemsJsonInput.value = '[]';
            return;
        }

        btnSaveInvoice.disabled = false;
        cartBadge.textContent = `${cart.length} Items`;

        let html = '';
        let subtotal = 0;
        let totalPieces = 0;
        let totalWeight = 0;

        cart.forEach((item, index) => {
            subtotal += item.line_total;
            totalPieces += item.equivalent_pieces;
            totalWeight += item.deducted_kg;

            let unitBadgeClass = 'bg-primary-subtle text-primary';
            if (item.sale_unit === 'kg') unitBadgeClass = 'bg-success-subtle text-success';
            if (item.sale_unit === 'feet') unitBadgeClass = 'bg-secondary-subtle text-secondary';

            html += `
                <tr>
                    <td>${index + 1}</td>
                    <td class="fw-bold text-dark">${item.product_name}</td>
                    <td>
                        <span class="fw-bold">${item.quantity}</span> 
                        <span class="badge ${unitBadgeClass} ms-1">${item.sale_unit.toUpperCase()}</span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary text-white fs-6 px-2 py-1">
                            ~${item.equivalent_pieces.toFixed(2)} Pieces
                        </span>
                    </td>
                    <td>Rs. ${item.unit_price.toFixed(2)}</td>
                    <td class="fw-bold text-dark">Rs. ${item.line_total.toFixed(2)}</td>
                    <td class="small text-muted">${item.deducted_kg.toFixed(2)} KG</td>
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

        // Update piece and weight summaries
        if (summaryTotalPieces) summaryTotalPieces.textContent = `~${totalPieces.toFixed(2)} Pieces`;
        if (summaryTotalWeight) summaryTotalWeight.textContent = `${totalWeight.toFixed(2)} KG`;
        if (summaryPiecesBadge) summaryPiecesBadge.textContent = `~${totalPieces.toFixed(1)} Pieces`;

        // Calculate Totals
        const discount = parseFloat(discountInput.value) || 0;
        const grandTotal = Math.max(0, subtotal - discount);
        
        // Auto fill paid input if user hasn't edited it
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

        // Attach remove item handlers
        document.querySelectorAll('.btn-remove-item').forEach(btn => {
            btn.addEventListener('click', function () {
                const idx = parseInt(this.dataset.index);
                cart.splice(idx, 1);
                renderCart();
            });
        });
    }

    // Totals recalc on discount or paid change
    discountInput.addEventListener('input', function () {
        paidInput.dataset.autoFilled = "0";
        renderCart();
    });

    paidInput.addEventListener('input', function () {
        paidInput.dataset.autoFilled = "0";
        renderCart();
    });

    // Run immediately on page load to sync suggestions and rates with selected product/unit
    updateSelectedProductDetails();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
