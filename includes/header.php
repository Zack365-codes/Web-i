<?php
// includes/header.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

// Enforce Login Authentication on all system pages
require_login();

// Handle PIN verification (Super Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_profit_pin'])) {
    if (is_super_admin() && isset($pdo)) {
        $entered_pin = trim($_POST['profit_pin'] ?? '');
        $pin_row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'profit_pin'")->fetch();
        $real_pin = $pin_row ? $pin_row['setting_value'] : '1234';

        if ($entered_pin === $real_pin) {
            $_SESSION['profit_unlocked'] = true;
            set_flash('success', 'Profit visibility unlocked successfully!');
        } else {
            set_flash('danger', 'Incorrect PIN code! Please try again.');
        }
        $redirect_url = strtok($_SERVER["REQUEST_URI"], '?');
        header("Location: " . $redirect_url);
        exit;
    }
}

// Handle PIN Change (Super Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_profit_pin'])) {
    if (is_super_admin() && isset($pdo)) {
        $current_pin = trim($_POST['current_pin'] ?? '');
        $new_pin = trim($_POST['new_pin'] ?? '');
        $pin_row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'profit_pin'")->fetch();
        $real_pin = $pin_row ? $pin_row['setting_value'] : '1234';

        if ($current_pin === $real_pin && !empty($new_pin)) {
            $up = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'profit_pin'");
            $up->execute([$new_pin]);
            set_flash('success', 'Profit PIN was updated successfully!');
        } else {
            set_flash('danger', 'Current PIN was incorrect. PIN could not be updated.');
        }
        $redirect_url = strtok($_SERVER["REQUEST_URI"], '?');
        header("Location: " . $redirect_url);
        exit;
    }
}

// Determine active page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' - ' : '' ?>Iron Store POS & Inventory</title>
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .profit-shield-badge {
            cursor: pointer;
            transition: all 0.2s ease;
            letter-spacing: 1px;
        }
        .profit-shield-badge:hover {
            transform: scale(1.05);
            background-color: #fef08a !important;
            color: #854d0e !important;
        }
    </style>
</head>
<body>

    <!-- Main Navigation Bar -->
    <nav class="navbar navbar-expand-xl navbar-dark main-navbar sticky-top py-2">
        <div class="container-fluid px-lg-3">
            <a class="navbar-brand d-flex align-items-center me-3" href="index.php">
                <i class="fa-solid fa-cubes-stacked me-2 text-warning fs-5"></i>
                <span>IRON STORE POS</span>
                <span class="brand-badge d-none d-md-inline">INVENTORY</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-xl-0 d-flex flex-wrap align-items-center gap-1">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>" href="index.php">
                            <i class="fa-solid fa-chart-pie me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'pos.php' ? 'active' : '' ?>" href="pos.php">
                            <i class="fa-solid fa-cash-register me-1 text-warning"></i> Billing POS
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'invoices.php' ? 'active' : '' ?>" href="invoices.php">
                            <i class="fa-solid fa-file-invoice me-1"></i> Invoices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['customers_khata.php', 'customer_ledger.php']) ? 'active' : '' ?>" href="customers_khata.php">
                            <i class="fa-solid fa-book-bookmark me-1 text-info"></i> Customer Khata
                        </a>
                    </li>

                    <?php if (is_super_admin()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= in_array($current_page, ['purchases.php', 'purchase_add.php']) ? 'active' : '' ?>" href="purchases.php">
                                <i class="fa-solid fa-truck-ramp-box me-1 text-primary"></i> Purchases
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= in_array($current_page, ['suppliers_khata.php', 'supplier_ledger.php']) ? 'active' : '' ?>" href="suppliers_khata.php">
                                <i class="fa-solid fa-building-columns me-1 text-warning"></i> Supplier Khata
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'products.php' ? 'active' : '' ?>" href="products.php">
                            <i class="fa-solid fa-boxes-stacked me-1"></i> Products
                        </a>
                    </li>

                    <!-- Profit Report TAB: ONLY VISIBLE TO SUPER ADMIN -->
                    <?php if (is_super_admin()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'profit_report.php' ? 'active' : '' ?>" href="profit_report.php">
                                <i class="fa-solid fa-chart-line me-1 text-success"></i> Profit Report
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-2 flex-nowrap ms-auto mt-2 mt-xl-0">
                    <!-- Profit Privacy Lock Button (Super Admin Only) -->
                    <?php if (is_super_admin()): ?>
                        <?php if (is_profit_unlocked()): ?>
                            <a href="?lock_profit=1" class="btn btn-outline-success btn-sm fw-bold px-2 py-1 text-nowrap" title="Profit is visible. Click to hide profit immediately!">
                                <i class="fa-solid fa-lock-open me-1"></i> Profit Visible
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-warning btn-sm fw-bold px-2 py-1 text-nowrap" data-bs-toggle="modal" data-bs-target="#profitPinModal" title="Click to enter PIN and view profit">
                                <i class="fa-solid fa-lock me-1"></i> Profit PIN
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- User Profile & Role Badge -->
                    <div class="d-flex align-items-center bg-dark bg-opacity-50 px-2 py-1 rounded border border-secondary border-opacity-50 text-white small text-nowrap">
                        <?php if (is_super_admin()): ?>
                            <span class="badge bg-primary me-2"><i class="fa-solid fa-user-shield me-1"></i>Admin</span>
                        <?php else: ?>
                            <span class="badge bg-secondary me-2"><i class="fa-solid fa-user me-1"></i>Salesman</span>
                        <?php endif; ?>
                        <span class="fw-semibold me-2"><?= htmlspecialchars($user['fullname'] ?? 'User') ?></span>
                        <a href="logout.php" class="btn btn-outline-danger btn-sm py-0 px-2 fw-bold" title="Sign Out">
                            <i class="fa-solid fa-right-from-bracket"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Global Connection Error Banner -->
    <?php if (isset($db_connection_error)): ?>
        <div class="container mt-3">
            <div class="alert alert-danger shadow-sm d-flex align-items-center" role="alert">
                <i class="fa-solid fa-triangle-exclamation fs-3 me-3 text-danger"></i>
                <div>
                    <h5 class="alert-heading mb-1 fw-bold">Database Connection Failed</h5>
                    <p class="mb-0">Please open XAMPP Control Panel and make sure <strong>MySQL</strong> is started.<br>
                    <small class="text-muted">Error Details: <?= htmlspecialchars($db_connection_error) ?></small></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Flash message display container -->
    <div class="container mt-3">
        <?php display_flash(); ?>
    </div>

    <!-- Main Content Container -->
    <main class="container-fluid px-lg-4 py-3 flex-grow-1">
