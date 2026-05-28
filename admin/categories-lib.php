<?php
/**
 * Nano CMS - admin category record helpers.
 *
 * Managed categories are optional metadata records stored one-per-file at
 * NANO_CONTENT_PATH/categories/<slug>.json:
 *
 *   { slug, name, description, image, created, updated }
 *
 * Membership stays driven by each post's `category:` frontmatter (model A):
 * a record only adds a display name, description, and hero image. A category
 * with no record still works - it's derived from the posts that use it.
 *
 * Independent of the frontend's nano_load_category(); the two codebases
 * share only the on-disk format. Requires admin/core.php + admin/posts.php.
 */

if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

function nano_admin_categories_dir(): string
{
    if (!defined('NANO_CONTENT_PATH')) {
        throw new RuntimeException('Nano CMS admin: NANO_CONTENT_PATH is not defined');
    }
    return NANO_CONTENT_PATH . '/categories';
}

function nano_admin_category_record_path(string $slug): string
{
    return nano_admin_categories_dir() . '/' . $slug . '.json';
}

function nano_admin_load_category(string $slug): ?array
{
    if ($slug === '' || $slug !== nano_admin_safe_slug($slug)) {
        return null;
    }
    $path = nano_admin_category_record_path($slug);
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

/**
 * Create or update a category record. Slug is sanitised and authoritative
 * (it's the membership key posts reference, so it never changes silently).
 */
function nano_admin_save_category(array $cat): bool
{
    $slug = nano_admin_safe_slug((string)($cat['slug'] ?? ''));
    if ($slug === '') {
        return false;
    }
    $dir = nano_admin_categories_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $existing = nano_admin_load_category($slug);
    $record = [
        'slug'        => $slug,
        'name'        => trim((string)($cat['name'] ?? '')),
        'description' => trim((string)($cat['description'] ?? '')),
        'image'       => trim((string)($cat['image'] ?? '')),
        'image_position' => (($cat['image_position'] ?? '') === 'right') ? 'right' : 'left',
        'image_bg'    => preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim((string)($cat['image_bg'] ?? ''))) ? trim((string)$cat['image_bg']) : '',
        'created'     => (string)($existing['created'] ?? $cat['created'] ?? $now),
        'updated'     => $now,
    ];
    // Optional manual display order (lower leads). Stored only when set, so
    // categories without one sink to the bottom alphabetically - mirrors Cart.
    if (isset($cat['sort_order']) && $cat['sort_order'] !== '' && is_numeric($cat['sort_order'])) {
        $record['sort_order'] = (int)$cat['sort_order'];
    }
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    nano_admin_atomic_write(nano_admin_category_record_path($slug), $json . "\n", 0644);
    return true;
}

function nano_admin_delete_category(string $slug): bool
{
    if ($slug === '' || $slug !== nano_admin_safe_slug($slug)) {
        return false;
    }
    $path = nano_admin_category_record_path($slug);
    $real = is_file($path) ? realpath($path) : false;
    $dir_real = is_dir(nano_admin_categories_dir()) ? realpath(nano_admin_categories_dir()) : false;
    if ($real === false || $dir_real === false || dirname($real) !== $dir_real) {
        return false;
    }
    return (bool)@unlink($real);
}

/** All category records keyed by slug. */
function nano_admin_list_category_records(): array
{
    $dir = nano_admin_categories_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data) && !empty($data['slug'])) {
            $out[(string)$data['slug']] = $data;
        }
    }
    return $out;
}

/** Number of posts (drafts included) per category slug. */
function nano_admin_category_post_counts(): array
{
    $counts = [];
    foreach (nano_admin_list_posts(true) as $entry) {
        $c = trim((string)($entry['frontmatter']['category'] ?? ''));
        if ($c !== '') {
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }
    }
    return $counts;
}

/**
 * Merged view for the manager: every category that has a record OR is used
 * by a post, with display name, description, image, post count, and whether
 * a record exists. Sorted by name.
 *
 * @return array<int, array{slug:string,name:string,description:string,image:string,count:int,has_record:bool}>
 */
function nano_admin_all_categories(): array
{
    $records = nano_admin_list_category_records();
    $counts  = nano_admin_category_post_counts();
    $slugs   = array_values(array_unique(array_merge(array_keys($records), array_keys($counts))));
    $out = [];
    foreach ($slugs as $slug) {
        $rec = $records[$slug] ?? null;
        $name = ($rec !== null && trim((string)($rec['name'] ?? '')) !== '')
            ? (string)$rec['name']
            : ucfirst(str_replace('-', ' ', $slug));
        $out[] = [
            'slug'        => $slug,
            'name'        => $name,
            'description' => (string)($rec['description'] ?? ''),
            'image'       => (string)($rec['image'] ?? ''),
            'image_bg'    => (string)($rec['image_bg'] ?? ''),
            'count'       => (int)($counts[$slug] ?? 0),
            'has_record'  => $rec !== null,
            'sort_order'  => ($rec !== null && array_key_exists('sort_order', $rec)) ? (int)$rec['sort_order'] : null,
        ];
    }
    // Manual order first (sort_order, lower leads), then alphabetical by name
    // for any without one. Mirrors Cart's category ordering.
    usort($out, static function ($a, $b) {
        $oa = $a['sort_order'] ?? PHP_INT_MAX;
        $ob = $b['sort_order'] ?? PHP_INT_MAX;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}
