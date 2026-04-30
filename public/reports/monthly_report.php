<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../modules/DashboardService.php';

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Report dependency missing: vendor/autoload.php was not found. Run "composer install" from the project root.';
    exit;
}

require_once $autoloadPath;

// Requires dompdf via Composer: composer require dompdf/dompdf
use Dompdf\Dompdf;

$month = $_GET['month'] ?? date('Y-m-01');
$service = new DashboardService();
$summary = $service->summary($month);

$html = sprintf(
    '<h1>Monthly Financial Report</h1><p>Month: %s</p><ul><li>Expected: %s</li><li>Paid: %s</li><li>Outstanding: %s</li><li>Collection: %0.2f%%</li></ul>',
    htmlspecialchars(date('F Y', strtotime($month)), ENT_QUOTES, 'UTF-8'),
    formatKsh((float) $summary['expected']),
    formatKsh((float) $summary['paid']),
    formatKsh((float) $summary['outstanding']),
    $summary['collection_percent']
);

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="monthly-financial-report.pdf"');
$dompdf->stream('monthly-financial-report.pdf', ['Attachment' => true]);
