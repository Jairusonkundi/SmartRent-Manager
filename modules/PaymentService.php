<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class PaymentService
{
    /**
     * Arrears are only valid for current/past months where collected cash is below expected rent.
     *
     * @param string $fromMonth  YYYY-MM lower bound (inclusive), empty = no lower bound
     * @param string $toMonth    YYYY-MM upper bound (inclusive), empty = current month
     */
    public function arrearsSummaryByTenant(
        string $search     = '',
        ?int   $propertyId = null,
        string $fromMonth  = '',
        string $toMonth    = ''
    ): array {
        $pdo = Database::connection();

        // ── Outer WHERE: search + property (applied after subquery JOIN) ────────
        $outerWhere = [];
        $params     = [];

        if ($search !== '') {
            $outerWhere[] = '(t.name LIKE ? OR t.phone LIKE ? OR t.email LIKE ? OR u.unit_number LIKE ?)';
            $searchParam  = '%' . $search . '%';
            $params[]     = $searchParam;
            $params[]     = $searchParam;
            $params[]     = $searchParam;
            $params[]     = $searchParam;
        }

        if ($propertyId !== null) {
            $outerWhere[] = 'pr.id = ?';
            $params[]     = $propertyId;
        }

        // ── Inner WHERE: billing_month scope ────────────────────────────────────
        $innerParams = [];
        $innerWhere  = ["p.billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')"];

        if ($fromMonth !== '') {
            $innerWhere[]  = 'p.billing_month >= ?';
            $innerParams[] = $fromMonth;
        }
        if ($toMonth !== '') {
            $innerWhere[]  = 'p.billing_month <= ?';
            $innerParams[] = $toMonth;
        }

        $innerWhereSql = implode(' AND ', $innerWhere);
        $outerWhereSql = $outerWhere !== [] ? 'WHERE ' . implode(' AND ', $outerWhere) : '';
        $detailsAndSql = $outerWhere !== [] ? 'AND ' . implode(' AND ', $outerWhere) : '';

        $stmt = $pdo->prepare(
            "SELECT
                sub.tenant_id,
                t.name,
                COALESCE(pr.name, 'Unassigned Property') AS property_name,
                COALESCE(u.unit_number, '-')              AS unit_number,
                SUM(sub.month_balance)                    AS total_arrears
             FROM (
                 SELECT p.tenant_id,
                        p.billing_month,
                        SUM(p.amount_expected) - SUM(p.amount_paid) AS month_balance
                 FROM payments p
                 WHERE {$innerWhereSql}
                 GROUP BY p.tenant_id, p.billing_month
                 HAVING month_balance > 0
             ) sub
             JOIN tenants t          ON t.id = sub.tenant_id
             LEFT JOIN leases l      ON l.tenant_id = t.id AND l.status = 'active'
             LEFT JOIN units u       ON u.id = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             {$outerWhereSql}
             GROUP BY sub.tenant_id, t.name, pr.name, u.unit_number
             ORDER BY total_arrears DESC, t.name ASC"
        );
        $stmt->execute(array_merge($innerParams, $params));
        $tenantRows = $stmt->fetchAll() ?: [];

        if ($tenantRows === []) {
            return [];
        }

        $detailsStmt = $pdo->prepare(
            "SELECT
                p.tenant_id,
                p.billing_month,
                SUM(p.amount_expected)                      AS amount_expected,
                SUM(p.amount_paid)                          AS amount_paid,
                SUM(p.amount_expected) - SUM(p.amount_paid) AS balance
             FROM payments p
             JOIN tenants t          ON t.id = p.tenant_id
             LEFT JOIN leases l      ON l.tenant_id = t.id AND l.status = 'active'
             LEFT JOIN units u       ON u.id = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE {$innerWhereSql}
               {$detailsAndSql}
             GROUP BY p.tenant_id, p.billing_month
             HAVING balance > 0
             ORDER BY p.billing_month ASC"
        );
        $detailsStmt->execute(array_merge($innerParams, $params));
        $detailRows = $detailsStmt->fetchAll() ?: [];
        $detailsByTenant = [];
        foreach ($detailRows as $detailRow) {
            $detailsByTenant[(int) $detailRow['tenant_id']][] = $detailRow;
        }

        foreach ($tenantRows as &$tenantRow) {
            $tenantRow['details'] = $detailsByTenant[(int) $tenantRow['tenant_id']] ?? [];
        }
        unset($tenantRow);

        return $tenantRows;
    }

    /**
     * Returns one ledger row per tenant using the latest imported Excel payment in the filtered period.
     * The row details contain the tenant's month-by-month imported payment history.
     */
    public function tenantLedger(
        string $search = '',
        ?int $propertyId = null,
        string $status = 'all',
        string $monthFrom = '',
        string $monthTo = ''
    ): array {
        $pdo = Database::connection();
        $currentMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');

        $where = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(t.name LIKE ? OR t.phone LIKE ? OR t.tenant_phone LIKE ? OR u.unit_number LIKE ? OR pr.name LIKE ?)';
            $searchParam = '%' . $search . '%';
            array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
        }

        if ($propertyId !== null) {
            $where[] = 'pr.id = ?';
            $params[] = $propertyId;
        }

        if ($monthFrom !== '') {
            $where[] = 'p.billing_month >= ?';
            $params[] = $monthFrom;
        }

        if ($monthTo !== '') {
            $where[] = 'p.billing_month <= ?';
            $params[] = $monthTo;
        }

        $statusSql = "CASE
            WHEN p.amount_paid >= p.amount_expected THEN 'Paid'
            WHEN p.amount_paid > 0 THEN 'Partial'
            WHEN p.billing_month < ? THEN 'Overdue'
            ELSE 'Unpaid'
        END";
        $selectParams = [$currentMonth];

        if ($status !== 'all') {
            $where[] = "{$statusSql} = ?";
            $params[] = $currentMonth;
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $pdo->prepare(
            "SELECT
                p.id,
                p.tenant_id,
                p.billing_month,
                p.amount_expected,
                p.amount_paid,
                (p.amount_expected - p.amount_paid) AS balance,
                t.name,
                COALESCE(NULLIF(t.phone, ''), NULLIF(t.tenant_phone, ''), '-') AS phone,
                COALESCE(pr.name, 'Unassigned Property') AS property_name,
                COALESCE(u.unit_number, '-') AS unit_number,
                {$statusSql} AS status
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
            LEFT JOIN units u ON u.id = l.unit_id
            LEFT JOIN properties pr ON pr.id = u.property_id
            WHERE {$whereSql}
            ORDER BY t.name ASC, p.billing_month DESC, p.id DESC"
        );
        $stmt->execute(array_merge($selectParams, $params));
        $paymentRows = $stmt->fetchAll() ?: [];

        $ledgerRows = [];
        foreach ($paymentRows as $paymentRow) {
            $tenantId = (int) $paymentRow['tenant_id'];
            $detailRow = [
                'billing_month' => $paymentRow['billing_month'],
                'amount_expected' => $paymentRow['amount_expected'],
                'amount_paid' => $paymentRow['amount_paid'],
                'balance' => $paymentRow['balance'],
                'status' => $paymentRow['status'],
            ];

            if (!isset($ledgerRows[$tenantId])) {
                $paymentRow['details'] = [$detailRow];
                $ledgerRows[$tenantId] = $paymentRow;
                continue;
            }

            $ledgerRows[$tenantId]['details'][] = $detailRow;
        }

        return array_values($ledgerRows);
    }

}
