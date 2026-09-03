<?php
declare(strict_types=1);

function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => env_bool('SESSION_SECURE', true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ratatosk');
    session_start();
}

function current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, email, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();

    return $user = ($row ?: null);
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: /login');
        exit;
    }
    return $user;
}

function require_login_api(): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['error' => 'Nepřihlášen'], 401);
    }
    return $user;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}

/** ---------------------------------------------------- throttling přihlášení
 *
 * Žádná fronta ani cache navíc — appka i tak jede přes Postgres, tak i
 * tohle. Počítá se v klouzavém okně přes obyčejný COUNT, staré řádky se
 * příležitostně mažou (viz record_login_failure), ať tabulka neroste
 * bez omezení ani pod útokem.
 *
 * Pozor: identifikátor IP bere $_SERVER['REMOTE_ADDR'], což je adresa,
 * kterou vidí nginx. Za obráceným proxy (např. Cloudflare v proxy
 * režimu) by tu byla adresa proxy, ne klienta — pak by šlo o throttling
 * podle e-mailu, ne podle IP.
 */
const LOGIN_MAX_PER_EMAIL = 8;
const LOGIN_MAX_PER_IP    = 20;
const LOGIN_WINDOW        = '15 minutes';

function login_is_throttled(string $email, string $ip): bool
{
    $stmt = db()->prepare(
        "SELECT
            (SELECT count(*) FROM login_attempts
              WHERE identifier = ? AND attempted_at > now() - interval '" . LOGIN_WINDOW . "') AS by_email,
            (SELECT count(*) FROM login_attempts
              WHERE identifier = ? AND attempted_at > now() - interval '" . LOGIN_WINDOW . "') AS by_ip"
    );
    $stmt->execute(['email:' . $email, 'ip:' . $ip]);
    $row = $stmt->fetch();

    return (int) $row['by_email'] >= LOGIN_MAX_PER_EMAIL
        || (int) $row['by_ip'] >= LOGIN_MAX_PER_IP;
}

function record_login_failure(string $email, string $ip): void
{
    db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?), (?)')
        ->execute(['email:' . $email, 'ip:' . $ip]);

    // Náhodou, ať na to není potřeba zvláštní cron — postačí, když se
    // tabulka čas od času uklidí sama.
    if (random_int(1, 50) === 1) {
        db()->exec("DELETE FROM login_attempts WHERE attempted_at < now() - interval '1 day'");
    }
}

/** ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Vypršela relace, načti stránku znovu.');
    }
}
