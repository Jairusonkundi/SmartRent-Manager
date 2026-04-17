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

function formatKsh(float $amount): string
{
    // Keep a fixed space after the currency code for readable label/value output.
    return 'KSH ' . number_format($amount, 0, '.', ',');
}

function formatCurrency(float $amount): string
{
    // Backward-compatible alias for existing callers.
    return formatKsh($amount);
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

function getPaginationState(array $allowedLimits = [5, 10, 15, 20], int $defaultLimit = 10): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = (int) ($_GET['limit'] ?? $defaultLimit);

    if (!in_array($limit, $allowedLimits, true)) {
        $limit = $defaultLimit;
    }

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => ($page - 1) * $limit,
    ];
}

function renderPaginationLinks(int $totalRecords, int $page, int $limit, array $persistedParams = []): string
{
    $totalPages = (int) ceil($totalRecords / max(1, $limit));

    if ($totalPages <= 1) {
        return '';
    }

    $buildLink = static function (int $targetPage) use ($persistedParams): string {
        $params = array_merge($persistedParams, ['page' => $targetPage]);

        return '?' . http_build_query($params);
    };

    $html = '<nav class="pagination" aria-label="Pagination"><ul>';

    if ($page > 1) {
        $html .= '<li><a href="' . h($buildLink($page - 1)) . '">Previous</a></li>';
    }

    for ($i = 1; $i <= $totalPages; $i++) {
        $activeClass = $i === $page ? ' class="active"' : '';
        $html .= '<li' . $activeClass . '><a href="' . h($buildLink($i)) . '">' . $i . '</a></li>';
    }

    if ($page < $totalPages) {
        $html .= '<li><a href="' . h($buildLink($page + 1)) . '">Next</a></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}
