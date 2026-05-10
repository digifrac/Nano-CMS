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
    if (!preg_match('~^https?://~', $base_url)) {
        $errors[] = 'Base URL must start with http:// or https://.';
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
            'format_version'          => '1.1',
            'site_name'               => $site_name,
            'base_url'                => $base_url,
            'author'                  => $author,
            'publisher_name'          => $publisher_name,
            'publisher_logo'          => $publisher_logo,
            'posts_per_page'          => $posts_per_page,
            'password_hash'           => password_hash($password, PASSWORD_DEFAULT),
            'created'                 => gmdate('Y-m-d\TH:i:s\Z'),
            'admin_version_last_used' => NANO_ADMIN_VERSION,
        ]);
        // Render a "setup complete" landing page instead of silently
        // redirecting to login - the user needs a clear, prominent
        // reminder to delete setup.php from the server now that it
        // has done its one and only job.
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup complete - <?= nano_admin_e($site_name) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="setup">
<h1>Setup complete</h1>
<div class="flash-ok">Configuration saved to <code>config.json</code>.</div>
<div class="warn">
<strong>One last step: delete <code>setup.php</code> from your server now.</strong>
<p>This file has done its only job. Leaving it in place doesn't break anything (subsequent visits return 403), but removing it cuts one unused URL from your attack surface and matches the rest of Nano CMS's "upload only when needed" pattern.</p>
</div>
<p><a class="button-primary" href="index.php">Continue to sign-in</a></p>
</body>
</html>
        <?php
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nano CMS - Setup</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="setup">
<h1>Nano CMS - first-time setup</h1>
<p>Choose a password and enter the site details. This wizard runs only once.</p>
<?php if (!empty($errors)): ?>
<div class="errors"><ul>
<?php foreach ($errors as $e): ?><li><?= nano_admin_e($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>
<form method="post" autocomplete="off">
<?= nano_admin_csrf_field() ?>
<label>Password (min <?= NANO_ADMIN_PASSWORD_MIN ?> chars)<input type="password" name="password" required></label>
<label>Confirm password<input type="password" name="password_confirm" required></label>
<label>Site name<input type="text" name="site_name" value="<?= nano_admin_e((string)($_POST['site_name'] ?? '')) ?>" required></label>
<label>Base URL of the blog<input type="url" name="base_url" placeholder="https://example.com/blog" value="<?= nano_admin_e((string)($_POST['base_url'] ?? '')) ?>" required></label>
<p class="help">Full URL the blog is served at. Used for canonical links and the feed.</p>
<label>Author name<input type="text" name="author" value="<?= nano_admin_e((string)($_POST['author'] ?? '')) ?>" required></label>
<label>Publisher name<input type="text" name="publisher_name" value="<?= nano_admin_e((string)($_POST['publisher_name'] ?? '')) ?>" required></label>
<label>Publisher logo URL (optional)<input type="url" name="publisher_logo" value="<?= nano_admin_e((string)($_POST['publisher_logo'] ?? '')) ?>"></label>
<label>Posts per page<input type="number" name="posts_per_page" min="1" max="50" value="<?= nano_admin_e((string)($_POST['posts_per_page'] ?? '10')) ?>" required></label>
<button type="submit">Create site</button>
</form>
</body>
</html>
