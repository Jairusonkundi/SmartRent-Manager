<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

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
<body class="login-page glass-login-page">
<main class="glass-login-wrap">
    <form method="post" class="glass-login-card">
        <h1>SmartRent Manager</h1>
        <p class="login-subtitle">Secure finance portal access</p>

        <?php if ($flash): ?>
            <div class="alert error login-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <label for="username">Username</label>
        <input id="username" type="text" name="username" placeholder="Enter your username" autocomplete="username" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>

        <button type="submit">Sign In</button>
    </form>
</main>
</body>
</html>
