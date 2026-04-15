<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/DashboardService.php';

requireAuth();

$month = $_GET['month'] ?? date('Y-m-01');
$service = new DashboardService();
$summary = $service->summary($month);
$trend = $service->monthlyTrend();
$distribution = $service->paymentStatusDistribution($month);

renderHeader('Dashboard');
?>
<section class="cards">
    <article class="card metric"><span>Total Expected</span><strong><?= h(formatKsh($summary['expected'])) ?></strong></article>
    <article class="card metric paid"><span>Total Paid</span><strong><?= h(formatKsh($summary['paid'])) ?></strong></article>
    <article class="card metric unpaid"><span>Outstanding</span><strong><?= h(formatKsh($summary['outstanding'])) ?></strong></article>
    <article class="card metric"><span>Collection %</span><strong><?= number_format($summary['collection_percent'], 2) ?>%</strong></article>
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
