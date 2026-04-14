<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
$pdo = Database::connection();

$sql = "
SELECT t.name,
       rs.month,
       rs.due_date,
       rs.expected_rent,
       COALESCE(SUM(p.amount_paid),0) AS paid,
       rs.expected_rent - COALESCE(SUM(p.amount_paid),0) AS balance,
       CASE WHEN COALESCE(SUM(p.amount_paid),0) = 0 THEN 'Unpaid'
            WHEN COALESCE(SUM(p.amount_paid),0) < rs.expected_rent THEN 'Partial'
            ELSE 'Paid'
       END AS rent_status,
       CASE WHEN COALESCE(MAX(p.payment_date), '9999-12-31') > rs.due_date THEN 'Late' ELSE 'On Time' END AS timing_status
FROM rent_schedule rs
JOIN tenants t ON t.id = rs.tenant_id
LEFT JOIN payments p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
WHERE rs.month <= CURRENT_DATE AND rs.status <> 'paid'
GROUP BY t.name, rs.month, rs.due_date, rs.expected_rent
ORDER BY balance DESC, rs.month ASC
";
$arrears = $pdo->query($sql)->fetchAll();

renderHeader('Arrears');
?>
<section class="card">
    <h3>Outstanding Accounts & High-Risk Tenants</h3>
    <table class="sortable">
        <thead><tr><th>Tenant</th><th>Month</th><th>Expected</th><th>Paid</th><th>Balance</th><th>Status</th><th>Timing</th></tr></thead>
        <tbody>
            <?php foreach ($arrears as $row): ?>
                <tr>
                    <td><?= h($row['name']) ?></td>
                    <td><?= h(date('M Y', strtotime($row['month']))) ?></td>
                    <td>$<?= number_format((float) $row['expected_rent'], 2) ?></td>
                    <td>$<?= number_format((float) $row['paid'], 2) ?></td>
                    <td class="text-unpaid">$<?= number_format((float) $row['balance'], 2) ?></td>
                    <td><span class="badge <?= strtolower($row['rent_status']) === 'partial' ? 'partial' : 'unpaid' ?>"><?= h($row['rent_status']) ?></span></td>
                    <td><span class="badge <?= strtolower($row['timing_status']) === 'late' ? 'unpaid' : 'paid' ?>"><?= h($row['timing_status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php renderFooter(); ?>
