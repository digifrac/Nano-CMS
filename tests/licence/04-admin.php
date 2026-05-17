<?php
/**
 * Tests for the admin Licence page and nav links:
 *   - admin/licence.php parses cleanly
 *   - every other admin nav links to licence.php
 *   - licence.php's own nav links to everything else and not to itself
 */
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$repo  = dirname(__DIR__, 2);
$admin = $repo . '/admin';

nano_section('php -l (syntax) on every modified/new admin file');
$files = ['licence.php', 'settings.php', 'help.php', 'media.php', 'categories.php', 'edit.php', 'index.php'];
foreach ($files as $f) {
    $out = shell_exec(sprintf('php -l %s 2>&1', escapeshellarg("$admin/$f"))) ?? '';
    nano_check("admin/$f parses", strpos($out, 'No syntax errors') !== false, trim($out));
}

nano_section('nav links: every admin page links to licence.php');
foreach (['settings.php', 'help.php', 'media.php', 'categories.php', 'edit.php', 'index.php'] as $f) {
    $contents = file_get_contents("$admin/$f");
    nano_check("admin/$f links to licence.php",
        strpos($contents, 'href="licence.php"') !== false);
}

nano_section('licence.php nav: links to everything else, not to itself');
$lic      = file_get_contents("$admin/licence.php");
$nav_end  = strpos($lic, '</div>', strpos($lic, 'class="bar"')) ?: strlen($lic);
$nav_block = substr($lic, 0, $nav_end);

foreach (['index.php', 'media.php', 'categories.php', 'settings.php', 'help.php'] as $other) {
    nano_check("licence nav contains $other", strpos($nav_block, "href=\"$other\"") !== false);
}
nano_check('licence nav has NO self-link', strpos($nav_block, 'href="licence.php"') === false);
