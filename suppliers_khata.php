<?php
// suppliers_khata.php - Supplier & Mill Payables / Khata Directory
$page_title = "Supplier Credit Ledger (Khata / Payables)";
require_once __DIR__ . '/includes/header.php';

// Handle Paying Supplier (Factory Ko Adaigi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_supplier'])) {
    if (isset($pdo)) {
        $s_name   = trim($_POST['supplier_name'] ?? '');
        $s_phone  = trim($_POST['supplier_phone'] ?? '');
        $amount   = floatval($_POST['amount_paid'] ?? 0);
        $method   = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $notes    = trim($_POST['notes'] ?? '');

        if (empty($s_name) || $amount <= 0) {
            set_flash('danger', 'Please enter supplier name and a valid payment amount.');
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Generate payment voucher number: VOU-YYYYMMDD-XXX
                $date_prefix = date('Ymd');
                $count_stmt = $pdo->query("SELECT COUNT(*) FROM supplier_payments WHERE DATE(created_at) = CURDATE()");
                $daily_count = $count_stmt->fetchColumn() + 1;
                $voucher_no = "VOU-" . $date_prefix . "-" . str_pad($daily_count, 3, '0', STR_PAD_LEFT);

                // 2. Insert into supplier_payments
                $insert_pay = $pdo->prepare("
                    INSERT INTO supplier_payments (voucher_no, supplier_name, supplier_phone, amount_paid, payment_method, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insert_pay->execute([$voucher_no, $s_name, $s_phone, $amount, $method, $notes]);
                $payment_id = $pdo->lastInsertId();

                // 3. Automatically apply payment to oldest unpaid purchases for this supplier (FIFO)
                $rem_payment = $amount;
                $pur_stmt = $pdo->prepare("
                    SELECT id, grand_total, paid_amount, due_amount 
                    FROM purchases 
                    WHERE supplier_name = ? AND due_amount > 0 
                    ORDER BY id ASC 
                    FOR UPDATE
                ");
                $pur_stmt->execute([$s_name]);
                $unpaid_purchases = $pur_stmt->fetchAll();

                foreach ($unpaid_purchases as $pur) {
                    if ($rem_payment <= 0) break;

                    $pur_id  = $pur['id'];
                    $pur_due = (float)$pur['due_amount'];

                    $applied = min($rem_payment, $pur_due);
                    $new_due = $pur_due - $applied;
                    $new_paid = (float)$pur['paid_amount'] + $applied;
                    $status = ($new_due <= 0.01) ? 'paid' : 'partial';

                    $update_pur = $pdo->prepare("
                        UPDATE purchases 
                        SET paid_amount = ?, due_amount = ?, payment_status = ? 
                        WHERE id = ?
                    ");
                    $update_pur->execute([$new_paid, max(0, $new_due), $status, $pur_id]);

                    $rem_payment -= $applied;
                }

                $pdo->commit();
                set_flash('success', "Payment voucher '{$voucher_no}' recorded! Paid Rs. " . number_format($amount, 2) . " to '{$s_name}'.");
                echo "<script>window.location.href = 'supplier_payment_receipt.php?id={$payment_id}&autoprint=1';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('danger', 'Failed to record supplier payment: ' . $e->getMessage());
            }
        }
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter_due = isset($_GET['filter_due']) ? $_GET['filter_due'] : '0'; // default show all

$query = "
    SELECT 
        supplier_name, 
        MAX(supplier_phone) as supplier_phone,
        COUNT(id) as total_shipments,
        SUM(grand_total) as total_purchased,
        SUM(paid_amount) as total_paid,
        SUM(due_amount) as total_due,
        SUM(total_weight_kg) as total_weight_kg,
        MAX(created_at) as last_inward_date
    FROM purchases 
    WHERE supplier_name IS NOT NULL AND supplier_name != ''
";
$params = [];

if (!empty($search)) {
    $query .= " AND (supplier_name LIKE ? OR supplier_phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$query .= " GROUP BY supplier_name";

if ($filter_due === '1') {
    $query .= " HAVING total_due > 0";
}

$query .= " ORDER BY total_due DESC, last_inward_date DESC";

$suppliers = [];
$stats = [
    'total_suppliers' => 0,
    'total_inward_val' => 0,
    'total_paid' => 0,
    'total_payable' => 0
];

if (isset($pdo)) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $suppliers = $stmt->fetchAll();

        // Overall stats
        $all_stmt = $pdo->query("
            SELECT 
                COUNT(DISTINCT supplier_name) as total_sup,
                COALESCE(SUM(grand_total), 0) as total_val,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due
            FROM purchases
            WHERE supplier_name IS NOT NULL AND supplier_name != ''
        ");
        $all_data = $all_stmt->fetch();
        $stats['total_suppliers'] = (int)$all_data['total_sup'];
        $stats['total_inward_val'] = (float)$all_data['total_val'];
        $stats['total_paid'] = (float)$all_data['total_paid'];
        $stats['total_payable'] = (float)$all_data['total_due'];

    } catch (PDOException $e) {
        $error = "Failed to load supplier khata: " . $e->getMessage();
    }
}
?>

<!-- Header -->
<div class="row mb-4 align-items-center">
    <div class="col-md-6 mb-2 mb-md-0">
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-building-columns text-warning me-2"></i>Supplier Khata & Mill Payables
        </h3>
        <p class="text-muted mb-0">Track payables owed to factories/mills and record bank/cash payments.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <button type="button" class="btn btn-warning text-dark btn-lg shadow-sm fw-bold me-2" data-bs-toggle="modal" data-bs-target="#paySupplierModal">
            <i class="fa-solid fa-money-bill-transfer me-1"></i> Pay Supplier (Adaigi)
        </button>
        <a href="purchase_add.php" class="btn btn-success btn-lg shadow-sm fw-bold">
            <i class="fa-solid fa-plus-circle me-1"></i> New Stock Inward
        </a>
    </div>
</div>

<!-- Financial Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Active Suppliers / Mills</span>
                <div class="stat-icon bg-secondary-subtle text-dark">
                    <i class="fa-solid fa-industry"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= number_format($stats['total_suppliers']) ?></div>
            <div class="small text-muted mt-2">
                Registered steel mills & vendors
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Stock Inward Value</span>
                <div class="stat-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-truck-ramp-box"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= format_currency($stats['total_inward_val'], 0) ?></div>
            <div class="small text-muted mt-2">
                Total purchases recorded
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-success">Total Amount Paid</span>
                <div class="stat-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-check-double"></i>
                </div>
            </div>
            <div class="stat-value text-success"><?= format_currency($stats['total_paid'], 0) ?></div>
            <div class="small text-muted mt-2">
                Cleared to factories
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm h-100 <?= $stats['total_payable'] > 0 ? 'border-danger' : '' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-danger fw-bold">Total Wajib-ul-Ada (Payable)</span>
                <div class="stat-icon bg-danger-subtle text-danger">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
            </div>
            <div class="stat-value text-danger"><?= format_currency($stats['total_payable'], 0) ?></div>
            <div class="small text-muted mt-2">
                Factory & Mill pending udhar
            </div>
        </div>
    </div>
</div>

<!-- Search & Filter Controls -->
<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-body p-3">
        <form method="GET" action="suppliers_khata.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fa-solid fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search Supplier / Mill Name or Phone..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-md-4">
                <div class="btn-group w-100" role="group">
                    <a href="suppliers_khata.php?filter_due=0<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn <?= $filter_due === '0' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        All Suppliers (<?= $stats['total_suppliers'] ?>)
                    </a>
                    <a href="suppliers_khata.php?filter_due=1<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn <?= $filter_due === '1' ? 'btn-danger' : 'btn-outline-danger' ?>">
                        With Balance Due
                    </a>
                </div>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="fa-solid fa-filter me-1"></i> Search
                </button>
                <?php if (!empty($search) || $filter_due !== '0'): ?>
                    <a href="suppliers_khata.php" class="btn btn-outline-secondary" title="Reset">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Supplier Khata Table -->
<div class="card card-custom shadow-sm border-0">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="fa-solid fa-address-book text-warning me-2"></i>Supplier Accounts Directory
            <span class="badge bg-secondary ms-2"><?= count($suppliers) ?> found</span>
        </h5>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Supplier / Mill Name</th>
                    <th>Contact Phone</th>
                    <th class="text-center">Shipments</th>
                    <th>Total Inward Weight</th>
                    <th class="text-end">Total Billed</th>
                    <th class="text-end">Total Paid</th>
                    <th class="text-end">Balance Due</th>
                    <th>Last Inward</th>
                    <th class="text-center pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5">
                            <div class="text-muted">
                                <i class="fa-solid fa-building-columns fa-3x mb-3 text-secondary opacity-50"></i>
                                <h5>No Supplier Accounts Found</h5>
                                <p class="mb-3">Record purchases from factories to automatically maintain their khata.</p>
                                <a href="purchase_add.php" class="btn btn-success">
                                    <i class="fa-solid fa-plus me-1"></i> Record Stock Inward
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $sup): ?>
                        <tr>
                            <td class="ps-3 fw-bold text-dark fs-6">
                                <a href="supplier_ledger.php?name=<?= urlencode($sup['supplier_name']) ?>" class="text-decoration-none text-dark">
                                    <i class="fa-solid fa-industry text-warning me-2"></i><?= htmlspecialchars($sup['supplier_name']) ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($sup['supplier_phone'])): ?>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fa-solid fa-phone me-1 text-secondary"></i><?= htmlspecialchars($sup['supplier_phone']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= $sup['total_shipments'] ?> shipments</span>
                            </td>
                            <td>
                                <span class="fw-semibold text-primary"><?= number_format($sup['total_weight_kg'] / 1000, 2) ?> Ton</span>
                                <div class="small text-muted"><?= number_format($sup['total_weight_kg'], 1) ?> KG</div>
                            </td>
                            <td class="text-end fw-semibold text-dark">
                                <?= format_currency($sup['total_purchased'], 2) ?>
                            </td>
                            <td class="text-end text-success fw-semibold">
                                <?= format_currency($sup['total_paid'], 2) ?>
                            </td>
                            <td class="text-end fw-bold fs-6 <?= $sup['total_due'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= format_currency($sup['total_due'], 2) ?>
                                <?php if ($sup['total_due'] <= 0): ?>
                                    <span class="badge bg-success-subtle text-success ms-1 small">CLEAR</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?= date('d M, Y', strtotime($sup['last_inward_date'])) ?></small>
                            </td>
                            <td class="text-center pe-3">
                                <div class="btn-group">
                                    <?php if ($sup['total_due'] > 0): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-warning text-dark fw-bold btn-pay-modal" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#paySupplierModal" 
                                                data-name="<?= htmlspecialchars($sup['supplier_name']) ?>" 
                                                data-phone="<?= htmlspecialchars($sup['supplier_phone'] ?? '') ?>" 
                                                data-due="<?= (float)$sup['total_due'] ?>"
                                                title="Make Payment to Supplier">
                                            <i class="fa-solid fa-money-bill-transfer me-1"></i> Pay
                                        </button>
                                    <?php endif; ?>
                                    <a href="supplier_ledger.php?name=<?= urlencode($sup['supplier_name']) ?>" class="btn btn-sm btn-outline-primary" title="View Supplier Account Statement">
                                        <i class="fa-solid fa-file-lines me-1"></i> Statement
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

<!-- Modal: Pay Supplier (Factory Ko Adaigi) -->
<div class="modal fade" id="paySupplierModal" tabindex="-1" aria-labelledby="paySupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <form method="POST" action="suppliers_khata.php">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold" id="paySupplierModalLabel">
                        <i class="fa-solid fa-building-columns me-2"></i>Pay Supplier / Mill (Factory Ko Adaigi)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Supplier / Mill Name <span class="text-danger">*</span></label>
                        <input type="text" name="supplier_name" id="modal_sup_name" class="form-control form-control-lg" required placeholder="e.g. Mughal Steel, Ittefaq Iron Mills">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Supplier Phone</label>
                        <input type="text" name="supplier_phone" id="modal_sup_phone" class="form-control" placeholder="0300-1234567">
                    </div>

                    <div class="mb-3 p-3 bg-light rounded-3 border" id="modal_due_box" style="display: none;">
                        <div class="small text-muted">Current Outstanding Balance:</div>
                        <div class="fs-4 fw-bold text-danger" id="modal_due_amount">Rs. 0.00</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Amount (Rs.) <span class="text-danger">*</span></label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white fw-bold">Rs.</span>
                            <input type="number" step="0.01" min="1" name="amount_paid" id="modal_amount_paid" class="form-control fw-bold text-success" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="Bank Transfer">Bank Transfer / Online</option>
                            <option value="Cheque">Bank Cheque</option>
                            <option value="Cash">Cash</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes / Transaction Reference</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Paid via HBL Online Transfer, Cheque # 12458"></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="pay_supplier" class="btn btn-warning text-dark fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i> Save Payment & Print Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Populate modal when clicking "Pay" button
    const payButtons = document.querySelectorAll('.btn-pay-modal');
    payButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const name = this.getAttribute('data-name');
            const phone = this.getAttribute('data-phone');
            const due = parseFloat(this.getAttribute('data-due') || 0);

            document.getElementById('modal_sup_name').value = name;
            document.getElementById('modal_sup_phone').value = phone;
            document.getElementById('modal_amount_paid').value = due > 0 ? due : '';

            const dueBox = document.getElementById('modal_due_box');
            if (due > 0) {
                dueBox.style.display = 'block';
                document.getElementById('modal_due_amount').innerText = 'Rs. ' + due.toLocaleString('en-US', {minimumFractionDigits: 2});
            } else {
                dueBox.style.display = 'none';
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

