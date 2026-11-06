<?php
// config/db.php
// Database Configuration for XAMPP
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'iron_store_db');

try {
    // 1. First connect to MySQL without specifying database (to auto-create DB if not exists)
    $pdo_init = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Auto-create database
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2. Connect directly to the database
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 3. Products table
    $createProductsTable = "
    CREATE TABLE IF NOT EXISTS `products` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_code` VARCHAR(50) NULL,
        `product_name` VARCHAR(150) NOT NULL,
        `category` VARCHAR(50) NOT NULL,
        `standard_length_feet` DECIMAL(8,2) DEFAULT 20.00,
        `weight_per_foot` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        `weight_per_piece` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
        `cost_price_per_kg` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `sell_price_per_kg` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `sell_price_per_foot` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `sell_price_per_piece` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `total_stock_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `alert_quantity_kg` DECIMAL(10,3) DEFAULT 50.000,
        `description` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createProductsTable);

    // Auto-migration for products: weight_per_piece
    $cols = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'weight_per_piece'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `weight_per_piece` DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER `weight_per_foot`");
        $pdo->exec("UPDATE `products` SET `weight_per_piece` = `weight_per_foot` * `standard_length_feet` WHERE `weight_per_piece` = 0");
    }

    // 4. Sales table
    $createSalesTable = "
    CREATE TABLE IF NOT EXISTS `sales` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
        `customer_name` VARCHAR(120) NULL,
        `customer_phone` VARCHAR(30) NULL,
        `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `due_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `payment_status` ENUM('paid', 'partial', 'due') NOT NULL DEFAULT 'paid',
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
        `total_profit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSalesTable);

    // Auto-migration for sales: total_profit
    $cols_s_profit = $pdo->query("SHOW COLUMNS FROM `sales` LIKE 'total_profit'")->fetchAll();
    if (empty($cols_s_profit)) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `total_profit` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `payment_method`");
    }

    // 5. Sale_items table
    $createSaleItemsTable = "
    CREATE TABLE IF NOT EXISTS `sale_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sale_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `product_name` VARCHAR(150) NOT NULL,
        `sale_unit` ENUM('kg', 'piece', 'feet') NOT NULL,
        `quantity` DECIMAL(10,2) NOT NULL,
        `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `item_profit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `deducted_stock_kg` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        `equivalent_pieces` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSaleItemsTable);

    // Auto-migration for sale_items: item_profit, equivalent_pieces, unit_cost
    $cols_si_profit = $pdo->query("SHOW COLUMNS FROM `sale_items` LIKE 'item_profit'")->fetchAll();
    if (empty($cols_si_profit)) {
        $pdo->exec("ALTER TABLE `sale_items` ADD COLUMN `item_profit` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`");
    }

    $cols_si_eq = $pdo->query("SHOW COLUMNS FROM `sale_items` LIKE 'equivalent_pieces'")->fetchAll();
    if (empty($cols_si_eq)) {
        $pdo->exec("ALTER TABLE `sale_items` ADD COLUMN `equivalent_pieces` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `deducted_stock_kg`");
    }

    // 6. Customer Payments Table (Vasooli / Khata Payments)
    $createPaymentsTable = "
    CREATE TABLE IF NOT EXISTS `customer_payments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `receipt_no` VARCHAR(50) NOT NULL UNIQUE,
        `customer_name` VARCHAR(150) NOT NULL,
        `customer_phone` VARCHAR(50) NULL,
        `sale_id` INT NULL,
        `amount_paid` DECIMAL(12,2) NOT NULL,
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
        `notes` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createPaymentsTable);

    // 7. Purchases Table (Stock Inward from Mill/Factory)
    $createPurchasesTable = "
    CREATE TABLE IF NOT EXISTS `purchases` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `purchase_no` VARCHAR(50) NOT NULL UNIQUE,
        `supplier_name` VARCHAR(150) NOT NULL,
        `supplier_phone` VARCHAR(50) NULL,
        `bilty_no` VARCHAR(100) NULL,
        `total_weight_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `extra_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Carriage or labor charges',
        `grand_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `due_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `payment_status` ENUM('paid', 'partial', 'due') NOT NULL DEFAULT 'paid',
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Bank Transfer',
        `notes` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createPurchasesTable);

    // 8. Purchase Items Table
    $createPurchaseItemsTable = "
    CREATE TABLE IF NOT EXISTS `purchase_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `purchase_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `product_name` VARCHAR(150) NOT NULL,
        `purchase_unit` ENUM('ton', 'kg', 'piece') NOT NULL,
        `quantity` DECIMAL(10,3) NOT NULL,
        `rate_per_unit` DECIMAL(12,2) NOT NULL,
        `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `added_stock_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createPurchaseItemsTable);

    // 9. Supplier Payments Table (Factory Ko Adaigi)
    $createSupplierPaymentsTable = "
    CREATE TABLE IF NOT EXISTS `supplier_payments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `voucher_no` VARCHAR(50) NOT NULL UNIQUE,
        `supplier_name` VARCHAR(150) NOT NULL,
        `supplier_phone` VARCHAR(50) NULL,
        `purchase_id` INT NULL,
        `amount_paid` DECIMAL(14,2) NOT NULL,
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Bank Transfer',
        `notes` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSupplierPaymentsTable);

    // 10. Settings table (For Profit Password/PIN storage)
    $createSettingsTable = "
    CREATE TABLE IF NOT EXISTS `settings` (
        `setting_key` VARCHAR(50) PRIMARY KEY,
        `setting_value` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSettingsTable);

    // Default PIN: 1234
    $pin_check = $pdo->query("SELECT setting_value FROM `settings` WHERE `setting_key` = 'profit_pin'")->fetch();
    if (!$pin_check) {
        $pdo->exec("INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('profit_pin', '1234')");
    }

    // 11. Users Table (Authentication & RBAC: Super Admin vs Salesman)
    $createUsersTable = "
    CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `fullname` VARCHAR(100) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('super_admin', 'salesman') NOT NULL DEFAULT 'salesman',
        `phone` VARCHAR(30) NULL,
        `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createUsersTable);

    // Seed default Super Admin ('admin' / 'admin123') if users table is empty
    $user_check = $pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
    if ($user_check == 0) {
        $default_admin_pwd = password_hash('admin123', PASSWORD_DEFAULT);
        $default_sales_pwd = password_hash('123456', PASSWORD_DEFAULT);
        
        $pdo->prepare("INSERT INTO `users` (`username`, `fullname`, `password`, `role`, `phone`) VALUES (?, ?, ?, ?, ?)")
            ->execute(['admin', 'Shop Owner (Super Admin)', $default_admin_pwd, 'super_admin', '0300-1111111']);
            
        $pdo->prepare("INSERT INTO `users` (`username`, `fullname`, `password`, `role`, `phone`) VALUES (?, ?, ?, ?, ?)")
            ->execute(['salesman', 'Counter Salesman', $default_sales_pwd, 'salesman', '0300-2222222']);
    }

    // 12. Auto-migration for Minimum Selling Rates (Kam se kam rate limit / Floor price)
    $cols_min_kg = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'min_price_per_kg'")->fetchAll();
    if (empty($cols_min_kg)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `min_price_per_kg` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `sell_price_per_kg`");
    }

    $cols_min_ft = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'min_price_per_foot'")->fetchAll();
    if (empty($cols_min_ft)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `min_price_per_foot` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `sell_price_per_foot`");
    }

    $cols_min_pc = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'min_price_per_piece'")->fetchAll();
    if (empty($cols_min_pc)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `min_price_per_piece` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `sell_price_per_piece`");
    }

    // Set default min rates to cost_price + 5% or 95% of sell_price for any existing products where min_price is 0
    $pdo->exec("
        UPDATE `products` 
        SET 
            `min_price_per_kg` = IF(`min_price_per_kg` <= 0, GREATEST(`cost_price_per_kg`, ROUND(`sell_price_per_kg` * 0.95, 2)), `min_price_per_kg`),
            `min_price_per_foot` = IF(`min_price_per_foot` <= 0, ROUND(`sell_price_per_foot` * 0.95, 2), `min_price_per_foot`),
            `min_price_per_piece` = IF(`min_price_per_piece` <= 0, ROUND(`sell_price_per_piece` * 0.95, 2), `min_price_per_piece`)
        WHERE `sell_price_per_kg` > 0 OR `sell_price_per_piece` > 0
    ");

    // 13. Sales Returns Tables (Maal Wapsi / Credit Notes)
    $createReturnsTable = "
    CREATE TABLE IF NOT EXISTS `sales_returns` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `return_no` VARCHAR(50) NOT NULL UNIQUE,
        `sale_id` INT NULL,
        `invoice_no` VARCHAR(50) NULL,
        `customer_name` VARCHAR(150) NOT NULL,
        `customer_phone` VARCHAR(50) NULL,
        `total_refund_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `total_returned_weight_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `refund_method` ENUM('Cash', 'Deduct from Khata', 'Credit Balance') NOT NULL DEFAULT 'Cash',
        `reason` TEXT NULL,
        `created_by` VARCHAR(50) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createReturnsTable);

    $createReturnItemsTable = "
    CREATE TABLE IF NOT EXISTS `sales_return_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `return_id` INT NOT NULL,
        `sale_item_id` INT NULL,
        `product_id` INT NOT NULL,
        `product_name` VARCHAR(150) NOT NULL,
        `sale_unit` ENUM('kg', 'feet', 'piece') NOT NULL,
        `quantity_returned` DECIMAL(10,3) NOT NULL,
        `unit_price` DECIMAL(12,2) NOT NULL,
        `refund_subtotal` DECIMAL(14,2) NOT NULL,
        `restored_stock_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `restored_pieces` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        FOREIGN KEY (`return_id`) REFERENCES `sales_returns`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createReturnItemsTable);

} catch (PDOException $e) {
    $db_connection_error = $e->getMessage();
}
