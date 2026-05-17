<?php
/**
 * Nano CMS - admin core.
 *
 * Security foundation for the admin codebase. Loaded by every admin
 * entry point (setup.php, index.php) after bootstrap.php has defined
 * NANO_BOOTSTRAPPED / NANO_CONFIG_PATH / NANO_RATE_LIMIT_PATH.
 *
 * Independent of the frontend by design. The two codebases share only
 * the on-disk file format; no PHP code is shared. Duplication is
 * intentional - removing the admin folder must never break the
 * frontend rendering.
 */

if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

const NANO_ADMIN_VERSION = '1.2.0';
const NANO_ADMIN_SESSION_NAME = 'nano_admin';
const NANO_ADMIN_IDLE_TIMEOUT = 60 * 60;                 // 60 minutes of inactivity
const NANO_ADMIN_RATE_LIMIT_FAILURES = 5;
const NANO_ADMIN_RATE_LIMIT_WINDOW = 60 * 15;            // 15 minutes
const NANO_ADMIN_RATE_LIMIT_BLOCK = 60 * 60;             // 1 hour
const NANO_ADMIN_PASSWORD_MIN = 12;

/* ------------------------------------------------------------------------ */
/* Output escaping (admin's own copy)                                        */
/* ------------------------------------------------------------------------ */

function nano_admin_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render the standard admin-page footer: a credit/support/source link
 * row plus the running version. Called from every admin page just
 * before </body> so any future link-list or version changes are a
 * single edit.
 */
function nano_admin_render_footer(): string
{
    $links = [
        ['Created by Digital Fracture', 'https://digitalfracture.co.uk/index.html'],
        ['Buy Me a Coffee', 'https://buymeacoffee.com/digitalfracture'],
        ['GitHub', 'https://github.com/digifrac/Nano-CMS/tree/main'],
    ];
    $rendered = [];
    foreach ($links as [$label, $url]) {
        $rendered[] = '<a href="' . nano_admin_e($url) . '" target="_blank" rel="noopener">'
            . nano_admin_e($label) . '</a>';
    }
    return '<footer class="admin-footer">'
        . '<p class="admin-links">' . implode(' &middot; ', $rendered) . '</p>'
        . '<p class="admin-version">Nano CMS v' . nano_admin_e(NANO_ADMIN_VERSION) . '</p>'
        . '</footer>';
}

/* ------------------------------------------------------------------------ */
/* HTTPS enforcement                                                         */
/* ------------------------------------------------------------------------ */

function nano_admin_is_https(): bool
{
    $h = $_SERVER['HTTPS'] ?? '';
    if ($h !== '' && strtolower((string)$h) !== 'off') {
        return true;
    }
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return strtolower((string)$proto) === 'https';
}

function nano_admin_assert_https(): void
{
    if (nano_admin_is_https()) {
        return;
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Nano CMS admin requires HTTPS. Please access this URL over https://.";
    exit;
}

/* ------------------------------------------------------------------------ */
/* Session management                                                        */
/* ------------------------------------------------------------------------ */

function nano_admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    session_name(NANO_ADMIN_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0, // browser-session cookie: deleted when the browser closes
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    @session_start();
}

function nano_admin_pw_fingerprint(string $hash): string
{
    return hash('sha256', 'nano-pw-fp|' . $hash);
}

function nano_admin_logged_in(): bool
{
    nano_admin_session_start();

    if (empty($_SESSION['nano_admin_logged_in'])) {
        return false;
    }

    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last === 0 || (time() - $last) > NANO_ADMIN_IDLE_TIMEOUT) {
        nano_admin_logout();
        return false;
    }

    if (!nano_admin_config_exists()) {
        nano_admin_logout();
        return false;
    }
    $cfg = nano_admin_load_config();
    $current_fp = nano_admin_pw_fingerprint((string)($cfg['password_hash'] ?? ''));
    $session_fp = (string)($_SESSION['pw_fp'] ?? '');
    if ($session_fp === '' || !hash_equals($current_fp, $session_fp)) {
        nano_admin_logout();
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

function nano_admin_require_login(): void
{
    if (nano_admin_logged_in()) {
        return;
    }
    header('Location: index.php');
    exit;
}

/* ------------------------------------------------------------------------ */
/* CSRF                                                                      */
/* ------------------------------------------------------------------------ */

function nano_admin_csrf_token(): string
{
    nano_admin_session_start();
    if (empty($_SESSION['nano_csrf_token'])) {
        $_SESSION['nano_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['nano_csrf_token'];
}

function nano_admin_csrf_check(string $supplied): bool
{
    nano_admin_session_start();
    $token = (string)($_SESSION['nano_csrf_token'] ?? '');
    return $token !== '' && hash_equals($token, $supplied);
}

function nano_admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . nano_admin_e(nano_admin_csrf_token()) . '">';
}

function nano_admin_require_csrf(): void
{
    $supplied = (string)($_POST['csrf'] ?? '');
    if (!nano_admin_csrf_check($supplied)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Invalid CSRF token. Please reload and try again.";
        exit;
    }
}

/* ------------------------------------------------------------------------ */
/* Login / logout                                                            */
/* ------------------------------------------------------------------------ */

function nano_admin_login_attempt(string $password, string $ip): array
{
    if (nano_admin_rate_limit_blocked($ip)) {
        return ['ok' => false, 'reason' => 'blocked'];
    }
    $config = nano_admin_load_config();
    $hash = (string)($config['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        nano_admin_rate_limit_record_failure($ip);
        return ['ok' => false, 'reason' => 'invalid'];
    }
    nano_admin_session_start();
    @session_regenerate_id(true);
    $_SESSION['nano_admin_logged_in'] = true;
    $_SESSION['nano_csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['last_activity'] = time();
    $_SESSION['pw_fp'] = nano_admin_pw_fingerprint($hash);
    nano_admin_rate_limit_clear($ip);
    return ['ok' => true];
}

function nano_admin_logout(): void
{
    nano_admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        @setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            $params['secure'] ?? true,
            $params['httponly'] ?? true
        );
    }
    @session_destroy();
}

function nano_admin_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/* ------------------------------------------------------------------------ */
/* Config (independent of frontend's loader)                                 */
/* ------------------------------------------------------------------------ */

function nano_admin_config_exists(): bool
{
    return defined('NANO_CONFIG_PATH') && is_file(NANO_CONFIG_PATH);
}

function nano_admin_load_config(): array
{
    if (!nano_admin_config_exists()) {
        throw new RuntimeException('Nano CMS admin: config.json does not exist; run setup first.');
    }
    $raw = file_get_contents(NANO_CONFIG_PATH);
    if ($raw === false) {
        throw new RuntimeException('Nano CMS admin: failed to read config.json');
    }
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        throw new RuntimeException('Nano CMS admin: config.json is not valid JSON');
    }
    return $cfg;
}

function nano_admin_save_config(array $config): void
{
    $config['admin_version_last_used'] = NANO_ADMIN_VERSION;
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Nano CMS admin: failed to encode config.json');
    }
    nano_admin_atomic_write(NANO_CONFIG_PATH, $json . "\n");
}

function nano_admin_atomic_write(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException("Nano CMS admin: directory does not exist: $dir");
    }
    $tmp = tempnam($dir, '.nano-tmp-');
    if ($tmp === false || file_put_contents($tmp, $contents) === false) {
        if (is_string($tmp)) @unlink($tmp);
        throw new RuntimeException("Nano CMS admin: could not write temp file in $dir");
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Nano CMS admin: could not rename to $path");
    }
    @chmod($path, 0644);
}

/* ------------------------------------------------------------------------ */
/* Version compatibility check                                               */
/* ------------------------------------------------------------------------ */

function nano_admin_version_check(): void
{
    if (!nano_admin_config_exists()) {
        return; // pre-setup; nothing to compare against yet
    }
    $cfg = nano_admin_load_config();
    $last = (string)($cfg['admin_version_last_used'] ?? '0.0.0');
    if (version_compare($last, NANO_ADMIN_VERSION, '>')) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "This site was last edited with admin version $last. "
           . "Upload that version or newer before continuing.";
        exit;
    }
}

/* ------------------------------------------------------------------------ */
/* Rate limiting (state in NANO_RATE_LIMIT_PATH outside webroot)             */
/* ------------------------------------------------------------------------ */

function nano_admin_rate_limit_load(): array
{
    if (!defined('NANO_RATE_LIMIT_PATH') || !is_file(NANO_RATE_LIMIT_PATH)) {
        return [];
    }
    $raw = file_get_contents(NANO_RATE_LIMIT_PATH);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function nano_admin_rate_limit_save(array $state): void
{
    if (!defined('NANO_RATE_LIMIT_PATH')) {
        return;
    }
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    nano_admin_atomic_write(NANO_RATE_LIMIT_PATH, $json . "\n");
}

function nano_admin_rate_limit_prune(array $state, int $now): array
{
    foreach ($state as $ip => $entry) {
        $blocked_until = (int)($entry['blocked_until'] ?? 0);
        if ($blocked_until > $now) {
            continue; // still actively blocked, leave intact
        }
        $failures = $entry['failures'] ?? [];
        $cutoff = $now - NANO_ADMIN_RATE_LIMIT_WINDOW;
        $failures = array_values(array_filter($failures, fn($t) => (int)$t >= $cutoff));
        if (empty($failures)) {
            unset($state[$ip]);
        } else {
            $state[$ip] = ['failures' => $failures, 'blocked_until' => 0];
        }
    }
    return $state;
}

function nano_admin_rate_limit_blocked(string $ip): bool
{
    $now = time();
    $state = nano_admin_rate_limit_prune(nano_admin_rate_limit_load(), $now);
    nano_admin_rate_limit_save($state);
    return (int)($state[$ip]['blocked_until'] ?? 0) > $now;
}

function nano_admin_rate_limit_record_failure(string $ip): void
{
    $now = time();
    $state = nano_admin_rate_limit_prune(nano_admin_rate_limit_load(), $now);
    if (!isset($state[$ip])) {
        $state[$ip] = ['failures' => [], 'blocked_until' => 0];
    }
    $state[$ip]['failures'][] = $now;
    if (count($state[$ip]['failures']) >= NANO_ADMIN_RATE_LIMIT_FAILURES) {
        $state[$ip]['blocked_until'] = $now + NANO_ADMIN_RATE_LIMIT_BLOCK;
        $state[$ip]['failures'] = []; // counter resets once blocked
    }
    nano_admin_rate_limit_save($state);
}

function nano_admin_rate_limit_clear(string $ip): void
{
    $state = nano_admin_rate_limit_load();
    if (!isset($state[$ip])) {
        return;
    }
    unset($state[$ip]);
    nano_admin_rate_limit_save($state);
}
