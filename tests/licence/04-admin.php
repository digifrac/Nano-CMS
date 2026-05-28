<?php
/**
 * Tests for the admin shell and nav.
 *
 * The admin nav is no longer hand-copied into each page; it is rendered
 * once by nano_admin_header() in admin/core.php, which highlights the
 * current page instead of removing its link. These tests assert that
 * shared-scaffold design:
 *   - every admin file parses
 *   - the canonical nav in core.php links to every section (incl. licence)
 *   - the current page is highlighted (nav-current), not dropped
 *   - every admin page delegates its chrome to nano_admin_header()
 *   - no page still hand-rolls the old class="bar" nav
 *   - each page passes its correct current-nav key
 */
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$repo  = dirname(__DIR__, 2);
$admin = $repo . '/admin';

$pages = ['index.php', 'media.php', 'categories.php', 'settings.php', 'licence.php', 'help.php', 'edit.php'];

nano_section('php -l (syntax) on every admin file');
foreach (array_merge(['core.php', 'setup.php'], $pages) as $f) {
    $out = shell_exec(sprintf('php -l %s 2>&1', escapeshellarg("$admin/$f"))) ?? '';
    nano_check("admin/$f parses", strpos($out, 'No syntax errors') !== false, trim($out));
}

nano_section('canonical nav in core.php links to every section');
$core = (string)file_get_contents("$admin/core.php");
nano_check('core.php defines nano_admin_header()',
    strpos($core, 'function nano_admin_header') !== false);
foreach (['index.php', 'media.php', 'categories.php', 'settings.php', 'licence.php', 'help.php'] as $target) {
    nano_check("nav targets $target", strpos($core, "'$target'") !== false);
}
nano_check('current page is highlighted, not removed (nav-current)',
    strpos($core, 'nano-cms-admin-nav-current') !== false);

nano_section('every admin page delegates its chrome to the shared scaffold');
foreach ($pages as $f) {
    $contents = (string)file_get_contents("$admin/$f");
    nano_check("admin/$f calls nano_admin_header()",
        strpos($contents, 'nano_admin_header(') !== false);
    nano_check("admin/$f no longer hand-rolls class=\"bar\" nav",
        strpos($contents, 'class="bar"') === false);
}

nano_section('each page passes its correct current-nav key');
$expected = [
    'index.php'      => "'posts'",
    'media.php'      => "'media'",
    'categories.php' => "'categories'",
    'settings.php'   => "'settings'",
    'licence.php'    => "'licence'",
    'help.php'       => "'help'",
    'edit.php'       => "'posts'", // editing a post lives under Posts
];
foreach ($expected as $f => $key) {
    $contents = (string)file_get_contents("$admin/$f");
    nano_check("admin/$f marks current nav $key",
        strpos($contents, $key) !== false);
}
