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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Categories - <?= nano_admin_e($site_name) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bar">
  <h1>Categories</h1>
  <div>
    <a href="index.php">All posts</a>
    | <a href="media.php">Media</a>
    | <a href="settings.php">Settings</a>
    | <a href="licence.php">Licence</a>
    | <a href="help.php">Help</a>
    | <a href="index.php?action=logout">Sign out</a>
  </div>
</div>

<?php if ($flash !== null): ?>
<div class="flash-<?= nano_admin_e($flash[0]) ?>"><?= nano_admin_e($flash[1]) ?></div>
<?php endif; ?>

<?php if (!$reencoder_available): ?>
<div class="warn"><strong>Uploads disabled:</strong> GD or Imagick must be loaded to safely accept image uploads. Browsing still works.</div>
<?php endif; ?>

<p class="help">A category is anything used in a post's <code>category:</code> frontmatter. Attach an image here and it appears on the homepage card for that topic. Removing an image leaves the card text-only.</p>

<?php if (empty($categories)): ?>
<p class="empty">No categories yet. Create a post first.</p>
<?php else: ?>
<div class="category-image-list">
<?php foreach ($categories as $cat):
    $img_filename = nano_admin_find_category_image($cat);
    $img_url = $img_filename !== null ? $base_url . '/media/' . $img_filename : null;
?>
  <div class="category-image-row">
    <div class="category-image-thumb">
<?php if ($img_url !== null): ?>
      <img src="<?= nano_admin_e($img_url) ?>" alt="">
<?php else: ?>
      <span class="empty-thumb">No image</span>
<?php endif; ?>
    </div>
    <div class="category-image-meta">
      <strong><?= nano_admin_e(ucfirst(str_replace('-', ' ', $cat))) ?></strong>
      <small><?= nano_admin_e($cat) ?></small>
    </div>
    <form method="post" action="?action=upload" enctype="multipart/form-data" class="category-image-form">
      <?= nano_admin_csrf_field() ?>
      <input type="hidden" name="slug" value="<?= nano_admin_e($cat) ?>">
      <input type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp" <?= $reencoder_available ? 'required' : 'disabled' ?>>
      <button type="submit" <?= $reencoder_available ? '' : 'disabled' ?>><?= $img_url !== null ? 'Replace' : 'Upload' ?></button>
    </form>
<?php if ($img_url !== null): ?>
    <form method="post" action="?action=delete" class="category-image-remove" onsubmit="return confirm('Remove the image for <?= nano_admin_e($cat) ?>?');">
      <?= nano_admin_csrf_field() ?>
      <input type="hidden" name="slug" value="<?= nano_admin_e($cat) ?>">
      <button type="submit" class="danger">Remove</button>
    </form>
<?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?= nano_admin_render_footer() ?>
</body>
</html>
