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
                DATE_FORMAT(rs.month, '%Y-%m') AS month_key,
                SUM(rs.expected_rent) AS expected,
                COALESCE(SUM(p.total_paid),0) AS paid,
                SUM(rs.expected_rent) - COALESCE(SUM(p.total_paid),0) AS outstanding
             FROM rent_schedule rs
             LEFT JOIN (
                SELECT tenant_id, month, SUM(amount_paid) AS total_paid
                FROM payments
                GROUP BY tenant_id, month
             ) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
             WHERE YEAR(rs.month) = :year
             GROUP BY rs.month
             ORDER BY rs.month"
        );
        $stmt->execute(['year' => $year]);

        return $stmt->fetchAll();
    }

    public function quarterlyComparison(int $year): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT
                CONCAT('Q', QUARTER(rs.month)) AS quarter_label,
                SUM(rs.expected_rent) AS expected,
                COALESCE(SUM(p.total_paid),0) AS paid,
                SUM(rs.expected_rent) - COALESCE(SUM(p.total_paid),0) AS outstanding
            FROM rent_schedule rs
            LEFT JOIN (
                SELECT tenant_id, month, SUM(amount_paid) AS total_paid
                FROM payments
                GROUP BY tenant_id, month
            ) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
            WHERE YEAR(rs.month) = :year
            GROUP BY QUARTER(rs.month)
            ORDER BY QUARTER(rs.month)"
        );
        $stmt->execute(['year' => $year]);

        return $stmt->fetchAll();
    }
}
