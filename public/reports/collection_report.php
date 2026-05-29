<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Report dependency missing: vendor/autoload.php was not found. Run "composer install" from the project root.';
    exit;
}

require_once $autoloadPath;

use Dompdf\Dompdf;

$month = $_GET['month'] ?? date('Y-m-01');
$pdo = Database::connection();
$billingMonth = date('Y-m', strtotime($month));
// Group by tenant so multiple payment rows in the same month are collapsed correctly.
$stmt = $pdo->prepare(
    "SELECT t.name,
            SUM(p.amount_expected)                                              AS expected_rent,
            SUM(p.amount_paid)                                                  AS paid,
            GREATEST(SUM(p.amount_expected) - SUM(p.amount_paid), 0)           AS outstanding
     FROM payments p
     JOIN tenants t ON t.id = p.tenant_id
     WHERE p.billing_month = ?
     GROUP BY t.id, t.name
     ORDER BY t.name"
);
$stmt->execute([$billingMonth]);
$rows = $stmt->fetchAll();

// Totals via per-tenant subquery so overpayments don't cancel out other tenants' arrears.
$totalsStmt = $pdo->prepare(
    "SELECT
         COALESCE(SUM(m.expected), 0)                        AS total_expected,
         COALESCE(SUM(m.paid), 0)                            AS total_paid,
         COALESCE(SUM(GREATEST(m.expected - m.paid, 0)), 0)  AS total_outstanding
     FROM (
         SELECT tenant_id,
                SUM(amount_expected) AS expected,
                SUM(amount_paid)     AS paid
         FROM payments
         WHERE billing_month = ?
         GROUP BY tenant_id
     ) m"
);
$totalsStmt->execute([$billingMonth]);
$totals = $totalsStmt->fetch() ?: ['total_expected' => 0, 'total_paid' => 0, 'total_outstanding' => 0];
$totalExpected    = (float) ($totals['total_expected']    ?? 0);
$totalPaid        = (float) ($totals['total_paid']        ?? 0);
$totalOutstanding = (float) ($totals['total_outstanding'] ?? 0);

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
    '<h1>Collection vs. Vacancy Report</h1><p>Month: %s</p><ul><li>Total Units: %d</li><li>Occupied Units: %d</li><li>Vacant Units: %d</li><li>Vacancy Rate: %0.2f%%</li><li>Total Expected: %s</li><li>Total Revenue: %s</li><li>Total Outstanding: %s</li></ul><table border="1" cellspacing="0" cellpadding="6"><thead><tr><th>Tenant</th><th>Expected</th><th>Paid</th><th>Outstanding</th></tr></thead><tbody>%s</tbody></table>',
    htmlspecialchars(date('F Y', strtotime($billingMonth . '-01')), ENT_QUOTES, 'UTF-8'),
    $totalUnits,
    $occupiedUnits,
    $vacantUnits,
    $vacancyRate,
    formatKsh($totalExpected),
    formatKsh($totalPaid),
    formatKsh($totalOutstanding),
    $tableRows
);

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="collection-report.pdf"');
$dompdf->stream('collection-report.pdf', ['Attachment' => true]);
