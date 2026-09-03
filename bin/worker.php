<?php
declare(strict_types=1);

/**
 * Překódování WebM -> MP4/H.264.
 *
 * Spouští se z cronu, doběhne frontu a skončí. Žádný démon — v klidu tu
 * neběží nic. flock drží concurrency = 1, takže se dva běhy nikdy nepotkají.
 *
 *   docker compose exec -T app php /var/www/html/bin/worker.php
 */

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Jen z CLI.\n");
}

const LOCK_PATH        = '/tmp/ratatosk-worker.lock';
const STUCK_AFTER      = '2 hours';   // po tomhle považujeme běh za spadlý
const MAX_JOBS_PER_RUN = 20;

$lock = fopen(LOCK_PATH, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Předchozí běh ještě pracuje. Nic se neděje, cron to zkusí zas.
    exit(0);
}

function logmsg(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

/* Uklidit po spadlém běhu — jinak by záznam visel v 'transcoding' navždy. */
db()->exec(
    "UPDATE recordings
        SET status = 'uploaded', updated_at = now()
      WHERE status = 'transcoding'
        AND updated_at < now() - interval '" . STUCK_AFTER . "'"
);

$done = 0;
while ($done < MAX_JOBS_PER_RUN && ($rec = claim_next())) {
    try {
        transcode($rec);
        $done++;
    } catch (Throwable $e) {
        logmsg("CHYBA {$rec['id']}: {$e->getMessage()}");
        $stmt = db()->prepare(
            "UPDATE recordings SET status = 'failed', error = ?, updated_at = now() WHERE id = ?"
        );
        $stmt->execute([mb_substr($e->getMessage(), 0, 1000), $rec['id']]);
    }
}

flock($lock, LOCK_UN);
exit(0);

/* ------------------------------------------------------------------------ */

/** Vezme nejstarší nahraný záznam a hned si ho označí, aby ho nikdo nesebral. */
function claim_next(): ?array
{
    $stmt = db()->query(
        "UPDATE recordings
            SET status = 'transcoding', updated_at = now()
          WHERE id = (
                SELECT id FROM recordings
                 WHERE status = 'uploaded'
                 ORDER BY created_at
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
          )
      RETURNING *"
    );

    return $stmt->fetch() ?: null;
}

function transcode(array $rec): void
{
    $id  = $rec['id'];
    $dir = sys_get_temp_dir() . '/ratatosk-' . $id;
    @mkdir($dir, 0700, true);

    $src = "$dir/source.webm";
    $out = "$dir/video.mp4";

    try {
        logmsg("$id: stahuji {$rec['source_key']}");
        s3_download($rec['source_key'], $src);

        logmsg("$id: ffmpeg");
        run_ffmpeg($src, $out);

        $mp4Key = preg_replace('#/source\.webm$#', '/video.mp4', $rec['source_key']);
        logmsg("$id: nahrávám $mp4Key (" . filesize($out) . " B)");
        s3_upload($mp4Key, $out, 'video/mp4');

        $stmt = db()->prepare(
            "UPDATE recordings
                SET status = 'ready', mp4_key = ?, error = NULL,
                    ready_at = now(), updated_at = now()
              WHERE id = ?"
        );
        $stmt->execute([$mp4Key, $id]);

        if (env_bool('DELETE_SOURCE_AFTER_TRANSCODE', false)) {
            s3_delete($rec['source_key']);
        }

        logmsg("$id: hotovo");
    } finally {
        @unlink($src);
        @unlink($out);
        @rmdir($dir);
    }
}

function run_ffmpeg(string $src, string $out): void
{
    $cmd = [
        'ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin',
        '-i', $src,
        '-c:v', 'libx264',
        '-preset', env('FFMPEG_PRESET', 'veryfast'),
        '-crf', env('FFMPEG_CRF', '23'),
        '-pix_fmt', 'yuv420p',
        // liché rozměry H.264 nepobere; screen capture je občas přinese
        '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
        '-c:a', 'aac', '-b:a', env('FFMPEG_AUDIO_BITRATE', '128k'),
        // faststart = moov atom dopředu, aby video šlo přehrát bez stažení celku
        '-movflags', '+faststart',
        '-y', $out,
    ];

    $proc = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('ffmpeg se nepodařilo spustit');
    }

    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $p) {
        fclose($p);
    }
    $code = proc_close($proc);

    if ($code !== 0) {
        throw new RuntimeException('ffmpeg skončil s kódem ' . $code . ': ' . mb_substr(trim($stderr), 0, 500));
    }
    if (!is_file($out) || filesize($out) === 0) {
        throw new RuntimeException('ffmpeg nevyrobil použitelný výstup');
    }
}
