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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenantId = (int) ($_POST['tenant_id'] ?? 0);
    $amount = (float) ($_POST['amount_paid'] ?? 0);
    $month = (string) ($_POST['month'] ?? date('Y-m-01'));
    $paymentDate = (string) ($_POST['payment_date'] ?? date('Y-m-d'));

    if ($tenantId <= 0 || $amount <= 0) {
        flash('error', 'Please select tenant and enter a valid amount.');
    } else {
        (new PaymentService())->recordPayment($tenantId, $month, $amount, $paymentDate, (int) $_SESSION['user_id']);
        flash('success', 'Payment recorded successfully.');
    }

    header('Location: /public/payments.php');
    exit;
}

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

$tenants = $pdo->query("SELECT id, name FROM tenants WHERE status='active' ORDER BY name")->fetchAll();
$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$params = [];
$where = ['1=1'];
$having = ['1=1'];
$sumPaidExpr = 'COALESCE(SUM(pm.amount_paid), 0)';
$paymentStatusExpr = "CASE
        WHEN {$sumPaidExpr} = 0 THEN 'Unpaid'
        WHEN {$sumPaidExpr} < rs.expected_rent THEN 'Partial'
        ELSE 'Paid'
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
    $having[] = "{$paymentStatusExpr} = :status";
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
    LEFT JOIN payments pm ON pm.tenant_id = rs.tenant_id AND pm.month = rs.month
    WHERE {$whereSql}
    GROUP BY rs.id, t.name, rs.month, rs.expected_rent, pr.name, u.unit_number
    HAVING {$havingSql}
";

$countSql = "SELECT COUNT(*) FROM (SELECT rs.id {$baseFrom}) AS counted_rows";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();

$paymentsSql = "SELECT
        t.name,
        rs.month,
        rs.expected_rent,
        {$sumPaidExpr} AS amount_paid,
        MAX(pm.payment_date) AS payment_date,
        pr.name AS property_name,
        u.unit_number,
        {$paymentStatusExpr} AS payment_status
    {$baseFrom}
    ORDER BY rs.month DESC, t.name ASC";

if (!$isAllLimit) {
    $paymentsSql .= ' LIMIT :limit OFFSET :offset';
}

$paymentsStmt = $pdo->prepare($paymentsSql);
foreach ($params as $key => $value) {
    $paymentsStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
if (!$isAllLimit) {
    $paymentsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $paymentsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$paymentsStmt->execute();
$recentPayments = $paymentsStmt->fetchAll();

$currentCount = count($recentPayments);
$paginationLimit = $isAllLimit ? max(1, $totalRecords) : $limit;
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
    'status' => $statusFilter,
]);

renderHeader('Payments');
?>
<section class="card">
    <h3>Record Payment</h3>
    <form method="post" class="grid-form">
        <label>Tenant
            <select name="tenant_id" required>
                <option value="">Select tenant</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= (int) $tenant['id'] ?>"><?= h($tenant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Amount Paid <input name="amount_paid" type="number" min="0" step="0.01" required></label>
        <label>Rent Month <input name="month" type="month" value="<?= date('Y-m') ?>" required></label>
        <label>Payment Date <input name="payment_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
        <button type="submit">Save Payment</button>
    </form>
</section>
<section class="card">
    <h3>Recent Payments</h3>
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
        <a class="button" href="/public/payments.php">Clear Filters</a>
    </form>
    <p>Showing <?= $currentCount ?> records | Total Found: <?= $totalRecords ?></p>
    <table class="sortable">
        <thead><tr><th>#</th><th>Tenant</th><th>Property</th><th>Unit</th><th>Amount</th><th>Payment Date</th><th>Month</th><th>Payment Status</th></tr></thead>
        <tbody>
            <?php foreach ($recentPayments as $index => $p): ?>
                <?php $rowNumber = $offset + $index + 1; ?>
                <tr>
                    <td><?= $rowNumber ?></td>
                    <td><?= h($p['name']) ?></td>
                    <td><?= h($p['property_name']) ?></td>
                    <td><?= h($p['unit_number']) ?></td>
                    <td><?= formatKsh((float) $p['amount_paid']) ?></td>
                    <td><?= $p['payment_date'] ? h($p['payment_date']) : '-' ?></td>
                    <td><?= date('M Y', strtotime($p['month'])) ?></td>
                    <td><span class="badge <?= strtolower($p['payment_status']) === 'paid' ? 'paid' : (strtolower($p['payment_status']) === 'partial' ? 'partial' : 'unpaid') ?>"><?= h($p['payment_status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= $paginationHtml ?>
</section>
<?php renderFooter(); ?>
