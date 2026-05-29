<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireAuth();

renderHeader('Import Data (Emergency)');
?>

<section class="card upload-warning-banner">
    <div class="upload-warning-icon">&#9888;</div>
    <div>
        <strong>Emergency Recovery Tool — Use with Extreme Caution</strong>
        <p>This import <strong>truncates and repopulates all data tables</strong>
           (properties, units, tenants, leases, payments). Any manually entered records,
           including those posted via the Post Payment form or Manage Tenants, will be
           <strong>permanently overwritten</strong>.</p>
        <p>Use this tool <em>only</em> when recovering from data loss or performing a
           full system reset. For day-to-day data entry, use
           <a href="/public/manage_properties.php">Manage Properties</a>,
           <a href="/public/manage_tenants.php">Manage Tenants</a>, and
           <a href="/public/post_payment.php">Post Payment</a> instead.</p>
    </div>
</section>

<section class="card upload-card">
    <h3>Upload Monthly Data (CSV)</h3>
    <p class="upload-help">Wide-format CSV with exact headers:
        <code>Property_Name, Unit_Number, Tenant_Name, Tenant_Email, Tenant_Phone,
        Monthly_Rent, Jan_Paid … Dec_Paid</code>
    </p>
    <div id="upload-feedback" aria-live="polite"></div>
    <form id="csv-upload-form" action="/includes/import_handler.php" method="post"
          enctype="multipart/form-data" class="upload-form"
          onsubmit="return confirm('This will DELETE and recreate all data from the CSV. Continue?')">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="return_to"  value="/public/dashboard.php">
        <label for="csv_file">CSV File</label>
        <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
        <button type="submit" class="button button-danger">Upload &amp; Overwrite All Data</button>
    </form>
</section>

<?php renderFooter(); ?>
