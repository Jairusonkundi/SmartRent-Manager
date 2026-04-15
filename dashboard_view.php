<?php

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

session_start();
if (!isset($_SESSION['finance_manager_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = dbConnect();

$summary = $pdo->query(
    'SELECT
        COALESCE(SUM(rs.expected_rent), 0) AS total_expected,
        COALESCE(SUM(paid.total_paid), 0) AS total_paid
     FROM rent_schedule rs
     LEFT JOIN (
        SELECT tenant_id, month, SUM(amount_paid) AS total_paid
        FROM payments
        GROUP BY tenant_id, month
     ) paid ON paid.tenant_id = rs.tenant_id AND paid.month = rs.month'
)->fetch();

$totalExpected = (float) ($summary['total_expected'] ?? 0);
$totalPaid = (float) ($summary['total_paid'] ?? 0);
$collectionEfficiency = $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 2) : 0;
$outstanding = max(0, $totalExpected - $totalPaid);

$monthlyVsQuarterly = $pdo->query(
    'SELECT
        DATE_FORMAT(rs.month, "%Y-%m") AS month_key,
        CONCAT("Q", QUARTER(rs.month)) AS quarter_label,
        SUM(rs.expected_rent) AS expected,
        COALESCE(SUM(paid.total_paid), 0) AS paid
     FROM rent_schedule rs
     LEFT JOIN (
        SELECT tenant_id, month, SUM(amount_paid) AS total_paid
        FROM payments
        GROUP BY tenant_id, month
     ) paid ON paid.tenant_id = rs.tenant_id AND paid.month = rs.month
     GROUP BY rs.month
     ORDER BY rs.month'
)->fetchAll();

$timingTrend = $pdo->query(
    'SELECT
        pty.name AS property_name,
        SUM(CASE WHEN p.reference_no LIKE "On Time%" THEN 1 ELSE 0 END) AS on_time_count,
        SUM(CASE WHEN p.reference_no LIKE "Late%" THEN 1 ELSE 0 END) AS late_count
     FROM payments p
     INNER JOIN tenants t ON t.id = p.tenant_id
     INNER JOIN leases l ON l.tenant_id = t.id AND l.status = "active"
     INNER JOIN units u ON u.id = l.unit_id
     INNER JOIN properties pty ON pty.id = u.property_id
     GROUP BY pty.id, pty.name
     ORDER BY pty.name'
)->fetchAll();

$statusRows = [
    ['label' => 'Paid', 'value' => $totalPaid >= $totalExpected && $totalExpected > 0 ? 'Paid' : 'Partial', 'class' => $totalPaid >= $totalExpected ? 'status-paid' : 'status-partial'],
    ['label' => 'Outstanding', 'value' => formatKsh($outstanding), 'class' => $outstanding > 0 ? 'status-unpaid' : 'status-paid'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Inter, Arial, sans-serif; margin: 0; padding: 1.2rem; background: #f8fafc; color: #0f172a; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .card { background: #fff; border-radius: 14px; padding: 1rem 1.2rem; box-shadow: 0 10px 30px rgba(15,23,42,.07); }
        .card h3 { margin: 0 0 .4rem; font-size: .95rem; color: #475569; }
        .metric { font-size: 1.4rem; font-weight: 800; }
        .status-paid { color: #15803d; font-weight: 700; }
        .status-partial { color: #ca8a04; font-weight: 700; }
        .status-unpaid { color: #b91c1c; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: .65rem; text-align: left; }
        .charts { margin-top: 1rem; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        .import-card { margin: 1rem 0; }
        .import-actions { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
        .upload-btn { background: #0f766e; color: #fff; border: none; border-radius: 10px; padding: .65rem 1rem; font-weight: 700; cursor: pointer; }
        #importFeedback { color: #475569; font-size: .92rem; }
    </style>
</head>
<body>
    <h1>Collection Dashboard</h1>
    <div class="grid">
        <div class="card"><h3>Total Expected Rent</h3><div id="totalExpectedValue" class="metric"><?= htmlspecialchars(formatKsh($totalExpected), ENT_QUOTES, 'UTF-8') ?></div></div>
        <div class="card"><h3>Total Paid</h3><div id="totalPaidValue" class="metric status-paid"><?= htmlspecialchars(formatKsh($totalPaid), ENT_QUOTES, 'UTF-8') ?></div></div>
        <div class="card"><h3>Collection Efficiency</h3><div class="metric"><?= $collectionEfficiency ?>%</div></div>
        <?php foreach ($statusRows as $status): ?>
            <div class="card"><h3><?= htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') ?></h3><div<?= $status['label'] === 'Outstanding' ? " id=\"totalArrearsValue\"" : "" ?> class="metric <?= $status['class'] ?>"><?= htmlspecialchars((string) $status['value'], ENT_QUOTES, 'UTF-8') ?></div></div>
        <?php endforeach; ?>
    </div>


    <div class="card import-card">
        <h3>Monthly Upload</h3>
        <form id="csvUploadForm" class="import-actions" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <button class="upload-btn" type="submit">Upload Monthly Data</button>
            <span id="importFeedback"></span>
        </form>
    </div>

    <div class="card">
        <h3>Budget Module: Monthly vs Quarterly Comparison</h3>
        <table>
            <thead><tr><th>Month</th><th>Quarter</th><th>Expected</th><th>Paid</th></tr></thead>
            <tbody>
            <?php foreach ($monthlyVsQuarterly as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $row['month_key'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $row['quarter_label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(formatKsh((float) $row['expected']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="status-paid"><?= htmlspecialchars(formatKsh((float) $row['paid']), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="charts">
        <div class="card">
            <h3>On Time vs Late Payments by Property</h3>
            <canvas id="timingChart"></canvas>
        </div>
    </div>

    <script>
        const timingData = <?= json_encode($timingTrend, JSON_THROW_ON_ERROR) ?>;
        const labels = timingData.map(x => x.property_name);
        const onTime = timingData.map(x => Number(x.on_time_count || 0));
        const late = timingData.map(x => Number(x.late_count || 0));



        const uploadForm = document.getElementById('csvUploadForm');
        const importFeedback = document.getElementById('importFeedback');
        uploadForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            importFeedback.textContent = 'Uploading...';

            try {
                const response = await fetch('import_handler.php', {
                    method: 'POST',
                    body: new FormData(uploadForm)
                });

                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(payload.message || 'Upload failed.');
                }

                document.getElementById('totalExpectedValue').textContent = payload.dashboard_totals.expected;
                document.getElementById('totalPaidValue').textContent = payload.dashboard_totals.paid;
                document.getElementById('totalArrearsValue').textContent = payload.dashboard_totals.arrears;
                importFeedback.textContent = `Upload complete: ${payload.rows_processed} row(s) imported.`;
                uploadForm.reset();
            } catch (error) {
                importFeedback.textContent = error.message || 'Upload failed.';
            }
        });
        new Chart(document.getElementById('timingChart'), {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'On Time', data: onTime, backgroundColor: '#22c55e' },
                    { label: 'Late', data: late, backgroundColor: '#ef4444' }
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    </script>
</body>
</html>
