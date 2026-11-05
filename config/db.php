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

    // 7. Settings table (For Profit Password/PIN storage)
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

} catch (PDOException $e) {
    $db_connection_error = $e->getMessage();
}
