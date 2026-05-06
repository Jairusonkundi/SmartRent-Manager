<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$page = max(1, (int) ($_GET['page'] ?? 1));
$requestedLimit = (string) ($_GET['limit'] ?? '10');
$isAllLimit = $requestedLimit === 'all';
$limit = $isAllLimit ? 10 : (int) $requestedLimit;
$allowedLimits = [5, 10, 15, 20];
if (!$isAllLimit && !in_array($limit, $allowedLimits, true)) {
    $limit = 10;
}
$offset = $isAllLimit ? 0 : ($page - 1) * $limit;
$search = trim((string) ($_GET['search'] ?? ''));
$propertyFilter = (string) ($_GET['property_id'] ?? ($_SESSION['global_property_filter'] ?? 'all'));
$_SESSION['global_property_filter'] = $propertyFilter;
$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['Paid', 'Partial', 'Unpaid'];
$dateFrom = (string) ($_GET['date_from'] ?? '');
$dateTo = (string) ($_GET['date_to'] ?? '');

if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(t.name LIKE ? OR u.unit_number LIKE ? OR pr.name LIKE ?)';
    $searchParam = '%' . $search . '%';
    array_push($params, $searchParam, $searchParam, $searchParam);
}

if ($propertyFilter !== 'all') {
    $where[] = 'pr.id = ?';
    array_push($params, (int) $propertyFilter);
}

if ($statusFilter !== 'all') {
    $where[] = "(CASE
        WHEN p.amount_paid >= p.amount_expected THEN 'Paid'
        WHEN p.amount_paid > 0 THEN 'Partial'
        ELSE 'Unpaid'
    END) = ?";
    array_push($params, $statusFilter);
}

if ($dateFrom !== '') {
    $where[] = 'p.payment_date >= ?';
    array_push($params, $dateFrom);
}

if ($dateTo !== '') {
    $where[] = 'p.payment_date <= ?';
    array_push($params, $dateTo);
}

$whereSql = implode(' AND ', $where);
$baseFrom = "
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
    JOIN units u ON u.id = l.unit_id
    JOIN properties pr ON pr.id = u.property_id
    WHERE {$whereSql}
";

$countSql = "SELECT COUNT(*) {$baseFrom}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();

$paymentsSql = "SELECT
        p.*,
        t.name,
        pr.name AS property_name,
        u.unit_number,
        CASE
            WHEN p.amount_paid >= p.amount_expected THEN 'Paid'
            WHEN p.amount_paid > 0 THEN 'Partial'
            ELSE 'Unpaid'
        END AS payment_status
    {$baseFrom}
    ORDER BY p.payment_date DESC, p.id DESC";

if (!$isAllLimit) {
    $paymentsSql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
}

$paymentsStmt = $pdo->prepare($paymentsSql);
$paymentsStmt->execute($params);
$recentPayments = $paymentsStmt->fetchAll();

$currentCount = count($recentPayments);
$paginationLimit = $isAllLimit ? max(1, $totalRecords) : $limit;
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
    'status' => $statusFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);

renderHeader('Payments');
?>
<section class="card payments-audit-log">
    <h3>Recent Payments</h3>
    <p class="section-help">Imported Excel payment records are shown below as the primary audit log. Status is calculated from expected rent versus imported amount paid so partial or missing payments reconcile with arrears.</p>
    <form method="get" id="paymentsFilters" class="control-bar filter-form">
        <label>Limit
            <select name="limit">
                <?php foreach ([5, 10, 15, 20] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= !$isAllLimit && $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option>
                <?php endforeach; ?>
                <option value="all" <?= $isAllLimit ? 'selected' : '' ?>>All</option>
            </select>
        </label>
        <label>Search
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Tenant, property, or unit">
        </label>
        <label>Property
            <select name="property_id">
                <option value="all" <?= $propertyFilter === 'all' ? 'selected' : '' ?>>All Properties</option>
                <?php foreach ($properties as $property): ?>
                    <option value="<?= (int) $property['id'] ?>" <?= $propertyFilter === (string) $property['id'] ? 'selected' : '' ?>>
                        <?= h($property['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Date From
            <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
        </label>
        <label>Date To
            <input type="date" name="date_to" value="<?= h($dateTo) ?>">
        </label>
        <label>Status
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit">Search</button>
            <button type="button" class="button" onclick="resetFilters('paymentsFilters','/public/payments.php')">Clear Filters</button>
        </div>
    </form>
    <p>Showing <?= $currentCount ?> records | Total Found: <?= $totalRecords ?></p>
    <?php if ($totalRecords === 0): ?>
        <div class="alert">Welcome! Please upload your CSV to begin.</div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="sortable payments-audit-table">
        <thead><tr><th>#</th><th>Tenant</th><th>Property</th><th>Unit</th><th>Amount</th><th>Payment Date</th><th>Month</th><th>Payment Status</th></tr></thead>
        <tbody>
            <?php foreach ($recentPayments as $index => $p): ?>
                <?php $rowNumber = $offset + $index + 1; ?>
                <tr>
                    <td><?= $rowNumber ?></td>
                    <td><?= h($p['name']) ?></td>
                    <td><?= h($p['property_name']) ?></td>
                    <td><?= h($p['unit_number']) ?></td>
                    <td><?= 'KSh ' . number_format((float) $p['amount_paid'], 2, '.', ',') ?></td>
                    <td><?= $p['payment_date'] ? h($p['payment_date']) : '-' ?></td>
                    <td><?= date('M Y', strtotime($p['billing_month'] . '-01')) ?></td>
                    <td><span class="badge <?= h(strtolower((string) $p['payment_status'])) ?>"><?= h($p['payment_status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?= $paginationHtml ?>
</section>
<?php renderFooter(); ?>
