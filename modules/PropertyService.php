<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class PropertyService
{
    // ── Read ──────────────────────────────────────────────────────────────────

    public function listProperties(): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->query(
            'SELECT p.id, p.name, p.location,
                    COUNT(u.id) AS unit_count
             FROM properties p
             LEFT JOIN units u ON u.property_id = p.id
             GROUP BY p.id
             ORDER BY p.name'
        );

        return $stmt->fetchAll() ?: [];
    }

    public function listUnitsForProperty(int $propertyId): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT u.id, u.unit_number, u.status,
                    t.name AS tenant_name,
                    l.rent_amount
             FROM units u
             LEFT JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
             LEFT JOIN tenants t ON t.id = l.tenant_id
             WHERE u.property_id = ?
             ORDER BY u.unit_number"
        );
        $stmt->execute([$propertyId]);

        return $stmt->fetchAll() ?: [];
    }

    public function listAllUnits(): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->query(
            "SELECT u.id, u.unit_number, u.status,
                    p.id AS property_id, p.name AS property_name
             FROM units u
             JOIN properties p ON p.id = u.property_id
             ORDER BY p.name, u.unit_number"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function getProperty(int $id): ?array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM properties WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getUnit(int $id): ?array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM units WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function listPropertyOptions(): array
    {
        $pdo = Database::connection();

        return $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll() ?: [];
    }

    public function listPropertiesFiltered(string $search = '', int $page = 1, int $perPage = 15): array
    {
        $pdo    = Database::connection();
        $offset = max(0, ($page - 1) * $perPage);
        $params = [];
        $where  = '';

        if ($search !== '') {
            $where    = 'WHERE (p.name LIKE ? OR p.location LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.location, COUNT(u.id) AS unit_count
             FROM properties p
             LEFT JOIN units u ON u.property_id = p.id
             {$where}
             GROUP BY p.id
             ORDER BY p.name
             LIMIT ? OFFSET ?"
        );
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function countPropertiesFiltered(string $search = ''): int
    {
        $pdo = Database::connection();

        if ($search !== '') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM properties WHERE name LIKE ? OR location LIKE ?'
            );
            $like = '%' . $search . '%';
            $stmt->execute([$like, $like]);
        } else {
            $stmt = $pdo->query('SELECT COUNT(*) FROM properties');
        }

        return (int) $stmt->fetchColumn();
    }

    // ── Upsert (used by import handler) ──────────────────────────────────────

    public function upsertProperty(string $name, string $location = 'Unspecified'): int
    {
        $pdo  = Database::connection();
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
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO units (property_id, unit_number, status)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), status = VALUES(status)'
        );
        $stmt->execute([$propertyId, $unitNumber, $status]);

        return (int) $pdo->lastInsertId();
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function createProperty(string $name, string $location): int
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO properties (name, location) VALUES (?, ?)'
        );
        $stmt->execute([trim($name), trim($location)]);

        return (int) $pdo->lastInsertId();
    }

    public function createUnit(int $propertyId, string $unitNumber, string $status = 'vacant'): int
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO units (property_id, unit_number, status) VALUES (?, ?, ?)'
        );
        $stmt->execute([$propertyId, trim($unitNumber), $status]);

        return (int) $pdo->lastInsertId();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function updateProperty(int $id, string $name, string $location): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE properties SET name = ?, location = ? WHERE id = ?'
        );
        $stmt->execute([trim($name), trim($location), $id]);
    }

    public function updateUnit(int $id, string $unitNumber, string $status): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE units SET unit_number = ?, status = ? WHERE id = ?'
        );
        $stmt->execute([trim($unitNumber), $status, $id]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function deleteProperty(int $id): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM properties WHERE id = ?');

        try {
            $stmt->execute([$id]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \RuntimeException(
                    'Property cannot be deleted while related records still exist. '
                    . 'Remove dependent units/leases first.'
                );
            }

            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Property not found or already deleted.');
        }
    }

    public function deleteUnit(int $id): void
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM units WHERE id = ?');

        try {
            $stmt->execute([$id]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \RuntimeException(
                    'Unit cannot be deleted while related lease records still exist.'
                );
            }

            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Unit not found or already deleted.');
        }
    }
}
