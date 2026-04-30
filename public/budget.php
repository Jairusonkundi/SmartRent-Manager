<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/BudgetService.php';

requireAuth();
$year = (int) ($_GET['year'] ?? date('Y'));
$selectedMonth = date('Y-m', strtotime((string) ($_GET['month'] ?? date('Y-m'))));
$view = (string) ($_GET['view'] ?? 'monthly');
$view = in_array($view, ['monthly', 'quarterly'], true) ? $view : 'monthly';
$currentMonth = date('Y-m');
$currentYear = (int) date('Y');
$currentQuarter = (int) ceil(((int) date('n')) / 3);
$isFuturePeriod = $selectedMonth > $currentMonth;
$futureBillingStartDate = $isFuturePeriod ? date('F j, Y', strtotime($selectedMonth . '-01')) : null;

$service = new BudgetService();
$monthly = $service->monthlyBreakdown($year);
$quarterly = $service->quarterlyComparison($year);
$quarterlyYoY = $service->quarterlyYearOverYear($year);

$selectedDate = new DateTimeImmutable($selectedMonth . '-01');
$selectedQuarter = (int) ceil(((int) $selectedDate->format('n')) / 3);
$periodHeadline = $view === 'quarterly'
    ? sprintf('Q%d %s Analysis', $selectedQuarter, $selectedDate->format('Y'))
    : $selectedDate->format('F Y') . ' Performance';

$periodStart = $selectedMonth;
$periodEnd = $selectedMonth;
if ($view === 'quarterly') {
    $quarterStartMonth = (($selectedQuarter - 1) * 3) + 1;
    $periodStart = $selectedDate->setDate((int) $selectedDate->format('Y'), $quarterStartMonth, 1)->format('Y-m');
    $periodEnd = $selectedDate->setDate((int) $selectedDate->format('Y'), $quarterStartMonth + 2, 1)->format('Y-m');
}
$periodEnd = min($periodEnd, $currentMonth);
if ($periodStart > $currentMonth) {
    $periodStart = $currentMonth;
}

$periodTotals = $service->periodTotals($periodStart, $periodEnd);
$periodExpected = (float) $periodTotals['expected'];
$periodPaid = (float) $periodTotals['paid'];
$periodOutstanding = (float) $periodExpected - (float) $periodPaid;
$varianceAmount = (float) $periodPaid - (float) $periodExpected;
$variancePercent = $periodExpected > 0 ? ((float) $varianceAmount / (float) $periodExpected) * 100 : 0.0;
$varianceBadgeClass = $varianceAmount >= 0 ? 'paid' : 'unpaid';

$totalExpected = array_sum(array_map(fn($r) => (float) $r['expected'], $monthly));
$totalPaid = array_sum(array_map(fn($r) => (float) $r['paid'], $monthly));
$totalOutstanding = array_sum(array_map(fn($r) => (float) $r['expected'] - (float) $r['paid'], $monthly));

renderHeader('Budget');
?>
<section class="card">
    <form method="get" id="budgetFilters" class="control-bar filter-form">
        <label>Year
            <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>">
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
        <div class="control-actions">
            <button type="submit">Search</button>
            <button type="button" class="button" onclick="resetFilters('budgetFilters','/public/budget.php')">Clear Filters</button>
        </div>
    </form>
</section>
<?php if ($isFuturePeriod && $futureBillingStartDate !== null): ?>
<section class="card">
    <p>Data for this period is projected. Official billing starts on <?= h($futureBillingStartDate) ?>.</p>
</section>
<?php endif; ?>

<section class="cards metrics-general">
    <article class="card metric metric-general">
        <span class="metric-label">Total Annual Budget (<?= $year ?>):</span>
        <strong><span class="card-value"><?= formatKsh($totalExpected) ?></span></strong>
    </article>
    <article class="card metric metric-general">
        <span class="metric-label">Total YTD Collection:</span>
        <strong><span class="card-value"><?= formatKsh($totalPaid) ?></span></strong>
    </article>
    <article class="card metric metric-general">
        <span class="metric-label">Total YTD Arrears:</span>
        <strong><span class="card-value"><?= formatKsh($totalOutstanding) ?></span></strong>
    </article>
</section>

<section class="card selected-period-title">
    <h3><?= h($periodHeadline) ?></h3>
</section>
<section class="cards metrics-selected">
    <article class="card metric metric-selected">
        <span class="metric-label"><?= $view === 'quarterly' ? 'Quarter Target:' : 'Month Target:' ?></span>
        <strong><span class="card-value"><?= formatKsh($periodExpected) ?></span></strong>
    </article>
    <article class="card metric metric-selected">
        <span class="metric-label"><?= $view === 'quarterly' ? 'Quarter Collected:' : 'Month Collected:' ?></span>
        <strong><span class="card-value"><?= formatKsh($periodPaid) ?></span></strong>
    </article>
    <article class="card metric metric-selected <?= $varianceBadgeClass === 'paid' ? 'paid' : 'unpaid' ?>">
        <span class="metric-label"><?= $view === 'quarterly' ? 'Quarter Variance:' : 'Month Variance:' ?></span>
        <strong><span class="card-value <?= $varianceAmount < 0 ? 'negative-financial' : '' ?>"><?= formatKsh($varianceAmount) ?></span></strong>
        <span class="badge <?= h($varianceBadgeClass) ?>"><?= number_format($variancePercent, 2) ?>%</span>
    </article>
</section>
<section class="card">
    <h3>Monthly Budget Breakdown (<?= $year ?>)</h3>
    <table class="sortable">
        <thead><tr><th>Month</th><th>Expected</th><th>Paid</th><th>Outstanding</th></tr></thead>
        <tbody>
            <?php foreach ($monthly as $row): ?>
                <?php $expected = (float) $row['expected']; ?>
                <?php $paid = (float) $row['paid']; ?>
                <?php $outstanding = (float) $expected - (float) $paid; ?>
                <tr>
                    <td><?= h((string) $row['month_key']) ?></td>
                    <td><?= formatKsh($expected) ?></td>
                    <td class="text-paid"><?= formatKsh($paid) ?></td>
                    <td class="<?= $outstanding > 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($outstanding) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<section class="card">
    <h3>Quarterly Side-by-Side: Q1 vs Q2</h3>
    <table>
        <thead><tr><th>Quarter</th><th>Expected</th><th>Paid</th><th>Variance</th></tr></thead>
        <tbody>
        <?php foreach ($quarterly as $row): ?>
            <?php if (in_array($row['quarter_label'], ['Q1', 'Q2'], true)): ?>
                <?php $qVariance = (float) $row['paid'] - (float) $row['expected']; ?>
                <tr>
                    <td><?= h($row['quarter_label']) ?></td>
                    <td><?= formatKsh((float) $row['expected']) ?></td>
                    <td><?= formatKsh((float) $row['paid']) ?></td>
                    <td class="<?= $qVariance < 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($qVariance) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<section class="charts-grid">
    <article class="card"><h3>Monthly Comparison</h3><canvas id="monthlyBudget"></canvas></article>
    <article class="card"><h3>Quarterly Cumulative Comparison</h3><canvas id="quarterlyBudget"></canvas></article>
</section>
<script>
window.budgetData = {
    monthly: <?= json_encode($monthly, JSON_THROW_ON_ERROR) ?>,
    quarterly: <?= json_encode($quarterly, JSON_THROW_ON_ERROR) ?>,
    quarterlyYoY: <?= json_encode($quarterlyYoY, JSON_THROW_ON_ERROR) ?>,
    selectedYear: <?= json_encode($year, JSON_THROW_ON_ERROR) ?>,
    currentYear: <?= json_encode($currentYear, JSON_THROW_ON_ERROR) ?>,
    currentQuarter: <?= json_encode($currentQuarter, JSON_THROW_ON_ERROR) ?>
};
</script>
<?php renderFooter(); ?>
