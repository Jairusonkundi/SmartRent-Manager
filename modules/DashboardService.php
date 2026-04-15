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
            WHERE rs.month = :month"
        );
        $stmt->execute(['month' => $monthStart]);
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
            LIMIT :months
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll());
    }

    public function paymentStatusDistribution(string $month): array
    {
        $pdo = Database::connection();
        $monthStart = (new DateTimeImmutable($month))->modify('first day of this month')->format('Y-m-d');
        $stmt = $pdo->prepare(
            "SELECT payment_status AS status, COUNT(*) total
             FROM payments
             WHERE month = :month
             GROUP BY payment_status"
        );
        $stmt->execute(['month' => $monthStart]);

        return $stmt->fetchAll();
    }
}
