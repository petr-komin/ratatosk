<?php
declare(strict_types=1);

require __DIR__ . '/env.php';
env_load(__DIR__ . '/../.env');

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/s3.php';
require __DIR__ . '/auth.php';

date_default_timezone_set(env('APP_TIMEZONE', 'Europe/Prague'));
