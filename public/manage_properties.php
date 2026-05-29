<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/PropertyService.php';

requireAuth();

const PROPS_PER_PAGE = 15;

$service         = new PropertyService();
$formError       = null;
$allowedStatuses = ['vacant', 'occupied'];

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $formError = 'Security token invalid. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        switch ($action) {
            case 'add_property':
                $name     = trim((string) ($_POST['name'] ?? ''));
                $location = trim((string) ($_POST['location'] ?? ''));
                if ($name === '') {
                    $formError = 'Property name is required.';
                } else {
                    $service->createProperty($name, $location !== '' ? $location : 'Unspecified');
                    setFlash('success', 'Property "' . $name . '" added.');
                    header('Location: /public/manage_properties.php');
                    exit;
                }
                break;

            case 'edit_property':
                $id       = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                $name     = trim((string) ($_POST['name'] ?? ''));
                $location = trim((string) ($_POST['location'] ?? ''));
                if (!$id || $name === '') {
                    $formError = 'Invalid property data.';
                } else {
                    $service->updateProperty($id, $name, $location !== '' ? $location : 'Unspecified');
                    setFlash('success', 'Property updated.');
                    header('Location: /public/manage_properties.php');
                    exit;
                }
                break;

            case 'delete_property':
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if ($id) {
                    try {
                        $service->deleteProperty($id);
                        setFlash('success', 'Property deleted.');
                    } catch (\RuntimeException $e) {
                        setFlash('error', $e->getMessage());
                    } catch (\PDOException $e) {
                        setFlash('error', 'Property could not be deleted right now. Please try again.');
                    }
                } else {
                    setFlash('error', 'Invalid property selection.');
                }
                header('Location: /public/manage_properties.php');
                exit;

            case 'add_unit':
                $propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
                $unitNumber = trim((string) ($_POST['unit_number'] ?? ''));
                $status     = (string) ($_POST['status'] ?? 'vacant');
                $status     = in_array($status, $allowedStatuses, true) ? $status : 'vacant';
                if (!$propertyId || $unitNumber === '') {
                    $formError = 'Property and unit number are required.';
                } else {
                    $service->createUnit($propertyId, $unitNumber, $status);
                    setFlash('success', 'Unit "' . $unitNumber . '" added.');
                    header('Location: /public/manage_properties.php');
                    exit;
                }
                break;

            case 'edit_unit':
                $id         = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                $unitNumber = trim((string) ($_POST['unit_number'] ?? ''));
                $status     = (string) ($_POST['status'] ?? 'vacant');
                $status     = in_array($status, $allowedStatuses, true) ? $status : 'vacant';
                if (!$id || $unitNumber === '') {
                    $formError = 'Invalid unit data.';
                } else {
                    $service->updateUnit($id, $unitNumber, $status);
                    setFlash('success', 'Unit updated.');
                    header('Location: /public/manage_properties.php');
                    exit;
                }
                break;

            case 'delete_unit':
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if ($id) {
                    try {
                        $service->deleteUnit($id);
                        setFlash('success', 'Unit deleted.');
                    } catch (\RuntimeException $e) {
                        setFlash('error', $e->getMessage());
                    } catch (\PDOException $e) {
                        setFlash('error', 'Unit could not be deleted right now. Please try again.');
                    }
                } else {
                    setFlash('error', 'Invalid unit selection.');
                }
                header('Location: /public/manage_properties.php');
                exit;
        }
    }
}

// ── Filter & Pagination state ──────────────────────────────────────────────────
$search        = trim((string) ($_GET['search'] ?? ''));
$page          = max(1, (int) ($_GET['page'] ?? 1));
$editPropertyId = filter_input(INPUT_GET, 'edit_property', FILTER_VALIDATE_INT) ?: null;
$editUnitId     = filter_input(INPUT_GET, 'edit_unit',     FILTER_VALIDATE_INT) ?: null;

$totalCount = $service->countPropertiesFiltered($search);
$totalPages = max(1, (int) ceil($totalCount / PROPS_PER_PAGE));
$page       = min($page, $totalPages);

$properties = $service->listPropertiesFiltered($search, $page, PROPS_PER_PAGE);

// Load units only for properties on the current page
$allUnits        = $service->listAllUnits();
$propertyIdsPage = array_map(static fn($p) => (int) $p['id'], $properties);
$unitsByProperty = [];
foreach ($allUnits as $unit) {
    if (in_array((int) $unit['property_id'], $propertyIdsPage, true)) {
        $unitsByProperty[(int) $unit['property_id']][] = $unit;
    }
}

$editProperty = $editPropertyId ? $service->getProperty($editPropertyId) : null;
$editUnit     = $editUnitId     ? $service->getUnit($editUnitId)         : null;

$filterParams = ['search' => $search, 'page' => $page];

renderHeader('Manage Properties & Units');
?>

<?php if ($formError !== null): ?>
    <div class="alert error"><?= h($formError) ?></div>
<?php endif; ?>

<!-- ── Add Property ─────────────────────────────────────────────────────────── -->
<section class="card">
    <details>
        <summary class="expense-form-summary"><strong>+ Add New Property</strong></summary>
        <div class="expense-form-body">
            <form method="post" action="/public/manage_properties.php" class="expense-form-grid">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action"     value="add_property">
                <label>Property Name <span class="required">*</span>
                    <input type="text" name="name" required placeholder="e.g. Cobble Garden Apartments">
                </label>
                <label>Location
                    <input type="text" name="location" placeholder="e.g. Karen, Nairobi">
                </label>
                <div class="control-actions" style="grid-column:1/-1">
                    <button type="submit" class="button">Add Property</button>
                </div>
            </form>
        </div>
    </details>
</section>

<!-- ── Edit Property (inline) ──────────────────────────────────────────────── -->
<?php if ($editProperty !== null): ?>
<section class="card">
    <h3>Edit Property: <?= h((string) $editProperty['name']) ?></h3>
    <form method="post" action="/public/manage_properties.php" class="expense-form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action"     value="edit_property">
        <input type="hidden" name="id"         value="<?= (int) $editProperty['id'] ?>">
        <label>Property Name <span class="required">*</span>
            <input type="text" name="name" required value="<?= h((string) $editProperty['name']) ?>">
        </label>
        <label>Location
            <input type="text" name="location" value="<?= h((string) ($editProperty['location'] ?? '')) ?>">
        </label>
        <div class="control-actions" style="grid-column:1/-1">
            <button type="submit" class="button">Save Changes</button>
            <a href="/public/manage_properties.php" class="button button-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- ── Edit Unit (inline) ──────────────────────────────────────────────────── -->
<?php if ($editUnit !== null): ?>
<section class="card">
    <h3>Edit Unit: <?= h((string) $editUnit['unit_number']) ?></h3>
    <form method="post" action="/public/manage_properties.php" class="expense-form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action"     value="edit_unit">
        <input type="hidden" name="id"         value="<?= (int) $editUnit['id'] ?>">
        <label>Unit Number <span class="required">*</span>
            <input type="text" name="unit_number" required value="<?= h((string) $editUnit['unit_number']) ?>">
        </label>
        <label>Status
            <select name="status">
                <option value="vacant"   <?= ($editUnit['status'] ?? '') === 'vacant'   ? 'selected' : '' ?>>Vacant</option>
                <option value="occupied" <?= ($editUnit['status'] ?? '') === 'occupied' ? 'selected' : '' ?>>Occupied</option>
            </select>
        </label>
        <div class="control-actions" style="grid-column:1/-1">
            <button type="submit" class="button">Save Changes</button>
            <a href="/public/manage_properties.php" class="button button-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- ── Filter Bar ──────────────────────────────────────────────────────────── -->
<section class="card budget-filter-card">
    <form method="get" action="/public/manage_properties.php" class="control-bar filter-form budget-filter-row">
        <label>Search
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Property name or location…">
        </label>
        <div class="control-actions">
            <button type="submit" class="button">Search</button>
            <a href="/public/manage_properties.php" class="button button-secondary">Reset</a>
        </div>
    </form>
</section>

<!-- ── Result count ───────────────────────────────────────────────────────── -->
<?php if ($search !== ''): ?>
<p class="muted-text" style="padding:0 4px;">
    <?= $totalCount ?> propert<?= $totalCount !== 1 ? 'ies' : 'y' ?> matching
    &ldquo;<?= h($search) ?>&rdquo;
</p>
<?php endif; ?>

<!-- ── Properties List ─────────────────────────────────────────────────────── -->
<?php if (empty($properties)): ?>
<section class="card">
    <p class="muted-text">
        <?= $search !== '' ? 'No properties match the search criteria.' : 'No properties yet. Use the form above to add your first property.' ?>
    </p>
</section>
<?php endif; ?>

<?php foreach ($properties as $prop): ?>
<section class="card manage-property-card">
    <div class="manage-property-header">
        <div>
            <strong class="manage-property-name"><?= h((string) $prop['name']) ?></strong>
            <span class="muted-text"> &mdash; <?= h((string) ($prop['location'] ?? '')) ?>
                &nbsp;&middot;&nbsp;
                <?= (int) $prop['unit_count'] ?> unit<?= (int) $prop['unit_count'] !== 1 ? 's' : '' ?>
            </span>
        </div>
        <div class="manage-actions">
            <a href="/public/manage_properties.php?edit_property=<?= (int) $prop['id'] ?>"
               class="button button-secondary">Edit</a>
            <form method="post" action="/public/manage_properties.php" style="display:inline"
                  data-delete-confirmation="property"
                  data-item-label="<?= h((string) $prop['name']) ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action"     value="delete_property">
                <input type="hidden" name="id"         value="<?= (int) $prop['id'] ?>">
                <button type="submit" class="button button-danger">Delete</button>
            </form>
        </div>
    </div>

    <?php $propUnits = $unitsByProperty[(int) $prop['id']] ?? []; ?>
    <?php if (!empty($propUnits)): ?>
    <div class="table-responsive" style="margin-top:14px;">
        <table class="manage-table">
            <thead>
                <tr>
                    <th>Unit</th><th>Status</th><th>Current Tenant</th><th>Rent</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($propUnits as $unit): ?>
                <tr>
                    <td><?= h((string) $unit['unit_number']) ?></td>
                    <td><span class="unit-status-badge unit-<?= h((string) $unit['status']) ?>"><?= h(ucfirst((string) $unit['status'])) ?></span></td>
                    <td><?= h((string) ($unit['tenant_name'] ?? '—')) ?></td>
                    <td><?= isset($unit['rent_amount']) && $unit['rent_amount'] !== null
                            ? formatKsh((float) $unit['rent_amount']) : '—' ?></td>
                    <td class="manage-actions">
                        <a href="/public/manage_properties.php?edit_unit=<?= (int) $unit['id'] ?>"
                           class="button button-secondary">Edit</a>
                        <form method="post" action="/public/manage_properties.php" style="display:inline"
                              data-delete-confirmation="unit"
                              data-item-label="<?= h((string) $unit['unit_number']) ?>">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action"     value="delete_unit">
                            <input type="hidden" name="id"         value="<?= (int) $unit['id'] ?>">
                            <button type="submit" class="button button-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <details style="margin-top:14px;">
        <summary class="expense-form-summary">+ Add Unit to <?= h((string) $prop['name']) ?></summary>
        <div class="expense-form-body">
            <form method="post" action="/public/manage_properties.php" class="expense-form-grid">
                <input type="hidden" name="csrf_token"  value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action"      value="add_unit">
                <input type="hidden" name="property_id" value="<?= (int) $prop['id'] ?>">
                <label>Unit Number <span class="required">*</span>
                    <input type="text" name="unit_number" required placeholder="e.g. A1, 101">
                </label>
                <label>Status
                    <select name="status">
                        <option value="vacant">Vacant</option>
                        <option value="occupied">Occupied</option>
                    </select>
                </label>
                <div class="control-actions" style="grid-column:1/-1">
                    <button type="submit" class="button">Add Unit</button>
                </div>
            </form>
        </div>
    </details>
</section>
<?php endforeach; ?>

<!-- ── Pagination ──────────────────────────────────────────────────────────── -->
<?php if ($totalPages > 1): ?>
<div class="pagination-bar">
    <span class="pagination-info">
        Page <?= $page ?> of <?= $totalPages ?> &nbsp;&middot;&nbsp; <?= $totalCount ?> propert<?= $totalCount !== 1 ? 'ies' : 'y' ?>
    </span>
    <?php if ($page > 1): ?>
        <a href="/public/manage_properties.php?<?= h(http_build_query(array_merge($filterParams, ['page' => $page - 1]))) ?>"
           class="button button-secondary">&larr; Previous</a>
    <?php else: ?>
        <span class="button button-secondary pagination-disabled">&larr; Previous</span>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
        <a href="/public/manage_properties.php?<?= h(http_build_query(array_merge($filterParams, ['page' => $page + 1]))) ?>"
           class="button">Next &rarr;</a>
    <?php else: ?>
        <span class="button pagination-disabled">Next &rarr;</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
    var destructiveForms = document.querySelectorAll('form[data-delete-confirmation]');
    if (!destructiveForms.length) {
        return;
    }

    destructiveForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var label = form.getAttribute('data-item-label') || 'this item';
            var response = window.prompt(
                'Type DELETE to confirm permanent deletion of "' + label + '".',
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
