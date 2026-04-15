<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (!function_exists('formatKsh')) {
    function formatKsh(float $amount): string
    {
        return 'KSH ' . number_format($amount, 0);
    }
}

if (!function_exists('dbConnection')) {
    function dbConnection(): PDO
    {
        return Database::connection();
    }
}
