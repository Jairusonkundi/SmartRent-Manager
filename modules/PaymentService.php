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
            "mb.billing_month >= DATE_FORMAT(CURRENT_DATE, '%Y-01')",
            "mb.billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')",
            '(mb.amount_expected - mb.amount_paid) > 0',
        ];
        $params = [];

        if ($search !== '') {
            $where[] = '(t.name LIKE ? OR COALESCE(loc.unit_number, \'\') LIKE ?)';
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($propertyId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM leases fl
                JOIN units fu ON fu.id = fl.unit_id
                WHERE fl.tenant_id = t.id
                  AND fl.status = \'active\'
                  AND fu.property_id = ?
            )';
            $params[] = $propertyId;
        }

        $whereSql = implode(' AND ', $where);
        $monthlyBalancesSql = $this->monthlyPaymentBalancesSql();
        $tenantLocationSql = $this->tenantLocationSql();

        $stmt = $pdo->prepare(
            "SELECT
                t.id AS tenant_id,
                t.name,
                COALESCE(loc.property_name, 'Unassigned Property') AS property_name,
                COALESCE(loc.unit_number, '-') AS unit_number,
                SUM(mb.amount_expected - mb.amount_paid) AS total_debt,
                COUNT(*) AS unpaid_months
            FROM ({$monthlyBalancesSql}) mb
            JOIN tenants t ON t.id = mb.tenant_id
            LEFT JOIN ({$tenantLocationSql}) loc ON loc.tenant_id = t.id
            WHERE {$whereSql}
            GROUP BY t.id, t.name, loc.property_name, loc.unit_number
            ORDER BY total_debt DESC"
        );

        $stmt->execute($params);
        $tenantRows = $stmt->fetchAll() ?: [];

        if ($tenantRows === []) {
            return [];
        }

        $detailsStmt = $pdo->prepare(
            "SELECT
                t.id AS tenant_id,
                mb.billing_month,
                mb.amount_expected,
                mb.amount_paid,
                (mb.amount_expected - mb.amount_paid) AS balance
            FROM ({$monthlyBalancesSql}) mb
            JOIN tenants t ON t.id = mb.tenant_id
            LEFT JOIN ({$tenantLocationSql}) loc ON loc.tenant_id = t.id
            WHERE {$whereSql}
            ORDER BY mb.billing_month ASC"
        );
        $detailsStmt->execute($params);
        $detailRows = $detailsStmt->fetchAll() ?: [];
        $detailsByTenant = [];
        foreach ($detailRows as $detailRow) {
            $detailsByTenant[(int) $detailRow['tenant_id']][] = $detailRow;
        }

        foreach ($tenantRows as &$tenantRow) {
            $tenantId = (int) $tenantRow['tenant_id'];
            $tenantRow['total_outstanding'] = (float) $tenantRow['total_debt'];
            $tenantRow['details'] = $detailsByTenant[$tenantId] ?? [];
        }
        unset($tenantRow);

        return $tenantRows;
    }

    public function arrearsPortfolioStats(?int $propertyId = null): array
    {
        $pdo = Database::connection();
        $params = [];
        $propertyPredicate = '';

        if ($propertyId !== null) {
            $propertyPredicate = ' AND EXISTS (
                SELECT 1
                FROM leases fl
                JOIN units fu ON fu.id = fl.unit_id
                WHERE fl.tenant_id = mb.tenant_id
                  AND fl.status = \'active\'
                  AND fu.property_id = ?
            )';
            $params[] = $propertyId;
        }

        $monthlyBalancesSql = $this->monthlyPaymentBalancesSql();
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_arrears,
                COALESCE(SUM(amount_expected), 0) AS total_expected_ytd
            FROM (
                SELECT
                    mb.tenant_id,
                    mb.billing_month,
                    mb.amount_expected,
                    mb.amount_paid,
                    (mb.amount_expected - mb.amount_paid) AS balance
                FROM ({$monthlyBalancesSql}) mb
                WHERE mb.billing_month >= DATE_FORMAT(CURRENT_DATE, '%Y-01')
                  AND mb.billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
                  {$propertyPredicate}
            ) ytd"
        );
        $stmt->execute($params);
        $totals = $stmt->fetch() ?: ['total_arrears' => 0, 'total_expected_ytd' => 0];

        $riskStmt = $pdo->prepare(
            "SELECT COUNT(*)
            FROM (
                SELECT mb.tenant_id
                FROM ({$monthlyBalancesSql}) mb
                WHERE mb.billing_month >= DATE_FORMAT(CURRENT_DATE, '%Y-01')
                  AND mb.billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
                  AND (mb.amount_expected - mb.amount_paid) > 0
                  {$propertyPredicate}
                GROUP BY mb.tenant_id
                HAVING COUNT(*) > 2
            ) high_risk"
        );
        $riskStmt->execute($params);

        return [
            'total_arrears' => (float) ($totals['total_arrears'] ?? 0),
            'total_expected_ytd' => (float) ($totals['total_expected_ytd'] ?? 0),
            'high_risk_tenants' => (int) ($riskStmt->fetchColumn() ?: 0),
        ];
    }

    private function monthlyPaymentBalancesSql(): string
    {
        return "SELECT
                tenant_id,
                billing_month,
                MAX(amount_expected) AS amount_expected,
                SUM(amount_paid) AS amount_paid
            FROM payments
            WHERE billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
            GROUP BY tenant_id, billing_month";
    }

    private function tenantLocationSql(): string
    {
        return "SELECT
                l.tenant_id,
                GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR ', ') AS property_name,
                GROUP_CONCAT(DISTINCT u.unit_number ORDER BY u.unit_number SEPARATOR ', ') AS unit_number
            FROM leases l
            JOIN units u ON u.id = l.unit_id
            LEFT JOIN properties pr ON pr.id = u.property_id
            WHERE l.status = 'active'
            GROUP BY l.tenant_id";
    }
}
