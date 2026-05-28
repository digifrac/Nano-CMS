<?php
/**
 * First-time setup wizard. Runs once per deployment. Refuses to
 * overwrite an existing config.json - to change settings later, edit
 * the file directly via SFTP.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';

nano_admin_assert_https();

if (nano_admin_config_exists()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Setup has already been completed for this site. "
       . "Edit config.json directly to change settings.";
    exit;
}

// Bound the unauthenticated-setup window. Setup.php has to be reachable
// before any password is set, so we can't gate it on auth. Instead,
// require that bootstrap.php was modified within the last 10 minutes -
// the operator's own SFTP upload of bootstrap.php is the implicit
// "I am at the keyboard now" signal. If they walk away without
// finishing, the window closes and an opportunistic visitor can't
// claim the password later. Recovering: re-upload bootstrap.php.
$bootstrap_path = __DIR__ . '/../bootstrap.php';
$bootstrap_mtime = is_file($bootstrap_path) ? (int)@filemtime($bootstrap_path) : 0;
if ($bootstrap_mtime === 0 || (time() - $bootstrap_mtime) > 600) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Setup window closed. Re-upload bootstrap.php (via SFTP) to "
       . "open a new 10-minute window, then reload this page.";
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_admin_require_csrf();

    $password       = (string)($_POST['password'] ?? '');
    $confirm        = (string)($_POST['password_confirm'] ?? '');
    $site_name      = trim((string)($_POST['site_name'] ?? ''));
    $base_url       = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
    $author         = trim((string)($_POST['author'] ?? ''));
    $publisher_name = trim((string)($_POST['publisher_name'] ?? ''));
    $publisher_logo = trim((string)($_POST['publisher_logo'] ?? ''));
    $posts_per_page = (int)($_POST['posts_per_page'] ?? 10);

    if (strlen($password) < NANO_ADMIN_PASSWORD_MIN) {
        $errors[] = 'Password must be at least ' . NANO_ADMIN_PASSWORD_MIN . ' characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if ($site_name === '') {
        $errors[] = 'Site name is required.';
    }
    // Strict shape: http(s)://host[:port][/path] with only URL-safe chars.
    // Stops a setup-time value like `javascript:alert(1)` from later
    // landing inside an href= attribute - htmlspecialchars escapes < and "
    // but doesn't neutralise a javascript: scheme.
    if (!preg_match('~^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~!\$&\'()*+,;=:@%/-]*)?$~', $base_url)) {
        $errors[] = 'Base URL must be a plain http(s) URL like https://example.com/blog.';
    }
    if ($author === '') {
        $errors[] = 'Author name is required.';
    }
    if ($publisher_name === '') {
        $errors[] = 'Publisher name is required.';
    }
    if ($posts_per_page < 1 || $posts_per_page > 50) {
        $errors[] = 'Posts per page must be between 1 and 50.';
    }

    if (empty($errors)) {
        nano_admin_save_config([
            'format_version'          => '1.3',
            'site_name'               => $site_name,
            'base_url'                => $base_url,
            'author'                  => $author,
            'publisher_name'          => $publisher_name,
            'publisher_logo'          => $publisher_logo,
            'posts_per_page'          => $posts_per_page,
            'licence_key'             => '',
            'password_hash'           => password_hash($password, PASSWORD_DEFAULT),
            'created'                 => gmdate('Y-m-d\TH:i:s\Z'),
            'admin_version_last_used' => NANO_ADMIN_VERSION,
        ]);
        // Render a "setup complete" landing page instead of silently
        // redirecting to login - the user needs a clear, prominent
        // reminder to delete setup.php from the server now that it
        // has done its one and only job.
        echo nano_admin_header('Setup complete', '', false, 'nano-cms-admin-setup');
        ?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-success">Configuration saved to <code>config.json</code>.</div>
<div class="nano-cms-admin-advisory">
<h2>One last step: delete <code>setup.php</code> from your server now.</h2>
<p>This file has done its only job. Leaving it in place doesn't break anything (subsequent visits return 403), but removing it cuts one unused URL from your attack surface and matches the rest of Nano CMS's "upload only when needed" pattern.</p>
</div>
<p><a class="nano-cms-admin-button nano-cms-admin-button-primary" href="index.php">Continue to sign-in</a></p>
<?php echo nano_admin_render_footer();
        exit;
    }
}
echo nano_admin_header('First-time setup', '', false, 'nano-cms-admin-setup');
?>
<p>Choose a password and enter the site details. This wizard runs only once.</p>
<?php if (!empty($errors)): ?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-error"><ul>
<?php foreach ($errors as $e): ?><li><?= nano_admin_e($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>
<form class="nano-cms-admin-form" method="post" autocomplete="off">
<?= nano_admin_csrf_field() ?>
<label>Password (min <?= NANO_ADMIN_PASSWORD_MIN ?> chars)<input type="password" name="password" required></label>
<label>Confirm password<input type="password" name="password_confirm" required></label>
<label>Site name<input type="text" name="site_name" value="<?= nano_admin_e((string)($_POST['site_name'] ?? '')) ?>" required></label>
<label>Base URL of the blog<input type="url" name="base_url" placeholder="https://example.com/blog" value="<?= nano_admin_e((string)($_POST['base_url'] ?? '')) ?>" required></label>
<p class="nano-cms-admin-help">Full URL the blog is served at. Used for canonical links and the feed.</p>
<label>Author name<input type="text" name="author" value="<?= nano_admin_e((string)($_POST['author'] ?? '')) ?>" required></label>
<label>Publisher name<input type="text" name="publisher_name" value="<?= nano_admin_e((string)($_POST['publisher_name'] ?? '')) ?>" required></label>
<label>Publisher logo URL (optional)<input type="url" name="publisher_logo" value="<?= nano_admin_e((string)($_POST['publisher_logo'] ?? '')) ?>"></label>
<label>Posts per page<input type="number" name="posts_per_page" min="1" max="50" value="<?= nano_admin_e((string)($_POST['posts_per_page'] ?? '10')) ?>" required></label>
<div class="nano-cms-admin-form-actions">
<button type="submit" class="nano-cms-admin-button nano-cms-admin-button-primary">Create site</button>
</div>
</form>
<?php echo nano_admin_render_footer(); ?>
