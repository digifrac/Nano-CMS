<?php
/**
 * Admin licence page. Lets the operator paste a Nano CMS licence key
 * and have it verified locally against the embedded public key.
 *
 * A valid licence is written to config.json's `licence_key` field; the
 * frontend reads the same field on every page render and suppresses
 * the "Powered by Nano CMS" footer when verification succeeds.
 *
 * Per the spec, this is the one place where verbose verification
 * feedback is appropriate: only the operator sees it, and the messages
 * help debug paste/clipboard issues.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/../licence.php';

nano_admin_assert_https();

if (!nano_admin_config_exists()) {
    header('Location: setup.php');
    exit;
}

nano_admin_version_check();
nano_admin_require_login();

$cfg       = nano_admin_load_config();
$site_name = (string)($cfg['site_name'] ?? 'Nano CMS');
$host      = (string)($_SERVER['HTTP_HOST'] ?? '');
$is_dev    = nano_is_dev_host($host);
$flash     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nano_admin_require_csrf();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'remove') {
        $cfg['licence_key'] = '';
        nano_admin_save_config($cfg);
        $flash = ['ok', 'Licence removed. The "Powered by Nano CMS" footer will reappear on production pages.'];
    } else {
        $new_key = trim((string)($_POST['licence_key'] ?? ''));
        $result  = nano_licence_inspect($new_key, $host);
        if ($result['ok']) {
            $cfg['licence_key'] = $new_key;
            nano_admin_save_config($cfg);
            $tier   = (string)($result['payload']['tier'] ?? 'unknown');
            $domain = (string)($result['payload']['domain'] ?? '');
            $flash  = ['ok', "Licence saved. Tier: $tier. Covers: $domain. Footer attribution hidden."];
        } else {
            // Verbose, operator-only error - exposed so paste mistakes
            // (truncated keys, extra whitespace, wrong domain) can be
            // diagnosed without round-tripping to Digital Fracture.
            $flash = ['error', 'Licence rejected: ' . $result['reason']];
        }
    }
}

/* Re-load so the status display reflects whatever just got saved. */
$cfg          = nano_admin_load_config();
$current_key  = (string)($cfg['licence_key'] ?? '');
$status       = nano_licence_inspect($current_key, $host);
$has_licence  = $current_key !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Licence - <?= nano_admin_e($site_name) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bar">
  <h1>Licence</h1>
  <div>
    <a href="index.php">All posts</a>
    | <a href="media.php">Media</a>
    | <a href="categories.php">Categories</a>
    | <a href="settings.php">Settings</a>
    | <a href="help.php">Help</a>
    | <a href="index.php?action=logout">Sign out</a>
  </div>
</div>

<?php if ($flash !== null): ?>
<div class="flash-<?= nano_admin_e($flash[0]) ?>"><?= nano_admin_e($flash[1]) ?></div>
<?php endif; ?>

<section class="settings-form">
  <h2>Current status</h2>
<?php if ($is_dev): ?>
  <p class="help"><strong>Development host detected (<code><?= nano_admin_e($host) ?></code>).</strong> The footer attribution is suppressed on <code>localhost</code>, <code>*.test</code>, <code>*.local</code>, and hosts with a port. Verification still runs against your real licence below; this banner just explains why no footer appears here even without a licence.</p>
<?php endif; ?>

<?php if (!$has_licence): ?>
  <p><strong>No licence active.</strong> The "Powered by Nano CMS - Developed by Digital Fracture" footer will appear on every Nano-rendered page (except development hosts).</p>
<?php elseif ($status['ok']): ?>
  <p><strong>Licence valid.</strong>
    Tier: <code><?= nano_admin_e((string)($status['payload']['tier'] ?? '?')) ?></code>.
    Covers: <code><?= nano_admin_e((string)($status['payload']['domain'] ?? '?')) ?></code>.
    Issued: <code><?= nano_admin_e((string)($status['payload']['issued'] ?? '?')) ?></code>.
    Footer attribution hidden.</p>
<?php else: ?>
  <p><strong>Licence present but not valid for this host.</strong> Reason: <?= nano_admin_e((string)$status['reason']) ?></p>
  <p class="help">The licence will not suppress the footer until either you paste one that covers <code><?= nano_admin_e($host) ?></code>, or you remove the current one.</p>
<?php endif; ?>
</section>

<form method="post" class="settings-form">
  <?= nano_admin_csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <h2>Paste a licence key</h2>
  <p class="help">Paste the <code>base64.base64</code> licence string Digital Fracture emailed you, then click <strong>Verify and save</strong>. Verification runs locally - no network calls, no phone-home.</p>
  <label>Licence key
    <textarea name="licence_key" rows="6" cols="80" placeholder="eyJwcm9kdWN0IjoibmFuby1jbXMi....abcdef"><?= nano_admin_e($current_key) ?></textarea>
  </label>
  <button type="submit">Verify and save</button>
</form>

<?php if ($has_licence): ?>
<form method="post" class="settings-form" onsubmit="return confirm('Remove the current licence? The footer attribution will reappear on production pages.');">
  <?= nano_admin_csrf_field() ?>
  <input type="hidden" name="action" value="remove">
  <h2>Remove licence</h2>
  <p class="help">Clears the <code>licence_key</code> field in <code>config.json</code>. The licence file itself is not destroyed - you can paste it back any time.</p>
  <button type="submit">Remove licence</button>
</form>
<?php endif; ?>

<section class="settings-form">
  <h2>Where to buy</h2>
  <p>£29 per domain. £69 for an agency 3-pack. £249 unlimited.
    <a href="https://digitalfracture.co.uk/licensing/nano-cms" target="_blank" rel="noopener">Buy at Digital Fracture &rarr;</a>
  </p>
  <p class="help">Licences are signed offline with Ed25519 and verified locally against the public key embedded in this build. There is no licence server. Lost a key? Email Digital Fracture; re-issue is free.</p>
</section>

<?= nano_admin_render_footer() ?>
</body>
</html>
