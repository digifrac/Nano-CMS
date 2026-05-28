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
echo nano_admin_header('Media', 'media');
?>
<?php if ($flash !== null): ?>
<?= nano_admin_flash($flash[0], $flash[1]) ?>
<?php endif; ?>

<?php if (!$reencoder_available): ?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-warning"><strong>Uploads disabled:</strong> neither the GD nor the Imagick PHP extension is loaded on this server. Re-encoding through one of them is required to safely accept uploads. Browsing and deletion still work.</div>
<?php endif; ?>

<form class="nano-cms-admin-upload" method="post" action="?action=upload" enctype="multipart/form-data">
  <?= nano_admin_csrf_field() ?>
  <input type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp" <?= $reencoder_available ? 'required' : 'disabled' ?>>
  <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-primary" <?= $reencoder_available ? '' : 'disabled' ?>>Upload</button>
  <span class="nano-cms-admin-help">jpg/png/gif/webp, up to 5 MB. Files are re-encoded on save.</span>
</form>

<?php if (empty($items)): ?>
<div class="nano-cms-admin-empty"><p>No media uploaded yet.</p></div>
<?php else: ?>
<div class="nano-cms-admin-media-grid">
<?php foreach ($items as $it): $name = $it['filename']; $is_used = isset($used[strtolower($name)]); ?>
  <div class="nano-cms-admin-tile">
    <img src="<?= nano_admin_e($base_url . '/media/' . $name) ?>" alt="" loading="lazy">
    <div class="nano-cms-admin-tile-meta">
      <strong><?= nano_admin_e($name) ?></strong>
      <?php if (!$is_used): ?> <span class="nano-cms-admin-pill">unused</span><?php endif; ?>
      <br><?= number_format($it['bytes'] / 1024, 1) ?> KB &middot; <?= nano_admin_e(date('Y-m-d', $it['mtime'])) ?>
    </div>
    <div class="nano-cms-admin-tile-actions">
      <button type="button" class="js-copy nano-cms-admin-button nano-cms-admin-button-sm" data-clip="![](<?= nano_admin_e($name) ?>)" title="Copy Markdown image syntax for the post body">Copy MD</button>
      <button type="button" class="js-copy nano-cms-admin-button nano-cms-admin-button-sm" data-clip="<?= nano_admin_e($name) ?>" title="Copy just the filename for the frontmatter image: field">Copy name</button>
      <form method="post" action="?action=delete" onsubmit="return confirm('Delete <?= nano_admin_e($name) ?>?');">
        <?= nano_admin_csrf_field() ?>
        <input type="hidden" name="filename" value="<?= nano_admin_e($name) ?>">
        <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-danger nano-cms-admin-button-sm">Delete</button>
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
