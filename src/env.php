<?php
declare(strict_types=1);

/**
 * Minimalistický .env loader. Žádná závislost, žádný composer.
 *
 * Soubor je pohodlí pro CLI a vývoj, ne jediný zdroj pravdy. V kontejneru
 * ho compose načte přes `env_file` do prostředí procesu, takže .env může
 * zůstat s právy 600 pro vlastníka — php-fpm workeři běží pod www-data
 * a na soubor by nedosáhli.
 */
function env_load(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    if (!is_readable($path)) {
        // Nevadí, pokud konfigurace dorazila prostředím. Když ne, ozve se
        // env() u první chybějící položky, a to konkrétněji než tenhle soubor.
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        // uvozovky jsou volitelné
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $_ENV[$key] = $val;
    }
}

function env(string $key, ?string $default = null): string
{
    // variables_order nemusí do $_ENV prostředí vůbec pustit, proto getenv().
    $val = $_ENV[$key] ?? null;
    if ($val === null) {
        $fromEnv = getenv($key);
        $val = $fromEnv === false ? $default : $fromEnv;
    }

    if ($val === null) {
        throw new RuntimeException(
            "Chybí povinná položka konfigurace: $key "
            . '(zkontroluj .env, případně env_file v docker-compose.yml)'
        );
    }

    return $val;
}

function env_int(string $key, ?int $default = null): int
{
    return (int) env($key, $default === null ? null : (string) $default);
}

function env_bool(string $key, bool $default = false): bool
{
    $val = strtolower(env($key, $default ? '1' : '0'));
    return in_array($val, ['1', 'true', 'yes', 'on'], true);
}
