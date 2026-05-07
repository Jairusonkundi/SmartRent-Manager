<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/BudgetService.php';

requireAuth();
$year = (int) ($_GET['year'] ?? date('Y'));
$selectedMonthInput = (string) ($_GET['month'] ?? sprintf('%04d-%02d', $year, (int) date('n')));
$selectedMonth = $selectedMonthInput === 'all' ? 'all' : date('Y-m', strtotime($selectedMonthInput));
$view = (string) ($_GET['view'] ?? 'monthly');
$view = in_array($view, ['monthly', 'quarterly'], true) ? $view : 'monthly';
$currentMonth = date('Y-m');
$currentYear = (int) date('Y');
$currentQuarter = (int) ceil(((int) date('n')) / 3);
$isFuturePeriod = $selectedMonth !== 'all' && $selectedMonth > $currentMonth;
$futureBillingStartDate = $isFuturePeriod ? date('F j, Y', strtotime($selectedMonth . '-01')) : null;

$service = new BudgetService();
$budgetData = $service->dashboardData($year, 'all');
$monthly = $budgetData['monthly'];
$quarterly = $budgetData['quarterly'];
$quarterlyYoY = $service->quarterlyYearOverYear($year);

$selectedDate = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, 1));
if ($selectedMonth !== 'all') {
    $selectedDate = new DateTimeImmutable($selectedMonth . '-01');
}
$selectedQuarter = (int) ceil(((int) $selectedDate->format('n')) / 3);
$selectedReference = $view === 'quarterly' ? sprintf('Q%d', $selectedQuarter) : $selectedDate->format('F');
$periodHeadline = $view === 'quarterly'
    ? sprintf('Analysis for %s %s', $selectedReference, $selectedDate->format('Y'))
    : sprintf('Analysis for %s %s', $selectedReference, $selectedDate->format('Y'));

$periodStart = sprintf('%04d-01', $year);
$periodEnd = sprintf('%04d-12', $year);
if ($selectedMonth !== 'all') {
    $periodStart = $selectedMonth;
    $periodEnd = $selectedMonth;
    if ($view === 'quarterly') {
        $quarterStartMonth = (($selectedQuarter - 1) * 3) + 1;
        $periodStart = $selectedDate->setDate((int) $selectedDate->format('Y'), $quarterStartMonth, 1)->format('Y-m');
        $periodEnd = $selectedDate->setDate((int) $selectedDate->format('Y'), $quarterStartMonth + 2, 1)->format('Y-m');
    }
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
    <form method="get" id="budgetYearFilter" class="control-bar filter-form">
        <label>Year
            <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>">
        </label>
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <input type="hidden" name="month" value="<?= h($selectedMonth) ?>">
        <div class="control-actions">
            <button type="submit">Apply Year</button>
            <button type="button" class="button" onclick="resetFilters('budgetYearFilter','/public/budget.php')">Reset</button>
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
        <span class="metric-label">Annual Budget:</span>
        <strong><span id="totalYtdBudgetCard" class="card-value"><?= formatKsh($totalYtdBudget) ?></span></strong>
    </article>
    <article class="card metric metric-general">
        <span class="metric-label">Annual Collected:</span>
        <strong><span id="totalYtdCollectionCard" class="card-value"><?= formatKsh($totalPaid) ?></span></strong>
    </article>
    <article class="card metric metric-general">
        <span class="metric-label">Annual Arrears:</span>
        <strong><span id="totalYtdArrearsCard" class="card-value"><?= formatKsh($totalOutstanding) ?></span></strong>
    </article>
</section>
<p class="budget-ytd-note">Annual totals reflect the full selected year from imported Excel records and do not change with period filters.</p>

<section class="card analysis-filter-card">
    <form method="get" id="budgetPeriodFilter" class="control-bar filter-form">
        <input type="hidden" name="year" value="<?= $year ?>">
        <label>View
            <select name="view">
                <option value="monthly" <?= $view === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="quarterly" <?= $view === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
            </select>
        </label>
        <label>Reference Month/Quarter
            <select name="month" id="budgetReferenceSelect">
                <?php if ($view === 'quarterly'): ?>
                    <?php for ($quarterNumber = 1; $quarterNumber <= 4; $quarterNumber++): ?>
                        <?php $quarterMonthKey = sprintf('%04d-%02d', $year, (($quarterNumber - 1) * 3) + 1); ?>
                        <option value="<?= h($quarterMonthKey) ?>" <?= $selectedQuarter === $quarterNumber ? 'selected' : '' ?>>Q<?= $quarterNumber ?></option>
                    <?php endfor; ?>
                <?php else: ?>
                    <?php for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++): ?>
                        <?php $monthKey = sprintf('%04d-%02d', $year, $monthNumber); ?>
                        <option value="<?= h($monthKey) ?>" <?= $selectedMonth === $monthKey ? 'selected' : '' ?>><?= h(date('F', strtotime($monthKey . '-01'))) ?></option>
                    <?php endfor; ?>
                <?php endif; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit">Apply Period</button>
        </div>
    </form>
</section>

<section class="card selected-period-title">
    <h3 id="analysisPeriodHeadline"><?= h($periodHeadline) ?></h3>
</section>
<section class="cards metrics-selected">
    <article class="card metric metric-selected">
        <span class="metric-label"><?= $view === 'quarterly' ? 'Quarter Budget:' : 'Month Budget:' ?></span>
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
    ], JSON_THROW_ON_ERROR) ?>,
    selectedView: <?= json_encode($view, JSON_THROW_ON_ERROR) ?>,
    selectedMonth: <?= json_encode($selectedMonth, JSON_THROW_ON_ERROR) ?>,
    selectedReference: <?= json_encode($selectedReference, JSON_THROW_ON_ERROR) ?>
};
</script>

<?php renderFooter(); ?>
