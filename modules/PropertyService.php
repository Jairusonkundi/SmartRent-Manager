<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class PropertyService
{
    public function upsertProperty(string $name, string $location = 'Unspecified'): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO properties (name, location)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = VALUES(name), location = VALUES(location)'
        );
        $stmt->execute([$name, $location]);

        return (int) $pdo->lastInsertId();
    }

    public function upsertUnit(int $propertyId, string $unitNumber, string $status = 'occupied'): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO units (property_id, unit_number, status)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), status = VALUES(status)'
        );
        $stmt->execute([$propertyId, $unitNumber, $status]);

        return (int) $pdo->lastInsertId();
    }
}
