<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function monthStart(string $month): string
{
    return (new DateTimeImmutable($month))->modify('first day of this month')->format('Y-m-d');
}

function formatCurrency(float $amount): string
{
    return 'KSH ' . number_format($amount, 0, '.', ',');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}
