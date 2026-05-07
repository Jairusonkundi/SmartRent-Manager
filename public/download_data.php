<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$downloadType = (string) ($_GET['type'] ?? 'filtered');
if ($downloadType === 'raw') {
    $rawUploadPath = __DIR__ . '/../storage/raw_uploads/latest-upload.csv';
    $rawUploadMetaPath = __DIR__ . '/../storage/raw_uploads/latest-upload.json';

    if (!is_file($rawUploadPath)) {
        http_response_code(404);
        exit('No raw CSV file is available yet. Please upload a CSV first.');
    }

    $downloadName = 'raw-upload.csv';
    if (is_file($rawUploadMetaPath)) {
        $metadata = json_decode((string) file_get_contents($rawUploadMetaPath), true);
        if (is_array($metadata) && !empty($metadata['original_name']) && is_string($metadata['original_name'])) {
            $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($metadata['original_name'])) ?: $downloadName;
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($rawUploadPath));
    readfile($rawUploadPath);
    exit;
}

$year = (int) ($_GET['year'] ?? date('Y'));
$selectedMonthInput = (string) ($_GET['month'] ?? sprintf('%04d-%02d', $year, (int) date('n')));
$selectedMonth = sprintf('%04d-%02d', $year, (int) date('n'));
if (preg_match('/^(\d{4})-(\d{2})$/', $selectedMonthInput, $matches) === 1) {
    $candidateYear = (int) $matches[1];
    $candidateMonth = (int) $matches[2];
    if ($candidateYear === $year && $candidateMonth >= 1 && $candidateMonth <= 12) {
        $selectedMonth = sprintf('%04d-%02d', $candidateYear, $candidateMonth);
    }
}
$view = (string) ($_GET['view'] ?? 'monthly');
$view = in_array($view, ['monthly', 'quarterly'], true) ? $view : 'monthly';
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$propertyWhere = $propertyFilter !== 'all' ? ' AND pr.id = ?' : '';
$propertyParams = $propertyFilter !== 'all' ? [(int) $propertyFilter] : [];

$periodStart = $selectedMonth;
$periodEnd = $selectedMonth;
if ($view === 'quarterly') {
    $baseDate = new DateTimeImmutable($selectedMonth . '-01');
    $quarter = (int) ceil(((int) $baseDate->format('n')) / 3);
    $quarterStartMonth = (($quarter - 1) * 3) + 1;
    $periodStart = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth, 1)->format('Y-m');
    $periodEnd = $baseDate->setDate((int) $baseDate->format('Y'), $quarterStartMonth + 2, 1)->format('Y-m');
}

$stmt = $pdo->prepare(
    "SELECT p.billing_month, pr.name AS property_name, u.unit_number, t.name AS tenant_name, p.amount_expected, p.amount_paid, p.payment_date, p.collection_status
     FROM payments p
     LEFT JOIN tenants t ON t.id = p.tenant_id
     LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
     LEFT JOIN units u ON u.id = l.unit_id
     LEFT JOIN properties pr ON pr.id = u.property_id
     WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}
     ORDER BY p.billing_month DESC, pr.name ASC, u.unit_number ASC, t.name ASC"
);
$stmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filename = 'smartrent-current-data-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('Unable to generate file.');
}

fputcsv($output, ['Billing Month', 'Property', 'Unit', 'Tenant', 'Amount Expected', 'Amount Paid', 'Payment Date', 'Collection Status']);
foreach ($rows as $row) {
    fputcsv($output, [
        (string) ($row['billing_month'] ?? ''),
        (string) ($row['property_name'] ?? ''),
        (string) ($row['unit_number'] ?? ''),
        (string) ($row['tenant_name'] ?? ''),
        (string) ($row['amount_expected'] ?? ''),
        (string) ($row['amount_paid'] ?? ''),
        (string) ($row['payment_date'] ?? ''),
        (string) ($row['collection_status'] ?? ''),
    ]);
}
fclose($output);
exit;
