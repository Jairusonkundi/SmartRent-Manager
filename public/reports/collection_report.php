<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

use Dompdf\Dompdf;

$month = $_GET['month'] ?? date('Y-m-01');
$pdo = Database::connection();
$billingMonth = date('Y-m', strtotime($month));
$stmt = $pdo->prepare(
    "SELECT t.name,
            p.amount_expected AS expected_rent,
            p.amount_paid AS paid,
            p.amount_expected - p.amount_paid AS outstanding
     FROM payments p
     JOIN tenants t ON t.id = p.tenant_id
     WHERE p.billing_month = ?
     ORDER BY t.name"
);
$stmt->execute([$billingMonth]);
$rows = $stmt->fetchAll();

$vacancyStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_units,
        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS occupied_units
     FROM units"
);
$vacancyStmt->execute(['occupied']);
$vacancy = $vacancyStmt->fetch() ?: ['total_units' => 0, 'occupied_units' => 0];
$totalUnits = (int) ($vacancy['total_units'] ?? 0);
$occupiedUnits = (int) ($vacancy['occupied_units'] ?? 0);
$vacantUnits = max($totalUnits - $occupiedUnits, 0);
$vacancyRate = $totalUnits > 0 ? round(($vacantUnits / $totalUnits) * 100, 2) : 0.0;

$tableRows = '';
foreach ($rows as $row) {
    $tableRows .= sprintf(
        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
        htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'),
        formatKsh((float) $row['expected_rent']),
        formatKsh((float) $row['paid']),
        formatKsh((float) $row['outstanding'])
    );
}

$html = sprintf(
    '<h1>Collection vs. Vacancy Report</h1><p>Month: %s</p><ul><li>Total Units: %d</li><li>Occupied Units: %d</li><li>Vacant Units: %d</li><li>Vacancy Rate: %0.2f%%</li></ul><table border="1" cellspacing="0" cellpadding="6"><thead><tr><th>Tenant</th><th>Expected</th><th>Paid</th><th>Outstanding</th></tr></thead><tbody>%s</tbody></table>',
    htmlspecialchars(date('F Y', strtotime($billingMonth . '-01')), ENT_QUOTES, 'UTF-8'),
    $totalUnits,
    $occupiedUnits,
    $vacantUnits,
    $vacancyRate,
    $tableRows
);

require_once __DIR__ . '/../../vendor/autoload.php';
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('collection-report.pdf', ['Attachment' => true]);
