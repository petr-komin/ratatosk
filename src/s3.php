<?php
declare(strict_types=1);

/**
 * Presigned URL pro S3-kompatibilní úložiště (Cloudflare R2), SigV4, path-style.
 *
 * Ručně, bez aws-sdk-php: potřebujeme jen podepsat URL, tahat kvůli tomu
 * desítky megabajtů vendoru nedává smysl.
 *
 * Podepisujeme jen hlavičku `host`, takže prohlížeč smí k PUT přidat vlastní
 * Content-Type, aniž by podpis rozbil.
 */

function s3_uri_encode_key(string $key): string
{
    // rawurlencode celý klíč, ale lomítka nechat jako oddělovače segmentů
    return implode('/', array_map('rawurlencode', explode('/', $key)));
}

function s3_presign(string $method, string $key, ?int $expires = null): string
{
    $endpoint = rtrim(env('R2_ENDPOINT'), '/');
    $bucket   = env('R2_BUCKET');
    $region   = env('R2_REGION', 'auto');
    $access   = env('R2_ACCESS_KEY_ID');
    $secret   = env('R2_SECRET_ACCESS_KEY');
    $expires ??= env_int('UPLOAD_URL_TTL', 3600);

    $host = parse_url($endpoint, PHP_URL_HOST);
    if (!is_string($host)) {
        throw new RuntimeException('R2_ENDPOINT není platná URL');
    }
    $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';

    $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $amzDate   = $now->format('Ymd\THis\Z');
    $dateStamp = $now->format('Ymd');

    $scope         = "$dateStamp/$region/s3/aws4_request";
    $canonicalUri  = '/' . rawurlencode($bucket) . '/' . s3_uri_encode_key($key);

    $query = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "$access/$scope",
        'X-Amz-Date'          => $amzDate,
        'X-Amz-Expires'       => (string) $expires,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($query);
    $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $canonicalRequest = implode("\n", [
        strtoupper($method),
        $canonicalUri,
        $canonicalQuery,
        "host:$host\n",
        'host',
        'UNSIGNED-PAYLOAD',
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return "$scheme://$host$canonicalUri?$canonicalQuery&X-Amz-Signature=$signature";
}

/** Veřejná URL hotového MP4 (bucket napojený na vlastní doménu). */
function s3_public_url(string $key): string
{
    return rtrim(env('R2_PUBLIC_BASE_URL'), '/') . '/' . s3_uri_encode_key($key);
}

/** ------------------------------------------------- přenosy přes presigned URL */

function s3_download(string $key, string $destPath): void
{
    $fh = fopen($destPath, 'wb');
    if (!$fh) {
        throw new RuntimeException("Nelze zapsat $destPath");
    }

    $ch = curl_init(s3_presign('GET', $key, 3600));
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_TIMEOUT        => 1800,
    ]);
    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($ok === false) {
        throw new RuntimeException("Stažení $key selhalo: $err");
    }
}

function s3_upload(string $key, string $srcPath, string $contentType): void
{
    $fh = fopen($srcPath, 'rb');
    if (!$fh) {
        throw new RuntimeException("Nelze číst $srcPath");
    }

    $ch = curl_init(s3_presign('PUT', $key, 3600));
    curl_setopt_array($ch, [
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => filesize($srcPath),
        CURLOPT_HTTPHEADER     => ["Content-Type: $contentType"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_TIMEOUT        => 1800,
    ]);
    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($ok === false) {
        throw new RuntimeException("Upload $key selhal: $err");
    }
}

/** Best-effort — když se úklid nepovede, není to důvod nic shodit. */
function s3_delete(string $key): bool
{
    $ch = curl_init(s3_presign('DELETE', $key, 300));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}
