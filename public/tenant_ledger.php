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
$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['Paid', 'Partial', 'Unpaid', 'Overdue'];
$monthFrom = (string) ($_GET['month_from'] ?? '');
$monthTo = (string) ($_GET['month_to'] ?? '');

if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();
$paymentService = new PaymentService();
$allLedgerRows = $paymentService->tenantLedger($search, $propertyId, $statusFilter, $monthFrom, $monthTo);
$totalRecords = count($allLedgerRows);

$totalExpected = 0.0;
$totalPaid = 0.0;
$totalBalance = 0.0;
foreach ($allLedgerRows as $ledgerRow) {
    $totalExpected += (float) ($ledgerRow['amount_expected'] ?? 0);
    $totalPaid += (float) ($ledgerRow['amount_paid'] ?? 0);
    $totalBalance += (float) ($ledgerRow['balance'] ?? 0);
}

if ($isAllLimit) {
    $ledgerRows = $allLedgerRows;
    $offset = 0;
    $paginationLimit = max(1, $totalRecords);
} else {
    $offset = ($page - 1) * $limit;
    $ledgerRows = array_slice($allLedgerRows, $offset, $limit);
    $paginationLimit = $limit;
}

$currentCount = count($ledgerRows);
$paginationHtml = renderPaginationLinks($totalRecords, $page, $paginationLimit, [
    'limit' => $isAllLimit ? 'all' : (string) $limit,
    'search' => $search,
    'property_id' => $propertyFilter,
    'status' => $statusFilter,
    'month_from' => $monthFrom,
    'month_to' => $monthTo,
]);

$formatMonth = static function (string $billingMonth): string {
    $monthDate = DateTimeImmutable::createFromFormat('Y-m', $billingMonth) ?: new DateTimeImmutable($billingMonth . '-01');

    return $monthDate->format('M Y');
};

renderHeader('Tenant Ledger');
?>
<section class="card tenant-ledger">
    <h3>Tenant Ledger</h3>
    <p class="section-help">Tenant contact details and Excel-imported financial records are consolidated here. Amount Paid comes directly from the import, while Current Balance is calculated as Expected Amount minus Amount Paid.</p>
    <div class="cards arrears-summary-cards">
        <article class="card metric">
            <span class="metric-label">Expected Amount</span>
            <strong><?= formatKsh($totalExpected) ?></strong>
        </article>
        <article class="card metric paid">
            <span class="metric-label">Amount Paid</span>
            <strong><?= formatKsh($totalPaid) ?></strong>
        </article>
        <article class="card metric unpaid">
            <span class="metric-label">Current Balance</span>
            <strong><?= formatKsh($totalBalance) ?></strong>
        </article>
    </div>
    <form method="get" id="tenantLedgerFilters" class="control-bar filter-form">
        <label>Limit
            <select name="limit">
                <?php foreach ([5, 10, 15, 20] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= !$isAllLimit && $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option>
                <?php endforeach; ?>
                <option value="all" <?= $isAllLimit ? 'selected' : '' ?>>All</option>
            </select>
        </label>
        <label>Search
            <input type="text" id="tenantLedgerSearchInput" name="search" value="<?= h($search) ?>" placeholder="Tenant, phone, property, or unit">
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
        <label>Month From
            <input type="month" name="month_from" value="<?= h($monthFrom) ?>">
        </label>
        <label>Month To
            <input type="month" name="month_to" value="<?= h($monthTo) ?>">
        </label>
        <label>Status
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit">Search</button>
            <button type="button" class="button" onclick="resetFilters('tenantLedgerFilters','/public/tenant_ledger.php')">Clear Filters</button>
        </div>
    </form>
    <p>Showing <?= $currentCount ?> records | Total Found: <?= $totalRecords ?></p>
    <?php if ($totalRecords === 0): ?>
        <div class="alert">Welcome! Please upload your CSV to populate the tenant ledger.</div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="tenant-ledger-table arrears-accordion-table">
        <thead>
            <tr><th>#</th><th>Name</th><th>Phone Number</th><th>Property</th><th>Unit</th><th>Rent Month</th><th>Expected Amount (KSh)</th><th>Amount Paid (KSh)</th><th>Current Balance (KSh)</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($ledgerRows as $index => $row): ?>
                <?php
                    $rowNumber = $offset + $index + 1;
                    $detailId = 'ledger-tenant-' . (int) $row['tenant_id'];
                    $details = $row['details'] ?? [];
                    $hasMultipleMonths = count($details) > 1;
                    $status = (string) $row['status'];
                ?>
                <tr class="arrears-parent-row tenant-ledger-parent-row">
                    <td><?= $rowNumber ?></td>
                    <td class="tenant-name-cell"><button type="button" class="tenant-toggle" aria-expanded="false" aria-controls="<?= h($detailId) ?>" <?= $hasMultipleMonths ? '' : 'disabled' ?>><?= h((string) $row['name']) ?></button></td>
                    <td><?= h((string) ($row['phone'] ?: '-')) ?></td>
                    <td class="property-cell"><?= h((string) $row['property_name']) ?></td>
                    <td class="unit-cell"><?= h((string) $row['unit_number']) ?></td>
                    <td><?= h($formatMonth((string) $row['billing_month'])) ?></td>
                    <td><?= formatKsh((float) $row['amount_expected']) ?></td>
                    <td><?= formatKsh((float) $row['amount_paid']) ?></td>
                    <td class="<?= (float) $row['balance'] > 0 ? 'text-unpaid' : 'text-paid' ?>"><?= formatKsh((float) $row['balance']) ?></td>
                    <td><span class="badge <?= h(strtolower($status === 'Overdue' ? 'unpaid' : $status)) ?>"><?= h($status) ?></span></td>
                    <td>
                        <?php if ($hasMultipleMonths): ?>
                            <button type="button" class="button arrears-toggle-button" data-target="<?= h($detailId) ?>">View History ⌄</button>
                        <?php else: ?>
                            <span class="muted-text">Single month</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($hasMultipleMonths): ?>
                <tr id="<?= h($detailId) ?>" class="arrears-detail-row" hidden>
                    <td colspan="11">
                        <table class="arrears-detail-table tenant-ledger-detail-table">
                            <thead>
                                <tr><th>Rent Month</th><th>Expected Amount (KSh)</th><th>Amount Paid (KSh)</th><th>Current Balance (KSh)</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($details as $detail): ?>
                                <?php $detailStatus = (string) $detail['status']; ?>
                                <tr>
                                    <td><?= h($formatMonth((string) $detail['billing_month'])) ?></td>
                                    <td><?= formatKsh((float) $detail['amount_expected']) ?></td>
                                    <td><?= formatKsh((float) $detail['amount_paid']) ?></td>
                                    <td class="<?= (float) $detail['balance'] > 0 ? 'text-unpaid' : 'text-paid' ?>"><?= formatKsh((float) $detail['balance']) ?></td>
                                    <td><span class="badge <?= h(strtolower($detailStatus === 'Overdue' ? 'unpaid' : $detailStatus)) ?>"><?= h($detailStatus) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?= $paginationHtml ?>
</section>
<script>
    (function () {
        const searchInput = document.getElementById('tenantLedgerSearchInput');
        const form = document.getElementById('tenantLedgerFilters');
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
        const buttons = document.querySelectorAll('.tenant-ledger .arrears-toggle-button, .tenant-ledger .tenant-toggle:not([disabled])');
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
                        ctrl.textContent = isHidden ? 'Hide History ⌃' : 'View History ⌄';
                    }
                });
            });
        });
    }());
</script>
<?php renderFooter(); ?>
