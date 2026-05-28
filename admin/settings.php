<?php
/**
 * Admin settings page. The single place to manage site-level config
 * after setup: base URL, site name, author/publisher (used in article
 * JSON-LD), posts per page, grid columns, and thumbnail sizes. All of
 * these were previously only settable in the setup wizard.
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
    $new_site_name = trim((string)($_POST['site_name'] ?? ''));
    $new_base_url  = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
    $new_author = trim((string)($_POST['author'] ?? ''));
    $new_publisher_name = trim((string)($_POST['publisher_name'] ?? ''));
    $new_publisher_logo = trim((string)($_POST['publisher_logo'] ?? ''));
    $new_posts_per_page = (int)($_POST['posts_per_page'] ?? 0);
    $cats_raw = (int)($_POST['categories_per_row'] ?? 0);
    $arts_raw = (int)($_POST['articles_per_row'] ?? 0);
    $thumb_w = (int)($_POST['thumb_width'] ?? 0);
    $thumb_h = (int)($_POST['thumb_height'] ?? 0);
    $cat_thumb_w = (int)($_POST['cat_thumb_width'] ?? 0);
    $cat_thumb_h = (int)($_POST['cat_thumb_height'] ?? 0);
    $card_image_bg = trim((string)($_POST['card_image_bg'] ?? ''));
    $cat_image_bg  = trim((string)($_POST['cat_image_bg'] ?? ''));
    $errors = [];
    if ($new_site_name === '' || mb_strlen($new_site_name) > 80) {
        $errors[] = 'Site name must be 1-80 characters.';
    }
    if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~!\$&\'()*+,;=:@%/-]*)?$#', $new_base_url)) {
        $errors[] = 'Base URL must be a plain http(s) URL like https://example.com/blog.';
    }
    if ($new_author === '') $errors[] = 'Author name is required.';
    if ($new_publisher_name === '') $errors[] = 'Publisher name is required.';
    if ($new_publisher_logo !== '' && !preg_match('#^https?://\S+$#', $new_publisher_logo)) {
        $errors[] = 'Publisher logo must be a valid http(s) URL or left blank.';
    }
    if ($new_posts_per_page < 1 || $new_posts_per_page > 50) {
        $errors[] = 'Posts per page must be between 1 and 50.';
    }
    if ($cats_raw !== 3 && $cats_raw !== 4) $errors[] = 'Categories per row must be 3 or 4.';
    if ($arts_raw !== 3 && $arts_raw !== 4) $errors[] = 'Articles per row must be 3 or 4.';
    if ($thumb_w < 100 || $thumb_w > 2400) $errors[] = 'Article thumbnail width must be between 100 and 2400.';
    if ($thumb_h < 100 || $thumb_h > 2400) $errors[] = 'Article thumbnail height must be between 100 and 2400.';
    if ($cat_thumb_w < 100 || $cat_thumb_w > 2400) $errors[] = 'Category image width must be between 100 and 2400.';
    if ($cat_thumb_h < 100 || $cat_thumb_h > 2400) $errors[] = 'Category image height must be between 100 and 2400.';
    $hex_re = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
    if ($card_image_bg !== '' && !preg_match($hex_re, $card_image_bg)) $errors[] = 'Article card image background must be a hex colour like #ffffff, or left blank.';
    if ($cat_image_bg !== '' && !preg_match($hex_re, $cat_image_bg)) $errors[] = 'Category image background must be a hex colour like #ffffff, or left blank.';
    if (empty($errors)) {
        $cfg['site_name'] = $new_site_name;
        $cfg['base_url'] = $new_base_url;
        $cfg['author'] = $new_author;
        $cfg['publisher_name'] = $new_publisher_name;
        $cfg['publisher_logo'] = $new_publisher_logo;
        $cfg['posts_per_page'] = $new_posts_per_page;
        $cfg['categories_per_row'] = $cats_raw;
        $cfg['articles_per_row'] = $arts_raw;
        $cfg['thumb_width'] = $thumb_w;
        $cfg['thumb_height'] = $thumb_h;
        $cfg['cat_thumb_width'] = $cat_thumb_w;
        $cfg['cat_thumb_height'] = $cat_thumb_h;
        $cfg['card_image_bg'] = $card_image_bg;
        $cfg['cat_image_bg'] = $cat_image_bg;
        nano_admin_save_config($cfg);
        $site_name = $new_site_name; // refresh the page-title variable
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
$base_url = rtrim((string)($cfg['base_url'] ?? ''), '/');
$author = (string)($cfg['author'] ?? '');
$publisher_name = (string)($cfg['publisher_name'] ?? '');
$publisher_logo = (string)($cfg['publisher_logo'] ?? '');
$posts_per_page = (int)($cfg['posts_per_page'] ?? 10);
if ($posts_per_page < 1 || $posts_per_page > 50) $posts_per_page = 10;
$card_image_bg = (string)($cfg['card_image_bg'] ?? '');
$cat_image_bg = (string)($cfg['cat_image_bg'] ?? '');
echo nano_admin_header('Settings', 'settings');
?>
<?php if ($flash !== null): ?>
<?= nano_admin_flash($flash[0], $flash[1]) ?>
<?php endif; ?>

<form method="post" class="nano-cms-admin-form">
  <?= nano_admin_csrf_field() ?>

  <h2>Site</h2>
  <label>Site name
    <input type="text" name="site_name" value="<?= nano_admin_e($site_name) ?>" maxlength="80" required>
  </label>
  <p class="nano-cms-admin-help">Shown in the <code>&lt;title&gt;</code> suffix, the RSS feed, and OpenGraph metadata.</p>

  <label>Base URL
    <input type="url" name="base_url" value="<?= nano_admin_e($base_url) ?>" placeholder="https://example.com/blog" required>
  </label>
  <p class="nano-cms-admin-help">The full public URL the blog is served at, e.g. <code>https://example.com/blog</code> (no trailing slash). Every post link, category page, image URL, the sitemap and the feed are built from this - if it points at the wrong path, category pages 404 and images break. <strong>This is the value to fix if links are dropping the <code>/blog</code> path.</strong></p>

  <label>Author name
    <input type="text" name="author" value="<?= nano_admin_e($author) ?>" required>
  </label>

  <label>Publisher name
    <input type="text" name="publisher_name" value="<?= nano_admin_e($publisher_name) ?>" required>
  </label>

  <label>Publisher logo URL (optional)
    <input type="url" name="publisher_logo" value="<?= nano_admin_e($publisher_logo) ?>" placeholder="https://example.com/logo.png">
  </label>
  <p class="nano-cms-admin-help">Used in each post's article structured data (JSON-LD publisher logo) for search rich results. Leave blank if you don't have one.</p>

  <label>Posts per page
    <input type="number" name="posts_per_page" min="1" max="50" step="1" value="<?= (int)$posts_per_page ?>" required>
  </label>
  <p class="nano-cms-admin-help">How many posts appear per category-archive page (and in the RSS feed) before pagination.</p>

  <h2>Layout</h2>
  <label>Categories per row
    <select name="categories_per_row">
      <option value="3"<?= $categories_per_row === 3 ? ' selected' : '' ?>>3 (default)</option>
      <option value="4"<?= $categories_per_row === 4 ? ' selected' : '' ?>>4</option>
    </select>
  </label>
  <p class="nano-cms-admin-help">How many category cards appear per row on the blog homepage.</p>

  <label>Articles per row
    <select name="articles_per_row">
      <option value="3"<?= $articles_per_row === 3 ? ' selected' : '' ?>>3 (default)</option>
      <option value="4"<?= $articles_per_row === 4 ? ' selected' : '' ?>>4</option>
    </select>
  </label>
  <p class="nano-cms-admin-help">How many article cards appear per row on category archive pages.</p>

  <h2>Article thumbnails</h2>
  <p class="nano-cms-admin-help">Hero images uploaded through the media manager get a smaller, pre-cropped thumbnail saved alongside them. Article cards use the thumbnail. The dimensions also drive the card display aspect ratio, so changes show up immediately on the public side. Thumbnail FILE size is regenerated only on future uploads.</p>
  <p class="nano-cms-admin-help"><strong>Common sizes:</strong> <code>600&times;400</code> (3:2, default), <code>800&times;533</code> (3:2 retina), <code>640&times;360</code> (16:9 widescreen), <code>600&times;450</code> (4:3 squarer), <code>500&times;500</code> (1:1 square).</p>
  <fieldset class="nano-cms-admin-fieldset">
    <legend>Article thumbnail size</legend>
    <label>Width (px)
      <input type="number" name="thumb_width" min="100" max="2400" step="1" value="<?= (int)$thumb_width ?>" required>
    </label>
    <label>Height (px)
      <input type="number" name="thumb_height" min="100" max="2400" step="1" value="<?= (int)$thumb_height ?>" required>
    </label>
  </fieldset>
  <label>Article card image background
    <input type="text" name="card_image_bg" value="<?= nano_admin_e($card_image_bg) ?>" placeholder="#ffffff (blank = transparent)" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})">
  </label>
  <p class="nano-cms-admin-help">Hex colour shown behind article card and hero images - e.g. through the transparent areas of a PNG, or as a frame for non-filling images. Leave blank for transparent.</p>

  <h2>Category images</h2>
  <p class="nano-cms-admin-help">Category cards on the homepage can have their own hero image (managed on the Categories page). These dimensions are independent of article thumbnails so the two grids can be tuned separately.</p>
  <p class="nano-cms-admin-help"><strong>Common sizes:</strong> <code>600&times;400</code> (3:2, default), <code>800&times;533</code> (3:2 retina), <code>640&times;360</code> (16:9 widescreen), <code>600&times;450</code> (4:3 squarer), <code>500&times;500</code> (1:1 square).</p>
  <fieldset class="nano-cms-admin-fieldset">
    <legend>Category image size</legend>
    <label>Width (px)
      <input type="number" name="cat_thumb_width" min="100" max="2400" step="1" value="<?= (int)$cat_thumb_width ?>" required>
    </label>
    <label>Height (px)
      <input type="number" name="cat_thumb_height" min="100" max="2400" step="1" value="<?= (int)$cat_thumb_height ?>" required>
    </label>
  </fieldset>
  <label>Category image background
    <input type="text" name="cat_image_bg" value="<?= nano_admin_e($cat_image_bg) ?>" placeholder="#ffffff (blank = transparent)" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})">
  </label>
  <p class="nano-cms-admin-help">Hex colour shown behind category card and category-page banner images. Leave blank for transparent.</p>

  <div class="nano-cms-admin-form-actions">
    <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-primary">Save settings</button>
  </div>
</form>

<?= nano_admin_render_footer() ?>
