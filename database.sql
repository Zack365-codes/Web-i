-- database.sql
-- Iron Store Management Database Schema & Demo Data (Gates, Girders, Pipes, Saria)

CREATE DATABASE IF NOT EXISTS `iron_store_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `iron_store_db`;

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

-- Sample Iron Store Data with Gates, Girders, Pipes, and Saria
INSERT INTO `products` 
(`product_code`, `product_name`, `category`, `standard_length_feet`, `weight_per_foot`, `weight_per_piece`, `cost_price_per_kg`, `sell_price_per_kg`, `sell_price_per_foot`, `sell_price_per_piece`, `total_stock_kg`, `alert_quantity_kg`, `description`) 
VALUES
('GAT-101', 'Double Leaf Main Iron Gate (10x7 ft)', 'Iron Gate (Main Gate / Door)', 1.00, 0.0000, 135.000, 240.00, 290.00, 0.00, 45000.00, 675.000, 135.000, 'Heavy Duty Main Entrance Gate with 14-Gauge Sheet and decorative grill (5 pieces in stock)'),
('GIR-201', '7x4 Heavy Steel Girder (20 Feet)', 'Girder / Beam', 20.00, 7.5000, 150.000, 225.00, 255.00, 2000.00, 39500.00, 1500.000, 300.000, 'Standard 20-ft Heavy Structural Girder for Roof (10 pieces in stock)'),
('SAR-301', '1/2" (4 Sutar) Mughal Saria 60-Grade', 'Saria (Deformed Bar)', 40.00, 0.3040, 12.160, 230.00, 260.00, 85.00, 3400.00, 2500.000, 200.000, 'Original Grade 60 Certified Construction Steel'),
('PIP-401', '1x1 Square Pipe 16 Gauge (Chakor)', 'Square Pipe (Chakor)', 20.00, 0.2850, 5.700, 240.00, 280.00, 88.00, 1750.00, 855.000, 100.000, 'CRC Prime Quality Furniture/Grill Pipe (150 pieces in stock)');
