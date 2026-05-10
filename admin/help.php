<?php
/**
 * Admin help page. Reference card for Markdown syntax, shortcodes,
 * frontmatter fields, and deployment expectations. No state, no
 * forms - just static content gated behind the admin login.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Help - <?= nano_admin_e($site_name) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bar">
  <h1>Help</h1>
  <div>
    <a href="index.php">All posts</a>
    | <a href="media.php">Media</a>
    | <a href="categories.php">Categories</a>
    | <a href="settings.php">Settings</a>
    | <a href="index.php?action=logout">Sign out</a>
  </div>
</div>

<section class="help-section">
<h2>Markdown</h2>
<p>The post body is Markdown. Parsedown's safe mode is on, which strips raw HTML and dangerous URLs - everything that ends up in the rendered post is generated from Markdown or shortcodes.</p>
<table class="help-table">
<tr><th>Syntax</th><th>Renders as</th></tr>
<tr><td><code># Heading 1</code> ... <code>###### Heading 6</code></td><td>Headings</td></tr>
<tr><td><code>**bold**</code> &middot; <code>*italic*</code> &middot; <code>`inline code`</code></td><td>Inline emphasis</td></tr>
<tr><td><code>[text](https://example.com)</code></td><td>Link</td></tr>
<tr><td><code>![alt](2026-05-09-abc123.jpg)</code></td><td>Image (filename relative to <code>/media/</code>)</td></tr>
<tr><td><code>- item</code> on consecutive lines</td><td>Unordered list</td></tr>
<tr><td><code>1. item</code> on consecutive lines</td><td>Ordered list</td></tr>
<tr><td><code>&gt; quoted text</code></td><td>Blockquote</td></tr>
<tr><td>Triple backticks fence a code block</td><td>Preformatted code</td></tr>
<tr><td>Blank line</td><td>Paragraph break</td></tr>
</table>
</section>

<section class="help-section">
<h2>Shortcodes</h2>
<p>Shortcodes expand to safe iframe embeds <em>after</em> Markdown rendering, so they survive safe-mode stripping. There are exactly two:</p>
<table class="help-table">
<tr><th>Shortcode</th><th>Renders as</th></tr>
<tr><td><code>[video:youtube:VIDEO_ID]</code></td><td>Responsive YouTube embed (uses <code>youtube-nocookie.com</code>)</td></tr>
<tr><td><code>[video:vimeo:VIDEO_ID]</code></td><td>Responsive Vimeo embed</td></tr>
</table>
<p class="help">Place a shortcode on its own line for a block-level embed. Inline shortcodes (mid-paragraph) work but render as a div nested inside a paragraph - browsers tolerate it.</p>
</section>

<section class="help-section">
<h2>Frontmatter fields</h2>
<table class="help-table">
<tr><th>Field</th><th>Required</th><th>Notes</th></tr>
<tr><td><code>title</code></td><td>yes</td><td>Used in <code>&lt;title&gt;</code> and the post heading. Aim for under 60 chars for search snippets.</td></tr>
<tr><td><code>slug</code></td><td>yes</td><td>URL slug. <code>[a-z0-9-]+</code> only. Authoritative - the file is renamed to match on every save.</td></tr>
<tr><td><code>date</code></td><td>yes</td><td><code>YYYY-MM-DD</code>. Original publish date.</td></tr>
<tr><td><code>updated</code></td><td>no</td><td><code>YYYY-MM-DD</code>. Auto-set to today on save when content changes; clear it manually for trivial edits.</td></tr>
<tr><td><code>category</code></td><td>yes</td><td>Single category, free-form, <code>[a-z0-9-]+</code>. Existing values autocomplete in the editor.</td></tr>
<tr><td><code>description</code></td><td>yes</td><td>Meta description. Aim for ~150 chars.</td></tr>
<tr><td><code>image</code></td><td>no</td><td>Hero image filename (relative to <code>/media/</code>).</td></tr>
<tr><td><code>image_alt</code></td><td>no</td><td>Alt text. Falls back to <code>title</code> if absent. Supplying it explicitly is strongly preferred.</td></tr>
<tr><td><code>draft</code></td><td>no</td><td><code>true</code> hides the post from listing, sitemap, and feed. Drafts are previewable from the editor while signed in.</td></tr>
</table>
</section>

<section class="help-section">
<h2>Media uploads</h2>
<ul>
<li>Allowed types: <code>jpg</code>, <code>jpeg</code>, <code>png</code>, <code>gif</code>, <code>webp</code>. Nothing else.</li>
<li>Max size: 5 MB per file.</li>
<li>Filenames are randomised on save: <code>YYYY-MM-DD-XXXXXX.ext</code>. The original filename is discarded.</li>
<li>Every upload is decoded and re-encoded through GD (or Imagick) to strip embedded payloads. If neither extension is loaded on the server, uploads are refused with a banner.</li>
<li>The "unused" tag in the media grid means no post references the file - frontmatter <code>image:</code> or inline Markdown <code>![](...)</code>.</li>
</ul>
</section>

<section class="help-section">
<h2>Recommended image sizes</h2>
<p>Hero images appear in two places: the article-card grid (small, cropped) and the single-post hero (full-width). The rendering rules:</p>
<ul>
<li><strong>Aim for landscape, ratio 3:2.</strong> Article cards crop everything to 3:2 (1.5x wider than tall). Pictures shot in 4:3 or 16:9 still work, but expect crop loss.</li>
<li><strong>Recommended size: 1200&times;800 pixels.</strong> Big enough to look sharp on retina screens, small enough that the file stays well under the 5 MB limit. 1600&times;1067 if you want extra headroom for high-DPI displays.</li>
<li><strong>Keep the subject near the upper-centre.</strong> The card crop biases roughly 35% from the top - faces and key elements there survive the crop reliably.</li>
<li><strong>JPG for photos, PNG for graphics with sharp edges, WebP if you want smaller files.</strong> The server re-encodes on upload, so source compression doesn't matter.</li>
</ul>
<p class="help">Every upload now generates a pre-cropped thumbnail saved alongside the original (e.g. <code>2026-05-06-a4f8b2.jpg</code> + <code>2026-05-06-a4f8b2-thumb.jpg</code>). Article cards on category pages display the thumbnail; the single-post hero still uses the full-size original. Default thumbnail dimensions are 600&times;400 (3:2). Adjust them on the Settings page if needed - changes apply only to subsequent uploads.</p>
</section>

<section class="help-section">
<h2>Deployment notes</h2>
<ul>
<li><strong>HTTPS is mandatory.</strong> The admin refuses to load over HTTP, with no localhost exemption.</li>
<li><strong>Config lives outside webroot.</strong> <code>config.json</code> and <code>rate-limit.json</code> sit at the paths declared by <code>bootstrap.php</code> - both contain the password hash and security state.</li>
<li><strong>Remove the admin folder when done.</strong> Upload via SFTP to publish, work, then delete the entire <code>/admin/</code> tree. The frontend keeps rendering the same posts after the admin is gone. Sessions and rate-limit state persist outside webroot, so re-uploading the admin folder doesn't reset lockouts.</li>
<li><strong>Sitemap and feed regenerate on save and delete.</strong> No cron job needed.</li>
<li><strong>Backups: just rsync the <code>/posts/</code> and <code>/media/</code> directories.</strong> The whole CMS is on disk.</li>
</ul>
</section>

<p class="help">Admin version <?= nano_admin_e(NANO_ADMIN_VERSION) ?>. Format version <?= nano_admin_e((string)($cfg['format_version'] ?? '?')) ?>.</p>

<?= nano_admin_render_footer() ?>
</body>
</html>
