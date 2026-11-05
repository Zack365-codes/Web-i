<?php
// customers_khata.php - Customer Credit / Udhar Ledger Directory
$page_title = "Customer Credit Ledger (Khata)";
require_once __DIR__ . '/includes/header.php';

// Handle Receiving Payment (Vasooli / Jama)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_payment'])) {
    if (isset($pdo)) {
        $c_name   = trim($_POST['customer_name'] ?? '');
        $c_phone  = trim($_POST['customer_phone'] ?? '');
        $amount   = floatval($_POST['amount_paid'] ?? 0);
        $method   = trim($_POST['payment_method'] ?? 'Cash');
        $notes    = trim($_POST['notes'] ?? '');

        if (empty($c_name) || $amount <= 0) {
            set_flash('danger', 'Please enter customer name and a valid payment amount.');
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Generate receipt number: REC-YYYYMMDD-XXX
                $date_prefix = date('Ymd');
                $count_stmt = $pdo->query("SELECT COUNT(*) FROM customer_payments WHERE DATE(created_at) = CURDATE()");
                $daily_count = $count_stmt->fetchColumn() + 1;
                $receipt_no = "REC-" . $date_prefix . "-" . str_pad($daily_count, 3, '0', STR_PAD_LEFT);

                // 2. Insert into customer_payments
                $insert_pay = $pdo->prepare("
                    INSERT INTO customer_payments (receipt_no, customer_name, customer_phone, amount_paid, payment_method, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insert_pay->execute([$receipt_no, $c_name, $c_phone, $amount, $method, $notes]);
                $payment_id = $pdo->lastInsertId();

                // 3. Automatically apply payment to oldest unpaid invoices for this customer
                $rem_payment = $amount;
                $inv_stmt = $pdo->prepare("
                    SELECT id, grand_total, paid_amount, due_amount 
                    FROM sales 
                    WHERE customer_name = ? AND due_amount > 0 
                    ORDER BY id ASC 
                    FOR UPDATE
                ");
                $inv_stmt->execute([$c_name]);
                $unpaid_invoices = $inv_stmt->fetchAll();

                foreach ($unpaid_invoices as $inv) {
                    if ($rem_payment <= 0) break;

                    $inv_id  = $inv['id'];
                    $inv_due = (float)$inv['due_amount'];

                    $applied = min($rem_payment, $inv_due);
                    $new_due = $inv_due - $applied;
                    $new_paid = (float)$inv['paid_amount'] + $applied;
                    $status = ($new_due <= 0.01) ? 'paid' : 'partial';

                    $update_inv = $pdo->prepare("
                        UPDATE sales 
                        SET paid_amount = ?, due_amount = ?, payment_status = ? 
                        WHERE id = ?
                    ");
                    $update_inv->execute([$new_paid, max(0, $new_due), $status, $inv_id]);

                    $rem_payment -= $applied;
                }

                $pdo->commit();
                set_flash('success', "Payment of Rs. " . number_format($amount, 2) . " received successfully from '{$c_name}'!");
                echo "<script>window.location.href = 'payment_receipt_print.php?id={$payment_id}&autoprint=1';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('danger', 'Failed to process payment: ' . $e->getMessage());
            }
        }
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter_due = isset($_GET['filter_due']) ? $_GET['filter_due'] : '1'; // default show customers with due

// Query customer summaries
$query = "
    SELECT 
        customer_name, 
        MAX(customer_phone) as customer_phone,
        COUNT(id) as total_invoices,
        SUM(grand_total) as total_purchased,
        SUM(paid_amount) as total_paid,
        SUM(due_amount) as total_due,
        MAX(created_at) as last_purchase_date
    FROM sales 
    WHERE customer_name IS NOT NULL AND customer_name != ''
";
$params = [];

if (!empty($search)) {
    $query .= " AND (customer_name LIKE ? OR customer_phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$query .= " GROUP BY customer_name";

if ($filter_due === '1') {
    $query .= " HAVING total_due > 0";
}

$query .= " ORDER BY total_due DESC, total_purchased DESC";

$customers = [];
$total_market_due = 0;
$total_customers_count = 0;
$total_market_paid = 0;

if (isset($pdo)) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        // Calculate overarching totals
        $tot_stmt = $pdo->query("SELECT COALESCE(SUM(due_amount), 0) as tot_due, COALESCE(SUM(paid_amount), 0) as tot_paid FROM sales");
        $tot_data = $tot_stmt->fetch();
        $total_market_due = (float)$tot_data['tot_due'];
        $total_market_paid = (float)$tot_data['tot_paid'];
        $total_customers_count = count($customers);

    } catch (PDOException $e) {
        $error = "Failed to load credit ledger: " . $e->getMessage();
    }
}
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-book-bookmark text-primary me-2"></i>Customer Credit Ledger (Udhar Khata)
        </h3>
        <p class="text-muted mb-0">Track all customer credit accounts, total receivables (market udhar), and record cash payments.</p>
    </div>
    <div class="col-md-5 text-md-end">
        <button type="button" class="btn btn-success shadow-sm fw-bold me-2" data-bs-toggle="modal" data-bs-target="#receivePaymentModal">
            <i class="fa-solid fa-hand-holding-dollar me-1"></i> Receive Payment (Jama Karein)
        </button>
        <a href="invoices.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-invoice me-1"></i> Invoices
        </a>
    </div>
</div>

<!-- 3 KPI Cards for Credit & Udhar -->
<div class="row g-3 mb-4">
    <!-- Total Market Udhar (Receivables) -->
    <div class="col-md-4">
        <div class="stat-card shadow-sm h-100 border-danger" style="background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label text-danger fw-bold">Total Market Udhar (Receivable)</span>
                <div class="stat-icon bg-danger text-white">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
            </div>
            <div class="stat-value text-danger"><?= format_currency($total_market_due, 0) ?></div>
            <div class="small text-muted mt-2">
                Total money pending to be collected from customers
            </div>
        </div>
    </div>

    <!-- Total Collected Payments -->
    <div class="col-md-4">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Total Payments Received</span>
                <div class="stat-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="stat-value text-success"><?= format_currency($total_market_paid, 0) ?></div>
            <div class="small text-muted mt-2">
                Total cash/bank collections recorded
            </div>
        </div>
    </div>

    <!-- Active Credit Customers -->
    <div class="col-md-4">
        <div class="stat-card shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="stat-label">Credit Customers</span>
                <div class="stat-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <div class="stat-value text-dark"><?= $total_customers_count ?></div>
            <div class="small text-muted mt-2">
                <?= ($filter_due === '1') ? 'Showing customers with pending balance' : 'Showing all customers' ?>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card card-custom mb-4">
    <div class="card-body p-3">
        <form method="GET" action="customers_khata.php" class="row g-2 align-items-center">
            <div class="col-md-7">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search customer by name or phone..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="filter_due" class="form-select">
                    <option value="1" <?= $filter_due === '1' ? 'selected' : '' ?>>Only With Due Balance (Udhar Wale)</option>
                    <option value="0" <?= $filter_due === '0' ? 'selected' : '' ?>>All Customers (Clear + Udhar)</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-dark w-100"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                <?php if (!empty($search) || $filter_due === '0'): ?>
                    <a href="customers_khata.php" class="btn btn-outline-secondary"><i class="fa-solid fa-rotate"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Customer Khata Table -->
<div class="card card-custom">
    <div class="table-responsive">
        <table class="table table-hover table-custom align-middle mb-0">
            <thead>
                <tr>
                    <th>Customer Name</th>
                    <th>Phone</th>
                    <th>Total Bills</th>
                    <th>Total Purchased</th>
                    <th>Total Paid</th>
                    <th>Current Due Balance (Udhar)</th>
                    <th>Last Purchase</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="fa-solid fa-users-slash text-muted fs-1 mb-3 d-block"></i>
                            <h5 class="fw-bold text-secondary">No Credit Accounts Found!</h5>
                            <p class="text-muted small">No customers found with pending balances. Create invoices with credit in POS.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): 
                        $due = (float)$c['total_due'];
                    ?>
                        <tr>
                            <td>
                                <a href="customer_ledger.php?name=<?= urlencode($c['customer_name']) ?>" class="fw-bold text-dark fs-6 text-decoration-none hover-primary">
                                    <i class="fa-solid fa-user me-2 text-primary"></i><?= htmlspecialchars($c['customer_name']) ?>
                                </a>
                            </td>

                            <td class="small text-muted">
                                <?= htmlspecialchars($c['customer_phone'] ?: 'N/A') ?>
                            </td>

                            <td>
                                <span class="badge bg-light text-secondary border"><?= $c['total_invoices'] ?> Invoices</span>
                            </td>

                            <td class="fw-semibold text-dark">
                                <?= format_currency($c['total_purchased']) ?>
                            </td>

                            <td class="fw-semibold text-success">
                                <?= format_currency($c['total_paid']) ?>
                            </td>

                            <td>
                                <?php if ($due > 0): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle fs-6 px-3 py-2 fw-bold">
                                        <?= format_currency($due) ?> DUE
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fw-bold">
                                        <i class="fa-solid fa-circle-check me-1"></i> Cleared (NIL)
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="small text-muted">
                                <?= date('d-M-Y', strtotime($c['last_purchase_date'])) ?>
                            </td>

                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($due > 0): ?>
                                        <button type="button" class="btn btn-success btn-receive-payment" 
                                                data-name="<?= htmlspecialchars($c['customer_name']) ?>"
                                                data-phone="<?= htmlspecialchars($c['customer_phone'] ?? '') ?>"
                                                data-due="<?= $due ?>">
                                            <i class="fa-solid fa-hand-holding-dollar me-1"></i> Receive
                                        </button>
                                    <?php endif; ?>
                                    <a href="customer_ledger.php?name=<?= urlencode($c['customer_name']) ?>" class="btn btn-outline-primary" title="View Statement / Khata">
                                        <i class="fa-solid fa-file-lines me-1"></i> Khata
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

<!-- Modal: Receive Payment (Raqam Jama Karein) -->
<div class="modal fade" id="receivePaymentModal" tabindex="-1" aria-labelledby="receivePaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-success">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title fw-bold" id="receivePaymentModalLabel">
                    <i class="fa-solid fa-hand-holding-dollar me-2"></i>Receive Customer Payment (Raqam Jama Karein)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" action="customers_khata.php">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="modal_customer_name" class="form-label fw-bold">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" id="modal_customer_name" name="customer_name" class="form-control form-control-lg fw-bold" required placeholder="Customer Name">
                    </div>

                    <div class="mb-3">
                        <label for="modal_customer_phone" class="form-label fw-bold">Phone Number</label>
                        <input type="text" id="modal_customer_phone" name="customer_phone" class="form-control" placeholder="0300-1234567">
                    </div>

                    <!-- Due alert if known -->
                    <div id="modal_due_alert" class="alert alert-danger py-2 mb-3 d-none">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Current Outstanding Balance:</span>
                            <span class="fw-bold fs-6" id="modal_due_display">Rs. 0.00</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="modal_amount_paid" class="form-label fw-bold text-success">
                            Amount Paid by Customer (Rs.) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-success text-white fw-bold">Rs.</span>
                            <input type="number" step="1" min="1" id="modal_amount_paid" name="amount_paid" class="form-control fw-bold text-success" required placeholder="e.g. 20000">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="Cash">Cash (Naqd)</option>
                            <option value="Bank Transfer">Bank Transfer / Online</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold">Notes / Receipt Remarks</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional note (e.g. Partial recovery for Gate bill)">
                    </div>
                </div>

                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="receive_payment" class="btn btn-success fw-bold px-4">
                        <i class="fa-solid fa-check-circle me-1"></i> Save & Print Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalCustName  = document.getElementById('modal_customer_name');
    const modalCustPhone = document.getElementById('modal_customer_phone');
    const modalAmount    = document.getElementById('modal_amount_paid');
    const modalDueAlert  = document.getElementById('modal_due_alert');
    const modalDueDisplay= document.getElementById('modal_due_display');
    const paymentModal   = new bootstrap.Modal(document.getElementById('receivePaymentModal'));

    document.querySelectorAll('.btn-receive-payment').forEach(btn => {
        btn.addEventListener('click', function () {
            const name = this.dataset.name;
            const phone = this.dataset.phone;
            const due = parseFloat(this.dataset.due) || 0;

            modalCustName.value = name;
            modalCustPhone.value = phone;
            modalAmount.value = due;

            modalDueAlert.classList.remove('d-none');
            modalDueDisplay.textContent = 'Rs. ' + due.toLocaleString('en-US', { minimumFractionDigits: 2 });

            paymentModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

