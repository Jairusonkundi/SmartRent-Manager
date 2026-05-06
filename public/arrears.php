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
$propertyFilter = (string) ($_GET['property_id'] ?? ($_SESSION['global_property_filter'] ?? 'all'));
$_SESSION['global_property_filter'] = $propertyFilter;
$propertyId = $propertyFilter !== 'all' ? (int) $propertyFilter : null;

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();

$paymentService = new PaymentService();
$allArrears = $paymentService->arrearsSummaryByTenant($search, $propertyId);
$totalRecords = count($allArrears);
$portfolioTotal = 0.0;
$oldestDebtMonth = null;
foreach ($allArrears as $tenantArrear) {
    $portfolioTotal += (float) ($tenantArrear['total_arrears'] ?? 0);
    foreach (($tenantArrear['details'] ?? []) as $detail) {
        $balance = (float) ($detail['balance'] ?? 0);
        if ($balance <= 0) {
            continue;
        }
        $monthDate = DateTimeImmutable::createFromFormat('Y-m', (string) $detail['billing_month']);
        if (!$monthDate) {
            $monthDate = new DateTimeImmutable((string) $detail['billing_month'] . '-01');
        }
        if ($oldestDebtMonth === null || $monthDate < $oldestDebtMonth) {
            $oldestDebtMonth = $monthDate;
        }
    }
}

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
$highestArrears = (float) ($allArrears[0]['total_arrears'] ?? 0);
?>
<section class="card">
    <h3>Balance Owed per Tenant by Month</h3>
    <div class="cards arrears-summary-cards">
        <article class="card metric unpaid">
            <span class="metric-label">Total Arrears (Portfolio)</span>
            <strong><?= 'Ksh ' . number_format($portfolioTotal, 2) ?></strong>
        </article>
        <article class="card metric">
            <span class="metric-label">Affected Tenants</span>
            <strong><?= number_format($totalRecords) ?></strong>
        </article>
        <article class="card metric">
            <span class="metric-label">Oldest Debt</span>
            <strong><?= $oldestDebtMonth ? h($oldestDebtMonth->format('F Y')) : 'N/A' ?></strong>
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
            <input type="text" id="arrearsSearchInput" name="search" value="<?= h($search) ?>" placeholder="Tenant, unit, or property">
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
            <button type="button" class="button" onclick="resetFilters('arrearsFilters','/public/arrears.php')">Clear Filters</button>
        </div>
    </form>
    <p>Showing <?= $currentCount ?> records | Total Found: <?= $totalRecords ?></p>
    <?php if ($totalRecords === 0): ?>
        <div class="alert">Welcome! Please upload your CSV to begin.</div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="arrears-accordion-table">
        <thead><tr><th>#</th><th>Tenant</th><th>Property</th><th>Unit</th><th>Months Owed</th><th>Total Arrears</th><th>Action</th></tr></thead>
        <tbody>
            <?php foreach ($arrears as $index => $row): ?>
                <?php $rowNumber = $offset + $index + 1; ?>
                <?php $detailId = 'tenant-' . (int) $row['tenant_id']; ?>
                <?php $totalArrears = (float) ($row['total_arrears'] ?? 0); ?>
                <?php $isHighestDebt = $highestArrears > 0 && $totalArrears === $highestArrears; ?>
                <tr class="arrears-parent-row<?= $isHighestDebt ? ' highest-arrears-row' : '' ?>">
                    <td><?= $rowNumber ?></td>
                    <td><button type="button" class="tenant-toggle" aria-expanded="false" aria-controls="<?= h($detailId) ?>"><?= h((string) $row['name']) ?></button></td>
                    <td><?= h((string) $row['property_name']) ?></td>
                    <td><?= h((string) $row['unit_number']) ?></td>
                    <td class="months-owed"><?= h((string) ($row['months_owed'] ?? '')) ?></td>
                    <td class="arrears-total<?= $isHighestDebt ? ' arrears-total-highest' : '' ?>"><?= 'Ksh ' . number_format($totalArrears, 2) ?></td>
                    <td><button type="button" class="button arrears-toggle-button" data-target="<?= h($detailId) ?>">View Details ⌄</button></td>
                </tr>
                <tr id="<?= h($detailId) ?>" class="arrears-detail-row" hidden>
                    <td colspan="7">
                        <table class="arrears-detail-table">
                            <thead>
                                <tr><th>Month</th><th>Expected</th><th>Paid</th><th>Balance</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach (($row['details'] ?? []) as $detail): ?>
                                <?php
                                    $monthDate = DateTimeImmutable::createFromFormat('Y-m', (string) $detail['billing_month']) ?: new DateTimeImmutable((string) $detail['billing_month'] . '-01');
                                    $monthLabel = $monthDate->format('F Y');
                                    $monthsOverdue = ((int) $currentMonthDate->format('Y') - (int) $monthDate->format('Y')) * 12 + ((int) $currentMonthDate->format('n') - (int) $monthDate->format('n'));
                                    $statusLabel = $monthsOverdue >= 2 ? '🔴 Critical' : '🟠 Overdue';
                                ?>
                                <tr>
                                    <td><?= h($monthLabel) ?></td>
                                    <td><?= 'Ksh ' . number_format((float) $detail['amount_expected'], 2) ?></td>
                                    <td><?= 'Ksh ' . number_format((float) $detail['amount_paid'], 2) ?></td>
                                    <td class="text-unpaid"><?= 'Ksh ' . number_format((float) $detail['balance'], 2) ?></td>
                                    <td><?= h($statusLabel) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
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
        const buttons = document.querySelectorAll('.arrears-toggle-button, .tenant-toggle');
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                const rowId = button.dataset.target || button.getAttribute('aria-controls');
                if (!rowId) return;
                const detailRow = document.getElementById(rowId);
                if (!detailRow) return;
                const isHidden = detailRow.hasAttribute('hidden');
                detailRow.toggleAttribute('hidden');
                document.querySelectorAll('[data-target="' + rowId + '"], [aria-controls="' + rowId + '"]').forEach(function (ctrl) {
                    ctrl.setAttribute('aria-expanded', String(isHidden));
                    if (ctrl.classList.contains('arrears-toggle-button')) {
                        ctrl.textContent = isHidden ? 'Hide Details ⌃' : 'View Details ⌄';
                    }
                });
            });
        });
    }());
</script>
<?php renderFooter(); ?>
