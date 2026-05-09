<?php
/**
 * Nano CMS pre-flight check.
 *
 * Upload this file to the host root (or wherever the blog will live)
 * and load it once over HTTPS. It checks the host has everything Nano
 * CMS needs to run. DELETE THIS FILE when you're done - it leaks host
 * details that don't need to be public.
 */

declare(strict_types=1);

function row(string $name, bool $ok, string $value, string $note = ''): void
{
    $cls = $ok ? 'ok' : 'fail';
    $icon = $ok ? 'PASS' : 'FAIL';
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $note = $note !== '' ? '<br><small>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</small>' : '';
    echo "<tr class='$cls'><td>$icon</td><td>$name</td><td>$value$note</td></tr>";
}

$checks = [];

// PHP version - we use match expressions (8.0) and require_once null-safety
$php = PHP_VERSION;
$php_ok = version_compare($php, '8.0', '>=');
$checks[] = ['PHP version', $php_ok, $php, $php_ok ? 'Need 8.0 or newer (we use match expressions).' : 'NEED 8.0+. Update PHP version in your host control panel.'];

// HTTPS - admin refuses to load over HTTP
$https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$checks[] = ['HTTPS', $https, $https ? 'on' : 'OFF', $https ? 'Good - the admin will accept this connection.' : 'CRITICAL - the admin refuses HTTP. Enable Let\'s Encrypt in your host control panel.'];

// Image re-encoder - one of GD or Imagick is required for uploads
$gd = extension_loaded('gd');
$imagick = extension_loaded('imagick');
$reencoder = $gd || $imagick;
$reenc_value = ($gd ? 'GD' : '') . ($gd && $imagick ? ' + ' : '') . ($imagick ? 'Imagick' : '');
if (!$reencoder) $reenc_value = 'NEITHER';
$checks[] = ['Image re-encoder (GD or Imagick)', $reencoder, $reenc_value,
    $reencoder ? 'Media uploads will work.' : 'Without one of these, the admin refuses uploads. GD is part of standard PHP - enable it in your host control panel.'];

// GD WebP support (only relevant if using GD)
if ($gd) {
    $webp = function_exists('imagewebp');
    $checks[] = ['GD WebP support', $webp, $webp ? 'yes' : 'no', $webp ? '' : 'WebP uploads will be refused, but JPG/PNG/GIF still work.'];
}

// fileinfo extension - required for MIME detection on uploads
$finfo = extension_loaded('fileinfo');
$checks[] = ['fileinfo extension', $finfo, $finfo ? 'loaded' : 'MISSING', $finfo ? '' : 'CRITICAL - upload validation cannot work without it.'];

// session support
$sess = function_exists('session_start');
$checks[] = ['Session support', $sess, $sess ? 'yes' : 'no'];

// mod_rewrite (Apache only) - required for clean URLs
if (function_exists('apache_get_modules')) {
    $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
    $checks[] = ['Apache mod_rewrite', $rewrite, $rewrite ? 'enabled' : 'disabled',
        $rewrite ? 'Clean URLs (.htaccess) will work.' : 'Clean URLs will not work. Enable mod_rewrite in your host control panel.'];
} else {
    $checks[] = ['Apache mod_rewrite', true, 'unknown (not Apache, or apache_get_modules unavailable)',
        'On nginx you handle clean URLs in the server block instead of .htaccess.'];
}

// AllowOverride / .htaccess (heuristic test)
$ht_works = file_exists(__FILE__) && is_readable(__FILE__);
// Real test: try to load a .htaccess - we'll just inform.
$checks[] = ['.htaccess support', true, 'check manually',
    'After uploading a test .htaccess, visit a deny-listed path to confirm AllowOverride is on.'];

// Document-root path - useful for setting up the outside-webroot config dir
$docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '?');
$cwd = getcwd() ?: '?';
$checks[] = ['DOCUMENT_ROOT', true, $docroot, "config dir should live OUTSIDE this path (e.g., parent dir, often $docroot/../blog-config/)."];
$checks[] = ['Current working dir', true, $cwd];

// Writability - parent of DOCUMENT_ROOT (where blog-config will go)
$parent = dirname($docroot);
$parent_writable = is_writable($parent);
$checks[] = ['Parent of DOCUMENT_ROOT writable', $parent_writable, $parent . ' (' . ($parent_writable ? 'writable' : 'NOT writable') . ')',
    $parent_writable ? "OK to put blog-config/ here." : "If not writable, put the config dir somewhere else outside webroot, or contact host."];

// Posts/media dirs in current dir
$here_writable = is_writable($cwd);
$checks[] = ['Current dir writable (for posts/, media/)', $here_writable, $here_writable ? 'yes' : 'no'];

// upload_max_filesize - Nano CMS app-level cap is 5MB
$upload_max = (string)ini_get('upload_max_filesize');
$post_max = (string)ini_get('post_max_size');
$checks[] = ['upload_max_filesize', true, $upload_max, 'App-level cap is 5MB; this only matters if it\'s LOWER than 5M.'];
$checks[] = ['post_max_size', true, $post_max];

// Display errors - should be off in production
$display = ini_get('display_errors');
$display_off = !($display && $display !== '0');
$checks[] = ['display_errors off', $display_off, (string)$display ?: '0',
    $display_off ? '' : 'Recommended: turn off in production php.ini to avoid leaking paths in error pages.'];

// Render
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Nano CMS pre-flight</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 820px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #1a1a1a; }
h1 { font-size: 1.5rem; }
table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #eee; vertical-align: top; }
td:first-child { width: 4rem; font-weight: 700; }
.ok td:first-child { color: #060; }
.fail td:first-child { color: #c00; }
small { color: #666; }
.warning { background: #fff5e0; border: 1px solid #cba62b; padding: 0.5rem 1rem; border-radius: 4px; margin: 1rem 0; }
</style>
</head>
<body>
<h1>Nano CMS pre-flight check</h1>
<p>Each row tells you if the host meets one Nano CMS requirement. Anything FAIL needs fixing before deploying.</p>

<div class="warning"><strong>DELETE this file</strong> after checking - it exposes host detail (paths, PHP config) that doesn't need to be public.</div>

<table>
<?php foreach ($checks as $c) row($c[0], $c[1], $c[2], $c[3] ?? ''); ?>
</table>

<h2>Summary</h2>
<?php
$blocking = array_filter($checks, fn($c) => !$c[1]);
if (empty($blocking)):
?>
<p style="background:#efe; border:1px solid #9c9; padding:0.5rem 1rem; border-radius:4px;"><strong>Ready to deploy.</strong> No blocking issues found.</p>
<?php else: ?>
<p style="background:#fee; border:1px solid #f99; padding:0.5rem 1rem; border-radius:4px;"><strong>Not ready.</strong> Fix the FAIL rows first.</p>
<ul>
<?php foreach ($blocking as $b): ?>
<li><?= htmlspecialchars($b[0], ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<p><small>Generated <?= date('Y-m-d H:i:s T') ?> &middot; PHP <?= PHP_VERSION ?> on <?= php_sapi_name() ?></small></p>
</body>
</html>
