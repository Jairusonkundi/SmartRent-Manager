<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/TenantService.php';

requireAuth();
header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

try {
    echo json_encode((new TenantService())->searchTenants($q), JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    // Never let a DB error reach the browser as HTML — the JS fetch would fail to parse
    // it and show "Error loading results". Return an empty array with a debug hint instead.
    echo json_encode(['_error' => $e->getMessage()]);
}
