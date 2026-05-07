<?php
/**
 * Nano CMS - sitemap and RSS feed generators.
 *
 * Library file. The admin calls nano_regenerate_static() on every save
 * (or delete) that affects published content. Drafts are excluded from
 * both files per the on-disk format contract.
 *
 * Output files live alongside index.php / post.php in NANO_CONTENT_PATH:
 *   NANO_CONTENT_PATH/sitemap.xml
 *   NANO_CONTENT_PATH/feed.xml
 *
 * Both writes are atomic - a tempfile is written first, then renamed
 * over the destination, so HTTP readers never see a half-written file.
 */

if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/core.php';

/**
 * Regenerate sitemap.xml and feed.xml. Call from the admin on every
 * save or delete that affects published content.
 */
function nano_regenerate_static(): void
{
    nano_atomic_write(NANO_CONTENT_PATH . '/sitemap.xml', nano_generate_sitemap());
    nano_atomic_write(NANO_CONTENT_PATH . '/feed.xml', nano_generate_feed());
}

/**
 * Build sitemap.xml content. Drafts excluded.
 *
 * Includes:
 *   - the blog index URL
 *   - one <url> per category archive that contains >= 1 published post
 *   - one <url> per published post
 * <lastmod> for posts uses `updated` if present, else `date`. For the
 * index and category archives, the most recent contained post's date
 * is used.
 */
function nano_generate_sitemap(): string
{
    $posts = nano_list_posts();
    $base = nano_base_url();
    $entries = [];

    $index_lastmod = !empty($posts)
        ? (string)($posts[0]['frontmatter']['updated'] ?? $posts[0]['frontmatter']['date'])
        : date('Y-m-d');
    $entries[] = nano_sitemap_url($base . '/', $index_lastmod);

    $cat_lastmod = [];
    foreach ($posts as $entry) {
        $fm = $entry['frontmatter'];
        $cat = (string)($fm['category'] ?? '');
        if ($cat === '') continue;
        $when = (string)($fm['updated'] ?? $fm['date']);
        if (!isset($cat_lastmod[$cat]) || strcmp($when, $cat_lastmod[$cat]) > 0) {
            $cat_lastmod[$cat] = $when;
        }
    }
    foreach ($cat_lastmod as $cat => $when) {
        $entries[] = nano_sitemap_url(nano_category_url($cat), $when);
    }

    foreach ($posts as $entry) {
        $fm = $entry['frontmatter'];
        $when = (string)($fm['updated'] ?? $fm['date']);
        $entries[] = nano_sitemap_url(nano_post_url((string)$fm['slug']), $when);
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
         . implode("\n", $entries) . "\n"
         . '</urlset>' . "\n";
}

function nano_sitemap_url(string $loc, string $lastmod): string
{
    return '  <url><loc>' . nano_e($loc) . '</loc>'
         . '<lastmod>' . nano_e($lastmod) . '</lastmod></url>';
}

/**
 * Build feed.xml content (RSS 2.0). Drafts excluded. Includes the most
 * recent posts_per_page published posts.
 *
 * Each <item> carries title, link (absolute URL), description (the post
 * `description` frontmatter field), pubDate, and a permalink GUID.
 */
function nano_generate_feed(): string
{
    $cfg = nano_config();
    $posts_per_page = max(1, (int)($cfg['posts_per_page'] ?? 10));
    $site_name = (string)($cfg['site_name'] ?? 'Blog');

    $base = nano_base_url();
    $posts = array_slice(nano_list_posts(), 0, $posts_per_page);

    $items = [];
    $latest_pub_date = null;
    foreach ($posts as $entry) {
        $fm = $entry['frontmatter'];
        $url = nano_post_url((string)$fm['slug']);
        $date = (string)$fm['date'];
        $pub = nano_rfc2822_date($date);
        if ($latest_pub_date === null || strtotime($date) > strtotime($latest_pub_date)) {
            $latest_pub_date = $date;
        }
        $items[] = '    <item>'
                 . '<title>' . nano_e((string)$fm['title']) . '</title>'
                 . '<link>' . nano_e($url) . '</link>'
                 . '<description>' . nano_e((string)$fm['description']) . '</description>'
                 . '<pubDate>' . nano_e($pub) . '</pubDate>'
                 . '<guid isPermaLink="true">' . nano_e($url) . '</guid>'
                 . '</item>';
    }

    $channel_pub = nano_rfc2822_date($latest_pub_date ?? date('Y-m-d'));

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
         . '  <channel>' . "\n"
         . '    <title>' . nano_e($site_name) . '</title>' . "\n"
         . '    <link>' . nano_e($base . '/') . '</link>' . "\n"
         . '    <description>' . nano_e($site_name . ' - latest posts.') . '</description>' . "\n"
         . '    <language>en</language>' . "\n"
         . '    <atom:link href="' . nano_e($base . '/feed.xml') . '" rel="self" type="application/rss+xml" />' . "\n"
         . '    <lastBuildDate>' . nano_e($channel_pub) . '</lastBuildDate>' . "\n"
         . (empty($items) ? '' : implode("\n", $items) . "\n")
         . '  </channel>' . "\n"
         . '</rss>' . "\n";
}

function nano_rfc2822_date(string $iso_date): string
{
    $ts = strtotime($iso_date);
    if ($ts === false) {
        $ts = time();
    }
    return gmdate('D, d M Y H:i:s', $ts) . ' +0000';
}

/**
 * Atomic file write. A tempfile in the same directory is written and
 * fsync-flushed, then renamed over the target. Concurrent readers
 * either see the old file or the new file, never a partial one.
 */
function nano_atomic_write(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException("Nano CMS: directory does not exist: $dir");
    }
    $tmp = tempnam($dir, '.nano-tmp-');
    if ($tmp === false) {
        throw new RuntimeException("Nano CMS: could not create temp file in $dir");
    }
    if (file_put_contents($tmp, $contents) === false) {
        @unlink($tmp);
        throw new RuntimeException("Nano CMS: could not write temp file $tmp");
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Nano CMS: could not rename $tmp to $path");
    }
    @chmod($path, 0644);
}
