<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/TenantService.php';
require_once __DIR__ . '/../modules/PropertyService.php';

requireAuth();

$allowedStatuses      = ['active', 'inactive'];
$allowedLeaseStatuses = ['all', 'with_lease', 'no_lease'];

$tenantService   = new TenantService();
$propertyService = new PropertyService();
$formError       = null;

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $formError = 'Security token invalid. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        switch ($action) {
            case 'add_tenant':
                $name  = trim((string) ($_POST['name']  ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                if ($name === '') {
                    $formError = 'Tenant name is required.';
                } else {
                    $tenantService->createTenant($name, $phone, $email);
                    setFlash('success', 'Tenant "' . $name . '" added.');
                    header('Location: /public/manage_tenants.php');
                    exit;
                }
                break;

            case 'edit_tenant':
                $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                $name  = trim((string) ($_POST['name']  ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                if (!$id || $name === '') {
                    $formError = 'Invalid tenant data.';
                } else {
                    $tenantService->updateTenant($id, $name, $phone, $email);
                    setFlash('success', 'Tenant updated.');
                    header('Location: /public/manage_tenants.php');
                    exit;
                }
                break;

            case 'activate_tenant':
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if ($id) {
                    $tenantService->activateTenant($id);
                    setFlash('success', 'Tenant reactivated.');
                }
                header('Location: /public/manage_tenants.php');
                exit;

            case 'deactivate_tenant':
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if ($id) {
                    $tenantService->deactivateTenant($id);
                    setFlash('success', 'Tenant deactivated.');
                }
                header('Location: /public/manage_tenants.php');
                exit;

            case 'create_lease':
                $tenantId   = filter_input(INPUT_POST, 'tenant_id',   FILTER_VALIDATE_INT);
                $unitId     = filter_input(INPUT_POST, 'unit_id',     FILTER_VALIDATE_INT);
                $rentAmount = filter_input(INPUT_POST, 'rent_amount', FILTER_VALIDATE_FLOAT);
                $startDate  = trim((string) ($_POST['start_date'] ?? ''));
                if (!$tenantId || !$unitId || !$rentAmount || $rentAmount <= 0) {
                    $formError = 'Tenant, unit, and a valid rent amount are required.';
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
                    $formError = 'Please enter a valid lease start date (YYYY-MM-DD).';
                } else {
                    try {
                        $tenantService->createLease($tenantId, $unitId, (float) $rentAmount, $startDate);
                        setFlash('success', 'Lease created successfully.');
                        header('Location: /public/manage_tenants.php');
                        exit;
                    } catch (\RuntimeException $e) {
                        $formError = $e->getMessage();
                    } catch (\PDOException $e) {
                        if ($e->getCode() === '23000') {
                            $formError = 'A lease for this tenant and unit already exists on that start date. '
                                       . 'Choose a different start date or reactivate the existing record.';
                        } else {
                            $formError = 'Unable to create the lease right now. Please try again.';
                        }
                    }
                }
                break;

            case 'update_lease':
                $leaseId    = filter_input(INPUT_POST, 'lease_id',    FILTER_VALIDATE_INT);
                $rentAmount = filter_input(INPUT_POST, 'rent_amount', FILTER_VALIDATE_FLOAT);
                $startDate  = trim((string) ($_POST['start_date'] ?? ''));
                $endDateRaw = trim((string) ($_POST['end_date']   ?? ''));
                $endDate    = ($endDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDateRaw) === 1)
                              ? $endDateRaw : null;
                if (!$leaseId || !$rentAmount || $rentAmount <= 0) {
                    $formError = 'Invalid lease data.';
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
                    $formError = 'Please enter a valid start date.';
                } else {
                    $tenantService->updateLease($leaseId, (float) $rentAmount, $startDate, $endDate);
                    setFlash('success', 'Lease updated.');
                    header('Location: /public/manage_tenants.php');
                    exit;
                }
                break;

            case 'terminate_lease':
                $leaseId = filter_input(INPUT_POST, 'lease_id', FILTER_VALIDATE_INT);
                $endDate = trim((string) ($_POST['end_date'] ?? ''));
                if (!$leaseId || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1) {
                    $formError = 'Please provide a valid termination date.';
                } else {
                    try {
                        $tenantService->terminateLease($leaseId, $endDate);
                        setFlash('success', 'Lease terminated. Unit is now vacant.');
                        header('Location: /public/manage_tenants.php');
                        exit;
                    } catch (\RuntimeException $e) {
                        $formError = $e->getMessage();
                    } catch (\PDOException $e) {
                        $formError = 'Lease termination failed due to a database constraint. Please retry.';
                    }
                }
                break;
        }
    }
}

// ── Filter & Pagination state ──────────────────────────────────────────────────
$search       = trim((string) ($_GET['search']       ?? ''));
$statusFilter = (string) ($_GET['status']             ?? 'all');
$leaseFilter  = (string) ($_GET['lease_status']       ?? 'all');
$limitRaw     = (string) ($_GET['limit']              ?? '15');
$page         = max(1, (int) ($_GET['page']           ?? 1));

$limit = $limitRaw === 'all' ? 0 : (int) $limitRaw;
if (!in_array($limit, [0, 5, 10, 15, 20], true)) {
    $limit    = 15;
    $limitRaw = '15';
}

if (!in_array($statusFilter, array_merge(['all'], $allowedStatuses), true)) {
    $statusFilter = 'all';
}
if (!in_array($leaseFilter, $allowedLeaseStatuses, true)) {
    $leaseFilter = 'all';
}

$perPage    = $limit > 0 ? $limit : PHP_INT_MAX;
$totalCount = $tenantService->countTenantsFiltered($search, $statusFilter, $leaseFilter);
$totalPages = $limit > 0 ? max(1, (int) ceil($totalCount / $limit)) : 1;
$page       = min($page, $totalPages);

$tenants = $tenantService->listTenantsFiltered(
    $search, $statusFilter, $leaseFilter, $page, $limit > 0 ? $limit : ($totalCount ?: 1)
);

// ── Resolve edit/action targets from GET ──────────────────────────────────────
$editTenantId        = filter_input(INPUT_GET, 'edit_tenant',      FILTER_VALIDATE_INT) ?: null;
$createLeaseForId    = filter_input(INPUT_GET, 'create_lease',     FILTER_VALIDATE_INT) ?: null;
$editLeaseForId      = filter_input(INPUT_GET, 'edit_lease',       FILTER_VALIDATE_INT) ?: null;
$terminateLeaseForId = filter_input(INPUT_GET, 'terminate_lease',  FILTER_VALIDATE_INT) ?: null;

$editTenant        = $editTenantId        ? $tenantService->getTenant($editTenantId)        : null;
$createLeaseTenant = $createLeaseForId    ? $tenantService->getTenant($createLeaseForId)    : null;
$editLeaseTenant   = $editLeaseForId      ? $tenantService->getTenant($editLeaseForId)      : null;
$terminateTenant   = $terminateLeaseForId ? $tenantService->getTenant($terminateLeaseForId) : null;

// Units grouped by property for create-lease form
$allUnits            = $propertyService->listAllUnits();
$unitsByPropertyName = [];
foreach ($allUnits as $u) {
    $unitsByPropertyName[(string) $u['property_name']][] = $u;
}

$filterParams     = ['search' => $search, 'status' => $statusFilter, 'lease_status' => $leaseFilter, 'limit' => $limitRaw];
$hasActiveFilters = ($search !== '' || $statusFilter !== 'all' || $leaseFilter !== 'all');

// ── Ledger sub-view state ──────────────────────────────────────────────────────
$ledgerTenantId    = filter_input(INPUT_GET, 'ledger_tenant', FILTER_VALIDATE_INT) ?: 0;
$ledgerYear        = filter_input(INPUT_GET, 'ledger_year',   FILTER_VALIDATE_INT) ?: (int) date('Y');
$ledgerYear        = max(2000, min(2100, $ledgerYear));
$ledgerTenant      = null;
$ledgerRows        = [];
$ledgerExpected    = 0.0;
$ledgerPaid        = 0.0;
$ledgerOutstanding = 0.0;

if ($ledgerTenantId > 0) {
    $ledgerTenant = $tenantService->getTenant($ledgerTenantId);
    if ($ledgerTenant !== null) {
        $ledgerRows = $tenantService->getLedgerForTenant($ledgerTenantId, $ledgerYear);
        foreach ($ledgerRows as $row) {
            $ledgerExpected += (float) $row['amount_expected'];
            $ledgerPaid     += (float) $row['amount_paid'];
        }
        // Allow negative: a negative value means the tenant holds a credit balance.
        $ledgerOutstanding = $ledgerExpected - $ledgerPaid;
    }
}

renderHeader('Manage Tenants & Leases');

if ($ledgerTenantId > 0 && $ledgerTenant !== null) {
?>
<!-- ── Tenant Ledger Sub-View ───────────────────────────────────────────────── -->
<section class="card budget-filter-card">
    <div class="ledger-nav-row">
        <a href="/public/manage_tenants.php?<?= h(http_build_query($filterParams)) ?>"
           class="btn-back">&larr; Back to Tenants</a>
        <form method="get" class="control-bar filter-form ledger-year-form">
            <input type="hidden" name="ledger_tenant" value="<?= $ledgerTenantId ?>">
            <?php foreach ($filterParams as $fpk => $fpv): ?>
                <input type="hidden" name="<?= h($fpk) ?>" value="<?= h((string) $fpv) ?>">
            <?php endforeach; ?>
            <label>Year
                <input type="number" name="ledger_year" min="2000" max="2100"
                       value="<?= $ledgerYear ?>" style="width:90px">
            </label>
            <button type="submit" class="button">Change Year</button>
        </form>
    </div>
</section>

<section class="cards arrears-summary-cards">
    <article class="card metric">
        <span class="metric-label">Total Expected</span>
        <strong><?= formatKsh($ledgerExpected) ?></strong>
    </article>
    <article class="card metric paid">
        <span class="metric-label">Total Paid</span>
        <strong><?= formatKsh($ledgerPaid) ?></strong>
    </article>
    <article class="card metric <?= $ledgerOutstanding > 0 ? 'unpaid' : 'paid' ?>">
        <span class="metric-label">Net Outstanding<?= $ledgerOutstanding < 0 ? ' (Credit)' : '' ?></span>
        <strong><?= formatKsh($ledgerOutstanding) ?></strong>
    </article>
</section>

<section class="card">
    <h3>
        Statement &mdash; <?= h((string) $ledgerTenant['name']) ?>
        <?php if (!empty($ledgerTenant['unit_number'])): ?>
            <span class="muted-text" style="font-weight:normal;font-size:.9em">
                &nbsp;&middot;&nbsp;
                <?= h((string) $ledgerTenant['property_name']) ?> /
                <?= h((string) $ledgerTenant['unit_number']) ?>
            </span>
        <?php endif; ?>
        <span class="muted-text" style="font-weight:normal;font-size:.9em">
            &nbsp;&middot;&nbsp; <?= $ledgerYear ?>
        </span>
    </h3>

    <?php if ($ledgerRows === []): ?>
        <p class="muted-text">No payment records found for
            <?= h((string) $ledgerTenant['name']) ?> in <?= $ledgerYear ?>.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="ledger-statement-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reference Period</th>
                    <th>Payment Date</th>
                    <th>Expected (KSh)</th>
                    <th>Paid (KSh)</th>
                    <th>Balance (KSh)</th>
                    <th>Status</th>
                    <th>Channel</th>
                    <th>Ref No.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledgerRows as $i => $row): ?>
                <?php
                    $isAdvance  = (bool) ($row['is_advance'] ?? false);
                    $rowBalance = (float) $row['amount_expected'] - (float) $row['amount_paid'];
                    $runClass   = $rowBalance > 0 ? 'text-unpaid' : ($rowBalance < 0 ? 'text-paid' : '');
                    $cs         = (string) ($row['collection_status'] ?? '');
                ?>
                <tr<?= $isAdvance ? ' class="ledger-advance-row"' : '' ?>>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <?= h((string) $row['billing_month']) ?>
                        <?php if ($isAdvance): ?>
                            <em class="muted-text" style="font-size:.82em">&nbsp;(advance)</em>
                        <?php endif; ?>
                    </td>
                    <td><?= $row['payment_date'] !== null
                            ? h((string) $row['payment_date'])
                            : '<span class="muted-text">—</span>' ?></td>
                    <td class="ledger-money-cell"><?= formatKsh((float) $row['amount_expected']) ?></td>
                    <td class="ledger-money-cell"><?= formatKsh((float) $row['amount_paid']) ?></td>
                    <td class="ledger-money-cell <?= $runClass ?>">
                        <?= formatKsh($rowBalance) ?>
                    </td>
                    <td>
                        <span class="badge <?= h(strtolower($cs)) ?>"><?= h($cs) ?></span>
                    </td>
                    <td><?= !empty($row['payment_channel'])
                            ? h((string) $row['payment_channel'])
                            : '<span class="muted-text">—</span>' ?></td>
                    <td><?= !empty($row['reference_no'])
                            ? h((string) $row['reference_no'])
                            : '<span class="muted-text">—</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3"><strong>Totals</strong></td>
                    <td class="ledger-money-cell"><strong><?= formatKsh($ledgerExpected) ?></strong></td>
                    <td class="ledger-money-cell"><strong><?= formatKsh($ledgerPaid) ?></strong></td>
                    <td class="ledger-money-cell <?= $ledgerOutstanding > 0 ? 'text-unpaid' : ($ledgerOutstanding < 0 ? 'text-paid' : '') ?>">
                        <strong><?= formatKsh($ledgerOutstanding) ?></strong>
                    </td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php
    renderFooter();
    exit;
}
?>

<?php if ($formError !== null): ?>
    <div class="alert error"><?= h($formError) ?></div>
<?php endif; ?>

<!-- ── Add Tenant ──────────────────────────────────────────────────────────── -->
<section class="card">
    <details>
        <summary class="expense-form-summary"><strong>+ Add New Tenant</strong></summary>
        <div class="expense-form-body">
            <form method="post" action="/public/manage_tenants.php" class="tenant-form-grid">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action"     value="add_tenant">
                <label>Full Name <span class="required">*</span>
                    <input type="text" name="name" required placeholder="e.g. Jane Wanjiku">
                </label>
                <label>Phone Number
                    <input type="tel" name="phone" placeholder="e.g. 0712 345 678">
                </label>
                <label>Email Address
                    <input type="email" name="email" placeholder="e.g. jane@example.com">
                </label>
                <div class="control-actions tenant-form-actions">
                    <button type="submit" class="button">Add Tenant</button>
                </div>
            </form>
        </div>
    </details>
</section>

<!-- ── Edit Tenant (inline) ────────────────────────────────────────────────── -->
<?php if ($editTenant !== null): ?>
<section class="card">
    <h3>Edit Tenant: <?= h((string) $editTenant['name']) ?></h3>
    <form method="post" action="/public/manage_tenants.php" class="tenant-form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action"     value="edit_tenant">
        <input type="hidden" name="id"         value="<?= (int) $editTenant['id'] ?>">
        <label>Full Name <span class="required">*</span>
            <input type="text" name="name" required value="<?= h((string) $editTenant['name']) ?>">
        </label>
        <label>Phone Number
            <input type="tel" name="phone" value="<?= h((string) ($editTenant['phone'] ?? '')) ?>">
        </label>
        <label>Email Address
            <input type="email" name="email" value="<?= h((string) ($editTenant['email'] ?? '')) ?>">
        </label>
        <div class="control-actions tenant-form-actions">
            <button type="submit" class="button">Save Changes</button>
            <a href="/public/manage_tenants.php" class="button button-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- ── Create Lease (inline) ───────────────────────────────────────────────── -->
<?php if ($createLeaseTenant !== null): ?>
<section class="card">
    <h3>Create Lease for: <?= h((string) $createLeaseTenant['name']) ?></h3>
    <?php if ($createLeaseTenant['lease_id'] !== null): ?>
        <div class="alert error">This tenant already has an active lease on unit
            <strong><?= h((string) ($createLeaseTenant['unit_number'] ?? '')) ?></strong>.
            Terminate the existing lease first.
        </div>
        <a href="/public/manage_tenants.php" class="button button-secondary" style="margin-top:10px;">Back</a>
    <?php else: ?>
    <form method="post" action="/public/manage_tenants.php" class="expense-form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action"     value="create_lease">
        <input type="hidden" name="tenant_id"  value="<?= (int) $createLeaseTenant['id'] ?>">
        <label>Unit <span class="required">*</span>
            <select name="unit_id" required>
                <option value="">Select unit…</option>
                <?php foreach ($unitsByPropertyName as $propName => $propUnits): ?>
                    <optgroup label="<?= h($propName) ?>">
                    <?php foreach ($propUnits as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $u['status'] === 'occupied' ? 'disabled' : '' ?>>
                            <?= h((string) $u['unit_number']) ?> (<?= h(ucfirst((string) $u['status'])) ?>)
                        </option>
                    <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Monthly Rent (KSh) <span class="required">*</span>
            <input type="number" name="rent_amount" min="1" step="0.01" placeholder="0.00" required>
        </label>
        <label>Lease Start Date <span class="required">*</span>
            <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
        </label>
        <div class="control-actions" style="grid-column:1/-1">
            <button type="submit" class="button">Create Lease</button>
            <a href="/public/manage_tenants.php" class="button button-secondary">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- ── Edit Lease (inline) ─────────────────────────────────────────────────── -->
<?php if ($editLeaseTenant !== null && $editLeaseTenant['lease_id'] !== null): ?>
<section class="card">
    <h3>Edit Lease: <?= h((string) $editLeaseTenant['name']) ?></h3>
    <form method="post" action="/public/manage_tenants.php" class="expense-form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action"     value="update_lease">
        <input type="hidden" name="lease_id"   value="<?= (int) $editLeaseTenant['lease_id'] ?>">
        <label>Monthly Rent (KSh) <span class="required">*</span>
            <input type="number" name="rent_amount" min="1" step="0.01"
                   value="<?= h((string) ($editLeaseTenant['rent_amount'] ?? '')) ?>" required>
        </label>
        <label>Start Date <span class="required">*</span>
            <input type="date" name="start_date"
                   value="<?= h((string) ($editLeaseTenant['start_date'] ?? '')) ?>" required>
        </label>
        <label>End Date <em>(leave blank for open-ended)</em>
            <input type="date" name="end_date"
                   value="<?= h((string) ($editLeaseTenant['end_date'] ?? '')) ?>">
        </label>
        <div class="control-actions" style="grid-column:1/-1">
            <button type="submit" class="button">Save Lease</button>
            <a href="/public/manage_tenants.php" class="button button-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- ── Terminate Lease (inline) ────────────────────────────────────────────── -->
<?php if ($terminateTenant !== null && $terminateTenant['lease_id'] !== null): ?>
<section class="card">
    <h3>Terminate Lease: <?= h((string) $terminateTenant['name']) ?></h3>
    <p>Unit: <strong><?= h((string) ($terminateTenant['unit_number'] ?? '')) ?></strong>
       &nbsp;&mdash;&nbsp;
       Rent: <strong><?= formatKsh((float) ($terminateTenant['rent_amount'] ?? 0)) ?>/mo</strong></p>
    <form method="post" action="/public/manage_tenants.php" class="expense-form-grid"
          data-delete-confirmation="lease"
          data-item-label="<?= h((string) $terminateTenant['name']) ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action"     value="terminate_lease">
        <input type="hidden" name="lease_id"   value="<?= (int) $terminateTenant['lease_id'] ?>">
        <label>Termination / Move-Out Date <span class="required">*</span>
            <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required>
        </label>
        <div class="control-actions" style="grid-column:1/-1">
            <button type="submit" class="button button-danger">
                Terminate Lease
            </button>
            <a href="/public/manage_tenants.php" class="button button-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- ── Filter Bar ──────────────────────────────────────────────────────────── -->
<section class="card budget-filter-card">
    <form method="get" id="tenantFilters" action="/public/manage_tenants.php"
          class="control-bar filter-form budget-filter-row">
        <label>Search
            <input type="text" id="tenantSearch" name="search" value="<?= h($search) ?>"
                   placeholder="Search name, phone, email, unit…">
        </label>
        <label>Status
            <select name="status">
                <option value="all"      <?= $statusFilter === 'all'      ? 'selected' : '' ?>>All Statuses</option>
                <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </label>
        <label>Lease
            <select name="lease_status">
                <option value="all"        <?= $leaseFilter === 'all'        ? 'selected' : '' ?>>All</option>
                <option value="with_lease" <?= $leaseFilter === 'with_lease' ? 'selected' : '' ?>>With Active Lease</option>
                <option value="no_lease"   <?= $leaseFilter === 'no_lease'   ? 'selected' : '' ?>>No Lease</option>
            </select>
        </label>
        <label>Show rows
            <select name="limit" onchange="document.getElementById('tenantFilters').submit()">
                <?php foreach ([5, 10, 15, 20, 'all'] as $opt): ?>
                    <option value="<?= h((string) $opt) ?>" <?= $limitRaw === (string) $opt ? 'selected' : '' ?>>
                        <?= $opt === 'all' ? 'All' : h((string) $opt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="control-actions">
            <button type="submit" class="button">Filter</button>
            <a href="/public/manage_tenants.php" class="button button-secondary">Reset</a>
        </div>
    </form>
</section>

<!-- ── Tenants Table ───────────────────────────────────────────────────────── -->
<section class="card">
    <div class="manage-table-header">
        <h3>Tenants
            <span class="muted-text" style="font-weight:normal; font-size:.9rem;">
                <?php if ($hasActiveFilters): ?>
                    &nbsp;&mdash; <?= $totalCount ?> record<?= $totalCount !== 1 ? 's' : '' ?> found
                <?php else: ?>
                    &nbsp;(<?= $totalCount ?> total)
                <?php endif; ?>
            </span>
        </h3>
    </div>

    <?php if (empty($tenants)): ?>
        <p class="muted-text">
            <?= $hasActiveFilters
                ? 'No tenants match the current filters.'
                : 'No tenants found. Add your first tenant above.' ?>
        </p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="manage-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Unit</th>
                    <th>Property</th>
                    <th>Rent</th>
                    <th>Lease</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $t): ?>
                <?php
                    $hasLease    = $t['lease_id'] !== null;
                    $isActive    = $t['status'] === 'active';
                    $statusClass = $isActive ? 'unit-occupied' : 'unit-vacant';
                ?>
                <tr>
                    <td><strong><?= h((string) $t['name']) ?></strong></td>
                    <td><?= h((string) ($t['phone'] ?? '—')) ?></td>
                    <td><?= h((string) ($t['email'] ?? '—')) ?></td>
                    <td><span class="unit-status-badge <?= $statusClass ?>"><?= h(ucfirst((string) $t['status'])) ?></span></td>
                    <td><?= $hasLease ? h((string) ($t['unit_number'] ?? '—')) : '—' ?></td>
                    <td><?= $hasLease ? h((string) ($t['property_name'] ?? '—')) : '—' ?></td>
                    <td><?= $hasLease ? formatKsh((float) ($t['rent_amount'] ?? 0)) : '—' ?></td>
                    <td><?= $hasLease
                            ? '<span class="unit-status-badge unit-occupied">Active</span>'
                            : '<span class="unit-status-badge unit-vacant">None</span>' ?></td>
                    <td class="manage-actions">
                        <div class="action-cell">
                            <a href="/public/manage_tenants.php?edit_tenant=<?= (int) $t['id'] ?>"
                               class="button button-secondary">Edit</a>
                            <?php if ($hasLease): ?>
                                <a href="/public/manage_tenants.php?edit_lease=<?= (int) $t['id'] ?>"
                                   class="button button-secondary">Lease</a>
                                <a href="/public/manage_tenants.php?terminate_lease=<?= (int) $t['id'] ?>"
                                   class="btn-terminate">Terminate</a>
                            <?php else: ?>
                                <a href="/public/manage_tenants.php?create_lease=<?= (int) $t['id'] ?>"
                                   class="button">+ Lease</a>
                            <?php endif; ?>
                            <a href="/public/manage_tenants.php?ledger_tenant=<?= (int) $t['id'] ?>&<?= h(http_build_query($filterParams)) ?>"
                               class="btn-ledger">Ledger</a>
                            <?php if ($isActive): ?>
                                <form method="post" action="/public/manage_tenants.php"
                                      onsubmit="return confirm('Deactivate tenant &quot;<?= h(addslashes((string) $t['name'])) ?>&quot;?')">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action"     value="deactivate_tenant">
                                    <input type="hidden" name="id"         value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn-deactivate">Deactivate</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/public/manage_tenants.php">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action"     value="activate_tenant">
                                    <input type="hidden" name="id"         value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn-activate">Activate</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<!-- ── Pagination ──────────────────────────────────────────────────────────── -->
<?php if ($limit > 0 && $totalPages > 1): ?>
<div class="pagination-bar">
    <span class="pagination-info">
        Page <?= $page ?> of <?= $totalPages ?>
        &nbsp;&middot;&nbsp;
        <?= $totalCount ?> tenant<?= $totalCount !== 1 ? 's' : '' ?>
    </span>
    <?php if ($page > 1): ?>
        <a href="/public/manage_tenants.php?<?= h(http_build_query(array_merge($filterParams, ['page' => $page - 1]))) ?>"
           class="button button-secondary">&larr; Previous</a>
    <?php else: ?>
        <span class="button button-secondary pagination-disabled">&larr; Previous</span>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
        <a href="/public/manage_tenants.php?<?= h(http_build_query(array_merge($filterParams, ['page' => $page + 1]))) ?>"
           class="button">Next &rarr;</a>
    <?php else: ?>
        <span class="button pagination-disabled">Next &rarr;</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
    var input = document.getElementById('tenantSearch');
    var form  = document.getElementById('tenantFilters');
    if (!input || !form) return;
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 300);
    });
}());

(function () {
    var destructiveForms = document.querySelectorAll('form[data-delete-confirmation]');
    if (!destructiveForms.length) {
        return;
    }

    destructiveForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var label = form.getAttribute('data-item-label') || 'this lease';
            var response = window.prompt(
                'Type DELETE to confirm lease termination for "' + label + '".',
                ''
            );

            if (response !== 'DELETE') {
                event.preventDefault();
                window.alert('Action canceled. You must type DELETE exactly.');
            }
        });
    });
}());
</script>
<?php renderFooter(); ?>
