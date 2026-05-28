<?php
/**
 * Nano CMS - admin post helpers.
 *
 * Independent of the frontend's core.php. The two codebases share only
 * the on-disk file format described in FORMAT.md - never PHP code.
 */

if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

// admin/lib/Parsedown.php is vendored on disk but deliberately not
// loaded here: nano_regenerate_static() pulls in the frontend's copy
// on every save, and a second `class Parsedown` declaration in the
// same request is fatal. Any future server-side Markdown rendering in
// admin must live on a code path that doesn't reach generators.php.

const NANO_ADMIN_FRONTMATTER_FIELDS = [
    'title', 'slug', 'date', 'updated', 'category',
    'description', 'image', 'image_alt', 'thumbnail', 'draft', 'hero', 'featured',
];

const NANO_ADMIN_FRONTMATTER_REQUIRED = [
    'title', 'slug', 'date', 'category', 'description',
];

/* ------------------------------------------------------------------------ */
/* Filesystem layout                                                         */
/* ------------------------------------------------------------------------ */

function nano_admin_posts_dir(): string
{
    if (!defined('NANO_CONTENT_PATH')) {
        throw new RuntimeException('Nano CMS admin: NANO_CONTENT_PATH is not defined');
    }
    return NANO_CONTENT_PATH . '/posts';
}

function nano_admin_posts_real_dir(): string
{
    $dir = nano_admin_posts_dir();
    if (!is_dir($dir)) {
        throw new RuntimeException("Nano CMS admin: posts directory missing: $dir");
    }
    $real = realpath($dir);
    if ($real === false) {
        throw new RuntimeException("Nano CMS admin: posts directory not resolvable: $dir");
    }
    return $real;
}

/* ------------------------------------------------------------------------ */
/* Slug + filename helpers                                                   */
/* ------------------------------------------------------------------------ */

function nano_admin_safe_slug(string $input): string
{
    $slug = strtolower($input);
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?? '';
    $slug = preg_replace('/-+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

function nano_admin_post_filename(string $date, string $slug): string
{
    return $date . '-' . $slug . '.md';
}

function nano_admin_post_path(string $date, string $slug): string
{
    return nano_admin_posts_dir() . '/' . nano_admin_post_filename($date, $slug);
}

/**
 * Validate that $path resolves inside the posts directory. Used before
 * any read/write/delete to prevent path traversal via crafted slugs.
 */
function nano_admin_assert_inside_posts(string $path): void
{
    $real_root = nano_admin_posts_real_dir();
    $dir = dirname($path);
    $real_dir = realpath($dir);
    if ($real_dir === false || $real_dir !== $real_root) {
        throw new RuntimeException('Nano CMS admin: path escapes posts directory');
    }
}

/* ------------------------------------------------------------------------ */
/* Frontmatter parser (admin's own copy)                                     */
/* ------------------------------------------------------------------------ */

/**
 * Parse a post file. Returns ['frontmatter' => array, 'body' => string,
 * 'filepath' => string].
 */
function nano_admin_read_post(string $filepath): array
{
    if (!is_file($filepath) || !is_readable($filepath)) {
        throw new RuntimeException("Nano CMS admin: post not readable: $filepath");
    }
    $contents = file_get_contents($filepath);
    if ($contents === false) {
        throw new RuntimeException("Nano CMS admin: failed to read post: $filepath");
    }
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    if (substr($contents, 0, 4) !== "---\n") {
        throw new RuntimeException("Nano CMS admin: post missing frontmatter: $filepath");
    }
    $end = strpos($contents, "\n---\n", 4);
    if ($end === false) {
        if (substr($contents, -4) === "\n---") {
            $end = strlen($contents) - 4;
            $body = '';
        } else {
            throw new RuntimeException("Nano CMS admin: frontmatter not closed: $filepath");
        }
    } else {
        $body = ltrim(substr($contents, $end + 5), "\n");
    }
    $fm = nano_admin_parse_frontmatter(substr($contents, 4, $end - 4));
    return ['frontmatter' => $fm, 'body' => $body, 'filepath' => $filepath];
}

function nano_admin_parse_frontmatter(string $raw): array
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
    foreach (['hero', 'featured'] as $flag) {
        $out[$flag] = isset($out[$flag])
            && in_array(strtolower((string)$out[$flag]), ['true', 'yes', '1'], true);
    }
    return $out;
}

/* ------------------------------------------------------------------------ */
/* Frontmatter writer                                                        */
/* ------------------------------------------------------------------------ */

/**
 * Serialize frontmatter + body to the on-disk format. Field order
 * matches FORMAT.md. Optional empty-string fields are omitted. The
 * draft flag is always emitted as a bare boolean.
 */
function nano_admin_serialize_post(array $fm, string $body): string
{
    $lines = ["---"];
    foreach (NANO_ADMIN_FRONTMATTER_FIELDS as $key) {
        if (!array_key_exists($key, $fm)) {
            continue;
        }
        $value = $fm[$key];
        if ($key === 'draft') {
            $lines[] = 'draft: ' . (!empty($value) ? 'true' : 'false');
            continue;
        }
        // hero/featured are optional booleans: emitted only when true so
        // they don't clutter the frontmatter of ordinary posts.
        if ($key === 'hero' || $key === 'featured') {
            if (!empty($value)) {
                $lines[] = $key . ': true';
            }
            continue;
        }
        $value = (string)$value;
        if ($value === '' && !in_array($key, NANO_ADMIN_FRONTMATTER_REQUIRED, true)) {
            continue;
        }
        $lines[] = $key . ': ' . nano_admin_yaml_value($value);
    }
    $lines[] = '---';
    $lines[] = '';
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    return implode("\n", $lines) . "\n" . rtrim($body, "\n") . "\n";
}

/**
 * Encode a frontmatter scalar. The parser only strips outer matching
 * quotes, so we only need to wrap a value when it would otherwise be
 * mis-read - i.e. it already starts AND ends with the same quote
 * character, or the parser would trim away leading/trailing whitespace.
 *
 * To avoid escape sequences, we wrap with whichever quote character is
 * NOT already present in the value. If both are present (rare) we fall
 * back to double quotes with backslash escaping; the parser unescapes
 * \" and \\ inside double-quoted scalars.
 */
function nano_admin_yaml_value(string $value): string
{
    if ($value === '') {
        return '""';
    }
    if (strpos($value, "\n") !== false) {
        throw new RuntimeException('Frontmatter value cannot contain newlines.');
    }
    $needs_quote = false;
    if (strlen($value) >= 2) {
        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $needs_quote = true;
        }
    }
    if ($value !== trim($value)) {
        $needs_quote = true;
    }
    if (!$needs_quote) {
        return $value;
    }
    if (strpos($value, '"') === false) {
        return '"' . $value . '"';
    }
    if (strpos($value, "'") === false) {
        return "'" . $value . "'";
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

/* ------------------------------------------------------------------------ */
/* Listing + categories                                                      */
/* ------------------------------------------------------------------------ */

/**
 * Return summaries of every post on disk. Drafts included by default
 * because the admin needs to see them. Sorted newest-first by date.
 *
 * @return array<int, array{frontmatter: array, filepath: string}>
 */
function nano_admin_list_posts(bool $include_drafts = true): array
{
    $dir = nano_admin_posts_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $real_root = nano_admin_posts_real_dir();
    $posts = [];
    foreach (glob($dir . '/*.md') ?: [] as $filepath) {
        $real = realpath($filepath);
        if ($real === false || dirname($real) !== $real_root) {
            continue;
        }
        try {
            $parts = nano_admin_read_post($filepath);
        } catch (RuntimeException $e) {
            error_log('Nano CMS admin: skipping ' . $filepath . ' - ' . $e->getMessage());
            continue;
        }
        if (!$include_drafts && !empty($parts['frontmatter']['draft'])) {
            continue;
        }
        $posts[] = ['frontmatter' => $parts['frontmatter'], 'filepath' => $filepath];
    }
    usort($posts, static function (array $a, array $b): int {
        return strcmp(
            (string)($b['frontmatter']['date'] ?? ''),
            (string)($a['frontmatter']['date'] ?? '')
        );
    });
    return $posts;
}

/**
 * Distinct list of `category` values across all posts (drafts included),
 * sorted alphabetically. Used for autocomplete in the editor.
 *
 * @return array<int, string>
 */
function nano_admin_categories(): array
{
    $cats = [];
    foreach (nano_admin_list_posts(true) as $entry) {
        $cat = trim((string)($entry['frontmatter']['category'] ?? ''));
        if ($cat !== '') {
            $cats[$cat] = true;
        }
    }
    $list = array_keys($cats);
    sort($list, SORT_STRING);
    return $list;
}

/**
 * Find a post by its frontmatter slug. Returns the absolute filepath or
 * null if no post has that slug.
 */
function nano_admin_find_post_by_slug(string $slug): ?string
{
    if ($slug === '' || $slug !== nano_admin_safe_slug($slug)) {
        return null;
    }
    foreach (nano_admin_list_posts(true) as $entry) {
        if (($entry['frontmatter']['slug'] ?? '') === $slug) {
            return $entry['filepath'];
        }
    }
    return null;
}

/* ------------------------------------------------------------------------ */
/* Save + delete                                                             */
/* ------------------------------------------------------------------------ */

/**
 * Atomically write a post file to disk. If $original_filepath is given
 * and the new on-disk path differs (slug or date changed), the old file
 * is removed after the new one is in place. Returns the resolved final
 * filepath. Caller is responsible for calling nano_regenerate_static()
 * after a successful save.
 *
 * Enforced invariants:
 *  - Required frontmatter fields present (title, slug, date, category, description).
 *  - Slug sanitised to [a-z0-9-]+ and non-empty.
 *  - Date in YYYY-MM-DD format.
 *  - Filename always reconciled to YYYY-MM-DD-{slug}.md.
 *  - No collision with a different existing post.
 *  - Final write is atomic (tempfile + rename inside the posts dir).
 */
function nano_admin_save_post(array $fm, string $body, ?string $original_filepath = null): string
{
    foreach (NANO_ADMIN_FRONTMATTER_REQUIRED as $required) {
        $value = trim((string)($fm[$required] ?? ''));
        if ($value === '') {
            throw new RuntimeException("Required field missing: $required");
        }
    }
    $slug = nano_admin_safe_slug((string)$fm['slug']);
    if ($slug === '') {
        throw new RuntimeException('Slug must contain at least one of [a-z0-9-]');
    }
    $fm['slug'] = $slug;
    $date = (string)$fm['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Date must be YYYY-MM-DD');
    }
    if (!empty($fm['updated']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fm['updated'])) {
        throw new RuntimeException('Updated must be YYYY-MM-DD or empty');
    }
    $fm['category'] = nano_admin_safe_slug((string)$fm['category']);
    if ($fm['category'] === '') {
        throw new RuntimeException('Category must contain at least one of [a-z0-9-]');
    }

    // Image + thumbnail are referenced from frontmatter and rendered into
    // <img src=> by the frontend. Restrict to plain filenames with an
    // allowed image extension - blocks `../bootstrap.php`, `foo.svg`
    // (script-bearing), and similar paths a hand-edit or SFTP drop could
    // sneak past the upload pipeline's own checks.
    foreach (['image', 'thumbnail'] as $img_key) {
        $value = trim((string)($fm[$img_key] ?? ''));
        if ($value === '') {
            continue;
        }
        if (str_contains($value, '..') || !preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\.(?:jpg|jpeg|png|gif|webp)$#i', $value)) {
            throw new RuntimeException(
                ucfirst($img_key) . ' must be a media filename or folder path ending in .jpg, .jpeg, .png, .gif, or .webp.'
            );
        }
    }

    $target = nano_admin_post_path($date, $slug);

    // Refuse if the target path is already taken by a *different* post.
    if (is_file($target)
        && ($original_filepath === null
            || realpath($target) !== realpath($original_filepath))
    ) {
        throw new RuntimeException('A post with that date and slug already exists.');
    }

    $contents = nano_admin_serialize_post($fm, $body);

    $dir = nano_admin_posts_dir();
    if (!is_dir($dir)) {
        throw new RuntimeException("Posts directory missing: $dir");
    }
    $tmp = tempnam($dir, '.nano-tmp-');
    if ($tmp === false || file_put_contents($tmp, $contents) === false) {
        if (is_string($tmp)) @unlink($tmp);
        throw new RuntimeException('Failed to write temp post file');
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException("Failed to rename temp file to $target");
    }
    @chmod($target, 0644);

    // Only one post can be the homepage hero. When this save sets hero,
    // clear the flag on every other post so the homepage never has two.
    if (!empty($fm['hero'])) {
        nano_admin_clear_other_heroes($slug);
    }

    // If renaming an existing post, remove the old file *after* the new
    // one is in place. realpath() comparison so a same-path save
    // (slug+date unchanged) is a no-op.
    if ($original_filepath !== null && is_file($original_filepath)) {
        $orig_real = realpath($original_filepath);
        $new_real = realpath($target);
        if ($orig_real !== false && $new_real !== false && $orig_real !== $new_real) {
            nano_admin_assert_inside_posts($original_filepath);
            @unlink($original_filepath);
        }
    }

    return $target;
}

/**
 * Delete the post with the given frontmatter slug. No-op if no such
 * post exists. Caller is responsible for calling
 * nano_regenerate_static() afterwards.
 */
/**
 * Clear the `hero` flag on every post except $keep_slug, so the homepage
 * hero is always unique. Rewrites each affected file in place (atomic
 * tempfile + rename) without touching anything else in it.
 */
function nano_admin_clear_other_heroes(string $keep_slug): void
{
    foreach (nano_admin_list_posts(true) as $entry) {
        $fm = $entry['frontmatter'];
        if (empty($fm['hero'])) {
            continue;
        }
        if ((string)($fm['slug'] ?? '') === $keep_slug) {
            continue;
        }
        try {
            $loaded = nano_admin_read_post($entry['filepath']);
        } catch (RuntimeException $e) {
            continue;
        }
        $loaded['frontmatter']['hero'] = false;
        $contents = nano_admin_serialize_post($loaded['frontmatter'], $loaded['body']);
        $tmp = $entry['filepath'] . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contents) !== false && @rename($tmp, $entry['filepath'])) {
            @chmod($entry['filepath'], 0644);
        } elseif (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function nano_admin_delete_post(string $slug): void
{
    $path = nano_admin_find_post_by_slug($slug);
    if ($path === null) {
        return;
    }
    nano_admin_assert_inside_posts($path);
    @unlink($path);
}

