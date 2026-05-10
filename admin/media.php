<?php
/**
 * Admin media manager page entry. Upload, browse, delete files in
 * /media/. Helpers live in admin/media-lib.php so they can be reused
 * (and smoke-tested) without triggering this file's auth gates.
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
    if ($action === 'upload') {
        $result = nano_admin_media_save_upload($_FILES['image'] ?? []);
        $flash = $result['ok']
            ? ['ok', 'Uploaded ' . $result['filename'] . '.']
            : ['error', $result['error'] ?? 'Upload failed.'];
    } elseif ($action === 'delete') {
        $name = (string)($_POST['filename'] ?? '');
        $flash = nano_admin_media_delete($name)
            ? ['ok', 'Deleted ' . $name . '.']
            : ['error', 'Could not delete ' . $name . '.'];
    }
}

$items = nano_admin_list_media();
$used = nano_admin_media_used_set();
$base_url = rtrim((string)($cfg['base_url'] ?? ''), '/');
$reencoder_available = extension_loaded('gd') || extension_loaded('imagick');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Media - <?= nano_admin_e($site_name) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bar">
  <h1>Media</h1>
  <div>
    <a href="index.php">All posts</a> | <a href="categories.php">Categories</a> | <a href="settings.php">Settings</a> | <a href="help.php">Help</a> | <a href="index.php?action=logout">Sign out</a>
  </div>
</div>

<?php if ($flash !== null): ?>
<div class="flash-<?= nano_admin_e($flash[0]) ?>"><?= nano_admin_e($flash[1]) ?></div>
<?php endif; ?>

<?php if (!$reencoder_available): ?>
<div class="warn"><strong>Uploads disabled:</strong> neither the GD nor the Imagick PHP extension is loaded on this server. Re-encoding through one of them is required to safely accept uploads. Browsing and deletion still work.</div>
<?php endif; ?>

<form class="upload" method="post" action="?action=upload" enctype="multipart/form-data">
  <?= nano_admin_csrf_field() ?>
  <input type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp" <?= $reencoder_available ? 'required' : 'disabled' ?>>
  <button type="submit" <?= $reencoder_available ? '' : 'disabled' ?>>Upload</button>
  <span class="help">jpg/png/gif/webp, up to 5 MB. Files are re-encoded on save.</span>
</form>

<?php if (empty($items)): ?>
<p class="empty">No media uploaded yet.</p>
<?php else: ?>
<div class="media-grid">
<?php foreach ($items as $it): $name = $it['filename']; $is_used = isset($used[strtolower($name)]); ?>
  <div class="tile">
    <img src="<?= nano_admin_e($base_url . '/media/' . $name) ?>" alt="" loading="lazy">
    <div class="meta">
      <strong><?= nano_admin_e($name) ?></strong>
      <?php if (!$is_used): ?><span class="unused-tag">unused</span><?php endif; ?>
      <br><?= number_format($it['bytes'] / 1024, 1) ?> KB &middot; <?= nano_admin_e(date('Y-m-d', $it['mtime'])) ?>
    </div>
    <div class="actions">
      <button type="button" class="js-copy" data-clip="![](<?= nano_admin_e($name) ?>)" title="Copy Markdown image syntax for the post body">Copy MD</button>
      <button type="button" class="js-copy" data-clip="<?= nano_admin_e($name) ?>" title="Copy just the filename for the frontmatter image: field">Copy name</button>
      <form method="post" action="?action=delete" onsubmit="return confirm('Delete <?= nano_admin_e($name) ?>?');">
        <?= nano_admin_csrf_field() ?>
        <input type="hidden" name="filename" value="<?= nano_admin_e($name) ?>">
        <button type="submit" class="danger">Delete</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('button.js-copy').forEach(function (b) {
  b.addEventListener('click', function () {
    var text = b.getAttribute('data-clip');
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () {
        var orig = b.textContent;
        b.textContent = 'Copied!';
        setTimeout(function () { b.textContent = orig; }, 1200);
      });
    } else {
      window.prompt('Copy this:', text);
    }
  });
});
</script>
<?= nano_admin_render_footer() ?>
</body>
</html>
