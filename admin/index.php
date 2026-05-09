<?php
/**
 * Admin entry point. Hosts the login form, the post-list dashboard,
 * and the delete handler. The post editor itself lives in edit.php.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';

nano_admin_assert_https();

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

/* ===== Logged in: dashboard + delete handler ============================ */

require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/../generators.php';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    nano_admin_require_csrf();
    $slug = nano_admin_safe_slug((string)($_POST['slug'] ?? ''));
    if ($slug === '') {
        $flash = ['error', 'Cannot delete: slug missing.'];
    } else {
        nano_admin_delete_post($slug);
        nano_regenerate_static();
        nano_admin_save_config($cfg); // bumps admin_version_last_used
        header('Location: index.php?msg=deleted');
        exit;
    }
}

if ($flash === null && (string)($_GET['msg'] ?? '') !== '') {
    $msg = (string)$_GET['msg'];
    $flash = match ($msg) {
        'saved'   => ['ok', 'Post saved.'],
        'deleted' => ['ok', 'Post deleted.'],
        default   => null,
    };
}

$filter_category = nano_admin_safe_slug((string)($_GET['category'] ?? ''));
$show_drafts = ((string)($_GET['drafts'] ?? '1')) !== '0';

$all_posts = nano_admin_list_posts(true);
$posts = array_values(array_filter($all_posts, static function (array $p) use ($filter_category, $show_drafts): bool {
    if ($filter_category !== '' && ($p['frontmatter']['category'] ?? '') !== $filter_category) {
        return false;
    }
    if (!$show_drafts && !empty($p['frontmatter']['draft'])) {
        return false;
    }
    return true;
}));
$categories = nano_admin_categories();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nano_admin_e($site_name) ?> - Admin</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 920px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #1a1a1a; }
h1 { font-size: 1.5rem; margin: 0; }
.bar { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; margin-bottom: 1rem; }
.bar a { margin-left: 0.75rem; }
.toolbar { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin: 0.5rem 0 1.5rem; padding: 0.75rem 1rem; background: #f5f5f5; border-radius: 4px; }
.toolbar .new { margin-left: auto; padding: 0.4rem 0.9rem; background: #0066cc; color: #fff; text-decoration: none; border-radius: 4px; }
.flash-ok { background: #efe; border: 1px solid #9c9; padding: 0.5rem 1rem; border-radius: 4px; margin: 0 0 1rem; }
.flash-error { background: #fee; border: 1px solid #f99; padding: 0.5rem 1rem; border-radius: 4px; margin: 0 0 1rem; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #eee; vertical-align: top; }
th { background: #fafafa; font-size: 0.875rem; }
.draft-tag { background: #fce8b2; color: #735c00; padding: 0.05rem 0.4rem; border-radius: 3px; font-size: 0.75rem; margin-left: 0.4rem; }
.actions { white-space: nowrap; }
.actions form { display: inline; }
.actions button { background: none; border: none; color: #c00; cursor: pointer; padding: 0; font: inherit; }
.empty { color: #666; font-style: italic; padding: 1rem 0; }
</style>
</head>
<body>
<div class="bar">
  <h1><?= nano_admin_e($site_name) ?> - admin</h1>
  <div><a href="media.php">Media</a> | <a href="?action=logout">Sign out</a></div>
</div>
<?php if ($flash !== null): ?>
<div class="flash-<?= nano_admin_e($flash[0]) ?>"><?= nano_admin_e($flash[1]) ?></div>
<?php endif; ?>

<form class="toolbar" method="get">
  <label>Category
    <select name="category" onchange="this.form.submit()">
      <option value="">All</option>
<?php foreach ($categories as $cat): ?>
      <option value="<?= nano_admin_e($cat) ?>"<?= $filter_category === $cat ? ' selected' : '' ?>><?= nano_admin_e($cat) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>
    <input type="checkbox" name="drafts" value="1"<?= $show_drafts ? ' checked' : '' ?> onchange="this.form.submit()">
    Show drafts
  </label>
  <noscript><button type="submit">Apply</button></noscript>
  <a class="new" href="edit.php">New post</a>
</form>

<?php if (empty($posts)): ?>
<p class="empty">No posts match the current filter.</p>
<?php else: ?>
<table>
<thead>
<tr><th>Title</th><th>Date</th><th>Updated</th><th>Category</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($posts as $p): $fm = $p['frontmatter']; ?>
<tr>
<td>
  <a href="edit.php?slug=<?= nano_admin_e((string)$fm['slug']) ?>"><?= nano_admin_e((string)$fm['title']) ?></a>
<?php if (!empty($fm['draft'])): ?><span class="draft-tag">DRAFT</span><?php endif; ?>
</td>
<td><?= nano_admin_e((string)$fm['date']) ?></td>
<td><?= nano_admin_e((string)($fm['updated'] ?? '')) ?></td>
<td><?= nano_admin_e((string)($fm['category'] ?? '')) ?></td>
<td class="actions">
  <a href="edit.php?slug=<?= nano_admin_e((string)$fm['slug']) ?>">Edit</a>
  <form method="post" action="?action=delete" onsubmit="return confirm('Delete &quot;<?= nano_admin_e((string)$fm['title']) ?>&quot;? This cannot be undone.');">
    <?= nano_admin_csrf_field() ?>
    <input type="hidden" name="slug" value="<?= nano_admin_e((string)$fm['slug']) ?>">
    | <button type="submit">Delete</button>
  </form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</body>
</html>
