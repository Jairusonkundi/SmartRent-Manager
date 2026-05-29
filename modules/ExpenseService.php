<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class ExpenseService
{
    // ── Private: shared WHERE builder (used by list + count) ─────────────────

    /** @return array{0: list<string>, 1: list<mixed>} */
    private function buildExpenseWhere(
        int    $year,
        string $propertyId,
        string $status,
        string $period
    ): array {
        $where  = ['YEAR(e.expense_date) = ?'];
        $params = [$year];

        if ($propertyId !== 'all') {
            $where[]  = 'e.property_id = ?';
            $params[] = (int) $propertyId;
        }
        if ($status !== 'all') {
            $where[]  = 'e.status = ?';
            $params[] = $status;
        }
        if ($period !== 'all' && $period !== '') {
            $quarterMap = [
                'Q1' => ['Q1', 'January', 'February', 'March', 'Jan', 'Feb', 'Mar'],
                'Q2' => ['Q2', 'April', 'May', 'June', 'Apr', 'Jun'],
                'Q3' => ['Q3', 'July', 'August', 'September', 'Jul', 'Aug', 'Sep'],
                'Q4' => ['Q4', 'October', 'November', 'December', 'Oct', 'Nov', 'Dec'],
            ];
            if (isset($quarterMap[$period])) {
                $inList  = $quarterMap[$period];
                $where[] = 'e.reference_period IN ('
                    . implode(',', array_fill(0, count($inList), '?'))
                    . ')';
                foreach ($inList as $v) {
                    $params[] = $v;
                }
            } else {
                $where[]  = 'e.reference_period = ?';
                $params[] = $period;
            }
        }

        return [$where, $params];
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * @param int $limit  Rows per page; 0 = no limit (return all).
     * @param int $page   1-based page number.
     */
    public function listExpenses(
        int    $year,
        string $propertyId = 'all',
        string $status     = 'all',
        string $period     = 'all',
        int    $limit      = 20,
        int    $page       = 1
    ): array {
        $pdo = Database::connection();
        [$where, $params] = $this->buildExpenseWhere($year, $propertyId, $status, $period);

        $sql = 'SELECT e.*, p.name AS property_name,
                        u.full_name AS created_by_name
                 FROM expenses e
                 LEFT JOIN properties p ON p.id = e.property_id
                 LEFT JOIN users u ON u.id = e.created_by
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY e.expense_date DESC, e.id DESC';

        if ($limit > 0) {
            $offset    = max(0, ($page - 1) * $limit);
            $sql      .= ' LIMIT ? OFFSET ?';
            $params[]  = $limit;
            $params[]  = $offset;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function countExpenses(
        int    $year,
        string $propertyId = 'all',
        string $status     = 'all',
        string $period     = 'all'
    ): int {
        $pdo = Database::connection();
        [$where, $params] = $this->buildExpenseWhere($year, $propertyId, $status, $period);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM expenses e WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function summaryTotals(
        int    $year,
        string $propertyId = 'all',
        string $status     = 'all',
        string $period     = 'all'
    ): array {
        $pdo = Database::connection();
        [$where, $params] = $this->buildExpenseWhere($year, $propertyId, $status, $period);

        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN e.status = 'Requisition Pending' THEN e.amount ELSE 0 END), 0) AS total_pending,
                COALESCE(SUM(CASE WHEN e.status = 'Approved'            THEN e.amount ELSE 0 END), 0) AS total_approved,
                COALESCE(SUM(CASE WHEN e.status = 'Disbursed/Paid'      THEN e.amount ELSE 0 END), 0) AS total_disbursed,
                COALESCE(SUM(e.amount), 0) AS total_all,
                COUNT(*) AS record_count
             FROM expenses e
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return $stmt->fetch() ?: [
            'total_pending'   => 0,
            'total_approved'  => 0,
            'total_disbursed' => 0,
            'total_all'       => 0,
            'record_count'    => 0,
        ];
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    public function addExpense(array $data): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO expenses
                (property_id, category_name, description, amount, status,
                 cheque_number, expense_date, reference_period, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)    $data['property_id'],
            (string) $data['category_name'],
            (string) ($data['description'] ?? ''),
            (float)  $data['amount'],
            (string) $data['status'],
            !empty($data['cheque_number'])    ? (string) $data['cheque_number']    : null,
            (string) $data['expense_date'],
            !empty($data['reference_period']) ? (string) $data['reference_period'] : null,
            !empty($data['created_by'])       ? (int)    $data['created_by']       : null,
        ]);
    }

    public function updateExpenseStatus(int $expenseId, string $newStatus, ?string $chequeNumber = null): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE expenses SET status = ?, cheque_number = COALESCE(?, cheque_number) WHERE id = ?'
        );
        $stmt->execute([$newStatus, $chequeNumber, $expenseId]);
    }

    /**
     * Back-fills reference_period from each row's own expense_date month name
     * for any legacy rows saved without a period.
     */
    public function backfillMissingPeriods(): void
    {
        Database::connection()->prepare(
            "UPDATE expenses
             SET reference_period = DATE_FORMAT(expense_date, '%M')
             WHERE reference_period IS NULL OR reference_period = ''"
        )->execute();
    }

    // ── Report helpers ────────────────────────────────────────────────────────

    public function getDisbursedByMonth(int $year): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month_key,
                    COALESCE(SUM(amount), 0) AS total_expenses
             FROM expenses
             WHERE YEAR(expense_date) = ? AND status = 'Disbursed/Paid'
             GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
             ORDER BY month_key"
        );
        $stmt->execute([$year]);

        $map = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(string) $row['month_key']] = (float) $row['total_expenses'];
        }

        return $map;
    }

    public function getTotalDisbursed(int $year): float
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM expenses
             WHERE YEAR(expense_date) = ? AND status = 'Disbursed/Paid'"
        );
        $stmt->execute([$year]);

        return (float) ($stmt->fetchColumn() ?: 0);
    }
}
