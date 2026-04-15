<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/BudgetService.php';

requireAuth();
$year = (int) ($_GET['year'] ?? date('Y'));
$service = new BudgetService();
$monthly = $service->monthlyBreakdown($year);
$quarterly = $service->quarterlyComparison($year);

$totalExpected = array_sum(array_map(fn($r) => (float) $r['expected'], $monthly));
$totalPaid = array_sum(array_map(fn($r) => (float) $r['paid'], $monthly));
$totalOutstanding = array_sum(array_map(fn($r) => max((float) $r['outstanding'], 0), $monthly));

renderHeader('Budget');
?>
<section class="cards">
    <article class="card metric"><span>Total Expected Rent</span><strong><?= h(formatKsh($totalExpected)) ?></strong></article>
    <article class="card metric paid"><span>Total Income</span><strong><?= h(formatKsh($totalPaid)) ?></strong></article>
    <article class="card metric unpaid"><span>Outstanding Rent</span><strong><?= h(formatKsh($totalOutstanding)) ?></strong></article>
</section>
<section class="card">
    <h3>Monthly Budget Breakdown (<?= $year ?>)</h3>
    <table class="sortable">
        <thead><tr><th>Month</th><th>Expected</th><th>Paid</th><th>Outstanding</th></tr></thead>
        <tbody>
            <?php foreach ($monthly as $row): ?>
                <tr>
                    <td><?= h($row['month_key']) ?></td>
                    <td><?= h(formatKsh((float) $row['expected'])) ?></td>
                    <td class="text-paid"><?= h(formatKsh((float) $row['paid'])) ?></td>
                    <td class="text-unpaid"><?= h(formatKsh((float) $row['outstanding'])) ?></td>
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
