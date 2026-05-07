<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class BudgetService
{
    public function dashboardData(int $year, string $referenceMonth): array
    {
        $monthly = $this->monthlyBreakdown($year);
        $quarterly = $this->rollupQuarterly($monthly);
        $referenceMonth = substr($referenceMonth, 0, 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $referenceMonth)) {
            $referenceMonth = date('Y-m');
        }

        $ytdCollection = 0.0;
        $ytdArrears = 0.0;
        $projectedFutureRent = 0.0;
        foreach ($monthly as $row) {
            $monthKey = (string) ($row['month_key'] ?? '');
            if (substr($monthKey, 0, 4) !== (string) $year) {
                continue;
            }

            $expected = (float) ($row['expected'] ?? 0);
            $paid = (float) ($row['paid'] ?? 0);
            $outstanding = $expected - $paid;

            if ($monthKey <= $referenceMonth) {
                $ytdCollection += $paid;
                $ytdArrears += $outstanding;
            } else {
                $projectedFutureRent += $expected;
            }
        }

        return [
            'monthly' => $monthly,
            'quarterly' => $quarterly,
            'total_ytd_collection' => $ytdCollection,
            'total_ytd_arrears' => $ytdArrears,
            'projected_future_rent' => $projectedFutureRent,
            'total_annual_budget' => $ytdCollection + $ytdArrears + $projectedFutureRent,
        ];
    }

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
               AND billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
             GROUP BY billing_month
             ORDER BY billing_month"
        );
        $stmt->execute([(string) $year]);

        return $stmt->fetchAll() ?: [];
    }

    public function quarterlyComparison(int $year): array
    {
        return $this->rollupQuarterly($this->monthlyBreakdown($year));
    }

    public function quarterlyYearOverYear(int $year): array
    {
        $current = $this->quarterlyComparison($year);
        $previous = $this->quarterlyComparison($year - 1);
        $previousMap = [];
        foreach ($previous as $row) {
            $previousMap[(string) $row['quarter_label']] = (float) $row['paid'];
        }

        foreach ($current as &$row) {
            $label = (string) $row['quarter_label'];
            $prevPaid = $previousMap[$label] ?? 0.0;
            $row['previous_year_paid'] = $prevPaid;
            $row['yoy_growth_percent'] = $prevPaid > 0 ? (((float) $row['paid'] - $prevPaid) / $prevPaid) * 100 : 0.0;
        }

        return $current;
    }

    public function periodTotals(string $periodStartMonth, string $periodEndMonth): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(amount_expected), 0) AS expected,
                COALESCE(SUM(amount_paid), 0) AS paid
             FROM payments
             WHERE billing_month >= ? AND billing_month <= ?
               AND billing_month <= DATE_FORMAT(CURRENT_DATE, "%Y-%m")'
        );
        $stmt->execute([$periodStartMonth, $periodEndMonth]);

        $row = $stmt->fetch() ?: ['expected' => 0, 'paid' => 0];

        return [
            'expected' => (float) ($row['expected'] ?? 0),
            'paid' => (float) ($row['paid'] ?? 0),
        ];
    }

    private function rollupQuarterly(array $months): array
    {
        $quarters = [
            'Q1' => ['expected' => 0.0, 'paid' => 0.0],
            'Q2' => ['expected' => 0.0, 'paid' => 0.0],
            'Q3' => ['expected' => 0.0, 'paid' => 0.0],
            'Q4' => ['expected' => 0.0, 'paid' => 0.0],
        ];

        foreach ($months as $row) {
            $month = (string) ($row['month_key'] ?? '');
            $monthNumber = (int) substr($month, 5, 2);
            if ($monthNumber < 1 || $monthNumber > 12) {
                continue;
            }

            $quarter = 'Q' . (string) (int) ceil($monthNumber / 3);
            $quarters[$quarter]['expected'] += (float) ($row['expected'] ?? 0);
            $quarters[$quarter]['paid'] += (float) ($row['paid'] ?? 0);
        }

        $result = [];
        foreach ($quarters as $label => $values) {
            $result[] = [
                'quarter_label' => $label,
                'expected' => $values['expected'],
                'paid' => $values['paid'],
                'outstanding' => $values['expected'] - $values['paid'],
            ];
        }

        return $result;
    }
}
