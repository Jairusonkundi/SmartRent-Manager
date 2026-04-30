<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class TenantService
{
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

    public function ensureCurrentMonthBilling(int $tenantId, float $expectedRent, string $referenceDate): void
    {
        $pdo = Database::connection();

        $month = (new DateTimeImmutable($referenceDate))->format('Y-m-01');
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
