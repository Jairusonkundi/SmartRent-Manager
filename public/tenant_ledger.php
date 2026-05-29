<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

requireAuth();

$tenantId = filter_input(INPUT_GET, 'tenant_id', FILTER_VALIDATE_INT);
$year     = filter_input(INPUT_GET, 'year',      FILTER_VALIDATE_INT);

$params = [];
if ($tenantId) { $params['ledger_tenant'] = $tenantId; }
if ($year)     { $params['ledger_year']   = $year; }

$qs = $params !== [] ? '?' . http_build_query($params) : '';
header('Location: /public/manage_tenants.php' . $qs, true, 302);
exit;
