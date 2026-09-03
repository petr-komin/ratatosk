<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/** 128 bitů náhody = zároveň sdílecí token. */
function new_recording_id(): string
{
    return bin2hex(random_bytes(16));
}

function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../views/layout.php';
}

function share_url(string $id): string
{
    return rtrim(env('APP_URL'), '/') . '/w/' . $id;
}

/**
 * Postgres vrací timestamptz i s offsetem (".. +00"), který DateTimeImmutable
 * převezme a nechá být — bez explicitního převodu by se čas zobrazoval v UTC,
 * i když má appka nastavenou českou zónu. Uloženo je to správně, jen se to
 * musí přepočítat na zobrazení.
 */
function format_dt(?string $ts, string $format = 'j. n. Y H:i'): string
{
    if (!$ts) {
        return '—';
    }

    return (new DateTimeImmutable($ts))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format($format);
}

function format_bytes(?int $bytes): string
{
    if (!$bytes) {
        return '—';
    }
    $units = ['B', 'kB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);

    return round($bytes / 1024 ** $i, 1) . ' ' . $units[$i];
}

function format_duration(?int $ms): string
{
    if (!$ms) {
        return '—';
    }
    $s = intdiv($ms, 1000);

    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

const STATUS_LABELS = [
    'pending'     => 'čeká na upload',
    'uploaded'    => 've frontě na překódování',
    'transcoding' => 'překódovává se',
    'ready'       => 'hotovo',
    'failed'      => 'chyba',
];
