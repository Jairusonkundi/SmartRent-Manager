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
$selectedMonth = date('Y-m', strtotime((string) ($_GET['month'] ?? date('Y-m'))));

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$where = ['p.billing_month = ?'];
$params = [$selectedMonth];

if ($search !== '') {
    $where[] = '(t.name LIKE ? OR u.unit_number LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($propertyFilter !== 'all') {
    $where[] = 'pr.id = ?';
    $params[] = (int) $propertyFilter;
}

$whereSql = implode(' AND ', $where);

$baseSql = "
FROM payments p
JOIN tenants t ON t.id = p.tenant_id
LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
LEFT JOIN units u ON u.id = l.unit_id
LEFT JOIN properties pr ON pr.id = u.property_id
WHERE {$whereSql}
GROUP BY p.tenant_id, t.name, pr.name, u.unit_number
HAVING (COALESCE(MAX(p.amount_expected), 0) - COALESCE(SUM(p.amount_paid), 0)) > 0
";

$countSql = "SELECT COUNT(*) FROM (SELECT p.tenant_id {$baseSql}) AS arrears_count";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) ($countStmt->fetchColumn() ?: 0);

$dataSql = "
SELECT
    t.name,
    COALESCE(pr.name, 'Unassigned Property') AS property_name,
    COALESCE(u.unit_number, '-') AS unit_number,
    COALESCE(MAX(p.amount_expected), 0) AS monthly_rent,
    COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
    (COALESCE(MAX(p.amount_expected), 0) - COALESCE(SUM(p.amount_paid), 0)) AS balance
{$baseSql}
ORDER BY balance DESC, t.name ASC
";

if (!$isAllLimit) {
    $dataSql .= 'LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
}

$stmt = $pdo->prepare($dataSql);
$stmt->execute($params);
$arrears = $stmt->fetchAll();

$currentCount = count($arrears);
$paginationLimit = $isAllLimit ? max(1, $totalRecords) : $limit;
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
    'month' => $selectedMonth,
]);

renderHeader('Arrears');
?>
<section class="card">
    <h3>Balance Owed per Tenant</h3>
    <form method="get" class="control-bar">
        <label>Month
            <input type="month" name="month" value="<?= h($selectedMonth) ?>">
        </label>
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
        <button type="submit">Apply</button>
        <a class="button" href="/public/arrears.php">Clear Filters</a>
    </form>
    <p>Showing <?= $currentCount ?> records | Total Found: <?= $totalRecords ?></p>
    <table class="sortable">
        <thead><tr><th>#</th><th>Tenant</th><th>Property</th><th>Unit</th><th>Monthly Rent</th><th>Amount Paid</th><th>Balance Owed</th></tr></thead>
        <tbody>
            <?php foreach ($arrears as $index => $row): ?>
                <?php $rowNumber = $offset + $index + 1; ?>
                <tr>
                    <td><?= $rowNumber ?></td>
                    <td><?= h($row['name']) ?></td>
                    <td><?= h($row['property_name']) ?></td>
                    <td><?= h($row['unit_number']) ?></td>
                    <td>KSH <?= number_format((float) $row['monthly_rent'], 2) ?></td>
                    <td>KSH <?= number_format((float) $row['amount_paid'], 2) ?></td>
                    <td class="text-unpaid">KSH <?= number_format((float) $row['balance'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= $paginationHtml ?>
</section>
<?php renderFooter(); ?>
