<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../modules/DashboardService.php';

// Requires dompdf via Composer: composer require dompdf/dompdf
use Dompdf\Dompdf;

$month = $_GET['month'] ?? date('Y-m-01');
$service = new DashboardService();
$summary = $service->summary($month);

$html = sprintf(
    '<h1>Monthly Financial Report</h1><p>Month: %s</p><ul><li>Expected: $%0.2f</li><li>Paid: $%0.2f</li><li>Outstanding: $%0.2f</li><li>Collection: %0.2f%%</li></ul>',
    htmlspecialchars(date('F Y', strtotime($month)), ENT_QUOTES, 'UTF-8'),
    $summary['expected'],
    $summary['paid'],
    $summary['outstanding'],
    $summary['collection_percent']
);

require_once __DIR__ . '/../../vendor/autoload.php';
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('monthly-financial-report.pdf', ['Attachment' => true]);
