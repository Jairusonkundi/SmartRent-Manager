<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/DashboardService.php';

requireAuth();

$selectedMonth = monthStart((string) ($_GET['month'] ?? date('Y-m-01')));
$service = new DashboardService();
$kpis = $service->portfolioKpis($selectedMonth);
$trend = $service->monthlyTrend();
$distribution = $service->paymentStatusDistribution($selectedMonth);

renderHeader('Dashboard');
?>
<section class="card upload-cta">
    <a class="button-link" href="/public/upload_csv.php">Upload Monthly Data</a>
</section>
<section class="cards">
    <article class="card metric">
        <span class="metric-label">Total Potential Revenue:</span>
        <strong><span class="card-value"><?= formatKsh((float) $kpis['total_expected']) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Actual Revenue:</span>
        <strong><span class="card-value"><?= formatKsh((float) $kpis['total_paid']) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Collection Efficiency:</span>
        <strong><span class="card-value"><?= number_format((float) $kpis['collection_efficiency'], 2) ?>%</span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Arrears Trend (Portfolio Debt):</span>
        <strong><span class="card-value"><?= formatKsh((float) $kpis['arrears_trend']) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Occupancy Rate:</span>
        <strong><span class="card-value"><?= number_format((float) $kpis['occupancy_rate'], 2) ?>% (<?= (int) $kpis['occupied_units'] ?>/<?= (int) $kpis['total_units'] ?>)</span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Late Payment Alert (&gt; 10th):</span>
        <strong><span class="card-value"><?= (int) $kpis['late_payment_alert'] ?></span></strong>
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
