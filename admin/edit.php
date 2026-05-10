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
    'draft'       => !empty($original_fm['draft']),
    'body'        => $original_body,
];

/* ---- Save handler ---------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_admin_require_csrf();

    foreach (['title', 'slug', 'date', 'updated', 'category', 'description', 'image', 'image_alt'] as $key) {
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
            foreach (['title', 'slug', 'date', 'category', 'description', 'image', 'image_alt'] as $key) {
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
    ? $base_url . '/' . rawurlencode((string)$original_fm['slug']) . '/?preview=' . rawurlencode(nano_admin_csrf_token())
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nano_admin_e($is_new ? 'New post' : ('Edit: ' . $form['title'])) ?> - <?= nano_admin_e($site_name) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bar">
  <h1><?= nano_admin_e($is_new ? 'New post' : 'Edit post') ?></h1>
  <div>
    <a href="index.php">All posts</a>
    | <a href="media.php">Media</a>
    | <a href="categories.php">Categories</a>
    | <a href="settings.php">Settings</a>
    | <a href="help.php">Help</a>
    | <a href="index.php?action=logout">Sign out</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="errors"><strong>Could not save:</strong>
<ul><?php foreach ($errors as $e): ?><li><?= nano_admin_e($e) ?></li><?php endforeach; ?></ul>
</div>
<?php elseif ($flash !== null): ?>
<div class="flash-ok"><?= nano_admin_e($flash) ?></div>
<?php endif; ?>

<form method="post" autocomplete="off">
<?= nano_admin_csrf_field() ?>

<div class="grid">
  <div class="full">
    <label>Title<input type="text" name="title" id="nano-title" value="<?= nano_admin_e($form['title']) ?>" maxlength="200" required></label>
    <p class="counter"><span id="title-count">0</span> chars (search snippets cut off around 60)</p>
  </div>
  <div>
    <label>Slug<input type="text" name="slug" value="<?= nano_admin_e($form['slug']) ?>" pattern="[a-z0-9\-]+" required></label>
    <p class="help">[a-z0-9-] only. Authoritative for the URL; filename will be reconciled on save.</p>
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
    <p class="counter"><span id="desc-count">0</span> chars</p>
  </div>
  <div>
    <label>Image filename (in /media/)<input type="text" name="image" value="<?= nano_admin_e($form['image']) ?>"></label>
  </div>
  <div>
    <label>Image alt text<input type="text" name="image_alt" value="<?= nano_admin_e($form['image_alt']) ?>"></label>
  </div>
  <div class="full checkbox-row">
    <label><input type="checkbox" name="draft" value="1"<?= $form['draft'] ? ' checked' : '' ?>> Draft (excluded from public listing, sitemap, RSS)</label>
<?php if ($preview_url !== null): ?>
    <span class="preview-link">
      <a href="<?= nano_admin_e($preview_url) ?>" target="_blank" rel="noopener">Preview as draft</a>
    </span>
<?php endif; ?>
  </div>
  <div class="full">
    <label>Body (Markdown)</label>
    <div class="md-toolbar">
      <button type="button" data-md="bold">B</button>
      <button type="button" data-md="italic"><em>I</em></button>
      <button type="button" data-md="link">Link</button>
      <button type="button" data-md="heading">H</button>
      <button type="button" data-md="list">List</button>
      <button type="button" data-md="code">Code</button>
    </div>
    <textarea id="nano-body" name="body" required><?= nano_admin_e($form['body']) ?></textarea>
    <p class="help">Markdown only. Embed video with <code>[video:youtube:ID]</code> or <code>[video:vimeo:ID]</code>.</p>
  </div>
</div>

<div class="actions">
  <button type="submit" name="save_action" value="list" class="primary"><?= $is_new ? 'Create and return to list' : 'Save and return to list' ?></button>
  <button type="submit" name="save_action" value="continue">Save and keep editing</button>
  <a href="index.php">Cancel</a>
</div>

</form>

<?php if (!$is_new): ?>
<form method="post" action="index.php?action=delete" class="delete-form"
      onsubmit="return confirm('Delete this post? This cannot be undone.');">
  <?= nano_admin_csrf_field() ?>
  <input type="hidden" name="slug" value="<?= nano_admin_e((string)$original_fm['slug']) ?>">
  <button type="submit" class="danger">Delete this post</button>
</form>
<?php endif; ?>

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
    document.querySelectorAll('.md-toolbar button[data-md]').forEach(function (btn) {
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
</body>
</html>
