<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

class DatabaseSetupException extends Exception {}

class DBManager {
  private PDO $pdo;
  private array $rawData = [];
  private array $defaultPasswords = [
    'admin' => 'admin123',
    'employee' => 'emp123',
    'marie' => 'marie123'
  ];

  public function __construct() {
    $this->connect();
    $this->initializeSchema();
    $missingTables = $this->getMissingTables();
    if ($missingTables !== []) {
      throw new DatabaseSetupException(
        'The database has not been imported yet. Please import the included schema.sql file into MySQL, verify the credentials in config/database.php, and refresh the app.'
      );
    }
    $this->bootstrapData();
    $this->loadCache();
  }

  private function getMissingTables(): array {
    $requiredTables = ['users', 'employees', 'categories', 'products', 'settings', 'daily_reconciliations', 'report_summaries'];
    $stmt = $this->pdo->query('SHOW TABLES');
    $existing = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
      $existing[] = strtolower((string)($row[0] ?? ''));
    }
    $missing = [];
    foreach ($requiredTables as $table) {
      if (!in_array($table, $existing, true)) {
        $missing[] = $table;
      }
    }
    return $missing;
  }

  private function connect(): void {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
    $this->pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', DB_NAME) . '`');
    $this->pdo->exec('USE `' . str_replace('`', '``', DB_NAME) . '`');
  }

  private function initializeSchema(): void {
    $this->pdo->exec('CREATE TABLE IF NOT EXISTS users (
      id VARCHAR(64) PRIMARY KEY,
      username VARCHAR(100) NOT NULL UNIQUE,
      name VARCHAR(255) NOT NULL,
      role VARCHAR(50) NOT NULL DEFAULT "employee",
      password_hash VARCHAR(255) NOT NULL,
      status VARCHAR(50) NOT NULL DEFAULT "active",
      created_at DATETIME NOT NULL
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS employees (
      id VARCHAR(64) PRIMARY KEY,
      user_id VARCHAR(64) NOT NULL,
      name VARCHAR(255) NOT NULL,
      email VARCHAR(255) NULL,
      phone VARCHAR(50) NULL,
      salary DECIMAL(12,2) NOT NULL DEFAULT 0,
      status VARCHAR(50) NOT NULL DEFAULT "active",
      created_at DATETIME NOT NULL,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_employees_user_id (user_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS categories (
      id VARCHAR(64) PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      description TEXT NULL,
      created_at DATETIME NOT NULL
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS suppliers (
      id VARCHAR(64) PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      contact VARCHAR(255) NULL,
      phone VARCHAR(50) NULL,
      email VARCHAR(255) NULL,
      created_at DATETIME NOT NULL
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS products (
      id VARCHAR(64) PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      code VARCHAR(100) NULL,
      barcode VARCHAR(100) NULL,
      category_id VARCHAR(64) NULL,
      supplier_id VARCHAR(64) NULL,
      price DECIMAL(12,2) NOT NULL DEFAULT 0,
      cost DECIMAL(12,2) NOT NULL DEFAULT 0,
      unit VARCHAR(100) NULL,
      minimum_stock INT NOT NULL DEFAULT 0,
      current_stock INT NOT NULL DEFAULT 0,
      image VARCHAR(255) NULL,
      status VARCHAR(50) NOT NULL DEFAULT "active",
      created_at DATETIME NOT NULL,
      updated_at DATETIME NULL,
      FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
      FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
      INDEX idx_products_category (category_id),
      INDEX idx_products_supplier (supplier_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS stock_movements (
      id VARCHAR(64) PRIMARY KEY,
      product_id VARCHAR(64) NOT NULL,
      quantity INT NOT NULL,
      previous_stock INT NOT NULL,
      new_stock INT NOT NULL,
      movement_type VARCHAR(50) NOT NULL,
      reference_type VARCHAR(100) NULL,
      reference_id VARCHAR(64) NULL,
      notes TEXT NULL,
      created_by_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      INDEX idx_stock_movements_product (product_id),
      INDEX idx_stock_movements_created_at (created_at)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS purchases (
      id VARCHAR(64) PRIMARY KEY,
      supplier_id VARCHAR(64) NULL,
      product_id VARCHAR(64) NOT NULL,
      quantity INT NOT NULL,
      purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
      date VARCHAR(20) NOT NULL,
      created_by_user_id VARCHAR(64) NULL,
      created_by_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
      INDEX idx_purchases_product (product_id),
      INDEX idx_purchases_date (date)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS sales (
      id VARCHAR(64) PRIMARY KEY,
      payment_type VARCHAR(50) NOT NULL,
      customer_name VARCHAR(255) NULL,
      customer_phone VARCHAR(50) NULL,
      amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
      total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      employee_id VARCHAR(64) NULL,
      employee_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_sales_created_at (created_at),
      INDEX idx_sales_employee (employee_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS sale_items (
      id VARCHAR(64) PRIMARY KEY,
      sale_id VARCHAR(64) NOT NULL,
      product_id VARCHAR(64) NOT NULL,
      product_name VARCHAR(255) NOT NULL,
      quantity INT NOT NULL DEFAULT 0,
      unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
      total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      INDEX idx_sale_items_sale (sale_id),
      INDEX idx_sale_items_product (product_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS debts (
      id VARCHAR(64) PRIMARY KEY,
      sale_id VARCHAR(64) NULL,
      customer_name VARCHAR(255) NULL,
      customer_phone VARCHAR(50) NULL,
      total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
      balance DECIMAL(12,2) NOT NULL DEFAULT 0,
      status VARCHAR(50) NOT NULL DEFAULT "open",
      created_at DATETIME NOT NULL,
      FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS debt_payments (
      id VARCHAR(64) PRIMARY KEY,
      debt_id VARCHAR(64) NOT NULL,
      amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      payment_method VARCHAR(50) NULL,
      created_by_user_id VARCHAR(64) NULL,
      created_by_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (debt_id) REFERENCES debts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS expenses (
      id VARCHAR(64) PRIMARY KEY,
      title VARCHAR(255) NOT NULL,
      category VARCHAR(255) NULL,
      amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      description TEXT NULL,
      date VARCHAR(20) NOT NULL,
      created_by_user_id VARCHAR(64) NULL,
      created_by_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_expenses_date (date)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS daily_stock (
      id VARCHAR(64) PRIMARY KEY,
      product_id VARCHAR(64) NOT NULL,
      product_name VARCHAR(255) NULL,
      date VARCHAR(20) NOT NULL,
      opening_stock INT NOT NULL DEFAULT 0,
      stock_in INT NOT NULL DEFAULT 0,
      remaining_stock INT NOT NULL DEFAULT 0,
      sold_stock INT NOT NULL DEFAULT 0,
      employee_id VARCHAR(64) NULL,
      employee_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_daily_stock_date (date),
      INDEX idx_daily_stock_employee (employee_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS stock_reports (
      id VARCHAR(64) PRIMARY KEY,
      date VARCHAR(20) NOT NULL,
      employee_id VARCHAR(64) NULL,
      employee_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_stock_reports_date (date),
      INDEX idx_stock_reports_employee (employee_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS stock_report_items (
      id VARCHAR(64) PRIMARY KEY,
      stock_report_id VARCHAR(64) NOT NULL,
      product_id VARCHAR(64) NOT NULL,
      product_name VARCHAR(255) NULL,
      opening_stock INT NOT NULL DEFAULT 0,
      stock_in INT NOT NULL DEFAULT 0,
      remaining_stock INT NOT NULL DEFAULT 0,
      sold_stock INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (stock_report_id) REFERENCES stock_reports(id) ON DELETE CASCADE,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      INDEX idx_stock_report_items_report (stock_report_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS cash_reconciliation (
      id VARCHAR(64) PRIMARY KEY,
      stock_report_id VARCHAR(64) NOT NULL,
      date VARCHAR(20) NOT NULL,
      total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      momo_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      bank_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
      expense_items JSON NULL,
      total_collected DECIMAL(12,2) NOT NULL DEFAULT 0,
      final_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_difference DECIMAL(12,2) NOT NULL DEFAULT 0,
      comment TEXT NULL,
      employee_id VARCHAR(64) NULL,
      employee_name VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      FOREIGN KEY (stock_report_id) REFERENCES stock_reports(id) ON DELETE CASCADE,
      FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_cash_reconciliation_date (date)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS daily_reconciliations (
      id VARCHAR(64) PRIMARY KEY,
      date VARCHAR(20) NOT NULL,
      total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      momo_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      bank_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
      expense_items JSON NULL,
      total_collected DECIMAL(12,2) NOT NULL DEFAULT 0,
      final_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_difference DECIMAL(12,2) NOT NULL DEFAULT 0,
      comment TEXT NULL,
      employee_id VARCHAR(64) NULL,
      employee_name VARCHAR(255) NULL,
      status VARCHAR(50) NOT NULL DEFAULT "Submitted",
      approved_by VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NULL,
      FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_daily_reconciliations_date (date),
      INDEX idx_daily_reconciliations_employee (employee_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS report_status (
      id VARCHAR(64) PRIMARY KEY,
      report_id VARCHAR(64) NOT NULL,
      status VARCHAR(50) NOT NULL DEFAULT "Submitted",
      comment TEXT NULL,
      updated_by_user_id VARCHAR(64) NULL,
      updated_at DATETIME NOT NULL,
      FOREIGN KEY (report_id) REFERENCES daily_reconciliations(id) ON DELETE CASCADE,
      FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_report_status_report (report_id)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
      id VARCHAR(64) PRIMARY KEY,
      title VARCHAR(255) NOT NULL,
      message TEXT NULL,
      type VARCHAR(50) NOT NULL DEFAULT "info",
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS activity_logs (
      id VARCHAR(64) PRIMARY KEY,
      user_id VARCHAR(64) NULL,
      user_name VARCHAR(255) NULL,
      role VARCHAR(50) NULL,
      action VARCHAR(100) NOT NULL,
      details TEXT NULL,
      created_at DATETIME NOT NULL,
      INDEX idx_activity_logs_created_at (created_at)
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS settings (
      id INT PRIMARY KEY AUTO_INCREMENT,
      business_name VARCHAR(255) NULL,
      address VARCHAR(255) NULL,
      phone VARCHAR(50) NULL,
      currency VARCHAR(20) NULL,
      receipt_footer TEXT NULL,
      timezone VARCHAR(100) NULL,
      low_stock_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
      tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
      updated_at DATETIME NULL
    ) ENGINE=InnoDB');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS report_summaries (
      id VARCHAR(64) PRIMARY KEY,
      report_type VARCHAR(20) NOT NULL,
      period_start VARCHAR(20) NOT NULL,
      period_end VARCHAR(20) NOT NULL,
      report_count INT NOT NULL DEFAULT 0,
      total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      momo_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
      total_collected DECIMAL(12,2) NOT NULL DEFAULT 0,
      final_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_difference DECIMAL(12,2) NOT NULL DEFAULT 0,
      generated_at DATETIME NOT NULL,
      INDEX idx_report_summaries_type (report_type),
      INDEX idx_report_summaries_period (period_start, period_end)
    ) ENGINE=InnoDB');
  }

  private function bootstrapData(): void {
    $this->ensureDefaultSettings();
    $this->ensureSeedUsers();
    $this->ensureSeedCategories();
    $this->ensureSeedProducts();
    $this->ensureSeedNotifications();
    $this->generateAutomaticReportSummaries();
  }

  private function ensureDefaultSettings(): void {
    $count = (int)$this->pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    if ($count > 0) return;
    $stmt = $this->pdo->prepare('INSERT INTO settings (business_name, address, phone, currency, receipt_footer, timezone, low_stock_alert_enabled, tax_rate, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute(['TEQUILA BAR & RESTAURANT', 'Base, Rulindo, Rwanda', '0783063787', 'RWF', 'Thank you for your business! Visit again.', 'Africa/Kigali', 1, 18.00]);
  }

  private function ensureSeedUsers(): void {
    $count = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) return;
    $users = [
      ['U-ADMIN', 'admin', 'Gatera Alphonse (Admin)', 'admin', 'admin123', 'active', 'admin'],
      ['U-EMP1', 'employee', 'Mutangana Jean (Staff)', 'employee', 'emp123', 'active', 'employee'],
      ['U-EMP2', 'marie', 'Mukamana Marie (Staff)', 'marie', 'marie123', 'active', 'employee'],
    ];
    $stmt = $this->pdo->prepare('INSERT INTO users (id, username, name, role, password_hash, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    foreach ($users as $u) {
      $stmt->execute([$u[0], $u[1], $u[2], $u[3] === 'admin' ? 'admin' : 'employee', password_hash($u[4], PASSWORD_DEFAULT), $u[5]]);
    }
    $stmtEmp = $this->pdo->prepare('INSERT INTO employees (id, user_id, name, email, phone, salary, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmtEmp->execute(['E-1', 'U-EMP1', 'Mutangana Jean', 'jean@tequilabar.com', '+250 788 555 111', 150000.00, 'active']);
    $stmtEmp->execute(['E-2', 'U-EMP2', 'Mukamana Marie', 'marie@tequilabar.com', '+250 788 555 222', 140000.00, 'active']);
  }

  private function ensureSeedCategories(): void {
    $count = (int)$this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count > 0) return;
    $stmt = $this->pdo->prepare('INSERT INTO categories (id, name, description, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute(['CAT-BEER', 'Beers & Ciders', 'Cold local and imported beers']);
    $stmt->execute(['CAT-SPIRIT', 'Liquors & Spirits', 'Premium spirits, gins, whiskeys and vodkas']);
    $stmt->execute(['CAT-SOFT', 'Soft Drinks & Water', 'Refreshing sodas, energy drinks and pure water']);
    $stmt->execute(['CAT-FOOD', 'Food & Kitchen', 'Delicious local brochettes, potatoes, pork and fish']);
    $stmt->execute(['CAT-CIG', 'Cigarettes', 'Local and imported cigarettes']);
  }

  private function ensureSeedProducts(): void {
    $existingProducts = $this->fetchAll('SELECT id FROM products');
    $existingIds = [];
    foreach ($existingProducts as $product) {
      $existingIds[(string)($product['id'] ?? '')] = true;
    }

    $seedProducts = $this->loadSeedProductsFromBackup();
    if ($seedProducts === []) {
      $fallbackProducts = [
        ['id' => 'P-1', 'name' => 'Miitzig', 'code' => 'PROD-MIITZIG', 'barcode' => '', 'category_id' => 'CAT-BEER', 'supplier_id' => null, 'price' => 2000.00, 'cost' => 1500.00, 'unit' => 'Bottle', 'minimum_stock' => 15, 'current_stock' => 120],
        ['id' => 'P-2', 'name' => 'P.miitzig', 'code' => 'PROD-PMIITZIG', 'barcode' => '', 'category_id' => 'CAT-BEER', 'supplier_id' => null, 'price' => 1000.00, 'cost' => 750.00, 'unit' => 'Bottle', 'minimum_stock' => 15, 'current_stock' => 120],
        ['id' => 'P-3', 'name' => 'Babuji', 'code' => 'PROD-BABUJI', 'barcode' => '', 'category_id' => 'CAT-SPIRIT', 'supplier_id' => null, 'price' => 1500.00, 'cost' => 1130.00, 'unit' => 'Bottle', 'minimum_stock' => 15, 'current_stock' => 120],
        ['id' => 'P-4', 'name' => 'G.fanta', 'code' => 'PROD-GFANTA', 'barcode' => '', 'category_id' => 'CAT-SOFT', 'supplier_id' => null, 'price' => 1000.00, 'cost' => 750.00, 'unit' => 'Bottle', 'minimum_stock' => 15, 'current_stock' => 120],
        ['id' => 'P-5', 'name' => 'P.fanta', 'code' => 'PROD-PFANTA', 'barcode' => '', 'category_id' => 'CAT-SOFT', 'supplier_id' => null, 'price' => 700.00, 'cost' => 530.00, 'unit' => 'Bottle', 'minimum_stock' => 15, 'current_stock' => 120],
      ];
      $stmt = $this->pdo->prepare('INSERT INTO products (id, name, code, barcode, category_id, supplier_id, price, cost, unit, minimum_stock, current_stock, image, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
      foreach ($fallbackProducts as $product) {
        $id = (string)($product['id'] ?? '');
        if ($id === '' || isset($existingIds[$id])) continue;
        $stmt->execute([$id, (string)($product['name'] ?? ''), (string)($product['code'] ?? ''), (string)($product['barcode'] ?? ''), (string)($product['category_id'] ?? ''), $product['supplier_id'] ?? null, (float)($product['price'] ?? 0), (float)($product['cost'] ?? 0), (string)($product['unit'] ?? ''), (int)($product['minimum_stock'] ?? 0), (int)($product['current_stock'] ?? 0), '', 'active']);
        $existingIds[$id] = true;
      }
      return;
    }

    $stmt = $this->pdo->prepare('INSERT INTO products (id, name, code, barcode, category_id, supplier_id, price, cost, unit, minimum_stock, current_stock, image, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    foreach ($seedProducts as $product) {
      $id = (string)($product['id'] ?? '');
      if ($id === '' || isset($existingIds[$id])) continue;
      $stmt->execute([
        $id,
        (string)($product['name'] ?? ''),
        (string)($product['code'] ?? ''),
        (string)($product['barcode'] ?? ''),
        (string)($product['category_id'] ?? ''),
        $product['supplier_id'] ?? null,
        (float)($product['price'] ?? 0),
        (float)($product['cost'] ?? 0),
        (string)($product['unit'] ?? ''),
        (int)($product['minimum_stock'] ?? 0),
        (int)($product['current_stock'] ?? 0),
        '',
        'active',
      ]);
      $existingIds[$id] = true;
    }
  }

  private function loadSeedProductsFromBackup(): array {
    $candidatePaths = [
      dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database.json',
      dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'database.json',
    ];

    foreach ($candidatePaths as $path) {
      if (!is_file($path)) continue;
      $data = json_decode((string)file_get_contents($path), true);
      if (is_array($data) && isset($data['products']) && is_array($data['products'])) {
        return $data['products'];
      }
    }

    return [];
  }

  private function ensureSeedNotifications(): void {
    $count = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    if ($count > 0) return;
    $stmt = $this->pdo->prepare('INSERT INTO notifications (id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute(['N-LOW-STOCK', 'Low Stock Alert', 'Inventory review is recommended for a product nearing its minimum threshold.', 'warning', 0]);
  }

  private function generateAutomaticReportSummaries(): void {
    $this->pdo->exec('CREATE TABLE IF NOT EXISTS report_summaries (
      id VARCHAR(64) PRIMARY KEY,
      report_type VARCHAR(20) NOT NULL,
      period_start VARCHAR(20) NOT NULL,
      period_end VARCHAR(20) NOT NULL,
      report_count INT NOT NULL DEFAULT 0,
      total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      momo_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
      total_collected DECIMAL(12,2) NOT NULL DEFAULT 0,
      final_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
      cash_difference DECIMAL(12,2) NOT NULL DEFAULT 0,
      generated_at DATETIME NOT NULL,
      INDEX idx_report_summaries_type (report_type),
      INDEX idx_report_summaries_period (period_start, period_end)
    ) ENGINE=InnoDB');

    $dailyReports = $this->fetchAll('SELECT * FROM daily_reconciliations WHERE date IS NOT NULL AND date <> "" ORDER BY date ASC');
    $seen = [];
    foreach ($dailyReports as $report) {
      $date = (string)($report['date'] ?? '');
      if ($date === '') continue;
      foreach (['weekly', 'monthly', 'yearly'] as $type) {
        [$startDate, $endDate] = $this->getReportSummaryPeriod($type, $date);
        $key = $type . ':' . $startDate . ':' . $endDate;
        if (isset($seen[$key])) continue;
        $this->ensureSummaryForPeriod($type, $startDate, $endDate);
        $seen[$key] = true;
      }
    }
  }

  private function getReportSummaryPeriod(string $type, string $dateStr): array {
    $date = new DateTime($dateStr);
    $date->setTime(0, 0, 0);
    if ($type === 'weekly') {
      return [(clone $date)->modify('monday this week')->format('Y-m-d'), (clone $date)->modify('sunday this week')->format('Y-m-d')];
    }
    if ($type === 'monthly') {
      return [(clone $date)->modify('first day of this month')->format('Y-m-d'), (clone $date)->modify('last day of this month')->format('Y-m-d')];
    }
    return [(clone $date)->modify('first day of january this year')->format('Y-m-d'), (clone $date)->modify('last day of december this year')->format('Y-m-d')];
  }

  private function ensureSummaryForPeriod(string $type, string $startDate, string $endDate): void {
    $existing = $this->fetchAll('SELECT id FROM report_summaries WHERE report_type = ? AND period_start = ? AND period_end = ? LIMIT 1', [$type, $startDate, $endDate]);
    if ($existing !== []) return;

    $rows = $this->fetchAll('SELECT * FROM daily_reconciliations WHERE date BETWEEN ? AND ? ORDER BY date ASC', [$startDate, $endDate]);
    $totals = ['total_sales' => 0.0, 'cash_amount' => 0.0, 'momo_amount' => 0.0, 'expenses' => 0.0, 'total_collected' => 0.0, 'final_balance' => 0.0, 'cash_difference' => 0.0];
    foreach ($rows as $row) {
      $totals['total_sales'] += (float)($row['total_sales'] ?? 0);
      $totals['cash_amount'] += (float)($row['cash_amount'] ?? 0);
      $totals['momo_amount'] += (float)($row['momo_amount'] ?? 0);
      $totals['expenses'] += (float)($row['expenses'] ?? 0);
      $totals['total_collected'] += (float)($row['total_collected'] ?? 0);
      $totals['final_balance'] += (float)($row['final_balance'] ?? 0);
      $totals['cash_difference'] += (float)($row['cash_difference'] ?? 0);
    }

    $stmt = $this->pdo->prepare('INSERT INTO report_summaries (id, report_type, period_start, period_end, report_count, total_sales, cash_amount, momo_amount, expenses, total_collected, final_balance, cash_difference, generated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
      self::generateId(),
      $type,
      $startDate,
      $endDate,
      count($rows),
      $totals['total_sales'],
      $totals['cash_amount'],
      $totals['momo_amount'],
      $totals['expenses'],
      $totals['total_collected'],
      $totals['final_balance'],
      $totals['cash_difference'],
    ]);
  }

  public function getReportSummaries(string $type): array {
    return $this->fetchAll('SELECT * FROM report_summaries WHERE report_type = ? ORDER BY period_end DESC, generated_at DESC', [$type]);
  }

  private function migrateJsonIfNeeded(): void {
    // Legacy JSON import is no longer used. Import schema.sql instead.
  }

  private function loadCache(): void {
    $this->rawData = [
      'users' => $this->fetchAll('SELECT * FROM users ORDER BY created_at ASC'),
      'employees' => $this->fetchAll('SELECT * FROM employees ORDER BY created_at ASC'),
      'categories' => $this->fetchAll('SELECT * FROM categories ORDER BY created_at ASC'),
      'products' => $this->fetchAll('SELECT * FROM products ORDER BY created_at ASC'),
      'suppliers' => $this->fetchAll('SELECT * FROM suppliers ORDER BY created_at ASC'),
      'purchases' => $this->fetchAll('SELECT * FROM purchases ORDER BY created_at ASC'),
      'stock_movements' => $this->fetchAll('SELECT * FROM stock_movements ORDER BY created_at ASC'),
      'sales' => $this->fetchAll('SELECT * FROM sales ORDER BY created_at ASC'),
      'sale_items' => $this->fetchAll('SELECT * FROM sale_items ORDER BY created_at ASC'),
      'debts' => $this->fetchAll('SELECT * FROM debts ORDER BY created_at ASC'),
      'debt_payments' => $this->fetchAll('SELECT * FROM debt_payments ORDER BY created_at ASC'),
      'expenses' => $this->fetchAll('SELECT * FROM expenses ORDER BY created_at ASC'),
      'daily_stock' => $this->fetchAll('SELECT * FROM daily_stock ORDER BY created_at ASC'),
      'notifications' => $this->fetchAll('SELECT * FROM notifications ORDER BY created_at DESC'),
      'activity_logs' => $this->fetchAll('SELECT * FROM activity_logs ORDER BY created_at DESC'),
      'settings' => $this->getSettingsArray(),
      'daily_reconciliations' => $this->fetchAll('SELECT * FROM daily_reconciliations ORDER BY created_at DESC'),
    ];
  }

  private function fetchAll(string $sql, array $params = []): array {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  private function getSettingsArray(): array {
    $row = $this->pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return [];
    }
    return [
      'business_name' => $row['business_name'] ?? 'TEQUILA BAR & RESTAURANT',
      'address' => $row['address'] ?? 'Base, Rulindo, Rwanda',
      'phone' => $row['phone'] ?? '0783063787',
      'currency' => $row['currency'] ?? 'RWF',
      'receipt_footer' => $row['receipt_footer'] ?? 'Thank you for your business! Visit again.',
      'timezone' => $row['timezone'] ?? 'Africa/Kigali',
      'low_stock_alert_enabled' => (bool)$row['low_stock_alert_enabled'],
      'tax_rate' => (float)$row['tax_rate'],
    ];
  }

  private function refreshCache(): void {
    $this->loadCache();
  }

  public static function generateId(): string {
    return strtoupper(substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 9));
  }

  public function getRawData(): array {
    return $this->rawData;
  }

  public function &getRawDataRef(): array {
    return $this->rawData;
  }

  public function resetToDefault(): void {
    $this->pdo->exec('DELETE FROM activity_logs');
    $this->pdo->exec('DELETE FROM notifications');
    $this->pdo->exec('DELETE FROM report_summaries');
    $this->pdo->exec('DELETE FROM daily_reconciliations');
    $this->pdo->exec('DELETE FROM cash_reconciliation');
    $this->pdo->exec('DELETE FROM stock_report_items');
    $this->pdo->exec('DELETE FROM stock_reports');
    $this->pdo->exec('DELETE FROM daily_stock');
    $this->pdo->exec('DELETE FROM sales');
    $this->pdo->exec('DELETE FROM sale_items');
    $this->pdo->exec('DELETE FROM expenses');
    $this->pdo->exec('DELETE FROM debt_payments');
    $this->pdo->exec('DELETE FROM debts');
    $this->pdo->exec('DELETE FROM purchases');
    $this->pdo->exec('DELETE FROM stock_movements');
    $this->pdo->exec('DELETE FROM products');
    $this->pdo->exec('DELETE FROM employees');
    $this->pdo->exec('DELETE FROM users');
    $this->pdo->exec('DELETE FROM categories');
    $this->pdo->exec('DELETE FROM suppliers');
    $this->pdo->exec('DELETE FROM settings');
    $this->bootstrapData();
    $this->refreshCache();
  }

  public function restoreFromBackup(array $backupData): void {
    $this->pdo->beginTransaction();
    try {
      $this->pdo->exec('DELETE FROM activity_logs');
      $this->pdo->exec('DELETE FROM notifications');
      $this->pdo->exec('DELETE FROM report_summaries');
      $this->pdo->exec('DELETE FROM daily_reconciliations');
      $this->pdo->exec('DELETE FROM cash_reconciliation');
      $this->pdo->exec('DELETE FROM stock_report_items');
      $this->pdo->exec('DELETE FROM stock_reports');
      $this->pdo->exec('DELETE FROM daily_stock');
      $this->pdo->exec('DELETE FROM sales');
      $this->pdo->exec('DELETE FROM sale_items');
      $this->pdo->exec('DELETE FROM expenses');
      $this->pdo->exec('DELETE FROM debt_payments');
      $this->pdo->exec('DELETE FROM debts');
      $this->pdo->exec('DELETE FROM purchases');
      $this->pdo->exec('DELETE FROM stock_movements');
      $this->pdo->exec('DELETE FROM products');
      $this->pdo->exec('DELETE FROM employees');
      $this->pdo->exec('DELETE FROM users');
      $this->pdo->exec('DELETE FROM categories');
      $this->pdo->exec('DELETE FROM suppliers');
      $this->pdo->exec('DELETE FROM settings');
      $this->importJsonData($backupData);
      $this->pdo->commit();
      $this->refreshCache();
    } catch (Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  public function authenticate(string $username, string $password): ?array {
    $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ? AND status = "active" LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;
    if (!password_verify($password, $user['password_hash'])) return null;
    $employeeStmt = $this->pdo->prepare('SELECT * FROM employees WHERE user_id = ? LIMIT 1');
    $employeeStmt->execute([$user['id']]);
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
    return ['user' => $user, 'employee' => $employee ?: null];
  }

  public function logActivity(string $userId, string $userName, string $role, string $action, string $details): void {
    $stmt = $this->pdo->prepare('INSERT INTO activity_logs (id, user_id, user_name, role, action, details, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([self::generateId(), $userId, $userName, $role, $action, $details]);
    $this->refreshCache();
  }

  public function createNotification(string $title, string $message, string $type): void {
    $stmt = $this->pdo->prepare('INSERT INTO notifications (id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([self::generateId(), $title, $message, $type, 0]);
    $this->refreshCache();
  }

  public function getCategories(): array {
    return $this->fetchAll('SELECT * FROM categories ORDER BY created_at ASC');
  }

  public function createCategory(string $name, string $description, array $user): array {
    $id = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO categories (id, name, description, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$id, $name, $description]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'CREATE_CATEGORY', 'Created category: ' . $name);
    $this->refreshCache();
    return ['id' => $id, 'name' => $name, 'description' => $description, 'created_at' => date('Y-m-d H:i:s')];
  }

  public function getProducts(): array {
    return $this->fetchAll('SELECT * FROM products ORDER BY created_at ASC');
  }

  public function createProduct(string $name, string $code, string $barcode, string $category_id, string $supplier_id, float $price, float $cost, string $unit, int $minimum_stock, int $initial_stock, string $image, array $user): array {
    $id = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO products (id, name, code, barcode, category_id, supplier_id, price, cost, unit, minimum_stock, current_stock, image, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$id, $name, $code, $barcode, $category_id, $supplier_id, $price, $cost, $unit, $minimum_stock, $initial_stock, $image, 'active']);
    $this->adjustStock($id, $initial_stock, 'initial', 'Initial stock setup', 'initial', 'SYSTEM', $user['name']);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'CREATE_PRODUCT', 'Created product: ' . $name);
    $this->refreshCache();
    return ['id' => $id, 'name' => $name, 'code' => $code, 'barcode' => $barcode, 'category_id' => $category_id, 'supplier_id' => $supplier_id, 'price' => $price, 'cost' => $cost, 'unit' => $unit, 'minimum_stock' => $minimum_stock, 'current_stock' => $initial_stock, 'image' => $image, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')];
  }

  public function updateProduct(string $id, string $name, string $code, string $barcode, string $category_id, string $supplier_id, float $price, float $cost, string $unit, int $minimum_stock, string $image, array $user): array {
    $stmt = $this->pdo->prepare('UPDATE products SET name = ?, code = ?, barcode = ?, category_id = ?, supplier_id = ?, price = ?, cost = ?, unit = ?, minimum_stock = ?, image = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$name, $code, $barcode, $category_id, $supplier_id, $price, $cost, $unit, $minimum_stock, $image, $id]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'UPDATE_PRODUCT', 'Updated product: ' . $name);
    $this->refreshCache();
    return ['id' => $id, 'name' => $name, 'code' => $code, 'barcode' => $barcode, 'category_id' => $category_id, 'supplier_id' => $supplier_id, 'price' => $price, 'cost' => $cost, 'unit' => $unit, 'minimum_stock' => $minimum_stock, 'image' => $image];
  }

  public function deleteProduct(string $id, array $user): void {
    $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'DELETE_PRODUCT', 'Deleted product: ' . $id);
    $this->refreshCache();
  }

  public function adjustStock(string $product_id, int $quantity, string $type, string $notes, string $ref_type, string $ref_id, string $created_by_name): array {
    $productStmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $productStmt->execute([$product_id]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new Exception('Product not found');
    $previousStock = (int)$product['current_stock'];
    $newStock = $previousStock + $quantity;
    $stmt = $this->pdo->prepare('UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$newStock, $product_id]);
    $movementId = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO stock_movements (id, product_id, quantity, previous_stock, new_stock, movement_type, reference_type, reference_id, notes, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$movementId, $product_id, $quantity, $previousStock, $newStock, $type, $ref_type, $ref_id, $notes, $created_by_name]);
    $this->refreshCache();
    return ['id' => $movementId, 'product_id' => $product_id, 'quantity' => $quantity, 'previous_stock' => $previousStock, 'new_stock' => $newStock];
  }

  public function getStockMovements(): array {
    return $this->fetchAll('SELECT * FROM stock_movements ORDER BY created_at DESC');
  }

  public function processSale(array $items, string $payment_type, string $customer_name, string $customer_phone, float $amount_paid, array $user): array {
    $saleId = self::generateId();
    $total = 0.0;
    foreach ($items as $item) {
      $total += (float)($item['total_amount'] ?? 0);
    }
    $stmt = $this->pdo->prepare('INSERT INTO sales (id, payment_type, customer_name, customer_phone, amount_paid, total_amount, employee_id, employee_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$saleId, $payment_type, $customer_name, $customer_phone, $amount_paid, $total, $user['id'], $user['name']]);
    foreach ($items as $item) {
      $itemId = self::generateId();
      $stmt = $this->pdo->prepare('INSERT INTO sale_items (id, sale_id, product_id, product_name, quantity, unit_price, total_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
      $stmt->execute([$itemId, $saleId, $item['product_id'], $item['product_name'] ?? '', $item['quantity'] ?? 0, $item['unit_price'] ?? 0, $item['total_amount'] ?? 0]);
    }
    $debtBalance = max(0.0, $total - $amount_paid);
    if ($debtBalance > 0) {
      $debtId = self::generateId();
      $stmt = $this->pdo->prepare('INSERT INTO debts (id, sale_id, customer_name, customer_phone, total_amount, amount_paid, balance, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
      $stmt->execute([$debtId, $saleId, $customer_name, $customer_phone, $total, $amount_paid, $debtBalance, 'open']);
    }
    $this->refreshCache();
    return ['id' => $saleId, 'total_amount' => $total, 'amount_paid' => $amount_paid, 'payment_type' => $payment_type, 'items' => $items];
  }

  public function getDebts(): array {
    return $this->fetchAll('SELECT * FROM debts ORDER BY created_at DESC');
  }

  public function recordDebtPayment(string $debt_id, float $amount, string $payment_method, array $user): array {
    $id = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO debt_payments (id, debt_id, amount, payment_method, created_by_user_id, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$id, $debt_id, $amount, $payment_method, $user['id'], $user['name']]);
    $stmt = $this->pdo->prepare('SELECT * FROM debts WHERE id = ? LIMIT 1');
    $stmt->execute([$debt_id]);
    $debt = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($debt) {
      $newBalance = max(0.0, (float)$debt['balance'] - $amount);
      $stmt = $this->pdo->prepare('UPDATE debts SET amount_paid = amount_paid + ?, balance = ?, status = ? WHERE id = ?');
      $stmt->execute([$amount, $newBalance, $newBalance > 0 ? 'open' : 'paid', $debt_id]);
    }
    $this->refreshCache();
    return ['id' => $id, 'debt_id' => $debt_id, 'amount' => $amount, 'payment_method' => $payment_method];
  }

  public function getExpenses(): array {
    return $this->fetchAll('SELECT * FROM expenses ORDER BY created_at DESC');
  }

  public function createExpense(string $title, string $category, float $amount, string $description, string $date, array $user): array {
    $id = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO expenses (id, title, category, amount, description, date, created_by_user_id, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$id, $title, $category, $amount, $description, $date, $user['id'], $user['name']]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'CREATE_EXPENSE', 'Created expense: ' . $title);
    $this->refreshCache();
    return ['id' => $id, 'title' => $title, 'category' => $category, 'amount' => $amount, 'description' => $description, 'date' => $date, 'created_by_user_id' => $user['id'], 'created_by_name' => $user['name']];
  }

  public function getSuppliers(): array {
    return $this->fetchAll('SELECT * FROM suppliers ORDER BY created_at ASC');
  }

  public function createSupplier(string $name, string $contact, string $phone, string $email, array $user): array {
    $id = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO suppliers (id, name, contact, phone, email, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$id, $name, $contact, $phone, $email]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'CREATE_SUPPLIER', 'Created supplier: ' . $name);
    $this->refreshCache();
    return ['id' => $id, 'name' => $name, 'contact' => $contact, 'phone' => $phone, 'email' => $email];
  }

  public function updateSupplier(string $id, string $name, string $contact, string $phone, string $email, array $user): array {
    $stmt = $this->pdo->prepare('UPDATE suppliers SET name = ?, contact = ?, phone = ?, email = ? WHERE id = ?');
    $stmt->execute([$name, $contact, $phone, $email, $id]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'UPDATE_SUPPLIER', 'Updated supplier: ' . $name);
    $this->refreshCache();
    return ['id' => $id, 'name' => $name, 'contact' => $contact, 'phone' => $phone, 'email' => $email];
  }

  public function deleteSupplier(string $id, array $user): void {
    $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
    $stmt->execute([$id]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'DELETE_SUPPLIER', 'Deleted supplier: ' . $id);
    $this->refreshCache();
  }

  public function getPurchases(): array {
    return $this->fetchAll('SELECT * FROM purchases ORDER BY created_at DESC');
  }

  public function createPurchase(string $supplier_id, string $product_id, float $quantity, float $purchase_price, string $date, array $user): array {
    $id = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO purchases (id, supplier_id, product_id, quantity, purchase_price, date, created_by_user_id, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$id, $supplier_id, $product_id, $quantity, $purchase_price, $date, $user['id'], $user['name']]);
    $this->adjustStock($product_id, (int)$quantity, 'purchase', 'Purchase received', 'purchase', $id, $user['name']);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'CREATE_PURCHASE', 'Created purchase for product: ' . $product_id);
    $this->refreshCache();
    return ['id' => $id, 'supplier_id' => $supplier_id, 'product_id' => $product_id, 'quantity' => $quantity, 'purchase_price' => $purchase_price, 'date' => $date];
  }

  public function getDailyReconciliations(): array {
    return $this->fetchAll('SELECT * FROM daily_reconciliations ORDER BY created_at DESC');
  }

  public function resetUserPassword(string $userId, string $newPassword, array $actor): array {
    $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    $this->logActivity($actor['id'], $actor['name'], $actor['role'], 'RESET_USER_PASSWORD', 'Reset password for user: ' . $userId);
    $this->refreshCache();
    return ['id' => $userId];
  }

  public function changePassword(string $userId, string $currentPassword, string $newPassword): bool {
    $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
      throw new Exception('Current password is incorrect');
    }
    $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    $this->refreshCache();
    return true;
  }

  public function updateUserProfile(string $userId, string $name, string $email, string $phone): array {
    $stmt = $this->pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
    $stmt->execute([$name, $userId]);
    $stmt = $this->pdo->prepare('UPDATE employees SET name = ?, email = ?, phone = ? WHERE user_id = ?');
    $stmt->execute([$name, $email, $phone, $userId]);
    $this->refreshCache();
    return ['user' => ['id' => $userId, 'name' => $name], 'employee' => ['id' => $userId, 'name' => $name, 'email' => $email, 'phone' => $phone]];
  }

  private function validateReportDate(string $dateStr, array $user): void {
    $date = new DateTime($dateStr);
    $today = (new DateTime('today'))->setTime(0, 0, 0);
    $monthStart = (new DateTime('first day of this month'))->setTime(0, 0, 0);
    if (($user['role'] ?? '') !== 'admin' && $date > $today) throw new Exception('Employees cannot submit reports for future dates.');
    if (($user['role'] ?? '') !== 'admin' && $date < $monthStart) throw new Exception('Employees can only submit reports for the current month.');
  }

  public function submitDailyReport(string $product_id, string $dateStr, int $opening, int $stock_in, int $remaining, array $user): array {
    $this->validateReportDate($dateStr, $user);
    $dateOnly = explode('T', $dateStr)[0];
    $id = self::generateId();
    $productStmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $productStmt->execute([$product_id]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new Exception('Product not found');
    $sold = $opening + $stock_in - $remaining;
    if ($sold < 0) throw new Exception('Invalid stock values');
    $stmt = $this->pdo->prepare('INSERT INTO daily_stock (id, product_id, product_name, date, opening_stock, stock_in, remaining_stock, sold_stock, employee_id, employee_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$id, $product_id, $product['name'] ?? '', $dateOnly, $opening, $stock_in, $remaining, $sold, $user['id'], $user['name']]);
    $this->refreshCache();
    return ['id' => $id, 'product_id' => $product_id, 'date' => $dateOnly, 'opening_stock' => $opening, 'stock_in' => $stock_in, 'remaining_stock' => $remaining, 'sold_stock' => $sold, 'employee_id' => $user['id'], 'employee_name' => $user['name']];
  }

  public function submitDailyReconciliation(string $dateStr, float $total_sales, float $cash_amount, float $momo_amount, float $expenses, array $expense_items = [], string $comment = '', array $user, ?float $cash_difference = null): array {
    $this->validateReportDate($dateStr, $user);
    $dateOnly = explode('T', $dateStr)[0];
    $id = self::generateId();
    $totalCollected = $cash_amount + $momo_amount;
    $finalBalance = $totalCollected - $expenses;
    $cashDifferenceValue = $cash_difference ?? ($totalCollected - $total_sales);
    $stmt = $this->pdo->prepare('INSERT INTO daily_reconciliations (id, date, total_sales, cash_amount, momo_amount, bank_amount, expenses, expense_items, total_collected, final_balance, cash_difference, comment, employee_id, employee_name, status, approved_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$id, $dateOnly, $total_sales, $cash_amount, $momo_amount, 0, $expenses, json_encode($expense_items), $totalCollected, $finalBalance, $cashDifferenceValue, $comment, $user['id'], $user['name'], 'Submitted', '']);
    $this->generateAutomaticReportSummaries();
    $this->refreshCache();
    return ['id' => $id, 'date' => $dateOnly, 'total_sales' => $total_sales, 'cash_amount' => $cash_amount, 'momo_amount' => $momo_amount, 'expenses' => $expenses, 'expense_items' => $expense_items, 'total_collected' => $totalCollected, 'final_balance' => $finalBalance, 'cash_difference' => $cashDifferenceValue, 'comment' => $comment, 'employee_id' => $user['id'], 'employee_name' => $user['name'], 'status' => 'Submitted'];
  }

  public function submitDailyReportBundle(string $dateStr, array $rows, array $reconciliation, array $user): array {
    $this->validateReportDate($dateStr, $user);
    $dateOnly = explode('T', $dateStr)[0];
    $this->pdo->beginTransaction();
    try {
      $reportId = self::generateId();
      $stmt = $this->pdo->prepare('INSERT INTO stock_reports (id, date, employee_id, employee_name, created_at) VALUES (?, ?, ?, ?, NOW())');
      $stmt->execute([$reportId, $dateOnly, $user['id'], $user['name']]);
      foreach ($rows as $row) {
        $itemId = self::generateId();
        $productId = (string)($row['product_id'] ?? '');
        $opening = (int)($row['opening_stock'] ?? 0);
        $stockIn = (int)($row['stock_in'] ?? 0);
        $remaining = (int)($row['remaining_stock'] ?? 0);
        $sold = max(0, $opening + $stockIn - $remaining);
        $stmt = $this->pdo->prepare('INSERT INTO stock_report_items (id, stock_report_id, product_id, product_name, opening_stock, stock_in, remaining_stock, sold_stock, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$itemId, $reportId, $productId, (string)($row['product_name'] ?? ''), $opening, $stockIn, $remaining, $sold]);
        $this->pdo->prepare('INSERT INTO daily_stock (id, product_id, product_name, date, opening_stock, stock_in, remaining_stock, sold_stock, employee_id, employee_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([self::generateId(), $productId, (string)($row['product_name'] ?? ''), $dateOnly, $opening, $stockIn, $remaining, $sold, $user['id'], $user['name']]);
      }
      $reconId = self::generateId();
      $totalSales = (float)($reconciliation['total_sales'] ?? 0);
      $cashAmount = (float)($reconciliation['cash_amount'] ?? 0);
      $momoAmount = (float)($reconciliation['momo_amount'] ?? 0);
      $expenses = (float)($reconciliation['expenses'] ?? 0);
      $expenseItems = (array)($reconciliation['expense_items'] ?? []);
      $totalCollected = $cashAmount + $momoAmount;
      $finalBalance = $totalCollected - $expenses;
      $cashDifference = (float)($reconciliation['cash_difference'] ?? ($totalCollected - $totalSales));
      $stmt = $this->pdo->prepare('INSERT INTO cash_reconciliation (id, stock_report_id, date, total_sales, cash_amount, momo_amount, bank_amount, expenses, expense_items, total_collected, final_balance, cash_difference, comment, employee_id, employee_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
      $stmt->execute([$reconId, $reportId, $dateOnly, $totalSales, $cashAmount, $momoAmount, 0, $expenses, json_encode($expenseItems), $totalCollected, $finalBalance, $cashDifference, (string)($reconciliation['comment'] ?? ''), $user['id'], $user['name']]);
      $dailyReconId = self::generateId();
      $stmt = $this->pdo->prepare('INSERT INTO daily_reconciliations (id, date, total_sales, cash_amount, momo_amount, bank_amount, expenses, expense_items, total_collected, final_balance, cash_difference, comment, employee_id, employee_name, status, approved_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
      $stmt->execute([$dailyReconId, $dateOnly, $totalSales, $cashAmount, $momoAmount, 0, $expenses, json_encode($expenseItems), $totalCollected, $finalBalance, $cashDifference, (string)($reconciliation['comment'] ?? ''), $user['id'], $user['name'], 'Submitted', '']);
      $this->pdo->commit();
      $this->generateAutomaticReportSummaries();
      $this->refreshCache();
      return ['reports' => $rows, 'reconciliation' => ['id' => $dailyReconId, 'date' => $dateOnly, 'total_sales' => $totalSales, 'cash_amount' => $cashAmount, 'momo_amount' => $momoAmount, 'expenses' => $expenses, 'expense_items' => $expenseItems, 'total_collected' => $totalCollected, 'final_balance' => $finalBalance, 'cash_difference' => $cashDifference, 'comment' => (string)($reconciliation['comment'] ?? ''), 'employee_id' => $user['id'], 'employee_name' => $user['name']]];
    } catch (Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  public function getDailyReconciliation(string $dateStr): ?array {
    $stmt = $this->pdo->prepare('SELECT * FROM daily_reconciliations WHERE date = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([explode('T', $dateStr)[0]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  private function normalizeDate(string $dateStr): ?DateTime {
    try { $dt = new DateTime($dateStr); $dt->setTime(0,0,0); return $dt; } catch (Throwable $e) { return null; }
  }

  private function getDailySalesCount(string $dateStr): int {
    $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE DATE(s.created_at) = ?');
    $stmt->execute([$dateStr]);
    return (int)$stmt->fetchColumn();
  }

  public function getFilteredDailyReconciliations(?string $startDate = null, ?string $endDate = null, ?string $employeeId = null, ?string $search = null, ?string $cashDifferenceFilter = null, ?string $status = null): array {
    $sql = 'SELECT * FROM daily_reconciliations WHERE 1=1';
    $params = [];
    if ($employeeId) { $sql .= ' AND employee_id = ?'; $params[] = $employeeId; }
    if ($startDate) { $sql .= ' AND date >= ?'; $params[] = $startDate; }
    if ($endDate) { $sql .= ' AND date <= ?'; $params[] = $endDate; }
    if ($status) { $sql .= ' AND status = ?'; $params[] = $status; }
    if ($search) { $sql .= ' AND (id LIKE ? OR employee_name LIKE ? OR comment LIKE ? OR date LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
    $sql .= ' ORDER BY date DESC, created_at DESC';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$report) {
      $report['status'] = $report['status'] ?? 'Submitted';
      $report['total_products_sold'] = $this->getDailySalesCount($report['date']);
      $report['total_collected'] = (float)$report['total_collected'];
      $report['final_balance'] = (float)$report['final_balance'];
      if ($cashDifferenceFilter) {
        $diff = (float)$report['cash_difference'];
        if ($cashDifferenceFilter === 'positive' && $diff <= 0) { $report = null; }
        if ($cashDifferenceFilter === 'negative' && $diff >= 0) { $report = null; }
        if ($cashDifferenceFilter === 'balanced' && $diff !== 0) { $report = null; }
      }
    }
    unset($report);
    return array_values(array_filter($rows));
  }

  public function getReportMetrics(array $reports): array {
    $today = (new DateTime())->setTime(0,0,0);
    $weekStart = (clone $today)->modify('monday this week');
    $monthStart = (clone $today)->modify('first day of this month');
    $yearStart = (clone $today)->modify('first day of january this year');
    $metrics = ['daily_sales' => 0.0, 'weekly_sales' => 0.0, 'monthly_sales' => 0.0, 'yearly_sales' => 0.0, 'total_expenses' => 0.0, 'total_profit' => 0.0, 'total_reports' => count($reports)];
    foreach ($reports as $report) {
      $reportDate = $this->normalizeDate($report['date'] ?? '');
      if (!$reportDate) continue;
      $totalSales = (float)($report['total_sales'] ?? 0);
      $expenses = (float)($report['expenses'] ?? 0);
      $metrics['total_expenses'] += $expenses;
      $metrics['total_profit'] += $totalSales - $expenses;
      if ($reportDate == $today) $metrics['daily_sales'] += $totalSales;
      if ($reportDate >= $weekStart) $metrics['weekly_sales'] += $totalSales;
      if ($reportDate >= $monthStart) $metrics['monthly_sales'] += $totalSales;
      if ($reportDate >= $yearStart) $metrics['yearly_sales'] += $totalSales;
    }
    return $metrics;
  }

  public function getDailyReconciliationById(string $id): ?array {
    $stmt = $this->pdo->prepare('SELECT * FROM daily_reconciliations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  public function updateDailyReconciliation(string $id, array $updates, array $user): array {
    $stmt = $this->pdo->prepare('SELECT * FROM daily_reconciliations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) throw new Exception('Report not found');
    $status = (string)($updates['status'] ?? $report['status']);
    $comment = (string)($updates['comment'] ?? $report['comment']);
    $stmt = $this->pdo->prepare('UPDATE daily_reconciliations SET status = ?, comment = ?, approved_by = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $comment, $user['name'], $id]);
    $stmt = $this->pdo->prepare('INSERT INTO report_status (id, report_id, status, comment, updated_by_user_id, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([self::generateId(), $id, $status, $comment, $user['id']]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'UPDATE_REPORT', 'Updated daily report: ' . $id);
    $this->refreshCache();
    return ['id' => $id, 'status' => $status, 'comment' => $comment];
  }

  public function getSalesTotalsForRange(string $startDateStr, string $endDateStr): array {
    $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) AS sales, COALESCE(SUM((SELECT SUM(amount) FROM expenses WHERE date = daily_reconciliations.date)), 0) AS expenses FROM daily_reconciliations WHERE date BETWEEN ? AND ?');
    $stmt->execute([$startDateStr, $endDateStr]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ['sales' => (float)($row['sales'] ?? 0), 'expenses' => (float)($row['expenses'] ?? 0), 'profit' => (float)($row['sales'] ?? 0) - (float)($row['expenses'] ?? 0)];
  }

  public function getCarryoverOpeningStock(string $product_id, string $targetDateStr): int {
    $stmt = $this->pdo->prepare('SELECT remaining_stock FROM daily_stock WHERE product_id = ? AND date < ? ORDER BY date DESC LIMIT 1');
    $stmt->execute([$product_id, $targetDateStr]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['remaining_stock'];
    $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$product_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['current_stock'] : 0;
  }

  public function getAggregatedStockReport(string $startDateStr, string $endDateStr): array {
    $stmt = $this->pdo->prepare('SELECT * FROM daily_reconciliations WHERE date BETWEEN ? AND ? ORDER BY date ASC');
    $stmt->execute([$startDateStr, $endDateStr]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public function getEmployeesAndUsers(): array {
    $stmt = $this->pdo->query('SELECT e.*, u.username, u.role FROM employees e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.created_at ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public function createEmployee(string $name, string $email, string $phone, float $salary, string $username, array $user): array {
    $userId = self::generateId();
    $employeeId = self::generateId();
    $stmt = $this->pdo->prepare('INSERT INTO users (id, username, name, role, password_hash, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $username, $name . ' (Staff)', 'employee', password_hash($username . '123', PASSWORD_DEFAULT), 'active']);
    $stmt = $this->pdo->prepare('INSERT INTO employees (id, user_id, name, email, phone, salary, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$employeeId, $userId, $name, $email, $phone, $salary, 'active']);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'CREATE_EMPLOYEE', 'Created employee ' . $name);
    $this->refreshCache();
    return ['user' => ['id' => $userId, 'username' => $username, 'name' => $name . ' (Staff)', 'role' => 'employee', 'status' => 'active'], 'employee' => ['id' => $employeeId, 'user_id' => $userId, 'name' => $name, 'email' => $email, 'phone' => $phone, 'salary' => $salary, 'status' => 'active']];
  }

  public function updateEmployee(string $id, string $name, string $email, string $phone, float $salary, string $status, array $user): array {
    $stmt = $this->pdo->prepare('UPDATE employees SET name = ?, email = ?, phone = ?, salary = ?, status = ? WHERE id = ?');
    $stmt->execute([$name, $email, $phone, $salary, $status, $id]);
    $stmt = $this->pdo->prepare('SELECT user_id FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($emp) {
      $stmt = $this->pdo->prepare('UPDATE users SET status = ?, name = ? WHERE id = ?');
      $stmt->execute([$status, $name . ' (Staff)', $emp['user_id']]);
    }
    $this->logActivity($user['id'], $user['name'], $user['role'], 'UPDATE_EMPLOYEE', 'Updated employee: ' . $name);
    $this->refreshCache();
    return ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'salary' => $salary, 'status' => $status];
  }

  public function updateSettings(array $settings, array $user): array {
    $stmt = $this->pdo->prepare('UPDATE settings SET business_name = ?, address = ?, phone = ?, currency = ?, receipt_footer = ?, timezone = ?, low_stock_alert_enabled = ?, tax_rate = ?, updated_at = NOW() WHERE id = 1');
    $stmt->execute([
      (string)($settings['business_name'] ?? 'TEQUILA BAR & RESTAURANT'),
      (string)($settings['address'] ?? 'Base, Rulindo, Rwanda'),
      (string)($settings['phone'] ?? '0783063787'),
      (string)($settings['currency'] ?? 'RWF'),
      (string)($settings['receipt_footer'] ?? 'Thank you for your business! Visit again.'),
      (string)($settings['timezone'] ?? 'Africa/Kigali'),
      (int)((bool)($settings['low_stock_alert_enabled'] ?? true)),
      (float)($settings['tax_rate'] ?? 0)
    ]);
    $this->logActivity($user['id'], $user['name'], $user['role'], 'UPDATE_SETTINGS', 'Updated system settings');
    $this->refreshCache();
    return $this->getSettingsArray();
  }
}
