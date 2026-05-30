<?php
/**
 * Nano CMS - first-time web installer.
 *
 * Lives at /blog/install.php. Detects whether the install is already
 * configured (bootstrap.php exists). If not, creates the outside-webroot
 * config directory, writes bootstrap.php with absolute paths, and hands
 * off to the admin setup wizard.
 *
 * Operator deletes this file after install (same pattern as the admin
 * folder). The script refuses to run once bootstrap.php exists, so a
 * forgotten install.php cannot reconfigure a live blog. The success page
 * tells the operator NOT to delete yet: the admin dashboard shows a red
 * banner with a one-click delete once setup is complete, which keeps the
 * setup-wizard hand-off intact.
 */

$blog_dir   = __DIR__;
$bootstrap  = $blog_dir . '/bootstrap.php';
$self_file  = __FILE__;

// Absolute, PATH_INFO-proof URLs. install.php's own links/forms must be
// absolute: accessing the script with trailing path info (e.g.
// /blog/install.php/admin/) otherwise makes a relative "admin/" link stack
// into /blog/install.php/admin/admin/... and re-run the installer in a loop.
$self_url   = $_SERVER['SCRIPT_NAME'] ?? 'install.php';
$base_url   = rtrim(str_replace('\\', '/', dirname($self_url)), '/');
$admin_url  = $base_url . '/admin/';

// Never run with trailing path info; bounce to the clean script URL.
if (!empty($_SERVER['PATH_INFO'])) {
    header('Location: ' . $self_url, true, 302);
    exit;
}

/**
 * Pick a sensible default for the outside-webroot config directory.
 *
 * The naive choice "sibling of /blog/" works on stock hosting where the
 * webroot is e.g. /var/www/html and blog/ sits inside it. On cPanel /
 * Plesk setups with addon domains, the webroot IS one level under
 * /home/<user>/ (e.g. /home/clientuser/example.com/), so "sibling of
 * blog/" lands inside the webroot, which is exactly what we are trying
 * to avoid.
 *
 * Strategy: take DOCUMENT_ROOT as authoritative if present, go one level
 * above it. Fall back to dirname(__DIR__) only when DOCUMENT_ROOT is
 * missing or matches __DIR__ exactly (the installer is in the webroot
 * itself, in which case dirname is still correct).
 */
function nano_install_default_cfg_dir(string $blog_dir): string
{
    // Unique per-site name. Multiple Nano CMS blogs under the same parent
    // directory must NOT default to the same config dir, or they would
    // silently share one config.json (admin password hash + licence key).
    // Derive a slug from the hostname so each domain gets its own.
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $host), '-');
    $name = 'nano-blog-config' . ($slug !== '' ? '-' . $slug : '');
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/') : '';
    $blog_norm = rtrim(str_replace('\\', '/', $blog_dir), '/');
    $parent = dirname($blog_norm);
    if ($docroot !== '' && (str_starts_with($blog_norm, $docroot . '/') || $blog_norm === $docroot)) {
        // The blog is inside DOCUMENT_ROOT. Go one level ABOVE the
        // document root to be safely outside.
        return dirname($docroot) . DIRECTORY_SEPARATOR . $name;
    }
    return $parent . DIRECTORY_SEPARATOR . $name;
}

$default_cfg_dir = nano_install_default_cfg_dir($blog_dir);

/**
 * True if the given path would be web-accessible (lives inside
 * DOCUMENT_ROOT). Used to warn the operator if they enter a path that
 * defeats the "config outside webroot" guarantee.
 */
function nano_install_is_inside_docroot(string $path): bool
{
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/') : '';
    if ($docroot === '') return false;
    $p = rtrim(str_replace('\\', '/', $path), '/');
    return $p === $docroot || str_starts_with($p, $docroot . '/');
}

function nano_install_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function nano_install_page(string $title, string $body, string $extra_head = ''): void
{
    $title_h = nano_install_h($title);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . $title_h . ' - Nano CMS install</title>'
       . '<style>'
       . 'body{font-family:system-ui,-apple-system,sans-serif;max-width:42em;margin:2em auto;padding:0 1em;color:#1f2328;line-height:1.55}'
       . 'h1{font-size:1.5em;margin:0 0 1em}h2{font-size:1.1em;margin:1.5em 0 .5em}'
       . 'code,pre{background:#f6f8fa;border-radius:4px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.92em}'
       . 'code{padding:.1em .35em}pre{padding:.75em 1em;overflow:auto}'
       . 'label{display:block;margin:1em 0;font-weight:500}'
       . 'input[type=text]{display:block;width:100%;padding:.55em .7em;font-family:inherit;font-size:1em;border:1px solid #d0d7de;border-radius:4px;margin-top:.35em}'
       . '.btn{display:inline-block;padding:.6em 1.2em;background:#0066cc;color:#fff;border:1px solid #0066cc;border-radius:4px;text-decoration:none;cursor:pointer;font:inherit}'
       . '.btn:hover{background:#004fa3}'
       . '.btn-secondary{background:#fff;color:#1f2328;border-color:#d0d7de}'
       . '.danger{background:#ffebe9;border:1px solid #82071e;color:#82071e;padding:1em;border-radius:4px;margin:1em 0}'
       . '.success{background:#dafbe1;border:1px solid #1a7f37;color:#1a7f37;padding:1em;border-radius:4px;margin:1em 0}'
       . '.warning{background:#fff8c5;border:1px solid #9a6700;color:#7d4e00;padding:1em;border-radius:4px;margin:1em 0}'
       . '.meta{color:#57606a;font-size:.85em}'
       . $extra_head
       . '</style></head><body>'
       . '<h1>Nano CMS install: ' . $title_h . '</h1>'
       . $body
       . '<p class="meta">install.php should be deleted from /blog/ after a successful install. Re-uploading is only needed to reinstall on a fresh server.</p>'
       . '</body></html>';
}

/* ----------------------------------------------------------------------- */
/* Self-delete action (POST action=delete)                                  */
/* ----------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleted = @unlink($self_file);
    if ($deleted) {
        // After delete, send the operator to /admin/ which routes
        // correctly based on state (dashboard if logged in, login if
        // config exists, setup if not). By the time delete happens,
        // setup is typically already complete - the dashboard banner is
        // the primary entry point to this action.
        nano_install_page(
            'install.php removed',
            '<div class="success"><p><strong>install.php deleted from the server.</strong></p></div>'
            . (file_exists($bootstrap)
                ? '<p><a class="btn" href="' . nano_install_h($admin_url) . '">Go to admin</a></p>'
                : '<p>You can now configure the blog. Re-upload install.php from the release zip if you want to run it again later.</p>')
        );
    } else {
        nano_install_page(
            'cannot delete',
            '<div class="danger"><p>PHP could not delete <code>install.php</code> on this host. Some shared hosts block scripts from unlinking themselves.</p>'
            . '<p>Please remove it manually:</p>'
            . '<ul>'
            . '<li><strong>cPanel:</strong> File Manager &rarr; navigate to <code>/blog/</code> &rarr; right-click <code>install.php</code> &rarr; Delete</li>'
            . '<li><strong>SFTP client</strong> (FileZilla, Cyberduck, WinSCP): connect, navigate to <code>/blog/</code>, right-click <code>install.php</code> &rarr; Delete</li>'
            . '<li><strong>SSH:</strong> <code>rm ' . nano_install_h($self_file) . '</code></li>'
            . '</ul></div>'
            . (file_exists($bootstrap)
                ? '<p><a class="btn btn-secondary" href="' . nano_install_h($admin_url) . '">Go to admin</a></p>'
                : '')
        );
    }
    exit;
}

$delete_form = '<form method="post" action="' . nano_install_h($self_url) . '" style="display:inline">'
    . '<input type="hidden" name="action" value="delete">'
    . '<button class="btn btn-secondary" type="submit" onclick="return confirm(\'Delete install.php from the server now?\')">Delete install.php</button>'
    . '</form>';

/* ----------------------------------------------------------------------- */
/* Already configured? Bail.                                                */
/* ----------------------------------------------------------------------- */

if (file_exists($bootstrap)) {
    nano_install_page(
        'already configured',
        '<div class="warning"><p><strong>This blog is already configured.</strong> <code>bootstrap.php</code> exists. Re-running the installer is not allowed because it would overwrite the live configuration.</p></div>'
        . '<p>If you want to reconfigure from scratch, delete <code>bootstrap.php</code> AND the config directory (the one referenced inside <code>bootstrap.php</code>), then reload this page.</p>'
        . '<p><strong>You should delete this <code>install.php</code> file now.</strong> It served its purpose; leaving it on the server is a small fingerprinting risk.</p>'
        . '<p><a class="btn" href="' . nano_install_h($admin_url) . '">Go to admin</a> ' . $delete_form . '</p>'
    );
    exit;
}

/* ----------------------------------------------------------------------- */
/* POST: try to install                                                     */
/* ----------------------------------------------------------------------- */

$errors = [];
$cfg_dir = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg_dir = rtrim(str_replace('\\', '/', trim((string)($_POST['cfg_dir'] ?? ''))), '/');

    if ($cfg_dir === '') {
        $errors[] = 'Config directory path is required.';
    } elseif (str_contains($cfg_dir, '..')) {
        $errors[] = 'Path may not contain "..".';
    } elseif (str_starts_with($cfg_dir, $blog_dir . '/') || $cfg_dir === $blog_dir) {
        $errors[] = 'Config directory must be OUTSIDE the blog directory (that is the point).';
    } elseif (nano_install_is_inside_docroot($cfg_dir)) {
        $errors[] = 'Config directory <code>' . nano_install_h($cfg_dir) . '</code> is inside the webroot (<code>' . nano_install_h((string)$_SERVER['DOCUMENT_ROOT']) . '</code>). It would be web-accessible, defeating the whole point of an outside-webroot config. Try a path ABOVE the webroot, e.g. <code>' . nano_install_h(dirname((string)$_SERVER['DOCUMENT_ROOT']) . '/nano-blog-config') . '</code>.';
    } elseif (is_file($cfg_dir . '/config.json')) {
        $errors[] = 'A <code>config.json</code> already exists in <code>' . nano_install_h($cfg_dir) . '</code>. That directory belongs to an existing Nano CMS install. Choose a different, empty config directory for THIS blog (for example <code>' . nano_install_h($default_cfg_dir) . '</code>) - otherwise the two sites would share one admin password and licence.';
    } else {
        // Try to create the directory.
        if (!is_dir($cfg_dir)) {
            if (!@mkdir($cfg_dir, 0750, true)) {
                $errors[] = "Could not create directory <code>" . nano_install_h($cfg_dir) . "</code>. "
                    . "PHP probably lacks permission to write to its parent. "
                    . "Create the directory manually, run <code>chmod 750 " . nano_install_h($cfg_dir) . "</code>, "
                    . "then reload this page.";
            }
        }
        // Try to write a test file to confirm writability.
        if (empty($errors)) {
            $test = $cfg_dir . '/.write-test';
            if (@file_put_contents($test, 'ok') === false) {
                $errors[] = "Directory <code>" . nano_install_h($cfg_dir) . "</code> exists but PHP cannot write to it. "
                    . "Run <code>chown</code> to set ownership to the PHP user (typically <code>www-data</code> "
                    . "or your host's PHP user) and <code>chmod 750</code>, then reload.";
            } else {
                @unlink($test);
                @chmod($cfg_dir, 0750);
            }
        }
        // Write bootstrap.php. Mirrors bootstrap.example.php: defines the
        // path constants only and does NOT require core.php - each Nano CMS
        // entry point (front end and admin) loads core.php itself.
        if (empty($errors)) {
            $cfg_dir_php = var_export($cfg_dir, true);
            $bootstrap_contents = "<?php\n"
                . "// Generated by install.php on " . gmdate('Y-m-d\TH:i:s\Z') . " UTC.\n"
                . "// Edit the paths below if you ever move the config directory.\n\n"
                . "\$cfg_dir = " . $cfg_dir_php . ";\n\n"
                . "define('NANO_BOOTSTRAPPED', true);\n\n"
                . "define('NANO_CONFIG_PATH',     \$cfg_dir . '/config.json');\n"
                . "define('NANO_RATE_LIMIT_PATH', \$cfg_dir . '/rate-limit.json');\n"
                . "define('NANO_CONTENT_PATH',    __DIR__);\n\n"
                . "// Set to true ONLY if this site is behind a reverse proxy / CDN you\n"
                . "// trust to set X-Forwarded-Proto correctly (Cloudflare, AWS ALB,\n"
                . "// nginx in front of php-fpm). Leaving it false trusts only the direct\n"
                . "// \$_SERVER['HTTPS'] flag, which is the safe default.\n"
                . "define('NANO_TRUST_PROXY', false);\n";
            if (@file_put_contents($bootstrap, $bootstrap_contents) === false) {
                $errors[] = "Could not write <code>bootstrap.php</code> in the blog directory. "
                    . "Check that PHP can write to <code>" . nano_install_h($blog_dir) . "</code>.";
            } else {
                @chmod($bootstrap, 0640);
            }
        }
        if (empty($errors)) {
            // Success - render the success page and stop.
            nano_install_page(
                'install complete',
                '<div class="success"><p><strong>Installed.</strong> <code>bootstrap.php</code> is in place and points at <code>' . nano_install_h($cfg_dir) . '</code>.</p></div>'
                . '<h2>Next step</h2>'
                . '<p><a class="btn" href="' . nano_install_h($base_url) . '/admin/setup.php">Open setup wizard</a></p>'
                . '<p>The setup wizard creates the operator password and site settings. <strong>Do not delete install.php yet</strong> - the admin dashboard shows a one-click "delete install.php" banner once setup completes successfully. Deleting it now would break the setup hand-off.</p>'
                . '<h2>What just happened</h2>'
                . '<ul>'
                . '<li>Created <code>' . nano_install_h($cfg_dir) . '</code> (mode 0750) for outside-webroot config.</li>'
                . '<li>Wrote <code>bootstrap.php</code> in the blog directory pointing at it.</li>'
                . '<li>Did NOT create <code>config.json</code> yet; that happens when you complete the setup wizard.</li>'
                . '</ul>'
            );
            exit;
        }
    }
}

/* ----------------------------------------------------------------------- */
/* GET (or POST with errors): show the form                                 */
/* ----------------------------------------------------------------------- */

if ($cfg_dir === '') $cfg_dir = $default_cfg_dir;

$parent_writable = is_writable(dirname($cfg_dir));
$php_user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
    : (getenv('USERNAME') ?: getenv('USER') ?: 'unknown');

$error_html = '';
if (!empty($errors)) {
    $error_html = '<div class="danger"><ul>';
    foreach ($errors as $e) {
        $error_html .= '<li>' . $e . '</li>';
    }
    $error_html .= '</ul></div>';
}

$writable_hint = $parent_writable
    ? '<p class="meta">Parent directory <code>' . nano_install_h(dirname($cfg_dir)) . '</code> appears writable by PHP. The installer should be able to create the config directory automatically.</p>'
    : '<div class="warning"><p>Parent directory <code>' . nano_install_h(dirname($cfg_dir)) . '</code> is NOT writable by PHP (running as <code>' . nano_install_h($php_user) . '</code>). You may need to either:</p>'
      . '<ul>'
      . '<li>Create the config directory manually first, then make it writable: <code>mkdir ' . nano_install_h($cfg_dir) . ' && chmod 750 ' . nano_install_h($cfg_dir) . '</code></li>'
      . '<li>Or chmod the parent so PHP can create it: <code>chmod 750 ' . nano_install_h(dirname($cfg_dir)) . '</code></li>'
      . '</ul></div>';

$docroot_warning = '';
if (nano_install_is_inside_docroot($cfg_dir)) {
    $alt = dirname((string)$_SERVER['DOCUMENT_ROOT']) . '/nano-blog-config';
    $docroot_warning = '<div class="danger"><p><strong>Warning:</strong> the path <code>' . nano_install_h($cfg_dir) . '</code> is INSIDE the webroot (<code>' . nano_install_h((string)$_SERVER['DOCUMENT_ROOT']) . '</code>). A web-accessible config directory would leak your password hash and licence key.</p>'
        . '<p>On cPanel / Plesk hosting where addon domains live under <code>/home/&lt;user&gt;/&lt;domain&gt;/</code>, use a sibling of the webroot like <code>' . nano_install_h($alt) . '</code> instead.</p></div>';
}

$body = $error_html
    . '<p>Nano CMS needs a config directory <strong>outside the webroot</strong> for its <code>config.json</code> (admin password hash, licence key) and <code>rate-limit.json</code> (per-IP login backoff state). Web-accessible config would leak credentials.</p>'
    . '<p>The installer will create that directory and write <code>bootstrap.php</code> for you, then hand off to the admin setup wizard.</p>'
    . $docroot_warning
    . $writable_hint
    . '<form method="post" action="' . nano_install_h($self_url) . '">'
    . '<label>Config directory (absolute path)'
    . '<input type="text" name="cfg_dir" required value="' . nano_install_h($cfg_dir) . '">'
    . '</label>'
    . '<p class="meta">Default is a sibling of <code>/blog/</code>, which keeps it tidy and ensures it is not web-accessible.</p>'
    . '<button class="btn" type="submit">Install</button>'
    . '</form>'
    . '<h2>What if this fails?</h2>'
    . '<p>If the installer cannot create the directory, follow the fallback steps in <a href="https://github.com/digifrac/Nano-CMS/blob/main/INSTALL.md">INSTALL.md</a>: create the directory by hand via SFTP, copy <code>bootstrap.example.php</code> to <code>bootstrap.php</code>, edit the outside-webroot path constants, then visit <code>admin/setup.php</code> directly.</p>';

nano_install_page('configure', $body);
