<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?: '';
    $password = $_POST['password'] ?? '';

    if (login($email, $password)) {
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
<form method="post" class="card login-card">
    <h1>SmartRent Manager</h1>
    <p>Finance Manager Login</p>
    <?php if ($flash): ?><div class="alert error"><?= h($flash['message']) ?></div><?php endif; ?>
    <label>Email <input type="email" name="email" required></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Sign In</button>
</form>
</body>
</html>
