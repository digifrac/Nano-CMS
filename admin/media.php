<?php
/**
 * Nano CMS - media manager (Cart-style, folder-based, self-contained).
 *
 * One file: JSON API + HTML + CSS + JS, mirroring the Nano Cart media
 * manager. A single-pane file browser over /media with free-form folders:
 * a breadcrumb shows where you are; create/delete folders, upload, rename,
 * delete, and drag a thumbnail onto a folder to move it. When a file is
 * moved or renamed, posts and category records that reference it are updated.
 *
 * GET  -> renders the manager page.
 * POST -> JSON API: list, upload, mkdir, deletefolder, rename, move, delete.
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
            case 'list':         nano_cms_media_api_list();         break;
            case 'upload':       nano_cms_media_api_upload();       break;
            case 'mkdir':        nano_cms_media_api_mkdir();        break;
            case 'deletefolder': nano_cms_media_api_deletefolder(); break;
            case 'rename':       nano_cms_media_api_rename();       break;
            case 'move':         nano_cms_media_api_move();         break;
            case 'delete':       nano_cms_media_api_delete();       break;
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

function nano_cms_media_base(): string
{
    return rtrim((string)(nano_admin_load_config()['base_url'] ?? ''), '/');
}

function nano_cms_media_thumb_url(string $path, string $base): string
{
    $thumb = nano_admin_media_thumb_filename($path);
    return is_file(nano_admin_media_fs($thumb)) ? $base . '/media/' . $thumb : $base . '/media/' . $path;
}

function nano_cms_media_api_list(): void
{
    $dir = trim((string)($_POST['dir'] ?? ''), '/');
    if (!nano_admin_media_dir_ok($dir)) { echo json_encode(['ok' => false, 'error' => 'Invalid folder.']); exit; }
    if ($dir !== '' && !is_dir(nano_admin_media_fs($dir))) { echo json_encode(['ok' => false, 'error' => 'Folder not found.']); exit; }

    // Keep the two structural folders present at the media home, so the blog
    // and the manager always have somewhere obvious to put article and
    // category images. They cannot be deleted.
    if ($dir === '') {
        foreach (NANO_ADMIN_MEDIA_STRUCTURAL as $d) {
            $p = nano_admin_media_fs($d);
            if (!is_dir($p)) @mkdir($p, 0755, true);
        }
    }

    $base = nano_cms_media_base();
    $used = nano_admin_media_used_set();

    $crumbs = []; $acc = '';
    foreach ($dir === '' ? [] : explode('/', $dir) as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $crumbs[] = ['name' => $seg, 'path' => $acc];
    }
    $folders = [];
    foreach (nano_admin_media_subfolders($dir) as $sf) {
        $folders[] = [
            'name'      => $sf['name'],
            'path'      => $sf['path'],
            'deletable' => !in_array($sf['path'], NANO_ADMIN_MEDIA_STRUCTURAL, true),
        ];
    }
    $files = [];
    foreach (nano_admin_media_scan_dir($dir) as $f) {
        $files[] = [
            'name'  => $f['name'],
            'path'  => $f['path'],
            'thumb' => nano_cms_media_thumb_url($f['path'], $base),
            'used'  => isset($used[strtolower(basename($f['path']))]),
            'kb'    => round($f['bytes'] / 1024, 1),
        ];
    }
    echo json_encode([
        'ok'      => true,
        'dir'     => $dir,
        'parent'  => $dir === '' ? null : (str_contains($dir, '/') ? substr($dir, 0, strrpos($dir, '/')) : ''),
        'crumbs'  => $crumbs,
        'folders' => $folders,
        'files'   => $files,
        'canupload' => extension_loaded('gd') || extension_loaded('imagick'),
    ]);
    exit;
}

function nano_cms_media_api_upload(): void
{
    @ini_set('memory_limit', '256M');
    $dir = trim((string)($_POST['dir'] ?? ''), '/');
    if (!nano_admin_media_dir_ok($dir)) { echo json_encode(['ok' => false, 'error' => 'Invalid folder.']); exit; }
    if (empty($_FILES['files'])) { echo json_encode(['ok' => false, 'error' => 'No files received.']); exit; }
    $entry = $_FILES['files'];
    $results = [];
    $names = is_array($entry['name']) ? $entry['name'] : [$entry['name']];
    for ($i = 0, $n = count($names); $i < $n; $i++) {
        $one = is_array($entry['name'])
            ? ['name' => $entry['name'][$i], 'tmp_name' => $entry['tmp_name'][$i] ?? '', 'error' => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $entry['size'][$i] ?? 0]
            : $entry;
        $r = nano_admin_media_save_upload($one, $dir);
        $results[] = ['name' => (string)$names[$i], 'ok' => $r['ok'], 'error' => $r['error'] ?? null];
    }
    echo json_encode(['ok' => true, 'files' => $results]);
    exit;
}

function nano_cms_media_api_mkdir(): void
{
    echo json_encode(nano_admin_media_mkdir((string)($_POST['dir'] ?? ''), (string)($_POST['name'] ?? '')));
    exit;
}
function nano_cms_media_api_deletefolder(): void
{
    echo json_encode(nano_admin_media_deletefolder((string)($_POST['path'] ?? '')));
    exit;
}
function nano_cms_media_api_rename(): void
{
    echo json_encode(nano_admin_media_rename((string)($_POST['path'] ?? ''), (string)($_POST['newname'] ?? '')));
    exit;
}
function nano_cms_media_api_move(): void
{
    echo json_encode(nano_admin_media_move((string)($_POST['path'] ?? ''), (string)($_POST['to'] ?? '')));
    exit;
}
function nano_cms_media_api_delete(): void
{
    echo json_encode(nano_admin_media_delete_path((string)($_POST['path'] ?? '')));
    exit;
}

/* ----------------------------------------------------------------------- */
/* Page (GET)                                                               */
/* ----------------------------------------------------------------------- */

echo nano_admin_header('Media', 'media');
?>
<div id="ncm-root" data-endpoint="media.php" data-csrf="<?= nano_admin_e(nano_admin_csrf_token()) ?>"></div>

<style>
/* Ported verbatim from the Nano Cart media manager so the two products
   share an identical media UI (literal colours, not the admin tokens). */
.ncm{border:1px solid #e5e5e5;border-radius:6px;background:#fff;margin-top:1rem;padding:1rem;display:flex;flex-direction:column;gap:.85rem}
.ncm-where{display:flex;align-items:center;gap:.5rem;font-size:1.1rem;background:#f6f7f9;border:1px solid #e5e5e5;border-radius:6px;padding:.55rem .75rem;flex-wrap:wrap}
.ncm-where b{color:#888;font-weight:600;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em}
.ncm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:.15rem}
.ncm-cl{background:none;border:0;color:#0066cc;cursor:pointer;padding:.1rem .35rem;border-radius:4px;font:inherit;font-size:1.05rem}
.ncm-cl:hover{background:#e6eefb;text-decoration:underline}
.ncm-crumb> :last-child{color:#1a1a1a;font-weight:700}
.ncm-sep{color:#aaa}
.ncm-toolbar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.ncm-browse{background:none;border:0;color:#0066cc;cursor:pointer;font:inherit;padding:0;text-decoration:underline}
.ncm-drop{border:2px dashed #cfd6df;border-radius:6px;padding:.85rem;text-align:center;color:#555;background:#fafbfc}
.ncm-drop p{margin:.15rem 0}.ncm-drop small{color:#888}
.ncm-drop-on{border-color:#0066cc;background:#eaf3ff}
.ncm-status{min-height:1.1rem;font-size:.9rem;color:#2c7a2c}
.ncm-status-err{color:#b00020}
.ncm-h{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#999;margin:.25rem 0 0}
.ncm-folders{display:flex;flex-wrap:wrap;gap:.5rem}
.ncm-folder{display:flex;align-items:center;border:1px solid #d9dde3;border-radius:6px;background:#fff;overflow:hidden}
.ncm-fopen{display:flex;align-items:center;gap:.45rem;padding:.55rem .75rem;background:none;border:0;cursor:pointer;font:inherit;color:#1a1a1a}
.ncm-fopen:hover{background:#f0f3f7}
.ncm-fico{color:#c79a4a;display:inline-flex;align-items:center}
.ncm-fdel{border:0;border-left:1px solid #e5e5e5;background:#fafafa;color:#b00020;cursor:pointer;padding:0 .65rem;font-size:1.1rem;align-self:stretch}
.ncm-fdel:hover{background:#fbeaea}
.ncm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.6rem}
.ncm-empty{color:#888;font-style:italic}
.ncm-file{border:1px solid #e5e5e5;border-radius:6px;overflow:hidden;background:#fff;position:relative}
.ncm-file[draggable=true]{cursor:grab}
.ncm-drag{opacity:.45}
.ncm-thumb{aspect-ratio:4/3;background:#eef0f3 repeating-linear-gradient(45deg,#eef0f3 0 8px,#e7e9ec 8px 16px);display:flex;align-items:center;justify-content:center}
.ncm-thumb img{width:100%;height:100%;object-fit:contain;display:block}
.ncm-broken img{display:none}.ncm-broken::after{content:'source missing';color:#b00020;font-size:.78rem}
.ncm-meta{display:flex;align-items:center;justify-content:space-between;gap:.3rem;padding:.35rem .4rem .15rem}
.ncm-fn{font-size:.8rem;word-break:break-all}
.ncm-badge{flex:none;font-size:.65rem;padding:.05rem .35rem;border-radius:10px;cursor:help}
.ncm-used{background:#e3f0e3;color:#2c7a2c}.ncm-unused{background:#f1f1f1;color:#999}
.ncm-fa{display:flex;justify-content:space-between;padding:.15rem .4rem .45rem}
.ncm-fa button{background:none;border:0;color:#0066cc;cursor:pointer;font-size:.78rem;padding:0}
.ncm-fa button:hover{text-decoration:underline}
.ncm-target{outline:2px dashed #0066cc;outline-offset:1px;background:#eaf3ff}
.ncm-toasts{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.5rem;z-index:1100;max-width:24rem}
.ncm-toast{background:#1f2430;color:#fff;padding:.65rem .9rem;border-radius:6px;font-size:.9rem;box-shadow:0 6px 18px rgba(0,0,0,.25);opacity:0;transform:translateY(10px);transition:opacity .2s,transform .2s}
.ncm-toast-show{opacity:1;transform:none}
.ncm-toast-success{background:#1f7a1f}
.ncm-toast-error{background:#b00020}
.ncm-mbg{position:fixed;inset:0;background:rgba(20,24,31,.5);display:flex;align-items:center;justify-content:center;z-index:1200;padding:1.5rem}
.ncm-modal{background:#fff;border-radius:8px;max-width:26rem;width:100%;padding:1.25rem;box-shadow:0 12px 40px rgba(0,0,0,.3)}
.ncm-modal p{margin:0 0 1rem;white-space:pre-line}
.ncm-modal input{width:100%;padding:.55rem .65rem;border:1px solid #cfd6df;border-radius:5px;margin-bottom:1rem;font:inherit;box-sizing:border-box}
.ncm-mbtns{display:flex;justify-content:flex-end;gap:.5rem}
</style>

<script>
(function () {
  'use strict';
  var root = document.getElementById('ncm-root');
  if (!root) return;
  var ENDPOINT = root.dataset.endpoint, CSRF = root.dataset.csrf;
  var dir = '', dragPath = null, data = null;
  var FOLDER = '<svg class="ncm-fico" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
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
  function dialog(opts) {
    return new Promise(function (resolve) {
      var bg = el('div', 'ncm-mbg'), m = el('div', 'ncm-modal');
      m.appendChild(el('p', null, esc(opts.message)));
      var input = null;
      if (opts.prompt) { input = document.createElement('input'); input.type = 'text'; input.value = opts.value || ''; m.appendChild(input); }
      var btns = el('div', 'ncm-mbtns');
      var cancel = el('button', 'nano-cms-admin-button nano-cms-admin-button-secondary nano-cms-admin-button-sm', 'Cancel');
      var ok = el('button', 'nano-cms-admin-button nano-cms-admin-button-sm' + (opts.danger ? ' nano-cms-admin-button-danger' : ' nano-cms-admin-button-primary'), opts.ok || 'OK');
      btns.appendChild(cancel); btns.appendChild(ok); m.appendChild(btns); bg.appendChild(m); document.body.appendChild(bg);
      (input || ok).focus();
      function close(v) { if (bg.parentNode) bg.parentNode.removeChild(bg); resolve(v); }
      cancel.addEventListener('click', function () { close(opts.prompt ? null : false); });
      ok.addEventListener('click', function () { close(opts.prompt ? input.value : true); });
      bg.addEventListener('click', function (e) { if (e.target === bg) close(opts.prompt ? null : false); });
      bg.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.click(); } if (e.key === 'Escape') cancel.click(); });
    });
  }
  function api(action, params, fd) {
    fd = fd || new FormData();
    fd.append('csrf', CSRF); fd.append('action', action);
    Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
    return fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'Server error (HTTP ' + r.status + ').' }; } });
    }).catch(function (e) { return { ok: false, error: 'Network error: ' + e.message }; });
  }

  root.innerHTML =
      '<div class="ncm">'
    + '<div class="ncm-where"><b>You are here</b><nav class="ncm-crumb"></nav></div>'
    + '<div class="ncm-toolbar">'
    +   '<button type="button" class="nano-cms-admin-button nano-cms-admin-button-secondary ncm-up">Up one level</button>'
    +   '<button type="button" class="nano-cms-admin-button ncm-new">New folder</button>'
    + '</div>'
    + '<div class="ncm-drop"><p>Drop images here or <button type="button" class="ncm-browse">browse</button></p>'
    +   '<p><small>jpg / png / gif / webp, up to 5 MB. Re-encoded on upload.</small></p>'
    +   '<input type="file" accept=".jpg,.jpeg,.png,.gif,.webp" multiple hidden></div>'
    + '<div class="ncm-status" aria-live="polite"></div>'
    + '<div><p class="ncm-h">Folders</p><div class="ncm-folders"></div></div>'
    + '<div><p class="ncm-h">Images</p><div class="ncm-grid"></div></div>'
    + '</div>';

  var elCrumb = root.querySelector('.ncm-crumb'), elUp = root.querySelector('.ncm-up'), elNew = root.querySelector('.ncm-new'),
      elDrop = root.querySelector('.ncm-drop'), elStatus = root.querySelector('.ncm-status'),
      elFolders = root.querySelector('.ncm-folders'), elGrid = root.querySelector('.ncm-grid'), elFile = root.querySelector('input[type=file]');

  elUp.addEventListener('click', function () { if (data && data.parent !== null) load(data.parent); });
  elNew.addEventListener('click', newFolder);
  elDrop.querySelector('.ncm-browse').addEventListener('click', function () { elFile.click(); });
  elFile.addEventListener('change', function () { if (elFile.files.length) upload(elFile.files); elFile.value = ''; });
  elDrop.addEventListener('dragover', function (e) { e.preventDefault(); elDrop.classList.add('ncm-drop-on'); });
  elDrop.addEventListener('dragleave', function () { elDrop.classList.remove('ncm-drop-on'); });
  elDrop.addEventListener('drop', function (e) { e.preventDefault(); elDrop.classList.remove('ncm-drop-on'); if (e.dataTransfer.files.length) upload(e.dataTransfer.files); });

  function status(m, err) { elStatus.textContent = m || ''; elStatus.classList.toggle('ncm-status-err', !!err); }
  function load(d) { status('Loading...'); api('list', { dir: d }).then(function (res) { if (!res.ok) { status(''); toast(res.error, 'error'); return; } dir = res.dir; data = res; render(res); status(''); }); }

  function crumbBtn(label, path) {
    var b = el('button', 'ncm-cl', esc(label));
    b.addEventListener('click', function () { load(path); });
    b.addEventListener('dragover', function (e) { if (dragPath) { e.preventDefault(); b.classList.add('ncm-target'); } });
    b.addEventListener('dragleave', function () { b.classList.remove('ncm-target'); });
    b.addEventListener('drop', function (e) { e.preventDefault(); b.classList.remove('ncm-target'); if (dragPath) move(dragPath, path); });
    return b;
  }

  function render(d) {
    elCrumb.innerHTML = '';
    elCrumb.appendChild(crumbBtn('media', ''));
    (d.crumbs || []).forEach(function (c) { elCrumb.appendChild(el('span', 'ncm-sep', ' / ')); elCrumb.appendChild(crumbBtn(c.name, c.path)); });
    elUp.disabled = (d.parent === null);

    elFolders.innerHTML = '';
    if (!d.folders.length) elFolders.appendChild(el('span', 'ncm-empty', 'No folders here. Use "New folder" to add one.'));
    d.folders.forEach(function (f) {
      var wrap = el('div', 'ncm-folder');
      var open = el('button', 'ncm-fopen', FOLDER + '<span>' + esc(f.name) + '</span>');
      open.addEventListener('click', function () { load(f.path); });
      open.addEventListener('dragover', function (e) { if (dragPath) { e.preventDefault(); wrap.classList.add('ncm-target'); } });
      open.addEventListener('dragleave', function () { wrap.classList.remove('ncm-target'); });
      open.addEventListener('drop', function (e) { e.preventDefault(); wrap.classList.remove('ncm-target'); if (dragPath) move(dragPath, f.path); });
      wrap.appendChild(open);
      if (f.deletable) {
        var del = el('button', 'ncm-fdel', '&times;'); del.title = 'Delete this folder and everything in it';
        del.addEventListener('click', function () { deleteFolder(f); });
        wrap.appendChild(del);
      }
      elFolders.appendChild(wrap);
    });

    elGrid.innerHTML = '';
    if (!d.files.length) { elGrid.appendChild(el('p', 'ncm-empty', 'No images in this folder. Drop some above.')); return; }
    d.files.forEach(function (f) { elGrid.appendChild(fileCard(f)); });
  }

  function fileCard(f) {
    var card = el('div', 'ncm-file'); card.draggable = true;
    var badge = f.used ? '<span class="ncm-badge ncm-used">in use</span>' : '<span class="ncm-badge ncm-unused">unused</span>';
    card.innerHTML = '<div class="ncm-thumb"><img alt="" loading="lazy" src="' + esc(f.thumb) + '"></div>'
      + '<div class="ncm-meta"><span class="ncm-fn">' + esc(f.name) + '</span>' + badge + '</div>'
      + '<div class="ncm-fa"><button type="button" class="ncm-cp">Copy MD</button><button type="button" class="ncm-ren">Rename</button><button type="button" class="ncm-del">Delete</button></div>';
    card.querySelector('img').addEventListener('error', function () { card.querySelector('.ncm-thumb').classList.add('ncm-broken'); });
    card.querySelector('.ncm-cp').addEventListener('click', function () {
      var md = '![](' + f.path + ')';
      if (navigator.clipboard) navigator.clipboard.writeText(md).then(function () { toast('Markdown copied.', 'success'); });
      else window.prompt('Copy this:', md);
    });
    card.querySelector('.ncm-ren').addEventListener('click', function () { rename(f); });
    card.querySelector('.ncm-del').addEventListener('click', function () { del(f); });
    card.addEventListener('dragstart', function (e) { dragPath = f.path; card.classList.add('ncm-drag'); try { e.dataTransfer.setData('text/plain', f.path); } catch (x) {} e.dataTransfer.effectAllowed = 'move'; });
    card.addEventListener('dragend', function () { dragPath = null; card.classList.remove('ncm-drag'); });
    return card;
  }

  function refs(d) { return d && d.refs ? ' ' + d.refs + ' reference' + (d.refs === 1 ? '' : 's') + ' updated.' : ''; }
  function upload(files) {
    var fd = new FormData();
    Array.prototype.slice.call(files).forEach(function (f) { fd.append('files[]', f); });
    status('Uploading...');
    api('upload', { dir: dir }, fd).then(function (d) {
      status('');
      if (!d.ok) { toast(d.error, 'error'); return; }
      var bad = (d.files || []).filter(function (f) { return !f.ok; });
      if (bad.length) toast(bad.length + ' rejected: ' + bad.map(function (f) { return f.error; }).join('; '), 'error');
      else toast('Uploaded.', 'success');
      load(dir);
    });
  }
  function newFolder() {
    dialog({ message: 'New folder name (lowercase letters, numbers, hyphens):', prompt: true, ok: 'Create' }).then(function (n) {
      if (n === null) return; n = (n || '').trim().toLowerCase(); if (!n) return;
      api('mkdir', { dir: dir, name: n }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } toast('Folder created.', 'success'); load(dir); });
    });
  }
  function deleteFolder(f) {
    dialog({ message: 'Delete the folder "' + f.name + '" and everything inside it?', danger: true, ok: 'Delete folder' }).then(function (y) {
      if (!y) return;
      api('deletefolder', { path: f.path }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } toast('Folder deleted.', 'success'); load(dir); });
    });
  }
  function rename(f) {
    var base = f.name.replace(/\.[^.]+$/, '');
    dialog({ message: 'Rename "' + f.name + '" to:', prompt: true, value: base, ok: 'Rename' }).then(function (n) {
      if (n === null) return; n = (n || '').trim().toLowerCase(); if (!n || n === base) return;
      api('rename', { path: f.path, newname: n }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } toast('Renamed.' + refs(d), 'success'); load(dir); });
    });
  }
  function del(f) {
    var m = 'Delete "' + f.name + '"?' + (f.used ? '\n\nThis file is still referenced by a post or category.' : '');
    dialog({ message: m, danger: true, ok: 'Delete' }).then(function (y) {
      if (!y) return;
      api('delete', { path: f.path }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } toast('Deleted.', 'success'); load(dir); });
    });
  }
  function move(path, to) { api('move', { path: path, to: to }).then(function (d) { if (!d.ok) { toast(d.error, 'error'); return; } toast('Moved.' + refs(d), 'success'); load(dir); }); }

  load('');
})();
</script>
<?= nano_admin_render_footer() ?>
