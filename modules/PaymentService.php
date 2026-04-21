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

            $expectedStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(rs.expected_rent), MAX(l.rent_amount), 0)
                 FROM tenants t
                 LEFT JOIN rent_schedule rs ON rs.tenant_id = t.id AND rs.month = ?
                 LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = ?
                 WHERE t.id = ?'
            );
            $expectedStmt->execute([$monthStart, 'active', $tenantId]);
            $expectedRent = (float) ($expectedStmt->fetchColumn() ?: 0);

            $insertSchedule = $pdo->prepare(
                'INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date, status)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE expected_rent = VALUES(expected_rent), due_date = VALUES(due_date)'
            );
            $insertSchedule->execute([
                $tenantId,
                $monthStart,
                $expectedRent,
                (new DateTimeImmutable($monthStart))->format('Y-m-10'),
                'unpaid',
            ]);

            $sql = 'INSERT INTO payments (tenant_id, billing_month, monthly_rent, amount_paid, payment_date, user_id, status, month) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tenantId,
                $billingMonth,
                $expectedRent,
                $amountPaid,
                $paymentDate,
                $userId,
                $status,
                $monthStart,
            ]);

            $statusSql = "
                UPDATE rent_schedule rs
                LEFT JOIN (
                    SELECT tenant_id, month, SUM(amount_paid) total_paid
                    FROM payments
                    WHERE tenant_id = ? AND month = ?
                    GROUP BY tenant_id, month
                ) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
                SET rs.status = CASE
                    WHEN COALESCE(p.total_paid, 0) >= rs.expected_rent THEN 'paid'
                    WHEN COALESCE(p.total_paid, 0) > 0 THEN 'partial'
                    ELSE 'unpaid'
                END
                WHERE rs.tenant_id = ? AND rs.month = ?
            ";

            $statusStmt = $pdo->prepare($statusSql);
            $statusStmt->execute([$tenantId, $monthStart, $tenantId, $monthStart]);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
