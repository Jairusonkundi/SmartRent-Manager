<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireAuth();
renderHeader('Reports');
?>
<section class="card">
    <h3>Financial Reports</h3>
    <p>Generate PDF reports for monthly finance and collections.</p>
    <ul>
        <li><a href="/public/reports/monthly_report.php?month=<?= date('Y-m-01') ?>" target="_blank">Download Monthly Financial Report (PDF)</a></li>
        <li><a href="/public/reports/collection_report.php?month=<?= date('Y-m-01') ?>" target="_blank">Download Collection Report (PDF)</a></li>
    </ul>
</section>
<?php renderFooter(); ?>
