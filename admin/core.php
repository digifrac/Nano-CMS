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

/**
 * Project version. Tracked alongside the frontend's NANO_VERSION:
 * both constants carry the same value because they belong to the same
 * release, even though the two codebases ship as separate zips.
 * When bumping for a release, edit both.
 */
const NANO_ADMIN_VERSION = '1.7.0';
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
        $rendered[] = '<a href="' . nano_admin_e($url) . '" target="_blank" rel="noopener noreferrer">'
            . nano_admin_e($label) . '</a>';
    }
    return '</main>'
        . '<footer class="nano-cms-admin-footer">'
        . '<p>Nano CMS v' . nano_admin_e(NANO_ADMIN_VERSION) . ' admin. Remove this folder when done editing.</p>'
        . '<p>' . implode(' &middot; ', $rendered) . '</p>'
        . '</footer>'
        . '</body></html>';
}

/**
 * Open an admin page: doctype, head (admin.css cache-busted by version),
 * <body class="nano-cms-admin">, the sticky header (brand + nav + logout)
 * and the page-title bar, then an open <main>. Paired with
 * nano_admin_render_footer(), which closes </main></body></html>.
 *
 * One canonical nav rendered identically on every page; the current page
 * is highlighted via $current_nav (key match) rather than removed, so the
 * bar never changes shape between pages.
 *
 * $show_chrome = false drops the nav header and page-title (used by the
 * login and setup screens, which are reached before / without a session);
 * pass an extra body class such as 'nano-cms-admin-login' to narrow the
 * column.
 */
/**
 * Inline monoline SVG icon for a nav item, keyed by nav slug. Kept inline
 * (no sprite or image file) so the admin stays a self-contained upload with
 * no extra asset requests. Unknown keys fall back to a neutral dot.
 */
function nano_admin_nav_icon(string $key): string
{
    $paths = [
        'dashboard'  => '<rect x="3.5" y="3.5" width="7" height="7"/><rect x="13.5" y="3.5" width="7" height="7"/><rect x="3.5" y="13.5" width="7" height="7"/><rect x="13.5" y="13.5" width="7" height="7"/>',
        'posts'      => '<path d="M6 3.5h9l4 4V20.5H6z"/><path d="M15 3.5V8h4"/><path d="M9 12.5h7M9 16h7"/>',
        'products'   => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5"/><path d="M12 12v9"/>',
        'categories' => '<path d="M3.5 8.5 12 4l8.5 4.5L12 13z"/><path d="M3.5 13.5 12 18l8.5-4.5"/>',
        'media'      => '<rect x="3.5" y="4.5" width="17" height="15"/><circle cx="9" cy="10" r="1.7"/><path d="M20.5 15.5 15 10 4 19.5"/>',
        'settings'   => '<path d="M4 8h9M17 8h3"/><circle cx="15" cy="8" r="2"/><path d="M4 16h3M11 16h9"/><circle cx="9" cy="16" r="2"/>',
        'licence'    => '<path d="M12 3.5 19 6v6c0 4.3-3 7-7 8.5-4-1.5-7-4.2-7-8.5V6z"/><path d="M9 12l2 2 4-4"/>',
        'help'       => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.4 2.4 0 1 1 3.3 2.3c-.8.4-1.4.9-1.4 1.9"/><path d="M12 16.6h.01"/>',
    ];
    $inner = $paths[$key] ?? '<circle cx="12" cy="12" r="3.2"/>';
    return '<svg class="nano-cms-admin-nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

function nano_admin_header(
    string $page_title,
    string $current_nav = '',
    bool $show_chrome = true,
    string $extra_body_class = ''
): string {
    $title = nano_admin_e($page_title . ' - Nano CMS admin');
    $css   = 'assets/admin.css?v=' . rawurlencode(NANO_ADMIN_VERSION);
    $body  = 'nano-cms-admin' . ($extra_body_class !== '' ? ' ' . $extra_body_class : '');

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . $title . '</title>'
        . '<link rel="stylesheet" href="' . nano_admin_e($css) . '">'
        . '</head><body class="' . nano_admin_e($body) . '">';

    if ($show_chrome) {
        $items = [
            'dashboard'  => ['Dashboard',  'dashboard.php'],
            'posts'      => ['Posts',      'index.php'],
            'categories' => ['Categories', 'categories.php'],
            'media'      => ['Media',      'media.php'],
            'settings'   => ['Settings',   'settings.php'],
            'licence'    => ['Licence',    'licence.php'],
            'help'       => ['Help',       'help.php'],
        ];
        $nav = '';
        foreach ($items as $key => [$label, $url]) {
            $cls = 'nano-cms-admin-nav-link' . ($key === $current_nav ? ' nano-cms-admin-nav-current' : '');
            $nav .= '<a class="' . $cls . '" href="' . nano_admin_e($url) . '"'
                . ($key === $current_nav ? ' aria-current="page"' : '') . '>'
                . nano_admin_nav_icon($key)
                . '<span class="nano-cms-admin-nav-label">' . nano_admin_e($label) . '</span></a>';
        }
        $html .= '<header class="nano-cms-admin-header">'
            . '<a class="nano-cms-admin-brand" href="dashboard.php"><span class="nano-cms-admin-brand-mark" aria-hidden="true"></span>Nano <span class="nano-cms-admin-brand-tag">CMS</span></a>'
            . '<input type="checkbox" id="nano-cms-admin-navtoggle" class="nano-cms-admin-navtoggle" aria-label="Toggle menu">'
            . '<label class="nano-cms-admin-navtoggle-btn" for="nano-cms-admin-navtoggle" aria-hidden="true"><span class="nano-cms-admin-navtoggle-bars"></span></label>'
            . '<label class="nano-cms-admin-navbackdrop" for="nano-cms-admin-navtoggle" aria-hidden="true"></label>'
            . '<nav class="nano-cms-admin-nav">' . $nav . nano_admin_logout_form() . '</nav>'
            . '</header>';
    }

    $html .= '<main class="nano-cms-admin-main">';
    if ($show_chrome && $page_title !== '') {
        $html .= '<h1 class="nano-cms-admin-page-title">' . nano_admin_e($page_title) . '</h1>';
    }
    return $html;
}

/**
 * Render a flash/notice block. $type is 'ok'/'success', 'error', or
 * 'warn'/'warning'. Text is escaped; for messages that need markup (e.g.
 * a list of validation errors) build the block inline using the same
 * classes.
 */
function nano_admin_flash(string $type, string $message): string
{
    $cls = match ($type) {
        'error'           => 'nano-cms-admin-flash-error',
        'warn', 'warning' => 'nano-cms-admin-flash-warning',
        default           => 'nano-cms-admin-flash-success',
    };
    return '<div class="nano-cms-admin-flash ' . $cls . '">' . nano_admin_e($message) . '</div>';
}

/**
 * Installation health checks, surfaced on the admin dashboard. Catches a
 * half-finished upgrade (a file that did not upload, a missing extension,
 * an unwritable media dir) here in the admin, instead of via a dead public
 * page. Mirrors the Nano Cart dashboard health panel.
 *
 * @return list<array{label:string, ok:bool, detail:string}>
 */
function nano_admin_health_checks(): array
{
    $root = defined('NANO_CONTENT_PATH') ? NANO_CONTENT_PATH : dirname(__DIR__);
    $checks = [];

    $php_ok = version_compare(PHP_VERSION, '8.0', '>=');
    $checks[] = ['label' => 'PHP version', 'ok' => $php_ok,
        'detail' => $php_ok ? PHP_VERSION : PHP_VERSION . ' - 8.0 or newer required'];

    $gd = extension_loaded('gd');
    $imagick = extension_loaded('imagick');
    $reenc = $gd || $imagick;
    $which = trim(($gd ? 'GD' : '') . ($gd && $imagick ? ' + ' : '') . ($imagick ? 'Imagick' : ''));
    $checks[] = ['label' => 'Image re-encoder (GD or Imagick)', 'ok' => $reenc,
        'detail' => $reenc ? $which . ' available' : 'missing - media uploads will be refused'];

    $finfo = extension_loaded('fileinfo');
    $checks[] = ['label' => 'fileinfo extension', 'ok' => $finfo,
        'detail' => $finfo ? 'loaded' : 'missing - upload MIME validation cannot run'];

    $required = [
        'core.php', 'index.php', 'post.php', 'template.php',
        'generators.php', 'licence.php', 'lib/Parsedown.php', '.htaccess',
    ];
    $missing = [];
    foreach ($required as $f) {
        if (!is_file($root . '/' . $f)) {
            $missing[] = $f;
        }
    }
    $checks[] = ['label' => 'Required front-end files', 'ok' => empty($missing),
        'detail' => empty($missing) ? 'all present' : 'missing: ' . implode(', ', $missing)];

    $cfg_ok = defined('NANO_CONFIG_PATH') && is_file(NANO_CONFIG_PATH)
        && is_array(json_decode((string)@file_get_contents(NANO_CONFIG_PATH), true));
    $checks[] = ['label' => 'Configuration', 'ok' => $cfg_ok,
        'detail' => $cfg_ok ? 'config.json loads' : 'config.json missing or invalid'];

    $media = $root . '/media';
    $media_target = is_dir($media) ? $media : dirname($media);
    $media_ok = is_writable($media_target);
    $checks[] = ['label' => 'Media folder writable', 'ok' => $media_ok,
        'detail' => $media_ok ? 'writable' : 'not writable - uploads will fail'];

    return $checks;
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
    // HTTP_X_FORWARDED_PROTO is client-controlled unless a reverse proxy
    // strips/overwrites it. Only honour it when the operator has opted in
    // by defining NANO_TRUST_PROXY in bootstrap.php (or the site is behind
    // a Cloudflare/Apache/Nginx layer that they trust).
    if (defined('NANO_TRUST_PROXY') && NANO_TRUST_PROXY) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        return strtolower((string)$proto) === 'https';
    }
    return false;
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

/**
 * Render the "Sign out" control as a CSRF-protected POST form, styled
 * inline to read as a link inside the admin nav bar. POST + CSRF stops
 * cross-site GETs (e.g. <img src="...?action=logout">) from logging
 * the operator out.
 */
function nano_admin_logout_form(): string
{
    return '<form method="post" action="index.php?action=logout" class="nano-cms-admin-logout-form">'
         . nano_admin_csrf_field()
         . '<button type="submit" class="nano-cms-admin-logout">Sign out</button>'
         . '</form>';
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
    // 0600: config.json contains password_hash + licence_key. On shared
    // hosting, 0644 lets other tenants read the bcrypt hash and offline-
    // crack it. The file is outside webroot (HTTP-unreachable) but local
    // tenants share the filesystem.
    nano_admin_atomic_write(NANO_CONFIG_PATH, $json . "\n", 0600);
}

function nano_admin_atomic_write(string $path, string $contents, int $mode = 0644): void
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
    @chmod($path, $mode);
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
    // 0600: same shared-hosting concern as config.json - failed-login state
    // shouldn't be readable by other tenants.
    nano_admin_atomic_write(NANO_RATE_LIMIT_PATH, $json . "\n", 0600);
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
