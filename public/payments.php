<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/PaymentService.php';

requireAuth();
$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenantId = (int) ($_POST['tenant_id'] ?? 0);
    $amount = (float) ($_POST['amount_paid'] ?? 0);
    $month = (string) ($_POST['month'] ?? date('Y-m-01'));
    $paymentDate = (string) ($_POST['payment_date'] ?? date('Y-m-d'));

    if ($tenantId <= 0 || $amount <= 0) {
        flash('error', 'Please select tenant and enter a valid amount.');
    } else {
        (new PaymentService())->recordPayment($tenantId, $month, $amount, $paymentDate, (int) $_SESSION['user_id']);
        flash('success', 'Payment recorded successfully.');
    }

    header('Location: /public/payments.php');
    exit;
}

$tenants = $pdo->query("SELECT id, name FROM tenants WHERE status='active' ORDER BY name")->fetchAll();
$recentPayments = $pdo->query(
    "SELECT t.name, p.amount_paid, p.payment_date, p.month
     FROM payments p
     JOIN tenants t ON t.id = p.tenant_id
     ORDER BY p.created_at DESC
     LIMIT 20"
)->fetchAll();

renderHeader('Payments');
?>
<section class="card">
    <h3>Record Payment</h3>
    <form method="post" class="grid-form">
        <label>Tenant
            <select name="tenant_id" required>
                <option value="">Select tenant</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= (int) $tenant['id'] ?>"><?= h($tenant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Amount Paid <input name="amount_paid" type="number" min="0" step="0.01" required></label>
        <label>Rent Month <input name="month" type="month" value="<?= date('Y-m') ?>" required></label>
        <label>Payment Date <input name="payment_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
        <button type="submit">Save Payment</button>
    </form>
</section>
<section class="card">
    <h3>Recent Payments</h3>
    <table class="sortable">
        <thead><tr><th>Tenant</th><th>Amount</th><th>Payment Date</th><th>Month</th><th>Payment Status</th></tr></thead>
        <tbody>
            <?php foreach ($recentPayments as $p): ?>
                <?php $status = ((int) date('d', strtotime($p['payment_date'])) <= 10) ? 'On Time' : 'Late'; ?>
                <tr>
                    <td><?= h($p['name']) ?></td>
                    <td><?= formatCurrency((float) $p['amount_paid']) ?></td>
                    <td><?= h($p['payment_date']) ?></td>
                    <td><?= date('M Y', strtotime($p['month'])) ?></td>
                    <td><span class="badge <?= $status === 'On Time' ? 'paid' : 'unpaid' ?>"><?= $status ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php renderFooter(); ?>
