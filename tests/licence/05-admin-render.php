<?php
/**
 * Renders every authenticated admin page through its real request
 * lifecycle (HTTPS gate + forged login session, via _render_admin.php in
 * a subprocess) and asserts each one comes back as a complete page with
 * the shared chrome and no fatal error.
 *
 * Closes the "couldn't click through the admin locally" verification gap:
 * the admin refuses plain HTTP and needs a session, so a plain dev server
 * can't reach these pages - this drives them directly instead.
 *
 * Skips cleanly when there is no local bootstrap.php/config.json (e.g. a
 * fresh CI checkout), so it never fails for lack of a dev environment.
 */
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$repo    = dirname(__DIR__, 2);
$harness = __DIR__ . '/_render_admin.php';

nano_section('authenticated admin pages render end-to-end');

$probe = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($harness), escapeshellarg('index.php')));
if ($probe === null || str_contains((string)$probe, 'NO_BOOTSTRAP') || str_contains((string)$probe, 'NO_CONFIG')) {
    nano_check('admin render (skipped: no local bootstrap/config)', true,
        'set up a local bootstrap.php + run setup to exercise this');
    return;
}

$pages = ['index.php', 'edit.php', 'media.php', 'categories.php', 'settings.php', 'licence.php', 'help.php'];
foreach ($pages as $page) {
    $html = (string)shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($harness), escapeshellarg($page)));
    $no_fatal = !preg_match('/Fatal error|Parse error|Uncaught/i', $html);
    $complete = str_contains($html, '</html>');
    $chrome   = str_contains($html, 'nano-cms-admin-header');
    nano_check("admin/$page renders without a fatal", $no_fatal,
        $no_fatal ? '' : substr(preg_replace('/\s+/', ' ', $html), 0, 160));
    nano_check("admin/$page is a complete page with chrome", $complete && $chrome);
}
