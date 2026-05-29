<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class TenantService
{
    // ── Read ──────────────────────────────────────────────────────────────────

    public function listTenants(): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->query(
            "SELECT t.id, t.name, t.phone, t.email, t.status,
                    l.id AS lease_id, l.rent_amount, l.start_date, l.end_date, l.status AS lease_status,
                    u.id AS unit_id, u.unit_number,
                    p.id AS property_id, p.name AS property_name
             FROM tenants t
             LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
             LEFT JOIN units u ON u.id = l.unit_id
             LEFT JOIN properties p ON p.id = u.property_id
             ORDER BY t.name"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function getTenant(int $id): ?array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT t.*, l.id AS lease_id, l.rent_amount, l.start_date, l.end_date,
                    l.status AS lease_status, l.unit_id,
                    u.unit_number, p.name AS property_name, p.id AS property_id
             FROM tenants t
             LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
             LEFT JOIN units u ON u.id = l.unit_id
             LEFT JOIN properties p ON p.id = u.property_id
             WHERE t.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function searchTenants(string $query): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT t.id, t.name, t.phone, t.email, t.credit_balance,
                    l.id AS lease_id, l.rent_amount,
                    u.id AS unit_id, u.unit_number,
                    p.id AS property_id, p.name AS property_name
             FROM tenants t
             JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
             JOIN units u ON u.id = l.unit_id
             JOIN properties p ON p.id = u.property_id
             WHERE t.status = 'active' AND (t.name LIKE ? OR t.email LIKE ? OR t.phone LIKE ?)
             ORDER BY t.name
             LIMIT 20"
        );
        $like = '%' . $query . '%';
        $stmt->execute([$like, $like, $like]);

        return $stmt->fetchAll() ?: [];
    }

    public function addCredit(int $tenantId, float $amount): void
    {
        Database::connection()
            ->prepare('UPDATE tenants SET credit_balance = credit_balance + ? WHERE id = ?')
            ->execute([$amount, $tenantId]);
    }

    public function listTenantsFiltered(
        string $search      = '',
        string $status      = 'all',
        string $leaseStatus = 'all',
        int    $page        = 1,
        int    $perPage     = 15
    ): array {
        $pdo        = Database::connection();
        $offset     = max(0, ($page - 1) * $perPage);
        $conditions = [];
        $params     = [];

        if ($search !== '') {
            $conditions[] = '(t.name LIKE ? OR t.phone LIKE ? OR t.email LIKE ? OR u.unit_number LIKE ?)';
            $like         = '%' . $search . '%';
            $params[]     = $like;
            $params[]     = $like;
            $params[]     = $like;
            $params[]     = $like;
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $conditions[] = 't.status = ?';
            $params[]     = $status;
        }

        if ($leaseStatus === 'with_lease') {
            $conditions[] = 'l.id IS NOT NULL';
        } elseif ($leaseStatus === 'no_lease') {
            $conditions[] = 'l.id IS NULL';
        }

        $where    = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $pdo->prepare(
            "SELECT t.id, t.name, t.phone, t.email, t.status,
                    l.id AS lease_id, l.rent_amount, l.start_date, l.end_date, l.status AS lease_status,
                    u.id AS unit_id, u.unit_number,
                    p.id AS property_id, p.name AS property_name
             FROM tenants t
             LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
             LEFT JOIN units u  ON u.id = l.unit_id
             LEFT JOIN properties p ON p.id = u.property_id
             {$where}
             ORDER BY t.name
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function countTenantsFiltered(
        string $search      = '',
        string $status      = 'all',
        string $leaseStatus = 'all'
    ): int {
        $pdo        = Database::connection();
        $conditions = [];
        $params     = [];

        if ($search !== '') {
            $conditions[] = '(t.name LIKE ? OR t.phone LIKE ? OR t.email LIKE ? OR u.unit_number LIKE ?)';
            $like         = '%' . $search . '%';
            $params[]     = $like;
            $params[]     = $like;
            $params[]     = $like;
            $params[]     = $like;
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $conditions[] = 't.status = ?';
            $params[]     = $status;
        }

        if ($leaseStatus === 'with_lease') {
            $conditions[] = 'l.id IS NOT NULL';
        } elseif ($leaseStatus === 'no_lease') {
            $conditions[] = 'l.id IS NULL';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $pdo->prepare(
            "SELECT COUNT(t.id)
             FROM tenants t
             LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
             LEFT JOIN units u  ON u.id = l.unit_id
             {$where}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // ── Upsert (used by import handler) ──────────────────────────────────────

    public function upsertTenant(string $name, string $phone, string $email): int
    {
        $pdo = Database::connection();

        $findStmt = $pdo->prepare('SELECT id FROM tenants WHERE name = ? LIMIT 1');
        $findStmt->execute([$name]);
        $tenantId = (int) ($findStmt->fetchColumn() ?: 0);

        if ($tenantId > 0) {
            $updateStmt = $pdo->prepare(
                'UPDATE tenants
                 SET phone = ?,
                     email = ?,
                     tenant_phone = ?,
                     tenant_email = ?,
                     status = ?
                 WHERE id = ?'
            );
            $updateStmt->execute([$phone, $email, $phone, $email, 'active', $tenantId]);

            return $tenantId;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO tenants (name, phone, email, tenant_phone, tenant_email, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([$name, $phone, $email, $phone, $email, 'active']);

        return (int) $pdo->lastInsertId();
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function createTenant(string $name, string $phone, string $email): int
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO tenants (name, phone, email, tenant_phone, tenant_email, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([trim($name), trim($phone), trim($email), trim($phone), trim($email), 'active']);

        return (int) $pdo->lastInsertId();
    }

    public function createLease(int $tenantId, int $unitId, float $rentAmount, string $startDate): int
    {
        $pdo = Database::connection();

        // Check whether a row matching the unique key already exists.
        // This happens when a lease is terminated and immediately re-created
        // on the same day — the terminated row keeps the same (tenant_id, unit_id, start_date).
        $dupeStmt = $pdo->prepare(
            'SELECT id, status FROM leases WHERE tenant_id = ? AND unit_id = ? AND start_date = ? LIMIT 1'
        );
        $dupeStmt->execute([$tenantId, $unitId, $startDate]);
        $existing = $dupeStmt->fetch();

        if ($existing !== false) {
            if ((string) $existing['status'] === 'active') {
                throw new \RuntimeException(
                    'An active lease for this tenant and unit already starts on ' . $startDate . '. ' .
                    'Terminate it first or choose a different start date.'
                );
            }

            // Reactivate the terminated row in place rather than inserting a duplicate.
            $pdo->prepare(
                "UPDATE leases SET status = 'active', rent_amount = ?, end_date = NULL WHERE id = ?"
            )->execute([$rentAmount, (int) $existing['id']]);

            $pdo->prepare('UPDATE units SET status = ? WHERE id = ?')->execute(['occupied', $unitId]);

            return (int) $existing['id'];
        }

        // No conflicting row — terminate any other active lease on this unit, then insert.
        $pdo->prepare(
            "UPDATE leases SET status = 'terminated', end_date = ? WHERE unit_id = ? AND status = 'active'"
        )->execute([$startDate, $unitId]);

        $pdo->prepare('UPDATE units SET status = ? WHERE id = ?')->execute(['occupied', $unitId]);

        $insertStmt = $pdo->prepare(
            "INSERT INTO leases (tenant_id, unit_id, rent_amount, start_date, status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $insertStmt->execute([$tenantId, $unitId, $rentAmount, $startDate]);

        return (int) $pdo->lastInsertId();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function updateTenant(int $id, string $name, string $phone, string $email): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE tenants SET name = ?, phone = ?, email = ?, tenant_phone = ?, tenant_email = ? WHERE id = ?'
        );
        $stmt->execute([trim($name), trim($phone), trim($email), trim($phone), trim($email), $id]);
    }

    public function updateLease(int $leaseId, float $rentAmount, string $startDate, ?string $endDate): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE leases SET rent_amount = ?, start_date = ?, end_date = ? WHERE id = ?'
        );
        $stmt->execute([$rentAmount, $startDate, $endDate ?: null, $leaseId]);
    }

    public function terminateLease(int $leaseId, string $endDate): void
    {
        $pdo = Database::connection();

        $pdo->beginTransaction();
        try {
            $leaseStmt = $pdo->prepare(
                "SELECT id, unit_id
                 FROM leases
                 WHERE id = :lease_id AND status = 'active'
                 LIMIT 1
                 FOR UPDATE"
            );
            $leaseStmt->execute(['lease_id' => $leaseId]);
            $lease = $leaseStmt->fetch();

            if ($lease === false) {
                throw new \RuntimeException('This lease is no longer active and cannot be terminated.');
            }

            $terminateLeaseStmt = $pdo->prepare(
                "UPDATE leases
                 SET status = 'terminated', end_date = :end_date
                 WHERE id = :lease_id"
            );
            $terminateLeaseStmt->execute([
                'end_date' => $endDate,
                'lease_id' => $leaseId,
            ]);

            $vacateUnitStmt = $pdo->prepare("UPDATE units SET status = 'vacant' WHERE id = :unit_id");
            $vacateUnitStmt->execute([
                'unit_id' => (int) $lease['unit_id'],
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    // ── Status lifecycle ─────────────────────────────────────────────────────

    public function deactivateTenant(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE tenants SET status = 'inactive' WHERE id = ?")->execute([$id]);
    }

    public function activateTenant(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$id]);
    }

    // ── Ledger ────────────────────────────────────────────────────────────────

    /**
     * Returns display-ready ledger rows for one tenant in a given year.
     *
     * Aggregates cash payments per billing_month (excludes credit_applied rows —
     * internal entries that would double-count the same cash already shown in the
     * overpaid month).
     *
     * For current/past months where amount_expected = 0 (cron not yet run), the
     * active lease rent fills in as the expected amount so the row is meaningful.
     *
     * Carry-credit algorithm: when a month is overpaid the surplus rolls forward
     * as a deduction on the next row rather than generating a mid-loop synthetic
     * row. A single synthetic row is emitted after the last DB row only if carry
     * credit remains — tagged 'Advance' only when its period is strictly in the
     * future (> current calendar month); otherwise treated as a normal billing row.
     *
     * Running outstanding is allowed to go negative (negative = credit owed back).
     */
    public function getLedgerForTenant(int $tenantId, int $year): array
    {
        $pdo = Database::connection();

        $leaseStmt = $pdo->prepare(
            "SELECT rent_amount FROM leases WHERE tenant_id = ? AND status = 'active' LIMIT 1"
        );
        $leaseStmt->execute([$tenantId]);
        $leaseRent = (float) ($leaseStmt->fetchColumn() ?: 0.0);

        $currentMonth = date('Y-m');

        $stmt = $pdo->prepare(
            "SELECT
                p.billing_month,
                SUM(p.amount_expected)                                                AS amount_expected,
                SUM(p.amount_paid)                                                    AS amount_paid,
                MAX(p.payment_date)                                                   AS payment_date,
                GROUP_CONCAT(DISTINCT p.payment_channel ORDER BY p.id SEPARATOR '/') AS payment_channel,
                GROUP_CONCAT(DISTINCT p.reference_no    ORDER BY p.id SEPARATOR ', ') AS reference_no
             FROM payments p
             WHERE p.tenant_id = ?
               AND p.billing_month LIKE ?
               AND p.payment_channel != 'credit_applied'
             GROUP BY p.billing_month
             ORDER BY p.billing_month ASC"
        );
        $stmt->execute([$tenantId, $year . '%']);
        $monthlyRows = $stmt->fetchAll() ?: [];

        $displayRows = [];
        $running     = 0.0;
        $carryCredit = 0.0;
        $lastBilling = '';
        $lastPayDate = null;

        foreach ($monthlyRows as $row) {
            $dbExpected = (float) $row['amount_expected'];
            $dbPaid     = (float) $row['amount_paid'];
            $billing    = (string) $row['billing_month'];
            $channel    = $row['payment_channel'] ?? null;
            $refNo      = $row['reference_no']    ?? null;
            $payDate    = $row['payment_date']    ?? null;

            // Absorb carry-credit from the previous overpaid month.
            $totalPaid   = $dbPaid + $carryCredit;
            $carryCredit = 0.0;

            // For current/past months where the cron has not yet posted the invoice
            // (expected = 0), use the lease rent so the row shows a real figure.
            $effectiveExpected = ($dbExpected === 0.0 && $billing <= $currentMonth && $leaseRent > 0.0)
                ? $leaseRent
                : $dbExpected;

            if ($effectiveExpected > 0.0 && $totalPaid > $effectiveExpected) {
                // Overpayment: cap display paid at expected; carry surplus forward.
                $carryCredit = round($totalPaid - $effectiveExpected, 2);

                $displayRows[] = [
                    'billing_month'       => $billing,
                    'payment_date'        => $payDate,
                    'amount_expected'     => $effectiveExpected,
                    'amount_paid'         => $effectiveExpected,
                    'collection_status'   => 'Paid',
                    'payment_channel'     => $channel,
                    'reference_no'        => $refNo,
                    'running_outstanding' => $running,
                    'is_advance'          => false,
                ];
                // Running delta = 0 (fully paid month).
            } else {
                $running += $effectiveExpected - $totalPaid;

                if ($totalPaid >= $effectiveExpected && $effectiveExpected > 0.0) {
                    $cs = 'Paid';
                } elseif ($totalPaid > 0.0) {
                    $cs = 'Partial';
                } else {
                    $cs = 'Unpaid';
                }

                $displayRows[] = [
                    'billing_month'       => $billing,
                    'payment_date'        => $payDate,
                    'amount_expected'     => $effectiveExpected,
                    'amount_paid'         => $totalPaid,
                    'collection_status'   => $cs,
                    'payment_channel'     => $channel,
                    'reference_no'        => $refNo,
                    'running_outstanding' => $running,
                    'is_advance'          => false,
                ];
            }

            $lastBilling = $billing;
            $lastPayDate = $payDate;
        }

        // If carry-credit remains after the last DB row, emit one synthetic row
        // for the next billing period.
        if ($carryCredit > 0.0 && $lastBilling !== '') {
            $advancePeriod = (new \DateTimeImmutable($lastBilling . '-01'))
                ->modify('+1 month')
                ->format('Y-m');

            $isAdvance = $advancePeriod > $currentMonth;

            // Future periods have no invoice yet; current/past use the lease rent.
            $syntheticExpected = (!$isAdvance && $leaseRent > 0.0) ? $leaseRent : 0.0;

            $running += $syntheticExpected - $carryCredit;

            if ($isAdvance) {
                $syntheticStatus = 'Advance';
            } elseif ($syntheticExpected > 0.0 && $carryCredit >= $syntheticExpected) {
                $syntheticStatus = 'Paid';
            } else {
                $syntheticStatus = 'Partial';
            }

            $displayRows[] = [
                'billing_month'       => $advancePeriod,
                'payment_date'        => $lastPayDate,
                'amount_expected'     => $syntheticExpected,
                'amount_paid'         => $carryCredit,
                'collection_status'   => $syntheticStatus,
                'payment_channel'     => null,
                'reference_no'        => $isAdvance ? 'Advance credit' : null,
                'running_outstanding' => $running,
                'is_advance'          => $isAdvance,
            ];
        }

        return $displayRows;
    }

    // ── Billing ───────────────────────────────────────────────────────────────

    public function ensureCurrentMonthBilling(int $tenantId, float $expectedRent, string $referenceDate): void
    {
        $pdo     = Database::connection();
        $month   = (new DateTimeImmutable($referenceDate))->format('Y-m-01');
        $dueDate = (new DateTimeImmutable($referenceDate))->format('Y-m-10');

        $stmt = $pdo->prepare(
            'INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date, status)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                expected_rent = VALUES(expected_rent),
                due_date = VALUES(due_date)'
        );
        $stmt->execute([$tenantId, $month, $expectedRent, $dueDate, 'unpaid']);
    }
}
