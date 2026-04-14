<?php

declare(strict_types=1);

function renderHeader(string $title): void
{
    $flash = getFlash();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> | SmartRent Manager</title>
    <link rel="stylesheet" href="/public/assets/css/styles.css">
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script defer src="/public/assets/js/app.js"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <h1>SmartRent</h1>
        <nav>
            <a href="/public/dashboard.php">Dashboard</a>
            <a href="/public/tenants.php">Tenants</a>
            <a href="/public/payments.php">Payments</a>
            <a href="/public/budget.php">Budget</a>
            <a href="/public/arrears.php">Arrears</a>
            <a href="/public/reports.php">Reports</a>
            <a href="/public/logout.php">Logout</a>
        </nav>
    </aside>
    <main class="content">
        <header class="topbar"><h2><?= h($title) ?></h2></header>
        <?php if ($flash): ?>
            <div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
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
