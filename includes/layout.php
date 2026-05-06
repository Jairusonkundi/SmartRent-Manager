<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function renderHeader(string $title): void
{
    $flash = function_exists('getFlash') ? getFlash() : null;
    $currentPage = basename(parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; form-action 'self'">
    <title><?= h($title) ?> | SmartRent Manager</title>
    <link rel="stylesheet" href="/public/assets/css/styles.css">
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script defer src="/public/assets/js/app.js"></script>
    <script defer src="/public/assets/js/upload.js"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <h1>SmartRent</h1>
        <nav>
            <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="/public/dashboard.php">Dashboard</a>
            <a class="<?= in_array($currentPage, ['tenant_ledger.php', 'tenants.php', 'payments.php'], true) ? 'active' : '' ?>" href="/public/tenant_ledger.php">Tenant Ledger</a>
            <a class="<?= $currentPage === 'budget.php' ? 'active' : '' ?>" href="/public/budget.php">Budget</a>
            <a class="<?= $currentPage === 'arrears.php' ? 'active' : '' ?>" href="/public/arrears.php">Arrears</a>
            <a class="<?= $currentPage === 'reports.php' ? 'active' : '' ?>" href="/public/reports.php">Reports</a>
            <a href="/public/logout.php">Logout</a>
        </nav>
    </aside>
    <main class="content">
        <header class="topbar"><h2><?= h($title) ?></h2></header>
        <?php if ($flash !== null && isset($flash['type'], $flash['message'])): ?>
            <div class="alert <?= h((string) $flash['type']) ?>"><?= h((string) $flash['message']) ?></div>
        <?php endif; ?>
<?php
}

function renderFooter(): void
{
    ?>
    </main>
</div>
</body>
</html>
<?php
}
