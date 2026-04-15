<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/DashboardService.php';
require_once __DIR__ . '/db_connect.php';

requireAuth();

$month = $_GET['month'] ?? date('Y-m-01');
$service = new DashboardService();
$summary = $service->summary($month);
$trend = $service->monthlyTrend();
$distribution = $service->paymentStatusDistribution($month);

renderHeader('Dashboard');
?>
<section class="dashboard-toolbar">
    <form id="csvUploadForm" class="card import-card" enctype="multipart/form-data">
        <h3>Master Importer</h3>
        <p>Upload monthly tenancy and collection data to sync the dashboard instantly.</p>
        <div class="import-actions">
            <input type="file" id="monthlyCsv" name="monthly_csv" accept=".csv" required>
            <button type="submit">Upload Monthly CSV</button>
        </div>
        <div id="importFeedback" class="alert" style="display:none;"></div>
    </form>
</section>

<section class="cards" id="dashboardSummary">
    <article class="card metric"><span>Total Expected</span><strong id="metricExpected"><?= h(formatKsh((float) $summary['expected'])) ?></strong></article>
    <article class="card metric paid"><span>Total Paid</span><strong id="metricPaid"><?= h(formatKsh((float) $summary['paid'])) ?></strong></article>
    <article class="card metric unpaid"><span>Outstanding</span><strong id="metricOutstanding"><?= h(formatKsh((float) $summary['outstanding'])) ?></strong></article>
    <article class="card metric"><span>Collection %</span><strong id="metricCollectionPercent"><?= number_format((float) $summary['collection_percent'], 2) ?>%</strong></article>
</section>
<section class="charts-grid">
    <article class="card"><h3>Monthly Collection: Expected vs. Paid</h3><canvas id="expectedVsPaid"></canvas></article>
    <article class="card"><h3>Payment Health: On Time vs. Late</h3><canvas id="statusPie"></canvas></article>
    <article class="card"><h3>Monthly Income Trend</h3><canvas id="incomeTrend"></canvas></article>
</section>
<script>
window.dashboardData = {
    month: <?= json_encode($month, JSON_THROW_ON_ERROR) ?>,
    summary: <?= json_encode($summary, JSON_THROW_ON_ERROR) ?>,
    trend: <?= json_encode($trend, JSON_THROW_ON_ERROR) ?>,
    distribution: <?= json_encode($distribution, JSON_THROW_ON_ERROR) ?>
};
</script>
<?php renderFooter(); ?>
