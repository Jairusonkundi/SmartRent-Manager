<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireAuth();

renderHeader('Upload Monthly Data');
?>
<section class="card upload-card">
    <h3>Upload Monthly Data (CSV)</h3>
    <p class="upload-help">Upload your monthly property CSV file to auto-create properties, units, tenants, and payments.</p>
    <form action="/includes/import_handler.php" method="post" enctype="multipart/form-data" class="upload-form">
        <label for="csv_file">CSV File</label>
        <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
        <button type="submit">Upload</button>
    </form>
</section>
<?php renderFooter(); ?>
