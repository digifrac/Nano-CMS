<?php
/**
 * Admin dashboard. Quick stats, health check, recent posts, and links to
 * common tasks. The post list itself lives on index.php; this page is the
 * landing screen and mirrors the Nano Cart dashboard so the two admins are
 * laid out identically.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';

nano_admin_assert_https();

if (!nano_admin_config_exists()) {
    header('Location: setup.php');
    exit;
}

nano_admin_version_check();
nano_admin_require_login();

require_once __DIR__ . '/posts.php';

$cfg = nano_admin_load_config();

$all_posts       = nano_admin_list_posts(true);
$draft_count     = count(array_filter($all_posts, static fn(array $p): bool => !empty($p['frontmatter']['draft'])));
$published_count = count($all_posts) - $draft_count;
$categories      = nano_admin_categories();
$category_count  = count($categories);
$base_url        = rtrim((string)($cfg['base_url'] ?? ''), '/');
$install_exists  = is_file(dirname(__DIR__) . '/install.php');

// Recent posts: newest by updated date (falling back to publish date).
$recent = $all_posts;
usort($recent, static function (array $a, array $b): int {
    $ka = (string)($a['frontmatter']['updated'] ?? $a['frontmatter']['date'] ?? '');
    $kb = (string)($b['frontmatter']['updated'] ?? $b['frontmatter']['date'] ?? '');
    return strcmp($kb, $ka);
});
$recent = array_slice($recent, 0, 5);

$health          = nano_admin_health_checks();
$health_problems = array_values(array_filter($health, static fn(array $c): bool => !$c['ok']));

echo nano_admin_header('Dashboard', 'dashboard');

if (!empty($health_problems)):
?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-error">
  <p><strong>Health check found <?= count($health_problems) ?> problem<?= count($health_problems) === 1 ? '' : 's' ?>.</strong> See the Health check panel below. This usually means an upgrade did not finish - re-extract the affected files.</p>
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

<section class="nano-cms-admin-section">
  <h2 class="nano-cms-admin-section-title">Recent posts</h2>
<?php if (empty($recent)): ?>
  <p class="nano-cms-admin-empty">No posts yet. <a href="edit.php">Create your first post.</a></p>
<?php else: ?>
  <table class="nano-cms-admin-table">
    <thead><tr><th>Title</th><th>Category</th><th>Date</th><th>Updated</th></tr></thead>
    <tbody>
<?php foreach ($recent as $p): $fm = $p['frontmatter']; ?>
    <tr>
      <td>
        <a href="edit.php?slug=<?= nano_admin_e((string)$fm['slug']) ?>"><?= nano_admin_e((string)$fm['title']) ?></a>
<?php if (!empty($fm['draft'])): ?> <span class="nano-cms-admin-pill nano-cms-admin-pill-draft">Draft</span><?php endif; ?>
      </td>
      <td><?= nano_admin_e((string)($fm['category'] ?? '')) ?></td>
      <td><?= nano_admin_e((string)($fm['date'] ?? '')) ?></td>
      <td><?= nano_admin_e((string)($fm['updated'] ?? '')) ?></td>
    </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section class="nano-cms-admin-section">
  <h2 class="nano-cms-admin-section-title">Quick actions</h2>
  <div class="nano-cms-admin-quick-actions">
    <a class="nano-cms-admin-button nano-cms-admin-button-primary" href="edit.php">New post</a>
    <a class="nano-cms-admin-button" href="index.php">All posts</a>
    <a class="nano-cms-admin-button" href="media.php">Media</a>
    <a class="nano-cms-admin-button" href="categories.php">Categories</a>
    <a class="nano-cms-admin-button" href="settings.php">Edit settings</a>
<?php if ($base_url !== ''): ?>
    <a class="nano-cms-admin-button nano-cms-admin-button-secondary" href="<?= nano_admin_e($base_url) ?>/" target="_blank" rel="noopener">View blog</a>
<?php endif; ?>
  </div>
</section>

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
