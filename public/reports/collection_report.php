<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

use Dompdf\Dompdf;

$month = $_GET['month'] ?? date('Y-m-01');
$pdo = Database::connection();
$stmt = $pdo->prepare(
    "SELECT t.name, rs.expected_rent, COALESCE(SUM(p.amount_paid),0) paid,
            rs.expected_rent - COALESCE(SUM(p.amount_paid),0) outstanding
     FROM rent_schedule rs
     JOIN tenants t ON t.id = rs.tenant_id
     LEFT JOIN payments p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
     WHERE rs.month = :month
     GROUP BY t.name, rs.expected_rent
     ORDER BY t.name"
);
$stmt->execute(['month' => date('Y-m-01', strtotime($month))]);
$rows = $stmt->fetchAll();

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

$html = '<h1>Collection Report</h1><table border="1" cellspacing="0" cellpadding="6"><thead><tr><th>Tenant</th><th>Expected</th><th>Paid</th><th>Outstanding</th></tr></thead><tbody>' . $tableRows . '</tbody></table>';

require_once __DIR__ . '/../../vendor/autoload.php';
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('collection-report.pdf', ['Attachment' => true]);
