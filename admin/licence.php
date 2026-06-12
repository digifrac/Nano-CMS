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
// Verify against the canonical host (from base_url), the same identity
// the frontend's footer renderer uses. The browser-supplied HTTP_HOST is
// kept only for the "you're accessing via a dev URL" banner below; it
// no longer drives licence verification.
$verify_host = nano_licence_canonical_host();
$request_host = (string)($_SERVER['HTTP_HOST'] ?? '');
$is_dev = nano_is_dev_host($verify_host !== '' ? $verify_host : $request_host);
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
        $result  = nano_licence_inspect($new_key, $verify_host);
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
$status       = nano_licence_inspect($current_key, $verify_host);
$has_licence  = $current_key !== '';
echo nano_admin_header('Licence', 'licence');
?>
<?php if ($flash !== null): ?>
<?= nano_admin_flash($flash[0], $flash[1]) ?>
<?php endif; ?>

<section class="nano-cms-admin-section">
  <h2 class="nano-cms-admin-section-title">Current status</h2>
<?php if ($is_dev): ?>
  <p class="nano-cms-admin-help"><strong>Development host detected (<code><?= nano_admin_e($verify_host !== '' ? $verify_host : $request_host) ?></code>).</strong> The footer attribution is suppressed on <code>localhost</code>, <code>*.test</code>, <code>*.local</code>, and hosts with a port. Verification still runs against your real licence below; this banner just explains why no footer appears here even without a licence.</p>
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
  <p class="nano-cms-admin-help">The licence will not suppress the footer until either you paste one that covers <code><?= nano_admin_e($verify_host !== '' ? $verify_host : '(base_url unset)') ?></code>, or you remove the current one.</p>
<?php endif; ?>
</section>

<form method="post" class="nano-cms-admin-form">
  <?= nano_admin_csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <h2>Paste a licence key</h2>
  <p class="nano-cms-admin-help">Paste the <code>base64.base64</code> licence string Digital Fracture emailed you, then click <strong>Verify and save</strong>. Verification runs locally - no network calls, no phone-home.</p>
  <label>Licence key
    <textarea name="licence_key" rows="6" cols="80" placeholder="eyJwcm9kdWN0IjoibmFuby1jbXMi....abcdef"><?= nano_admin_e($current_key) ?></textarea>
  </label>
  <div class="nano-cms-admin-form-actions">
    <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-primary">Verify and save</button>
  </div>
</form>

<?php if ($has_licence): ?>
<form method="post" class="nano-cms-admin-form" onsubmit="return confirm('Remove the current licence? The footer attribution will reappear on production pages.');">
  <?= nano_admin_csrf_field() ?>
  <input type="hidden" name="action" value="remove">
  <h2>Remove licence</h2>
  <p class="nano-cms-admin-help">Clears the <code>licence_key</code> field in <code>config.json</code>. The licence file itself is not destroyed - you can paste it back any time.</p>
  <div class="nano-cms-admin-form-actions">
    <button type="submit" class="nano-cms-admin-button nano-cms-admin-button-danger">Remove licence</button>
  </div>
</form>
<?php endif; ?>

<section class="nano-cms-admin-section">
  <h2 class="nano-cms-admin-section-title">Where to buy</h2>
  <p>£29 per domain. £69 for an agency 3-pack. £249 unlimited.
    <a href="https://www.digitalfracture.co.uk/nano.php" target="_blank" rel="noopener">Buy at Digital Fracture &rarr;</a>
  </p>
  <p class="nano-cms-admin-help">Licences are signed by the Digital Fracture licence server (Ed25519) and verified locally against the public key embedded in this build, so there is no phone-home at verification time. Lost a key? Email Digital Fracture; re-issue is free.</p>
</section>

<?= nano_admin_render_footer() ?>
