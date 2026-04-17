<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
$pdo = Database::connection();

$pagination = getPaginationState();
$page = $pagination['page'];
$limit = $pagination['limit'];
$offset = $pagination['offset'];
$search = trim((string) ($_GET['search'] ?? ''));
$propertyId = (int) ($_GET['property_id'] ?? 0);

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$where = ["t.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = '(t.name LIKE :search OR u.unit_number LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($propertyId > 0) {
    $where[] = 'p.id = :property_id';
    $params[':property_id'] = $propertyId;
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
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$tenants = $stmt->fetchAll();

$paginationHtml = renderPaginationLinks($totalRecords, $page, $limit, [
    'limit' => $limit,
    'search' => $search,
    'property_id' => $propertyId,
]);

renderHeader('Tenants');
?>
<article class="card">
    <h3>Active Tenants</h3>
    <form method="get" class="control-bar">
        <label>Limit
            <select name="limit">
                <?php foreach ([5, 10, 15, 20] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Search
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Tenant or unit number">
        </label>
        <label>Property
            <select name="property_id">
                <option value="0">All Properties</option>
                <?php foreach ($properties as $property): ?>
                    <option value="<?= (int) $property['id'] ?>" <?= $propertyId === (int) $property['id'] ? 'selected' : '' ?>>
                        <?= h($property['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Apply</button>
    </form>
    <table class="sortable">
        <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Property</th><th>Unit</th></tr></thead>
        <tbody>
        <?php foreach ($tenants as $tenant): ?>
            <tr>
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
