<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = Database::connection();

$month        = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$billingMonth = substr($month, 0, 7); // YYYY-MM
$dueDate      = (new DateTimeImmutable($month))->setDate((int) date('Y'), (int) date('m'), 10)->format('Y-m-d');

// ── 1. Generate schedule rows ──────────────────────────────────────────────
$pdo->prepare("
    INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date)
    SELECT l.tenant_id, ?, l.rent_amount, ?
    FROM leases l
    JOIN tenants t ON t.id = l.tenant_id
    WHERE l.status = 'active'
      AND t.status = 'active'
      AND ? BETWEEN l.start_date AND COALESCE(l.end_date, '9999-12-31')
    ON DUPLICATE KEY UPDATE expected_rent = VALUES(expected_rent), due_date = VALUES(due_date)
")->execute([$month, $dueDate, $month]);

echo "Rent schedule generated for {$month}" . PHP_EOL;

// ── 2. Auto-apply pending credit balances ──────────────────────────────────
// For each active tenant with accumulated credit, insert a credit_applied
// payment row so all aggregate queries (arrears, dashboard, ledger) treat
// the credit as cash received. A new row keeps the audit trail intact.
$creditStmt = $pdo->prepare(
    "SELECT t.id, t.credit_balance, l.rent_amount
     FROM tenants t
     JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
     WHERE t.status = 'active' AND t.credit_balance > 0"
);
$creditStmt->execute();
$tenantsWithCredit = $creditStmt->fetchAll();

foreach ($tenantsWithCredit as $tc) {
    $tenantId   = (int)   $tc['id'];
    $credit     = (float) $tc['credit_balance'];
    $rentAmount = (float) $tc['rent_amount'];
    $creditUsed = min($credit, $rentAmount);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO payments
                 (tenant_id, billing_month, amount_expected, monthly_rent, amount_paid,
                  payment_date, month, collection_status, payment_status,
                  payment_channel, reference_no)
             VALUES (?, ?, 0, ?, ?, ?, ?, 'Paid', 'On Time', 'credit_applied',
                     'Auto-applied credit balance')"
        )->execute([$tenantId, $billingMonth, $rentAmount, $creditUsed, date('Y-m-d'), $month]);

        $pdo->prepare(
            'UPDATE tenants SET credit_balance = GREATEST(0, credit_balance - ?) WHERE id = ?'
        )->execute([$creditUsed, $tenantId]);

        $pdo->commit();
        echo "Credit KSh {$creditUsed} applied for tenant {$tenantId}" . PHP_EOL;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "Credit application failed for tenant {$tenantId}: " . $e->getMessage() . PHP_EOL;
    }
}
