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
    // Keep a fixed space after the currency code for readable label/value output.
    return 'KSH ' . number_format($amount, 0, '.', ',');
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash(string $type, string $message): void
{
    // Backward-compatible alias for existing callers.
    setFlash($type, $message);
}

function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : null;
    }

    return null;
}
