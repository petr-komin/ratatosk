<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        env('DB_HOST'),
        env_int('DB_PORT', 5432),
        env('DB_NAME')
    );

    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Ať i to, co vrátí databáze, mluví v naší zóně — jinak se člověk při
    // ručním dotazu diví, proč jsou časy o dvě hodiny jinde.
    $pdo->exec("SET TIME ZONE '" . str_replace("'", '', env('APP_TIMEZONE', 'Europe/Prague')) . "'");

    return $pdo;
}
