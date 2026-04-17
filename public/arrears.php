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
$limit = $requestedLimit === 'all' ? 9999 : (int) $requestedLimit;
$allowedLimits = [5, 10, 15, 20, 9999];
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 10;
}
$offset = ($page - 1) * $limit;
$search = trim((string) ($_GET['search'] ?? ''));
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['Paid', 'Partial', 'Unpaid'];

if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$whereClauses = ['1=1', "rs.month <= CURRENT_DATE", "rs.status <> 'paid'"];
$params = [];
$amountPaidExpr = 'COALESCE(SUM(p.amount_paid), 0)';
$balanceExpr = "(rs.expected_rent - {$amountPaidExpr})";
$rentStatusExpr = "CASE
            WHEN {$balanceExpr} <= 0 THEN 'Paid'
            WHEN {$amountPaidExpr} = 0 THEN 'Unpaid'
            ELSE 'Partial'
       END";

if ($search !== '') {
    $whereClauses[] = '(t.name LIKE :search OR u.unit_number LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($propertyFilter !== 'all') {
    $whereClauses[] = 'pr.id = :property_id';
    $params[':property_id'] = (int) $propertyFilter;
}

if ($statusFilter !== 'all') {
    $whereClauses[] = "{$rentStatusExpr} = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = implode(' AND ', $whereClauses);

$baseFrom = "
FROM rent_schedule rs
JOIN tenants t ON t.id = rs.tenant_id
JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
JOIN units u ON u.id = l.unit_id
JOIN properties pr ON pr.id = u.property_id
LEFT JOIN payments p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
WHERE {$whereSql}
GROUP BY rs.id, t.name, rs.month, rs.due_date, rs.expected_rent, u.unit_number, pr.name
";

$countSql = "SELECT COUNT(*) FROM (SELECT rs.id {$baseFrom}) AS counted_rows";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$totalRecords = (int) $countStmt->fetchColumn();

$sql = "
SELECT t.name,
       rs.month,
       rs.due_date,
       rs.expected_rent,
       u.unit_number,
       pr.name AS property_name,
       {$amountPaidExpr} AS paid,
       {$balanceExpr} AS balance,
       {$rentStatusExpr} AS rent_status,
       CASE WHEN COALESCE(MAX(p.payment_date), '9999-12-31') > rs.due_date THEN 'Late' ELSE 'On Time' END AS timing_status
{$baseFrom}
ORDER BY balance DESC, rs.month ASC
LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
$stmt->execute();
$arrears = $stmt->fetchAll();

$currentCount = count($arrears);

$paginationHtml = renderPaginationLinks($totalRecords, $page, $limit, [
    'limit' => $limit,
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
                <?php foreach ([5, 10, 15, 20, 9999] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption === 9999 ? 'All' : $limitOption ?></option>
                <?php endforeach; ?>
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
