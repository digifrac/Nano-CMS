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
echo nano_admin_header('Help', 'help');
?>
<section class="nano-cms-admin-help-section">
<h2>Markdown</h2>
<p>The post body is Markdown. Parsedown's safe mode is on, which strips raw HTML and dangerous URLs - everything that ends up in the rendered post is generated from Markdown or shortcodes.</p>
<table class="nano-cms-admin-table">
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

<section class="nano-cms-admin-help-section">
<h2>Shortcodes</h2>
<p>Shortcodes expand to safe iframe embeds <em>after</em> Markdown rendering, so they survive safe-mode stripping. There are exactly two:</p>
<table class="nano-cms-admin-table">
<tr><th>Shortcode</th><th>Renders as</th></tr>
<tr><td><code>[video:youtube:VIDEO_ID]</code></td><td>Responsive YouTube embed (uses <code>youtube-nocookie.com</code>)</td></tr>
<tr><td><code>[video:vimeo:VIDEO_ID]</code></td><td>Responsive Vimeo embed</td></tr>
</table>
<p class="nano-cms-admin-help">Place a shortcode on its own line for a block-level embed. Inline shortcodes (mid-paragraph) work but render as a div nested inside a paragraph - browsers tolerate it.</p>
</section>

<section class="nano-cms-admin-help-section">
<h2>Frontmatter fields</h2>
<table class="nano-cms-admin-table">
<tr><th>Field</th><th>Required</th><th>Notes</th></tr>
<tr><td><code>title</code></td><td>yes</td><td>Used in <code>&lt;title&gt;</code> and the post heading. Aim for under 60 chars for search snippets.</td></tr>
<tr><td><code>slug</code></td><td>yes</td><td>URL slug. <code>[a-z0-9-]+</code> only. Authoritative - the file is renamed to match on every save.</td></tr>
<tr><td><code>date</code></td><td>yes</td><td><code>YYYY-MM-DD</code>. Original publish date.</td></tr>
<tr><td><code>updated</code></td><td>no</td><td><code>YYYY-MM-DD</code>. Auto-set to today on save when content changes; clear it manually for trivial edits.</td></tr>
<tr><td><code>category</code></td><td>yes</td><td>Single category, free-form, <code>[a-z0-9-]+</code>. Existing values autocomplete in the editor.</td></tr>
<tr><td><code>description</code></td><td>yes</td><td>Meta description. Aim for ~150 chars.</td></tr>
<tr><td><code>image</code></td><td>no</td><td>Hero image filename (relative to <code>/media/</code>). Shown full-size on the single-post page.</td></tr>
<tr><td><code>thumbnail</code></td><td>no</td><td>Optional separate image used only on article cards. Leave blank to auto-derive a thumbnail from <code>image</code>.</td></tr>
<tr><td><code>image_alt</code></td><td>no</td><td>Alt text. Falls back to <code>title</code> if absent. Supplying it explicitly is strongly preferred.</td></tr>
<tr><td><code>draft</code></td><td>no</td><td><code>true</code> hides the post from listing, sitemap, and feed. Drafts are previewable from the editor while signed in.</td></tr>
</table>
</section>

<section class="nano-cms-admin-help-section">
<h2>Media uploads</h2>
<ul>
<li>Allowed types: <code>jpg</code>, <code>jpeg</code>, <code>png</code>, <code>gif</code>, <code>webp</code>. Nothing else.</li>
<li>Max size: 5 MB per file.</li>
<li>Filenames are randomised on save: <code>YYYY-MM-DD-XXXXXX.ext</code>. The original filename is discarded.</li>
<li>Every upload is decoded and re-encoded through GD (or Imagick) to strip embedded payloads. If neither extension is loaded on the server, uploads are refused with a banner.</li>
<li>The "unused" tag in the media grid means no post references the file - frontmatter <code>image:</code> or inline Markdown <code>![](...)</code>.</li>
</ul>
</section>

<section class="nano-cms-admin-help-section">
<h2>Per-image control</h2>
<p>There is no single global crop. Each post and each category carries its own image settings, set on the post editor and the category editor:</p>
<table class="nano-cms-admin-table">
<tr><th>Setting</th><th>What it does</th></tr>
<tr><td><code>Fit: cover</code></td><td>Fills the card frame and crops the overflow. Best for photos where edge loss is fine. This is the default.</td></tr>
<tr><td><code>Fit: contain</code></td><td>Shows the <em>whole</em> image inside the card with no cropping. Leftover space shows the background colour. Best for logos, packshots, and anything that must not be cut.</td></tr>
<tr><td><code>Focal point</code></td><td>When Cover crops the image, this picks which part to keep - upper centre (default), centre, top, bottom, left, or right. No effect under Contain.</td></tr>
<tr><td><code>Background colour</code></td><td>Hex colour shown behind a Contain image or behind transparency. Leave blank for none.</td></tr>
</table>
<p class="nano-cms-admin-help">Uploaded thumbnails now keep the picture's original shape - nothing is cropped into the file - so you can change Fit and Focal point on any image at any time and the framing updates live, with no re-upload needed.</p>
</section>

<section class="nano-cms-admin-help-section">
<h2>Recommended image sizes</h2>
<ul>
<li><strong>Upload large, display small.</strong> Upload a generous source (e.g. 1200&times;800 or larger, well under the 5 MB limit); the blog downscales it for cards and never upscales, so a small source stays small.</li>
<li><strong>Landscape 3:2 is the safe default</strong> for the card grid, but any shape works now - use Contain plus a background colour when you don't want a picture cropped.</li>
<li><strong>Keep the subject where your Focal point points.</strong> Under Cover the default keeps the upper-centre; change it per image if the subject sits elsewhere.</li>
<li><strong>JPG for photos, PNG for graphics with transparency, WebP for smaller files.</strong> The server re-encodes on upload, so source compression doesn't matter.</li>
</ul>
<p class="nano-cms-admin-help">Card thumbnail dimensions (the bounding box images are downscaled into) are set on the Settings page; changes apply to subsequent uploads.</p>
</section>

<section class="nano-cms-admin-help-section">
<h2>Deployment notes</h2>
<ul>
<li><strong>HTTPS is mandatory.</strong> The admin refuses to load over HTTP, with no localhost exemption.</li>
<li><strong>Config lives outside webroot.</strong> <code>config.json</code> and <code>rate-limit.json</code> sit at the paths declared by <code>bootstrap.php</code> - both contain the password hash and security state.</li>
<li><strong>Remove the admin folder when done.</strong> Upload via SFTP to publish, work, then delete the entire <code>/admin/</code> tree. The frontend keeps rendering the same posts after the admin is gone. Sessions and rate-limit state persist outside webroot, so re-uploading the admin folder doesn't reset lockouts.</li>
<li><strong>Sitemap and feed regenerate on save and delete.</strong> No cron job needed.</li>
<li><strong>Backups: just rsync the <code>/posts/</code> and <code>/media/</code> directories.</strong> The whole CMS is on disk.</li>
</ul>
</section>

<p class="nano-cms-admin-help">Admin version <?= nano_admin_e(NANO_ADMIN_VERSION) ?>. Format version <?= nano_admin_e((string)($cfg['format_version'] ?? '?')) ?>.</p>

<?= nano_admin_render_footer() ?>
