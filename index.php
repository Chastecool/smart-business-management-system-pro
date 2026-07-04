<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/api/DBManager.php';

$setupError = null;
$db = null;
try {
    $db = new DBManager();
} catch (DatabaseSetupException $e) {
    $setupError = $e->getMessage();
}
$user = $_SESSION['user'] ?? null;
$employee = $_SESSION['employee'] ?? null;
$message = '';
$errors = [];

if ($setupError !== null) {
    http_response_code(503);
    echo '<!DOCTYPE html>';
    echo '<html lang="en"><head><meta charset="utf-8"><title>Database setup required</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:760px;margin:3rem auto;padding:2rem;border:1px solid #e5e7eb;border-radius:12px;line-height:1.6;}code{background:#f3f4f6;padding:2px 6px;border-radius:4px;}</style>';
    echo '</head><body><h1>Database setup required</h1><p>' . htmlspecialchars($setupError, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<ol><li>Import the included <code>schema.sql</code> file into MySQL.</li><li>Verify the credentials in <code>config/database.php</code>.</li><li>Refresh this page after the import completes.</li></ol></body></html>';
    exit;
}

function isAdmin(array $user = null): bool {
    return $user && isset($user['role']) && $user['role'] === 'admin';
}

function isEmployee(array $user = null): bool {
    return $user && isset($user['role']) && $user['role'] === 'employee';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));

        if ($username === '' || $password === '') {
            $errors[] = 'Username and password are required.';
        } else {
            $result = $db->authenticate($username, $password);
            if (!$result) {
                $errors[] = 'Invalid username or password.';
            } else {
                $_SESSION['user'] = $result['user'];
                $_SESSION['employee'] = $result['employee'] ?? null;
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }

    if ($action === 'add_category' && isAdmin($user)) {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($name === '') {
            $errors[] = 'Category name is required.';
        } else {
            try {
                $db->createCategory($name, $description, $user);
                $message = 'Category added successfully.';
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'add_product' && isAdmin($user)) {
        $name = trim((string)($_POST['name'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        $category_id = trim((string)($_POST['category_id'] ?? ''));
        $supplier_id = trim((string)($_POST['supplier_id'] ?? ''));
        $price = floatval($_POST['price'] ?? 0);
        $cost = floatval($_POST['cost'] ?? 0);
        $unit = trim((string)($_POST['unit'] ?? 'pcs'));
        $minimum_stock = intval($_POST['minimum_stock'] ?? 0);
        $initial_stock = intval($_POST['initial_stock'] ?? 0);
        $image = trim((string)($_POST['image'] ?? ''));

        if ($name === '' || $code === '' || $category_id === '') {
            $errors[] = 'Product name, code, and category are required.';
        } else {
            try {
                $db->createProduct($name, $code, $barcode, $category_id, $supplier_id, $price, $cost, $unit, $minimum_stock, $initial_stock, $image, $user);
                $message = 'Product added successfully.';
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'update_report' && isAdmin($user)) {
        $reportId = trim((string)($_POST['report_id'] ?? ''));
        $status = trim((string)($_POST['status'] ?? ''));
        $comment = trim((string)($_POST['comment'] ?? ''));

        if ($reportId === '') {
            $errors[] = 'Report ID is required to update a report.';
        } else {
            try {
                $db->updateDailyReconciliation($reportId, ['status' => $status, 'comment' => $comment], $user);
                $message = 'Report updated successfully.';
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'submit_daily_reconciliation' && ($user !== null)) {
        $reconDate = trim((string)($_POST['recon_date'] ?? ''));
        $cash = floatval($_POST['recon_cash'] ?? 0);
        $momo = floatval($_POST['recon_momo'] ?? 0);
        $bank = floatval($_POST['recon_bank'] ?? 0);
        $expensesValue = floatval($_POST['recon_expenses'] ?? 0);
        $comment = trim((string)($_POST['recon_comment'] ?? ''));
        $totalSales = floatval($_POST['recon_total_sales'] ?? 0);

        if ($reconDate === '') {
            $errors[] = 'Reconciliation date is required.';
        } elseif ($cash < 0 || $momo < 0 || $bank < 0 || $expensesValue < 0) {
            $errors[] = 'Reconciliation amounts must be non-negative.';
        } elseif (($cash + $momo + $bank) !== $totalSales && $comment === '') {
            $errors[] = 'A comment is required when totals do not match.';
        } else {
            try {
                $db->submitDailyReconciliation($reconDate, $totalSales, $cash, $momo, $bank, $expensesValue, $comment, $user);
                $message = 'Daily reconciliation saved successfully.';
                $reports = $db->getFilteredDailyReconciliations(null, null, $employeeId, null);
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'update_profile' && $user !== null) {
        $profileName = trim((string)($_POST['profile_name'] ?? ''));
        $profileEmail = trim((string)($_POST['profile_email'] ?? ''));
        $profilePhone = trim((string)($_POST['profile_phone'] ?? ''));

        if ($profileName === '') {
            $errors[] = 'Your full name is required.';
        } else {
            try {
                $updated = $db->updateUserProfile($user['id'], $profileName, $profileEmail, $profilePhone);
                $_SESSION['user']['name'] = $profileName;
                if (isset($_SESSION['employee'])) {
                    $_SESSION['employee']['name'] = $profileName;
                }
                $message = 'Profile updated successfully.';
                $user = $_SESSION['user'];
                $employee = $_SESSION['employee'];
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'update_settings' && isAdmin($user)) {
        $settingsPayload = [];
        $settingsPayload['business_name'] = trim((string)($_POST['business_name'] ?? ''));
        $settingsPayload['phone'] = trim((string)($_POST['phone'] ?? ''));
        $settingsPayload['address'] = trim((string)($_POST['address'] ?? ''));
        $settingsPayload['currency'] = trim((string)($_POST['currency'] ?? ''));
        $settingsPayload['tax_rate'] = floatval($_POST['tax_rate'] ?? 0);
        $settingsPayload['receipt_footer'] = trim((string)($_POST['receipt_footer'] ?? ''));

        if ($settingsPayload['business_name'] === '') {
            $errors[] = 'Business name is required.';
        } else {
            try {
                $db->updateSettings($settingsPayload, $user);
                $message = 'Settings updated successfully.';
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'change_password' && $user !== null) {
        $currentPassword = trim((string)($_POST['current_password'] ?? ''));
        $newPassword = trim((string)($_POST['new_password'] ?? ''));
        $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

        if ($newPassword === '' || $currentPassword === '' || $confirmPassword === '') {
            $errors[] = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation must match.';
        } else {
            try {
                $changed = $db->changePassword($user['id'], $currentPassword, $newPassword);
                if ($changed) {
                    $message = 'Password changed successfully.';
                } else {
                    $errors[] = 'Unable to change password. Please verify your current password.';
                }
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'logout') {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$user = $_SESSION['user'] ?? null;
$employee = $_SESSION['employee'] ?? null;
$categories = $db->getCategories();
$products = $db->getProducts();
$raw = $db->getRawData();
$sales = $raw['sales'] ?? [];
$debts = $db->getDebts();
$expenses = $db->getExpenses();
$notifications = $raw['notifications'] ?? [];
$settings = $raw['settings'] ?? [];
$employees = $raw['employees'] ?? [];
$activity_logs = $raw['activity_logs'] ?? [];
$employeeId = null;
if (!isAdmin($user) && $user !== null) {
    $employeeId = $user['id'] ?? null;
}
$reports = $db->getFilteredDailyReconciliations(null, null, $employeeId, null);
$reportMetrics = $db->getReportMetrics($reports);
$page = $_GET['page'] ?? 'dashboard';
$reportView = $_GET['report_view'] ?? 'daily';
$reportView = in_array($reportView, ['daily', 'weekly', 'monthly', 'yearly'], true) ? $reportView : 'daily';
$reportStartDate = trim((string)($_GET['start_date'] ?? ''));
$reportEndDate = trim((string)($_GET['end_date'] ?? ''));
$dailyReportRows = $reports;
if ($reportStartDate !== '') {
    $dailyReportRows = array_values(array_filter($dailyReportRows, fn($report) => (($report['date'] ?? '') >= $reportStartDate)));
}
if ($reportEndDate !== '') {
    $dailyReportRows = array_values(array_filter($dailyReportRows, fn($report) => (($report['date'] ?? '') <= $reportEndDate)));
}
$weeklySummaries = $db->getReportSummaries('weekly');
$monthlySummaries = $db->getReportSummaries('monthly');
$yearlySummaries = $db->getReportSummaries('yearly');
$employeeReports = array_values(array_filter($reports, function ($report) {
    $reportDate = (string)($report['date'] ?? '');
    if ($reportDate === '') return false;
    $dateObj = new DateTime($reportDate);
    $cutoff = (new DateTime('today'))->modify('-30 days')->setTime(0, 0, 0);
    return $dateObj >= $cutoff;
}));
usort($employeeReports, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));

$stockReportItems = [];
if ($page === 'submit_stock_report') {
    $stockReportItems = array_values(array_filter($products, function ($product) {
        return !isset($product['status']) || $product['status'] === 'active';
    }));

    foreach ($stockReportItems as &$item) {
        $item['product_name'] = $item['name'] ?? $item['product_name'] ?? 'Unknown';
        $item['opening'] = (int)($item['current_stock'] ?? $item['opening'] ?? 0);
        $item['added'] = 0;
        $item['remaining'] = 0;
        $item['sold'] = 0;
        $item['price'] = (float)($item['price'] ?? 0);
    }
    unset($item);
}

$adminPages = ['dashboard','products','categories','suppliers','purchases','sales','inventory','expenses','reports','report','employees','users','analytics','settings','backup','profile','change-password'];
$employeePages = ['dashboard','submit_stock_report','report','my_reports','my_sales','my_expenses','profile','change-password'];

$allowedPages = [];
if (isAdmin($user)) {
    $allowedPages = $adminPages;
} elseif (isEmployee($user)) {
    $allowedPages = $employeePages;
}

if (!in_array($page, $allowedPages, true)) {
    $page = 'access_denied';
    header('HTTP/1.1 403 Forbidden');
}

function formatMoney(float $amount): string {
    return number_format($amount, 2);
}

function filterSalesByEmployee(array $sales, ?string $employeeId): array {
    if (!$employeeId) return [];
    return array_values(array_filter($sales, fn($sale) => ($sale['employee_id'] ?? '') === $employeeId));
}

function filterExpensesByEmployee(array $expenses, string $employeeName): array {
    return array_values(array_filter($expenses, fn($exp) => ($exp['created_by_name'] ?? '') === $employeeName));
}

function getSalesTotals(array $sales): array {
    $total = 0.0;
    $count = 0;
    foreach ($sales as $sale) {
        $total += (float)($sale['total_amount'] ?? 0);
        $count += 1;
    }
    return ['count' => $count, 'total' => $total];
}

function getTopSellingProducts(array $saleItems, int $limit = 5): array {
    $counts = [];
    foreach ($saleItems as $item) {
        $product = $item['product_name'] ?? 'Unknown';
        $quantity = (int)($item['quantity'] ?? 0);
        $counts[$product] = ($counts[$product] ?? 0) + $quantity;
    }
    arsort($counts);
    return array_slice($counts, 0, $limit, true);
}

function getEmployeePerformance(array $sales, array $users): array {
    $performance = [];
    foreach ($sales as $sale) {
        $employeeId = $sale['employee_id'] ?? 'unknown';
        $employeeName = $sale['employee_name'] ?? 'Unknown';
        $performance[$employeeId]['name'] = $employeeName;
        $performance[$employeeId]['sales'] = ($performance[$employeeId]['sales'] ?? 0) + (float)($sale['total_amount'] ?? 0);
        $performance[$employeeId]['count'] = ($performance[$employeeId]['count'] ?? 0) + 1;
    }
    uasort($performance, fn($a, $b) => $b['sales'] <=> $a['sales']);
    return array_slice($performance, 0, 5, true);
}

$todayDate = date('Y-m-d');
$allSales = $raw['sales'] ?? [];
$allExpenses = $raw['expenses'] ?? [];
$allSaleItems = $raw['sale_items'] ?? [];
$allProducts = $raw['products'] ?? [];
$todaySales = array_values(array_filter($allSales, fn($sale) => strpos(($sale['created_at'] ?? ''), $todayDate) === 0));
$topSellingProducts = getTopSellingProducts($allSaleItems, 5);
$lowStockProducts = array_values(array_filter($allProducts, fn($product) => ((int)($product['current_stock'] ?? 0)) <= ((int)($product['minimum_stock'] ?? 0))));
$adminRevenue = getSalesTotals($allSales)['total'];
$adminExpenses = array_sum(array_map(fn($exp) => (float)($exp['amount'] ?? 0), $allExpenses));
$adminProfit = $adminRevenue - $adminExpenses;
$employeeSales = filterSalesByEmployee($allSales, $employeeId);
$employeeExpenses = filterExpensesByEmployee($allExpenses, $user['name'] ?? '');
$employeeSalesToday = array_values(array_filter($employeeSales, fn($sale) => strpos(($sale['created_at'] ?? ''), $todayDate) === 0));
$employeeReports = $reports;
$employeeProfile = array_filter($employees, fn($emp) => ($emp['user_id'] ?? '') === ($user['id'] ?? ''));
$employeeProfile = $employeeProfile ? array_values($employeeProfile)[0] : null;

function getQuickProductSearchJs(array $products): string {
    return json_encode(array_map(fn($product) => ['id' => $product['id'], 'name' => $product['name'], 'code' => $product['code'], 'stock' => $product['current_stock']], $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function getUserById(array $users, string $id): ?array {
    foreach ($users as $user) {
        if (($user['id'] ?? '') === $id) return $user;
    }
    return null;
}

function getEmployeeByUserId(array $employees, string $userId): ?array {
    foreach ($employees as $emp) {
        if (($emp['user_id'] ?? '') === $userId) return $emp;
    }
    return null;
}

function getUserByName(array $users, string $name): ?array {
    foreach ($users as $user) {
        if (($user['name'] ?? '') === $name) return $user;
    }
    return null;
}

function normalizePageId(string $page): string {
    return trim(strtolower(str_replace(' ', '_', $page)));
}

function isPageAllowed(array $allowedPages, string $page): bool {
    return in_array($page, $allowedPages, true);
}

function searchProducts(array $products, string $query): array {
    $query = mb_strtolower($query);
    return array_values(array_filter($products, fn($product) => mb_stripos(($product['name'] ?? '') . ' ' . ($product['code'] ?? ''), $query) !== false));
}

function getEmployeesList(array $users, array $employees): array {
    $list = [];
    foreach ($employees as $emp) {
        $user = getUserById($users, $emp['user_id'] ?? '');
        if ($user) {
            $list[] = ['employee' => $emp, 'user' => $user];
        }
    }
    return $list;
}

function getUserCreationDate(array $user): string {
    return $user['created_at'] ?? 'Unknown';
}

function isUserAdmin(array $user): bool {
    return ($user['role'] ?? '') === 'admin';
}

function getActiveUsersCount(array $users): int {
    return count(array_filter($users, fn($user) => ($user['status'] ?? '') === 'active'));
}

function getProductsCount(array $products): int {
    return count($products);
}

function getCategoriesCount(array $categories): int {
    return count($categories);
}

function getSuppliersCount(array $suppliers): int {
    return count($suppliers);
}

function getReportCount(array $reports): int {
    return count($reports);
}

function getBackupStatus(): string {
    return 'Backup not configured yet';
}

function getUserStatus(array $user): string {
    return $user['status'] ?? 'unknown';
}

function getUserRoleLabel(array $user): string {
    return ucfirst($user['role'] ?? 'unknown');
}

function getEmployeeName(array $emp): string {
    return $emp['name'] ?? 'Unknown';
}

function getRecentActivities(array $activityLogs, int $limit = 5): array {
    return array_slice($activityLogs, 0, $limit);
}

function getEmployeePerformanceRows(array $performance): array {
    return $performance;
}

function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Prepare stored profile values for rendering
$profileName = $user['name'] ?? '';
$profileEmail = $employeeProfile['email'] ?? '';
$profilePhone = $employeeProfile['phone'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Smart Business Management System</title>
    <style>
        body { margin:0; font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#eaf4ff; color:#0f172a; }
        .app-shell { display:flex; min-height:100vh; background:#eaf4ff; }
        .sidebar { width:280px; background:#ffffff; color:#0f172a; padding:28px; border-right:1px solid #dbeafe; }
        .sidebar h1 { margin:0 0 18px; font-size:24px; letter-spacing:.02em; color:#0f172a; }
        .sidebar p { margin:0 0 24px; color:#475569; font-size:14px; line-height:1.5; }
        .sidebar a { display:block; color:#0f172a; text-decoration:none; margin:10px 0; padding:14px 16px; border-radius:16px; border:1px solid transparent; background:#eff6ff; font-weight:600; }
        .sidebar a.active, .sidebar a:hover { background:#2563eb; color:#fff; border-color:#1d4ed8; }
        .content { flex:1; padding:28px; }
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:24px; padding:18px 22px; background:#ffffff; border:1px solid #dbeafe; border-radius:20px; box-shadow:0 16px 40px rgba(15, 23, 42, 0.08); }
        .topbar h2 { margin:4px 0 0; font-size:20px; color:#0f172a; }
        .topbar-label { font-size:12px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#64748b; }
        .profile-dropdown { position:relative; }
        .profile-dropdown summary { list-style:none; display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:999px; background:#eff6ff; cursor:pointer; color:#0f172a; font-weight:700; }
        .profile-dropdown summary::-webkit-details-marker { display:none; }
        .profile-avatar { width:36px; height:36px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }
        .profile-menu { position:absolute; right:0; top:calc(100% + 8px); min-width:220px; background:#fff; border:1px solid #dbeafe; border-radius:16px; box-shadow:0 20px 45px rgba(15,23,42,.12); padding:10px; display:flex; flex-direction:column; gap:6px; z-index:20; }
        .profile-menu a, .profile-menu button { display:block; width:100%; text-align:left; padding:10px 12px; border:none; border-radius:10px; background:transparent; color:#0f172a; text-decoration:none; font-weight:600; cursor:pointer; }
        .profile-menu a:hover, .profile-menu button:hover { background:#eff6ff; }
        .card { background:#ffffff; border-radius:24px; padding:24px; box-shadow:0 16px 40px rgba(15, 23, 42, 0.08); margin-bottom:24px; border:1px solid #dbeafe; }
        .grid { display:grid; gap:20px; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); }
        table { width:100%; border-collapse:collapse; background:#ffffff; }
        th, td { padding:14px 12px; border-bottom:1px solid #e2e8f0; text-align:left; }
        th { background:#eff6ff; color:#0f172a; font-weight:700; }
        .form-row { margin-bottom:16px; }
        .form-row label { display:block; margin-bottom:8px; font-weight:700; color:#0f172a; }
        .form-row input, .form-row select, .form-row textarea { width:100%; padding:14px 16px; border:1px solid #cbd5e1; border-radius:14px; background:#f8fbff; color:#0f172a; }
        .form-row input:focus, .form-row select:focus, .form-row textarea:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 4px rgba(59,130,246,0.12); }
        .button { display:inline-flex; align-items:center; justify-content:center; width:100%; max-width:220px; padding:14px 18px; border-radius:14px; border:none; background:#2563eb; color:#fff; text-decoration:none; cursor:pointer; font-weight:700; box-shadow:0 12px 28px rgba(37,99,235,0.18); }
        .button--danger { background:#ef4444; box-shadow:0 12px 28px rgba(239,68,68,0.18); }
        .alert { padding:16px 18px; border-radius:18px; margin-bottom:20px; }
        .alert--error { background:#f8d7da; color:#991b1b; border:1px solid #f5c2c7; }
        .alert--success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
        .summary-grid { display:grid; gap:18px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); margin-bottom:22px; }
        .metric-card { background:#eff6ff; border-radius:20px; padding:22px; border:1px solid #bfdbfe; }
        .metric-card strong { display:block; font-size:13px; color:#475569; margin-bottom:10px; }
        .metric-card span { font-size:28px; font-weight:800; color:#0f172a; }
        .filters { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); margin-bottom:22px; }
        .table-actions { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:18px; }
        .reports-table { width:100%; border-collapse:collapse; margin-bottom:18px; }
        .reports-table th, .reports-table td { padding:14px 12px; border-bottom:1px solid #e2e8f0; }
        .reports-table th { background:#eff6ff; cursor:pointer; }
        .report-chip { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-size:13px; border:1px solid transparent; }
        .report-chip.Submitted { background:#dbeafe; color:#1d4ed8; }
        .report-chip.Approved { background:#d1fae5; color:#047857; }
        .report-chip.Rejected { background:#fee2e2; color:#b91c1c; }
        .chart-grid { display:grid; gap:20px; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); margin-top:22px; }
        .table-footer { display:flex; justify-content:space-between; flex-wrap:wrap; gap:14px; align-items:center; margin-top:14px; }
        .pagination { display:flex; gap:10px; flex-wrap:wrap; }
        .pagination button { padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; cursor:pointer; }
        .pagination button.active { background:#2563eb; color:#fff; border-color:#1d4ed8; }
        .modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.45); display:none; align-items:center; justify-content:center; z-index:50; }
        .modal { background:#ffffff; border-radius:22px; width:min(96vw,760px); max-height:90vh; overflow:auto; padding:28px; box-shadow:0 32px 90px rgba(15,23,42,.22); }
        .modal h3 { margin-top:0; }
        .modal-close { position:absolute; top:18px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; }
        .card h2, .card h3 { margin-top:0; color:#0f172a; }
        .form-row textarea { min-height:120px; }
        .sidebar form button { width:100%; }
        .report-panel { margin-top:24px; }
        .report-list { display:flex; flex-direction:column; gap:0; background:#ffffff; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; }
        .report-card { background:#ffffff; border-radius:22px; padding:22px; border:1px solid #dbeafe; box-shadow:0 18px 40px rgba(15, 23, 42, 0.06); transition:transform .2s ease, box-shadow .2s ease; }
        .report-card:hover { transform:translateY(-2px); box-shadow:0 24px 50px rgba(15, 23, 42, 0.12); }
        .report-card-header { display:flex; flex-wrap:wrap; gap:14px; justify-content:space-between; align-items:flex-start; margin-bottom:18px; }
        .report-card-title { font-size:18px; font-weight:800; color:#0f172a; }
        .report-card-meta { display:grid; gap:8px; }
        .report-card-meta span { font-size:13px; color:#475569; }
        .status-badge { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-size:13px; font-weight:700; }
        .status-badge.balanced { background:#dcfce7; color:#166534; }
        .status-badge.pending { background:#fef3c7; color:#b45309; }
        .status-badge.difference { background:#fee2e2; color:#b91c1c; }
        .report-card-body { display:grid; gap:14px; }
        .report-row { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); }
        .report-value { background:#eff6ff; border-radius:16px; padding:16px; border:1px solid #dbeafe; }
        .report-value strong { display:block; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#475569; margin-bottom:8px; }
        .report-value span { font-size:20px; font-weight:800; color:#0f172a; }
        .report-card-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; justify-content:flex-end; }
        .action-button { border:none; border-radius:14px; background:#eff6ff; color:#2563eb; padding:10px 14px; cursor:pointer; font-weight:700; transition:background .2s ease; }
        .action-button:hover { background:#dbeafe; }
        .report-list-item { display:flex; align-items:center; gap:12px; padding:14px 18px; border-bottom:1px solid #e2e8f0; background:#ffffff; transition:background .2s ease; justify-content:space-between; flex-wrap:wrap; }
        .report-list-item:hover { background:#f8fbff; }
        .report-list-item:last-child { border-bottom:none; }
        .report-list-info { flex:1; display:flex; align-items:center; gap:16px; flex-wrap:nowrap; min-width:0; }
        .report-list-title { font-weight:700; color:#0f172a; font-size:14px; flex-shrink:0; }
        .report-list-date { color:#666; font-size:13px; flex-shrink:0; }
        .report-list-employee { color:#666; font-size:13px; flex-shrink:0; }
        .report-list-status { display:inline-flex; align-items:center; padding:5px 10px; border-radius:5px; font-size:12px; font-weight:600; flex-shrink:0; }
        .report-list-status-submitted { background:#fef3c7; color:#b45309; }
        .report-list-status-approved { background:#dcfce7; color:#166534; }
        .report-list-status-rejected { background:#fee2e2; color:#b91c1c; }
        .report-list-actions { display:flex; gap:10px; align-items:center; white-space:nowrap; }
        .report-list-actions a { padding:8px 14px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; font-size:12px; font-weight:600; transition:background .2s ease; }
        .report-list-actions a:hover { background:#1d4ed8; }
        .report-group-header { display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; align-items:center; }
        .report-group-heading { font-size:16px; font-weight:800; color:#0f172a; }
        .report-group-meta { color:#475569; font-size:14px; }
        .report-detail-heading { font-size:16px; font-weight:700; color:#0f172a; margin-top:18px; margin-bottom:12px; }
        .report-detail-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .detail-block { background:#f8fbff; border:1px solid #dbeafe; border-radius:16px; padding:16px; }
        .stock-report-sheet { width:100%; background:#ffffff; border:1px solid #111; border-radius:18px; padding:30px; margin-bottom:24px; box-shadow:0 18px 45px rgba(15,23,42,.08); }
        .page-actions { display:flex; gap:12px; flex-wrap:wrap; justify-content:flex-end; margin-bottom:24px; }
        .report-sheet-header { display:grid; grid-template-columns:1.6fr 1fr; gap:24px; padding-bottom:24px; border-bottom:2px solid #111; }
        .report-sheet-company { display:flex; flex-direction:column; gap:14px; }
        .report-sheet-logo { width:72px; height:72px; background:#111; color:#fff; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; border-radius:16px; letter-spacing:.08em; }
        .report-sheet-company h1 { margin:0; font-size:28px; letter-spacing:.04em; }
        .report-sheet-company p { margin:0; color:#1f2937; line-height:1.6; }
        .report-sheet-details { display:grid; gap:10px; align-content:start; }
        .report-sheet-details h2 { margin:0; font-size:24px; letter-spacing:.06em; }
        .meta-table { width:100%; border-collapse:collapse; margin-top:8px; }
        .meta-table td { padding:6px 8px; color:#111827; }
        .meta-table tr:nth-child(odd) td { background: #f8fafc; }
        .report-table { width:100%; border-collapse:collapse; margin-top:28px; }
        .report-table th, .report-table td { border:1px solid #111; padding:12px 10px; text-align:left; font-size:13px; }
        .report-table th { background:#f3f4f6; color:#111827; font-weight:700; }
        .report-table tbody tr:nth-child(even) { background:#fafafa; }
        .report-table tfoot td { font-weight:700; background:#e5e7eb; }
        .report-table tfoot td:last-child { text-align:right; }
        .reconciliation-card { background:#f8fafc; border:1px solid #d1d5db; border-radius:18px; padding:24px; margin-top:32px; }
        .reconciliation-grid { display:grid; gap:18px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
        .reconciliation-grid .form-row { margin:0; }
        .reconciliation-grid label { margin-bottom:8px; display:block; color:#111827; font-weight:700; }
        .reconciliation-grid input { width:100%; padding:10px 12px; border-radius:12px; border:1px solid #cbd5e1; background:#fff; }
        .reconciliation-summary { margin-top:22px; display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); }
        .summary-block { padding:16px; border-radius:16px; background:#ffffff; border:1px solid #d1d5db; }
        .summary-block.warning { border-color:#fca5a5; background:#fef2f2; }
        .summary-block strong { display:block; font-size:12px; color:#475569; margin-bottom:8px; }
        .summary-block span { font-size:18px; font-weight:800; color:#111827; }
        .summary-field span { display:inline-block; width:100%; padding:10px 12px; border-radius:12px; background:#fff; border:1px solid #cbd5e1; color:#111827; }
        .invalid-input { border-color:#ef4444 !important; box-shadow:0 0 0 2px rgba(239,68,68,0.15); }
        .reconciliation-warning { color:#b91c1c; margin-top:18px; font-weight:700; }
        .button[disabled] { opacity:0.65; cursor:default; }
        .spinner { display:inline-block; width:14px; height:14px; border:2px solid rgba(0,0,0,0.15); border-top-color:#111827; border-radius:50%; margin-right:8px; vertical-align:middle; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        #stock-submit-status { margin-top:8px; font-weight:700; color:#047857; display:none; }
        #stock-submit-status.show { display:block; }
        #stock-submit-status.error { color:#b91c1c; display:block; }
        .remarks-section { margin-top:28px; }
        .remarks-section textarea { width:100%; min-height:120px; padding:14px 16px; border-radius:14px; border:1px solid #cbd5e1; background:#fff; resize:vertical; }
        .signature-grid { display:grid; grid-template-columns:repeat(3,minmax(160px,1fr)); gap:28px; margin-top:28px; }
        .signature-block { border-top:1px solid #111; padding-top:12px; font-size:14px; color:#111827; }
        .signature-block span { display:block; margin-top:8px; color:#475569; font-size:13px; }
        .report-sheet-footer { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-top:28px; }
        .report-sheet-footer .subtitle { color:#475569; font-size:13px; }
        @media print {
            body { background:#fff; color:#000; }
            .sidebar, .page-actions, .button, .button--danger, .topbar { display:none !important; }
            .app-shell { flex-direction:column; }
            .content { padding:0; }
            .stock-report-sheet { box-shadow:none; border:none; margin:0; padding:0; }
            .report-sheet-header { border:none; }
            .report-table th, .report-table td { border:1px solid #000; }
            .reconciliation-card, .signature-grid { page-break-inside: avoid; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body>
<?php if (!$user): ?>
    <div style="display:flex; min-height:100vh; align-items:center; justify-content:center; background:#111827; padding:24px;">
        <div style="width:100%; max-width:420px; background:#fff; border-radius:20px; padding:32px; box-shadow:0 20px 60px rgba(15,23,42,.30);">
            <h2 style="margin-top:0;">Login</h2>
            <?php if ($errors): ?>
                <div class="alert alert--error"><?= esc(implode(' ', $errors)) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login" />
                <div class="form-row">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" required />
                </div>
                <div class="form-row">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required />
                </div>
                <button type="submit" class="button">Sign In</button>
            </form>
            <p style="margin-top:16px; color:#6b7280;">Default admin login: <strong>admin</strong> / <strong>admin123</strong></p>
        </div>
    </div>
<?php else: ?>
    <div class="app-shell">
        <aside class="sidebar">
            <h1><?= esc($settings['business_name'] ?? 'TEQUILA BAR & RESTAURANT') ?></h1>
            <p style="font-size:14px; color:#9ca3af; margin-bottom:20px;">Hello, <?= esc($user['name'] ?? $user['username']) ?></p>
            <a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
            <?php if (isAdmin($user)): ?>
                <a href="?page=products" class="<?= $page === 'products' ? 'active' : '' ?>">Products</a>
                <a href="?page=categories" class="<?= $page === 'categories' ? 'active' : '' ?>">Categories</a>
                <a href="?page=sales" class="<?= $page === 'sales' ? 'active' : '' ?>">Sales</a>
                <a href="?page=reports" class="<?= $page === 'reports' ? 'active' : '' ?>">Reports</a>
                <a href="?page=expenses" class="<?= $page === 'expenses' ? 'active' : '' ?>">Expenses</a>
                <a href="?page=employees" class="<?= $page === 'employees' ? 'active' : '' ?>">Employees</a>
                <a href="?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">Settings</a>
            <?php elseif (isEmployee($user)): ?>
                <a href="?page=submit_stock_report" class="<?= $page === 'submit_stock_report' ? 'active' : '' ?>">Daily Stock Report</a>
                <a href="?page=my_reports" class="<?= $page === 'my_reports' ? 'active' : '' ?>">My Reports</a>
                <a href="?page=my_sales" class="<?= $page === 'my_sales' ? 'active' : '' ?>">My Sales</a>
                <a href="?page=my_expenses" class="<?= $page === 'my_expenses' ? 'active' : '' ?>">My Expenses</a>
            <?php endif; ?>
            <a href="?page=profile" class="<?= $page === 'profile' ? 'active' : '' ?>">Profile</a>
        </aside>
        <main class="content">
            <div class="topbar">
                <div>
                    <div class="topbar-label">Business Portal</div>
                    <h2><?= esc($settings['business_name'] ?? 'TEQUILA BAR & RESTAURANT') ?></h2>
                </div>
                <div class="profile-menu-wrapper">
                    <details class="profile-dropdown">
                        <summary>
                            <span class="profile-avatar"><?= esc(substr($user['name'] ?? $user['username'] ?? 'U', 0, 1)) ?></span>
                            <span><?= esc($user['name'] ?? $user['username'] ?? 'User') ?></span>
                            <span>▾</span>
                        </summary>
                        <div class="profile-menu">
                            <a href="?page=profile">My Profile</a>
                            <a href="?page=profile#edit-profile">Edit Profile</a>
                            <a href="?page=change-password">Change Password</a>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="logout" />
                                <button type="submit">Logout</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>
            <?php if ($message): ?>
                <div class="alert alert--success"><?= esc($message) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert--error"><?= esc(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <div class="card">
                    <h2>Dashboard</h2>
                    <?php if (isAdmin($user)): ?>
                        <div style="margin-bottom:18px;"><a href="?page=employees" class="button">Add User</a></div>
                    <?php endif; ?>
                    <div class="grid">
                        <?php if (isAdmin($user)): ?>
                            <div class="card" style="background:#eef2ff;"><strong>Products</strong><div style="font-size:28px; margin-top:12px;"><?= count($products) ?></div></div>
                            <div class="card" style="background:#ecfdf5;"><strong>Categories</strong><div style="font-size:28px; margin-top:12px;"><?= count($categories) ?></div></div>
                            <div class="card" style="background:#fef3c7;"><strong>Sales</strong><div style="font-size:28px; margin-top:12px;"><?= esc(number_format(getSalesTotals($sales)['total'], 2)) ?></div></div>
                            <div class="card" style="background:#fee2e2;"><strong>Expenses</strong><div style="font-size:28px; margin-top:12px;"><?= esc(number_format(array_sum(array_map(fn($exp) => (float)($exp['amount'] ?? 0), $expenses)), 2)) ?></div></div>
                        <?php elseif (isEmployee($user)): ?>
                            <div class="card" style="background:#eef2ff;"><strong>My Sales Today</strong><div style="font-size:28px; margin-top:12px;"><?= esc(number_format((float)array_sum(array_map(fn($sale) => (float)($sale['total_amount'] ?? 0), $employeeSalesToday)), 2)) ?></div></div>
                            <div class="card" style="background:#ecfdf5;"><strong>My Sales Count</strong><div style="font-size:28px; margin-top:12px;"><?= esc((string)count($employeeSales)) ?></div></div>
                            <div class="card" style="background:#fef3c7;"><strong>My Reports</strong><div style="font-size:28px; margin-top:12px;"><?= esc((string)count($employeeReports)) ?></div></div>
                            <div class="card" style="background:#fee2e2;"><strong>My Expenses</strong><div style="font-size:28px; margin-top:12px;"><?= esc(number_format((float)array_sum(array_map(fn($exp) => (float)($exp['amount'] ?? 0), $employeeExpenses)), 2)) ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card">
                    <h2>Recent Notifications</h2>
                    <?php if (count($notifications) === 0): ?>
                        <p>No notifications yet.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach (array_slice($notifications, 0, 5) as $note): ?>
                                <li><strong><?= esc($note['title'] ?? '') ?></strong> — <?= esc($note['message'] ?? '') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php elseif ($page === 'products'): ?>
                <div class="card">
                    <h2>Products</h2>
                    <table>
                        <thead><tr><th>Name</th><th>Code</th><th>Category</th><th>Price</th><th>Stock</th></tr></thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?= esc($product['name']) ?></td>
                                    <td><?= esc($product['code']) ?></td>
                                    <td><?= esc($product['category_id']) ?></td>
                                    <td><?= esc(number_format((float)$product['price'], 2)) ?></td>
                                    <td><?= esc((string)$product['current_stock']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (isAdmin($user)): ?>
                    <div class="card">
                        <h3>Add New Product</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="add_product" />
                            <div class="form-row"><label>Product Name</label><input name="name" required /></div>
                            <div class="form-row"><label>Product Code</label><input name="code" required /></div>
                            <div class="form-row"><label>Barcode</label><input name="barcode" /></div>
                            <div class="form-row"><label>Category</label><select name="category_id" required><option value="">Select category</option><?php foreach ($categories as $cat): ?><option value="<?= esc($cat['id']) ?>"><?= esc($cat['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-row"><label>Supplier</label><input name="supplier_id" placeholder="Supplier ID or name" /></div>
                            <div class="form-row"><label>Price</label><input name="price" type="number" step="0.01" required /></div>
                            <div class="form-row"><label>Cost</label><input name="cost" type="number" step="0.01" required /></div>
                            <div class="form-row"><label>Unit</label><input name="unit" value="pcs" /></div>
                            <div class="form-row"><label>Minimum Stock</label><input name="minimum_stock" type="number" value="0" /></div>
                            <div class="form-row"><label>Initial Stock</label><input name="initial_stock" type="number" value="0" /></div>
                            <div class="form-row"><label>Image URL</label><input name="image" placeholder="Optional image URL or path" /></div>
                            <button class="button">Add Product</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php elseif ($page === 'categories'): ?>
                <div class="card">
                    <h2>Categories</h2>
                    <table>
                        <thead><tr><th>Name</th><th>Description</th></tr></thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?= esc($category['name']) ?></td>
                                    <td><?= esc($category['description'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (isAdmin($user)): ?>
                    <div class="card">
                        <h3>Add Category</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="add_category" />
                            <div class="form-row"><label>Name</label><input name="name" required /></div>
                            <div class="form-row"><label>Description</label><textarea name="description"></textarea></div>
                            <button class="button">Add Category</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php elseif ($page === 'sales'): ?>
                <div class="card">
                    <h2>Sales</h2>
                    <table>
                        <thead><tr><th>ID</th><th>Total</th><th>Paid</th><th>Type</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td><?= esc($sale['id']) ?></td>
                                    <td><?= esc(number_format((float)$sale['total_amount'], 2)) ?></td>
                                    <td><?= esc(number_format((float)$sale['amount_paid'], 2)) ?></td>
                                    <td><?= esc($sale['payment_type']) ?></td>
                                    <td><?= esc($sale['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($page === 'reports'): ?>
                <div class="card">
                    <h2>Reports</h2>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
                        <a href="?page=reports&report_view=daily<?= $reportStartDate !== '' ? '&start_date=' . urlencode($reportStartDate) : '' ?><?= $reportEndDate !== '' ? '&end_date=' . urlencode($reportEndDate) : '' ?>" class="button" style="background:<?= $reportView === 'daily' ? '#2563eb' : '#e2e8f0' ?>; color:<?= $reportView === 'daily' ? '#fff' : '#0f172a' ?>;">Daily Reports</a>
                        <a href="?page=reports&report_view=weekly<?= $reportStartDate !== '' ? '&start_date=' . urlencode($reportStartDate) : '' ?><?= $reportEndDate !== '' ? '&end_date=' . urlencode($reportEndDate) : '' ?>" class="button" style="background:<?= $reportView === 'weekly' ? '#2563eb' : '#e2e8f0' ?>; color:<?= $reportView === 'weekly' ? '#fff' : '#0f172a' ?>;">Weekly Reports</a>
                        <a href="?page=reports&report_view=monthly<?= $reportStartDate !== '' ? '&start_date=' . urlencode($reportStartDate) : '' ?><?= $reportEndDate !== '' ? '&end_date=' . urlencode($reportEndDate) : '' ?>" class="button" style="background:<?= $reportView === 'monthly' ? '#2563eb' : '#e2e8f0' ?>; color:<?= $reportView === 'monthly' ? '#fff' : '#0f172a' ?>;">Monthly Reports</a>
                        <a href="?page=reports&report_view=yearly<?= $reportStartDate !== '' ? '&start_date=' . urlencode($reportStartDate) : '' ?><?= $reportEndDate !== '' ? '&end_date=' . urlencode($reportEndDate) : '' ?>" class="button" style="background:<?= $reportView === 'yearly' ? '#2563eb' : '#e2e8f0' ?>; color:<?= $reportView === 'yearly' ? '#fff' : '#0f172a' ?>;">Yearly Reports</a>
                    </div>
                    <form method="get" style="display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); margin-bottom:16px; align-items:end;">
                        <input type="hidden" name="page" value="reports" />
                        <input type="hidden" name="report_view" value="<?= esc($reportView) ?>" />
                        <div class="form-row"><label>From</label><input type="date" name="start_date" value="<?= esc($reportStartDate) ?>" /></div>
                        <div class="form-row"><label>To</label><input type="date" name="end_date" value="<?= esc($reportEndDate) ?>" /></div>
                        <div class="form-row"><button class="button" type="submit">Apply Filter</button></div>
                    </form>
                    <?php if ($reportView === 'daily'): ?>
                        <table class="reports-table">
                            <thead><tr><th>Report Date</th></tr></thead>
                            <tbody>
                                <?php if (count($dailyReportRows) === 0): ?>
                                    <tr><td style="text-align:center; color:#64748b; padding:24px;">No daily reports match the selected range.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($dailyReportRows as $report): ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                                                    <span><?= esc($report['date'] ?? '') ?></span>
                                                    <a class="button" href="?page=report&id=<?= esc($report['id'] ?? '') ?>">View Report</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php elseif ($reportView === 'weekly'): ?>
                        <table class="reports-table">
                            <thead><tr><th>Period</th><th>Reports Included</th><th>Total Sales</th><th>Cash</th><th>MoMo</th><th>Expenses</th><th>Cash Difference</th></tr></thead>
                            <tbody>
                                <?php if (count($weeklySummaries) === 0): ?>
                                    <tr><td colspan="7" style="text-align:center; color:#64748b; padding:24px;">No weekly summaries have been generated yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($weeklySummaries as $summary): ?>
                                        <?php $periodLabel = date('M d, Y', strtotime($summary['period_start'])) . ' - ' . date('M d, Y', strtotime($summary['period_end'])); ?>
                                        <tr>
                                            <td><?= esc($periodLabel) ?></td>
                                            <td><?= esc((string)($summary['report_count'] ?? 0)) ?></td>
                                            <td><?= esc(number_format((float)($summary['total_sales'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['cash_amount'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['momo_amount'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['expenses'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['cash_difference'] ?? 0), 2)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php elseif ($reportView === 'monthly'): ?>
                        <table class="reports-table">
                            <thead><tr><th>Period</th><th>Reports Included</th><th>Total Sales</th><th>Cash</th><th>MoMo</th><th>Expenses</th><th>Cash Difference</th></tr></thead>
                            <tbody>
                                <?php if (count($monthlySummaries) === 0): ?>
                                    <tr><td colspan="7" style="text-align:center; color:#64748b; padding:24px;">No monthly summaries have been generated yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($monthlySummaries as $summary): ?>
                                        <?php $periodLabel = date('M Y', strtotime($summary['period_start'])); ?>
                                        <tr>
                                            <td><?= esc($periodLabel) ?></td>
                                            <td><?= esc((string)($summary['report_count'] ?? 0)) ?></td>
                                            <td><?= esc(number_format((float)($summary['total_sales'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['cash_amount'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['momo_amount'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['expenses'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['cash_difference'] ?? 0), 2)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <table class="reports-table">
                            <thead><tr><th>Period</th><th>Reports Included</th><th>Total Sales</th><th>Cash</th><th>MoMo</th><th>Expenses</th><th>Cash Difference</th></tr></thead>
                            <tbody>
                                <?php if (count($yearlySummaries) === 0): ?>
                                    <tr><td colspan="7" style="text-align:center; color:#64748b; padding:24px;">No yearly summaries have been generated yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($yearlySummaries as $summary): ?>
                                        <?php $periodLabel = date('Y', strtotime($summary['period_start'])); ?>
                                        <tr>
                                            <td><?= esc($periodLabel) ?></td>
                                            <td><?= esc((string)($summary['report_count'] ?? 0)) ?></td>
                                            <td><?= esc(number_format((float)($summary['total_sales'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['cash_amount'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['momo_amount'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['expenses'] ?? 0), 2)) ?></td>
                                            <td><?= esc(number_format((float)($summary['cash_difference'] ?? 0), 2)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <?php elseif ($page === 'report'): ?>
                <?php
                    // Admin read-only professional report view
                    $reportId = $_GET['id'] ?? '';
                    $report = null;
                    foreach ($reports as $r) { if (($r['id'] ?? '') === $reportId) { $report = $r; break; } }
                    if (!$report) {
                        echo '<div class="card"><h2>Report Not Found</h2><p>The requested report does not exist.</p></div>';
                    } elseif (!isAdmin($user) && (($report['employee_id'] ?? '') !== ($user['id'] ?? ''))) {
                        echo '<div class="card"><h2>Access Restricted</h2><p>You can only view your own report details.</p></div>';
                    } else {
                        $reportRows = [];
                        // load rows exactly as submitted by the employee: match by date and employee_id
                        $rawData = $db->getRawData();
                        $date = $report['date'] ?? '';
                        $employeeId = $report['employee_id'] ?? '';
                        foreach ($rawData['daily_stock'] ?? [] as $row) {
                            if ((($row['date'] ?? '') === $date) && (($row['employee_id'] ?? '') === $employeeId)) {
                                $reportRows[] = $row;
                            }
                        }
                        $status = $report['status'] ?? 'Submitted';
                        $badgeClass = $status === 'Approved' ? 'badge-approved' : ($status === 'Rejected' ? 'badge-rejected' : 'badge-pending');
                ?>
                <div class="card report-printable" style="padding:28px;">
                    <!-- Report Header -->
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px; flex-wrap:wrap;">
                        <div style="display:flex; gap:12px; align-items:center;">
                            <div style="width:72px; height:72px; background:#0f172a; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; border-radius:8px; font-size:12px;">LOGO</div>
                            <div>
                                <h2 style="margin:0; font-size:22px; line-height:1.1;"><?= esc($settings['business_name'] ?? 'Business Name') ?></h2>
                                <div style="font-size:13px; color:#475569; margin-top:2px;"><?= esc($settings['address'] ?? 'Address') ?></div>
                                <div style="font-size:13px; color:#475569;">Tel: <?= esc($settings['phone'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div style="text-align:right; min-width:240px; font-size:13px; color:#111827;">
                            <div style="margin-bottom:8px; font-size:16px; font-weight:700;">Daily Stock Report</div>
                            <div style="margin-bottom:3px;"><strong>Report No:</strong> <?= esc($report['id'] ?? '') ?></div>
                            <div style="margin-bottom:3px;"><strong>Report Date:</strong> <?= esc($report['date'] ?? '') ?></div>
                            <div style="margin-bottom:3px;"><strong>Employee:</strong> <?= esc($report['employee_name'] ?? '-') ?></div>
                            <?php if (!empty($report['shift'])): ?><div style="margin-bottom:3px;"><strong>Shift:</strong> <?= esc($report['shift']) ?></div><?php endif; ?>
                            <div><strong>Status:</strong> <span class="status-badge <?= $badgeClass ?>"><?= esc($status) ?></span></div>
                        </div>
                    </div>

                    <!-- Stock Report Table -->
                    <?php
                        $totalProducts = count($reportRows);
                        $totalOpening = 0;
                        $totalReceived = 0;
                        $totalSold = 0;
                        $totalRemaining = 0;
                        $totalSalesValue = 0.0;
                        $totalRemainingValue = 0.0;
                    ?>

                    <h3 style="margin-top:20px; margin-bottom:12px; font-size:15px;">Stock Report</h3>
                    <table class="report-table" style="margin-bottom:20px; font-size:13px;">
                        <thead style="background:#f0f9ff;">
                            <tr>
                                <th style="width:5%;">No</th>
                                <th style="width:20%;">Product Name</th>
                                <th style="width:10%;">Opening Stock</th>
                                <th style="width:10%;">Stock Received</th>
                                <th style="width:10%;">Total Stock Available</th>
                                <th style="width:10%;">Quantity Sold</th>
                                <th style="width:10%;">Remaining Stock</th>
                                <th style="width:8%;">Unit Price</th>
                                <th style="width:12%;">Total Sales Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportRows as $i => $rrow):
                                $prodName = esc($rrow['product_name'] ?? ($rrow['product_id'] ?? ''));
                                $opening = (int)($rrow['opening_stock'] ?? 0);
                                $received = (int)($rrow['stock_in'] ?? 0);
                                $remaining = (int)($rrow['remaining_stock'] ?? 0);
                                $total = $opening + $received;
                                $sold = max(0, $total - $remaining);
                                $price = 0.0;
                                foreach ($allProducts as $p) { if (($p['id'] ?? '') === ($rrow['product_id'] ?? '')) { $price = (float)($p['price'] ?? 0); break; } }
                                $salesValue = $sold * $price;
                                $remainingValue = $remaining * $price;
                                $totalOpening += $opening;
                                $totalReceived += $received;
                                $totalSold += $sold;
                                $totalRemaining += $remaining;
                                $totalSalesValue += $salesValue;
                                $totalRemainingValue += $remainingValue;
                            ?>
                                <tr>
                                    <td><?= esc((string)($i+1)) ?></td>
                                    <td><?= $prodName ?></td>
                                    <td style="text-align:right;"><?= esc((string)$opening) ?></td>
                                    <td style="text-align:right;"><?= esc((string)$received) ?></td>
                                    <td style="text-align:right;"><?= esc((string)$total) ?></td>
                                    <td style="text-align:right;"><?= esc((string)$sold) ?></td>
                                    <td style="text-align:right;"><?= esc((string)$remaining) ?></td>
                                    <td style="text-align:right;"><?= esc(number_format($price, 2)) ?></td>
                                    <td style="text-align:right;"><?= esc(number_format($salesValue, 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#f3f4f6; font-weight:600;">
                            <tr>
                                <td colspan="2">TOTALS</td>
                                <td style="text-align:right;"><?= esc((string)$totalOpening) ?></td>
                                <td style="text-align:right;"><?= esc((string)$totalReceived) ?></td>
                                <td style="text-align:right;"><?= esc((string)($totalOpening + $totalReceived)) ?></td>
                                <td style="text-align:right;"><?= esc((string)$totalSold) ?></td>
                                <td style="text-align:right;"><?= esc((string)$totalRemaining) ?></td>
                                <td></td>
                                <td style="text-align:right;"><?= esc(number_format($totalSalesValue, 2)) ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- Report Summary -->
                    <h3 style="margin-top:20px; margin-bottom:12px; font-size:15px;">Report Summary</h3>
                    <div style="display:grid; grid-template-columns:repeat(3,minmax(160px,1fr)); gap:12px; margin-bottom:20px; font-size:13px;">
                        <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Total Products</div>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;"><?= esc((string)$totalProducts) ?></div>
                        </div>
                        <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Total Opening Stock</div>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;"><?= esc((string)$totalOpening) ?></div>
                        </div>
                        <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Total Stock Received</div>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;"><?= esc((string)$totalReceived) ?></div>
                        </div>
                        <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Total Quantity Sold</div>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;"><?= esc((string)$totalSold) ?></div>
                        </div>
                        <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Total Remaining Stock</div>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;"><?= esc((string)$totalRemaining) ?></div>
                        </div>
                        <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Total Sales Value</div>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format($totalSalesValue, 2)) ?></div>
                        </div>
                        <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Remaining Stock Value</div>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format($totalRemainingValue, 2)) ?></div>
                        </div>
                    </div>

                    <!-- Daily Cash Reconciliation -->
                    <h3 style="margin-top:20px; margin-bottom:12px; font-size:15px;">Daily Cash Reconciliation</h3>
                    <table class="report-table" style="margin-bottom:20px; font-size:13px;">
                        <tbody>
                            <tr style="background:#f0f9ff;"><td style="width:40%; font-weight:600;">Total Sales</td><td style="text-align:right; font-weight:600;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format((float)($report['total_sales'] ?? $totalSalesValue), 2)) ?></td></tr>
                            <tr><td>Cash Received</td><td style="text-align:right;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format((float)($report['cash_amount'] ?? 0), 2)) ?></td></tr>
                            <tr><td>Mobile Money (MoMo)</td><td style="text-align:right;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format((float)($report['momo_amount'] ?? 0), 2)) ?></td></tr>
                            <tr style="background:#f0f9ff;"><td style="font-weight:600;">Total Collected</td><td style="text-align:right; font-weight:600;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format((float)($report['total_collected'] ?? 0), 2)) ?></td></tr>
                            <tr><td>Total Expenses</td><td style="text-align:right;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format((float)($report['expenses'] ?? 0), 2)) ?></td></tr>
                            <tr style="background:#f0f9ff;"><td style="font-weight:600;">Final Balance</td><td style="text-align:right; font-weight:600;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format((float)($report['final_balance'] ?? 0), 2)) ?></td></tr>
                            <tr style="background:#fff5e6; border-top:2px solid #e2e8f0;"><td style="font-weight:600;">Cash Difference</td><td style="text-align:right; font-weight:600;">
                                <?php 
                                    $diff = (float)($report['cash_difference'] ?? 0);
                                    if ($diff === 0) {
                                        echo '<span style="color:#10b981;">Balanced</span>';
                                    } elseif ($diff > 0) {
                                        echo '<span style="color:#10b981;">+' . esc($settings['currency'] ?? 'RWF') . ' ' . esc(number_format($diff, 2)) . '</span>';
                                    } else {
                                        echo '<span style="color:#dc2626;">' . esc($settings['currency'] ?? 'RWF') . ' ' . esc(number_format($diff, 2)) . '</span>';
                                    }
                                ?>
                            </td></tr>
                        </tbody>
                    </table>

                    <!-- Expense Details -->
                    <h3 style="margin-top:20px; margin-bottom:12px; font-size:15px;">Expense Details</h3>
                    <table class="report-table" style="margin-bottom:20px; font-size:13px;">
                        <thead style="background:#f0f9ff;">
                            <tr>
                                <th style="width:8%;">No</th>
                                <th style="width:60%;">Expense Description</th>
                                <th style="width:32%; text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($report['expense_items']) && is_array($report['expense_items'])): ?>
                                <?php $totalExpensesDetail = 0; foreach ($report['expense_items'] as $ei => $eitem): 
                                    $eAmount = (float)($eitem['amount'] ?? 0);
                                    $totalExpensesDetail += $eAmount;
                                ?>
                                    <tr><td><?= esc((string)($ei+1)) ?></td><td><?= esc($eitem['description'] ?? '-') ?></td><td style="text-align:right;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format($eAmount, 2)) ?></td></tr>
                                <?php endforeach; ?>
                                <tr style="background:#f3f4f6; font-weight:600;"><td colspan="2">Total Expenses</td><td style="text-align:right;"><?= esc($settings['currency'] ?? 'RWF') ?> <?= esc(number_format($totalExpensesDetail, 2)) ?></td></tr>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align:center; padding:16px; color:#64748b;">No expenses were submitted with this report</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Remarks (if available) -->
                    <?php if (!empty($report['remarks']) || !empty($report['comment'])): ?>
                        <h3 style="margin-top:20px; margin-bottom:12px; font-size:15px;">Remarks</h3>
                        <div style="padding:12px; background:#fef3c7; border:1px solid #fde68a; border-radius:6px; font-size:13px; color:#92400e;">
                            <?= esc($report['remarks'] ?? $report['comment'] ?? '') ?>
                        </div>
                    <?php endif; ?>

                    <!-- Signatures Section -->
                    <h3 style="margin-top:24px; margin-bottom:16px; font-size:15px;">Submission & Authorization</h3>
                    <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:24px; margin-bottom:28px;">
                        <div style="border-top:1px solid #d1d5db; padding-top:16px;">
                            <div style="font-size:13px; color:#64748b; margin-bottom:4px;">Prepared By (Employee)</div>
                            <div style="font-size:14px; font-weight:600; color:#0f172a;"><?= esc($report['employee_name'] ?? '-') ?></div>
                            <div style="margin-top:12px; font-size:12px; color:#64748b;">Employee Signature: _____________________</div>
                        </div>
                        <div style="border-top:1px solid #d1d5db; padding-top:16px;">
                            <div style="font-size:13px; color:#64748b; margin-bottom:4px;">Reviewed By (Administrator)</div>
                            <div style="font-size:14px; font-weight:600; color:#0f172a;"><?= esc($report['approved_by'] ?? 'Pending Review') ?></div>
                            <div style="margin-top:12px; font-size:12px; color:#64748b;">Review Signature: _____________________</div>
                        </div>
                    </div>

                    <!-- Footer Information -->
                    <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:16px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:13px;">
                        <div>
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Submission Date & Time</div>
                            <div style="font-weight:600; color:#0f172a;"><?= esc($report['created_at'] ?? date('Y-m-d H:i:s')) ?></div>
                        </div>
                        <div>
                            <div style="color:#64748b; font-size:12px; margin-bottom:4px;">Report Status</div>
                            <div style="font-weight:600; color:#0f172a;"><span class="status-badge <?= $badgeClass ?>"><?= esc($status) ?></span></div>
                        </div>
                    </div>

                    <!-- Admin Action Buttons -->
                    <div style="margin-top:28px; display:flex; flex-wrap:wrap; gap:8px; border-top:1px solid #e2e8f0; padding-top:16px;">
                        <button class="button" onclick="window.print()" style="background:#2563eb; color:#fff; padding:10px 16px; border:none; border-radius:6px; cursor:pointer; font-size:14px;">Print Report</button>
                        <button class="button" onclick="exportCurrentReportPdf()" style="background:#7c3aed; color:#fff; padding:10px 16px; border:none; border-radius:6px; cursor:pointer; font-size:14px;">Export PDF</button>
                        <button class="button" onclick="exportCurrentReportExcel()" style="background:#10b981; color:#fff; padding:10px 16px; border:none; border-radius:6px; cursor:pointer; font-size:14px;">Export Excel</button>
                        <?php if (isAdmin($user) && (($report['status'] ?? 'Submitted') === 'Submitted')): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="update_report" />
                                <input type="hidden" name="report_id" value="<?= esc($report['id'] ?? '') ?>" />
                                <input type="hidden" name="status" value="Approved" />
                                <button class="button" type="submit" style="background:#059669; color:#fff; padding:10px 16px; border:none; border-radius:6px; cursor:pointer; font-size:14px;">Approve Report</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="update_report" />
                                <input type="hidden" name="report_id" value="<?= esc($report['id'] ?? '') ?>" />
                                <input type="hidden" name="status" value="Rejected" />
                                <button class="button" type="submit" style="background:#dc2626; color:#fff; padding:10px 16px; border:none; border-radius:6px; cursor:pointer; font-size:14px;">Reject Report</button>
                            </form>
                        <?php else: ?>
                            <span style="padding:10px 16px; color:#666; font-size:14px; font-weight:600;">Status: <?= esc($status) ?></span>
                        <?php endif; ?>
                        <a class="button" href="?page=reports" style="background:#6b7280; color:#fff; padding:10px 16px; border:none; border-radius:6px; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block;">Back to Reports</a>
                    </div>
                </div>
                <script>
                    function exportCurrentReportPdf() {
                        const printHtml = document.querySelector('.report-printable').innerHTML;
                        const win = window.open('', '_blank');
                        if (win) { win.document.write('<html><head><title>Report</title></head><body>' + printHtml + '</body></html>'); win.document.close(); }
                    }
                    function exportCurrentReportExcel() { alert('Export to Excel (server-side) not implemented in this build.'); }
                </script>
                <?php } ?>
            <?php elseif ($page === 'submit_stock_report'): ?>
                <?php
                    $reportNumber = 'DSR-' . date('Ymd') . '-' . substr(md5($user['id'] ?? 'EMP'), 0, 5);
                    $branchName = $settings['branch_name'] ?? 'Main Branch';
                    $shiftName = $_GET['shift'] ?? 'Day Shift';
                    $stockTotals = ['sold' => 0, 'sales' => 0.0, 'remainingValue' => 0.0];
                ?>
                <div class="stock-report-sheet">
                    <div class="page-actions">
                        <button class="button" type="button" onclick="submitStockReport()">Submit Report</button>
                        <button class="button" type="button" onclick="printStockReport()">Print</button>
                        <button class="button" type="button" onclick="exportStockReportPdf()">Export PDF</button>
                        <button class="button" type="button" onclick="exportStockReportExcel()">Export Excel</button>
                    </div>
                    <div class="report-sheet-header">
                        <div class="report-sheet-company">
                            <div class="report-sheet-logo"><?= esc(substr($settings['business_name'] ?? 'BUSINESS', 0, 3)) ?></div>
                            <h1><?= esc($settings['business_name'] ?? 'Business Name') ?></h1>
                            <p><?= esc($settings['address'] ?? 'Business Address') ?></p>
                            <p>Tel: <?= esc($settings['phone'] ?? 'N/A') ?> | Email: <?= esc($settings['email'] ?? 'info@example.com') ?></p>
                        </div>
                        <div class="report-sheet-details">
                            <h2>Daily Stock Report</h2>
                            <table class="meta-table">
                                <tr><td>Report No:</td><td><?= esc($reportNumber) ?></td></tr>
                                <tr><td>Report Date:</td><td><input id="stock-report-date" type="date" value="<?= esc(date('Y-m-d')) ?>" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid #cbd5e1;" /></td></tr>
                                <tr><td>Employee Name:</td><td><?= esc($user['name'] ?? 'Unknown') ?></td></tr>
                                <tr><td>Branch:</td><td><?= esc($branchName) ?></td></tr>
                                <tr><td>Shift:</td><td><?= esc($shiftName) ?></td></tr>
                            </table>
                        </div>
                    </div>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Product Name</th>
                                <th>Opening Stock</th>
                                <th>Stock Received</th>
                                <th>Total Stock Available</th>
                                <th>Quantity Sold</th>
                                <th>Remaining Stock</th>
                                <th>Unit Price</th>
                                <th>Total Sales Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stockReportItems as $index => $item):
                                $opening = (int)($item['opening'] ?? 0);
                                $stockIn = (int)($item['added'] ?? 0);
                                $remaining = (int)($item['remaining'] ?? 0);
                                $price = (float)($item['price'] ?? 0);
                                $totalAvailable = $opening + $stockIn;
                                $sold = max(0, $totalAvailable - $remaining);
                                $salesValue = $sold * $price;
                            ?>
                                <tr data-index="<?= esc((string)$index) ?>" data-product-id="<?= esc($item['id'] ?? '') ?>" data-opening="<?= esc((string)$opening) ?>" data-price="<?= esc(number_format($price, 2, '.', '')) ?>">
                                    <td><?= esc((string)($index + 1)) ?></td>
                                    <td><?= esc($item['product_name'] ?? 'Unknown') ?></td>
                                    <td><?= esc((string)$opening) ?></td>
                                    <td><input class="stock-input stock-received" type="number" min="0" step="1" value="<?= esc((string)$stockIn) ?>" data-index="<?= esc((string)$index) ?>" /></td>
                                    <td><span class="row-total-stock" data-index="<?= esc((string)$index) ?>"><?= esc((string)$totalAvailable) ?></span></td>
                                    <td><span class="row-sold" data-index="<?= esc((string)$index) ?>"><?= esc((string)$sold) ?></span></td>
                                    <td><input class="stock-input stock-remaining" type="number" min="0" step="1" value="<?= esc((string)$remaining) ?>" data-index="<?= esc((string)$index) ?>" /></td>
                                    <td><?= esc(number_format($price, 2)) ?></td>
                                    <td><span class="row-sales-value" data-index="<?= esc((string)$index) ?>"><?= esc(number_format($salesValue, 2)) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5">Total Products: <span id="stock-total-products"><?= esc((string)count($stockReportItems)) ?></span></td>
                                <td><span id="stock-total-sold">0</span></td>
                                <td><span id="stock-total-remaining">0</span></td>
                                <td>Total Sales:</td>
                                <td><span id="stock-total-sales"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></td>
                            </tr>
                            <tr>
                                <td colspan="8" style="text-align:right;">Remaining Stock Value</td>
                                <td><span id="stock-remaining-value"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="reconciliation-card">
                        <h3>Daily Cash Reconciliation</h3>
                        <div class="reconciliation-grid">
                            <div class="form-row"><label>Total Sales</label><div class="summary-field"><span id="stock-total-sales-card"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div></div>
                            <div class="form-row"><label>Cash Received</label><input id="stock-cash" type="number" min="0" step="0.01" value="0" /></div>
                            <div class="form-row"><label>Mobile Money (MoMo)</label><input id="stock-momo" type="number" min="0" step="0.01" value="0" /></div>
                            <div class="form-row" style="grid-column:1/-1;">
                                <label>Expenses</label>
                                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:12px;">
                                    <table id="expense-table" style="width:100%; border-collapse:collapse;">
                                        <thead><tr><th style="text-align:left; padding:8px;">Amount</th><th style="text-align:left; padding:8px;">Description</th><th style="padding:8px; width:80px;"></th></tr></thead>
                                        <tbody></tbody>
                                    </table>
                                    <div style="display:flex; gap:8px; margin-top:8px;">
                                        <button type="button" class="button" id="add-expense">Add Expense</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="reconciliation-summary">
                            <div class="summary-block"><strong>Total Sales</strong><span id="stock-total-sales-card-summary"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div>
                            <div class="summary-block"><strong>Cash</strong><span id="stock-total-cash"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div>
                            <div class="summary-block"><strong>MoMo</strong><span id="stock-total-momo"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div>
                            <div class="summary-block"><strong>Expenses</strong><span id="stock-total-expenses"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div>
                            <div class="summary-block"><strong>Total Collected</strong><span id="stock-total-collected"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div>
                            <div class="summary-block"><strong>Final Balance</strong><span id="stock-final-balance"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div>
                            <div class="summary-block"><strong>Cash Difference</strong><span id="stock-cash-difference" style="font-weight:700;"><?= esc($settings['currency'] ?? 'RWF') ?> 0.00</span></div>
                        </div>
                        <div class="reconciliation-warning" id="stock-difference-warning"></div>
                        <div class="signature-grid">
                            <div class="signature-block">Prepared By: <?= esc($user['name'] ?? 'Employee') ?><span>Employee</span></div>
                            <div class="signature-block">Employee Signature: __________________<span>Signature</span></div>
                            <div class="signature-block">Verified By: __________________<span>Manager</span></div>
                            <div class="signature-block">Approved By: __________________<span>Administrator</span></div>
                            <div class="signature-block">Date & Time: <?= esc(date('Y-m-d H:i')) ?><span>Timestamp</span></div>
                        </div>
                        <div style="margin-top:18px; display:flex; flex-direction:column; align-items:center;">
                            <button id="submit-stock-button" class="button" type="button" onclick="submitStockReport()" style="background:#16a34a; box-shadow:0 12px 28px rgba(22,163,74,0.18); max-width:360px; font-size:16px; padding:14px 22px;">Submit Daily Report</button>
                            <div id="stock-date-status" class="reconciliation-warning" style="margin-top:12px;"></div>
                            <div id="stock-submit-status" role="status" aria-live="polite"></div>
                        </div>
                    </div>
                </div>
                <script>
                    const stockCurrency = '<?= esc($settings['currency'] ?? 'RWF') ?>';
                    let stockReportDate = '<?= esc(date('Y-m-d')) ?>';

                    function parseIntValue(value) {
                        const parsed = parseInt(value, 10);
                        return Number.isNaN(parsed) ? 0 : Math.max(0, parsed);
                    }

                    function parseFloatValue(value) {
                        const parsed = parseFloat(value);
                        return Number.isNaN(parsed) ? 0 : Math.max(0, parsed);
                    }

                    function formatMoney(value) {
                        return `${stockCurrency} ${value.toFixed(2)}`;
                    }

                    function updateStockRow(index) {
                        const row = document.querySelector(`tr[data-index="${index}"]`);
                        if (!row) return { sold: 0, remaining: 0, totalSalesValue: 0 };

                        const opening = parseIntValue(row.dataset.opening);
                        const price = parseFloatValue(row.dataset.price);
                        const receivedInput = row.querySelector('.stock-received');
                        const remainingInput = row.querySelector('.stock-remaining');
                        const received = parseIntValue(receivedInput.value);
                        const remaining = parseIntValue(remainingInput.value);
                        const total = opening + received;
                        const sold = Math.max(0, total - remaining);
                        const salesValue = sold * price;

                        row.querySelector('.row-total-stock').textContent = total;
                        row.querySelector('.row-sold').textContent = sold;
                        row.querySelector('.row-sales-value').textContent = salesValue.toFixed(2);

                        if (remaining > total) {
                            remainingInput.classList.add('invalid-input');
                        } else {
                            remainingInput.classList.remove('invalid-input');
                        }

                        return { sold, remaining, salesValue, total };
                    }

                    function getCurrentTotalSales() {
                        const text = document.getElementById('stock-total-sales').textContent.replace(/[^0-9.\-]/g, '');
                        return parseFloat(text) || 0;
                    }

                    function updateStockReportTotals() {
                        let totalSold = 0;
                        let totalRemaining = 0;
                        let totalSales = 0;
                        let totalRemainingValue = 0;

                        document.querySelectorAll('.report-table tbody tr').forEach(row => {
                            const index = row.dataset.index;
                            const result = updateStockRow(index);
                            const remaining = parseIntValue(row.querySelector('.stock-remaining').value);
                            const price = parseFloatValue(row.dataset.price);

                            totalSold += result.sold;
                            totalRemaining += remaining;
                            totalSales += result.salesValue;
                            totalRemainingValue += remaining * price;
                        });

                        document.getElementById('stock-total-sold').textContent = totalSold;
                        document.getElementById('stock-total-remaining').textContent = totalRemaining;
                        document.getElementById('stock-total-sales').textContent = formatMoney(totalSales);
                        document.getElementById('stock-total-sales-card').textContent = formatMoney(totalSales);
                        document.getElementById('stock-total-sales-card-summary').textContent = formatMoney(totalSales);
                        document.getElementById('stock-remaining-value').textContent = formatMoney(totalRemainingValue);

                        updateStockReconciliation();
                    }

                    function addExpenseRow(amount = '', description = '') {
                        const tbody = document.querySelector('#expense-table tbody');
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td style="padding:8px;"><input class="expense-amount" type="number" min="0" step="0.01" value="${amount}" style="width:100%; padding:8px; border-radius:8px; border:1px solid #e5e7eb;"/></td>
                            <td style="padding:8px;"><input class="expense-desc" type="text" value="${description}" placeholder="Description" style="width:100%; padding:8px; border-radius:8px; border:1px solid #e5e7eb;"/></td>
                            <td style="padding:8px; text-align:center;"><button type="button" class="button button--danger remove-expense" style="max-width:72px; padding:8px 10px;">Remove</button></td>
                        `;
                        tbody.appendChild(row);
                        const amtInput = row.querySelector('.expense-amount');
                        const remBtn = row.querySelector('.remove-expense');
                        amtInput.addEventListener('input', updateStockReconciliation);
                        remBtn.addEventListener('click', () => { row.remove(); updateStockReconciliation(); });
                        updateStockReconciliation();
                    }

                    function calculateTotalExpenses() {
                        let total = 0;
                        document.querySelectorAll('.expense-amount').forEach(el => {
                            total += parseFloatValue(el.value);
                        });
                        return total;
                    }

                    function validateSelectedReportDate() {
                        const dateInput = document.getElementById('stock-report-date');
                        const statusEl = document.getElementById('stock-date-status');
                        if (!dateInput || !statusEl) return true;

                        const selectedValue = dateInput.value;
                        if (!selectedValue) {
                            statusEl.textContent = 'Please choose a report date.';
                            statusEl.classList.add('show');
                            dateInput.classList.add('invalid-input');
                            return false;
                        }

                        const selectedDate = new Date(`${selectedValue}T00:00:00`);
                        const today = new Date();
                        today.setHours(0,0,0,0);
                        const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);

                        if (selectedDate > today) {
                            statusEl.textContent = 'Employees cannot submit reports for future dates.';
                            statusEl.classList.add('show');
                            dateInput.classList.add('invalid-input');
                            return false;
                        }

                        if (selectedDate < monthStart) {
                            statusEl.textContent = 'Employees can only submit reports for the current month.';
                            statusEl.classList.add('show');
                            dateInput.classList.add('invalid-input');
                            return false;
                        }

                        statusEl.textContent = '';
                        statusEl.classList.remove('show');
                        dateInput.classList.remove('invalid-input');
                        stockReportDate = selectedValue;
                        return true;
                    }

                    function updateStockReconciliation() {
                        const cash = parseFloatValue(document.getElementById('stock-cash').value);
                        const momo = parseFloatValue(document.getElementById('stock-momo').value);
                        const totalExpenses = calculateTotalExpenses();
                        const totalCollected = cash + momo;
                        const totalSales = getCurrentTotalSales();
                        const finalBalance = totalCollected - totalExpenses;
                        const cashDifference = totalCollected - totalSales;

                        document.getElementById('stock-total-cash').textContent = formatMoney(cash);
                        document.getElementById('stock-total-momo').textContent = formatMoney(momo);
                        document.getElementById('stock-total-expenses').textContent = formatMoney(totalExpenses);
                        document.getElementById('stock-total-collected').textContent = formatMoney(totalCollected);
                        document.getElementById('stock-final-balance').textContent = formatMoney(finalBalance);
                        
                        // Update Cash Difference display
                        const diffDisplay = document.getElementById('stock-cash-difference');
                        if (diffDisplay) {
                            if (cashDifference === 0) {
                                diffDisplay.textContent = 'Balanced';
                                diffDisplay.style.color = '#10b981';
                                diffDisplay.style.fontWeight = '700';
                            } else if (cashDifference > 0) {
                                diffDisplay.textContent = '+' + formatMoney(cashDifference);
                                diffDisplay.style.color = '#10b981';
                                diffDisplay.style.fontWeight = '700';
                            } else {
                                diffDisplay.textContent = formatMoney(cashDifference);
                                diffDisplay.style.color = '#dc2626';
                                diffDisplay.style.fontWeight = '700';
                            }
                        }
                    }

                    function markFieldInvalid(field, isInvalid) {
                        if (!field) return;
                        field.classList.toggle('invalid-input', isInvalid);
                    }

                    function validateReconciliation() {
                        let valid = true;
                        const cashInput = document.getElementById('stock-cash');
                        const momoInput = document.getElementById('stock-momo');

                        // Validate Cash and MoMo are valid numeric values (no equality requirement)
                        [cashInput, momoInput].forEach(input => {
                            const value = parseFloat(input.value);
                            const invalid = Number.isNaN(value) || value < 0;
                            markFieldInvalid(input, invalid);
                            valid = valid && !invalid;
                        });

                        // Validate expense amounts are valid numeric values
                        document.querySelectorAll('.expense-amount').forEach(input => {
                            const value = parseFloat(input.value);
                            const invalid = Number.isNaN(value) || value < 0;
                            markFieldInvalid(input, invalid);
                            valid = valid && !invalid;
                        });

                        return valid;
                    }

                    async function submitStockReport() {
                        if (!validateSelectedReportDate()) {
                            alert('Please choose a valid report date for the current month.');
                            return;
                        }

                        if (!validateReconciliation()) {
                            alert('Please ensure all reconciliation fields contain valid numeric values.');
                            return;
                        }

                        const rows = Array.from(document.querySelectorAll('.report-table tbody tr')).map(row => {
                            const productId = row.dataset.productId || '';
                            const openingStock = parseIntValue(row.dataset.opening);
                            const stockIn = parseIntValue(row.querySelector('.stock-received').value);
                            const remainingStock = parseIntValue(row.querySelector('.stock-remaining').value);
                            return {
                                product_id: productId,
                                opening_stock: openingStock,
                                stock_in: stockIn,
                                remaining_stock: remainingStock
                            };
                        });

                        const cash = parseFloatValue(document.getElementById('stock-cash').value);
                        const momo = parseFloatValue(document.getElementById('stock-momo').value);
                        const totalSales = getCurrentTotalSales();
                        const totalCollected = cash + momo;
                        const cashDifference = totalCollected - totalSales;
                        const expenseItems = Array.from(document.querySelectorAll('#expense-table tbody tr')).map(r => ({
                            amount: parseFloatValue(r.querySelector('.expense-amount').value),
                            description: (r.querySelector('.expense-desc').value || '').toString()
                        }));
                        const expensesTotal = expenseItems.reduce((s, e) => s + (parseFloat(e.amount) || 0), 0);

                        const payload = {
                            date: stockReportDate,
                            rows,
                            reconciliation: {
                                total_sales: totalSales,
                                cash_amount: cash,
                                momo_amount: momo,
                                total_collected: totalCollected,
                                cash_difference: cashDifference,
                                expenses: expensesTotal,
                                expense_items: expenseItems,
                                comment: ''
                            }
                        };

                        try {
                            const response = await fetch('/api/daily-stock/submit-bundle', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(payload)
                            });

                            if (!response.ok) {
                                const errorData = await response.json();
                                throw new Error(errorData.error || 'Failed to save the report');
                            }

                            await response.json();
                            alert('Stock report and reconciliation saved successfully.');
                        } catch (error) {
                            alert('Unable to save report: ' + (error.message || error));
                        }
                    }

                    document.querySelectorAll('.stock-input').forEach(input => {
                        input.addEventListener('input', updateStockReportTotals);
                    });

                    const reportDateInput = document.getElementById('stock-report-date');
                    if (reportDateInput) {
                        reportDateInput.addEventListener('change', validateSelectedReportDate);
                        reportDateInput.addEventListener('input', validateSelectedReportDate);
                    }

                    ['stock-cash', 'stock-momo'].forEach(id => {
                        const input = document.getElementById(id);
                        if (input) input.addEventListener('input', updateStockReconciliation);
                    });

                    // expense table actions
                    document.getElementById('add-expense').addEventListener('click', () => addExpenseRow());
                    // start with one empty expense row
                    addExpenseRow();

                    updateStockReportTotals();
                    updateStockReconciliation();
                    validateSelectedReportDate();
                </script>
            <?php elseif ($page === 'my_reports'): ?>
                <div class="card">
                    <h2>My Reports</h2>
                    <p>Showing only your daily reports from the last 30 days.</p>
                    <table class="reports-table">
                        <thead><tr><th>Report Date</th></tr></thead>
                        <tbody>
                            <?php if (count($employeeReports) === 0): ?>
                                <tr><td style="text-align:center; color:#64748b; padding:24px;">No reports were submitted by you in the last 30 days.</td></tr>
                            <?php else: ?>
                                <?php foreach ($employeeReports as $report): ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                                                <span><?= esc($report['date'] ?? '') ?></span>
                                                <a class="button" href="?page=report&id=<?= esc($report['id'] ?? '') ?>">View Report</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($page === 'my_sales'): ?>
                <div class="card">
                    <h2>My Sales</h2>
                    <div class="summary-grid">
                        <div class="metric-card"><strong>Sales Today</strong><span><?= esc(number_format((float)array_sum(array_map(fn($sale) => (float)($sale['total_amount'] ?? 0), $employeeSalesToday)), 2)) ?></span></div>
                        <div class="metric-card"><strong>Sales Count</strong><span><?= esc((string)count($employeeSales)) ?></span></div>
                    </div>
                    <table>
                        <thead><tr><th>Sale ID</th><th>Total</th><th>Paid</th><th>Payment</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($employeeSales as $sale): ?>
                                <tr>
                                    <td><?= esc($sale['id'] ?? '') ?></td>
                                    <td><?= esc(number_format((float)($sale['total_amount'] ?? 0), 2)) ?></td>
                                    <td><?= esc(number_format((float)($sale['amount_paid'] ?? 0), 2)) ?></td>
                                    <td><?= esc($sale['payment_type'] ?? '') ?></td>
                                    <td><?= esc($sale['created_at'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($page === 'my_expenses'): ?>
                <div class="card">
                    <h2>My Expenses</h2>
                    <div class="summary-grid">
                        <div class="metric-card"><strong>Total Expenses</strong><span><?= esc(number_format((float)array_sum(array_map(fn($exp) => (float)($exp['amount'] ?? 0), $employeeExpenses)), 2)) ?></span></div>
                        <div class="metric-card"><strong>Expense Items</strong><span><?= esc((string)count($employeeExpenses)) ?></span></div>
                    </div>
                    <table>
                        <thead><tr><th>Title</th><th>Category</th><th>Amount</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($employeeExpenses as $expense): ?>
                                <tr>
                                    <td><?= esc($expense['title'] ?? '') ?></td>
                                    <td><?= esc($expense['category'] ?? '') ?></td>
                                    <td><?= esc(number_format((float)($expense['amount'] ?? 0), 2)) ?></td>
                                    <td><?= esc($expense['date'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($page === 'profile'): ?>
                <div class="card">
                    <h2>Profile</h2>
                    <table>
                        <tr><th>Name</th><td><?= esc($user['name'] ?? $user['username']) ?></td></tr>
                        <tr><th>Username</th><td><?= esc($user['username'] ?? '') ?></td></tr>
                        <tr><th>Role</th><td><?= esc(ucfirst($user['role'] ?? '')) ?></td></tr>
                        <tr><th>Email</th><td><?= esc($profileEmail) ?></td></tr>
                        <tr><th>Phone</th><td><?= esc($profilePhone) ?></td></tr>
                    </table>
                </div>
                <div class="card" id="edit-profile">
                    <h3>Edit Profile</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="update_profile" />
                        <div class="form-row"><label>Full Name</label><input type="text" name="profile_name" value="<?= esc($user['name'] ?? $user['username'] ?? '') ?>" required /></div>
                        <div class="form-row"><label>Email</label><input type="email" name="profile_email" value="<?= esc($profileEmail) ?>" /></div>
                        <div class="form-row"><label>Phone</label><input type="text" name="profile_phone" value="<?= esc($profilePhone) ?>" /></div>
                        <button class="button" type="submit">Save Profile</button>
                    </form>
                </div>
            <?php elseif ($page === 'change-password'): ?>
                <div class="card">
                    <h2>Change Password</h2>
                    <form method="post">
                        <input type="hidden" name="action" value="change_password" />
                        <div class="form-row"><label>Current Password</label><input type="password" name="current_password" required /></div>
                        <div class="form-row"><label>New Password</label><input type="password" name="new_password" required /></div>
                        <div class="form-row"><label>Confirm Password</label><input type="password" name="confirm_password" required /></div>
                        <button class="button">Update Password</button>
                    </form>
                </div>
            <?php elseif ($page === 'access_denied'): ?>
                <div class="card">
                    <h2>Access Denied</h2>
                    <p>You do not have permission to view this page. Please use the menu to navigate to pages allowed for your role.</p>
                </div>
            <?php elseif ($page === 'expenses'): ?>
                <div class="card">
                    <h2>Expenses</h2>
                    <table>
                        <thead><tr><th>Title</th><th>Category</th><th>Amount</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                                <tr>
                                    <td><?= esc($expense['title']) ?></td>
                                    <td><?= esc($expense['category'] ?? '') ?></td>
                                    <td><?= esc(number_format((float)$expense['amount'], 2)) ?></td>
                                    <td><?= esc($expense['date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($page === 'employees' && isAdmin($user)): ?>
                <div class="card">
                    <h2>Employees</h2>
                    <table>
                        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Salary</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <tr>
                                    <td><?= esc($emp['name']) ?></td>
                                    <td><?= esc($emp['email'] ?? '') ?></td>
                                    <td><?= esc($emp['phone'] ?? '') ?></td>
                                    <td><?= esc(number_format((float)$emp['salary'], 2)) ?></td>
                                    <td><?= esc($emp['status'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($page === 'settings' && isAdmin($user)): ?>
                <div class="card">
                    <h2>System Settings</h2>
                    <form method="post">
                        <input type="hidden" name="action" value="update_settings" />
                        <div class="form-row"><label>Business Name</label><input type="text" name="business_name" value="<?= esc($settings['business_name'] ?? '') ?>" required /></div>
                        <div class="form-row"><label>Phone</label><input type="text" name="phone" value="<?= esc($settings['phone'] ?? '') ?>" /></div>
                        <div class="form-row"><label>Address</label><input type="text" name="address" value="<?= esc($settings['address'] ?? '') ?>" /></div>
                        <div class="form-row"><label>Currency</label><input type="text" name="currency" value="<?= esc($settings['currency'] ?? '') ?>" /></div>
                        <div class="form-row"><label>Tax Rate (%)</label><input type="number" step="0.01" name="tax_rate" value="<?= esc((string)((float)($settings['tax_rate'] ?? 0))) ?>" /></div>
                        <div class="form-row"><label>Receipt Footer</label><textarea name="receipt_footer"><?= esc($settings['receipt_footer'] ?? '') ?></textarea></div>
                        <button class="button" type="submit">Save Settings</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card"><p>Page not available.</p></div>
            <?php endif; ?>
        </main>
    </div>
<?php endif; ?>
<script>
    // Reports Dashboard Functions
    let allReports = [];
    const CURRENCY = '<?= esc($settings['currency'] ?? 'RWF') ?>';
    const pageSize = 10;
    let currentPage = 1;

    function formatMoney(value) {
        return `${CURRENCY} ${parseFloat(value || 0).toFixed(2)}`;
    }

    function getCashDifferenceDisplay(diff) {
        if (diff === 0) {
            return '<span style="color:#10b981; font-weight:600;">Balanced</span>';
        } else if (diff > 0) {
            return `<span style="color:#10b981; font-weight:600;">+${formatMoney(diff)}</span>`;
        } else {
            return `<span style="color:#dc2626; font-weight:600;">${formatMoney(diff)}</span>`;
        }
    }

    async function loadReports() {
        try {
            const startDate = document.getElementById('filter-start')?.value || '';
            const endDate = document.getElementById('filter-end')?.value || '';
            const search = document.getElementById('filter-search')?.value || '';
            const employeeId = document.getElementById('filter-employee')?.value || '';
            const status = document.getElementById('filter-status')?.value || '';
            const cashDiff = document.getElementById('filter-cash-difference')?.value || '';

            const params = new URLSearchParams();
            if (startDate) params.append('startDate', startDate);
            if (endDate) params.append('endDate', endDate);
            if (search) params.append('search', search);
            if (employeeId) params.append('employeeId', employeeId);
            if (status) params.append('status', status);
            if (cashDiff) params.append('cashDifference', cashDiff);

            const response = await fetch(`/api/reports/daily?${params.toString()}`);
            if (!response.ok) throw new Error('Failed to fetch reports');
            
            const data = await response.json();
            allReports = data.reports || [];
            currentPage = 1;
            renderReports();
        } catch (error) {
            console.error('Error loading reports:', error);
            document.getElementById('report-cards').innerHTML = '<div style="padding:20px; text-align:center; color:#dc2626;">Failed to load reports. Please try again.</div>';
        }
    }

function renderReports() {
        const container = document.getElementById('report-cards');
        if (!container) return;

        if (allReports.length === 0) {
            container.innerHTML = '<div style="padding:20px; text-align:center; color:#666;">No reports found.</div>';
            document.getElementById('reports-count').textContent = '0 reports';
            renderPagination();
            return;
        }

        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        const pageReports = allReports.slice(start, end);

        const reportRows = pageReports.map(report => {
            const statusClass = `report-list-status-${(report.status || 'Submitted').toLowerCase()}`;
            const dateObj = new Date(report.date || '');
            const formattedDate = dateObj.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
            const isAdmin = document.querySelector('select#filter-employee') ? true : false;
            
            return `
                <div class="report-list-item" data-id="${report.id}">
                    <div class="report-list-info">
                        <span class="report-list-title">Daily Stock Report</span>
                        <span class="report-list-date">${formattedDate}</span>
                        ${isAdmin ? `<span class="report-list-employee">${report.employee_name || 'Unknown'}</span>` : ''}
                        <span class="report-list-status ${statusClass}">${report.status || 'Submitted'}</span>
                    </div>
                    <div class="report-list-actions">
                        <a href="?page=report&id=${report.id}">View Report</a>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = reportRows;
        document.getElementById('reports-count').textContent = `${allReports.length} reports`;
        renderPagination();
    }

    function renderPagination() {
        const container = document.getElementById('pagination');
        if (!container) return;

        const totalPages = Math.ceil(allReports.length / pageSize);
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '';
        for (let i = 1; i <= totalPages; i++) {
            html += `<button onclick="currentPage=${i}; renderReports();" style="margin:0 4px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; background:${i === currentPage ? '#2563eb' : '#fff'}; color:${i === currentPage ? '#fff' : '#000'}; cursor:pointer;">${i}</button>`;
        }
        container.innerHTML = html;
    }

    async function updateReportStatus(reportId, status) {
        try {
            const response = await fetch(`/api/reports/daily/${reportId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status })
            });
            if (response.ok) {
                loadReports();
            } else {
                alert('Failed to update report status');
            }
        } catch (error) {
            console.error('Error updating report:', error);
            alert('Failed to update report status');
        }
    }

    function applyFilters() {
        loadReports();
    }

    function resetFilters() {
        document.getElementById('filter-range').value = 'all';
        document.getElementById('filter-start').value = '';
        document.getElementById('filter-end').value = '';
        document.getElementById('filter-search').value = '';
        if (document.getElementById('filter-employee')) document.getElementById('filter-employee').value = '';
        document.getElementById('filter-status').value = '';
        document.getElementById('filter-cash-difference').value = '';
        loadReports();
    }

    // Load reports when dashboard page is loaded
    if (document.getElementById('report-cards')) {
        loadReports();
    }
</script>
</body>
</html>
