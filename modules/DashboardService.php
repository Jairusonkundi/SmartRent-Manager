<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class DashboardService
{
    public function summary(string $month): array
    {
        $pdo = Database::connection();
        $billingMonth = (new DateTimeImmutable($month))->format('Y-m');

        $stmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(amount_expected), 0) AS total_expected,
                COALESCE(SUM(amount_paid), 0) AS total_paid
             FROM payments
             WHERE billing_month = ?'
        );
        $stmt->execute([$billingMonth]);
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
        $billingMonth = (new DateTimeImmutable($month))->format('Y-m');

        $expectedStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_expected), 0) AS total_expected
             FROM payments
             WHERE billing_month = ?'
        );
        $expectedStmt->execute([$billingMonth]);
        $totalExpected = (float) ($expectedStmt->fetchColumn() ?: 0);

        $paidStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_paid), 0)
             FROM payments
             WHERE billing_month = ?'
        );
        $paidStmt->execute([$billingMonth]);
        $totalPaid = (float) ($paidStmt->fetchColumn() ?: 0);

        $arrearsStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_expected - amount_paid), 0)
             FROM payments
             WHERE amount_paid < amount_expected'
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
            'total_expected' => $totalExpected,
            'total_paid' => $totalPaid,
            'collection_efficiency' => $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 2) : 0.0,
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
            SELECT billing_month AS month_key,
                   SUM(amount_expected) AS expected,
                   SUM(amount_paid) AS paid
            FROM payments
            GROUP BY billing_month
            ORDER BY billing_month DESC
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
        $billingMonth = (new DateTimeImmutable($month))->format('Y-m');
        $stmt = $pdo->prepare('SELECT collection_status AS status, COUNT(*) total FROM payments WHERE billing_month = ? GROUP BY collection_status');
        $stmt->execute([$billingMonth]);

        return $stmt->fetchAll();
    }
}
