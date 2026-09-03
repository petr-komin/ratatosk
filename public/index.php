<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

session_start_once();

$method = $_SERVER['REQUEST_METHOD'];
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

/* ------------------------------------------------------------------ routes */

// Sdílecí stránka je jediná veřejná — chrání ji jen neuhádnutelné id.
if (preg_match('#^/w/([0-9a-f]{32})$#', $path, $m)) {
    return route_watch($m[1]);
}
if (preg_match('#^/api/recordings/([0-9a-f]{32})/status$#', $path, $m) && $method === 'GET') {
    return route_status($m[1]);
}

switch (true) {
    case $path === '/' && $method === 'GET':
        return route_index();

    case $path === '/login' && $method === 'GET':
        return route_login_form();
    case $path === '/login' && $method === 'POST':
        return route_login();

    case $path === '/register' && $method === 'GET':
        return route_register_form();
    case $path === '/register' && $method === 'POST':
        return route_register();

    case $path === '/logout' && $method === 'POST':
        csrf_check();
        logout_user();
        header('Location: /login');
        return;

    case $path === '/record' && $method === 'GET':
        return route_record();

    case $path === '/api/recordings' && $method === 'POST':
        return route_create_recording();

    case (bool) preg_match('#^/api/recordings/([0-9a-f]{32})/complete$#', $path, $m) && $method === 'POST':
        return route_complete_recording($m[1]);

    case (bool) preg_match('#^/recordings/([0-9a-f]{32})/delete$#', $path, $m) && $method === 'POST':
        return route_delete_recording($m[1]);
}

http_response_code(404);
render('404', ['title' => 'Nenalezeno']);

/* ---------------------------------------------------------------- handlers */

function route_index(): void
{
    $user = require_login();

    // Interní nástroj pro dva lidi: každý vidí všechno, ale je vidět kdo a kdy.
    $recordings = db()->query(
        'SELECT r.*, u.email AS author
           FROM recordings r
           JOIN users u ON u.id = r.user_id
          WHERE r.status <> \'pending\'
             OR r.created_at > now() - interval \'1 hour\'
          ORDER BY r.created_at DESC
          LIMIT 200'
    )->fetchAll();

    render('index', [
        'title'      => 'Záznamy',
        'user'       => $user,
        'recordings' => $recordings,
    ]);
}

function route_login_form(): void
{
    if (current_user()) {
        header('Location: /');
        return;
    }
    render('login', ['title' => 'Přihlášení', 'error' => null]);
}

function route_login(): void
{
    csrf_check();
    $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (login_is_throttled($email, $ip)) {
        http_response_code(429);
        render('login', [
            'title' => 'Přihlášení',
            'error' => 'Příliš mnoho pokusů o přihlášení. Zkus to prosím za pár minut.',
        ]);
        return;
    }

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        record_login_failure($email, $ip);
        http_response_code(401);
        render('login', ['title' => 'Přihlášení', 'error' => 'Špatný e-mail nebo heslo.']);
        return;
    }

    login_user((int) $row['id']);
    header('Location: /');
}

function route_register_form(): void
{
    render('register', ['title' => 'Registrace', 'error' => null]);
}

function route_register(): void
{
    csrf_check();
    $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $invite   = (string) ($_POST['invite'] ?? '');

    $fail = static function (string $msg): void {
        http_response_code(422);
        render('register', ['title' => 'Registrace', 'error' => $msg]);
    };

    if (!hash_equals(env('INVITE_CODE'), $invite)) {
        $fail('Neplatný zvací kód.');
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fail('Neplatný e-mail.');
        return;
    }
    if (strlen($password) < 10) {
        $fail('Heslo musí mít aspoň 10 znaků.');
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO users (email, password_hash) VALUES (?, ?) RETURNING id'
        );
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        $fail('Takový účet už existuje.');
        return;
    }

    login_user((int) $stmt->fetchColumn());
    header('Location: /');
}

function route_record(): void
{
    $user = require_login();
    render('record', ['title' => 'Nový záznam', 'user' => $user]);
}

/** Založí řádek a vrátí presigned PUT URL — upload jde přímo do R2, mimo server. */
function route_create_recording(): void
{
    $user = require_login_api();
    csrf_check();

    $body  = json_body();
    $title = trim((string) ($body['title'] ?? ''));
    $title = $title === '' ? 'Záznam ' . date('j. n. Y H:i') : mb_substr($title, 0, 200);

    $id  = new_recording_id();
    $key = sprintf('rec/%s/%s/source.webm', date('Y/m'), $id);

    $stmt = db()->prepare(
        'INSERT INTO recordings (id, user_id, title, status, source_key)
         VALUES (?, ?, ?, \'pending\', ?)'
    );
    $stmt->execute([$id, $user['id'], $title, $key]);

    json_response([
        'id'        => $id,
        'uploadUrl' => s3_presign('PUT', $key),
        'shareUrl'  => share_url($id),
    ]);
}

/** Upload doběhl → přepnout na 'uploaded', odtud si to vezme worker. */
function route_complete_recording(string $id): void
{
    $user = require_login_api();
    csrf_check();

    $body = json_body();

    $stmt = db()->prepare(
        'UPDATE recordings
            SET status = \'uploaded\',
                duration_ms = ?,
                size_bytes = ?,
                uploaded_at = now(),
                updated_at = now()
          WHERE id = ? AND user_id = ? AND status = \'pending\''
    );
    $stmt->execute([
        isset($body['durationMs']) ? (int) $body['durationMs'] : null,
        isset($body['sizeBytes']) ? (int) $body['sizeBytes'] : null,
        $id,
        $user['id'],
    ]);

    if ($stmt->rowCount() === 0) {
        json_response(['error' => 'Záznam nenalezen'], 404);
    }

    json_response(['ok' => true, 'shareUrl' => share_url($id)]);
}

function route_delete_recording(string $id): void
{
    $user = require_login();
    csrf_check();

    $stmt = db()->prepare(
        'DELETE FROM recordings WHERE id = ? AND user_id = ? RETURNING source_key, mp4_key'
    );
    $stmt->execute([$id, $user['id']]);

    if ($row = $stmt->fetch()) {
        foreach (array_filter([$row['source_key'], $row['mp4_key']]) as $key) {
            s3_delete($key);
        }
    }

    header('Location: /');
}

/** Veřejná přehrávací stránka. Dokud není MP4, ukazuje „zpracovává se". */
function route_watch(string $id): void
{
    $stmt = db()->prepare(
        'SELECT r.*, u.email AS author
           FROM recordings r JOIN users u ON u.id = r.user_id
          WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    $rec = $stmt->fetch();

    if (!$rec) {
        http_response_code(404);
        render('404', ['title' => 'Nenalezeno']);
        return;
    }

    // Dokud MP4 není (nebo se nepovedlo), má smysl nabídnout původní WebM —
    // čeká se jen kvůli Safari, ostatní prohlížeče ho přehrají rovnou.
    // U 'pending' ne: upload ještě běží, soubor by byl neúplný.
    $sourceUrl = in_array($rec['status'], ['uploaded', 'transcoding', 'failed'], true)
        ? s3_public_url($rec['source_key'])
        : null;

    $viewer = current_user();

    render('watch', [
        'title'         => $rec['title'],
        'rec'           => $rec,
        'user'          => $viewer,
        'videoUrl'      => $rec['mp4_key'] ? s3_public_url($rec['mp4_key']) : null,
        'sourceUrl'     => $sourceUrl,
        'mainClass'     => 'watch-main',
        'showLoginLink' => $viewer === null,
    ]);
}

function route_status(string $id): void
{
    $stmt = db()->prepare('SELECT status, mp4_key FROM recordings WHERE id = ?');
    $stmt->execute([$id]);
    $rec = $stmt->fetch();

    if (!$rec) {
        json_response(['error' => 'not_found'], 404);
    }

    json_response([
        'status'   => $rec['status'],
        'videoUrl' => $rec['mp4_key'] ? s3_public_url($rec['mp4_key']) : null,
    ]);
}
