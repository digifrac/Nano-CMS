<?php
/**
 * Nano CMS - media manager (Cart-style, self-contained).
 *
 * One file: JSON API + HTML + CSS + JS, mirroring the Nano Cart media
 * manager's single-file design so nothing is ever served stale. Adapted
 * to Nano CMS's flat /media/ model (no folders, randomised filenames,
 * pre-generated -thumb companions). Folders + rename are deliberately
 * omitted here - they require posts to reference images by path, which is
 * a format-level change tracked separately.
 *
 * GET  -> renders the manager page.
 * POST -> JSON API: list, upload, delete.
 *
 * Server-side upload/delete reuse the tested helpers in media-lib.php.
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

/* ----------------------------------------------------------------------- */
/* JSON API (POST)                                                          */
/* ----------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @ini_set('display_errors', '0');
    header('Content-Type: application/json');
    register_shutdown_function(static function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e['message']]);
        }
    });
    if (!nano_admin_csrf_check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page and log in again.']);
        exit;
    }
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'list':   nano_cms_media_api_list();   break;
            case 'upload': nano_cms_media_api_upload(); break;
            case 'delete': nano_cms_media_api_delete(); break;
            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        }
    } catch (\Throwable $ex) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $ex->getMessage()]);
    }
    exit;
}

function nano_cms_media_api_list(): void
{
    $cfg  = nano_admin_load_config();
    $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
    $dir  = NANO_CONTENT_PATH . '/media';
    $used = nano_admin_media_used_set();
    $files = [];
    foreach (nano_admin_list_media() as $it) {
        $name  = $it['filename'];
        $thumb = nano_admin_media_thumb_filename($name);
        $files[] = [
            'name'  => $name,
            'thumb' => is_file($dir . '/' . $thumb) ? $base . '/media/' . $thumb : $base . '/media/' . $name,
            'used'  => isset($used[strtolower($name)]),
            'kb'    => round($it['bytes'] / 1024, 1),
            'date'  => date('Y-m-d', $it['mtime']),
        ];
    }
    echo json_encode([
        'ok'        => true,
        'files'     => $files,
        'reencoder' => extension_loaded('gd') || extension_loaded('imagick'),
    ]);
    exit;
}

function nano_cms_media_api_upload(): void
{
    @ini_set('memory_limit', '256M');
    if (empty($_FILES['files'])) {
        echo json_encode(['ok' => false, 'error' => 'No files received.']);
        exit;
    }
    // Normalise the multi-file $_FILES['files'] shape into per-file entries.
    $entry = $_FILES['files'];
    $results = [];
    if (is_array($entry['name'])) {
        for ($i = 0, $n = count($entry['name']); $i < $n; $i++) {
            $one = [
                'name'     => $entry['name'][$i],
                'tmp_name' => $entry['tmp_name'][$i] ?? '',
                'error'    => $entry['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $entry['size'][$i]     ?? 0,
            ];
            $r = nano_admin_media_save_upload($one);
            $results[] = ['name' => (string)$entry['name'][$i], 'ok' => $r['ok'], 'error' => $r['error'] ?? null];
        }
    } else {
        $r = nano_admin_media_save_upload($entry);
        $results[] = ['name' => (string)$entry['name'], 'ok' => $r['ok'], 'error' => $r['error'] ?? null];
    }
    echo json_encode(['ok' => true, 'files' => $results]);
    exit;
}

function nano_cms_media_api_delete(): void
{
    $name = (string)($_POST['filename'] ?? '');
    if (nano_admin_media_delete($name)) {
        echo json_encode(['ok' => true, 'deleted' => $name]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Could not delete ' . $name . '.']);
    }
    exit;
}

/* ----------------------------------------------------------------------- */
/* Page (GET)                                                               */
/* ----------------------------------------------------------------------- */

$reencoder_available = extension_loaded('gd') || extension_loaded('imagick');
echo nano_admin_header('Media', 'media');
?>
<?php if (!$reencoder_available): ?>
<div class="nano-cms-admin-flash nano-cms-admin-flash-warning"><strong>Uploads disabled:</strong> neither the GD nor the Imagick PHP extension is loaded. Browsing and deletion still work.</div>
<?php endif; ?>

<div id="ncm-root"
     data-endpoint="media.php"
     data-csrf="<?= nano_admin_e(nano_admin_csrf_token()) ?>"
     data-canupload="<?= $reencoder_available ? '1' : '0' ?>"></div>

<style>
.ncm-drop { border: 2px dashed var(--nano-cms-admin-border); border-radius: var(--nano-cms-admin-radius); padding: 1.1rem; text-align: center; color: var(--nano-cms-admin-muted); background: var(--nano-cms-admin-bg); margin-bottom: 1.25rem; transition: border-color 120ms ease, background-color 120ms ease; }
.ncm-drop.ncm-drop-on { border-color: var(--nano-cms-admin-accent); background: var(--nano-cms-admin-accent-soft); }
.ncm-drop p { margin: 0.2rem 0; }
.ncm-status { min-height: 1.1rem; font-size: 0.9rem; color: var(--nano-cms-admin-success-fg); margin-bottom: 0.75rem; }
.ncm-status.ncm-err { color: var(--nano-cms-admin-danger); }
.ncm-toasts { position: fixed; bottom: 1rem; right: 1rem; display: flex; flex-direction: column; gap: 0.5rem; z-index: 1300; max-width: 24rem; }
.ncm-toast { background: #1f2430; color: #fff; padding: 0.65rem 0.9rem; border-radius: var(--nano-cms-admin-radius-sm); font-size: 0.9rem; box-shadow: var(--nano-cms-admin-shadow-raised); opacity: 0; transform: translateY(10px); transition: opacity 0.2s, transform 0.2s; }
.ncm-toast-show { opacity: 1; transform: none; }
.ncm-toast-success { background: var(--nano-cms-admin-success-fg); }
.ncm-toast-error { background: var(--nano-cms-admin-danger); }
.ncm-mbg { position: fixed; inset: 0; background: rgba(20,24,31,0.5); display: flex; align-items: center; justify-content: center; z-index: 1400; padding: 1.5rem; }
.ncm-modal { background: var(--nano-cms-admin-panel); border-radius: var(--nano-cms-admin-radius); max-width: 26rem; width: 100%; padding: 1.25rem; box-shadow: var(--nano-cms-admin-shadow-raised); }
.ncm-modal p { margin: 0 0 1rem; white-space: pre-line; }
.ncm-mbtns { display: flex; justify-content: flex-end; gap: 0.5rem; }
</style>

<script>
(function () {
  'use strict';
  var root = document.getElementById('ncm-root');
  if (!root) return;
  var ENDPOINT = root.dataset.endpoint, CSRF = root.dataset.csrf, CANUP = root.dataset.canupload === '1';
  function el(t, c, h) { var e = document.createElement(t); if (c) e.className = c; if (h != null) e.innerHTML = h; return e; }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function toast(msg, type) {
    var box = document.querySelector('.ncm-toasts');
    if (!box) { box = el('div', 'ncm-toasts'); document.body.appendChild(box); }
    var t = el('div', 'ncm-toast' + (type ? ' ncm-toast-' + type : ''), esc(msg));
    box.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('ncm-toast-show'); });
    setTimeout(function () { t.classList.remove('ncm-toast-show'); setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 250); }, type === 'error' ? 6000 : 3000);
  }
  function confirmDlg(message) {
    return new Promise(function (resolve) {
      var bg = el('div', 'ncm-mbg'), m = el('div', 'ncm-modal');
      m.appendChild(el('p', null, esc(message)));
      var btns = el('div', 'ncm-mbtns');
      var cancel = el('button', 'nano-cms-admin-button nano-cms-admin-button-secondary', 'Cancel');
      var ok = el('button', 'nano-cms-admin-button nano-cms-admin-button-danger', 'Delete');
      btns.appendChild(cancel); btns.appendChild(ok); m.appendChild(btns); bg.appendChild(m); document.body.appendChild(bg);
      ok.focus();
      function close(v) { if (bg.parentNode) bg.parentNode.removeChild(bg); resolve(v); }
      cancel.addEventListener('click', function () { close(false); });
      ok.addEventListener('click', function () { close(true); });
      bg.addEventListener('click', function (e) { if (e.target === bg) close(false); });
      bg.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(false); });
    });
  }
  function api(action, params, fd) {
    fd = fd || new FormData();
    fd.append('csrf', CSRF); fd.append('action', action);
    Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
    return fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); }
        catch (e) { return { ok: false, error: 'Server error (HTTP ' + r.status + ').' }; }
      });
    }).catch(function (e) { return { ok: false, error: 'Network error: ' + e.message }; });
  }

  root.innerHTML =
      (CANUP ? '<div class="ncm-drop"><p>Drop images here or <button type="button" class="ncm-browse nano-cms-admin-button nano-cms-admin-button-sm">browse</button></p>'
      + '<p class="nano-cms-admin-help" style="margin:0">jpg / png / gif / webp, up to 5 MB. Re-encoded on upload.</p>'
      + '<input type="file" accept=".jpg,.jpeg,.png,.gif,.webp" multiple hidden></div>' : '')
    + '<div class="ncm-status" aria-live="polite"></div>'
    + '<div class="nano-cms-admin-media-grid"></div>';

  var elDrop = root.querySelector('.ncm-drop'), elStatus = root.querySelector('.ncm-status'),
      elGrid = root.querySelector('.nano-cms-admin-media-grid'), elFile = root.querySelector('input[type=file]');

  function status(m, err) { elStatus.textContent = m || ''; elStatus.classList.toggle('ncm-err', !!err); }

  if (elDrop) {
    elDrop.querySelector('.ncm-browse').addEventListener('click', function () { elFile.click(); });
    elFile.addEventListener('change', function () { if (elFile.files.length) upload(elFile.files); elFile.value = ''; });
    elDrop.addEventListener('dragover', function (e) { e.preventDefault(); elDrop.classList.add('ncm-drop-on'); });
    elDrop.addEventListener('dragleave', function () { elDrop.classList.remove('ncm-drop-on'); });
    elDrop.addEventListener('drop', function (e) { e.preventDefault(); elDrop.classList.remove('ncm-drop-on'); if (e.dataTransfer.files.length) upload(e.dataTransfer.files); });
  }

  function load() {
    status('Loading...');
    api('list', {}).then(function (res) {
      if (!res.ok) { status(''); toast(res.error, 'error'); return; }
      status(''); render(res.files || []);
    });
  }

  function render(files) {
    elGrid.innerHTML = '';
    if (!files.length) { elGrid.appendChild(el('p', 'nano-cms-admin-help', 'No media uploaded yet.')); return; }
    files.forEach(function (f) { elGrid.appendChild(card(f)); });
  }

  function card(f) {
    var c = el('div', 'nano-cms-admin-tile');
    c.innerHTML = '<img loading="lazy" alt="" src="' + esc(f.thumb) + '">'
      + '<div class="nano-cms-admin-tile-meta"><strong>' + esc(f.name) + '</strong>'
      + (f.used ? '' : ' <span class="nano-cms-admin-pill">unused</span>')
      + '<br>' + f.kb + ' KB &middot; ' + esc(f.date) + '</div>'
      + '<div class="nano-cms-admin-tile-actions">'
      + '<button type="button" class="nano-cms-admin-button nano-cms-admin-button-sm ncm-md" data-clip="![](' + esc(f.name) + ')">Copy MD</button>'
      + '<button type="button" class="nano-cms-admin-button nano-cms-admin-button-sm ncm-nm" data-clip="' + esc(f.name) + '">Copy name</button>'
      + '<button type="button" class="nano-cms-admin-button nano-cms-admin-button-danger nano-cms-admin-button-sm ncm-del">Delete</button>'
      + '</div>';
    c.querySelector('img').addEventListener('error', function () { this.style.opacity = '0.25'; });
    c.querySelectorAll('[data-clip]').forEach(function (b) {
      b.addEventListener('click', function () {
        var text = b.getAttribute('data-clip');
        if (navigator.clipboard) { navigator.clipboard.writeText(text).then(function () { var o = b.textContent; b.textContent = 'Copied!'; setTimeout(function () { b.textContent = o; }, 1200); }); }
        else { window.prompt('Copy this:', text); }
      });
    });
    c.querySelector('.ncm-del').addEventListener('click', function () {
      var m = 'Delete "' + f.name + '"?' + (f.used ? '\n\nThis file is still referenced by a post.' : '');
      confirmDlg(m).then(function (yes) {
        if (!yes) return;
        api('delete', { filename: f.name }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } toast('Deleted.', 'success'); load(); });
      });
    });
    return c;
  }

  function upload(files) {
    var fd = new FormData();
    Array.prototype.slice.call(files).forEach(function (f) { fd.append('files[]', f); });
    status('Uploading...');
    api('upload', {}, fd).then(function (d) {
      status('');
      if (!d.ok) { toast(d.error, 'error'); return; }
      var bad = (d.files || []).filter(function (f) { return !f.ok; });
      if (bad.length) toast(bad.length + ' rejected: ' + bad.map(function (f) { return f.error; }).join('; '), 'error');
      else toast('Uploaded.', 'success');
      load();
    });
  }

  load();
})();
</script>
<?= nano_admin_render_footer() ?>
