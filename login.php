<?php

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

session_start();

if (isset($_SESSION['finance_manager_id'])) {
    header('Location: dashboard_view.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $pdo = dbConnect();
    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = :username OR full_name = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, (string) $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['finance_manager_id'] = (int) $user['id'];
        $_SESSION['finance_manager_name'] = (string) $user['full_name'];
        $_SESSION['finance_manager_role'] = (string) $user['role'];

        header('Location: dashboard_view.php');
        exit;
    }

    $error = 'Invalid credentials. Please try again.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Manager Login</title>
    <style>
        :root {
            --bg: #f3f6fb;
            --card: #ffffff;
            --text: #1e293b;
            --sub: #64748b;
            --primary: #0f766e;
            --danger-bg: #fee2e2;
            --danger-text: #b91c1c;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, Segoe UI, Roboto, sans-serif;
            color: var(--text);
            background: radial-gradient(circle at top, #e6f4ff 0%, var(--bg) 60%);
            display: grid;
            place-items: center;
            padding: 1rem;
        }
        .card {
            width: min(100%, 420px);
            background: var(--card);
            border-radius: 16px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            padding: 2rem;
            border: 1px solid var(--border);
        }
        h1 { margin: 0 0 .35rem 0; font-size: 1.4rem; }
        p.subtitle { margin: 0 0 1.5rem 0; color: var(--sub); }
        label { display:block; font-weight: 600; margin-bottom: .4rem; }
        input {
            width: 100%;
            margin: 0 0 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: .75rem .9rem;
            font-size: .95rem;
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(15, 118, 110, .15); }
        .btn {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: .85rem;
            font-size: 1rem;
            color: #fff;
            background: var(--primary);
            cursor: pointer;
            font-weight: 700;
        }
        .alert {
            margin-bottom: 1rem;
            padding: .75rem .85rem;
            border-radius: 10px;
            border: 1px solid #fecaca;
            color: var(--danger-text);
            background: var(--danger-bg);
            font-size: .92rem;
        }
    </style>
</head>
<body>
    <form method="post" class="card" novalidate>
        <h1>Finance Manager Portal</h1>
        <p class="subtitle">Sign in to manage collections, budgets, and tenant payments.</p>

        <?php if ($error !== null): ?>
            <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <label for="username">Username</label>
        <input id="username" name="username" placeholder="Enter your username" required autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" placeholder="Enter your password" required autocomplete="current-password">

        <button class="btn" type="submit">Log In</button>
    </form>
</body>
</html>
