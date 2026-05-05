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

            $sql = 'INSERT INTO payments (tenant_id, billing_month, amount_expected, monthly_rent, amount_paid, payment_date, user_id, collection_status, payment_status, status, month) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $collectionStatus = $amountPaid >= $expectedRent
                ? 'Paid'
                : ($amountPaid > 0 ? 'Partial' : 'Unpaid');
            $paymentStatus = $status;
            $stmt->execute([
                $tenantId,
                $billingMonth,
                $expectedRent,
                $expectedRent,
                $amountPaid,
                $paymentDate,
                $userId,
                $collectionStatus,
                $paymentStatus,
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

    /**
     * Arrears are only valid for current/past months where collected cash is below expected rent.
     */
    public function arrearsSummaryByTenant(string $search = '', ?int $propertyId = null): array
    {
        $pdo = Database::connection();

        $where = [
            "p.billing_month >= DATE_FORMAT(CURRENT_DATE, '%Y-01')",
            "p.billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')",
            'p.amount_paid < p.amount_expected',
        ];
        $params = [];

        if ($search !== '') {
            $where[] = '(t.name LIKE ? OR u.unit_number LIKE ?)';
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($propertyId !== null) {
            $where[] = 'pr.id = ?';
            $params[] = $propertyId;
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT
                p.tenant_id,
                t.name,
                COALESCE(pr.name, 'Unassigned Property') AS property_name,
                COALESCE(u.unit_number, '-') AS unit_number,
                p.billing_month,
                p.amount_expected AS monthly_rent,
                p.amount_paid,
                (p.amount_expected - p.amount_paid) AS balance,
                totals.total_outstanding
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
            LEFT JOIN units u ON u.id = l.unit_id
            LEFT JOIN properties pr ON pr.id = u.property_id
            JOIN (
                SELECT
                    tenant_id,
                    SUM(amount_expected - amount_paid) AS total_outstanding
                FROM payments
                WHERE billing_month >= DATE_FORMAT(CURRENT_DATE, '%Y-01')
                    AND billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
                    AND amount_paid < amount_expected
                GROUP BY tenant_id
            ) totals ON totals.tenant_id = p.tenant_id
            WHERE {$whereSql}
            ORDER BY p.billing_month ASC, t.name ASC"
        );

        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }
}
