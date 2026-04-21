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

$service = new BudgetService();
$monthly = $service->monthlyBreakdown($year);
$quarterly = $service->quarterlyComparison($year);

$periodStart = $selectedMonth;
$periodEnd = $selectedMonth;
if ($view === 'quarterly') {
    $baseDate = new DateTimeImmutable($selectedMonth . '-01');
    $quarter = (int) ceil(((int) $baseDate->format('n')) / 3);
    $quarterStartMonth = (($quarter - 1) * 3) + 1;
    $periodStart = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth, 1)->format('Y-m');
    $periodEnd = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth + 2, 1)->format('Y-m');
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
    <form method="get" class="control-bar">
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
        <button type="submit">Apply</button>
    </form>
</section>
<section class="cards">
    <article class="card metric">
        <span class="metric-label">Total Expected Rent (<?= $year ?>):</span>
        <strong><span class="card-value"><?= formatKsh($totalExpected) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Total Income (<?= $year ?>):</span>
        <strong><span class="card-value"><?= formatKsh($totalPaid) ?></span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Outstanding Rent (<?= $year ?>):</span>
        <strong><span class="card-value"><?= formatKsh($totalOutstanding) ?></span></strong>
    </article>
    <article class="card metric <?= $varianceBadgeClass === 'paid' ? 'paid' : 'unpaid' ?>">
        <span class="metric-label">Budget Variance (Selected Period):</span>
        <strong><span class="card-value"><?= formatKsh($varianceAmount) ?></span></strong>
        <span class="badge <?= h($varianceBadgeClass) ?>"><?= number_format($variancePercent, 2) ?>%</span>
    </article>
    <article class="card metric">
        <span class="metric-label">Selected Period Expected:</span>
        <strong><span class="card-value"><?= formatKsh($periodExpected) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Selected Period Paid:</span>
        <strong><span class="card-value"><?= formatKsh($periodPaid) ?></span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Selected Period Outstanding:</span>
        <strong><span class="card-value"><?= formatKsh($periodOutstanding) ?></span></strong>
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
                    <td class="text-unpaid"><?= formatKsh($outstanding) ?></td>
                </tr>
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
    quarterly: <?= json_encode($quarterly, JSON_THROW_ON_ERROR) ?>
};
</script>
<?php renderFooter(); ?>
