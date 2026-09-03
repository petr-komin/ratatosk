<?php
declare(strict_types=1);

/**
 * Připraví R2 bucket: vytvoří ho, když chybí, a nastaví CORS pro upload
 * z prohlížeče. Používá klíče z .env, takže na tohle není potřeba dashboard.
 *
 *   docker compose exec -T app php bin/r2-setup.php [další-origin ...]
 *
 * Co skript NEUMÍ: zapnout veřejný přístup k bucketu. To jde jen přes
 * Cloudflare dashboard nebo Cloudflare API token — S3 klíče na to nestačí.
 */

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Jen z CLI.\n");
}

$bucket = env('R2_BUCKET');
$path   = '/' . rawurlencode($bucket);

$origins = array_values(array_unique(array_filter(array_merge(
    [rtrim(env('APP_URL', ''), '/')],
    array_slice($argv, 1)
))));

if (!$origins) {
    exit("V .env chybí APP_URL a nezadal jsi žádný origin — CORS by neměl co povolit.\n");
}

echo "Bucket:  $bucket\n";
echo "Endpoint: ", s3_endpoint(), "\n\n";

/* --------------------------------------------------------- 1. bucket */

$head = s3_request('HEAD', $path);

if ($head['status'] === 200) {
    echo "[1/3] Bucket už existuje.\n";
} elseif ($head['status'] === 404) {
    $create = s3_request('PUT', $path);
    if ($create['status'] !== 200) {
        fwrite(STDERR, "Bucket se nepodařilo vytvořit (HTTP {$create['status']}):\n{$create['body']}\n");
        exit(1);
    }
    echo "[1/3] Bucket vytvořen.\n";
} else {
    fwrite(STDERR, "Bucket nelze ověřit (HTTP {$head['status']}).\n");
    fwrite(STDERR, $head['status'] === 403
        ? "403 obvykle znamená, že token nemá na tenhle bucket práva — zkontroluj jeho rozsah.\n"
        : $head['body'] . "\n");
    exit(1);
}

/* ----------------------------------------------------------- 2. CORS */

$rules = '';
foreach ($origins as $origin) {
    $rules .= '<AllowedOrigin>' . htmlspecialchars($origin, ENT_XML1) . '</AllowedOrigin>';
}

// Podepisuje se i tělo, takže XML musí jít na drát přesně takhle.
$cors = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
      . '<CORSRule>'
      . $rules
      . '<AllowedMethod>PUT</AllowedMethod>'
      . '<AllowedHeader>content-type</AllowedHeader>'
      . '<ExposeHeader>ETag</ExposeHeader>'
      . '<MaxAgeSeconds>3600</MaxAgeSeconds>'
      . '</CORSRule>'
      . '</CORSConfiguration>';

$put = s3_request('PUT', $path, 'cors=', $cors, [
    'content-type' => 'application/xml',
    'content-md5'  => base64_encode(md5($cors, true)),
]);

if ($put['status'] < 200 || $put['status'] >= 300) {
    fwrite(STDERR, "CORS se nepodařilo nastavit (HTTP {$put['status']}):\n{$put['body']}\n");
    exit(1);
}
echo "[2/3] CORS nastaven pro: ", implode(', ', $origins), "\n";

/* --------------------------------------------------------- 3. ověření */

$get = s3_request('GET', $path, 'cors=');
if ($get['status'] === 200 && str_contains($get['body'], $origins[0])) {
    echo "[3/3] Ověřeno — R2 pravidlo vrací zpět.\n";
} else {
    echo "[3/3] Pozor: čtení CORS vrátilo HTTP {$get['status']}, pravidlo neumím potvrdit.\n";
}

echo "\nZbývá jediná věc, na kterou S3 klíče nestačí:\n";
echo "  Cloudflare dashboard -> R2 -> $bucket -> Settings -> Public access\n";
echo "  zapni \"Public Development URL\" (dostaneš adresu https://pub-....r2.dev)\n";
echo "  a tu vlož do .env jako R2_PUBLIC_BASE_URL.\n";
