<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/RentCollectionService.php';
require_once __DIR__ . '/../modules/ExpenseService.php';
require_once __DIR__ . '/../modules/PropertyService.php';

requireAuth();

$pdo          = Database::connection();
$year         = (int) ($_GET['year'] ?? date('Y'));
$tab          = (string) ($_GET['tab'] ?? 'collection');
$tab          = in_array($tab, ['collection', 'pl', 'budget'], true) ? $tab : 'collection';
$currentMonth = date('Y-m');

$properties     = (new PropertyService())->listPropertyOptions();
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$baseParams     = ['year' => $year, 'property_id' => $propertyFilter];

// ── TAB: COLLECTION ───────────────────────────────────────────────────────────
$collectionMonthly   = [];
$collectionQuarterly = [];
$collectionYtd       = ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];

if ($tab === 'collection') {
    $rcService         = new RentCollectionService();
    $collectionMonthly = $rcService->getMonthlyCollectionWithProperty($year, $propertyFilter);

    $qMap = ['Q1' => ['e' => 0.0, 'p' => 0.0, 'o' => 0.0],
             'Q2' => ['e' => 0.0, 'p' => 0.0, 'o' => 0.0],
             'Q3' => ['e' => 0.0, 'p' => 0.0, 'o' => 0.0],
             'Q4' => ['e' => 0.0, 'p' => 0.0, 'o' => 0.0]];
    foreach ($collectionMonthly as $row) {
        $mNum = (int) substr((string) $row['month_key'], 5, 2);
        $q    = 'Q' . (string) (int) ceil($mNum / 3);
        $qMap[$q]['e'] += (float) $row['expected'];
        $qMap[$q]['p'] += (float) $row['paid'];
        // Accumulate per-month GREATEST(outstanding, 0) so that an overpaid month
        // never offsets genuine arrears in a different month within the same quarter.
        $qMap[$q]['o'] += (float) $row['outstanding'];
    }
    foreach ($qMap as $label => $vals) {
        $collectionQuarterly[] = [
            'label'        => $label,
            'expected'     => $vals['e'],
            'paid'         => $vals['p'],
            'outstanding'  => $vals['o'],
            'variance_pct' => $vals['e'] > 0 ? (($vals['p'] - $vals['e']) / $vals['e']) * 100 : 0.0,
        ];
    }
    foreach ($collectionMonthly as $row) {
        $collectionYtd['expected']    += (float) $row['expected'];
        $collectionYtd['paid']        += (float) $row['paid'];
        $collectionYtd['outstanding'] += (float) $row['outstanding'];
    }
}

// ── TAB: P&L ──────────────────────────────────────────────────────────────────
$plRows   = [];
$plTotals = ['rent' => 0.0, 'expenses' => 0.0, 'net' => 0.0];

if ($tab === 'pl') {
    $rentStmt = $pdo->prepare(
        "SELECT billing_month, COALESCE(SUM(amount_paid), 0) AS rent_collected
         FROM payments
         WHERE LEFT(billing_month, 4) = ?
           AND billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
         GROUP BY billing_month
         ORDER BY billing_month"
    );
    $rentStmt->execute([(string) $year]);
    $rentByMonth = [];
    foreach ($rentStmt->fetchAll() ?: [] as $row) {
        $rentByMonth[(string) $row['billing_month']] = (float) $row['rent_collected'];
    }

    $expByMonth = (new ExpenseService())->getDisbursedByMonth($year);

    for ($m = 1; $m <= 12; $m++) {
        $monthKey = sprintf('%04d-%02d', $year, $m);
        if ($monthKey > $currentMonth) {
            break;
        }
        $rent     = $rentByMonth[$monthKey] ?? 0.0;
        $expenses = $expByMonth[$monthKey]  ?? 0.0;
        $net      = $rent - $expenses;
        $plRows[] = ['month' => $monthKey, 'rent' => $rent, 'expenses' => $expenses, 'net_income' => $net];
        $plTotals['rent']     += $rent;
        $plTotals['expenses'] += $expenses;
        $plTotals['net']      += $net;
    }
}

// ── TAB: BUDGET ───────────────────────────────────────────────────────────────
$budgetMonthly  = [];
$budgetQuarters = [];
$budgetTotals   = ['ytd_budget' => 0.0, 'ytd_collected' => 0.0, 'ytd_arrears' => 0.0];
$ytdAchievement = 0.0;

if ($tab === 'budget') {
    $budgetService  = new RentCollectionService();
    $budgetData     = $budgetService->dashboardData($year, 'all');
    $budgetMonthly  = $budgetData['monthly'];
    $budgetQuarters = $budgetService->quarterlyComparison($year);
    $budgetTotals   = [
        'ytd_budget'    => (float) $budgetData['total_ytd_budget'],
        'ytd_collected' => (float) $budgetData['total_ytd_collection'],
        'ytd_arrears'   => (float) $budgetData['total_ytd_arrears'],
    ];
    $ytdAchievement = $budgetTotals['ytd_budget'] > 0
        ? ($budgetTotals['ytd_collected'] / $budgetTotals['ytd_budget']) * 100
        : 0.0;
}

renderHeader('Budget and Reports');
?>

<!-- ── Shared Filter Bar ── -->
<section class="card budget-filter-card">
    <form method="get" class="control-bar filter-form budget-filter-row">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <label>Year
            <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>">
        </label>
        <label>Property
            <select name="property_id">
                <option value="all" <?= $propertyFilter === 'all' ? 'selected' : '' ?>>All Properties</option>
                <?php foreach ($properties as $prop): ?>
                    <option value="<?= (int) $prop['id'] ?>" <?= $propertyFilter === (string) $prop['id'] ? 'selected' : '' ?>><?= h($prop['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit">Apply</button>
            <button type="button" class="button button-secondary" onclick="window.location.href='/public/reports.php?tab=<?= h($tab) ?>';">Reset</button>
        </div>
    </form>
</section>

<!-- ── Tab Navigation ── -->
<nav class="tab-nav">
    <a class="<?= $tab === 'collection' ? 'active' : '' ?>" href="/public/reports.php?<?= h(http_build_query(array_merge($baseParams, ['tab' => 'collection']))) ?>">Collection Report</a>
    <a class="<?= $tab === 'pl' ? 'active' : '' ?>" href="/public/reports.php?<?= h(http_build_query(array_merge($baseParams, ['tab' => 'pl']))) ?>">Income &amp; Expenditure (P&amp;L)</a>
    <a class="<?= $tab === 'budget' ? 'active' : '' ?>" href="/public/reports.php?<?= h(http_build_query(array_merge($baseParams, ['tab' => 'budget']))) ?>">Standardized Budget</a>
</nav>

<?php if ($tab === 'collection'): ?>
<!-- ════════════════════════ COLLECTION REPORT ══════════════════════════════ -->

<section class="cards dashboard-kpi-grid">
    <article class="card metric">
        <span class="metric-label">YTD Expected:</span>
        <strong><span class="card-value"><?= formatKsh($collectionYtd['expected']) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">YTD Collected:</span>
        <strong><span class="card-value"><?= formatKsh($collectionYtd['paid']) ?></span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">YTD Outstanding:</span>
        <strong><span class="card-value"><?= formatKsh($collectionYtd['outstanding']) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">Collection Rate:</span>
        <?php $rate = $collectionYtd['expected'] > 0 ? ($collectionYtd['paid'] / $collectionYtd['expected']) * 100 : 0.0; ?>
        <strong><span class="card-value"><?= number_format($rate, 2) ?>%</span></strong>
    </article>
</section>

<section class="card">
    <h3>Monthly Collection Performance — <?= $year ?></h3>
    <?php if (empty($collectionMonthly)): ?>
        <p class="muted-text">No collection data found for <?= $year ?>.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="sortable">
            <thead>
                <tr><th>Month</th><th>Expected (KSh)</th><th>Collected (KSh)</th><th>Outstanding (KSh)</th><th>Collection %</th><th>Variance (KSh)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($collectionMonthly as $row): ?>
                    <?php
                        $exp  = (float) $row['expected'];
                        $paid = (float) $row['paid'];
                        $outs = max($exp - $paid, 0);
                        $pct  = $exp > 0 ? ($paid / $exp) * 100 : 0.0;
                        $var  = $paid - $exp;
                    ?>
                    <tr>
                        <td><?= h((string) $row['month_key']) ?></td>
                        <td><?= formatKsh($exp) ?></td>
                        <td class="text-paid"><?= formatKsh($paid) ?></td>
                        <td class="<?= $outs > 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($outs) ?></td>
                        <td><?= number_format($pct, 2) ?>%</td>
                        <td class="<?= $var < 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($var) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php $ytdVar = $collectionYtd['paid'] - $collectionYtd['expected']; ?>
                <tr class="table-total-row">
                    <td><strong>YTD Total</strong></td>
                    <td><?= formatKsh($collectionYtd['expected']) ?></td>
                    <td class="text-paid"><?= formatKsh($collectionYtd['paid']) ?></td>
                    <td class="<?= $collectionYtd['outstanding'] > 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($collectionYtd['outstanding']) ?></td>
                    <td><?= number_format($rate, 2) ?>%</td>
                    <td class="<?= $ytdVar < 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($ytdVar) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Quarterly Collection Summary — <?= $year ?></h3>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Quarter</th><th>Expected (KSh)</th><th>Collected (KSh)</th><th>Outstanding (KSh)</th><th>Variance %</th></tr></thead>
            <tbody>
                <?php foreach ($collectionQuarterly as $row): ?>
                    <tr>
                        <td><?= h($row['label']) ?></td>
                        <td><?= formatKsh($row['expected']) ?></td>
                        <td class="text-paid"><?= formatKsh($row['paid']) ?></td>
                        <td class="<?= $row['outstanding'] > 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($row['outstanding']) ?></td>
                        <td class="<?= $row['variance_pct'] < 0 ? 'negative-financial' : 'text-paid' ?>"><?= number_format($row['variance_pct'], 2) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($tab === 'pl'): ?>
<!-- ════════════════════════ INCOME & EXPENDITURE ════════════════════════════ -->

<section class="cards dashboard-kpi-grid">
    <article class="card metric paid">
        <span class="metric-label">Total Rent Collected (<?= $year ?>):</span>
        <strong><span class="card-value"><?= formatKsh($plTotals['rent']) ?></span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Total Disbursed Expenses (<?= $year ?>):</span>
        <strong><span class="card-value"><?= formatKsh($plTotals['expenses']) ?></span></strong>
    </article>
    <article class="card metric <?= $plTotals['net'] >= 0 ? 'paid' : 'unpaid' ?>">
        <span class="metric-label">Net Income (<?= $year ?>):</span>
        <strong><span class="card-value <?= $plTotals['net'] < 0 ? 'negative-financial' : '' ?>"><?= formatKsh($plTotals['net']) ?></span></strong>
    </article>
</section>
<p class="budget-ytd-note">Net Income = Total Rent Collected &minus; Disbursed Expenses. Only expenses with status <em>Disbursed/Paid</em> are included in the deduction.</p>

<section class="card">
    <h3>Income &amp; Expenditure Ledger — <?= $year ?></h3>
    <?php if (empty($plRows)): ?>
        <p class="muted-text">No data available for <?= $year ?>.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="sortable">
            <thead>
                <tr><th>Month</th><th>Rent Collected (KSh)</th><th>Disbursed Expenses (KSh)</th><th>Net Income (KSh)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($plRows as $row): ?>
                    <tr>
                        <td><?= h($row['month']) ?></td>
                        <td class="text-paid"><?= formatKsh($row['rent']) ?></td>
                        <td class="<?= $row['expenses'] > 0 ? 'negative-financial' : '' ?>"><?= formatKsh($row['expenses']) ?></td>
                        <td class="<?= $row['net_income'] < 0 ? 'negative-financial' : 'text-paid' ?>"><strong><?= formatKsh($row['net_income']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-total-row">
                    <td><strong>Total</strong></td>
                    <td class="text-paid"><?= formatKsh($plTotals['rent']) ?></td>
                    <td class="<?= $plTotals['expenses'] > 0 ? 'negative-financial' : '' ?>"><?= formatKsh($plTotals['expenses']) ?></td>
                    <td class="<?= $plTotals['net'] < 0 ? 'negative-financial' : 'text-paid' ?>"><strong><?= formatKsh($plTotals['net']) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'budget'): ?>
<!-- ════════════════════════ STANDARDIZED BUDGET ════════════════════════════ -->

<section class="cards dashboard-kpi-grid">
    <article class="card metric metric-general">
        <span class="metric-label">YTD Budget (Expected):</span>
        <strong><span class="card-value"><?= formatKsh($budgetTotals['ytd_budget']) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">YTD Collected:</span>
        <strong><span class="card-value"><?= formatKsh($budgetTotals['ytd_collected']) ?></span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">YTD Arrears:</span>
        <strong><span class="card-value"><?= formatKsh($budgetTotals['ytd_arrears']) ?></span></strong>
    </article>
    <article class="card metric">
        <span class="metric-label">YTD Achievement:</span>
        <strong><span class="card-value"><?= number_format($ytdAchievement, 2) ?>%</span></strong>
    </article>
</section>
<p class="budget-ytd-note">YTD cumulative totals from January 1st of <?= $year ?> to the current date.</p>

<section class="card">
    <h3>Month-by-Month Budget vs Actual — <?= $year ?></h3>
    <?php if (empty($budgetMonthly)): ?>
        <p class="muted-text">No budget data for <?= $year ?>.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="sortable">
            <thead>
                <tr><th>Month</th><th>Budget / Expected (KSh)</th><th>Actual Collected (KSh)</th><th>Outstanding (KSh)</th><th>Achievement %</th></tr>
            </thead>
            <tbody>
                <?php foreach ($budgetMonthly as $row): ?>
                    <?php
                        $exp  = (float) $row['expected'];
                        $paid = (float) $row['paid'];
                        $outs = max($exp - $paid, 0);
                        $pct  = $exp > 0 ? ($paid / $exp) * 100 : 0.0;
                    ?>
                    <tr>
                        <td><?= h((string) $row['month_key']) ?></td>
                        <td><?= formatKsh($exp) ?></td>
                        <td class="text-paid"><?= formatKsh($paid) ?></td>
                        <td class="<?= $outs > 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($outs) ?></td>
                        <td><?= number_format($pct, 2) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-total-row">
                    <td><strong>YTD</strong></td>
                    <td><?= formatKsh($budgetTotals['ytd_budget']) ?></td>
                    <td class="text-paid"><?= formatKsh($budgetTotals['ytd_collected']) ?></td>
                    <td class="<?= $budgetTotals['ytd_arrears'] > 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh($budgetTotals['ytd_arrears']) ?></td>
                    <td><?= number_format($ytdAchievement, 2) ?>%</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Quarterly Budget vs Actual — <?= $year ?></h3>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Quarter</th><th>Expected (KSh)</th><th>Collected (KSh)</th><th>Outstanding (KSh)</th><th>Achievement %</th></tr></thead>
            <tbody>
                <?php foreach ($budgetQuarters as $row): ?>
                    <?php $qPct = (float) $row['expected'] > 0 ? ((float) $row['paid'] / (float) $row['expected']) * 100 : 0.0; ?>
                    <tr>
                        <td><?= h((string) $row['quarter_label']) ?></td>
                        <td><?= formatKsh((float) $row['expected']) ?></td>
                        <td class="text-paid"><?= formatKsh((float) $row['paid']) ?></td>
                        <td class="<?= (float) $row['outstanding'] > 0 ? 'negative-financial' : 'text-paid' ?>"><?= formatKsh((float) $row['outstanding']) ?></td>
                        <td><?= number_format($qPct, 2) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php endif; ?>

<?php renderFooter(); ?>
