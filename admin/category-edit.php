<?php
/**
 * Admin category editor: create a new managed category record, or edit /
 * add a record for an existing (possibly post-derived) category.
 *
 *   GET  category-edit.php            -> new category
 *   GET  category-edit.php?slug=...   -> edit/add record for that slug
 *   POST                              -> validate + save, redirect to list
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

$get_slug = nano_admin_safe_slug((string)($_GET['slug'] ?? ''));
$is_new   = ($get_slug === '');
$existing = $is_new ? null : nano_admin_load_category($get_slug);

$form = [
    'slug'        => $is_new ? '' : $get_slug,
    'name'        => (string)($existing['name'] ?? ($is_new ? '' : ucfirst(str_replace('-', ' ', $get_slug)))),
    'description' => (string)($existing['description'] ?? ''),
    'image'       => (string)($existing['image'] ?? ''),
    'image_position' => (($existing['image_position'] ?? '') === 'right') ? 'right' : 'left',
    'sort_order'  => isset($existing['sort_order']) ? (string)(int)$existing['sort_order'] : '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_admin_require_csrf();
    $form['name']        = trim((string)($_POST['name'] ?? ''));
    $form['description'] = trim((string)($_POST['description'] ?? ''));
    $form['image']       = trim((string)($_POST['image'] ?? ''));
    $form['image_position'] = (($_POST['image_position'] ?? '') === 'right') ? 'right' : 'left';
    $form['sort_order'] = trim((string)($_POST['sort_order'] ?? ''));
    $slug = $is_new ? nano_admin_safe_slug((string)($_POST['slug'] ?? '')) : $get_slug;
    $form['slug'] = $slug;

    if ($slug === '') {
        $errors[] = 'Slug must contain at least one of [a-z0-9-].';
    }
    if ($form['name'] === '') {
        $errors[] = 'Name is required.';
    }
    if ($form['image'] !== '' && (str_contains($form['image'], '..') || !preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\.(?:jpg|jpeg|png|gif|webp)$#i', $form['image']))) {
        $errors[] = 'Image must be a media filename or folder path ending in .jpg, .jpeg, .png, .gif, or .webp.';
    }
    if ($is_new && $slug !== '' && nano_admin_load_category($slug) !== null) {
        $errors[] = 'A category record for "' . $slug . '" already exists.';
    }
    if ($form['sort_order'] !== '' && !preg_match('/^\d{1,6}$/', $form['sort_order'])) {
        $errors[] = 'Sort order must be a whole number (0 or higher), or left blank.';
    }

    if (empty($errors)) {
        nano_admin_save_category([
            'slug'        => $slug,
            'name'        => $form['name'],
            'description' => $form['description'],
            'image'       => $form['image'],
            'image_position' => $form['image_position'],
            'sort_order'  => $form['sort_order'],
        ]);
        header('Location: categories.php?msg=' . ($is_new ? 'created' : 'saved'));
        exit;
    }
}

// Media library for the image picker.
$media_dir = NANO_CONTENT_PATH . '/media';
$media_for_js = [];
foreach (nano_admin_media_all_images() as $path) {
    $thumb_name = nano_admin_media_thumb_filename($path);
    $media_for_js[] = [
        'name'  => $path,
        'thumb' => is_file($media_dir . '/' . $thumb_name) ? $base_url . '/media/' . $thumb_name : $base_url . '/media/' . $path,
    ];
}
$media_json = json_encode($media_for_js, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$media_base_json = json_encode($base_url . '/media', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';

echo nano_admin_header($is_new ? 'New category' : 'Edit category', 'categories');
?>
<?php if (!empty($errors)): ?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-error"><strong>Could not save:</strong>
<ul><?php foreach ($errors as $e): ?><li><?= nano_admin_e($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" class="nano-cms-admin-form" autocomplete="off">
<?= nano_admin_csrf_field() ?>

<label>Name
  <input type="text" name="name" value="<?= nano_admin_e($form['name']) ?>" maxlength="100" required>
</label>
<p class="nano-cms-admin-help">Display name shown as the category heading, on the homepage card, and in breadcrumbs.</p>

<?php if ($is_new): ?>
<label>Slug
  <input type="text" name="slug" value="<?= nano_admin_e($form['slug']) ?>" pattern="[a-z0-9\-]+" required>
</label>
<p class="nano-cms-admin-help">[a-z0-9-] only. This is the URL segment (<code><?= nano_admin_e($base_url) ?>/&lt;slug&gt;/</code>) and the value posts put in their <code>category:</code> field.</p>
<?php else: ?>
<label>Slug
  <input type="text" value="<?= nano_admin_e($form['slug']) ?>" readonly>
</label>
<p class="nano-cms-admin-help">The slug is fixed because posts reference it. To change it, recategorise the posts.</p>
<?php endif; ?>

<label>Description
  <textarea name="description" rows="5"><?= nano_admin_e($form['description']) ?></textarea>
</label>
<p class="nano-cms-admin-help">Shown in the category page header next to the image. Markdown is supported (headings, bold, lists). A plain-text version is used as the meta description for search results. Optional.</p>

<label for="cat-image">Hero image (in /media/)</label>
<div class="nano-cms-admin-imgfield">
  <input type="text" name="image" id="cat-image" value="<?= nano_admin_e($form['image']) ?>" placeholder="filename.jpg">
  <button type="button" class="nano-cms-admin-button nano-cms-admin-button-sm" data-pick="cat-image">Choose&hellip;</button>
</div>
<div class="nano-cms-admin-imgprev" data-prev-for="cat-image"></div>
<p class="nano-cms-admin-help">Shown on the homepage category card and the category page header. Optional.</p>

<label>Image position
  <select name="image_position">
    <option value="left"<?= $form['image_position'] === 'left' ? ' selected' : '' ?>>Left of the description</option>
    <option value="right"<?= $form['image_position'] === 'right' ? ' selected' : '' ?>>Right of the description</option>
  </select>
</label>
<p class="nano-cms-admin-help">Which side the banner image sits on (next to the description) in the category page header, on wide screens.</p>

<label>Sort order
  <input type="number" name="sort_order" min="0" max="999999" step="1" value="<?= nano_admin_e($form['sort_order']) ?>" placeholder="(blank)">
</label>
<p class="nano-cms-admin-help">Optional manual order for the homepage category grid - lower numbers come first. Leave blank to sort after the numbered ones, alphabetically by name.</p>

<div class="nano-cms-admin-form-actions">
  <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-primary"><?= $is_new ? 'Create category' : 'Save category' ?></button>
  <a href="categories.php" class="nano-cms-admin-button nano-cms-admin-button-secondary">Cancel</a>
</div>
</form>

<script>
window.NANO_MEDIA = <?= $media_json ?>;
window.NANO_MEDIA_BASE = <?= $media_base_json ?>;
</script>
<script>
(function () {
  var MEDIA = window.NANO_MEDIA || [];
  var MEDIA_BASE = window.NANO_MEDIA_BASE || '';
  function escAttr(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;'); }
  function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function openMediaPicker(onPick) {
    var bg = document.createElement('div'); bg.className = 'nano-cms-admin-pickbg';
    var m = document.createElement('div'); m.className = 'nano-cms-admin-pickmodal';
    m.innerHTML = '<div class="nano-cms-admin-pickhead"><strong>Select an image from the library</strong>'
      + '<button type="button" class="nano-cms-admin-pickclose" aria-label="Close">&times;</button></div>'
      + '<div class="nano-cms-admin-pickgrid"></div>'
      + '<div class="nano-cms-admin-pickfoot"><span>Upload new images in the Media tab.</span>'
      + '<a class="nano-cms-admin-button nano-cms-admin-button-secondary nano-cms-admin-button-sm" href="media.php" target="_blank" rel="noopener">Open Media manager &#8599;</a></div>';
    bg.appendChild(m); document.body.appendChild(bg);
    function close(){ if (bg.parentNode) bg.parentNode.removeChild(bg); document.removeEventListener('keydown', onKey); }
    function onKey(e){ if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    m.querySelector('.nano-cms-admin-pickclose').addEventListener('click', close);
    bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
    var grid = m.querySelector('.nano-cms-admin-pickgrid');
    if (!MEDIA.length) { grid.innerHTML = '<p class="nano-cms-admin-pickempty">No images in the library yet. Upload some in the Media tab.</p>'; }
    MEDIA.forEach(function (it) {
      var cell = document.createElement('button'); cell.type = 'button'; cell.className = 'nano-cms-admin-pickcell';
      cell.innerHTML = '<span class="nano-cms-admin-pickthumb"><img loading="lazy" src="' + escAttr(it.thumb) + '" alt=""></span>'
        + '<span class="nano-cms-admin-pickname">' + escHtml(it.name) + '</span>';
      cell.querySelector('img').addEventListener('error', function () { cell.querySelector('.nano-cms-admin-pickthumb').classList.add('nano-cms-admin-pickbroken'); });
      cell.addEventListener('click', function () { onPick(it); close(); });
      grid.appendChild(cell);
    });
  }

  function updatePreview(input) {
    var prev = document.querySelector('.nano-cms-admin-imgprev[data-prev-for="' + input.id + '"]');
    if (!prev) return;
    var v = input.value.trim();
    if (!v) { prev.innerHTML = ''; return; }
    var hit = null;
    for (var i = 0; i < MEDIA.length; i++) { if (MEDIA[i].name === v) { hit = MEDIA[i]; break; } }
    var src = hit ? hit.thumb : (MEDIA_BASE + '/' + v);
    prev.innerHTML = '<img src="' + escAttr(src) + '" alt=""><button type="button" class="nano-cms-admin-imgclear">Clear</button>';
    prev.querySelector('img').addEventListener('error', function () { this.style.display = 'none'; });
    prev.querySelector('.nano-cms-admin-imgclear').addEventListener('click', function () { input.value = ''; updatePreview(input); });
  }

  document.querySelectorAll('[data-pick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-pick'));
      if (!input) return;
      openMediaPicker(function (it) { input.value = it.name; updatePreview(input); });
    });
  });
  document.querySelectorAll('.nano-cms-admin-imgprev[data-prev-for]').forEach(function (prev) {
    var input = document.getElementById(prev.getAttribute('data-prev-for'));
    if (!input) return;
    input.addEventListener('input', function () { updatePreview(input); });
    updatePreview(input);
  });
})();
</script>
<?= nano_admin_render_footer() ?>
