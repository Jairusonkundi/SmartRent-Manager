<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

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
$propertyFilter = (string) ($_GET['property_id'] ?? 'all');
$view = (string) ($_GET['view'] ?? 'monthly');
$view = in_array($view, ['monthly', 'quarterly'], true) ? $view : 'monthly';

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$currentMonth = date('Y-m');
$isFuturePeriod = $selectedMonth > $currentMonth;
$futureBillingStartDate = $isFuturePeriod ? date('F j, Y', strtotime($selectedMonth . '-01')) : null;
$properties = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll();
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
$periodEnd = min($periodEnd, $currentMonth);
if ($periodStart > $currentMonth) {
    $periodStart = $currentMonth;
}

$periodTotalsStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(p.amount_expected), 0) AS total_expected, COALESCE(SUM(p.amount_paid), 0) AS total_paid
     FROM payments p
     LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active'
     LEFT JOIN units u ON u.id=l.unit_id
     LEFT JOIN properties pr ON pr.id=u.property_id
     WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}"
);
$periodTotalsStmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
$periodTotals = $periodTotalsStmt->fetch() ?: ['total_expected' => 0, 'total_paid' => 0];

$totalExpected = (float) ($periodTotals['total_expected'] ?? 0.0);
$totalPaid = (float) ($periodTotals['total_paid'] ?? 0.0);
$accountsReceivable = max($totalExpected - $totalPaid, 0);
$collectionEfficiency = $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 2) : 0.0;

$occupancyStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT u.id) AS total_units,
            COUNT(DISTINCT CASE WHEN l.start_date <= ? AND (l.end_date IS NULL OR l.end_date >= ?) THEN u.id END) AS occupied_units
     FROM units u
     LEFT JOIN properties pr ON pr.id = u.property_id
     LEFT JOIN leases l ON l.unit_id = u.id
     WHERE 1=1 {$propertyWhere}"
);
$periodStartDate = $periodStart . '-01';
$periodEndDate = date('Y-m-t', strtotime($periodEnd . '-01'));
$occupancyStmt->execute(array_merge([$periodEndDate, $periodStartDate], $propertyParams));
$occupancyRow = $occupancyStmt->fetch() ?: ['total_units' => 0, 'occupied_units' => 0];
$totalUnits = (int) ($occupancyRow['total_units'] ?? 0);
$occupiedUnits = (int) ($occupancyRow['occupied_units'] ?? 0);
$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 2) : 0.0;

$lateStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p
    LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active'
    LEFT JOIN units u ON u.id=l.unit_id
    LEFT JOIN properties pr ON pr.id=u.property_id
    WHERE p.billing_month >= ? AND p.billing_month <= ? AND DAY(p.payment_date) > 10{$propertyWhere}");
$lateStmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
$latePaymentAlert = (int) ($lateStmt->fetchColumn() ?: 0);

$trendStmt = $pdo->prepare(
    "SELECT p.billing_month AS month_key,
            COALESCE(SUM(p.amount_expected), 0) AS expected,
            COALESCE(SUM(p.amount_paid), 0) AS paid
     FROM payments p
     LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active'
     LEFT JOIN units u ON u.id=l.unit_id
     LEFT JOIN properties pr ON pr.id=u.property_id
     WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}
     GROUP BY p.billing_month
     ORDER BY p.billing_month ASC"
);
$trendStmt->execute(array_merge([sprintf('%04d-01', $year), sprintf('%04d-12', $year)], $propertyParams));
$trend = $trendStmt->fetchAll() ?: [];

$distributionStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN p.amount_paid >= p.amount_expected AND p.amount_expected > 0 THEN p.amount_paid ELSE 0 END), 0) AS paid_total,
        COALESCE(SUM(CASE WHEN p.amount_expected > p.amount_paid THEN p.amount_expected - p.amount_paid ELSE 0 END), 0) AS arrears_total
     FROM payments p
     LEFT JOIN leases l ON l.tenant_id=p.tenant_id AND l.status='active'
     LEFT JOIN units u ON u.id=l.unit_id
     LEFT JOIN properties pr ON pr.id=u.property_id
     WHERE p.billing_month >= ? AND p.billing_month <= ?{$propertyWhere}"
);
$distributionStmt->execute(array_merge([$periodStart, $periodEnd], $propertyParams));
$distribution = $distributionStmt->fetch() ?: ['paid_total' => 0, 'arrears_total' => 0];

$latestBillingMonth = $pdo->query('SELECT MAX(billing_month) FROM payments')->fetchColumn();
$missingTenantDataCount = 0;
if (is_string($latestBillingMonth) && $latestBillingMonth !== '') {
    $missingTenantStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.id)
         FROM tenants t
         LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
         LEFT JOIN units u ON u.id = l.unit_id
         LEFT JOIN properties pr ON pr.id = u.property_id
         WHERE t.status = 'active'
           AND NOT EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.tenant_id = t.id
                 AND p.billing_month = ?
           ){$propertyWhere}"
    );
    $missingTenantStmt->execute(array_merge([$latestBillingMonth], $propertyParams));
    $missingTenantDataCount = (int) ($missingTenantStmt->fetchColumn() ?: 0);
}

renderHeader('Dashboard');
$hasPayments = $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() > 0;
?>
<?php if (!$hasPayments): ?>
<section class="card"><h3>Welcome! Please upload your CSV to begin</h3><p>Import your wide-format rent collection data to unlock dashboard metrics, arrears, and reports.</p><a class="button-link" href="/public/upload_csv.php">Upload CSV</a></section>
<?php renderFooter(); return; endif; ?>
<section class="card budget-filter-card">
    <form method="get" id="dashboardFilters" class="control-bar filter-form budget-filter-row">
        <label>Property
            <select name="property_id"><option value="all" <?= $propertyFilter === 'all' ? 'selected' : '' ?>>All Properties</option><?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>" <?= $propertyFilter === (string) $property['id'] ? 'selected' : '' ?>><?= h($property['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Year<input type="number" name="year" min="2000" max="2100" value="<?= $year ?>"></label>
        <label>View
            <select name="view"><option value="monthly" <?= $view === 'monthly' ? 'selected' : '' ?>>Monthly</option><option value="quarterly" <?= $view === 'quarterly' ? 'selected' : '' ?>>Quarterly</option></select>
        </label>
        <label class="reference-month-field">Reference Month/Quarter
            <select name="month"><?php if ($view === 'quarterly'): ?><?php for ($quarterNumber = 1; $quarterNumber <= 4; $quarterNumber++): ?><?php $quarterMonthKey = sprintf('%04d-%02d', $year, (($quarterNumber - 1) * 3) + 1); ?><option value="<?= h($quarterMonthKey) ?>" <?= $selectedMonth === $quarterMonthKey ? 'selected' : '' ?>>Q<?= $quarterNumber ?></option><?php endfor; ?><?php else: ?><?php for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++): ?><?php $monthKey = sprintf('%04d-%02d', $year, $monthNumber); ?><option value="<?= h($monthKey) ?>" <?= $selectedMonth === $monthKey ? 'selected' : '' ?>><?= h(date('M', strtotime($monthKey . '-01'))) ?></option><?php endfor; ?><?php endif; ?></select>
        </label>
        <div class="control-actions">
            <button type="submit">Search</button>
            <button type="button" class="button button-secondary" onclick="window.location.href='/public/dashboard.php';">Reset</button>
        </div>
    </form>
</section>
<?php if ($missingTenantDataCount > 0): ?>
<section class="card subtle-alert-card">
    <strong>Missing Tenant Data:</strong> <?= $missingTenantDataCount ?> active tenant(s) were not found in the latest import month (<?= h((string) $latestBillingMonth) ?>).
</section>
<?php endif; ?>
<?php if ($isFuturePeriod && $futureBillingStartDate !== null): ?><section class="card"><p>Data for this period is projected. Official billing starts on <?= h($futureBillingStartDate) ?>.</p></section><?php endif; ?>
<section class="cards dashboard-kpi-grid">
    <article class="card metric paid accountant-priority"><span class="metric-label">Collection Efficiency:</span><strong><span class="card-value"><?= number_format($collectionEfficiency, 2) ?>%</span></strong></article>
    <article class="card metric unpaid accountant-priority"><span class="metric-label">Accounts Receivable:</span><strong><span class="card-value">KSh <?= number_format($accountsReceivable, 2) ?></span></strong></article>
    <article class="card metric occupancy-widget"><span class="metric-label">Occupancy Rate:</span><strong><span class="card-value"><?= number_format($occupancyRate, 2) ?>% Occupancy (<?= $occupiedUnits ?>/<?= $totalUnits ?>)</span></strong></article>
    <article class="card metric unpaid"><span class="metric-label">Late Payment Alert (&gt; 10th):</span><strong><span class="card-value"><?= $latePaymentAlert ?></span></strong></article>
</section>
<section class="charts-grid dashboard-charts-grid">
    <article class="card"><h3>Revenue Trend (Year-to-Date)</h3><canvas id="incomeTrend"></canvas></article>
    <article class="card compact-pie-card"><h3>Payment Status Distribution</h3><canvas id="statusPie"></canvas></article>
</section>
<section class="card data-management-card">
    <h4>Data Management</h4>
    <div class="data-management-actions">
        <div class="data-management-item">
            <form method="post" action="/includes/import_handler.php" enctype="multipart/form-data" class="inline-upload-form">
                <input id="dashboard_csv_file" name="csv_file" type="file" accept=".csv,text/csv" required hidden>
                <button type="button" class="button-link action-upload" onclick="document.getElementById('dashboard_csv_file').click();">UPLOAD CSV FILE</button>
            </form>
            <p>Upload the raw CSV file here for the system to process and perform financial analysis.</p>
        </div>
        <div class="data-management-item">
            <a id="dashboard_download_csv" class="button-link action-download" href="/public/download_data.php?type=raw">DOWNLOAD CSV FILE</a>
            <p>Download the original uploaded file to make edits or manual corrections.</p>
        </div>
    </div>
</section>
<script>
window.dashboardData = { trend: <?= json_encode($trend, JSON_THROW_ON_ERROR) ?>, distribution: <?= json_encode($distribution, JSON_THROW_ON_ERROR) ?> };
document.addEventListener('DOMContentLoaded', () => {
    const csvInput = document.getElementById('dashboard_csv_file');
    const downloadLink = document.getElementById('dashboard_download_csv');
    const rawCsvStorageKey = 'dashboardRawCsvUpload';

    const triggerRawCsvDownload = (name, content) => {
        const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        const blobUrl = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = blobUrl;
        anchor.download = name || 'raw-upload.csv';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(blobUrl);
    };

    if (csvInput) {
        csvInput.addEventListener('change', async () => {
            if (!csvInput.files || csvInput.files.length === 0) {
                return;
            }

            const [file] = csvInput.files;
            try {
                const content = await file.text();
                localStorage.setItem(rawCsvStorageKey, JSON.stringify({
                    name: file.name || 'raw-upload.csv',
                    content,
                    updatedAt: new Date().toISOString()
                }));
            } catch (error) {
                localStorage.removeItem(rawCsvStorageKey);
            }

            csvInput.form?.submit();
        });
    }

    if (downloadLink) {
        downloadLink.addEventListener('click', event => {
            const storedRawCsv = localStorage.getItem(rawCsvStorageKey);
            if (!storedRawCsv) return;
            try {
                const parsed = JSON.parse(storedRawCsv);
                if (parsed && typeof parsed.content === 'string' && parsed.content.length > 0) {
                    event.preventDefault();
                    triggerRawCsvDownload(String(parsed.name || 'raw-upload.csv'), parsed.content);
                }
            } catch (error) {
                localStorage.removeItem(rawCsvStorageKey);
            }
        });
    }
});
</script>
<?php renderFooter(); ?>
