<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireAuth();

header('Content-Type: application/json');

$unitId = filter_input(INPUT_GET, 'unit_id', FILTER_VALIDATE_INT);
if (!$unitId || $unitId < 1) {
    echo json_encode(null);
    exit;
}

$pdo  = Database::connection();
$stmt = $pdo->prepare(
    "SELECT t.id AS tenant_id, t.name AS tenant_name, l.rent_amount, l.id AS lease_id
     FROM leases l
     JOIN tenants t ON t.id = l.tenant_id
     WHERE l.unit_id = ? AND l.status = 'active'
     LIMIT 1"
);
$stmt->execute([$unitId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($row !== false ? $row : null);
