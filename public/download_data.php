<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$pdo          = Database::connection();
$downloadType = (string) ($_GET['type'] ?? 'backup');

// ── Raw upload file ────────────────────────────────────────────────────────────
if ($downloadType === 'raw') {
    $rawPath     = __DIR__ . '/../storage/raw_uploads/latest-upload.csv';
    $rawMetaPath = __DIR__ . '/../storage/raw_uploads/latest-upload.json';

    if (!is_file($rawPath)) {
        http_response_code(404);
        exit('No raw CSV file is available. Please upload a CSV first.');
    }

    $downloadName = 'raw-upload.csv';
    if (is_file($rawMetaPath)) {
        $meta = json_decode((string) file_get_contents($rawMetaPath), true);
        if (is_array($meta) && !empty($meta['original_name']) && is_string($meta['original_name'])) {
            $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($meta['original_name'])) ?: $downloadName;
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Length: ' . (string) filesize($rawPath));
    readfile($rawPath);
    exit;
}

// ── Filtered payments export (legacy / dashboard compat) ──────────────────────
if ($downloadType === 'filtered') {
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $year               = max(2000, min(2100, (int) ($_GET['year'] ?? date('Y'))));
    $selectedMonthInput = (string) ($_GET['month'] ?? sprintf('%04d-%02d', $year, (int) date('n')));
    $selectedMonth      = sprintf('%04d-%02d', $year, (int) date('n'));

    if (preg_match('/^(\d{4})-(\d{2})$/', $selectedMonthInput, $m) === 1) {
        $cy = (int) $m[1]; $cm = (int) $m[2];
        if ($cy === $year && $cm >= 1 && $cm <= 12) {
            $selectedMonth = sprintf('%04d-%02d', $cy, $cm);
        }
    }

    $view = (string) ($_GET['view'] ?? 'monthly');
    $view = in_array($view, ['monthly', 'quarterly'], true) ? $view : 'monthly';

    $propertyFilter = (string) ($_GET['property_id'] ?? 'all');
    $propertyId     = filter_var($propertyFilter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $propertyWhere  = ($propertyFilter !== 'all' && $propertyId !== false) ? ' AND pr.id = ?' : '';
    $propertyParams = ($propertyFilter !== 'all' && $propertyId !== false) ? [(int) $propertyId] : [];

    $periodStart = $selectedMonth;
    $periodEnd   = $selectedMonth;
    if ($view === 'quarterly') {
        $baseDate           = new DateTimeImmutable($selectedMonth . '-01');
        $quarter            = (int) ceil(((int) $baseDate->format('n')) / 3);
        $quarterStartMonth  = (($quarter - 1) * 3) + 1;
        $periodStart        = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth,     1)->format('Y-m');
        $periodEnd          = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth + 2, 1)->format('Y-m');
    }

    $stmt = $pdo->prepare(
        "SELECT p.billing_month, pr.name AS property_name, u.unit_number, t.name AS tenant_name,
                p.amount_expected, p.amount_paid, p.payment_date, p.collection_status
         FROM payments p
         LEFT JOIN tenants t  ON t.id  = p.tenant_id
         LEFT JOIN leases l   ON l.tenant_id = t.id AND l.status = 'active'
         LEFT JOIN units u    ON u.id  = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}
         ORDER BY p.billing_month DESC, pr.name ASC, u.unit_number ASC, t.name ASC"
    );
    $stmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $periodLabel = $periodStart === $periodEnd ? $periodStart : $periodStart . '_to_' . $periodEnd;
    $filename    = 'smartrent-payments-' . $periodLabel . '-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'wb');
    if ($out === false) { http_response_code(500); exit('Unable to generate file.'); }
    fputcsv($out, ['Billing Month', 'Property', 'Unit', 'Tenant', 'Amount Expected', 'Amount Paid', 'Payment Date', 'Collection Status']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['billing_month']   ?? ''),
            (string) ($row['property_name']   ?? ''),
            (string) ($row['unit_number']     ?? ''),
            (string) ($row['tenant_name']     ?? ''),
            (string) ($row['amount_expected'] ?? ''),
            (string) ($row['amount_paid']     ?? ''),
            (string) ($row['payment_date']    ?? ''),
            (string) ($row['collection_status'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// ── Comprehensive database backup (ZIP) ───────────────────────────────────────
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive PHP extension is not available. Enable php_zip in php.ini.');
}

$tmpFile = tempnam(sys_get_temp_dir(), 'smartrent_bk_');
if ($tmpFile === false) { http_response_code(500); exit('Cannot create temp file.'); }

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Cannot create backup archive.');
}

/**
 * Build a CSV string from a header row and a 2-D array of rows.
 *
 * @param string[] $headers
 * @param array<int, array<int, string|int|float|null>> $rows
 */
function buildCsv(array $headers, array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) { return ''; }
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv !== false ? $csv : '';
}

// ── Properties ────────────────────────────────────────────────────────────────
$rows = [];
foreach ($pdo->query(
    'SELECT id, name, location, created_at FROM properties ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [(string) $r['id'], (string) $r['name'], (string) ($r['location'] ?? ''), (string) ($r['created_at'] ?? '')];
}
$zip->addFromString('properties.csv', buildCsv(['ID', 'Name', 'Location', 'Created At'], $rows));

// ── Units ─────────────────────────────────────────────────────────────────────
$rows = [];
foreach ($pdo->query(
    "SELECT u.id, p.name AS property, u.unit_number, u.status, u.created_at
     FROM units u
     JOIN properties p ON p.id = u.property_id
     ORDER BY p.name, u.unit_number"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [(string) $r['id'], (string) $r['property'], (string) $r['unit_number'], (string) $r['status'], (string) ($r['created_at'] ?? '')];
}
$zip->addFromString('units.csv', buildCsv(['ID', 'Property', 'Unit Number', 'Status', 'Created At'], $rows));

// ── Tenants ───────────────────────────────────────────────────────────────────
$rows = [];
foreach ($pdo->query(
    "SELECT t.id, t.name, t.phone, t.email, t.status,
            u.unit_number, p.name AS property, l.rent_amount, l.start_date, l.end_date, l.status AS lease_status
     FROM tenants t
     LEFT JOIN leases l      ON l.tenant_id = t.id AND l.status = 'active'
     LEFT JOIN units u       ON u.id = l.unit_id
     LEFT JOIN properties p  ON p.id = u.property_id
     ORDER BY t.name"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        (string) $r['id'],
        (string) $r['name'],
        (string) ($r['phone'] ?? ''),
        (string) ($r['email'] ?? ''),
        (string) $r['status'],
        (string) ($r['unit_number'] ?? ''),
        (string) ($r['property'] ?? ''),
        (string) ($r['rent_amount'] ?? ''),
        (string) ($r['start_date'] ?? ''),
        (string) ($r['end_date'] ?? ''),
        (string) ($r['lease_status'] ?? ''),
    ];
}
$zip->addFromString('tenants.csv', buildCsv(
    ['ID', 'Name', 'Phone', 'Email', 'Status', 'Unit', 'Property', 'Rent Amount', 'Lease Start', 'Lease End', 'Lease Status'],
    $rows
));

// ── Leases (all, including terminated) ────────────────────────────────────────
$rows = [];
foreach ($pdo->query(
    "SELECT l.id, t.name AS tenant, p.name AS property, u.unit_number,
            l.rent_amount, l.start_date, l.end_date, l.status, l.created_at
     FROM leases l
     JOIN tenants t      ON t.id = l.tenant_id
     JOIN units u        ON u.id = l.unit_id
     JOIN properties p   ON p.id = u.property_id
     ORDER BY l.start_date DESC"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        (string) $r['id'],
        (string) $r['tenant'],
        (string) $r['property'],
        (string) $r['unit_number'],
        (string) $r['rent_amount'],
        (string) $r['start_date'],
        (string) ($r['end_date'] ?? ''),
        (string) $r['status'],
        (string) ($r['created_at'] ?? ''),
    ];
}
$zip->addFromString('leases.csv', buildCsv(
    ['ID', 'Tenant', 'Property', 'Unit', 'Rent Amount', 'Start Date', 'End Date', 'Status', 'Created At'],
    $rows
));

// ── Payments ──────────────────────────────────────────────────────────────────
$rows = [];
foreach ($pdo->query(
    "SELECT p.id, p.billing_month, t.name AS tenant, pr.name AS property, u.unit_number,
            p.amount_expected, p.amount_paid, p.payment_date, p.collection_status,
            p.payment_status, p.payment_channel, p.reference_no, p.created_at
     FROM payments p
     JOIN tenants t          ON t.id  = p.tenant_id
     LEFT JOIN leases l      ON l.tenant_id = t.id AND l.status = 'active'
     LEFT JOIN units u       ON u.id  = l.unit_id
     LEFT JOIN properties pr ON pr.id = u.property_id
     ORDER BY p.billing_month DESC, t.name"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        (string) $r['id'],
        (string) $r['billing_month'],
        (string) $r['tenant'],
        (string) ($r['property'] ?? ''),
        (string) ($r['unit_number'] ?? ''),
        (string) $r['amount_expected'],
        (string) $r['amount_paid'],
        (string) ($r['payment_date'] ?? ''),
        (string) $r['collection_status'],
        (string) $r['payment_status'],
        (string) $r['payment_channel'],
        (string) ($r['reference_no'] ?? ''),
        (string) ($r['created_at'] ?? ''),
    ];
}
$zip->addFromString('payments.csv', buildCsv(
    ['ID', 'Billing Month', 'Tenant', 'Property', 'Unit', 'Amount Expected', 'Amount Paid',
     'Payment Date', 'Collection Status', 'Payment Status', 'Channel', 'Reference No', 'Created At'],
    $rows
));

// ── Expenses ──────────────────────────────────────────────────────────────────
$rows = [];
foreach ($pdo->query(
    "SELECT e.id, e.expense_date, pr.name AS property, e.category_name, e.description,
            e.amount, e.status, e.cheque_number, e.reference_period, u.username AS created_by, e.created_at
     FROM expenses e
     JOIN properties pr   ON pr.id = e.property_id
     LEFT JOIN users u    ON u.id  = e.created_by
     ORDER BY e.expense_date DESC"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        (string) $r['id'],
        (string) $r['expense_date'],
        (string) $r['property'],
        (string) $r['category_name'],
        (string) ($r['description'] ?? ''),
        (string) $r['amount'],
        (string) $r['status'],
        (string) ($r['cheque_number'] ?? ''),
        (string) ($r['reference_period'] ?? ''),
        (string) ($r['created_by'] ?? ''),
        (string) ($r['created_at'] ?? ''),
    ];
}
$zip->addFromString('expenses.csv', buildCsv(
    ['ID', 'Date', 'Property', 'Category', 'Description', 'Amount', 'Status',
     'Cheque #', 'Reference Period', 'Created By', 'Created At'],
    $rows
));

$zip->close();

$filename = 'smartrent-backup-' . date('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Length: ' . (string) filesize($tmpFile));
readfile($tmpFile);
unlink($tmpFile);
exit;
