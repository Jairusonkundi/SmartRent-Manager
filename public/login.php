<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        header('Location: /public/dashboard.php');
        exit;
    }

    flash('error', 'Invalid credentials. Please try again.');
    header('Location: /public/login.php');
    exit;
}

$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SmartRent Manager</title>
    <link rel="stylesheet" href="/public/assets/css/styles.css">
</head>
<body class="login-page">
<main class="login-layout">
    <section class="login-brand-panel" aria-hidden="true">
        <div class="brand-content">
            <p class="brand-eyebrow">SmartRent Manager</p>
            <h1>Property Insights, Simplified.</h1>
            <p>Track collections, monitor portfolio performance, and stay ahead with a finance-first dashboard.</p>
        </div>
    </section>

    <section class="login-form-panel">
        <form method="post" class="card login-card">
            <h2>Welcome Back</h2>
            <p class="login-subtitle">Sign in to continue to your manager workspace.</p>
            <?php if ($flash): ?><div class="alert error"><?= h($flash['message']) ?></div><?php endif; ?>

            <label>Username
                <input type="text" name="username" placeholder="Enter your username" autocomplete="username" required>
            </label>

            <label>Password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>

            <button type="submit">Sign In</button>
        </form>
    </section>
</main>
</body>
</html>
