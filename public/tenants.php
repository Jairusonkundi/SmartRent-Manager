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
$allowedStatuses = ['active', 'inactive'];

if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$params = [];
$where = ['1=1'];

if ($search !== '') {
    $where[] = '(t.name LIKE :search OR u.unit_number LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($propertyFilter !== 'all') {
    $where[] = 'p.id = :property_id';
    $params['property_id'] = (int) $propertyFilter;
}

if ($statusFilter !== 'all') {
    $where[] = 't.status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*)
    FROM tenants t
    LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
    LEFT JOIN units u ON u.id = l.unit_id
    LEFT JOIN properties p ON p.id = u.property_id
    WHERE {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();

$querySql = "SELECT t.id, t.name, t.phone, t.email, t.status, u.unit_number, p.name AS property_name
    FROM tenants t
    LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
    LEFT JOIN units u ON u.id = l.unit_id
    LEFT JOIN properties p ON p.id = u.property_id
    WHERE {$whereSql}
    ORDER BY t.name";

if (!$isAllLimit) {
    $querySql .= ' LIMIT :limit OFFSET :offset';
}

$stmt = $pdo->prepare($querySql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
if (!$isAllLimit) {
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$stmt->execute();
$tenants = $stmt->fetchAll();

$currentCount = count($tenants);
$paginationLimit = $isAllLimit ? max(1, $totalRecords) : $limit;
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
    'status' => $statusFilter,
]);

renderHeader('Tenants');
?>
<article class="card">
    <h3>Tenants</h3>
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
                    <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= ucfirst(h($statusOption)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Apply</button>
        <a class="button" href="/public/tenants.php">Clear Filters</a>
    </form>
    <p>Showing <?= $currentCount ?> records | Total Found: <?= $totalRecords ?></p>
    <table class="sortable">
        <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Email</th><th>Property</th><th>Unit</th></tr></thead>
        <tbody>
        <?php foreach ($tenants as $index => $tenant): ?>
            <?php $rowNumber = $offset + $index + 1; ?>
            <tr>
                <td><?= $rowNumber ?></td>
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
