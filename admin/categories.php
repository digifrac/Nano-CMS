<?php
/**
 * Admin category manager. Lists every category (managed records + any
 * category used by a post), and lets the operator create, edit, and delete
 * managed category records (name, description, hero image). Membership is
 * still driven by each post's `category:` field - a record is metadata only.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/media-lib.php';
require_once __DIR__ . '/categories-lib.php';

nano_admin_assert_https();

if (!nano_admin_config_exists()) {
    header('Location: setup.php');
    exit;
}

nano_admin_version_check();
nano_admin_require_login();

$cfg = nano_admin_load_config();
$base_url = rtrim((string)($cfg['base_url'] ?? ''), '/');
$flash = null;

// Homepage grid is two rows of category cards: 6 at 3-per-row, 8 at 4-per-row.
$cats_per_row = ((int)($cfg['categories_per_row'] ?? 3) === 4) ? 4 : 3;
$cap = $cats_per_row * 2;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'slots') {
    nano_admin_require_csrf();
    $slots_in = is_array($_POST['slot'] ?? null) ? $_POST['slot'] : [];
    $map = [];
    for ($i = 1; $i <= $cap; $i++) {
        $map[$i] = (string)($slots_in[$i] ?? '');
    }
    $flash = nano_admin_set_homepage_slots($map, $cap)
        ? ['ok', 'Homepage slots saved.']
        : ['error', 'Could not save one or more categories.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'delete') {
    nano_admin_require_csrf();
    $slug = nano_admin_safe_slug((string)($_POST['slug'] ?? ''));
    $counts = nano_admin_category_post_counts();
    $in_use = (int)($counts[$slug] ?? 0);
    if ($slug === '') {
        $flash = ['error', 'Cannot delete: category missing.'];
    } elseif (nano_admin_delete_category($slug)) {
        $flash = $in_use > 0
            ? ['warn', 'Removed the metadata for "' . $slug . '". The category still appears because ' . $in_use . ' post' . ($in_use === 1 ? '' : 's') . ' still use it - recategorise those posts to remove it fully.']
            : ['ok', 'Category "' . $slug . '" deleted.'];
    } else {
        $flash = ['error', 'No managed record to delete for "' . $slug . '".'];
    }
}

if ($flash === null && (string)($_GET['msg'] ?? '') !== '') {
    $flash = match ((string)$_GET['msg']) {
        'saved'   => ['ok', 'Category saved.'],
        'created' => ['ok', 'Category created.'],
        default   => null,
    };
}

$categories = nano_admin_all_categories();
$media_dir = NANO_CONTENT_PATH . '/media';

// Which category currently holds each homepage slot (first wins on any clash).
$slot_holder = [];
foreach ($categories as $c) {
    $s = $c['homepage_slot'];
    if ($s !== null && $s >= 1 && $s <= $cap && !isset($slot_holder[$s])) {
        $slot_holder[$s] = (string)$c['slug'];
    }
}
$slot_by_slug = array_flip($slot_holder);

$thumb_for = static function (string $image) use ($base_url, $media_dir): ?string {
    if ($image === '') {
        return null;
    }
    $thumb = nano_admin_media_thumb_filename($image);
    return is_file($media_dir . '/' . $thumb)
        ? $base_url . '/media/' . $thumb
        : $base_url . '/media/' . $image;
};

echo nano_admin_header('Categories', 'categories');
?>
<?php if ($flash !== null): ?>
<?= nano_admin_flash($flash[0], $flash[1]) ?>
<?php endif; ?>

<div class="nano-cms-admin-actions">
  <a class="nano-cms-admin-button nano-cms-admin-button-primary" href="category-edit.php">New category</a>
</div>

<p class="nano-cms-admin-help">A category is anything a post's <code>category:</code> field points at. Give it a record here to set a proper name, description, and hero image for its page and homepage card. Categories without a record still work - they just use a name derived from the slug.</p>

<?php if (!empty($categories)): ?>
<section class="nano-cms-admin-section">
  <h2 class="nano-cms-admin-section-title">Homepage slots</h2>
  <p class="nano-cms-admin-help">The blog homepage shows up to <strong><?= (int)$cap ?></strong> category cards
    (two rows of <?= (int)$cats_per_row ?>, set by Categories per row in Settings). Pick which category fills
    each slot, in order. Categories left out still appear in the off-canvas <em>Categories</em> menu.
    Leave every slot empty to show all categories (the default).</p>
  <form method="post" action="?action=slots" class="nano-cms-admin-form">
    <?= nano_admin_csrf_field() ?>
    <div class="nano-cms-admin-slots">
<?php for ($i = 1; $i <= $cap; $i++): $held = $slot_holder[$i] ?? ''; ?>
      <label>Slot <?= (int)$i ?>
        <select name="slot[<?= (int)$i ?>]">
          <option value="">&mdash; Empty &mdash;</option>
<?php foreach ($categories as $c): $cslug = (string)$c['slug']; ?>
          <option value="<?= nano_admin_e($cslug) ?>"<?= $cslug === $held ? ' selected' : '' ?>><?= nano_admin_e((string)$c['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
<?php endfor; ?>
    </div>
    <div class="nano-cms-admin-form-actions">
      <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-primary">Save homepage slots</button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if (empty($categories)): ?>
<div class="nano-cms-admin-empty"><p>No categories yet. Create one, or write a post and give it a category.</p></div>
<?php else: ?>
<table class="nano-cms-admin-table">
<thead>
<tr><th>Image</th><th>Name</th><th>Slug</th><th>Order</th><th>Homepage</th><th>Posts</th><th>Record</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($categories as $c): $thumb = $thumb_for($c['image']); ?>
<tr>
<td style="width:96px">
<?php if ($thumb !== null): ?>
  <img src="<?= nano_admin_e($thumb) ?>" alt="" style="width:84px;height:56px;object-fit:cover;border-radius:5px;display:block">
<?php else: ?>
  <span class="nano-cms-admin-help" style="margin:0">&mdash;</span>
<?php endif; ?>
</td>
<td><?= nano_admin_e($c['name']) ?></td>
<td><code><?= nano_admin_e($c['slug']) ?></code></td>
<td><?php if ($c['sort_order'] !== null): ?><?= (int)$c['sort_order'] ?><?php else: ?><span class="nano-cms-admin-help" style="margin:0">&mdash;</span><?php endif; ?></td>
<td><?php if (isset($slot_by_slug[$c['slug']])): ?>Slot <?= (int)$slot_by_slug[$c['slug']] ?><?php else: ?><span class="nano-cms-admin-help" style="margin:0">&mdash;</span><?php endif; ?></td>
<td><?= (int)$c['count'] ?></td>
<td><?php if ($c['has_record']): ?><span class="nano-cms-admin-pill nano-cms-admin-pill-published">managed</span><?php else: ?><span class="nano-cms-admin-pill">derived</span><?php endif; ?></td>
<td class="nano-cms-admin-row-actions">
  <a href="category-edit.php?slug=<?= nano_admin_e($c['slug']) ?>"><?= $c['has_record'] ? 'Edit' : 'Add record' ?></a>
<?php if ($c['has_record']): ?>
  <form method="post" action="?action=delete" onsubmit="return confirm('Delete the managed record for &quot;<?= nano_admin_e($c['slug']) ?>&quot;?<?= $c['count'] > 0 ? ' The category will revert to a plain derived one (posts still use it).' : '' ?>');">
    <?= nano_admin_csrf_field() ?>
    <input type="hidden" name="slug" value="<?= nano_admin_e($c['slug']) ?>">
    &middot; <button type="submit" class="nano-cms-admin-link-danger">Delete</button>
  </form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?= nano_admin_render_footer() ?>
