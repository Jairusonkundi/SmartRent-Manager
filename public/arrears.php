<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/PaymentService.php';

requireAuth();
$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$page = max(1, (int) ($_GET['page'] ?? 1));
$requestedLimit = (string) ($_GET['limit'] ?? '10');
$isAllLimit = $requestedLimit === 'all';
$limit = $isAllLimit ? 10 : (int) $requestedLimit;
$allowedLimits = [5, 10, 15, 20];
if (!$isAllLimit && !in_array($limit, $allowedLimits, true)) {
    $limit = 10;
}

$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $page = 1;
}
$propertyFilter = (string) ($_GET['property_id'] ?? ($_SESSION['global_property_filter'] ?? 'all'));
$_SESSION['global_property_filter'] = $propertyFilter;
$propertyId = $propertyFilter !== 'all' ? (int) $propertyFilter : null;

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$paymentService = new PaymentService();
$allArrears = $paymentService->arrearsSummaryByTenant($search, $propertyId);
$portfolioStats = $paymentService->arrearsPortfolioStats($propertyId);
$totalRecords = count($allArrears);
$portfolioTotal = (float) $portfolioStats['total_arrears'];
$totalExpectedYtd = (float) $portfolioStats['total_expected_ytd'];
$collectionGapPercent = $totalExpectedYtd > 0 ? ($portfolioTotal / $totalExpectedYtd) * 100 : 0.0;
$highRiskTenants = (int) $portfolioStats['high_risk_tenants'];

if ($isAllLimit) {
    $arrears = $allArrears;
    $offset = 0;
    $paginationLimit = max(1, $totalRecords);
} else {
    $offset = ($page - 1) * $limit;
    $arrears = array_slice($allArrears, $offset, $limit);
    $paginationLimit = $limit;
}

$currentCount = count($arrears);
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
]);

renderHeader('Arrears');

$currentMonthDate = new DateTimeImmutable('first day of this month');
?>
<section class="card arrears-management">
    <h3>Arrears Hit List (highest debt first)</h3>
    <div class="cards arrears-summary-cards">
        <article class="card metric unpaid">
            <span class="metric-label">Total Portfolio Arrears</span>
            <strong><?= 'Ksh ' . number_format($portfolioTotal, 2) ?></strong>
        </article>
        <article class="card metric">
            <span class="metric-label">High-Risk Tenants</span>
            <strong><?= number_format($highRiskTenants) ?></strong>
            <small>Owing more than 2 months</small>
        </article>
        <article class="card metric">
            <span class="metric-label">Collection Gap %</span>
            <strong><?= number_format($collectionGapPercent, 2) ?>%</strong>
            <small>Total arrears vs. expected YTD</small>
        </article>
    </div>
    <form method="get" id="arrearsFilters" class="control-bar filter-form">
        <label>Limit
            <select name="limit">
                <?php foreach ([5, 10, 15, 20] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= !$isAllLimit && $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option>
                <?php endforeach; ?>
                <option value="all" <?= $isAllLimit ? 'selected' : '' ?>>All</option>
            </select>
        </label>
        <label>Search
            <input type="text" id="arrearsSearchInput" name="search" value="<?= h($search) ?>" placeholder="Tenant or unit number">
        </label>
        <label>Property
            <select name="property_id">
                <option value="all" <?= $propertyFilter === 'all' ? 'selected' : '' ?>>All Properties</option>
                <?php foreach ($properties as $property): ?>
                    <option value="<?= (int) $property['id'] ?>" <?= $propertyFilter === (string) $property['id'] ? 'selected' : '' ?>>
                        <?= h($property['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit">Search</button>
            <button type="button" class="button" onclick="resetFilters('arrearsFilters','/public/arrears.php?property_id=all&limit=10')">Clear Filters</button>
        </div>
    </form>
    <p>Showing <?= $currentCount ?> tenant rows sorted by highest Total Debt | Total Found: <?= $totalRecords ?></p>
    <?php if ($totalRecords === 0): ?>
        <div class="alert">Welcome! Please upload your CSV to begin.</div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="arrears-accordion-table">
        <thead><tr><th>Rank</th><th>Tenant Name</th><th>Unit Number</th><th>Total Debt (Ksh)</th><th>Aging Status</th></tr></thead>
        <tbody>
            <?php foreach ($arrears as $index => $row): ?>
                <?php $rowNumber = $offset + $index + 1; ?>
                <?php
                    $detailId = 'tenant-' . (int) $row['tenant_id'];
                    $unpaidMonths = count($row['details'] ?? []);
                    $agingStatus = $unpaidMonths === 1 ? '1 Month Overdue' : $unpaidMonths . ' Months Overdue';
                    $isAutoExpanded = $search !== '';
                ?>
                <tr class="arrears-parent-row" data-target="<?= h($detailId) ?>" tabindex="0" aria-expanded="<?= $isAutoExpanded ? 'true' : 'false' ?>">
                    <td><span class="accordion-arrow" aria-hidden="true">▸</span> <?= $rowNumber ?></td>
                    <td>
                        <button type="button" class="tenant-toggle" aria-expanded="<?= $isAutoExpanded ? 'true' : 'false' ?>" aria-controls="<?= h($detailId) ?>">
                            <?= h((string) $row['name']) ?>
                        </button>
                        <small><?= h((string) $row['property_name']) ?></small>
                    </td>
                    <td><?= h((string) $row['unit_number']) ?></td>
                    <td class="arrears-total-debt"><?= 'Ksh ' . number_format((float) $row['total_debt'], 2) ?></td>
                    <td><span class="badge unpaid"><?= h($agingStatus) ?></span></td>
                </tr>
                <tr id="<?= h($detailId) ?>" class="arrears-detail-row" <?= $isAutoExpanded ? 'data-expanded="true"' : 'hidden' ?>>
                    <td colspan="5">
                        <div class="arrears-detail-panel">
                        <table class="arrears-detail-table">
                            <thead>
                                <tr><th>Specific Month</th><th>Monthly Rent</th><th>Actual Paid</th><th>Remaining Balance</th><th>Overdue Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach (($row['details'] ?? []) as $detail): ?>
                                <?php
                                    $monthDate = DateTimeImmutable::createFromFormat('Y-m', (string) $detail['billing_month']) ?: new DateTimeImmutable((string) $detail['billing_month'] . '-01');
                                    $monthLabel = $monthDate->format('F Y');
                                    $monthsOverdue = ((int) $currentMonthDate->format('Y') - (int) $monthDate->format('Y')) * 12 + ((int) $currentMonthDate->format('n') - (int) $monthDate->format('n'));
                                    $daysOverdue = max(30, ($monthsOverdue + 1) * 30);
                                    $statusLabel = $daysOverdue . ' Days Overdue';
                                ?>
                                <tr>
                                    <td><?= h($monthLabel) ?></td>
                                    <td><?= 'Ksh ' . number_format((float) $detail['amount_expected'], 2) ?></td>
                                    <td><?= 'Ksh ' . number_format((float) $detail['amount_paid'], 2) ?></td>
                                    <td class="text-unpaid"><?= 'Ksh ' . number_format((float) $detail['balance'], 2) ?></td>
                                    <td><span class="badge month-risk"><?= h($statusLabel) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?= $paginationHtml ?>
</section>
<script>
    (function () {
        const searchInput = document.getElementById('arrearsSearchInput');
        const form = document.getElementById('arrearsFilters');
        if (!searchInput || !form) return;
        let timeoutId = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(function () {
                form.submit();
            }, 250);
        });
    }());
    (function () {
        const setExpanded = function (detailRow, expanded) {
            const panel = detailRow.querySelector('.arrears-detail-panel');
            if (!panel) return;

            if (expanded) {
                detailRow.hidden = false;
                requestAnimationFrame(function () {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    detailRow.dataset.expanded = 'true';
                });
            } else {
                panel.style.maxHeight = panel.scrollHeight + 'px';
                requestAnimationFrame(function () {
                    panel.style.maxHeight = '0px';
                    delete detailRow.dataset.expanded;
                });
                window.setTimeout(function () {
                    if (!detailRow.dataset.expanded) {
                        detailRow.hidden = true;
                    }
                }, 240);
            }
        };

        const syncControls = function (rowId, expanded) {
            document.querySelectorAll('[data-target="' + rowId + '"], [aria-controls="' + rowId + '"]').forEach(function (ctrl) {
                ctrl.setAttribute('aria-expanded', String(expanded));
            });
        };

        document.querySelectorAll('.arrears-detail-row').forEach(function (detailRow) {
            const panel = detailRow.querySelector('.arrears-detail-panel');
            if (!panel) return;
            if (detailRow.dataset.expanded === 'true') {
                detailRow.hidden = false;
                panel.style.maxHeight = panel.scrollHeight + 'px';
                syncControls(detailRow.id, true);
            } else {
                panel.style.maxHeight = '0px';
            }
        });

        document.querySelectorAll('.arrears-parent-row, .tenant-toggle').forEach(function (trigger) {
            trigger.addEventListener('click', function (event) {
                const rowId = trigger.dataset.target || trigger.getAttribute('aria-controls');
                if (!rowId) return;
                const detailRow = document.getElementById(rowId);
                if (!detailRow) return;
                const expanded = detailRow.dataset.expanded === 'true';
                setExpanded(detailRow, !expanded);
                syncControls(rowId, !expanded);
                event.stopPropagation();
            });

            trigger.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                trigger.click();
            });
        });
    }());
</script>
<?php renderFooter(); ?>
