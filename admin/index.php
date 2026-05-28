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
    // POST + CSRF only: prevents cross-site GETs (e.g. <img src="..."> in
    // a malicious email) from logging the operator out remotely.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !nano_admin_csrf_check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Logout must be a POST with a valid CSRF token.";
        exit;
    }
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
    echo nano_admin_header('Sign in', '', false, 'nano-cms-admin-login');
    ?>
<h1>Sign in to <?= nano_admin_e($site_name) ?></h1>
<?php if ($error !== null): ?>
<?= nano_admin_flash('error', $error) ?>
<?php endif; ?>
<form class="nano-cms-admin-form nano-cms-admin-form-login" method="post" autocomplete="off">
<?= nano_admin_csrf_field() ?>
<label>Password<input type="password" name="password" autocomplete="current-password" autofocus required></label>
<div class="nano-cms-admin-form-actions">
<button type="submit" class="nano-cms-admin-button nano-cms-admin-button-primary">Sign in</button>
</div>
</form>
<?= nano_admin_render_footer() ?>
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

$draft_count     = count(array_filter($all_posts, static fn(array $p): bool => !empty($p['frontmatter']['draft'])));
$published_count = count($all_posts) - $draft_count;
$category_count  = count($categories);
$install_exists  = is_file(dirname(__DIR__) . '/install.php');

$health          = nano_admin_health_checks();
$health_problems = array_values(array_filter($health, static fn(array $c): bool => !$c['ok']));

echo nano_admin_header('Posts', 'posts');

if (!empty($health_problems)):
?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-error">
  <p><strong>Health check found <?= count($health_problems) ?> problem<?= count($health_problems) === 1 ? '' : 's' ?>.</strong> See the Health check panel at the bottom of this page. This usually means an upgrade did not finish - re-extract the affected files.</p>
</div>
<?php endif;

if ($install_exists):
?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-error">
  <p><strong>install.php is still on the server.</strong> Setup is complete, so the installer should be removed now. Leaving it in place is a small fingerprinting risk and could let someone reconfigure the blog if your config files were ever wiped.</p>
  <form method="post" action="../install.php" style="margin-top:0.5rem">
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-danger" onclick="return confirm('Delete install.php from the server now?')">Delete install.php now</button>
  </form>
</div>
<?php endif; ?>
<?php if ($flash !== null): ?>
<?= nano_admin_flash($flash[0], $flash[1]) ?>
<?php endif; ?>

<section class="nano-cms-admin-stats">
  <div class="nano-cms-admin-stat">
    <div class="nano-cms-admin-stat-value"><?= (int)$published_count ?></div>
    <div class="nano-cms-admin-stat-label">Published posts</div>
  </div>
  <div class="nano-cms-admin-stat">
    <div class="nano-cms-admin-stat-value"><?= (int)$draft_count ?></div>
    <div class="nano-cms-admin-stat-label">Draft posts</div>
  </div>
  <div class="nano-cms-admin-stat">
    <div class="nano-cms-admin-stat-value"><?= (int)$category_count ?></div>
    <div class="nano-cms-admin-stat-label">Categories</div>
  </div>
</section>

<form class="nano-cms-admin-toolbar" method="get">
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
  <noscript><button type="submit" class="nano-cms-admin-button nano-cms-admin-button-sm">Apply</button></noscript>
  <a class="nano-cms-admin-button nano-cms-admin-button-primary nano-cms-admin-toolbar-spacer" href="edit.php">New post</a>
</form>

<?php if (empty($posts)): ?>
<?php if (empty($all_posts)): ?>
<div class="nano-cms-admin-empty">
  <p>No posts yet. Welcome to Nano CMS.</p>
  <p><a class="nano-cms-admin-button nano-cms-admin-button-primary" href="edit.php">Create your first post</a></p>
</div>
<?php else: ?>
<div class="nano-cms-admin-empty"><p>No posts match the current filter.</p></div>
<?php endif; ?>
<?php else: ?>
<table class="nano-cms-admin-table">
<thead>
<tr><th>Title</th><th>Date</th><th>Updated</th><th>Category</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($posts as $p): $fm = $p['frontmatter']; ?>
<tr>
<td>
  <a href="edit.php?slug=<?= nano_admin_e((string)$fm['slug']) ?>"><?= nano_admin_e((string)$fm['title']) ?></a>
<?php if (!empty($fm['draft'])): ?> <span class="nano-cms-admin-pill nano-cms-admin-pill-draft">Draft</span><?php endif; ?>
</td>
<td><?= nano_admin_e((string)$fm['date']) ?></td>
<td><?= nano_admin_e((string)($fm['updated'] ?? '')) ?></td>
<td><?= nano_admin_e((string)($fm['category'] ?? '')) ?></td>
<td class="nano-cms-admin-row-actions">
  <a href="edit.php?slug=<?= nano_admin_e((string)$fm['slug']) ?>">Edit</a>
  <form method="post" action="?action=delete" onsubmit="return confirm('Delete &quot;<?= nano_admin_e((string)$fm['title']) ?>&quot;? This cannot be undone.');">
    <?= nano_admin_csrf_field() ?>
    <input type="hidden" name="slug" value="<?= nano_admin_e((string)$fm['slug']) ?>">
    &middot; <button type="submit" class="nano-cms-admin-link-danger">Delete</button>
  </form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<section class="nano-cms-admin-section">
  <h2 class="nano-cms-admin-section-title">Health check</h2>
  <table class="nano-cms-admin-table">
    <tbody>
<?php foreach ($health as $c): ?>
      <tr>
        <td style="width:1%;white-space:nowrap"><strong style="color:<?= $c['ok'] ? 'var(--nano-cms-admin-success-fg)' : 'var(--nano-cms-admin-danger)' ?>"><?= $c['ok'] ? 'OK' : 'CHECK' ?></strong></td>
        <td style="white-space:nowrap"><?= nano_admin_e($c['label']) ?></td>
        <td><?= nano_admin_e($c['detail']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <p class="nano-cms-admin-help">Nano CMS v<?= nano_admin_e(NANO_ADMIN_VERSION) ?> &middot; running PHP <?= nano_admin_e(PHP_VERSION) ?>. Check this panel after every upgrade.</p>
</section>

<?= nano_admin_render_footer() ?>
