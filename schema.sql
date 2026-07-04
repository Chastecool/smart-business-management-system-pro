CREATE DATABASE IF NOT EXISTS `smart_business_management_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `smart_business_management_system`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(64) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'employee',
  `password_hash` VARCHAR(255) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employees` (
  `id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(50) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_employees_user_id` (`user_id`),
  CONSTRAINT `fk_employees_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `contact` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(100) DEFAULT NULL,
  `barcode` VARCHAR(100) DEFAULT NULL,
  `category_id` VARCHAR(64) DEFAULT NULL,
  `supplier_id` VARCHAR(64) DEFAULT NULL,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unit` VARCHAR(100) DEFAULT NULL,
  `minimum_stock` INT NOT NULL DEFAULT 0,
  `current_stock` INT NOT NULL DEFAULT 0,
  `image` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_supplier` (`supplier_id`),
  CONSTRAINT `fk_products_categories` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_suppliers` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` VARCHAR(64) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `quantity` INT NOT NULL,
  `previous_stock` INT NOT NULL,
  `new_stock` INT NOT NULL,
  `movement_type` VARCHAR(50) NOT NULL,
  `reference_type` VARCHAR(100) DEFAULT NULL,
  `reference_id` VARCHAR(64) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_movements_product` (`product_id`),
  KEY `idx_stock_movements_created_at` (`created_at`),
  CONSTRAINT `fk_stock_movements_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchases` (
  `id` VARCHAR(64) NOT NULL,
  `supplier_id` VARCHAR(64) DEFAULT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `quantity` INT NOT NULL,
  `purchase_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `date` VARCHAR(20) NOT NULL,
  `created_by_user_id` VARCHAR(64) DEFAULT NULL,
  `created_by_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchases_product` (`product_id`),
  KEY `idx_purchases_date` (`date`),
  CONSTRAINT `fk_purchases_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchases_suppliers` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales` (
  `id` VARCHAR(64) NOT NULL,
  `payment_type` VARCHAR(50) NOT NULL,
  `customer_name` VARCHAR(255) DEFAULT NULL,
  `customer_phone` VARCHAR(50) DEFAULT NULL,
  `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `employee_id` VARCHAR(64) DEFAULT NULL,
  `employee_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sales_created_at` (`created_at`),
  KEY `idx_sales_employee` (`employee_id`),
  CONSTRAINT `fk_sales_users` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` VARCHAR(64) NOT NULL,
  `sale_id` VARCHAR(64) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sale_items_sale` (`sale_id`),
  KEY `idx_sale_items_product` (`product_id`),
  CONSTRAINT `fk_sale_items_sales` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sale_items_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `debts` (
  `id` VARCHAR(64) NOT NULL,
  `sale_id` VARCHAR(64) DEFAULT NULL,
  `customer_name` VARCHAR(255) DEFAULT NULL,
  `customer_phone` VARCHAR(50) DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(50) NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_debts_sales` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `debt_payments` (
  `id` VARCHAR(64) NOT NULL,
  `debt_id` VARCHAR(64) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `created_by_user_id` VARCHAR(64) DEFAULT NULL,
  `created_by_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_debt_payments_debts` FOREIGN KEY (`debt_id`) REFERENCES `debts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `description` TEXT DEFAULT NULL,
  `date` VARCHAR(20) NOT NULL,
  `created_by_user_id` VARCHAR(64) DEFAULT NULL,
  `created_by_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_date` (`date`),
  CONSTRAINT `fk_expenses_users` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_stock` (
  `id` VARCHAR(64) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `product_name` VARCHAR(255) DEFAULT NULL,
  `date` VARCHAR(20) NOT NULL,
  `opening_stock` INT NOT NULL DEFAULT 0,
  `stock_in` INT NOT NULL DEFAULT 0,
  `remaining_stock` INT NOT NULL DEFAULT 0,
  `sold_stock` INT NOT NULL DEFAULT 0,
  `employee_id` VARCHAR(64) DEFAULT NULL,
  `employee_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_daily_stock_date` (`date`),
  KEY `idx_daily_stock_employee` (`employee_id`),
  CONSTRAINT `fk_daily_stock_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_daily_stock_users` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_reports` (
  `id` VARCHAR(64) NOT NULL,
  `date` VARCHAR(20) NOT NULL,
  `employee_id` VARCHAR(64) DEFAULT NULL,
  `employee_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_reports_date` (`date`),
  KEY `idx_stock_reports_employee` (`employee_id`),
  CONSTRAINT `fk_stock_reports_users` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_report_items` (
  `id` VARCHAR(64) NOT NULL,
  `stock_report_id` VARCHAR(64) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `product_name` VARCHAR(255) DEFAULT NULL,
  `opening_stock` INT NOT NULL DEFAULT 0,
  `stock_in` INT NOT NULL DEFAULT 0,
  `remaining_stock` INT NOT NULL DEFAULT 0,
  `sold_stock` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_report_items_report` (`stock_report_id`),
  CONSTRAINT `fk_stock_report_items_reports` FOREIGN KEY (`stock_report_id`) REFERENCES `stock_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_report_items_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cash_reconciliation` (
  `id` VARCHAR(64) NOT NULL,
  `stock_report_id` VARCHAR(64) NOT NULL,
  `date` VARCHAR(20) NOT NULL,
  `total_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cash_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `momo_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bank_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `expenses` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `expense_items` JSON DEFAULT NULL,
  `total_collected` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `final_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cash_difference` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `comment` TEXT DEFAULT NULL,
  `employee_id` VARCHAR(64) DEFAULT NULL,
  `employee_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cash_reconciliation_date` (`date`),
  CONSTRAINT `fk_cash_reconciliation_reports` FOREIGN KEY (`stock_report_id`) REFERENCES `stock_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cash_reconciliation_users` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_reconciliations` (
  `id` VARCHAR(64) NOT NULL,
  `date` VARCHAR(20) NOT NULL,
  `total_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cash_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `momo_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bank_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `expenses` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `expense_items` JSON DEFAULT NULL,
  `total_collected` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `final_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cash_difference` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `comment` TEXT DEFAULT NULL,
  `employee_id` VARCHAR(64) DEFAULT NULL,
  `employee_name` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Submitted',
  `approved_by` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_daily_reconciliations_date` (`date`),
  KEY `idx_daily_reconciliations_employee` (`employee_id`),
  CONSTRAINT `fk_daily_reconciliations_users` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_status` (
  `id` VARCHAR(64) NOT NULL,
  `report_id` VARCHAR(64) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Submitted',
  `comment` TEXT DEFAULT NULL,
  `updated_by_user_id` VARCHAR(64) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_status_report` (`report_id`),
  CONSTRAINT `fk_report_status_reports` FOREIGN KEY (`report_id`) REFERENCES `daily_reconciliations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_report_status_users` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT DEFAULT NULL,
  `type` VARCHAR(50) NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` VARCHAR(64) NOT NULL,
  `user_id` VARCHAR(64) DEFAULT NULL,
  `user_name` VARCHAR(255) DEFAULT NULL,
  `role` VARCHAR(50) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `business_name` VARCHAR(255) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `currency` VARCHAR(20) DEFAULT NULL,
  `receipt_footer` TEXT DEFAULT NULL,
  `timezone` VARCHAR(100) DEFAULT NULL,
  `low_stock_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_summaries` (
  `id` VARCHAR(64) NOT NULL,
  `report_type` VARCHAR(20) NOT NULL,
  `period_start` VARCHAR(20) NOT NULL,
  `period_end` VARCHAR(20) NOT NULL,
  `report_count` INT NOT NULL DEFAULT 0,
  `total_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cash_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `momo_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `expenses` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_collected` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `final_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cash_difference` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `generated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_summaries_type` (`report_type`),
  KEY `idx_report_summaries_period` (`period_start`, `period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `username`, `name`, `role`, `password_hash`, `status`, `created_at`) VALUES
('U-ADMIN', 'admin', 'Gatera Alphonse (Admin)', 'admin', '$2y$10$GyJaQoSZdob1MoCRrgJgeu/9DGSrxZPdxd0eqQ.tIPCRk8yWo5AFK', 'active', NOW()),
('U-EMP1', 'employee', 'Mutangana Jean (Staff)', 'employee', '$2y$10$tpuhCurIHu.l/spZWKd/y.r96ScZdl88ydqJTtmvZdusDd0c7IoWu', 'active', NOW()),
('U-EMP2', 'marie', 'Mukamana Marie (Staff)', 'marie', '$2y$10$.SjHCXewmvnvSBzcS0Y8rebKU1trxYgWzRds00F/Mz95Ef7J716XS', 'active', NOW());

INSERT INTO `employees` (`id`, `user_id`, `name`, `email`, `phone`, `salary`, `status`, `created_at`) VALUES
('E-1', 'U-EMP1', 'Mutangana Jean', 'jean@tequilabar.com', '+250 788 555 111', 150000.00, 'active', NOW()),
('E-2', 'U-EMP2', 'Mukamana Marie', 'marie@tequilabar.com', '+250 788 555 222', 140000.00, 'active', NOW());

INSERT INTO `categories` (`id`, `name`, `description`, `created_at`) VALUES
('CAT-BEER', 'Beers & Ciders', 'Cold local and imported beers', NOW()),
('CAT-SPIRIT', 'Liquors & Spirits', 'Premium spirits, gins, whiskeys and vodkas', NOW()),
('CAT-SOFT', 'Soft Drinks & Water', 'Refreshing sodas, energy drinks and pure water', NOW()),
('CAT-FOOD', 'Food & Kitchen', 'Delicious local brochettes, potatoes, pork and fish', NOW()),
('CAT-CIG', 'Cigarettes', 'Local and imported cigarettes', NOW());

INSERT INTO `products` (`id`, `name`, `code`, `barcode`, `category_id`, `supplier_id`, `price`, `cost`, `unit`, `minimum_stock`, `current_stock`, `image`, `status`, `created_at`, `updated_at`) VALUES
('P-1', 'Miitzig', 'PROD-MIITZIG', '', 'CAT-BEER', NULL, 2000.00, 1500.00, 'Bottle', 15, 120, '', 'active', NOW(), NOW()),
('P-2', 'P.miitzig', 'PROD-PMIITZIG', '', 'CAT-BEER', NULL, 1000.00, 750.00, 'Bottle', 15, 120, '', 'active', NOW(), NOW()),
('P-3', 'Babuji', 'PROD-BABUJI', '', 'CAT-SPIRIT', NULL, 1500.00, 1130.00, 'Bottle', 15, 120, '', 'active', NOW(), NOW()),
('P-4', 'G.fanta', 'PROD-GFANTA', '', 'CAT-SOFT', NULL, 1000.00, 750.00, 'Bottle', 15, 120, '', 'active', NOW(), NOW()),
('P-5', 'P.fanta', 'PROD-PFANTA', '', 'CAT-SOFT', NULL, 700.00, 530.00, 'Bottle', 15, 120, '', 'active', NOW(), NOW());

INSERT INTO `settings` (`business_name`, `address`, `phone`, `currency`, `receipt_footer`, `timezone`, `low_stock_alert_enabled`, `tax_rate`, `updated_at`) VALUES
('TEQUILA BAR & RESTAURANT', 'Base, Rulindo, Rwanda', '0783063787', 'RWF', 'Thank you for your business! Visit again.', 'Africa/Kigali', 1, 18.00, NOW());

INSERT INTO `notifications` (`id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
('N-LOW-STOCK', 'Low Stock Alert', 'Inventory review is recommended for a product nearing its minimum threshold.', 'warning', 0, NOW());
