<?php
/**
 * Render harness (not a test on its own; invoked as a subprocess by
 * 05-admin-render.php). Forces HTTPS on and forges a logged-in admin
 * session against the local dev config, then includes one admin page so
 * its full request lifecycle runs. Prints the rendered HTML to stdout.
 *
 *   php _render_admin.php <page.php>
 *
 * Exit codes: 0 rendered, 2 no local config/bootstrap (caller skips).
 */
declare(strict_types=1);
error_reporting(E_ERROR | E_PARSE); // keep stdout to page HTML + real fatals

$page = (string)($argv[1] ?? '');
$root = dirname(__DIR__, 2);

if (!is_file($root . '/bootstrap.php')) { fwrite(STDERR, "NO_BOOTSTRAP\n"); exit(2); }

$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/admin/' . $page;
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require $root . '/bootstrap.php';
require $root . '/admin/core.php';

if (!nano_admin_config_exists()) { fwrite(STDERR, "NO_CONFIG\n"); exit(2); }

$cfg = nano_admin_load_config();
nano_admin_session_start();
$_SESSION['nano_admin_logged_in'] = true;
$_SESSION['last_activity']         = time();
$_SESSION['pw_fp']                 = nano_admin_pw_fingerprint((string)($cfg['password_hash'] ?? ''));
if (empty($_SESSION['nano_csrf_token'])) {
    $_SESSION['nano_csrf_token'] = bin2hex(random_bytes(32));
}

chdir($root . '/admin');
require $root . '/admin/' . $page;
