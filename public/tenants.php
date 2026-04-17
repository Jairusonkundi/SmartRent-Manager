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

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$where = ['1=1', "t.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = '(t.name LIKE :search OR u.unit_number LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($propertyFilter !== 'all') {
    $where[] = 'p.id = :property_id';
    $params[':property_id'] = (int) $propertyFilter;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*)
    FROM tenants t
    LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
    LEFT JOIN units u ON u.id = l.unit_id
    LEFT JOIN properties p ON p.id = u.property_id
    WHERE {$whereSql}";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$totalRecords = (int) $countStmt->fetchColumn();

$querySql = "SELECT t.id, t.name, t.phone, t.email, u.unit_number, p.name AS property_name
    FROM tenants t
    LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
    LEFT JOIN units u ON u.id = l.unit_id
    LEFT JOIN properties p ON p.id = u.property_id
    WHERE {$whereSql}
    ORDER BY t.name
    LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($querySql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
$stmt->execute();
$tenants = $stmt->fetchAll();

$showFrom = $totalRecords > 0 ? (($page - 1) * $limit) + 1 : 0;
$showTo = $totalRecords > 0 ? min($offset + count($tenants), $totalRecords) : 0;
$rowNumber = $showFrom;

$paginationHtml = renderPaginationLinks($totalRecords, $page, $limit, [
    'limit' => $limit === 9999 ? 'all' : $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
]);

renderHeader('Tenants');
?>
<article class="card">
    <h3>Active Tenants</h3>
    <form method="get" class="control-bar">
        <label>Limit
            <select name="limit">
                <?php foreach ([5, 10, 15, 20, 9999] as $limitOption): ?>
                    <option value="<?= $limitOption === 9999 ? 'all' : $limitOption ?>" <?= $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption === 9999 ? 'All' : $limitOption ?></option>
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
        <button type="submit">Apply</button>
        <a class="button" href="/public/tenants.php">Clear Filters</a>
    </form>
    <p>Showing <?= $showFrom ?> to <?= $showTo ?> of <?= $totalRecords ?> Records</p>
    <table class="sortable">
        <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Email</th><th>Property</th><th>Unit</th></tr></thead>
        <tbody>
        <?php foreach ($tenants as $tenant): ?>
            <tr>
                <td><?= $rowNumber++ ?></td>
                <td><?= h($tenant['name']) ?></td>
                <td><?= h($tenant['phone']) ?></td>
                <td><?= h($tenant['email']) ?></td>
                <td><?= h($tenant['property_name']) ?></td>
                <td><?= h($tenant['unit_number']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?= $paginationHtml ?>
</article>
<?php renderFooter(); ?>
