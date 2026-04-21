<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$selectedMonth = monthStart((string) ($_GET['month'] ?? date('Y-m-01')));
$selectedBillingMonth = date('Y-m', strtotime($selectedMonth));
$monthStart = date('Y-m-01', strtotime($selectedMonth));
$monthEnd = date('Y-m-t', strtotime($selectedMonth));

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$expectedStmt = $pdo->prepare('SELECT COALESCE(SUM(monthly_rent), 0) FROM payments WHERE billing_month = ?');
$expectedStmt->execute([$selectedBillingMonth]);
$totalExpected = (float) ($expectedStmt->fetchColumn() ?: 0);

$paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE billing_month = ?');
$paidStmt->execute([$selectedBillingMonth]);
$totalPaid = (float) ($paidStmt->fetchColumn() ?: 0);

$arrears = max($totalExpected - $totalPaid, 0);

$occupancyStmt = $pdo->prepare(
    'SELECT COUNT(*) AS total_units, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS occupied_units FROM units'
);
$occupancyStmt->execute(['occupied']);
$occupancyRow = $occupancyStmt->fetch() ?: ['total_units' => 0, 'occupied_units' => 0];
$totalUnits = (int) ($occupancyRow['total_units'] ?? 0);
$occupiedUnits = (int) ($occupancyRow['occupied_units'] ?? 0);
$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 2) : 0.0;

$lateStmt = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_date >= ? AND payment_date <= ? AND DAY(payment_date) > 10');
$lateStmt->execute([$monthStart, $monthEnd]);
$latePaymentAlert = (int) ($lateStmt->fetchColumn() ?: 0);

$trendStmt = $pdo->prepare(
    'SELECT billing_month AS month_key,
            COALESCE(SUM(monthly_rent), 0) AS expected,
            COALESCE(SUM(amount_paid), 0) AS paid
     FROM payments
     GROUP BY billing_month
     ORDER BY billing_month ASC'
);
$trendStmt->execute();
$trend = $trendStmt->fetchAll() ?: [];

$distributionStmt = $pdo->prepare(
    "SELECT CASE
            WHEN COALESCE(SUM(amount_paid), 0) >= COALESCE(SUM(monthly_rent), 0) THEN 'paid'
            WHEN COALESCE(SUM(amount_paid), 0) <= 0 THEN 'unpaid'
            ELSE 'partial'
        END AS status,
        COUNT(*) AS total
     FROM payments
     WHERE billing_month = ?
     GROUP BY status"
);
$distributionStmt->execute([$selectedBillingMonth]);
$distribution = $distributionStmt->fetchAll() ?: [];

$collectionEfficiency = $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 2) : 0.0;

renderHeader('Dashboard');
?>
<section class="card upload-cta">
    <a class="button-link" href="/public/upload_csv.php">Upload Monthly Data</a>
</section>
<section class="cards">
    <article class="card metric">
        <span class="metric-label">Total Potential Revenue:</span>
        <strong><span class="card-value">KSH <?= number_format($totalExpected, 2) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Actual Revenue:</span>
        <strong><span class="card-value">KSH <?= number_format($totalPaid, 2) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Collection Efficiency:</span>
        <strong><span class="card-value"><?= number_format($collectionEfficiency, 2) ?>%</span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Arrears Trend (Portfolio Debt):</span>
        <strong><span class="card-value">KSH <?= number_format($arrears, 2) ?></span></strong>
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
    <article class="card"><h3>Monthly Income Trend</h3><canvas id="incomeTrend"></canvas></article>
    <article class="card"><h3>Expected vs Paid</h3><canvas id="expectedVsPaid"></canvas></article>
    <article class="card"><h3>Payment Status Distribution</h3><canvas id="statusPie"></canvas></article>
</section>
<script>
window.dashboardData = {
    trend: <?= json_encode($trend, JSON_THROW_ON_ERROR) ?>,
    distribution: <?= json_encode($distribution, JSON_THROW_ON_ERROR) ?>
};
</script>
<?php renderFooter(); ?>
