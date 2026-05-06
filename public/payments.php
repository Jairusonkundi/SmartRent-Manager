<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

requireAuth();
header('Location: /public/tenant_ledger.php', true, 302);
exit;
