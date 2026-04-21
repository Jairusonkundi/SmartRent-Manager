<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class DashboardService
{
    public function summary(string $month): array
    {
        $pdo = Database::connection();
        $monthStart = (new DateTimeImmutable($month))->modify('first day of this month')->format('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(rs.expected_rent),0) AS total_expected,
                COALESCE(SUM(p.total_paid),0) AS total_paid
            FROM rent_schedule rs
            LEFT JOIN (
                SELECT tenant_id, month, SUM(amount_paid) AS total_paid
                FROM payments
                GROUP BY tenant_id, month
            ) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
            WHERE rs.month = ?"
        );
        $stmt->execute([$monthStart]);
        $row = $stmt->fetch() ?: ['total_expected' => 0, 'total_paid' => 0];

        $expected = (float) $row['total_expected'];
        $paid = (float) $row['total_paid'];
        $outstanding = max($expected - $paid, 0);

        return [
            'expected' => $expected,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'collection_percent' => $expected > 0 ? round(($paid / $expected) * 100, 2) : 0,
        ];
    }

    public function portfolioKpis(string $month): array
    {
        $pdo = Database::connection();
        $monthStart = (new DateTimeImmutable($month))->modify('first day of this month')->format('Y-m-d');
        $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

        $expectedStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(l.rent_amount), 0)
             FROM leases l
             JOIN units u ON u.id = l.unit_id
             WHERE l.status = ? AND u.status = ?"
        );
        $expectedStmt->execute(['active', 'occupied']);
        $potentialRevenue = (float) ($expectedStmt->fetchColumn() ?: 0);

        $paidStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_paid), 0)
             FROM payments
             WHERE month = ?'
        );
        $paidStmt->execute([$monthStart]);
        $actualRevenue = (float) ($paidStmt->fetchColumn() ?: 0);

        $arrearsStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(expected_rent), 0) - COALESCE(SUM(total_paid), 0)
             FROM (
                SELECT rs.tenant_id, rs.month, rs.expected_rent, COALESCE(SUM(p.amount_paid), 0) AS total_paid
                FROM rent_schedule rs
                LEFT JOIN payments p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
                GROUP BY rs.tenant_id, rs.month, rs.expected_rent
             ) debt_rollup'
        );
        $arrearsStmt->execute();

        $occupancyStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_units,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS occupied_units
             FROM units"
        );
        $occupancyStmt->execute(['occupied']);
        $occupancy = $occupancyStmt->fetch() ?: ['total_units' => 0, 'occupied_units' => 0];

        $lateStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM payments
             WHERE payment_date >= ? AND payment_date <= ? AND DAY(payment_date) > 10'
        );
        $lateStmt->execute([$monthStart, $monthEnd]);

        $occupiedUnits = (int) ($occupancy['occupied_units'] ?? 0);
        $totalUnits = (int) ($occupancy['total_units'] ?? 0);

        return [
            'total_expected' => $potentialRevenue,
            'total_paid' => $actualRevenue,
            'collection_efficiency' => $potentialRevenue > 0 ? round(($actualRevenue / $potentialRevenue) * 100, 2) : 0.0,
            'arrears_trend' => max((float) $arrearsStmt->fetchColumn(), 0),
            'occupied_units' => $occupiedUnits,
            'total_units' => $totalUnits,
            'occupancy_rate' => $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 2) : 0.0,
            'late_payment_alert' => (int) $lateStmt->fetchColumn(),
        ];
    }

    public function monthlyTrend(int $months = 12): array
    {
        $pdo = Database::connection();
        $sql = "
            SELECT DATE_FORMAT(rs.month, '%Y-%m') AS month_key,
                   SUM(rs.expected_rent) AS expected,
                   COALESCE(SUM(p.total_paid), 0) AS paid
            FROM rent_schedule rs
            LEFT JOIN (
                SELECT tenant_id, month, SUM(amount_paid) AS total_paid
                FROM payments
                GROUP BY tenant_id, month
            ) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
            GROUP BY rs.month
            ORDER BY rs.month DESC
            LIMIT ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $months, PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll());
    }

    public function paymentStatusDistribution(string $month): array
    {
        $pdo = Database::connection();
        $monthStart = (new DateTimeImmutable($month))->modify('first day of this month')->format('Y-m-d');
        $stmt = $pdo->prepare('SELECT status, COUNT(*) total FROM rent_schedule WHERE month = ? GROUP BY status');
        $stmt->execute([$monthStart]);

        return $stmt->fetchAll();
    }
}
