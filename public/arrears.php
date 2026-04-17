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
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['Paid', 'Partial', 'Unpaid'];

if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$where = ['1=1', 'rs.month <= CURRENT_DATE'];
$params = [];
$amountPaidExpr = 'COALESCE(SUM(p.amount_paid), 0)';
$monthlyRentExpr = 'COALESCE(MAX(p.monthly_rent), rs.expected_rent)';
$balanceExpr = "({$monthlyRentExpr} - {$amountPaidExpr})";
$having = ["{$balanceExpr} > 0"];
$rentStatusExpr = "CASE
            WHEN {$balanceExpr} <= 0 THEN 'Paid'
            WHEN {$amountPaidExpr} = 0 THEN 'Unpaid'
            ELSE 'Partial'
       END";

if ($search !== '') {
    $where[] = '(t.name LIKE :search OR u.unit_number LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($propertyFilter !== 'all') {
    $where[] = 'pr.id = :property_id';
    $params['property_id'] = (int) $propertyFilter;
}

if ($statusFilter !== 'all') {
    $having[] = "{$rentStatusExpr} = :status";
    $params['status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);
$havingSql = implode(' AND ', $having);

$baseFrom = "
FROM rent_schedule rs
JOIN tenants t ON t.id = rs.tenant_id
JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
JOIN units u ON u.id = l.unit_id
JOIN properties pr ON pr.id = u.property_id
LEFT JOIN payments p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
WHERE {$whereSql}
GROUP BY rs.id, t.name, rs.month, rs.due_date, rs.expected_rent, u.unit_number, pr.name
HAVING {$havingSql}
";

$countSql = "SELECT COUNT(*) FROM (SELECT rs.id {$baseFrom}) AS sub";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();

$sql = "
SELECT t.name,
       rs.month,
       rs.due_date,
       {$monthlyRentExpr} AS expected_rent,
       u.unit_number,
       pr.name AS property_name,
       {$amountPaidExpr} AS paid,
       {$balanceExpr} AS balance,
       {$rentStatusExpr} AS rent_status,
       CASE WHEN COALESCE(MAX(p.payment_date), '9999-12-31') > rs.due_date THEN 'Late' ELSE 'On Time' END AS timing_status
{$baseFrom}
ORDER BY balance DESC, rs.month ASC
";

if (!$isAllLimit) {
    $sql .= 'LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$arrears = $stmt->fetchAll();

$currentCount = count($arrears);
$paginationLimit = $isAllLimit ? max(1, $totalRecords) : $limit;
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
    'status' => $statusFilter,
]);

renderHeader('Arrears');
?>
<section class="card">
    <h3>Outstanding Accounts & High-Risk Tenants</h3>
    <form method="get" class="control-bar">
        <label>Limit
            <select name="limit">
                <?php foreach ([5, 10, 15, 20] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= !$isAllLimit && $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option>
                <?php endforeach; ?>
                <option value="all" <?= $isAllLimit ? 'selected' : '' ?>>All</option>
            </select>
        </label>
        <label>Search
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Tenant or unit number">
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
        <label>Status
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Apply</button>
        <a class="button" href="/public/arrears.php">Clear Filters</a>
    </form>
    <p>Showing <?= $currentCount ?> records | Total Found: <?= $totalRecords ?></p>
    <table class="sortable">
        <thead><tr><th>#</th><th>Tenant</th><th>Property</th><th>Unit</th><th>Month</th><th>Expected</th><th>Paid</th><th>Balance</th><th>Status</th><th>Timing</th></tr></thead>
        <tbody>
            <?php foreach ($arrears as $index => $row): ?>
                <?php $rowNumber = $offset + $index + 1; ?>
                <tr>
                    <td><?= $rowNumber ?></td>
                    <td><?= h($row['name']) ?></td>
                    <td><?= h($row['property_name']) ?></td>
                    <td><?= h($row['unit_number']) ?></td>
                    <td><?= h(date('M Y', strtotime($row['month']))) ?></td>
                    <td><?= formatKsh((float) $row['expected_rent']) ?></td>
                    <td><?= formatKsh((float) $row['paid']) ?></td>
                    <td class="text-unpaid"><?= formatKsh((float) $row['balance']) ?></td>
                    <td><span class="badge <?= strtolower($row['rent_status']) === 'paid' ? 'paid' : (strtolower($row['rent_status']) === 'partial' ? 'partial' : 'unpaid') ?>"><?= h($row['rent_status']) ?></span></td>
                    <td><span class="badge <?= strtolower($row['timing_status']) === 'late' ? 'unpaid' : 'paid' ?>"><?= h($row['timing_status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= $paginationHtml ?>
</section>
<?php renderFooter(); ?>
