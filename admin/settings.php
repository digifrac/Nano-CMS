<?php
/**
 * Admin settings page. Lets the operator change site-level layout
 * options that don't fit in the per-post editor or media manager.
 *
 * Currently exposes cards_per_row only. Future settings can grow here
 * without adding new admin pages.
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

$cfg = nano_admin_load_config();
$site_name = (string)($cfg['site_name'] ?? 'Nano CMS');
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_admin_require_csrf();
    $cats_raw = (int)($_POST['categories_per_row'] ?? 0);
    $arts_raw = (int)($_POST['articles_per_row'] ?? 0);
    $thumb_w = (int)($_POST['thumb_width'] ?? 0);
    $thumb_h = (int)($_POST['thumb_height'] ?? 0);
    $cat_thumb_w = (int)($_POST['cat_thumb_width'] ?? 0);
    $cat_thumb_h = (int)($_POST['cat_thumb_height'] ?? 0);
    $errors = [];
    if ($cats_raw !== 3 && $cats_raw !== 4) $errors[] = 'Categories per row must be 3 or 4.';
    if ($arts_raw !== 3 && $arts_raw !== 4) $errors[] = 'Articles per row must be 3 or 4.';
    if ($thumb_w < 100 || $thumb_w > 2400) $errors[] = 'Article thumbnail width must be between 100 and 2400.';
    if ($thumb_h < 100 || $thumb_h > 2400) $errors[] = 'Article thumbnail height must be between 100 and 2400.';
    if ($cat_thumb_w < 100 || $cat_thumb_w > 2400) $errors[] = 'Category image width must be between 100 and 2400.';
    if ($cat_thumb_h < 100 || $cat_thumb_h > 2400) $errors[] = 'Category image height must be between 100 and 2400.';
    if (empty($errors)) {
        $cfg['categories_per_row'] = $cats_raw;
        $cfg['articles_per_row'] = $arts_raw;
        $cfg['thumb_width'] = $thumb_w;
        $cfg['thumb_height'] = $thumb_h;
        $cfg['cat_thumb_width'] = $cat_thumb_w;
        $cfg['cat_thumb_height'] = $cat_thumb_h;
        nano_admin_save_config($cfg);
        $flash = ['ok', 'Settings saved.'];
    } else {
        $flash = ['error', implode(' ', $errors)];
    }
}

$categories_per_row = (int)($cfg['categories_per_row'] ?? 3);
if ($categories_per_row !== 3 && $categories_per_row !== 4) {
    $categories_per_row = 3;
}
$articles_per_row = (int)($cfg['articles_per_row'] ?? 3);
if ($articles_per_row !== 3 && $articles_per_row !== 4) {
    $articles_per_row = 3;
}
$thumb_width = (int)($cfg['thumb_width'] ?? 600);
if ($thumb_width < 100 || $thumb_width > 2400) $thumb_width = 600;
$thumb_height = (int)($cfg['thumb_height'] ?? 400);
if ($thumb_height < 100 || $thumb_height > 2400) $thumb_height = 400;
$cat_thumb_width = (int)($cfg['cat_thumb_width'] ?? $thumb_width);
if ($cat_thumb_width < 100 || $cat_thumb_width > 2400) $cat_thumb_width = $thumb_width;
$cat_thumb_height = (int)($cfg['cat_thumb_height'] ?? $thumb_height);
if ($cat_thumb_height < 100 || $cat_thumb_height > 2400) $cat_thumb_height = $thumb_height;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings - <?= nano_admin_e($site_name) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bar">
  <h1>Settings</h1>
  <div>
    <a href="index.php">All posts</a>
    | <a href="media.php">Media</a>
    | <a href="categories.php">Categories</a>
    | <a href="help.php">Help</a>
    | <a href="index.php?action=logout">Sign out</a>
  </div>
</div>

<?php if ($flash !== null): ?>
<div class="flash-<?= nano_admin_e($flash[0]) ?>"><?= nano_admin_e($flash[1]) ?></div>
<?php endif; ?>

<form method="post" class="settings-form">
  <?= nano_admin_csrf_field() ?>
  <label>Categories per row
    <select name="categories_per_row">
      <option value="3"<?= $categories_per_row === 3 ? ' selected' : '' ?>>3 (default)</option>
      <option value="4"<?= $categories_per_row === 4 ? ' selected' : '' ?>>4</option>
    </select>
  </label>
  <p class="help">How many category cards appear per row on the blog homepage.</p>

  <label>Articles per row
    <select name="articles_per_row">
      <option value="3"<?= $articles_per_row === 3 ? ' selected' : '' ?>>3 (default)</option>
      <option value="4"<?= $articles_per_row === 4 ? ' selected' : '' ?>>4</option>
    </select>
  </label>
  <p class="help">How many article cards appear per row on category archive pages.</p>

  <h2>Article thumbnails</h2>
  <p class="help">Hero images uploaded through the media manager get a smaller, pre-cropped thumbnail saved alongside them. Article cards use the thumbnail. The dimensions also drive the card display aspect ratio, so changes show up immediately on the public side. Thumbnail FILE size is regenerated only on future uploads.</p>
  <label>Article thumbnail width (px)
    <input type="number" name="thumb_width" min="100" max="2400" step="1" value="<?= (int)$thumb_width ?>" required>
  </label>
  <label>Article thumbnail height (px)
    <input type="number" name="thumb_height" min="100" max="2400" step="1" value="<?= (int)$thumb_height ?>" required>
  </label>

  <h2>Category images</h2>
  <p class="help">Category cards on the homepage can have their own hero image (managed on the Categories page). These dimensions are independent of article thumbnails so the two grids can be tuned separately.</p>
  <label>Category image width (px)
    <input type="number" name="cat_thumb_width" min="100" max="2400" step="1" value="<?= (int)$cat_thumb_width ?>" required>
  </label>
  <label>Category image height (px)
    <input type="number" name="cat_thumb_height" min="100" max="2400" step="1" value="<?= (int)$cat_thumb_height ?>" required>
  </label>

  <button type="submit">Save settings</button>
</form>

<?= nano_admin_render_footer() ?>
</body>
</html>
