<?php
declare(strict_types=1);

/** Nasype schéma do Postgresu. Idempotentní, pouštěj klidně opakovaně. */

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Jen z CLI.\n");
}

$sql = file_get_contents(__DIR__ . '/../db/schema.sql');
db()->exec($sql);

echo "Schéma je aktuální.\n";
