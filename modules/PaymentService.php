<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class PaymentService
{
    public function recordPayment(int $tenantId, string $month, float $amount, string $paymentDate, ?int $recordedBy): void
    {
        $pdo = Database::connection();
        $monthStart = (new DateTimeImmutable($month))->modify('first day of this month')->format('Y-m-d');

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO payments (tenant_id, amount_paid, payment_date, month, recorded_by) VALUES (:tenant_id, :amount_paid, :payment_date, :month, :recorded_by)'
            );
            $insert->execute([
                'tenant_id' => $tenantId,
                'amount_paid' => $amount,
                'payment_date' => $paymentDate,
                'month' => $monthStart,
                'recorded_by' => $recordedBy,
            ]);

            $statusSql = "
                UPDATE rent_schedule rs
                JOIN (
                    SELECT tenant_id, month, SUM(amount_paid) total_paid
                    FROM payments
                    WHERE tenant_id = :tenant_id AND month = :month
                    GROUP BY tenant_id, month
                ) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
                SET rs.status = CASE
                    WHEN p.total_paid >= rs.expected_rent THEN 'paid'
                    WHEN p.total_paid > 0 THEN 'partial'
                    ELSE 'unpaid'
                END
                WHERE rs.tenant_id = :tenant_id AND rs.month = :month
            ";

            $statusStmt = $pdo->prepare($statusSql);
            $statusStmt->execute(['tenant_id' => $tenantId, 'month' => $monthStart]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
