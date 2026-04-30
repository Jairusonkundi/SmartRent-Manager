<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$selectedMonth = date('Y-m', strtotime((string) ($_GET['month'] ?? date('Y-m'))));
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$view = (string) ($_GET['view'] ?? 'monthly');
$view = in_array($view, ['monthly', 'quarterly'], true) ? $view : 'monthly';

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$currentMonth = date('Y-m');
$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();
$propertyWhere = $propertyFilter !== 'all' ? ' AND pr.id = ?' : '';
$propertyParams = $propertyFilter !== 'all' ? [(int) $propertyFilter] : [];

$currentMonthExpectedStmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount_expected), 0) FROM payments p LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active' LEFT JOIN units u ON u.id=l.unit_id LEFT JOIN properties pr ON pr.id=u.property_id WHERE p.billing_month = ?{$propertyWhere}");
$currentMonthExpectedStmt->execute(array_merge([$currentMonth], $propertyParams));
$currentMonthExpected = (float) ($currentMonthExpectedStmt->fetchColumn() ?: 0.0);

$currentMonthCollectedStmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active' LEFT JOIN units u ON u.id=l.unit_id LEFT JOIN properties pr ON pr.id=u.property_id WHERE p.billing_month = ?{$propertyWhere}");
$currentMonthCollectedStmt->execute(array_merge([$currentMonth], $propertyParams));
$currentMonthCollected = (float) ($currentMonthCollectedStmt->fetchColumn() ?: 0.0);
$previousMonth = date('Y-m', strtotime($currentMonth . '-01 -1 month'));
$previousMonthCollectedStmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active' LEFT JOIN units u ON u.id=l.unit_id LEFT JOIN properties pr ON pr.id=u.property_id WHERE p.billing_month = ?{$propertyWhere}");
$previousMonthCollectedStmt->execute(array_merge([$previousMonth], $propertyParams));
$previousMonthCollected = (float) ($previousMonthCollectedStmt->fetchColumn() ?: 0.0);
$monthOverMonthGrowth = $previousMonthCollected > 0 ? (($currentMonthCollected - $previousMonthCollected) / $previousMonthCollected) * 100 : ($currentMonthCollected > 0 ? 100.0 : 0.0);

$currentQuarter = (int) ceil(((int) date('n')) / 3);
$currentQuarterStartMonth = sprintf('%d-%02d', (int) date('Y'), (($currentQuarter - 1) * 3) + 1);
$previousQuarterStartMonth = date('Y-m', strtotime($currentQuarterStartMonth . '-01 -3 months'));

$quarterRangeStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount_paid), 0)
     FROM payments
     WHERE billing_month >= ? AND billing_month <= ?'
);
$quarterRangeStmt->execute([$currentQuarterStartMonth, date('Y-m')]);
$currentQuarterPaid = (float) ($quarterRangeStmt->fetchColumn() ?: 0.0);
$quarterRangeStmt->execute([$previousQuarterStartMonth, date('Y-m', strtotime($currentQuarterStartMonth . '-01 -1 month'))]);
$previousQuarterPaid = (float) ($quarterRangeStmt->fetchColumn() ?: 0.0);
$quarterlyGrowth = $previousQuarterPaid > 0
    ? (($currentQuarterPaid - $previousQuarterPaid) / $previousQuarterPaid) * 100
    : ($currentQuarterPaid > 0 ? 100.0 : 0.0);

$periodStart = $selectedMonth;
$periodEnd = $selectedMonth;
if ($view === 'quarterly') {
    $baseDate = new DateTimeImmutable($selectedMonth . '-01');
    $quarter = (int) ceil(((int) $baseDate->format('n')) / 3);
    $quarterStartMonth = (($quarter - 1) * 3) + 1;
    $periodStart = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth, 1)->format('Y-m');
    $periodEnd = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth + 2, 1)->format('Y-m');
}

$periodTotalsStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(amount_expected), 0) AS total_expected,
        COALESCE(SUM(amount_paid), 0) AS total_paid
     FROM payments
     WHERE billing_month >= ? AND billing_month <= ?'
);
$periodTotalsStmt->execute([$periodStart, $periodEnd]);
$periodTotals = $periodTotalsStmt->fetch() ?: ['total_expected' => 0, 'total_paid' => 0];

$totalExpected = (float) ($periodTotals['total_expected'] ?? 0.0);
$totalPaid = (float) ($periodTotals['total_paid'] ?? 0.0);
$arrearsStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount_expected - amount_paid), 0)
     FROM payments
     WHERE billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')"
);
$arrearsStmt->execute();
$arrears = max((float) ($arrearsStmt->fetchColumn() ?: 0.0), 0.0);

$budgetVarianceAmount = (float) $totalPaid - (float) $totalExpected;
$budgetVariancePercent = $totalExpected > 0 ? ($budgetVarianceAmount / $totalExpected) * 100 : 0.0;
$budgetBadgeClass = $budgetVarianceAmount >= 0 ? 'paid' : 'unpaid';

$occupancyStmt = $pdo->prepare(
    'SELECT COUNT(*) AS total_units, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS occupied_units FROM units'
);
$occupancyStmt->execute(['occupied']);
$occupancyRow = $occupancyStmt->fetch() ?: ['total_units' => 0, 'occupied_units' => 0];
$totalUnits = (int) ($occupancyRow['total_units'] ?? 0);
$occupiedUnits = (int) ($occupancyRow['occupied_units'] ?? 0);
$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 2) : 0.0;

$lateStmt = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_date >= ? AND payment_date <= ? AND DAY(payment_date) > 10');
$lateStmt->execute([$periodStart . '-01', date('Y-m-t', strtotime($periodEnd . '-01'))]);
$latePaymentAlert = (int) ($lateStmt->fetchColumn() ?: 0);

$trendStmt = $pdo->query(
    'SELECT billing_month AS month_key,
            COALESCE(SUM(amount_expected), 0) AS expected,
            COALESCE(SUM(amount_paid), 0) AS paid
     FROM payments
     GROUP BY billing_month
     ORDER BY billing_month DESC
     LIMIT 12'
);
$trend = array_reverse($trendStmt->fetchAll() ?: []);
$paidLast12 = array_map(static fn(array $row): float => (float) ($row['paid'] ?? 0), $trend);

$distributionStmt = $pdo->prepare(
    "SELECT collection_status AS status, COUNT(*) AS total
     FROM payments
     WHERE billing_month >= ? AND billing_month <= ?
     GROUP BY collection_status"
);
$distributionStmt->execute([$periodStart, $periodEnd]);
$distribution = $distributionStmt->fetchAll() ?: [];

$collectionEfficiency = $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 2) : 0.0;

renderHeader('Dashboard');
$hasPayments = $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() > 0;
?>
<?php if (!$hasPayments): ?>
<section class="card"><h3>Welcome! Please upload your CSV to begin</h3><p>Import your wide-format rent collection data to unlock dashboard metrics, arrears, and reports.</p><a class="button-link" href="/public/upload_csv.php">Upload CSV</a></section>
<?php renderFooter(); return; endif; ?>
<section class="card upload-cta">
    <a class="button-link" href="/public/upload_csv.php">Upload Monthly Data</a>
</section>
<section class="card">
    <form method="get" class="control-bar">
        <label>Property
            <select name="property_id">
                <option value="all" <?= $propertyFilter === 'all' ? 'selected' : '' ?>>All Properties</option>
                <?php foreach ($properties as $property): ?>
                    <option value="<?= (int) $property['id'] ?>" <?= $propertyFilter === (string) $property['id'] ? 'selected' : '' ?>><?= h($property['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>View
            <select name="view">
                <option value="monthly" <?= $view === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="quarterly" <?= $view === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
            </select>
        </label>
        <label>Reference Month
            <input type="month" name="month" value="<?= h($selectedMonth) ?>">
        </label>
        <button type="submit">Apply</button>
    </form>
</section>
<section class="cards">
    <article class="card metric">
        <span class="metric-label">Current Month Expected (<?= h($currentMonth) ?>):</span>
        <strong><span class="card-value"><?= h(formatKsh($currentMonthExpected)) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Current Month Collected (<?= h($currentMonth) ?>):</span>
        <strong><span class="card-value"><?= h(formatKsh($currentMonthCollected)) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Selected Period Expected Revenue:</span>
        <strong><span class="card-value"><?= h(formatKsh($totalExpected)) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Selected Period Actual Revenue:</span>
        <strong><span class="card-value"><?= h(formatKsh($totalPaid)) ?></span></strong>
    </article>
    <article class="card metric <?= $budgetBadgeClass === 'paid' ? 'paid' : 'unpaid' ?>">
        <span class="metric-label">Budget Variance:</span>
        <strong><span class="card-value"><?= h(formatKsh($budgetVarianceAmount)) ?></span></strong>
        <span class="badge <?= h($budgetBadgeClass) ?>"><?= number_format($budgetVariancePercent, 2) ?>%</span>
    </article>
    <article class="card metric">
        <span class="metric-label">Month-over-Month Collection Growth:</span>
        <strong><span class="card-value"><?= number_format($monthOverMonthGrowth, 2) ?>%</span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Arrears (Past + Current Months Only):</span>
        <strong><span class="card-value"><?= h(formatKsh($arrears)) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Collection Efficiency:</span>
        <strong><span class="card-value"><?= number_format($collectionEfficiency, 2) ?>%</span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Occupancy Rate:</span>
        <strong><span class="card-value"><?= number_format($occupancyRate, 2) ?>% (<?= $occupiedUnits ?>/<?= $totalUnits ?>)</span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Late Payment Alert (&gt; 10th):</span>
        <strong><span class="card-value"><?= $latePaymentAlert ?></span></strong>
    </article>
</section>
<section class="charts-grid">
    <article class="card"><h3>Revenue Trend (12 Months)</h3><canvas id="incomeTrend"></canvas></article>
    <article class="card"><h3>Collection vs. Target</h3><canvas id="expectedVsPaid"></canvas></article>
    <article class="card"><h3>Payment Status Distribution</h3><canvas id="statusPie"></canvas></article>
</section>
<script>
window.dashboardData = {
    trend: <?= json_encode($trend, JSON_THROW_ON_ERROR) ?>,
        distribution: <?= json_encode($distribution, JSON_THROW_ON_ERROR) ?>
};
</script>
<?php renderFooter(); ?>
