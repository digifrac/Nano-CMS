<?php
/**
 * Admin entry point. Step 7 ships login, logout, and a placeholder
 * dashboard. Step 8 will replace the dashboard with the post list,
 * editor, and media manager.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';

nano_admin_assert_https();

// First-time deployment: bounce to setup.php if no config exists.
if (!nano_admin_config_exists()) {
    header('Location: setup.php');
    exit;
}

nano_admin_version_check();

$action = (string)($_GET['action'] ?? '');

if ($action === 'logout') {
    nano_admin_logout();
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '' && !nano_admin_logged_in()) {
    if (!nano_admin_csrf_check((string)($_POST['csrf'] ?? ''))) {
        $error = 'Session expired. Please reload and try again.';
    } else {
        $result = nano_admin_login_attempt(
            (string)($_POST['password'] ?? ''),
            nano_admin_client_ip()
        );
        if ($result['ok']) {
            header('Location: index.php');
            exit;
        }
        $error = match ($result['reason'] ?? '') {
            'blocked' => 'Too many failed attempts. Try again later.',
            'invalid' => 'Incorrect password.',
            default   => 'Login failed.',
        };
    }
}

$cfg = nano_admin_load_config();
$site_name = (string)($cfg['site_name'] ?? 'Nano CMS');

if (!nano_admin_logged_in()) {
    /* ===== Login form ===================================================== */
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nano_admin_e($site_name) ?> - Sign in</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 360px; margin: 4rem auto; padding: 0 1rem; line-height: 1.5; color: #1a1a1a; }
h1 { font-size: 1.25rem; }
label { display: block; margin: 1rem 0 0.25rem; font-weight: 600; }
input { width: 100%; padding: 0.5rem; box-sizing: border-box; font-size: 1rem; }
.error { background: #fee; border: 1px solid #f99; padding: 0.5rem 1rem; border-radius: 4px; margin: 1rem 0; }
button { margin-top: 1rem; padding: 0.5rem 1.5rem; font-size: 1rem; cursor: pointer; }
</style>
</head>
<body>
<h1>Sign in to <?= nano_admin_e($site_name) ?></h1>
<?php if ($error !== null): ?>
<div class="error"><?= nano_admin_e($error) ?></div>
<?php endif; ?>
<form method="post" autocomplete="off">
<?= nano_admin_csrf_field() ?>
<label>Password<input type="password" name="password" autocomplete="current-password" autofocus required></label>
<button type="submit">Sign in</button>
</form>
</body>
</html>
    <?php
    exit;
}

/* ===== Logged in: placeholder dashboard (step 8 replaces this) =========== */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nano_admin_e($site_name) ?> - Admin</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #1a1a1a; }
h1 { font-size: 1.5rem; }
.bar { display: flex; justify-content: space-between; align-items: baseline; }
</style>
</head>
<body>
<div class="bar">
<h1><?= nano_admin_e($site_name) ?> - admin</h1>
<a href="?action=logout">Sign out</a>
</div>
<p>Signed in. The post list, editor, and media manager will live here in the next build step.</p>
</body>
</html>
