<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../modules/PropertyService.php';

requireAuth();

header('Content-Type: application/json');

$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT);
if (!$propertyId || $propertyId < 1) {
    echo json_encode([]);
    exit;
}

$service = new PropertyService();
echo json_encode($service->listUnitsForProperty($propertyId));
