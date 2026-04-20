<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class PaymentService
{
    public function recordPayment(int $tenantId, string $billingMonth, float $amountPaid, string $paymentDate, ?int $userId): bool
    {
        $pdo = Database::connection();
        $monthStart = (new DateTimeImmutable($billingMonth . '-01'))->format('Y-m-d');

        $pdo->beginTransaction();
        try {
            $day = (int) date('d', strtotime($paymentDate));
            $status = $day <= 10 ? 'On Time' : 'Late';

            $sql = 'INSERT INTO payments (tenant_id, billing_month, amount_paid, payment_date, user_id, status) VALUES (?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tenantId,
                $billingMonth,
                $amountPaid,
                $paymentDate,
                $userId,
                $status,
            ]);

            $statusSql = "
                UPDATE rent_schedule rs
                JOIN (
                    SELECT tenant_id, billing_month AS month, SUM(amount_paid) total_paid
                    FROM payments
                    WHERE tenant_id = ? AND billing_month = ?
                    GROUP BY tenant_id, billing_month
                ) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
                SET rs.status = CASE
                    WHEN p.total_paid >= rs.expected_rent THEN 'paid'
                    WHEN p.total_paid > 0 THEN 'partial'
                    ELSE 'unpaid'
                END
                WHERE rs.tenant_id = ? AND rs.month = ?
            ";

            $statusStmt = $pdo->prepare($statusSql);
            $statusStmt->execute([$tenantId, $billingMonth, $tenantId, $monthStart]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
