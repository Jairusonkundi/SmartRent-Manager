<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function monthStart(string $month): string
{
    return (new DateTimeImmutable($month))->modify('first day of this month')->format('Y-m-d');
}

function formatKsh(float $amount): string
{
    // Keep a fixed space after the currency code for readable label/value output.
    return 'KSh ' . number_format($amount, 2, '.', ',');
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


function csrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function safeRedirectPath(string $path, string $fallback = '/public/dashboard.php'): string
{
    $parsedPath = parse_url($path, PHP_URL_PATH);
    if (!is_string($parsedPath) || $parsedPath === '') {
        return $fallback;
    }

    $allowedPaths = [
        '/public/dashboard.php',
        '/public/upload_csv.php',
    ];

    if (!in_array($parsedPath, $allowedPaths, true)) {
        return $fallback;
    }

    $query = parse_url($path, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return $parsedPath;
    }

    parse_str($query, $queryParams);
    $safeQueryParams = [];
    foreach (['property_id', 'year', 'view', 'month'] as $allowedQueryParam) {
        if (isset($queryParams[$allowedQueryParam]) && is_scalar($queryParams[$allowedQueryParam])) {
            $safeQueryParams[$allowedQueryParam] = (string) $queryParams[$allowedQueryParam];
        }
    }

    return $parsedPath . ($safeQueryParams !== [] ? '?' . http_build_query($safeQueryParams) : '');
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
