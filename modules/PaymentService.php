<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class PaymentService
{
    /**
     * Arrears are only valid for current/past months where collected cash is below expected rent.
     */
    public function arrearsSummaryByTenant(string $search = '', ?int $propertyId = null): array
    {
        $pdo = Database::connection();

        $where = [
            "p.billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')",
            'p.amount_paid < p.amount_expected',
        ];
        $params = [];

        if ($search !== '') {
            $where[] = '(t.name LIKE ? OR u.unit_number LIKE ? OR pr.name LIKE ?)';
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($propertyId !== null) {
            $where[] = 'pr.id = ?';
            $params[] = $propertyId;
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT
                p.tenant_id,
                t.name,
                COALESCE(pr.name, 'Unassigned Property') AS property_name,
                COALESCE(u.unit_number, '-') AS unit_number,
                SUM(p.amount_expected - p.amount_paid) AS total_arrears
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
            LEFT JOIN units u ON u.id = l.unit_id
            LEFT JOIN properties pr ON pr.id = u.property_id
            WHERE {$whereSql}
            GROUP BY p.tenant_id, t.name, pr.name, u.unit_number
            ORDER BY total_arrears DESC, t.name ASC"
        );

        $stmt->execute($params);
        $tenantRows = $stmt->fetchAll() ?: [];

        if ($tenantRows === []) {
            return [];
        }

        $detailsStmt = $pdo->prepare(
            "SELECT
                p.tenant_id,
                p.billing_month,
                p.amount_expected,
                p.amount_paid,
                (p.amount_expected - p.amount_paid) AS balance
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
            LEFT JOIN units u ON u.id = l.unit_id
            LEFT JOIN properties pr ON pr.id = u.property_id
            WHERE {$whereSql}
            ORDER BY p.billing_month ASC"
        );
        $detailsStmt->execute($params);
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
}
