<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/DashboardService.php';

requireAuth();

$pdo = Database::connection();

$summaryStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(monthly_rent), 0) AS total_expected,
        COALESCE(SUM(amount_paid), 0) AS total_paid,
        COALESCE(SUM(monthly_rent - amount_paid), 0) AS total_outstanding
    FROM payments"
);
$summaryStmt->execute();
$summaryRow = $summaryStmt->fetch() ?: ['total_expected' => 0, 'total_paid' => 0, 'total_outstanding' => 0];

$expected = (float) $summaryRow['total_expected'];
$paid = (float) $summaryRow['total_paid'];
$outstanding = max((float) $summaryRow['total_outstanding'], 0);

$summary = [
    'expected' => $expected,
    'paid' => $paid,
    'outstanding' => $outstanding,
    'collection_percent' => $expected > 0 ? round(($paid / $expected) * 100, 2) : 0,
];

$service = new DashboardService();
$trend = $service->monthlyTrend();
$distribution = $service->paymentStatusDistribution(monthStart((string) ($_GET['month'] ?? date('Y-m-01'))));

renderHeader('Dashboard');
?>
<section class="card upload-cta">
    <a class="button-link" href="/public/upload_csv.php">Upload Monthly Data</a>
</section>
<section class="cards">
    <article class="card metric">
        <span class="metric-label">Total Expected Rent:</span>
        <strong><span class="card-value"><?= formatKsh((float) $summary['expected']) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Total Paid:</span>
        <strong><span class="card-value"><?= formatKsh((float) $summary['paid']) ?></span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Outstanding Rent:</span>
        <strong><span class="card-value"><?= formatKsh((float) $summary['outstanding']) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Collection %:</span>
        <strong><span class="card-value"><?= number_format($summary['collection_percent'], 2) ?>%</span></strong>
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
