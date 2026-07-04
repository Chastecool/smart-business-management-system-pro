<?php
declare(strict_types=1);

require_once __DIR__ . '/DBManager.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, x-user-id, x-user-role, x-user-name');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function send_json($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) return [];
  return $decoded;
}

function get_header(string $name): ?string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : null;
}

$db = null;
try {
  $db = new DBManager();
} catch (DatabaseSetupException $e) {
  send_json(['error' => $e->getMessage(), 'setup_required' => true], 503);
  exit;
}

// Auth middleware equivalents
function requireAuth(DBManager $db): array {
  $userId = get_header('x-user-id');
  $userRole = get_header('x-user-role');
  $userName = get_header('x-user-name');

  // allow either explicit headers or a PHP session cookie
  if ((!$userId || !$userRole)) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sessUser = $_SESSION['user'] ?? null;
    if ($sessUser && isset($sessUser['id']) && isset($sessUser['role'])) {
      // use session user
      return $sessUser;
    }
    send_json(['error' => 'Unauthorized: Session missing'], 401);
    exit;
  }

  $raw = $db->getRawData();
  $user = null;
  foreach ($raw['users'] ?? [] as $u) {
    if (($u['id'] ?? null) === $userId && (($u['status'] ?? '') === 'active')) {
      $user = $u;
      break;
    }
  }

  if (!$user) {
    send_json(['error' => 'Unauthorized: Invalid user session'], 401);
    exit;
  }

  return $user;
}

function requireAdmin(DBManager $db): array {
  $user = requireAuth($db);
  if (($user['role'] ?? '') !== 'admin') {
    send_json(['error' => 'Forbidden: Admin access required'], 403);
    exit;
  }
  return $user;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Normalize: remove trailing slash except root
if ($uri !== '/' && str_ends_with($uri, '/')) $uri = rtrim($uri, '/');

// Health Check
if ($method === 'GET' && $uri === '/api/health') {
  send_json(['status' => 'ok', 'serverTime' => (new DateTime())->format(DateTime::ATOM)]);
  exit;
}

// Auth login
if ($method === 'POST' && $uri === '/api/auth/login') {
  $body = read_json_body();
  $username = $body['username'] ?? null;
  $password = $body['password'] ?? null;

  if (!$username || !$password) {
    send_json(['error' => 'Username and password are required'], 400);
    exit;
  }

  $result = $db->authenticate((string)$username, (string)$password);
  if (!$result) {
    $db->logActivity('GUEST', (string)$username, 'guest', 'LOGIN_FAILED', 'Failed login attempt for username: ' . (string)$username);
    send_json(['error' => 'Invalid username or password'], 400);
    exit;
  }

  $user = $result['user'];
  $employee = $result['employee'] ?? null;
  $db->logActivity($user['id'], $user['name'], $user['role'], 'LOGIN_SUCCESS', 'User ' . ($user['username'] ?? '') . ' logged in successfully');

  send_json(['success' => true, 'user' => $user, 'employee' => $employee]);
  exit;
}

// Categories
if ($method === 'GET' && $uri === '/api/categories') {
  requireAuth($db);
  send_json($db->getCategories());
  exit;
}

if ($method === 'POST' && $uri === '/api/categories') {
  $user = requireAdmin($db);
  $body = read_json_body();
  $name = $body['name'] ?? null;
  $description = $body['description'] ?? '';
  if (!$name) {
    send_json(['error' => 'Category name is required'], 400);
    exit;
  }
  try {
    $cat = $db->createCategory((string)$name, (string)$description, $user);
    send_json($cat, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Products collection
if ($method === 'GET' && $uri === '/api/products') {
  requireAuth($db);
  send_json($db->getProducts());
  exit;
}

if ($method === 'POST' && $uri === '/api/products') {
  $user = requireAdmin($db);
  $body = read_json_body();
  $name = $body['name'] ?? null;
  $code = $body['code'] ?? null;
  $barcode = $body['barcode'] ?? '';
  $category_id = $body['category_id'] ?? null;
  $supplier_id = $body['supplier_id'] ?? '';
  $price = $body['price'] ?? null;
  $cost = $body['cost'] ?? null;
  $image = $body['image'] ?? '';

  if (!$name || !$code || !$category_id || $price === null || $cost === null) {
    send_json(['error' => 'Missing required product fields'], 400);
    exit;
  }

  try {
    $prod = $db->createProduct(
      (string)$name,
      (string)$code,
      (string)$barcode,
      (string)$category_id,
      (string)$supplier_id,
      (float)$price,
      (float)$cost,
      (string)($body['unit'] ?? 'Item'),
      (int)($body['minimum_stock'] ?? 0),
      (int)($body['initial_stock'] ?? 0),
      (string)$image,
      $user
    );
    send_json($prod, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Products by id
if ($method === 'PUT' && preg_match('#^/api/products/([^/]+)$#', $uri, $m)) {
  $user = requireAdmin($db);
  $id = $m[1];
  $body = read_json_body();

  $name = $body['name'] ?? null;
  $code = $body['code'] ?? null;
  $barcode = $body['barcode'] ?? '';
  $category_id = $body['category_id'] ?? null;
  $supplier_id = $body['supplier_id'] ?? '';
  $price = $body['price'] ?? null;
  $cost = $body['cost'] ?? null;
  $image = $body['image'] ?? '';

  try {
    $prod = $db->updateProduct(
      (string)$id,
      (string)($name ?? ''),
      (string)($code ?? ''),
      (string)($barcode ?? ''),
      (string)($category_id ?? ''),
      (string)$supplier_id,
      (float)($price ?? 0),
      (float)($cost ?? 0),
      (string)($body['unit'] ?? 'Item'),
      (int)($body['minimum_stock'] ?? 0),
      (string)$image,
      $user
    );
    send_json($prod);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

if ($method === 'DELETE' && preg_match('#^/api/products/([^/]+)$#', $uri, $m)) {
  $user = requireAdmin($db);
  $id = $m[1];
  try {
    $db->deleteProduct((string)$id, $user);
    send_json(['success' => true]);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Manual Stock Adjustments
if ($method === 'POST' && $uri === '/api/products/adjust') {
  $user = requireAdmin($db);
  $body = read_json_body();
  $product_id = $body['product_id'] ?? null;
  $quantity = $body['quantity'] ?? null;
  $type = $body['type'] ?? null;
  $notes = $body['notes'] ?? null;

  if (!$product_id || $quantity === null || !$type) {
    send_json(['error' => 'Missing product_id, quantity or type'], 400);
    exit;
  }

  try {
    $movement = $db->adjustStock(
      (string)$product_id,
      (int)$quantity,
      (string)$type,
      (string)($notes ?? 'Manual stock adjustment'),
      'manual',
      'ADMIN-MANUAL',
      (string)$user['name']
    );
    $db->logActivity($user['id'], $user['name'], $user['role'], 'STOCK_ADJUSTMENT', 'Manually adjusted stock of product ID ' . $product_id . ' by ' . $quantity);
    send_json($movement);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

if ($method === 'GET' && $uri === '/api/stock/movements') {
  requireAuth($db);
  send_json($db->getStockMovements());
  exit;
}

// Sales & POS
if ($method === 'POST' && $uri === '/api/sales') {
  $user = requireAuth($db);
  $body = read_json_body();
  $items = $body['items'] ?? null;
  $payment_type = $body['payment_type'] ?? null;

  if (!$items || !is_array($items) || count($items) === 0 || !$payment_type) {
    send_json(['error' => 'Invalid checkout request data'], 400);
    exit;
  }

  try {
    $result = $db->processSale(
      $items,
      (string)$payment_type,
      (string)($body['customer_name'] ?? ''),
      (string)($body['customer_phone'] ?? ''),
      (float)($body['amount_paid'] ?? 0),
      $user
    );
    send_json($result, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage() ?: 'Sale processing failed due to stock/product issues'], 500);
  }
  exit;
}

if ($method === 'GET' && $uri === '/api/sales') {
  requireAuth($db);
  $data = $db->getRawData();
  send_json(['sales' => $data['sales'], 'sale_items' => $data['sale_items']]);
  exit;
}

// Debts
if ($method === 'GET' && $uri === '/api/debts') {
  requireAuth($db);
  send_json($db->getDebts());
  exit;
}

if ($method === 'POST' && preg_match('#^/api/debts/([^/]+)/pay$#', $uri, $m)) {
  $user = requireAuth($db);
  $id = $m[1];
  $body = read_json_body();
  $amount = $body['amount'] ?? null;
  $payment_method = $body['payment_method'] ?? null;

  if ($amount === null || !$payment_method) {
    send_json(['error' => 'Amount and payment method are required'], 400);
    exit;
  }

  try {
    $pay = $db->recordDebtPayment((string)$id, (float)$amount, (string)$payment_method, $user);
    send_json($pay, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Expenses
if ($method === 'GET' && $uri === '/api/expenses') {
  requireAuth($db);
  send_json($db->getExpenses());
  exit;
}

if ($method === 'POST' && $uri === '/api/expenses') {
  $user = requireAuth($db);
  $body = read_json_body();
  $title = $body['title'] ?? null;
  $category = $body['category'] ?? null;
  $amount = $body['amount'] ?? null;

  if (!$title || !$category || $amount === null) {
    send_json(['error' => 'Missing required expense fields'], 400);
    exit;
  }

  try {
    $exp = $db->createExpense(
      (string)$title,
      (string)$category,
      (float)$amount,
      (string)($body['description'] ?? ''),
      (string)($body['date'] ?? ''),
      $user
    );
    send_json($exp, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Daily stock
if ($method === 'GET' && $uri === '/api/daily-stock') {
  requireAuth($db);
  send_json($db->getRawData()['daily_stock']);
  exit;
}

if ($method === 'GET' && $uri === '/api/daily-stock/carryover') {
  requireAuth($db);
  $product_id = $_GET['product_id'] ?? null;
  $date = $_GET['date'] ?? null;
  if (!$product_id || !$date) {
    send_json(['error' => 'Missing product_id or date parameters'], 400);
    exit;
  }
  try {
    $carryover = $db->getCarryoverOpeningStock((string)$product_id, (string)$date);
    send_json(['carryover' => $carryover]);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

if ($method === 'POST' && $uri === '/api/daily-stock/submit') {
  $user = requireAuth($db);
  $body = read_json_body();
  $product_id = $body['product_id'] ?? null;
  $date = $body['date'] ?? null;
  $opening_stock = $body['opening_stock'] ?? null;
  $stock_in = $body['stock_in'] ?? null;
  $remaining_stock = $body['remaining_stock'] ?? null;

  if (!$product_id || !$date || $opening_stock === null || $stock_in === null || $remaining_stock === null) {
    send_json(['error' => 'Missing daily stock report details'], 400);
    exit;
  }

  try {
    $report = $db->submitDailyReport(
      (string)$product_id,
      (string)$date,
      (int)$opening_stock,
      (int)$stock_in,
      (int)$remaining_stock,
      $user
    );
    send_json($report, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 400);
  }
  exit;
}

// Daily reconciliation
if ($method === 'GET' && $uri === '/api/daily-reconciliation') {
  requireAuth($db);
  $date = $_GET['date'] ?? null;
  if (!$date) {
    send_json(['error' => 'Missing date parameter'], 400);
    exit;
  }
  try {
    $recon = $db->getDailyReconciliation((string)$date);
    send_json(['reconciliation' => $recon]);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

if ($method === 'POST' && $uri === '/api/daily-reconciliation/submit') {
  $user = requireAuth($db);
  $body = read_json_body();
  $date = $body['date'] ?? null;
  $total_sales = $body['total_sales'] ?? null;
  $cash_amount = $body['cash_amount'] ?? null;
  $momo_amount = $body['momo_amount'] ?? null;
  $expenses = $body['expenses'] ?? null;
  $expense_items = $body['expense_items'] ?? [];

  if (!$date || $total_sales === null || $cash_amount === null || $momo_amount === null || $expenses === null) {
    send_json(['error' => 'Missing cash reconciliation parameters'], 400);
    exit;
  }

  try {
    $recon = $db->submitDailyReconciliation(
      (string)$date,
      (float)$total_sales,
      (float)$cash_amount,
      (float)$momo_amount,
      (float)$expenses,
      (array)$expense_items,
      (string)($body['comment'] ?? ''),
      $user
    );
    send_json($recon, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 400);
  }
  exit;
}

if ($method === 'POST' && $uri === '/api/daily-stock/submit-bundle') {
  $user = requireAuth($db);
  $body = read_json_body();
  $date = $body['date'] ?? null;
  $rows = $body['rows'] ?? null;
  $reconciliation = $body['reconciliation'] ?? null;

  if (!$date || !is_array($rows) || !$reconciliation) {
    send_json(['error' => 'Missing bundle details: date, rows, or reconciliation data'], 400);
    exit;
  }

  try {
    $result = $db->submitDailyReportBundle(
      (string)$date,
      $rows,
      $reconciliation,
      $user
    );
    send_json($result, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 400);
  }
  exit;
}

// Reports
if ($method === 'GET' && $uri === '/api/reports/stock') {
  requireAuth($db);
  $startDate = $_GET['startDate'] ?? null;
  $endDate = $_GET['endDate'] ?? null;
  if (!$startDate || !$endDate) {
    send_json(['error' => 'Missing startDate or endDate parameters'], 400);
    exit;
  }
  try {
    $report = $db->getAggregatedStockReport((string)$startDate, (string)$endDate);
    send_json($report);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

if ($method === 'GET' && $uri === '/api/reports/daily') {
  $user = requireAuth($db);
  $startDate = $_GET['startDate'] ?? null;
  $endDate = $_GET['endDate'] ?? null;
  $employeeId = null;
  $search = $_GET['search'] ?? null;
  $cashDifferenceFilter = $_GET['cashDifference'] ?? null;
  $status = $_GET['status'] ?? null;

  if (($user['role'] ?? '') === 'employee') {
    $employeeId = $user['id'];
  }

  try {
    $reports = $db->getFilteredDailyReconciliations($startDate, $endDate, $employeeId, $search, $cashDifferenceFilter, $status);
    $metrics = $db->getReportMetrics($reports);
    send_json(['reports' => $reports, 'metrics' => $metrics]);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

if ($method === 'PUT' && preg_match('#^/api/reports/daily/([^/]+)$#', $uri, $match)) {
  $user = requireAdmin($db);
  $reportId = $match[1];
  $body = read_json_body();
  try {
    $updated = $db->updateDailyReconciliation(
      (string)$reportId,
      ['status' => (string)($body['status'] ?? ''), 'comment' => (string)($body['comment'] ?? '')],
      $user
    );
    send_json($updated);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Employees (admin)
if ($method === 'GET' && $uri === '/api/employees') {
  requireAdmin($db);
  $raw = $db->getRawData();
  send_json(['users' => $raw['users'], 'employees' => $raw['employees']]);
  exit;
}

if ($method === 'POST' && $uri === '/api/employees') {
  $user = requireAdmin($db);
  $body = read_json_body();
  $name = $body['name'] ?? null;
  $email = $body['email'] ?? null;
  $username = $body['username'] ?? null;
  $salary = $body['salary'] ?? null;

  if (!$name || !$email || !$username || $salary === null) {
    send_json(['error' => 'Missing required employee creation parameters'], 400);
    exit;
  }

  try {
    $result = $db->createEmployee((string)$name, (string)$email, (string)($body['phone'] ?? ''), (float)$salary, (string)$username, $user);
    send_json($result, 201);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 400);
  }
  exit;
}

if ($method === 'PUT' && preg_match('#^/api/employees/([^/]+)$#', $uri, $m)) {
  $user = requireAdmin($db);
  $id = $m[1];
  $body = read_json_body();
  $name = $body['name'] ?? null;
  $email = $body['email'] ?? null;
  $phone = $body['phone'] ?? '';
  $salary = $body['salary'] ?? 0;
  $status = $body['status'] ?? 'active';

  try {
    $emp = $db->updateEmployee((string)$id, (string)($name ?? ''), (string)($email ?? ''), (string)$phone, (float)$salary, (string)$status, $user);
    send_json($emp);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Notifications
if ($method === 'GET' && $uri === '/api/notifications') {
  requireAuth($db);
  send_json($db->getRawData()['notifications']);
  exit;
}

if ($method === 'POST' && $uri === '/api/notifications/read') {
  requireAuth($db);
  $raw =& $db->getRawDataRef();
  foreach ($raw['notifications'] as &$n) {
    $n['is_read'] = true;
  }
  $db->createNotification('Notifications Cleared', 'All system messages marked as read.', 'info');
  send_json(['success' => true]);
  exit;
}

// Settings
if ($method === 'GET' && $uri === '/api/settings') {
  requireAuth($db);
  send_json($db->getRawData()['settings']);
  exit;
}

if ($method === 'POST' && $uri === '/api/settings') {
  $user = requireAdmin($db);
  $body = read_json_body();
  try {
    $updated = $db->updateSettings($body, $user);
    send_json($updated);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

// Logs
if ($method === 'GET' && $uri === '/api/logs') {
  requireAdmin($db);
  send_json($db->getRawData()['activity_logs']);
  exit;
}

// Backup & Restore
if ($method === 'GET' && $uri === '/api/backup/export') {
  requireAdmin($db);
  send_json($db->getRawData());
  exit;
}

if ($method === 'POST' && $uri === '/api/backup/restore') {
  $user = requireAdmin($db);
  $backupData = read_json_body();
  try {
    $db->restoreFromBackup($backupData);
    $db->logActivity($user['id'], $user['name'], $user['role'], 'RESTORE_BACKUP', 'Successfully restored database from standard export');
    $db->createNotification('System Restored', 'Database backup has been applied successfully.', 'success');
    send_json(['success' => true]);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 400);
  }
  exit;
}

if ($method === 'POST' && $uri === '/api/backup/reset') {
  $user = requireAdmin($db);
  try {
    $db->resetToDefault();
    $db->logActivity($user['id'], $user['name'], $user['role'], 'RESET_DATABASE', 'Successfully reset database to system defaults');
    $db->createNotification('System Reset', 'Database reset to default settings and initial data.', 'info');
    send_json(['success' => true]);
  } catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
  }
  exit;
}

send_json(['error' => 'Not Found'], 404);

