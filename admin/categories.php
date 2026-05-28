<?php
/**
 * Admin categories page. Lists every category in use across the post
 * set and lets the operator attach (or remove) a hero image per
 * category. The image is displayed on each category card on the blog
 * homepage. Convention-only, no JSON metadata: the file's existence
 * at /media/category-<slug>.<ext> is the metadata.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/media-lib.php';

nano_admin_assert_https();

if (!nano_admin_config_exists()) {
    header('Location: setup.php');
    exit;
}

nano_admin_version_check();
nano_admin_require_login();

$cfg = nano_admin_load_config();
$site_name = (string)($cfg['site_name'] ?? 'Nano CMS');
$action = (string)($_GET['action'] ?? '');
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_admin_require_csrf();
    $slug = nano_admin_safe_slug((string)($_POST['slug'] ?? ''));
    if ($slug === '') {
        $flash = ['error', 'Missing or invalid category.'];
    } elseif ($action === 'upload') {
        $result = nano_admin_category_image_save_upload($slug, $_FILES['image'] ?? []);
        $flash = $result['ok']
            ? ['ok', 'Image saved for ' . $slug . '.']
            : ['error', $result['error'] ?? 'Upload failed.'];
    } elseif ($action === 'delete') {
        $flash = nano_admin_category_image_delete($slug)
            ? ['ok', 'Image removed for ' . $slug . '.']
            : ['error', 'No image to remove.'];
    }
}

$categories = nano_admin_categories();
$base_url = rtrim((string)($cfg['base_url'] ?? ''), '/');
$reencoder_available = extension_loaded('gd') || extension_loaded('imagick');
echo nano_admin_header('Categories', 'categories');
?>
<?php if ($flash !== null): ?>
<?= nano_admin_flash($flash[0], $flash[1]) ?>
<?php endif; ?>

<?php if (!$reencoder_available): ?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-warning"><strong>Uploads disabled:</strong> GD or Imagick must be loaded to safely accept image uploads. Browsing still works.</div>
<?php endif; ?>

<p class="nano-cms-admin-help">A category is anything used in a post's <code>category:</code> frontmatter. Attach an image here and it appears on the homepage card for that topic. Removing an image leaves the card text-only.</p>

<?php if (empty($categories)): ?>
<div class="nano-cms-admin-empty"><p>No categories yet. Create a post first.</p></div>
<?php else: ?>
<div class="nano-cms-admin-category-list">
<?php foreach ($categories as $cat):
    $img_filename = nano_admin_find_category_image($cat);
    $img_url = $img_filename !== null ? $base_url . '/media/' . $img_filename : null;
?>
  <div class="nano-cms-admin-category-row">
    <div class="nano-cms-admin-category-thumb">
<?php if ($img_url !== null): ?>
      <img src="<?= nano_admin_e($img_url) ?>" alt="">
<?php else: ?>
      <span class="empty-thumb">No image</span>
<?php endif; ?>
    </div>
    <div class="nano-cms-admin-category-meta">
      <strong><?= nano_admin_e(ucfirst(str_replace('-', ' ', $cat))) ?></strong>
      <small><?= nano_admin_e($cat) ?></small>
    </div>
    <form method="post" action="?action=upload" enctype="multipart/form-data" class="nano-cms-admin-category-form">
      <?= nano_admin_csrf_field() ?>
      <input type="hidden" name="slug" value="<?= nano_admin_e($cat) ?>">
      <input type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp" <?= $reencoder_available ? 'required' : 'disabled' ?>>
      <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-sm" <?= $reencoder_available ? '' : 'disabled' ?>><?= $img_url !== null ? 'Replace' : 'Upload' ?></button>
    </form>
<?php if ($img_url !== null): ?>
    <form method="post" action="?action=delete" onsubmit="return confirm('Remove this category image?');">
      <?= nano_admin_csrf_field() ?>
      <input type="hidden" name="slug" value="<?= nano_admin_e($cat) ?>">
      <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-danger nano-cms-admin-button-sm">Remove</button>
    </form>
<?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?= nano_admin_render_footer() ?>
