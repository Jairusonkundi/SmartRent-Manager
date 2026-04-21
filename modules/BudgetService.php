<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class BudgetService
{
    public function monthlyBreakdown(int $year): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT
                billing_month AS month_key,
                SUM(amount_expected) AS expected,
                SUM(amount_paid) AS paid,
                SUM(amount_expected) - SUM(amount_paid) AS outstanding
             FROM payments
             WHERE LEFT(billing_month, 4) = ?
             GROUP BY billing_month
             ORDER BY billing_month"
        );
        $stmt->execute([(string) $year]);

        return $stmt->fetchAll() ?: [];
    }

    public function quarterlyComparison(int $year): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT
                CONCAT('Q', QUARTER(CONCAT(billing_month, '-01'))) AS quarter_label,
                SUM(amount_expected) AS expected,
                SUM(amount_paid) AS paid,
                SUM(amount_expected) - SUM(amount_paid) AS outstanding
            FROM payments
            WHERE LEFT(billing_month, 4) = ?
            GROUP BY QUARTER(CONCAT(billing_month, '-01'))
            ORDER BY QUARTER(CONCAT(billing_month, '-01'))"
        );
        $stmt->execute([(string) $year]);

        return $stmt->fetchAll() ?: [];
    }

    public function periodTotals(string $periodStartMonth, string $periodEndMonth): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(amount_expected), 0) AS expected,
                COALESCE(SUM(amount_paid), 0) AS paid
             FROM payments
             WHERE billing_month >= ? AND billing_month <= ?'
        );
        $stmt->execute([$periodStartMonth, $periodEndMonth]);

        $row = $stmt->fetch() ?: ['expected' => 0, 'paid' => 0];

        return [
            'expected' => (float) ($row['expected'] ?? 0),
            'paid' => (float) ($row['paid'] ?? 0),
        ];
    }
}
