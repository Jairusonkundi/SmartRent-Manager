<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class RentCollectionService
{
    public function dashboardData(int $year, string $referenceMonth): array
    {
        $monthly = $this->monthlyBreakdown($year);
        $quarterly = $this->rollupQuarterly($monthly);

        $isAllMonths = $referenceMonth === 'all';
        if (!$isAllMonths) {
            $referenceMonth = substr($referenceMonth, 0, 7);
            if (!preg_match('/^\d{4}-\d{2}$/', $referenceMonth) || (int) substr($referenceMonth, 0, 4) !== $year) {
                $referenceMonth = sprintf('%04d-01', $year);
            }
        }

        $totalCollection = 0.0;
        $totalArrears = 0.0;
        foreach ($monthly as $row) {
            $monthKey = (string) ($row['month_key'] ?? '');
            if (substr($monthKey, 0, 4) !== (string) $year) {
                continue;
            }

            if (!$isAllMonths && $monthKey !== $referenceMonth) {
                continue;
            }

            $expected = (float) ($row['expected'] ?? 0);
            $paid = (float) ($row['paid'] ?? 0);
            $outstanding = $expected - $paid;

            $totalCollection += $paid;
            $totalArrears += max(0.0, $outstanding);
        }

        return [
            'monthly' => $monthly,
            'quarterly' => $quarterly,
            'total_ytd_collection' => $totalCollection,
            'total_ytd_arrears' => $totalArrears,
            'total_ytd_budget' => $totalCollection + $totalArrears,
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
                GREATEST(SUM(amount_expected) - SUM(amount_paid), 0) AS outstanding
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

    public function getMonthlyCollectionWithProperty(int $year, string $propertyFilter): array
    {
        $pdo            = Database::connection();
        $propertyWhere  = $propertyFilter !== 'all' ? ' AND pr.id = ?' : '';
        $propertyParams = $propertyFilter !== 'all' ? [(int) $propertyFilter] : [];

        $stmt = $pdo->prepare(
            "SELECT p.billing_month AS month_key,
                    COALESCE(SUM(p.amount_expected), 0) AS expected,
                    COALESCE(SUM(p.amount_paid), 0)     AS paid,
                    GREATEST(COALESCE(SUM(p.amount_expected) - SUM(p.amount_paid), 0), 0) AS outstanding
             FROM payments p
             LEFT JOIN leases l      ON l.tenant_id = p.tenant_id AND l.status = 'active'
             LEFT JOIN units u       ON u.id = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE LEFT(p.billing_month, 4) = ?
               AND p.billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
               {$propertyWhere}
             GROUP BY p.billing_month
             ORDER BY p.billing_month"
        );
        $stmt->execute(array_merge([(string) $year], $propertyParams));

        return $stmt->fetchAll() ?: [];
    }

    private function rollupQuarterly(array $months): array
    {
        $quarters = [
            'Q1' => ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0],
            'Q2' => ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0],
            'Q3' => ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0],
            'Q4' => ['expected' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0],
        ];

        foreach ($months as $row) {
            $month = (string) ($row['month_key'] ?? '');
            $monthNumber = (int) substr($month, 5, 2);
            if ($monthNumber < 1 || $monthNumber > 12) {
                continue;
            }

            $expected = (float) ($row['expected'] ?? 0);
            $paid     = (float) ($row['paid']     ?? 0);
            $quarter  = 'Q' . (string) (int) ceil($monthNumber / 3);

            $quarters[$quarter]['expected']    += $expected;
            $quarters[$quarter]['paid']        += $paid;
            // Sum per-month GREATEST(0, outstanding) so overpayments in one month
            // never cancel genuine arrears in another month of the same quarter.
            $quarters[$quarter]['outstanding'] += max(0.0, $expected - $paid);
        }

        $result = [];
        foreach ($quarters as $label => $values) {
            $result[] = [
                'quarter_label' => $label,
                'expected'      => $values['expected'],
                'paid'          => $values['paid'],
                'outstanding'   => $values['outstanding'],
            ];
        }

        return $result;
    }
}
