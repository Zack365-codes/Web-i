<?php
// return_add.php - Process Sales Return / Maal Wapsi
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

// Check if an AJAX request for invoice items is requested
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice') {
    header('Content-Type: application/json');
    $inv_id = intval($_GET['invoice_id'] ?? 0);
    
    if ($inv_id <= 0 || !isset($pdo)) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice ID or database disconnected.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
        $stmt->execute([$inv_id]);
        $sale = $stmt->fetch();

        if (!$sale) {
            echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
            exit;
        }

        // Fetch items on this invoice with already returned quantities
        $items_stmt = $pdo->prepare("
            SELECT 
                si.*,
                COALESCE(p.weight_per_piece, 0) as weight_per_piece,
                COALESCE(p.weight_per_foot, 0) as weight_per_foot,
                COALESCE(p.standard_length_feet, 20) as standard_length_feet,
                COALESCE((
                    SELECT SUM(sri.quantity_returned) 
                    FROM sales_return_items sri 
                    WHERE sri.sale_item_id = si.id
                ), 0) as already_returned
            FROM sale_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = ?
        ");
        $items_stmt->execute([$inv_id]);
        $items = $items_stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'sale' => $sale,
            'items' => $items
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$page_title = "New Sales Return (Maal Wapsi)";
require_once __DIR__ . '/includes/header.php';

// Fetch recent invoices for selection dropdown
$recent_invoices = [];
if (isset($pdo)) {
    try {
        $inv_stmt = $pdo->query("SELECT id, invoice_no, customer_name, grand_total, due_amount, DATE_FORMAT(created_at, '%d-%b-%Y %h:%i %p') as formatted_date FROM sales ORDER BY id DESC LIMIT 50");
        $recent_invoices = $inv_stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to load invoices: " . $e->getMessage();
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_return'])) {
    if (!isset($pdo)) {
        set_flash('danger', 'Database is not connected.');
    } else {
        $sale_id        = intval($_POST['sale_id'] ?? 0);
        $refund_method  = trim($_POST['refund_method'] ?? 'Cash');
        $reason         = trim($_POST['reason'] ?? '');
        $return_items   = $_POST['return_qty'] ?? []; // array [sale_item_id => qty]

        if ($sale_id <= 0) {
            $error = "Please select an invoice for the return.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Fetch the sale
                $sale_stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
                $sale_stmt->execute([$sale_id]);
                $sale = $sale_stmt->fetch();

                if (!$sale) {
                    throw new Exception("Invoice not found.");
                }

                // 2. Generate unique Return Number: RET-YYYYMMDD-XXX
                $date_prefix = date('Ymd');
                $count_stmt = $pdo->query("SELECT COUNT(*) FROM sales_returns WHERE DATE(created_at) = CURDATE()");
                $daily_count = $count_stmt->fetchColumn() + 1;
                $return_no = "RET-" . $date_prefix . "-" . str_pad($daily_count, 3, '0', STR_PAD_LEFT);

                $total_refund = 0;
                $total_returned_weight = 0;
                $processed_return_items = [];
                $profit_to_deduct = 0;

                foreach ($return_items as $si_id => $ret_qty) {
                    $ret_qty = floatval($ret_qty);
                    if ($ret_qty <= 0) continue;

                    // Fetch sale item and product details
                    $si_stmt = $pdo->prepare("
                        SELECT 
                            si.*,
                            p.weight_per_piece,
                            p.weight_per_foot,
                            p.standard_length_feet,
                            p.cost_price_per_kg,
                            COALESCE((
                                SELECT SUM(sri.quantity_returned) 
                                FROM sales_return_items sri 
                                WHERE sri.sale_item_id = si.id
                            ), 0) as already_returned
                        FROM sale_items si
                        LEFT JOIN products p ON si.product_id = p.id
                        WHERE si.id = ? AND si.sale_id = ?
                        FOR UPDATE
                    ");
                    $si_stmt->execute([$si_id, $sale_id]);
                    $item = $si_stmt->fetch();

                    if (!$item) {
                        throw new Exception("Invalid item selected for return.");
                    }

                    $max_returnable = (float)$item['quantity'] - (float)$item['already_returned'];
                    if ($ret_qty > ($max_returnable + 0.001)) {
                        throw new Exception("Returned quantity ({$ret_qty}) cannot exceed remaining sold quantity ({$max_returnable}) for {$item['product_name']}.");
                    }

                    $weight_foot  = (float)$item['weight_per_foot'];
                    $length_feet  = (float)($item['standard_length_feet'] ?: 20);
                    $weight_piece = (float)($item['weight_per_piece'] ?: ($weight_foot * $length_feet));
                    $unit = $item['sale_unit'];

                    // Calculate restored weight and pieces
                    $restored_kg = 0;
                    $restored_pieces = 0;

                    if ($unit === 'piece') {
                        $restored_kg = $ret_qty * $weight_piece;
                        $restored_pieces = $ret_qty;
                    } elseif ($unit === 'kg') {
                        $restored_kg = $ret_qty;
                        $restored_pieces = $weight_piece > 0 ? ($ret_qty / $weight_piece) : 0;
                    } elseif ($unit === 'feet') {
                        $restored_kg = $ret_qty * $weight_foot;
                        $restored_pieces = $length_feet > 0 ? ($ret_qty / $length_feet) : 0;
                    }

                    $refund_subtotal = $ret_qty * (float)$item['unit_price'];
                    $unit_profit = (float)$item['unit_price'] - (float)$item['unit_cost'];
                    $item_profit_reversed = $unit_profit * $ret_qty;

                    $total_refund += $refund_subtotal;
                    $total_returned_weight += $restored_kg;
                    $profit_to_deduct += $item_profit_reversed;

                    // Restore Stock in Products Table
                    $restore_stmt = $pdo->prepare("UPDATE products SET total_stock_kg = total_stock_kg + ? WHERE id = ?");
                    $restore_stmt->execute([$restored_kg, $item['product_id']]);

                    $processed_return_items[] = [
                        'sale_item_id'       => $si_id,
                        'product_id'         => $item['product_id'],
                        'product_name'       => $item['product_name'],
                        'sale_unit'          => $unit,
                        'quantity_returned'  => $ret_qty,
                        'unit_price'         => (float)$item['unit_price'],
                        'refund_subtotal'    => $refund_subtotal,
                        'restored_stock_kg'  => $restored_kg,
                        'restored_pieces'    => $restored_pieces
                    ];
                }

                if (empty($processed_return_items)) {
                    throw new Exception("Please enter return quantity for at least 1 item.");
                }

                $created_by = $_SESSION['user']['fullname'] ?? 'User';

                // 3. Insert into sales_returns
                $ins_return = $pdo->prepare("
                    INSERT INTO sales_returns (
                        return_no, sale_id, invoice_no, customer_name, customer_phone,
                        total_refund_amount, total_returned_weight_kg, refund_method, reason, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins_return->execute([
                    $return_no, $sale['id'], $sale['invoice_no'], $sale['customer_name'], $sale['customer_phone'],
                    $total_refund, $total_returned_weight, $refund_method, $reason, $created_by
                ]);
                $return_id = $pdo->lastInsertId();

                // 4. Insert into sales_return_items
                $ins_r_item = $pdo->prepare("
                    INSERT INTO sales_return_items (
                        return_id, sale_item_id, product_id, product_name, sale_unit,
                        quantity_returned, unit_price, refund_subtotal, restored_stock_kg, restored_pieces
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($processed_return_items as $pri) {
                    $ins_r_item->execute([
                        $return_id, $pri['sale_item_id'], $pri['product_id'], $pri['product_name'], $pri['sale_unit'],
                        $pri['quantity_returned'], $pri['unit_price'], $pri['refund_subtotal'],
                        $pri['restored_stock_kg'], $pri['restored_pieces']
                    ]);
                }

                // 5. Adjust invoice due balance or khata if chosen
                if ($refund_method === 'Deduct from Khata') {
                    $new_due = max(0, (float)$sale['due_amount'] - $total_refund);
                    $new_status = ($new_due == 0) ? 'paid' : 'partial';
                    $upd_sale = $pdo->prepare("UPDATE sales SET due_amount = ?, payment_status = ? WHERE id = ?");
                    $upd_sale->execute([$new_due, $new_status, $sale['id']]);
                }

                // 6. Reverse realized profit from sale record
                $upd_profit = $pdo->prepare("UPDATE sales SET total_profit = GREATEST(0, total_profit - ?) WHERE id = ?");
                $upd_profit->execute([$profit_to_deduct, $sale['id']]);

                $pdo->commit();

                // Redirect to Return Credit Note Print
                echo "<script>window.location.href = 'return_print.php?id={$return_id}&autoprint=1';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to process return: " . $e->getMessage();
            }
        }
    }
}

$preset_sale_id = intval($_GET['sale_id'] ?? 0);
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold mb-1"><i class="fa-solid fa-rotate-left text-danger me-2"></i>New Sales Return / Maal Wapsi</h3>
        <p class="text-muted mb-0">Select an invoice to return iron items, restore warehouse stock, and issue refund or khata credit.</p>
    </div>
    <div class="col-md-5 text-md-end">
        <a href="returns.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-clock-rotate-left me-1"></i> Returns History
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form action="return_add.php" method="POST" id="salesReturnForm">
    <div class="row g-4">
        
        <!-- Left Side: Invoice Selection & Items on Invoice -->
        <div class="col-lg-8">
            
            <!-- Step 1: Choose Invoice -->
            <div class="card card-custom mb-3 border-primary">
                <div class="card-header bg-primary-subtle text-primary fw-bold py-2 d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-file-invoice me-1"></i> 1. Select Customer Invoice</span>
                    <span class="small text-muted">Pick invoice to load items</span>
                </div>
                <div class="card-body p-3">
                    <label class="form-label fw-semibold">Choose Invoice <span class="text-danger">*</span></label>
                    <select name="sale_id" id="return_sale_select" class="form-select form-select-lg" required>
                        <option value="">-- Select Invoice (INV-XXXX) --</option>
                        <?php foreach ($recent_invoices as $rinv): ?>
                            <option value="<?= $rinv['id'] ?>" <?= $preset_sale_id === (int)$rinv['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rinv['invoice_no']) ?> &bull; <?= htmlspecialchars($rinv['customer_name'] ?: 'Walk-in') ?> &bull; Total: Rs. <?= number_format($rinv['grand_total'], 0) ?> (<?= $rinv['formatted_date'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Invoice Details Strip (Loaded via JS) -->
                    <div id="invoice_info_strip" class="mt-3 p-3 bg-light rounded border d-none">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <span class="text-muted small d-block">Customer Name:</span>
                                <strong id="strip_customer_name" class="text-dark fs-6">-</strong>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted small d-block">Customer Phone:</span>
                                <strong id="strip_customer_phone" class="text-dark">-</strong>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted small d-block">Current Due / Udhar:</span>
                                <strong id="strip_due_amount" class="text-danger fs-6">-</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Items Table -->
            <div class="card card-custom mb-3">
                <div class="card-header card-header-clean d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fa-solid fa-boxes-stacked text-primary me-2"></i>2. Items on Invoice (Enter Returned Quantity)</span>
                    <span id="items_count_badge" class="badge bg-secondary">0 Items</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Sold Qty</th>
                                <th>Already Ret.</th>
                                <th style="width: 140px;">Return Qty</th>
                                <th>Rate (Rs.)</th>
                                <th>Refund (Rs.)</th>
                                <th>Restored Stock</th>
                            </tr>
                        </thead>
                        <tbody id="return_items_tbody">
                            <tr id="empty_invoice_row">
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-file-invoice fs-3 mb-2 d-block"></i>
                                    Please select an invoice above to view its items.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Right Side: Return Summary & Action -->
        <div class="col-lg-4">
            <div class="position-sticky" style="top: 80px;">
                <div class="card card-custom shadow-sm border-danger">
                    <div class="card-header bg-danger text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-receipt me-2"></i> Return Summary</span>
                        <span id="summary_return_badge" class="badge bg-white text-danger fw-bold">0 Items</span>
                    </div>
                    <div class="card-body p-4">
                        
                        <!-- Restored Stock Metrics Banner -->
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small fw-bold"><i class="fa-solid fa-weight-hanging me-1 text-danger"></i> Total Weight Restored:</span>
                                <span class="fw-bold fs-6 text-danger" id="summary_total_restored_kg">0.00 KG</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small fw-bold"><i class="fa-solid fa-shapes me-1 text-secondary"></i> Total Pieces Restored:</span>
                                <span class="fw-bold text-dark" id="summary_total_restored_pcs">0.00 Pieces</span>
                            </div>
                        </div>

                        <!-- Total Refund Amount -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold fs-5 text-dark">Total Refund:</span>
                            <span class="fw-bold fs-3 text-danger" id="summary_total_refund">Rs. 0.00</span>
                        </div>

                        <hr class="my-2">

                        <!-- Refund Method -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                Refund / Settlement Method <span class="text-danger">*</span>:
                            </label>
                            <select name="refund_method" id="refund_method_select" class="form-select fw-bold">
                                <option value="Cash">Cash (Naqd Wapsi)</option>
                                <option value="Deduct from Khata">Deduct from Customer Udhar (Khata)</option>
                                <option value="Credit Balance">Credit Balance (For Next Bill)</option>
                            </select>
                            <div class="form-text small" id="refund_method_hint">
                                Cash will be refunded directly to customer from drawer.
                            </div>
                        </div>

                        <!-- Return Reason / Notes -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary mb-1">Return Reason / Notes:</label>
                            <input type="text" name="reason" class="form-control" placeholder="e.g. Bacha hua saria wapas aya">
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" name="save_return" id="btn_save_return" class="btn btn-danger btn-lg w-100 fw-bold py-3 shadow-sm" disabled>
                            <i class="fa-solid fa-check me-2"></i> Process Return & Restore Stock
                        </button>

                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const saleSelect        = document.getElementById('return_sale_select');
    const invoiceStrip      = document.getElementById('invoice_info_strip');
    const stripCustName     = document.getElementById('strip_customer_name');
    const stripCustPhone    = document.getElementById('strip_customer_phone');
    const stripDueAmount    = document.getElementById('strip_due_amount');
    
    const itemsTbody        = document.getElementById('return_items_tbody');
    const itemsCountBadge   = document.getElementById('items_count_badge');
    const summaryBadge      = document.getElementById('summary_return_badge');
    const summaryRefund     = document.getElementById('summary_total_refund');
    const summaryWeight     = document.getElementById('summary_total_restored_kg');
    const summaryPcs        = document.getElementById('summary_total_restored_pcs');
    
    const refundMethodSelect= document.getElementById('refund_method_select');
    const refundMethodHint  = document.getElementById('refund_method_hint');
    const btnSaveReturn     = document.getElementById('btn_save_return');

    let invoiceItems = [];

    refundMethodSelect.addEventListener('change', function () {
        if (this.value === 'Deduct from Khata') {
            refundMethodHint.innerHTML = '<span class="text-primary fw-bold">Customer ke baqaya udhar me se ye raqam minus ho jayegi.</span>';
        } else if (this.value === 'Cash') {
            refundMethodHint.innerHTML = 'Cash will be refunded directly to customer from drawer.';
        } else {
            refundMethodHint.innerHTML = 'Customer can use this credit balance on their next purchase.';
        }
    });

    function loadInvoiceDetails(invoiceId) {
        if (!invoiceId) {
            invoiceStrip.classList.add('d-none');
            itemsTbody.innerHTML = `
                <tr id="empty_invoice_row">
                    <td colspan="8" class="text-center py-4 text-muted">
                        <i class="fa-solid fa-file-invoice fs-3 mb-2 d-block"></i>
                        Please select an invoice above to view its items.
                    </td>
                </tr>
            `;
            itemsCountBadge.textContent = '0 Items';
            recalcReturnSummary();
            return;
        }

        itemsTbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-primary">
                    <i class="fa-solid fa-spinner fa-spin fs-4 mb-2 d-block"></i>
                    Loading invoice items...
                </td>
            </tr>
        `;

        fetch(`return_add.php?action=get_invoice&invoice_id=${invoiceId}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Failed to load invoice items.');
                    return;
                }

                const sale = data.sale;
                invoiceItems = data.items;

                // Update Info Strip
                stripCustName.textContent = sale.customer_name || 'Walk-in Customer';
                stripCustPhone.textContent = sale.customer_phone || 'N/A';
                stripDueAmount.textContent = 'Rs. ' + parseFloat(sale.due_amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
                invoiceStrip.classList.remove('d-none');

                // Render Items
                if (invoiceItems.length === 0) {
                    itemsTbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                No items found on this invoice.
                            </td>
                        </tr>
                    `;
                    itemsCountBadge.textContent = '0 Items';
                    return;
                }

                let html = '';
                invoiceItems.forEach((item, idx) => {
                    const soldQty = parseFloat(item.quantity);
                    const alreadyRet = parseFloat(item.already_returned);
                    const maxRet = Math.max(0, soldQty - alreadyRet);
                    const price = parseFloat(item.unit_price);
                    const isFullyReturned = maxRet <= 0;

                    html += `
                        <tr class="${isFullyReturned ? 'table-light text-muted' : ''}">
                            <td>${idx + 1}</td>
                            <td>
                                <strong class="text-dark">${item.product_name}</strong>
                                <span class="badge bg-secondary-subtle text-secondary small ms-1">${item.sale_unit.toUpperCase()}</span>
                            </td>
                            <td class="fw-bold">${soldQty.toFixed(2)}</td>
                            <td class="text-danger">${alreadyRet.toFixed(2)}</td>
                            <td>
                                ${isFullyReturned ? 
                                    '<span class="badge bg-secondary">Fully Returned</span>' : 
                                    `<input type="number" step="0.01" min="0" max="${maxRet}" 
                                            name="return_qty[${item.id}]" 
                                            class="form-control form-control-sm fw-bold return-qty-input" 
                                            data-index="${idx}" 
                                            placeholder="0" 
                                            value="0">
                                     <small class="text-muted">Max: ${maxRet.toFixed(2)}</small>`
                                }
                            </td>
                            <td>Rs. ${price.toFixed(2)}</td>
                            <td class="fw-bold text-danger line-refund" id="refund_line_${idx}">Rs. 0.00</td>
                            <td class="small text-muted line-restored-stock" id="restored_stock_${idx}">0.00 KG</td>
                        </tr>
                    `;
                });

                itemsTbody.innerHTML = html;
                itemsCountBadge.textContent = `${invoiceItems.length} Items`;

                // Attach event listeners to input fields
                document.querySelectorAll('.return-qty-input').forEach(input => {
                    input.addEventListener('input', function () {
                        const idx = parseInt(this.dataset.index);
                        const item = invoiceItems[idx];
                        let val = parseFloat(this.value) || 0;
                        const max = parseFloat(this.max);

                        if (val > max) {
                            val = max;
                            this.value = max;
                        }

                        const price = parseFloat(item.unit_price);
                        const weightPiece = parseFloat(item.weight_per_piece) || 0;
                        const weightFoot  = parseFloat(item.weight_per_foot) || 0;
                        const lengthFeet  = parseFloat(item.standard_length_feet) || 20;

                        let restoredKg = 0;
                        let restoredPcs = 0;

                        if (item.sale_unit === 'piece') {
                            restoredKg = val * weightPiece;
                            restoredPcs = val;
                        } else if (item.sale_unit === 'kg') {
                            restoredKg = val;
                            restoredPcs = weightPiece > 0 ? (val / weightPiece) : 0;
                        } else if (item.sale_unit === 'feet') {
                            restoredKg = val * weightFoot;
                            restoredPcs = lengthFeet > 0 ? (val / lengthFeet) : 0;
                        }

                        const lineRefund = val * price;

                        document.getElementById(`refund_line_${idx}`).textContent = 'Rs. ' + lineRefund.toLocaleString('en-US', { minimumFractionDigits: 2 });
                        document.getElementById(`restored_stock_${idx}`).textContent = `${restoredKg.toFixed(2)} KG (~${restoredPcs.toFixed(1)} Pcs)`;

                        recalcReturnSummary();
                    });
                });

                recalcReturnSummary();
            })
            .catch(err => {
                alert('Error fetching invoice details.');
                console.error(err);
            });
    }

    function recalcReturnSummary() {
        let totalRefund = 0;
        let totalRestoredKg = 0;
        let totalRestoredPcs = 0;
        let activeReturnCount = 0;

        document.querySelectorAll('.return-qty-input').forEach(input => {
            const val = parseFloat(input.value) || 0;
            if (val > 0) {
                activeReturnCount++;
                const idx = parseInt(input.dataset.index);
                const item = invoiceItems[idx];
                const price = parseFloat(item.unit_price);
                const weightPiece = parseFloat(item.weight_per_piece) || 0;
                const weightFoot  = parseFloat(item.weight_per_foot) || 0;
                const lengthFeet  = parseFloat(item.standard_length_feet) || 20;

                totalRefund += val * price;

                if (item.sale_unit === 'piece') {
                    totalRestoredKg += val * weightPiece;
                    totalRestoredPcs += val;
                } else if (item.sale_unit === 'kg') {
                    totalRestoredKg += val;
                    totalRestoredPcs += weightPiece > 0 ? (val / weightPiece) : 0;
                } else if (item.sale_unit === 'feet') {
                    totalRestoredKg += val * weightFoot;
                    totalRestoredPcs += lengthFeet > 0 ? (val / lengthFeet) : 0;
                }
            }
        });

        summaryRefund.textContent = 'Rs. ' + totalRefund.toLocaleString('en-US', { minimumFractionDigits: 2 });
        summaryWeight.textContent = `${totalRestoredKg.toFixed(2)} KG`;
        summaryPcs.textContent = `~${totalRestoredPcs.toFixed(1)} Pieces`;
        summaryBadge.textContent = `${activeReturnCount} Items`;

        btnSaveReturn.disabled = (totalRefund <= 0);
    }

    saleSelect.addEventListener('change', function () {
        loadInvoiceDetails(this.value);
    });

    // Auto-load if preset_sale_id is present
    if (saleSelect.value) {
        loadInvoiceDetails(saleSelect.value);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

