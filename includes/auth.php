<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function startSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function requireAuth(): void
{
    startSession();

    if (!isset($_SESSION['user_id'])) {
        header('Location: /public/login.php');
        exit;
    }
}

function login(string $email, string $password): bool
{
    startSession();
    $pdo = Database::connection();

    $stmt = $pdo->prepare('SELECT id, full_name, password_hash, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => trim($email)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];

    return true;
}

function logout(): void
{
    startSession();
    $_SESSION = [];
    session_destroy();
}
