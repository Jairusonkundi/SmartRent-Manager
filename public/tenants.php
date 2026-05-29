<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

requireAuth();
header('Location: /public/manage_tenants.php', true, 302);
exit;
