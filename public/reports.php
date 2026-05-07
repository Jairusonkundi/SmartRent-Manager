<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$pdo = Database::connection();
$reportTypes = [
    'financial' => 'Financial Summary',
    'delinquency' => 'Tenant Delinquency',
    'occupancy' => 'Occupancy Trends',
    'expense' => 'Expense Analysis',
];

$selectedReport = (string) ($_GET['report'] ?? 'financial');
if (!array_key_exists($selectedReport, $reportTypes)) {
    $selectedReport = 'financial';
}

$range = (string) ($_GET['range'] ?? 'this_month');
$allowedRanges = ['this_month', 'last_quarter', 'ytd'];
if (!in_array($range, $allowedRanges, true)) {
    $range = 'this_month';
}

$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$export = (string) ($_GET['export'] ?? '');
$export = in_array($export, ['pdf', 'excel'], true) ? $export : '';

$today = new DateTimeImmutable('today');
$startDate = $today->modify('first day of this month');
$endDate = $today;
if ($range === 'last_quarter') {
    $currentQuarter = (int) ceil(((int) $today->format('n')) / 3);
    $lastQuarter = $currentQuarter - 1;
    $year = (int) $today->format('Y');
    if ($lastQuarter <= 0) {
        $lastQuarter = 4;
        $year--;
    }
    $startMonth = (($lastQuarter - 1) * 3) + 1;
    $startDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth)));
    $endDate = $startDate->modify('+2 months')->modify('last day of this month');
} elseif ($range === 'ytd') {
    $startDate = new DateTimeImmutable($today->format('Y-01-01'));
}

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$importLog = $pdo->query('SELECT source_file, created_at FROM import_logs ORDER BY created_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$hasData = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() > 0;

$propertyClause = '';
$params = [
    $startDate->format('Y-m-d'),
    $endDate->format('Y-m-d'),
];
if ($propertyFilter !== 'all') {
    $propertyClause = ' AND pr.id = ?';
    $params[] = (int) $propertyFilter;
}

$financialRows = [];
$kpi = ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'collection_rate' => 0.0];
$delinquencyRows = [];
$occupancyRows = [];
$expenseRows = [];

if ($hasData) {
    $stmtKpi = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount_expected),0) AS expected, COALESCE(SUM(p.amount_paid),0) AS paid
         FROM payments p
         LEFT JOIN tenants t ON t.id = p.tenant_id
         LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
         LEFT JOIN units u ON u.id = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE p.month BETWEEN ? AND ?{$propertyClause}"
    );
    $stmtKpi->execute($params);
    $kpiRow = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $kpi['expected'] = (float) ($kpiRow['expected'] ?? 0);
    $kpi['paid'] = (float) ($kpiRow['paid'] ?? 0);
    $kpi['outstanding'] = max($kpi['expected'] - $kpi['paid'], 0);
    $kpi['collection_rate'] = $kpi['expected'] > 0 ? ($kpi['paid'] / $kpi['expected']) * 100 : 0;

    $stmtFinancial = $pdo->prepare(
        "SELECT p.payment_date AS entry_date, pr.name AS property_name, u.unit_number, t.name AS tenant_name,
                p.amount_expected, p.amount_paid, (p.amount_expected - p.amount_paid) AS balance
         FROM payments p
         LEFT JOIN tenants t ON t.id = p.tenant_id
         LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
         LEFT JOIN units u ON u.id = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE p.month BETWEEN ? AND ?{$propertyClause}
         ORDER BY p.payment_date DESC, pr.name ASC, u.unit_number ASC"
    );
    $stmtFinancial->execute($params);
    $financialRows = $stmtFinancial->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtDel = $pdo->prepare(
        "SELECT rs.due_date, pr.name AS property_name, u.unit_number, t.name AS tenant_name,
                rs.expected_rent, COALESCE(p.amount_paid, 0) AS amount_paid,
                (rs.expected_rent - COALESCE(p.amount_paid, 0)) AS balance
         FROM rent_schedule rs
         JOIN tenants t ON t.id = rs.tenant_id
         LEFT JOIN payments p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
         LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
         LEFT JOIN units u ON u.id = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE rs.month BETWEEN ? AND ?{$propertyClause}
         ORDER BY rs.due_date DESC"
    );
    $stmtDel->execute($params);
    $delinquencyRows = $stmtDel->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtOcc = $pdo->prepare(
        "SELECT pr.name AS property_name, u.unit_number, u.status,
            CASE WHEN u.status = 'vacant' THEN DATEDIFF(CURDATE(), COALESCE(MAX(l.end_date), DATE_SUB(CURDATE(), INTERVAL 30 DAY))) ELSE 0 END AS vacancy_days
         FROM units u
         JOIN properties pr ON pr.id = u.property_id
         LEFT JOIN leases l ON l.unit_id = u.id
         WHERE 1=1" . ($propertyFilter !== 'all' ? ' AND pr.id = ?' : '') . "
         GROUP BY pr.name, u.unit_number, u.status
         ORDER BY pr.name ASC, u.unit_number ASC"
    );
    $stmtOcc->execute($propertyFilter !== 'all' ? [(int) $propertyFilter] : []);
    $occupancyRows = $stmtOcc->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtExp = $pdo->prepare(
        "SELECT e.category, SUM(e.amount) AS total_amount
         FROM expenses e
         JOIN properties pr ON pr.id = e.property_id
         WHERE e.date BETWEEN ? AND ?" . ($propertyFilter !== 'all' ? ' AND pr.id = ?' : '') . "
         GROUP BY e.category
         ORDER BY total_amount DESC"
    );
    $expParams = [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')];
    if ($propertyFilter !== 'all') {
        $expParams[] = (int) $propertyFilter;
    }
    $stmtExp->execute($expParams);
    $expenseRows = $stmtExp->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

renderHeader('Reports');
?>
<div class="reports-layout">
    <aside class="reports-nav card">
        <h3>Report Views</h3>
        <?php foreach ($reportTypes as $key => $label): ?>
            <a class="report-nav-link <?= $selectedReport === $key ? 'active' : '' ?>" href="?report=<?= h($key) ?>&range=<?= h($range) ?>&property_id=<?= h($propertyFilter) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </aside>

    <section class="reports-content">
        <form method="get" class="card report-filter-bar">
            <input type="hidden" name="report" value="<?= h($selectedReport) ?>">
            <label>Date Range
                <select name="range">
                    <option value="this_month" <?= $range === 'this_month' ? 'selected' : '' ?>>This Month</option>
                    <option value="last_quarter" <?= $range === 'last_quarter' ? 'selected' : '' ?>>Last Quarter</option>
                    <option value="ytd" <?= $range === 'ytd' ? 'selected' : '' ?>>Year to Date</option>
                </select>
            </label>
            <label>Property Selector
                <select name="property_id">
                    <option value="all">All Properties</option>
                    <?php foreach ($properties as $property): ?>
                        <option value="<?= h((string) $property['id']) ?>" <?= $propertyFilter === (string) $property['id'] ? 'selected' : '' ?>><?= h((string) $property['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Export Report
                <select name="export">
                    <option value="">Choose format</option>
                    <option value="pdf">PDF</option>
                    <option value="excel">Excel</option>
                </select>
            </label>
            <button type="submit">Apply</button>
        </form>

        <?php if (!$hasData): ?>
            <div class="card report-empty-state">
                <div class="empty-illustration">📄</div>
                <h3>No report data found</h3>
                <p>Upload the latest CSV in Data Management to populate financial, occupancy, and delinquency analytics.</p>
                <a class="button-link" href="/public/upload_csv.php">Go to Data Management to Upload CSV</a>
            </div>
        <?php else: ?>
            <p class="report-meta">Source: Latest CSV upload <strong><?= h((string) ($importLog['source_file'] ?? 'Unknown file')) ?></strong> at <?= h((string) ($importLog['created_at'] ?? 'N/A')) ?>.</p>

            <?php if ($selectedReport === 'financial'): ?>
                <div class="cards report-kpis">
                    <article class="card metric metric-selected"><span class="metric-label">Total Expected Revenue</span><strong><?= currency($kpi['expected']) ?></strong></article>
                    <article class="card metric metric-selected paid"><span class="metric-label">Actual Collection Rate</span><strong><?= number_format($kpi['collection_rate'], 1) ?>%</strong></article>
                    <article class="card metric metric-selected unpaid"><span class="metric-label">Total Outstanding Debt</span><strong><?= currency($kpi['outstanding']) ?></strong></article>
                </div>
                <div class="card table-responsive">
                    <table>
                        <thead><tr><th>Date</th><th>Property/Unit</th><th>Category</th><th>Tenant Name</th><th>Amount Due</th><th>Amount Paid</th><th>Balance</th></tr></thead>
                        <tbody>
                        <?php foreach ($financialRows as $row): ?>
                            <tr>
                                <td><?= h((string) $row['entry_date']) ?></td>
                                <td><?= h((string) $row['property_name']) ?> / <?= h((string) $row['unit_number']) ?></td>
                                <td>Rent</td>
                                <td><?= h((string) $row['tenant_name']) ?></td>
                                <td><?= currency((float) $row['amount_expected']) ?></td>
                                <td><?= currency((float) $row['amount_paid']) ?></td>
                                <td class="<?= (float) $row['balance'] > 0 ? 'text-unpaid' : 'text-paid' ?>"><?= currency((float) $row['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($selectedReport === 'delinquency'): ?>
                <div class="card table-responsive">
                    <table>
                        <thead><tr><th>Due Date</th><th>Tenant</th><th>Property/Unit</th><th>Amount Due</th><th>Amount Paid</th><th>Balance</th><th>Aged Bucket</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($delinquencyRows as $row): $balance=(float)$row['balance']; $overdue=$balance>0 && strtotime((string)$row['due_date'])<time(); $days=max((int)floor((time()-strtotime((string)$row['due_date']))/86400),0); ?>
                            <tr class="<?= $overdue ? 'row-overdue' : '' ?>">
                                <td><?= h((string) $row['due_date']) ?></td><td><?= h((string) $row['tenant_name']) ?></td><td><?= h((string) $row['property_name']) ?> / <?= h((string) $row['unit_number']) ?></td><td><?= currency((float) $row['expected_rent']) ?></td><td><?= currency((float) $row['amount_paid']) ?></td><td><?= currency($balance) ?></td>
                                <td><?= $days >= 90 ? '90+ days' : ($days >= 60 ? '60 days' : ($days >= 30 ? '30 days' : 'Current')) ?></td>
                                <td><?= $overdue ? '<span class="badge unpaid">Overdue</span>' : '<span class="badge paid">Current</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($selectedReport === 'occupancy'): ?>
                <div class="card table-responsive">
                    <table>
                        <thead><tr><th>Property</th><th>Unit</th><th>Status</th><th>Vacancy Duration</th></tr></thead>
                        <tbody><?php foreach ($occupancyRows as $row): ?><tr><td><?= h((string)$row['property_name']) ?></td><td><?= h((string)$row['unit_number']) ?></td><td class="<?= $row['status']==='vacant'?'status-vacant':'' ?>"><?= h(ucfirst((string)$row['status'])) ?></td><td><?= $row['status']==='vacant'? (int)$row['vacancy_days'].' days':'-' ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="card table-responsive">
                    <table>
                        <thead><tr><th>Expense Category</th><th>Total</th><th>Decision Metric</th></tr></thead>
                        <tbody><?php foreach ($expenseRows as $row): ?><tr><td><?= h((string)$row['category']) ?></td><td><?= currency((float)$row['total_amount']) ?></td><td>Expense by Category</td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php renderFooter(); ?>
