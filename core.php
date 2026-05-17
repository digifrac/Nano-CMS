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

    $cats = array_values($by_slug);
    usort($cats, static function (array $a, array $b): int {
        if ($a['count'] !== $b['count']) {
            return $b['count'] <=> $a['count'];
        }
        return strcmp($a['label'], $b['label']);
    });
    return $cats;
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
    $tags[] = '<script type="application/ld+json">'
        . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
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
    $tags[] = '<script type="application/ld+json">'
        . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
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
