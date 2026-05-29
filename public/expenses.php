<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/ExpenseService.php';
require_once __DIR__ . '/../modules/PropertyService.php';

requireAuth();

$pdo    = Database::connection();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$expenseCategories = [
    'Tax & Levies' => [
        'Rental Income Tax',
        'Rental Income tax for Safa tower units',
        'Affordable Housing Levy',
        'Affordable housing levy for Safa tower units',
        'VAT Liability',
        'Rental income withholding tax',
    ],
    'Utilities & Services' => [
        'Cleaning Service',
        'Electricity',
        'Electricity token for common area',
        'Water',
        'Internet subscription',
        'DSTV subscription',
        'Telephone & Airtime',
        'Garbage Collection Fees',
        'Security & alarm response',
        'Security & alarm response for building',
        'Alarm response for Gachie House',
        'Alarm response for Rongai House',
        'Generator service',
        'Service charge for Safa Tower',
    ],
    'Operations' => [
        'Repair & Maintenance',
        'Office supplies & stationery',
        'Petty Cash',
        'Petty Cash for Cobble garden',
        'Miscellaneous/Petty Cash',
        'Fuel card top-up',
        'Car Maintenance',
        'Car wash',
        'Vehicle toll fees',
        'Vehicle tracking',
        'Vehicle tracking-KCW 630T & KDV 251Q',
        'Parking-Karen Triangle',
        'Deposit refund',
        'Rent deposit reserve',
        'Email subscription, ChatGPT, Kaspersky & Odoo hosting',
    ],
    'Corporate & HR' => [
        'Staff Salary',
        'Leave allowance',
        'Director expenses provision',
        "Director's reserves",
        'Shareholder Loan Refund-Rent',
        'Financial expense',
        'Insurance Premium',
        'Insurance Premium for political violence',
        'Insurance Premium for fire industrial',
        'Cash reserve at Bank',
        'Overdraft in Tizi ledger',
        'Sacco savings for Mrs. Muigai',
        'Medicine bill',
        'Medical expenses',
        'Medical expense-insurance',
    ],
];

$allowedStatuses = ['Requisition Pending', 'Approved', 'Disbursed/Paid'];

$periodOptions = [
    'Monthly'   => ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'],
    'Quarterly' => ['Q1', 'Q2', 'Q3', 'Q4'],
];
$validPeriods = array_merge(...array_values($periodOptions));

// ── Handle POST ────────────────────────────────────────────────────────────────
$formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $formError = 'Security token invalid. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'add_expense');

        if ($action === 'update_status') {
            $expenseId = filter_input(INPUT_POST, 'expense_id', FILTER_VALIDATE_INT);
            $newStatus = (string) ($_POST['new_status'] ?? '');
            $chequeNum = trim((string) ($_POST['cheque_number'] ?? ''));
            $retYear   = (int) ($_POST['ret_year'] ?? date('Y'));
            $retPropId = (string) ($_POST['ret_property_id'] ?? 'all');

            if ($expenseId && in_array($newStatus, $allowedStatuses, true)) {
                (new ExpenseService())->updateExpenseStatus(
                    $expenseId,
                    $newStatus,
                    $chequeNum !== '' ? $chequeNum : null
                );
                setFlash('success', 'Expense status updated to "' . $newStatus . '".');
            }
            header('Location: /public/expenses.php?year=' . $retYear . '&property_id=' . urlencode($retPropId));
            exit;
        }

        // add_expense (default action)
        $postPropertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
        $postCategory   = trim((string) ($_POST['category_name'] ?? ''));
        $postDesc       = trim((string) ($_POST['description'] ?? ''));
        $postAmount     = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $postStatus     = (string) ($_POST['status'] ?? 'Requisition Pending');
        $postCheque     = trim((string) ($_POST['cheque_number'] ?? ''));
        $postDate       = trim((string) ($_POST['expense_date'] ?? ''));
        $postPeriod     = trim((string) ($_POST['reference_period'] ?? ''));

        if (!$postPropertyId || $postPropertyId < 1) {
            $formError = 'Please select a property.';
        } elseif ($postCategory === '') {
            $formError = 'Please select an expense category.';
        } elseif ($postAmount === false || $postAmount === null || $postAmount <= 0) {
            $formError = 'Please enter a valid amount greater than zero.';
        } elseif ($postDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $postDate) !== 1) {
            $formError = 'Please enter a valid expense date (YYYY-MM-DD).';
        } elseif (!in_array($postStatus, $allowedStatuses, true)) {
            $formError = 'Invalid status selected.';
        } elseif ($postPeriod === '' || !in_array($postPeriod, $validPeriods, true)) {
            $formError = 'Please select a reference period.';
        } else {
            $service = new ExpenseService();
            $service->addExpense([
                'property_id'      => $postPropertyId,
                'category_name'    => $postCategory,
                'description'      => $postDesc,
                'amount'           => $postAmount,
                'status'           => $postStatus,
                'cheque_number'    => $postCheque,
                'expense_date'     => $postDate,
                'reference_period' => $postPeriod,
                'created_by'       => $userId,
            ]);
            setFlash('success', 'Expense recorded successfully.');
            header('Location: /public/expenses.php?year=' . date('Y') . '&property_id=' . (int) $postPropertyId);
            exit;
        }
    }
}

// ── Filters & pagination state ─────────────────────────────────────────────────
$year           = (int) ($_GET['year'] ?? date('Y'));
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$statusFilter   = (string) ($_GET['status'] ?? 'all');
$periodFilter   = (string) ($_GET['period'] ?? 'all');
$limitRaw       = (string) ($_GET['limit'] ?? '20');
$page           = max(1, (int) ($_GET['page'] ?? 1));

// Normalise limit: allowed discrete values or 0 for "all"
$limit = $limitRaw === 'all' ? 0 : (int) $limitRaw;
if (!in_array($limit, [0, 5, 10, 15, 20], true)) {
    $limit    = 20;
    $limitRaw = '20';
}

if (!in_array($statusFilter, array_merge(['all'], $allowedStatuses), true)) {
    $statusFilter = 'all';
}
if ($periodFilter !== 'all' && !in_array($periodFilter, $validPeriods, true)) {
    $periodFilter = 'all';
}

// Params carried through pagination links (page intentionally omitted so filters reset page)
$filterParams = [
    'year'        => $year,
    'property_id' => $propertyFilter,
    'status'      => $statusFilter,
    'period'      => $periodFilter,
    'limit'       => $limitRaw,
];

$properties = (new PropertyService())->listPropertyOptions();

$service = new ExpenseService();
$service->backfillMissingPeriods();

$totalCount = $service->countExpenses($year, $propertyFilter, $statusFilter, $periodFilter);
$totalPages = $limit > 0 ? max(1, (int) ceil($totalCount / $limit)) : 1;
$page       = min($page, $totalPages);

$expenses = $service->listExpenses($year, $propertyFilter, $statusFilter, $periodFilter, $limit, $page);
$totals   = $service->summaryTotals($year, $propertyFilter, $statusFilter, $periodFilter);

renderHeader('Expenses');
?>

<!-- ── Filter Bar ── -->
<section class="card budget-filter-card">
    <form method="get" id="expenseFilters" class="control-bar filter-form budget-filter-row">
        <label>Property
            <select name="property_id">
                <option value="all" <?= $propertyFilter === 'all' ? 'selected' : '' ?>>All Properties</option>
                <?php foreach ($properties as $prop): ?>
                    <option value="<?= (int) $prop['id'] ?>" <?= $propertyFilter === (string) $prop['id'] ? 'selected' : '' ?>><?= h($prop['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Year
            <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>">
        </label>
        <label>Status
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <?php foreach ($allowedStatuses as $st): ?>
                    <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Period
            <select name="period">
                <option value="all" <?= $periodFilter === 'all' ? 'selected' : '' ?>>All Periods</option>
                <?php foreach ($periodOptions as $group => $periods): ?>
                    <optgroup label="<?= h($group) ?>">
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= h($p) ?>" <?= $periodFilter === $p ? 'selected' : '' ?>><?= h($p) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Show rows
            <select name="limit" onchange="document.getElementById('expenseFilters').submit()">
                <?php foreach ([5, 10, 15, 20, 'all'] as $opt): ?>
                    <option value="<?= h((string) $opt) ?>" <?= $limitRaw === (string) $opt ? 'selected' : '' ?>>
                        <?= $opt === 'all' ? 'All' : h((string) $opt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit">Apply Filters</button>
            <button type="button" class="button button-secondary" onclick="window.location.href='/public/expenses.php';">Reset</button>
        </div>
    </form>
</section>

<!-- ── KPI Summary Cards ── -->
<section class="cards dashboard-kpi-grid">
    <article class="card metric">
        <span class="metric-label">Total Requisitions Pending:</span>
        <strong><span class="card-value"><?= formatKsh((float) $totals['total_pending']) ?></span></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Total Approved:</span>
        <strong><span class="card-value"><?= formatKsh((float) $totals['total_approved']) ?></span></strong>
    </article>
    <article class="card metric unpaid">
        <span class="metric-label">Total Disbursed/Paid:</span>
        <strong><span class="card-value"><?= formatKsh((float) $totals['total_disbursed']) ?></span></strong>
    </article>
    <article class="card metric">
        <?php
            $cardScopeLabel = (string) $year;
            if ($periodFilter !== 'all') { $cardScopeLabel .= ' · ' . $periodFilter; }
            if ($statusFilter  !== 'all') { $cardScopeLabel .= ' · ' . $statusFilter; }
        ?>
        <span class="metric-label">Total Expenses (<?= h($cardScopeLabel) ?>):</span>
        <strong><span class="card-value"><?= formatKsh((float) $totals['total_all']) ?></span></strong>
    </article>
</section>

<!-- ── Add Expense Form ── -->
<section class="card">
    <details <?= $formError !== null ? 'open' : '' ?>>
        <summary class="expense-form-summary"><strong>+ Log New Expense</strong></summary>
        <div class="expense-form-body">
            <?php if ($formError !== null): ?>
                <div class="alert error"><?= h($formError) ?></div>
            <?php endif; ?>
            <form method="post" action="/public/expenses.php" class="expense-entry-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action"     value="add_expense">
                <div class="expense-form-grid">
                    <label>Property <span class="required">*</span>
                        <select name="property_id" required>
                            <option value="">Select property…</option>
                            <?php foreach ($properties as $prop): ?>
                                <option value="<?= (int) $prop['id'] ?>"><?= h($prop['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Expense Category <span class="required">*</span>
                        <select name="category_name" required>
                            <option value="">Select category…</option>
                            <?php foreach ($expenseCategories as $group => $items): ?>
                                <optgroup label="<?= h($group) ?>">
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= h($item) ?>"><?= h($item) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Amount (KSh) <span class="required">*</span>
                        <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
                    </label>
                    <label>Expense Date <span class="required">*</span>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                    </label>
                    <label>Status
                        <select name="status">
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?= h($st) ?>" <?= $st === 'Requisition Pending' ? 'selected' : '' ?>><?= h($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Cheque Number <em>(optional)</em>
                        <input type="text" name="cheque_number" placeholder="e.g. CHQ-001">
                    </label>
                    <label>Reference Period <span class="required">*</span>
                        <select name="reference_period" required>
                            <option value="">Select period…</option>
                            <?php foreach ($periodOptions as $group => $periods): ?>
                                <optgroup label="<?= h($group) ?>">
                                    <?php foreach ($periods as $p): ?>
                                        <option value="<?= h($p) ?>"><?= h($p) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="expense-desc-field">Description <em>(optional)</em>
                        <textarea name="description" rows="2" placeholder="e.g. Electricity token for common area, Affordable housing levy payment"></textarea>
                    </label>
                </div>
                <div class="control-actions" style="margin-top:12px;">
                    <button type="submit" class="button">Save Expense</button>
                </div>
            </form>
        </div>
    </details>
</section>

<!-- ── Expenses Table ── -->
<section class="card">
    <div class="manage-table-header">
        <h3>
            Expense Records — <?= $year ?><?= $propertyFilter !== 'all' ? ' (filtered)' : '' ?>
            <span class="muted-text" style="font-weight:400;font-size:.88em;">
                (<?= $totalCount ?> record<?= $totalCount !== 1 ? 's' : '' ?>)
            </span>
        </h3>
    </div>

    <?php if (empty($expenses)): ?>
        <p class="muted-text">No expense records found for the selected filters.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="sortable">
            <thead>
                <tr>
                    <th>Expense Date</th>
                    <th>Property</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount (KSh)</th>
                    <th>Status</th>
                    <th>Cheque #</th>
                    <th>Period</th>
                    <th>Logged By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $exp): ?>
                    <?php
                        $expStatus   = (string) ($exp['status'] ?? '');
                        $statusClass = match ($expStatus) {
                            'Disbursed/Paid'      => 'badge-disbursed',
                            'Approved'            => 'badge-approved',
                            'Requisition Pending' => 'badge-pending',
                            default               => '',
                        };
                    ?>
                    <tr>
                        <td><?= h((string) ($exp['expense_date'] ?? '')) ?></td>
                        <td><?= h((string) ($exp['property_name'] ?? '—')) ?></td>
                        <td><?= h((string) ($exp['category_name'] ?? '')) ?></td>
                        <td class="expense-desc-cell"><?= h((string) ($exp['description'] ?? '')) ?></td>
                        <td><?= formatKsh((float) ($exp['amount'] ?? 0)) ?></td>
                        <td>
                            <div class="status-advance-wrap">
                                <span class="expense-badge <?= h($statusClass) ?>"><?= h($expStatus) ?></span>
                                <?php if ($expStatus === 'Requisition Pending'): ?>
                                    <form method="post" action="/public/expenses.php" class="status-advance-form">
                                        <input type="hidden" name="csrf_token"      value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action"          value="update_status">
                                        <input type="hidden" name="expense_id"      value="<?= (int) $exp['id'] ?>">
                                        <input type="hidden" name="new_status"      value="Approved">
                                        <input type="hidden" name="ret_year"        value="<?= $year ?>">
                                        <input type="hidden" name="ret_property_id" value="<?= h($propertyFilter) ?>">
                                        <button type="submit" class="status-advance-btn">&#8594; Approve</button>
                                    </form>
                                <?php elseif ($expStatus === 'Approved'): ?>
                                    <details class="status-advance-form">
                                        <summary>&#8594; Mark Paid</summary>
                                        <form method="post" action="/public/expenses.php" class="cheque-inline">
                                            <input type="hidden" name="csrf_token"      value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action"          value="update_status">
                                            <input type="hidden" name="expense_id"      value="<?= (int) $exp['id'] ?>">
                                            <input type="hidden" name="new_status"      value="Disbursed/Paid">
                                            <input type="hidden" name="ret_year"        value="<?= $year ?>">
                                            <input type="hidden" name="ret_property_id" value="<?= h($propertyFilter) ?>">
                                            <input type="text"   name="cheque_number"   placeholder="Cheque # (opt.)">
                                            <button type="submit" class="button">Confirm</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= h((string) ($exp['cheque_number'] ?? '—')) ?></td>
                        <td><?= h((string) ($exp['reference_period'] ?? '—')) ?></td>
                        <td><?= h((string) ($exp['created_by_name'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Pagination ── -->
    <?php if ($limit > 0 && $totalPages > 1): ?>
    <div class="pagination-bar">
        <span class="pagination-info">
            Page <?= $page ?> of <?= $totalPages ?>
            &nbsp;&middot;&nbsp;
            <?= $totalCount ?> record<?= $totalCount !== 1 ? 's' : '' ?>
        </span>
        <?php if ($page > 1): ?>
            <a href="/public/expenses.php?<?= h(http_build_query(array_merge($filterParams, ['page' => $page - 1]))) ?>"
               class="button button-secondary">&larr; Previous</a>
        <?php else: ?>
            <span class="button button-secondary pagination-disabled">&larr; Previous</span>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
            <a href="/public/expenses.php?<?= h(http_build_query(array_merge($filterParams, ['page' => $page + 1]))) ?>"
               class="button">Next &rarr;</a>
        <?php else: ?>
            <span class="button pagination-disabled">Next &rarr;</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</section>

<?php renderFooter(); ?>
