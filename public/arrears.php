<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/PaymentService.php';

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

$search = trim((string) ($_GET['search'] ?? ''));
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$propertyId = $propertyFilter !== 'all' ? (int) $propertyFilter : null;

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$paymentService = new PaymentService();
$allArrears = $paymentService->arrearsSummaryByTenant($search, $propertyId);
$totalRecords = count($allArrears);

if ($isAllLimit) {
    $arrears = $allArrears;
    $offset = 0;
    $paginationLimit = max(1, $totalRecords);
} else {
    $offset = ($page - 1) * $limit;
    $arrears = array_slice($allArrears, $offset, $limit);
    $paginationLimit = $limit;
}

$currentCount = count($arrears);
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
]);

renderHeader('Arrears');
?>
<section class="card">
    <h3>Balance Owed per Tenant (up to current month)</h3>
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
                    <td><?= h((string) $row['name']) ?></td>
                    <td><?= h((string) $row['property_name']) ?></td>
                    <td><?= h((string) $row['unit_number']) ?></td>
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
