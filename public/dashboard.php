<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$year = (int) ($_GET['year'] ?? date('Y'));
$selectedMonthInput = (string) ($_GET['month'] ?? sprintf('%04d-%02d', $year, (int) date('n')));
$selectedMonth = sprintf('%04d-%02d', $year, (int) date('n'));
if ($selectedMonthInput === 'all') {
    $selectedMonth = 'all';
} elseif (preg_match('/^(\d{4})-(\d{2})$/', $selectedMonthInput, $matches) === 1) {
    $candidateYear = (int) $matches[1];
    $candidateMonth = (int) $matches[2];
    if ($candidateYear === $year && $candidateMonth >= 1 && $candidateMonth <= 12) {
        $selectedMonth = sprintf('%04d-%02d', $candidateYear, $candidateMonth);
    }
}
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$view = (string) ($_GET['view'] ?? 'monthly');
$view = in_array($view, ['monthly', 'quarterly'], true) ? $view : 'monthly';

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$currentMonth = date('Y-m');
$isFuturePeriod = $selectedMonth !== 'all' && $selectedMonth > $currentMonth;
$futureBillingStartDate = $isFuturePeriod ? date('F j, Y', strtotime($selectedMonth . '-01')) : null;
$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();
$propertyWhere = $propertyFilter !== 'all' ? ' AND pr.id = ?' : '';
$propertyParams = $propertyFilter !== 'all' ? [(int) $propertyFilter] : [];

if ($selectedMonth === 'all') {
    // Year-to-date: full year Jan → current month
    $periodStart = sprintf('%04d-01', $year);
    $periodEnd   = min(sprintf('%04d-12', $year), $currentMonth);
} else {
    $periodStart = $selectedMonth;
    $periodEnd   = $selectedMonth;
    if ($view === 'quarterly') {
        $baseDate = new DateTimeImmutable($selectedMonth . '-01');
        $quarter = (int) ceil(((int) $baseDate->format('n')) / 3);
        $quarterStartMonth = (($quarter - 1) * 3) + 1;
        $periodStart = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth, 1)->format('Y-m');
        $periodEnd   = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth + 2, 1)->format('Y-m');
    }
    $periodEnd = min($periodEnd, $currentMonth);
    if ($periodStart > $currentMonth) {
        $periodStart = $currentMonth;
    }
}

// Aggregate to per-(tenant, billing_month) subtotals first so that:
// (a) multi-row payments for the same month are combined correctly, and
// (b) total_receivable is the SUM of per-month deficits — an overpayment in
//     one month does NOT cancel genuine arrears from a different month.
$periodTotalsStmt = $pdo->prepare(
    "SELECT
         COALESCE(SUM(m.expected), 0)                       AS total_expected,
         COALESCE(SUM(m.paid), 0)                           AS total_paid,
         COALESCE(SUM(GREATEST(m.expected - m.paid, 0)), 0) AS total_receivable
     FROM (
         SELECT p.tenant_id,
                p.billing_month,
                SUM(p.amount_expected) AS expected,
                SUM(p.amount_paid)     AS paid
         FROM payments p
         LEFT JOIN leases l  ON l.tenant_id = p.tenant_id AND l.status = 'active'
         LEFT JOIN units u   ON u.id = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}
         GROUP BY p.tenant_id, p.billing_month
     ) m"
);
$periodTotalsStmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
$periodTotals = $periodTotalsStmt->fetch() ?: ['total_expected' => 0, 'total_paid' => 0, 'total_receivable' => 0];

$totalExpected = (float) ($periodTotals['total_expected'] ?? 0.0);
$totalPaid     = (float) ($periodTotals['total_paid']     ?? 0.0);
$accountsReceivable   = (float) ($periodTotals['total_receivable'] ?? 0.0);
$collectionEfficiency = $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 2) : 0.0;

$occupancyStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_units,
            COALESCE(SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END), 0) AS occupied_units
     FROM units u
     LEFT JOIN properties pr ON pr.id = u.property_id
     WHERE 1=1 {$propertyWhere}"
);
$occupancyStmt->execute($propertyParams);
$occupancyRow = $occupancyStmt->fetch() ?: ['total_units' => 0, 'occupied_units' => 0];
$totalUnits = (int) ($occupancyRow['total_units'] ?? 0);
$occupiedUnits = (int) ($occupancyRow['occupied_units'] ?? 0);
$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 2) : 0.0;

$lateStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p
    LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active'
    LEFT JOIN units u ON u.id=l.unit_id
    LEFT JOIN properties pr ON pr.id=u.property_id
    WHERE p.billing_month >= ? AND p.billing_month <= ? AND DAY(p.payment_date) > 10{$propertyWhere}");
$lateStmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
$latePaymentAlert = (int) ($lateStmt->fetchColumn() ?: 0);

$trendStmt = $pdo->prepare(
    "SELECT p.billing_month AS month_key,
            COALESCE(SUM(p.amount_expected), 0) AS expected,
            COALESCE(SUM(p.amount_paid), 0) AS paid
     FROM payments p
     LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active'
     LEFT JOIN units u ON u.id=l.unit_id
     LEFT JOIN properties pr ON pr.id=u.property_id
     WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}
     GROUP BY p.billing_month
     ORDER BY p.billing_month ASC"
);
$trendStmt->execute(array_merge([sprintf('%04d-01', $year), sprintf('%04d-12', $year)], $propertyParams));
$trend = $trendStmt->fetchAll() ?: [];

// Must aggregate per-(tenant, billing_month) before evaluating Paid vs Arrears.
// Row-level comparison (amount_paid >= amount_expected) gives wrong results when a
// single month has multiple payment rows (e.g., two partial bank transfers):
//   Row 1: expected=50000, paid=30000  → row-level: NOT "paid"
//   Row 2: expected=0,     paid=25000  → row-level: "0 > 0" is false, also NOT "paid"
//   Net: shows paid_total=0 and arrears=20000 even though 55000 was received for 50000 owed.
// Per-month aggregation collapses those rows before the CASE logic runs.
// paid_total is capped at expected (not amount_paid) so overpayments don't inflate "Collected".
$distributionStmt = $pdo->prepare(
    "SELECT
         COALESCE(SUM(CASE WHEN m.paid >= m.expected AND m.expected > 0
                           THEN m.expected ELSE 0 END), 0) AS paid_total,
         COALESCE(SUM(CASE WHEN m.expected > m.paid
                           THEN m.expected - m.paid ELSE 0 END), 0) AS arrears_total
     FROM (
         SELECT p.tenant_id,
                p.billing_month,
                SUM(p.amount_expected) AS expected,
                SUM(p.amount_paid)     AS paid
         FROM payments p
         LEFT JOIN leases l  ON l.tenant_id = p.tenant_id AND l.status = 'active'
         LEFT JOIN units u   ON u.id = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}
         GROUP BY p.tenant_id, p.billing_month
     ) m"
);
$distributionStmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
$distribution = $distributionStmt->fetch() ?: ['paid_total' => 0, 'arrears_total' => 0];

$latestBillingMonth = $pdo->query('SELECT MAX(billing_month) FROM payments')->fetchColumn();
$missingTenantDataCount = 0;
if (is_string($latestBillingMonth) && $latestBillingMonth !== '') {
    $missingTenantStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.id)
         FROM tenants t
         LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
         LEFT JOIN units u ON u.id = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE t.status = 'active'
           AND NOT EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.tenant_id = t.id
                 AND p.billing_month = ?
           ){$propertyWhere}"
    );
    $missingTenantStmt->execute(array_merge([$latestBillingMonth], $propertyParams));
    $missingTenantDataCount = (int) ($missingTenantStmt->fetchColumn() ?: 0);
}

renderHeader('Dashboard');
$hasPayments = $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() > 0;
?>
<?php if (!$hasPayments): ?>
<section class="card"><h3>No payment data yet</h3><p>Post individual payments via the <a href="/public/post_payment.php">Post Payment</a> module to populate dashboard metrics.</p></section>
<?php renderFooter(); return; endif; ?>
<section class="card budget-filter-card">
    <form method="get" id="dashboardFilters" class="control-bar filter-form budget-filter-row">
        <label>Property
            <select name="property_id"><option value="all" <?= $propertyFilter === 'all' ? 'selected' : '' ?>>All Properties</option><?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>" <?= $propertyFilter === (string) $property['id'] ? 'selected' : '' ?>><?= h($property['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Year<input type="number" name="year" min="2000" max="2100" value="<?= $year ?>"></label>
        <label>View
            <select name="view"><option value="monthly" <?= $view === 'monthly' ? 'selected' : '' ?>>Monthly</option><option value="quarterly" <?= $view === 'quarterly' ? 'selected' : '' ?>>Quarterly</option></select>
        </label>
        <label class="reference-month-field">Reference Month/Quarter
            <select name="month">
                <option value="all" <?= $selectedMonth === 'all' ? 'selected' : '' ?>><?= $view === 'quarterly' ? 'All Quarters' : 'All Months' ?></option>
                <?php if ($view === 'quarterly'): ?><?php for ($quarterNumber = 1; $quarterNumber <= 4; $quarterNumber++): ?><?php $quarterMonthKey = sprintf('%04d-%02d', $year, (($quarterNumber - 1) * 3) + 1); ?><option value="<?= h($quarterMonthKey) ?>" <?= $selectedMonth === $quarterMonthKey ? 'selected' : '' ?>>Q<?= $quarterNumber ?></option><?php endfor; ?><?php else: ?><?php for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++): ?><?php $monthKey = sprintf('%04d-%02d', $year, $monthNumber); ?><option value="<?= h($monthKey) ?>" <?= $selectedMonth === $monthKey ? 'selected' : '' ?>><?= h(date('M', strtotime($monthKey . '-01'))) ?></option><?php endfor; ?><?php endif; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit">Search</button>
            <button type="button" class="button button-secondary" onclick="window.location.href='/public/dashboard.php';">Reset</button>
        </div>
    </form>
</section>
<?php if ($missingTenantDataCount > 0): ?>
<section class="card subtle-alert-card">
    <strong>Missing Tenant Data:</strong> <?= $missingTenantDataCount ?> active tenant(s) have no payment recorded for <?= h((string) $latestBillingMonth) ?>.
</section>
<?php endif; ?>
<?php if ($isFuturePeriod && $futureBillingStartDate !== null): ?><section class="card"><p>Data for this period is projected. Official billing starts on <?= h($futureBillingStartDate) ?>.</p></section><?php endif; ?>
<section class="cards dashboard-kpi-grid">
    <article class="card metric paid accountant-priority"><span class="metric-label">Collection Efficiency:</span><strong><span id="collection_efficiency_value" class="card-value"><?= number_format($collectionEfficiency, 2) ?>%</span></strong></article>
    <article class="card metric unpaid accountant-priority"><span class="metric-label">Accounts Receivable:</span><strong><span id="accounts_receivable_value" class="card-value">KSh <?= number_format($accountsReceivable, 2) ?></span></strong></article>
    <article class="card metric occupancy-widget"><span class="metric-label">Occupancy Rate:</span><strong><span id="occupancy_rate_value" class="card-value"><?= number_format($occupancyRate, 2) ?>% Occupancy (<?= $occupiedUnits ?>/<?= $totalUnits ?>)</span></strong></article>
    <article class="card metric unpaid"><span class="metric-label">Late Payment Alert (&gt; 10th):</span><strong><span class="card-value"><?= $latePaymentAlert ?></span></strong></article>
</section>
<section class="charts-grid dashboard-charts-grid">
    <article class="card"><h3>Revenue Trend (Year-to-Date)</h3><canvas id="incomeTrend"></canvas></article>
    <article class="card compact-pie-card"><h3>Payment Status Distribution</h3><canvas id="statusPie"></canvas></article>
</section>

<section class="card">
    <h3>Financial Data Tables</h3>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Month</th><th>Expected (KSh)</th><th>Paid (KSh)</th><th>Receivable (KSh)</th><th>Collection %</th></tr></thead>
            <tbody>
                <?php foreach ($trend as $row): ?>
                <?php
                    $expectedAmount = (float) ($row['expected'] ?? 0);
                    $paidAmount = (float) ($row['paid'] ?? 0);
                    $receivableAmount = max($expectedAmount - $paidAmount, 0);
                    $rowCollection = $expectedAmount > 0 ? (($paidAmount / $expectedAmount) * 100) : 0.0;
                ?>
                <tr>
                    <td><?= h((string) $row['month_key']) ?></td>
                    <td><?= number_format($expectedAmount, 2) ?></td>
                    <td><?= number_format($paidAmount, 2) ?></td>
                    <td><?= number_format($receivableAmount, 2) ?></td>
                    <td><?= number_format($rowCollection, 2) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Status</th><th>Amount (KSh)</th></tr></thead>
            <tbody>
                <tr><td>Collected</td><td><?= number_format((float) ($distribution['paid_total'] ?? 0), 2) ?></td></tr>
                <tr><td>Arrears</td><td><?= number_format((float) ($distribution['arrears_total'] ?? 0), 2) ?></td></tr>
            </tbody>
        </table>
    </div>
</section>
<script>
window.dashboardData = { trend: <?= json_encode($trend, JSON_THROW_ON_ERROR) ?>, distribution: <?= json_encode($distribution, JSON_THROW_ON_ERROR) ?> };
</script>
<?php renderFooter(); ?>
