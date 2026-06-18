<?php
/**
 * Nano CMS - frontend core.
 *
 * Library file. Loaded by index.php / post.php after bootstrap.php has
 * defined NANO_BOOTSTRAPPED, NANO_CONFIG_PATH, and NANO_CONTENT_PATH.
 *
 * Independent of admin/core.php by design - the two codebases share only
 * the on-disk file format (see FORMAT.md), not PHP code.
 */

if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/**
 * Project version. Tracked alongside admin/core.php's NANO_ADMIN_VERSION:
 * both constants carry the same value because they belong to the same
 * release, even though the two codebases ship as separate zips.
 * When bumping for a release, edit both.
 */
const NANO_VERSION = '1.7.0';

require_once __DIR__ . '/lib/Parsedown.php';

/* ------------------------------------------------------------------------- */
/* Configuration                                                              */
/* ------------------------------------------------------------------------- */

function nano_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    if (!defined('NANO_CONFIG_PATH') || !is_file(NANO_CONFIG_PATH)) {
        throw new RuntimeException('Nano CMS: config.json not found at NANO_CONFIG_PATH');
    }
    $raw = file_get_contents(NANO_CONFIG_PATH);
    if ($raw === false) {
        throw new RuntimeException('Nano CMS: failed to read config.json');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Nano CMS: config.json is not valid JSON');
    }
    $config = $decoded;
    return $config;
}

/**
 * Return an inline ` style="background:#hex"` attribute fragment for an image
 * whose record/frontmatter carries an `image_bg` colour (e.g. to show behind
 * the transparent areas of a PNG), or '' when unset or not a valid hex. Each
 * article and category sets its own colour - per-image, like Nano Cart's
 * image_bg - so it is applied directly on the <img> rather than globally.
 */
function nano_image_bg_attr(?string $hex): string
{
    $hex = trim((string)$hex);
    return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)
        ? ' style="background:' . $hex . '"'
        : '';
}

/**
 * Map a stored focal-point keyword to a CSS object-position value. The
 * keyword is what each image's editor saves - so the crop is per-image,
 * never a single global setting. '' or an unknown value falls back to the
 * historical default of upper-centre (50% 35%), so images saved before this
 * feature existed look exactly as they did.
 */
function nano_image_position_value(string $keyword): string
{
    return match (trim($keyword)) {
        'centre', 'center' => '50% 50%',
        'top'              => '50% 0%',
        'bottom'           => '50% 100%',
        'left'             => '0% 50%',
        'right'            => '100% 50%',
        'top-left'         => '0% 0%',
        'top-right'        => '100% 0%',
        'bottom-left'      => '0% 100%',
        'bottom-right'     => '100% 100%',
        'upper-centre'     => '50% 35%',
        default            => '50% 35%',
    };
}

/**
 * Build the inline style fragment for a framed image (article/category cards
 * and the post hero): per-image fit (cover/contain), focal point, and
 * background colour, combined into one ` style="..."`. Only emits properties
 * that are actually set, so the stylesheet still drives the common (untouched)
 * case and existing markup stays lean. Returns '' when nothing is set.
 *
 * This is the per-image control - each post and category carries its own
 * fit/focus/background, mirroring Nano Cart's per-product image settings.
 */
function nano_image_style_attr(string $fit, string $position, ?string $bg): string
{
    $props = [];

    $fit = strtolower(trim($fit));
    if ($fit === 'cover' || $fit === 'contain') {
        $props[] = 'object-fit:' . $fit;
    }

    $position = trim($position);
    if ($position !== '') {
        $props[] = 'object-position:' . nano_image_position_value($position);
    }

    $bg = trim((string)$bg);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bg)) {
        $props[] = 'background:' . $bg;
    }

    return $props === [] ? '' : ' style="' . implode(';', $props) . '"';
}

/**
 * Per-image fit keyword for the card image (mirrors nano_card_image_bg): a
 * separate thumbnail's own setting when a `thumbnail` is set, otherwise the
 * hero image's. Follows whichever image nano_card_image_url() shows.
 */
function nano_card_image_fit(array $fm): string
{
    $thumb = trim((string)($fm['thumbnail'] ?? ''));
    if ($thumb !== '') {
        return (string)($fm['thumbnail_fit'] ?? '');
    }
    return (string)($fm['image_fit'] ?? '');
}

/**
 * Per-image focal-point keyword for the card image, paired with
 * nano_card_image_fit().
 */
function nano_card_image_position(array $fm): string
{
    $thumb = trim((string)($fm['thumbnail'] ?? ''));
    if ($thumb !== '') {
        return (string)($fm['thumbnail_position'] ?? '');
    }
    return (string)($fm['image_position'] ?? '');
}

/* ------------------------------------------------------------------------- */
/* Escaping                                                                   */
/* ------------------------------------------------------------------------- */

function nano_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------------- */
/* URL helpers                                                                */
/* ------------------------------------------------------------------------- */

function nano_base_url(): string
{
    $base = rtrim((string)(nano_config()['base_url'] ?? ''), '/');
    if ($base === '') {
        throw new RuntimeException('Nano CMS: base_url missing from config.json');
    }
    return $base;
}

function nano_post_url(string $slug, string $category): string
{
    return nano_base_url() . '/' . $category . '/' . $slug . '/';
}

function nano_category_url(string $category): string
{
    return nano_base_url() . '/' . $category . '/';
}

function nano_media_url(string $filename): string
{
    return nano_base_url() . '/media/' . $filename;
}

/**
 * Return the absolute URL of a category's image, or null if no
 * image is set. Convention: image lives at /media/category-<slug>.<ext>
 * for some allowed ext - existence of the file IS the metadata, no
 * sidecar JSON. Prefers the thumbnail if one exists.
 */
function nano_category_image_url(string $slug): ?string
{
    if ($slug === '' || !defined('NANO_CONTENT_PATH')) {
        return null;
    }
    // A managed category record's image (a /media/ filename) takes precedence
    // over the legacy category-<slug>.<ext> convention.
    $rec = nano_load_category($slug);
    if ($rec !== null && trim((string)($rec['image'] ?? '')) !== '') {
        return nano_thumb_url((string)$rec['image']);
    }
    static $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $dir = NANO_CONTENT_PATH . '/media';
    foreach ($allowed_exts as $ext) {
        $base = 'category-' . $slug;
        $original = $base . '.' . $ext;
        if (!is_file($dir . '/' . $original)) {
            continue;
        }
        $thumb = $base . '-thumb.' . $ext;
        if (is_file($dir . '/' . $thumb)) {
            return nano_base_url() . '/media/' . $thumb;
        }
        return nano_base_url() . '/media/' . $original;
    }
    return null;
}

/**
 * Return the URL of the image to use on an article card for the given
 * post frontmatter, or null if the post has no image at all. Order of
 * preference (first match wins):
 *   1. Explicit `thumbnail` frontmatter field (Joomla-style separate
 *      thumbnail) - used as-is, no auto-thumb fallback.
 *   2. Auto-generated thumbnail of the `image` field (if generated).
 *   3. Original `image` file at full size.
 */
function nano_card_image_url(array $fm): ?string
{
    $thumb = trim((string)($fm['thumbnail'] ?? ''));
    if ($thumb !== '') {
        return nano_media_url($thumb);
    }
    $image = trim((string)($fm['image'] ?? ''));
    if ($image !== '') {
        return nano_thumb_url($image);
    }
    return null;
}

/**
 * Alt text for the card image returned by nano_card_image_url(): when a
 * separate `thumbnail` is set, prefer its `thumbnail_alt`; otherwise fall
 * back to the hero `image_alt`, then the post title.
 */
function nano_card_image_alt(array $fm): string
{
    $thumb = trim((string)($fm['thumbnail'] ?? ''));
    if ($thumb !== '') {
        $talt = trim((string)($fm['thumbnail_alt'] ?? ''));
        if ($talt !== '') {
            return $talt;
        }
    }
    $alt = trim((string)($fm['image_alt'] ?? ''));
    return $alt !== '' ? $alt : (string)($fm['title'] ?? '');
}

/**
 * Background colour for the card image returned by nano_card_image_url():
 * the separate thumbnail's `thumbnail_bg` when a thumbnail is set, otherwise
 * the hero image's `image_bg`. The colour follows whichever image is shown.
 */
function nano_card_image_bg(array $fm): string
{
    $thumb = trim((string)($fm['thumbnail'] ?? ''));
    if ($thumb !== '') {
        return (string)($fm['thumbnail_bg'] ?? '');
    }
    return (string)($fm['image_bg'] ?? '');
}

/**
 * Return the URL of the auto-generated thumbnail for a media file
 * (e.g. `2026-05-06-a4f8b2-thumb.jpg`) when one exists on disk, or
 * the original file URL as a fallback. Used by article cards to keep
 * grid pages light without needing every existing post to be
 * re-uploaded after upgrade.
 */
function nano_thumb_url(string $filename): string
{
    if ($filename === '' || !defined('NANO_CONTENT_PATH')) {
        return nano_media_url($filename);
    }
    $dot = strrpos($filename, '.');
    if ($dot === false) {
        return nano_media_url($filename);
    }
    $thumb_name = substr($filename, 0, $dot) . '-thumb' . substr($filename, $dot);
    $thumb_path = NANO_CONTENT_PATH . '/media/' . $thumb_name;
    if (is_file($thumb_path)) {
        return nano_base_url() . '/media/' . $thumb_name;
    }
    return nano_media_url($filename);
}

function nano_index_url(int $page = 1): string
{
    if ($page <= 1) {
        return nano_base_url() . '/';
    }
    return nano_base_url() . '/page/' . $page . '/';
}

/**
 * Label used for the blog index in breadcrumbs and similar nav.
 * Reads `blog_label` from config if set; otherwise derives a label
 * from the last path segment of base_url (so `/blog/` -> "Blog",
 * `/news/` -> "News"). Falls back to "Home" if the blog is at the
 * site root.
 */
function nano_blog_label(): string
{
    $cfg = nano_config();
    $custom = trim((string)($cfg['blog_label'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $path = (string)(parse_url(nano_base_url(), PHP_URL_PATH) ?? '');
    $segment = trim($path, '/');
    if ($segment === '') {
        return 'Home';
    }
    $last = basename($segment);
    return ucfirst(str_replace(['-', '_'], ' ', $last));
}

/* ------------------------------------------------------------------------- */
/* Slug sanitization                                                          */
/* ------------------------------------------------------------------------- */

function nano_safe_slug(string $input): string
{
    $slug = strtolower($input);
    $slug = preg_replace('/[^a-z0-9-]/', '', $slug) ?? '';
    $slug = preg_replace('/-+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

/* ------------------------------------------------------------------------- */
/* Frontmatter parser                                                         */
/* ------------------------------------------------------------------------- */

function nano_read_post_file(string $filepath): array
{
    if (!is_file($filepath) || !is_readable($filepath)) {
        throw new RuntimeException("Nano CMS: post file not readable: $filepath");
    }
    $contents = file_get_contents($filepath);
    if ($contents === false) {
        throw new RuntimeException("Nano CMS: failed to read post file: $filepath");
    }
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);

    if (substr($contents, 0, 4) !== "---\n") {
        throw new RuntimeException("Nano CMS: post missing frontmatter block: $filepath");
    }
    $end = strpos($contents, "\n---\n", 4);
    if ($end === false) {
        // Tolerate a file ending with "\n---" and no trailing newline.
        if (substr($contents, -4) === "\n---") {
            $end = strlen($contents) - 4;
            $body = '';
        } else {
            throw new RuntimeException("Nano CMS: post frontmatter not closed: $filepath");
        }
    } else {
        $body = ltrim(substr($contents, $end + 5), "\n");
    }
    $frontmatter_raw = substr($contents, 4, $end - 4);
    $frontmatter = nano_parse_frontmatter($frontmatter_raw);

    return ['frontmatter' => $frontmatter, 'body' => $body];
}

function nano_parse_frontmatter(string $raw): array
{
    $out = [];
    foreach (explode("\n", $raw) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $key = trim(substr($line, 0, $colon));
        $value = trim(substr($line, $colon + 1));
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if ($first === '"' && $last === '"') {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\\\', '\\"'], ['\\', '"'], $value);
            } elseif ($first === "'" && $last === "'") {
                $value = substr($value, 1, -1);
                $value = str_replace("''", "'", $value);
            }
        }
        if ($key !== '') {
            $out[$key] = $value;
        }
    }

    $out['draft'] = isset($out['draft'])
        && in_array(strtolower((string)$out['draft']), ['true', 'yes', '1'], true);
    foreach (['hero', 'featured'] as $nano_flag) {
        $out[$nano_flag] = isset($out[$nano_flag])
            && in_array(strtolower((string)$out[$nano_flag]), ['true', 'yes', '1'], true);
    }

    foreach (['title', 'slug', 'date', 'category', 'description'] as $required) {
        if (!isset($out[$required]) || $out[$required] === '') {
            throw new RuntimeException("Nano CMS: frontmatter missing required field '$required'");
        }
    }

    return $out;
}

/* ------------------------------------------------------------------------- */
/* Markdown rendering with shortcode expansion                                */
/* ------------------------------------------------------------------------- */

function nano_render_markdown(string $body): string
{
    static $parsedown = null;
    if ($parsedown === null) {
        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);
    }
    $html = $parsedown->text($body);
    $html = nano_rewrite_media_image_srcs($html);
    return nano_expand_shortcodes($html);
}

/**
 * Markdown image syntax `![alt](2026-05-09-abc123.jpg)` renders to
 * `<img src="2026-05-09-abc123.jpg">` - a bare filename, which the
 * browser resolves relative to the current post URL and 404s. The
 * actual file lives at `/blog/media/...`. Patch the src to point at
 * the right place and add lazy loading while we're at it.
 *
 * Only matches our own randomized filename pattern so user-supplied
 * external URLs (https://..., data:, etc) are left alone.
 */
function nano_rewrite_media_image_srcs(string $html): string
{
    $pattern = '~<img\s+([^>]*?)src="(\d{4}-\d{2}-\d{2}-[0-9a-f]{6}\.(?:jpg|jpeg|png|gif|webp))"~i';
    $result = preg_replace_callback($pattern, static function (array $m): string {
        $url = nano_e(nano_media_url($m[2]));
        return '<img ' . $m[1] . 'src="' . $url . '" loading="lazy"';
    }, $html);
    return $result ?? $html;
}

function nano_expand_shortcodes(string $html): string
{
    // Match shortcode optionally wrapped in its own <p>...</p> block, so that
    // a shortcode on its own line becomes a block-level iframe rather than
    // staying inside a paragraph.
    $pattern = '~(?:<p>\s*)?\[video:(youtube|vimeo):([A-Za-z0-9_-]+)\](?:\s*</p>)?~';
    $result = preg_replace_callback($pattern, static function (array $m): string {
        return nano_video_embed($m[1], $m[2]);
    }, $html);
    return $result ?? $html;
}

function nano_video_embed(string $provider, string $id): string
{
    $id_safe = nano_e($id);
    if ($provider === 'youtube') {
        $src = 'https://www.youtube-nocookie.com/embed/' . $id_safe;
    } elseif ($provider === 'vimeo') {
        $src = 'https://player.vimeo.com/video/' . $id_safe;
    } else {
        return '';
    }
    return '<div class="nano-blog-video">'
         . '<iframe src="' . $src . '" loading="lazy" allowfullscreen '
         . 'referrerpolicy="strict-origin-when-cross-origin" '
         . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>'
         . '</div>';
}

/* ------------------------------------------------------------------------- */
/* Public parser entry points                                                 */
/* ------------------------------------------------------------------------- */

/**
 * Parse a post file into frontmatter, raw body, and rendered HTML.
 * @return array{frontmatter: array, body: string, html: string}
 */
function nano_parse_post(string $filepath): array
{
    $parts = nano_read_post_file($filepath);
    $parts['html'] = nano_render_markdown($parts['body']);
    return $parts;
}

/**
 * Return summaries of all posts, sorted newest first.
 * Drafts excluded unless filters['include_drafts'] is true.
 * Optional filters['category'] restricts to a single category.
 *
 * @return array<int, array{frontmatter: array, filepath: string}>
 */
function nano_list_posts(array $filters = []): array
{
    $include_drafts = !empty($filters['include_drafts']);
    $category = $filters['category'] ?? null;

    if (!defined('NANO_CONTENT_PATH')) {
        throw new RuntimeException('Nano CMS: NANO_CONTENT_PATH is not defined');
    }
    $dir = NANO_CONTENT_PATH . '/posts';
    if (!is_dir($dir)) {
        return [];
    }
    $real_dir = realpath($dir);
    if ($real_dir === false) {
        return [];
    }

    $posts = [];
    foreach (glob($dir . '/*.md') ?: [] as $filepath) {
        $real = realpath($filepath);
        // FORMAT.md mandates no subdirectories under /posts/, so a valid
        // post file's parent directory must be the posts directory itself.
        if ($real === false || dirname($real) !== $real_dir) {
            continue;
        }
        try {
            $parts = nano_read_post_file($filepath);
        } catch (RuntimeException $e) {
            error_log('Nano CMS: skipping post ' . $filepath . ' - ' . $e->getMessage());
            continue;
        }
        $fm = $parts['frontmatter'];
        if (!$include_drafts && !empty($fm['draft'])) {
            continue;
        }
        if ($category !== null && ($fm['category'] ?? '') !== $category) {
            continue;
        }
        $posts[] = ['frontmatter' => $fm, 'filepath' => $filepath];
    }

    usort($posts, static function (array $a, array $b): int {
        return strcmp($b['frontmatter']['date'], $a['frontmatter']['date']);
    });

    return $posts;
}

/**
 * Group published posts by category and return one entry per distinct
 * category. Sorted by count descending, ties broken alphabetically by
 * label. Drafts excluded (matches nano_list_posts() defaults).
 *
 * @return array<int, array{
 *     slug: string,
 *     label: string,
 *     count: int,
 *     latest_title: string,
 *     latest_date: string,
 * }>
 */
/**
 * Load a managed category record (categories/<slug>.json) or null if the
 * category has no record yet. Records are optional metadata - a category
 * works fine without one (it's still derived from the posts that use it).
 */
function nano_load_category(string $slug): ?array
{
    if ($slug === '' || !defined('NANO_CONTENT_PATH') || $slug !== nano_safe_slug($slug)) {
        return null;
    }
    $path = NANO_CONTENT_PATH . '/categories/' . $slug . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function nano_list_categories_with_counts(): array
{
    $by_slug = [];
    // nano_list_posts() returns newest-first, so the first post we see
    // in each category is the latest by date. PHP's usort is stable
    // since 8.0, so same-date ties fall back to glob's filename order.
    foreach (nano_list_posts() as $entry) {
        $fm = $entry['frontmatter'];
        $slug = trim((string)($fm['category'] ?? ''));
        if ($slug === '') {
            continue;
        }
        if (!isset($by_slug[$slug])) {
            $by_slug[$slug] = [
                'slug' => $slug,
                'label' => ucfirst(str_replace('-', ' ', $slug)),
                'count' => 0,
                'latest_title' => (string)($fm['title'] ?? ''),
                'latest_date' => (string)($fm['date'] ?? ''),
            ];
        }
        $by_slug[$slug]['count']++;
    }

    // Overlay managed category records: a record's name becomes the display
    // label and its description rides along, falling back to the derived
    // label when no record exists.
    foreach ($by_slug as $slug => $c) {
        $rec = nano_load_category($slug);
        if ($rec === null) {
            continue;
        }
        if (trim((string)($rec['name'] ?? '')) !== '') {
            $by_slug[$slug]['label'] = (string)$rec['name'];
        }
        $by_slug[$slug]['description'] = (string)($rec['description'] ?? '');
        $by_slug[$slug]['image_bg'] = (string)($rec['image_bg'] ?? '');
        $by_slug[$slug]['image_alt'] = (string)($rec['image_alt'] ?? '');
        $by_slug[$slug]['image_fit'] = (string)($rec['image_fit'] ?? '');
        $by_slug[$slug]['image_focus'] = (string)($rec['image_focus'] ?? '');
        if (array_key_exists('sort_order', $rec)) {
            $by_slug[$slug]['sort_order'] = (int)$rec['sort_order'];
        }
        if (array_key_exists('homepage_slot', $rec)) {
            $by_slug[$slug]['homepage_slot'] = (int)$rec['homepage_slot'];
        }
    }

    $cats = array_values($by_slug);
    // Manual order first (sort_order on the category record, lower leads),
    // then alphabetical by label for any without one. Mirrors Cart.
    usort($cats, static function (array $a, array $b): int {
        $oa = $a['sort_order'] ?? PHP_INT_MAX;
        $ob = $b['sort_order'] ?? PHP_INT_MAX;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcmp($a['label'], $b['label']);
    });
    return $cats;
}

/**
 * The categories to show in the homepage grid. Each category may carry a
 * `homepage_slot` (1..$cap). If any category is slotted, only the slotted
 * ones are shown, ordered by slot number. If none are slotted, all
 * categories are returned, so an existing blog is unchanged until an
 * operator starts assigning slots. The off-canvas category nav always
 * lists every category regardless of slots.
 *
 * @param array $categories Output of nano_list_categories_with_counts().
 * @param int   $cap        Maximum slots, = categories_per_row * 2 (6 or 8).
 */
function nano_homepage_categories(array $categories, int $cap): array
{
    $slotted = [];
    foreach ($categories as $c) {
        if (!array_key_exists('homepage_slot', $c)) {
            continue;
        }
        $slot = (int)$c['homepage_slot'];
        if ($slot < 1 || $slot > $cap) {
            continue;
        }
        if (isset($slotted[$slot])) {
            continue; // first category wins a contested slot
        }
        $slotted[$slot] = $c;
    }
    if (empty($slotted)) {
        return $categories; // fallback: no slots assigned yet
    }
    ksort($slotted);
    return array_values($slotted);
}

/**
 * Render the section sub-nav shown above each page's heading: an "All" link
 * plus one link per category, with the current section marked active. Driven
 * by the category list, so new categories appear automatically. Returns
 * already-escaped HTML, or '' when there are no categories.
 */
function nano_category_nav_html(string $current_slug = ''): string
{
    $cats = nano_list_categories_with_counts();
    if (empty($cats)) {
        return '';
    }
    $out  = '<nav class="nano-blog-catnav" aria-label="Categories">';
    $out .= '<input type="checkbox" id="nano-blog-catnav-toggle" class="nano-blog-catnav-toggle">';
    $out .= '<label class="nano-blog-catnav-btn" for="nano-blog-catnav-toggle">'
          . '<svg class="nano-blog-catnav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>'
          . '<span>Categories</span></label>';
    $out .= '<label class="nano-blog-catnav-backdrop" for="nano-blog-catnav-toggle" aria-hidden="true"></label>';
    $out .= '<aside class="nano-blog-catnav-panel" aria-label="Categories">';
    $out .= '<div class="nano-blog-catnav-head"><span>Categories</span>'
          . '<label class="nano-blog-catnav-close" for="nano-blog-catnav-toggle" aria-label="Close">&times;</label></div>';
    $out .= '<div class="nano-blog-catnav-links">';
    $out .= '<a' . ($current_slug === '' ? ' class="is-active" aria-current="page"' : '') . ' href="'
          . nano_e(nano_index_url(1)) . '">' . nano_e(nano_blog_label()) . '</a>';
    foreach ($cats as $c) {
        $slug = (string)($c['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $active = $slug === $current_slug ? ' class="is-active" aria-current="page"' : '';
        $out .= '<a href="' . nano_e(nano_category_url($slug)) . '"' . $active . '>'
              . nano_e((string)($c['label'] ?? $slug)) . '</a>';
    }
    $out .= '</div></aside></nav>';
    return $out;
}

/* ------------------------------------------------------------------------- */
/* SEO meta tag generation                                                    */
/* ------------------------------------------------------------------------- */

/**
 * Build canonical, Open Graph, Twitter Card, and JSON-LD BlogPosting tags
 * for a single post. Returns a block of HTML safe to drop into <head>.
 */
function nano_render_meta_tags_for_post(array $fm): string
{
    $cfg = nano_config();
    $url = nano_post_url((string)$fm['slug'], (string)$fm['category']);
    $title = (string)$fm['title'];
    $desc = (string)$fm['description'];
    $site_name = (string)($cfg['site_name'] ?? '');
    $locale = (string)($cfg['locale'] ?? 'en_US');
    $author = (string)($cfg['author'] ?? '');
    $publisher_name = (string)($cfg['publisher_name'] ?? $site_name);
    $publisher_logo = (string)($cfg['publisher_logo'] ?? '');
    $image_url = !empty($fm['image']) ? nano_media_url((string)$fm['image']) : null;
    $image_alt = (string)($fm['image_alt'] ?? $title);

    $tags = [];
    $tags[] = '<link rel="canonical" href="' . nano_e($url) . '">';
    $tags[] = '<meta property="og:type" content="article">';
    $tags[] = '<meta property="og:title" content="' . nano_e($title) . '">';
    $tags[] = '<meta property="og:description" content="' . nano_e($desc) . '">';
    $tags[] = '<meta property="og:url" content="' . nano_e($url) . '">';
    if ($site_name !== '') {
        $tags[] = '<meta property="og:site_name" content="' . nano_e($site_name) . '">';
    }
    $tags[] = '<meta property="og:locale" content="' . nano_e($locale) . '">';
    if ($image_url !== null) {
        $tags[] = '<meta property="og:image" content="' . nano_e($image_url) . '">';
        $tags[] = '<meta property="og:image:alt" content="' . nano_e($image_alt) . '">';
        $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        $tags[] = '<meta name="twitter:image" content="' . nano_e($image_url) . '">';
    } else {
        $tags[] = '<meta name="twitter:card" content="summary">';
    }
    $tags[] = '<meta name="twitter:title" content="' . nano_e($title) . '">';
    $tags[] = '<meta name="twitter:description" content="' . nano_e($desc) . '">';

    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $title,
        'description' => $desc,
        'datePublished' => (string)$fm['date'],
        'mainEntityOfPage' => $url,
    ];
    if (!empty($fm['updated'])) {
        $ld['dateModified'] = (string)$fm['updated'];
    }
    if ($image_url !== null) {
        $ld['image'] = $image_url;
    }
    if ($author !== '') {
        $ld['author'] = ['@type' => 'Person', 'name' => $author];
    }
    if ($publisher_name !== '') {
        $publisher = ['@type' => 'Organization', 'name' => $publisher_name];
        if ($publisher_logo !== '') {
            $publisher['logo'] = ['@type' => 'ImageObject', 'url' => $publisher_logo];
        }
        $ld['publisher'] = $publisher;
    }
    // JSON_HEX_TAG/AMP/APOS/QUOT guarantee no `<`, `&`, `'`, `"` slip into
    // the inline <script> as literal characters - any admin-supplied field
    // (title, description, author) that happens to contain `</script>` or
    // similar can't break out of the JSON-LD block.
    $tags[] = '<script type="application/ld+json">'
        . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';

    return implode("\n", $tags);
}

/**
 * Build canonical, Open Graph, Twitter Card, and CollectionPage JSON-LD
 * tags for the index or a category archive. Returns a block of HTML
 * safe to drop into <head>.
 */
function nano_render_meta_tags_for_index(?string $category = null, int $page = 1): string
{
    $cfg = nano_config();
    $site_name = (string)($cfg['site_name'] ?? 'Blog');
    $locale = (string)($cfg['locale'] ?? 'en_US');

    if ($category !== null) {
        $cat_label = ucfirst(str_replace('-', ' ', $category));
        $url = nano_category_url($category);
        $title = $cat_label . ' - ' . $site_name;
        $desc = 'Posts in the ' . $cat_label . ' category.';
        $posts_for_image = nano_list_posts(['category' => $category]);
    } else {
        $url = nano_index_url($page);
        $title = $page > 1 ? $site_name . ' - Page ' . $page : $site_name;
        $desc = $site_name . ' - latest posts.';
        $posts_for_image = nano_list_posts();
    }

    // og:image fallback - first post in the listing that has a hero image.
    // Gives social-share previews of category/index URLs a real thumbnail
    // instead of a blank text card.
    $og_image = null;
    foreach ($posts_for_image as $p) {
        $img = trim((string)($p['frontmatter']['image'] ?? ''));
        if ($img !== '') {
            $og_image = nano_media_url($img);
            break;
        }
    }

    $tags = [];
    $tags[] = '<link rel="canonical" href="' . nano_e($url) . '">';
    $tags[] = '<meta property="og:type" content="website">';
    $tags[] = '<meta property="og:title" content="' . nano_e($title) . '">';
    $tags[] = '<meta property="og:description" content="' . nano_e($desc) . '">';
    $tags[] = '<meta property="og:url" content="' . nano_e($url) . '">';
    if ($site_name !== '') {
        $tags[] = '<meta property="og:site_name" content="' . nano_e($site_name) . '">';
    }
    $tags[] = '<meta property="og:locale" content="' . nano_e($locale) . '">';
    if ($og_image !== null) {
        $tags[] = '<meta property="og:image" content="' . nano_e($og_image) . '">';
        $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        $tags[] = '<meta name="twitter:image" content="' . nano_e($og_image) . '">';
    } else {
        $tags[] = '<meta name="twitter:card" content="summary">';
    }
    $tags[] = '<meta name="twitter:title" content="' . nano_e($title) . '">';
    $tags[] = '<meta name="twitter:description" content="' . nano_e($desc) . '">';

    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $title,
        'description' => $desc,
        'url' => $url,
    ];
    // JSON_HEX_TAG/AMP/APOS/QUOT guarantee no `<`, `&`, `'`, `"` slip into
    // the inline <script> as literal characters - any admin-supplied field
    // (title, description, author) that happens to contain `</script>` or
    // similar can't break out of the JSON-LD block.
    $tags[] = '<script type="application/ld+json">'
        . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';

    return implode("\n", $tags);
}

/**
 * Find a post file by its frontmatter slug. Returns absolute filepath or null.
 */
function nano_find_post_by_slug(string $slug, bool $include_drafts = false): ?string
{
    if ($slug === '' || $slug !== nano_safe_slug($slug)) {
        return null;
    }
    foreach (nano_list_posts(['include_drafts' => $include_drafts]) as $entry) {
        if (($entry['frontmatter']['slug'] ?? '') === $slug) {
            return $entry['filepath'];
        }
    }
    return null;
}
