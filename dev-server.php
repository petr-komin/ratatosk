<?php
/**
 * Router pro vestavěný PHP server (`php -S`) — jen pro lokální vývoj.
 *
 * Na produkci tohle nikdy neběží; tam statiku servíruje nginx a index.php
 * dostane request přes FastCGI. Tady musíme obojí obsloužit sami.
 */
declare(strict_types=1);

$docroot = __DIR__ . '/public';
$path    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Existující statický soubor -> ať ho vrátí server sám (vrácení false).
$file = realpath($docroot . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, $docroot . DIRECTORY_SEPARATOR)) {
    return false;
}

require $docroot . '/index.php';
