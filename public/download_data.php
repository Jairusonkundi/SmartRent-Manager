<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$stmt = $pdo->query(
    'SELECT p.billing_month, pr.name AS property_name, u.unit_number, t.name AS tenant_name, p.amount_expected, p.amount_paid, p.payment_date, p.collection_status
     FROM payments p
     LEFT JOIN tenants t ON t.id = p.tenant_id
     LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = "active"
     LEFT JOIN units u ON u.id = l.unit_id
     LEFT JOIN properties pr ON pr.id = u.property_id
     ORDER BY p.billing_month DESC, pr.name ASC, u.unit_number ASC, t.name ASC'
);
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
