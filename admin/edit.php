<?php
/**
 * Admin post editor: create + edit + save.
 *
 * GET  edit.php             -> blank new-post form
 * GET  edit.php?slug=...    -> edit existing post (404 if slug unknown)
 * POST edit.php             -> save (with CSRF, slug-reconcile, auto-updated,
 *                              regen of sitemap.xml/feed.xml)
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/media-lib.php';
require_once __DIR__ . '/../generators.php';

nano_admin_assert_https();

if (!nano_admin_config_exists()) {
    header('Location: setup.php');
    exit;
}

nano_admin_version_check();
nano_admin_require_login();

$cfg = nano_admin_load_config();
$site_name = (string)($cfg['site_name'] ?? 'Nano CMS');
$base_url = rtrim((string)($cfg['base_url'] ?? ''), '/');

$today = date('Y-m-d');
$errors = [];

/* ---- Load original (for edits) ---------------------------------------- */

$original_slug = nano_admin_safe_slug((string)($_GET['slug'] ?? ''));
$original_filepath = null;
$original_fm = null;
$original_body = '';
if ($original_slug !== '') {
    $original_filepath = nano_admin_find_post_by_slug($original_slug);
    if ($original_filepath === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "No post with slug '$original_slug'.";
        exit;
    }
    $loaded = nano_admin_read_post($original_filepath);
    $original_fm = $loaded['frontmatter'];
    $original_body = $loaded['body'];
}

$is_new = ($original_filepath === null);

/* ---- Defaults for the form ------------------------------------------- */

$form = [
    'title'       => (string)($original_fm['title'] ?? ''),
    'slug'        => (string)($original_fm['slug'] ?? ''),
    'date'        => (string)($original_fm['date'] ?? $today),
    'updated'     => (string)($original_fm['updated'] ?? ''),
    'category'    => (string)($original_fm['category'] ?? ''),
    'description' => (string)($original_fm['description'] ?? ''),
    'image'       => (string)($original_fm['image'] ?? ''),
    'image_alt'   => (string)($original_fm['image_alt'] ?? ''),
    'thumbnail'   => (string)($original_fm['thumbnail'] ?? ''),
    'draft'       => !empty($original_fm['draft']),
    'body'        => $original_body,
];

/* ---- Save handler ---------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_admin_require_csrf();

    foreach (['title', 'slug', 'date', 'updated', 'category', 'description', 'image', 'image_alt', 'thumbnail'] as $key) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $form['draft'] = !empty($_POST['draft']);
    $form['body'] = (string)($_POST['body'] ?? '');

    $intent_slug = nano_admin_safe_slug($form['slug']);
    if ($intent_slug === '') {
        $errors[] = 'Slug must contain at least one of [a-z0-9-].';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['date'])) {
        $errors[] = 'Date must be in YYYY-MM-DD format.';
    }
    if ($form['updated'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['updated'])) {
        $errors[] = 'Updated must be in YYYY-MM-DD format or left blank.';
    }
    foreach (['title', 'category', 'description'] as $required) {
        if ($form[$required] === '') {
            $errors[] = ucfirst($required) . ' is required.';
        }
    }

    // Auto-`updated`: bump to today when body or any other field changed,
    // unless the user has manually overridden the field on this save.
    if (empty($errors) && !$is_new) {
        $orig_updated = (string)($original_fm['updated'] ?? '');
        $user_touched_updated = ($form['updated'] !== $orig_updated);
        if (!$user_touched_updated) {
            $changed = false;
            foreach (['title', 'slug', 'date', 'category', 'description', 'image', 'image_alt', 'thumbnail'] as $key) {
                if ($form[$key] !== (string)($original_fm[$key] ?? '')) {
                    $changed = true;
                    break;
                }
            }
            if (!$changed && (!empty($original_fm['draft']) !== $form['draft'])) {
                $changed = true;
            }
            if (!$changed && rtrim($form['body'], "\n") !== rtrim($original_body, "\n")) {
                $changed = true;
            }
            if ($changed) {
                $form['updated'] = $today;
            }
        }
    }

    if (empty($errors)) {
        $fm_to_save = [
            'title'       => $form['title'],
            'slug'        => $intent_slug,
            'date'        => $form['date'],
            'updated'     => $form['updated'],
            'category'    => $form['category'],
            'description' => $form['description'],
            'image'       => $form['image'],
            'image_alt'   => $form['image_alt'],
            'thumbnail'   => $form['thumbnail'],
            'draft'       => $form['draft'],
        ];
        try {
            nano_admin_save_post($fm_to_save, $form['body'], $original_filepath);
            nano_regenerate_static();
            nano_admin_save_config($cfg); // bumps admin_version_last_used
            $save_action = ((string)($_POST['save_action'] ?? 'list')) === 'continue' ? 'continue' : 'list';
            if ($save_action === 'continue') {
                header('Location: edit.php?slug=' . rawurlencode($intent_slug) . '&msg=saved');
            } else {
                header('Location: index.php?msg=saved');
            }
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$flash = ((string)($_GET['msg'] ?? '')) === 'saved' ? 'Post saved.' : null;
$categories = nano_admin_categories();
$preview_url = (!$is_new && $base_url !== '')
    ? $base_url . '/' . rawurlencode((string)$original_fm['category']) . '/' . rawurlencode((string)$original_fm['slug']) . '/?preview=' . rawurlencode(nano_admin_csrf_token())
    : null;

// Media library for the image picker (Choose-from-library + body Image button).
$media_dir = NANO_CONTENT_PATH . '/media';
$media_for_js = [];
foreach (nano_admin_list_media() as $m) {
    $name = $m['filename'];
    $thumb_name = nano_admin_media_thumb_filename($name);
    $media_for_js[] = [
        'name'  => $name,
        'thumb' => is_file($media_dir . '/' . $thumb_name)
            ? $base_url . '/media/' . $thumb_name
            : $base_url . '/media/' . $name,
    ];
}
$media_json = json_encode($media_for_js, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$media_base_json = json_encode($base_url . '/media', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';

echo nano_admin_header($is_new ? 'New post' : 'Edit post', 'posts');
?>
<?php if (!empty($errors)): ?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-error"><strong>Could not save:</strong>
<ul><?php foreach ($errors as $e): ?><li><?= nano_admin_e($e) ?></li><?php endforeach; ?></ul>
</div>
<?php elseif ($flash !== null): ?>
<?= nano_admin_flash('ok', $flash) ?>
<?php endif; ?>

<form method="post" class="nano-cms-admin-form" autocomplete="off">
<?= nano_admin_csrf_field() ?>

<div class="nano-cms-admin-grid">
  <div class="full">
    <label>Title<input type="text" name="title" id="nano-title" value="<?= nano_admin_e($form['title']) ?>" maxlength="200" required></label>
    <p class="nano-cms-admin-counter"><span id="title-count">0</span> chars (search snippets cut off around 60)</p>
  </div>
  <div>
    <label>Slug<input type="text" name="slug" value="<?= nano_admin_e($form['slug']) ?>" pattern="[a-z0-9\-]+" required></label>
    <p class="nano-cms-admin-help">[a-z0-9-] only. Authoritative for the URL; filename will be reconciled on save.</p>
  </div>
  <div>
    <label>Category<input type="text" name="category" list="nano-categories" value="<?= nano_admin_e($form['category']) ?>" required></label>
    <datalist id="nano-categories">
<?php foreach ($categories as $c): ?>
      <option value="<?= nano_admin_e($c) ?>">
<?php endforeach; ?>
    </datalist>
  </div>
  <div>
    <label>Date<input type="date" name="date" value="<?= nano_admin_e($form['date']) ?>" required></label>
  </div>
  <div>
    <label>Updated (auto-set on save unless overridden)<input type="date" name="updated" value="<?= nano_admin_e($form['updated']) ?>"></label>
  </div>
  <div class="full">
    <label>Description (meta description, ~150 chars)<input type="text" name="description" value="<?= nano_admin_e($form['description']) ?>" maxlength="240" required></label>
    <p class="nano-cms-admin-counter"><span id="desc-count">0</span> chars</p>
  </div>
  <div>
    <label for="nano-image">Hero image (in /media/)</label>
    <div class="nano-cms-admin-imgfield">
      <input type="text" name="image" id="nano-image" value="<?= nano_admin_e($form['image']) ?>" placeholder="filename.jpg">
      <button type="button" class="nano-cms-admin-button nano-cms-admin-button-sm" data-pick="nano-image">Choose&hellip;</button>
    </div>
    <div class="nano-cms-admin-imgprev" data-prev-for="nano-image"></div>
    <p class="nano-cms-admin-help">Full-size image shown at the top of the single-post page. Pick from the media library or type a filename.</p>
  </div>
  <div>
    <label for="nano-thumbnail">Card thumbnail (optional)</label>
    <div class="nano-cms-admin-imgfield">
      <input type="text" name="thumbnail" id="nano-thumbnail" value="<?= nano_admin_e($form['thumbnail'] ?? '') ?>" placeholder="filename.jpg">
      <button type="button" class="nano-cms-admin-button nano-cms-admin-button-sm" data-pick="nano-thumbnail">Choose&hellip;</button>
    </div>
    <div class="nano-cms-admin-imgprev" data-prev-for="nano-thumbnail"></div>
    <p class="nano-cms-admin-help">Separate image used on category-archive cards. Leave blank to auto-derive from the hero image.</p>
  </div>
  <div>
    <label>Image alt text<input type="text" name="image_alt" value="<?= nano_admin_e($form['image_alt']) ?>"></label>
  </div>
  <div class="full nano-cms-admin-checkbox-row">
    <label><input type="checkbox" name="draft" value="1"<?= $form['draft'] ? ' checked' : '' ?>> Draft (excluded from public listing, sitemap, RSS)</label>
<?php if ($preview_url !== null): ?>
    <span class="nano-cms-admin-preview-link">
      <a href="<?= nano_admin_e($preview_url) ?>" target="_blank" rel="noopener">Preview as draft</a>
    </span>
<?php endif; ?>
  </div>
  <div class="full">
    <label>Body (Markdown)</label>
    <div class="nano-cms-admin-markdown-editor">
      <div class="nano-cms-admin-md-toolbar">
        <button type="button" data-md="bold">B</button>
        <button type="button" data-md="italic"><em>I</em></button>
        <button type="button" data-md="link">Link</button>
        <button type="button" data-md="heading">H</button>
        <button type="button" data-md="list">List</button>
        <button type="button" data-md="code">Code</button>
        <button type="button" data-md-image title="Insert an image from the media library">Image</button>
      </div>
      <textarea id="nano-body" name="body" required><?= nano_admin_e($form['body']) ?></textarea>
    </div>
    <p class="nano-cms-admin-help">Markdown only. Embed video with <code>[video:youtube:ID]</code> or <code>[video:vimeo:ID]</code>.</p>
  </div>
</div>

<div class="nano-cms-admin-form-actions">
  <button type="submit" name="save_action" value="list" class="nano-cms-admin-button nano-cms-admin-button-primary"><?= $is_new ? 'Create and return to list' : 'Save and return to list' ?></button>
  <button type="submit" name="save_action" value="continue" class="nano-cms-admin-button">Save and keep editing</button>
  <a href="index.php" class="nano-cms-admin-button nano-cms-admin-button-secondary">Cancel</a>
<?php if (!$is_new): ?>
  <button type="submit" form="nano-delete-form" class="nano-cms-admin-button nano-cms-admin-button-danger nano-cms-admin-delete-action">Delete this post</button>
<?php endif; ?>
</div>

</form>

<?php if (!$is_new): ?>
<?php /* Separate, un-nested form so the editor and delete actions never nest.
         The Delete button above is associated with it via the HTML form= attribute. */ ?>
<form id="nano-delete-form" method="post" action="index.php?action=delete"
      onsubmit="return confirm('Delete this post? This cannot be undone.');">
  <?= nano_admin_csrf_field() ?>
  <input type="hidden" name="slug" value="<?= nano_admin_e((string)$original_fm['slug']) ?>">
</form>
<?php endif; ?>

<script>
window.NANO_MEDIA = <?= $media_json ?>;
window.NANO_MEDIA_BASE = <?= $media_base_json ?>;
</script>
<script>
(function () {
  var ta = document.getElementById('nano-body');
  if (ta) {
    // Some browsers reset textarea scrollTop and/or page scroll when
    // pasting into a focused textarea, which is jarring when pasting
    // at the bottom of a long body. Restore both after paste settles.
    ta.addEventListener('paste', function () {
      var savedScrollTop = ta.scrollTop;
      var savedWindowScroll = window.scrollY;
      setTimeout(function () {
        if (ta.scrollTop !== savedScrollTop) ta.scrollTop = savedScrollTop;
        if (window.scrollY !== savedWindowScroll) window.scrollTo(0, savedWindowScroll);
      }, 0);
    });
    document.querySelectorAll('.nano-cms-admin-md-toolbar button[data-md]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var kind = btn.getAttribute('data-md');
        var s = ta.selectionStart, e = ta.selectionEnd;
        var sel = ta.value.substring(s, e);
        var before = ta.value.substring(0, s);
        var after = ta.value.substring(e);
        var ins = sel, caret = null;
        switch (kind) {
          case 'bold':    ins = '**' + (sel || 'bold') + '**'; break;
          case 'italic':  ins = '*'  + (sel || 'italic') + '*'; break;
          case 'link':    ins = '[' + (sel || 'text') + '](https://)'; caret = ins.length - 1; break;
          case 'heading': ins = (before && !before.endsWith('\n') ? '\n' : '') + '## ' + (sel || 'Heading') + '\n'; break;
          case 'list':    ins = (before && !before.endsWith('\n') ? '\n' : '') + '- ' + (sel || 'item') + '\n'; break;
          case 'code':    ins = sel.indexOf('\n') >= 0 ? '```\n' + sel + '\n```\n' : '`' + (sel || 'code') + '`'; break;
        }
        ta.value = before + ins + after;
        var pos = caret !== null ? before.length + caret : before.length + ins.length;
        ta.focus();
        ta.setSelectionRange(pos, pos);
      });
    });
  }
  /* ----- Image manager: pick from the media library ----------------- */
  var MEDIA = window.NANO_MEDIA || [];
  var MEDIA_BASE = window.NANO_MEDIA_BASE || '';
  function nanoEscAttr(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;'); }
  function nanoEscHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function openMediaPicker(onPick) {
    var bg = document.createElement('div'); bg.className = 'nano-cms-admin-pickbg';
    var m = document.createElement('div'); m.className = 'nano-cms-admin-pickmodal';
    m.innerHTML =
        '<div class="nano-cms-admin-pickhead"><strong>Select an image from the library</strong>'
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
    if (!MEDIA.length) {
      grid.innerHTML = '<p class="nano-cms-admin-pickempty">No images in the library yet. Upload some in the Media tab.</p>';
    }
    MEDIA.forEach(function (it) {
      var cell = document.createElement('button'); cell.type = 'button'; cell.className = 'nano-cms-admin-pickcell';
      cell.innerHTML = '<span class="nano-cms-admin-pickthumb"><img loading="lazy" src="' + nanoEscAttr(it.thumb) + '" alt=""></span>'
        + '<span class="nano-cms-admin-pickname">' + nanoEscHtml(it.name) + '</span>';
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
    prev.innerHTML = '<img src="' + nanoEscAttr(src) + '" alt=""><button type="button" class="nano-cms-admin-imgclear">Clear</button>';
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

  var imgBtn = document.querySelector('.nano-cms-admin-md-toolbar [data-md-image]');
  if (ta && imgBtn) {
    imgBtn.addEventListener('click', function () {
      openMediaPicker(function (it) {
        var s = ta.selectionStart, e = ta.selectionEnd;
        var sel = ta.value.substring(s, e);
        var ins = '![' + sel + '](' + it.name + ')';
        ta.value = ta.value.substring(0, s) + ins + ta.value.substring(e);
        var pos = s + ins.length;
        ta.focus(); ta.setSelectionRange(pos, pos);
      });
    });
  }

  function wireCounter(inputSel, counterId) {
    var input = document.querySelector(inputSel);
    var counter = document.getElementById(counterId);
    if (!input || !counter) return;
    var update = function () { counter.textContent = input.value.length; };
    input.addEventListener('input', update);
    update();
  }
  wireCounter('input[name="description"]', 'desc-count');
  wireCounter('#nano-title', 'title-count');
})();
</script>
<?= nano_admin_render_footer() ?>
