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
$budgetData = $service->dashboardData($year, $selectedMonth);
$monthly = $budgetData['monthly'];
$quarterly = $budgetData['quarterly'];
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

$totalYtdBudget = (float) $budgetData['total_ytd_budget'];
$totalPaid = (float) $budgetData['total_ytd_collection'];
$totalOutstanding = (float) $budgetData['total_ytd_arrears'];
$isYtdBalanced = abs($totalYtdBudget - ($totalPaid + $totalOutstanding)) < 0.01;

if ((string) ($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'monthly' => $monthly,
        'quarterly' => $quarterly,
        'quarterlyYoY' => $quarterlyYoY,
        'selectedYear' => $year,
        'currentYear' => $currentYear,
        'currentQuarter' => $currentQuarter,
        'totals' => [
            'totalYtdBudget' => $totalYtdBudget,
            'totalYtdCollection' => $totalPaid,
            'totalYtdArrears' => $totalOutstanding,
        ],
    ], JSON_THROW_ON_ERROR);
    exit;
}

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
<?php if (!$isYtdBalanced): ?>
<section class="card">
    <p class="negative-financial">Budget totals are out of sync. Please refresh imported records.</p>
</section>
<?php endif; ?>

<?php if ($isFuturePeriod && $futureBillingStartDate !== null): ?>
<section class="card">
    <p>Data for this period is projected. Official billing starts on <?= h($futureBillingStartDate) ?>.</p>
</section>
<?php endif; ?>

<section class="cards metrics-general">
    <article class="card metric metric-general">
        <span class="metric-label">Total YTD Budget:</span>
        <strong><span id="totalYtdBudgetCard" class="card-value"><?= formatKsh($totalYtdBudget) ?></span></strong>
    </article>
    <article class="card metric metric-general">
        <span class="metric-label">Total YTD Collection:</span>
        <strong><span id="totalYtdCollectionCard" class="card-value"><?= formatKsh($totalPaid) ?></span></strong>
    </article>
    <article class="card metric metric-general">
        <span class="metric-label">Total YTD Arrears:</span>
        <strong><span id="totalYtdArrearsCard" class="card-value"><?= formatKsh($totalOutstanding) ?></span></strong>
    </article>
</section>
<p class="budget-ytd-note">YTD reflects cumulative data from January 1st of the selected year to the current date.</p>

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
    <table id="monthlyBreakdownTable" class="sortable budget-monthly-breakdown">
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
    <table id="quarterlyComparisonTable">
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
    currentQuarter: <?= json_encode($currentQuarter, JSON_THROW_ON_ERROR) ?>,
    totals: <?= json_encode([
        'totalYtdBudget' => $totalYtdBudget,
        'totalYtdCollection' => $totalPaid,
        'totalYtdArrears' => $totalOutstanding,
    ], JSON_THROW_ON_ERROR) ?>
};
</script>

<script>
(function(){
    const form = document.getElementById('budgetFilters');
    if (!form) return;
    const controls = form.querySelectorAll('input[name="year"], input[name="month"], select[name="view"]');
    const toKsh = value => `KSh ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const sync = function () {
        const params = new URLSearchParams(new FormData(form));
        params.set('ajax', '1');
        fetch('/public/budget.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }})
            .then(resp => resp.json())
            .then(data => {
                window.budgetData = data;
                if (window.renderBudgetDashboard) window.renderBudgetDashboard();
                const ytdBudget = document.getElementById('totalYtdBudgetCard');
                const collection = document.getElementById('totalYtdCollectionCard');
                const arrears = document.getElementById('totalYtdArrearsCard');
                if (ytdBudget) ytdBudget.textContent = toKsh(data.totals.totalYtdBudget);
                if (collection) collection.textContent = toKsh(data.totals.totalYtdCollection);
                if (arrears) arrears.textContent = toKsh(data.totals.totalYtdArrears);
            })
            .catch(() => {});
    };
    controls.forEach(control => control.addEventListener('change', sync));
})();
</script>

<?php renderFooter(); ?>
