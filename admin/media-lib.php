<?php
/**
 * Admin media helpers. Required by admin/media.php (the page entry)
 * and by smoke tests. The page-entry/auth gates live in media.php so
 * this file can be required from any context that has bootstrap +
 * admin/core + admin/posts loaded.
 */

if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

const NANO_ADMIN_MEDIA_MAX_BYTES = 5 * 1024 * 1024;
const NANO_ADMIN_MEDIA_EXTENSIONS = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                                     'png' => 'image/png', 'gif' => 'image/gif',
                                     'webp' => 'image/webp'];
const NANO_ADMIN_THUMB_DEFAULT_WIDTH = 600;
const NANO_ADMIN_THUMB_DEFAULT_HEIGHT = 400;
const NANO_ADMIN_THUMB_SUFFIX = '-thumb';

/* ------------------------------------------------------------------------ */
/* Path + filename helpers                                                   */
/* ------------------------------------------------------------------------ */

function nano_admin_media_dir(): string
{
    if (!defined('NANO_CONTENT_PATH')) {
        throw new RuntimeException('Nano CMS admin: NANO_CONTENT_PATH not defined');
    }
    return NANO_CONTENT_PATH . '/media';
}

function nano_admin_media_real_dir(): string
{
    $dir = nano_admin_media_dir();
    $real = is_dir($dir) ? realpath($dir) : false;
    if ($real === false) {
        throw new RuntimeException("Nano CMS admin: media directory missing: $dir");
    }
    return $real;
}

function nano_admin_media_filename_ok(string $name): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}-[0-9a-f]{6}(-thumb)?\.(jpg|jpeg|png|gif|webp)$/', $name);
}

function nano_admin_media_is_thumb(string $name): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}-[0-9a-f]{6}-thumb\.(jpg|jpeg|png|gif|webp)$/', $name);
}

/**
 * Category images live in /media/ with a fixed filename pattern
 * `category-<slug>.<ext>` (plus a `-thumb` companion). Existence of
 * the file IS the metadata - there is no JSON sidecar.
 */
function nano_admin_media_is_category_image(string $name): bool
{
    return (bool)preg_match('/^category-[a-z0-9-]+(-thumb)?\.(jpg|jpeg|png|gif|webp)$/', $name);
}

function nano_admin_category_image_filename(string $slug, string $ext): string
{
    return 'category-' . $slug . '.' . strtolower($ext);
}

/**
 * Find an existing category image file for $slug. Returns the
 * filename (e.g. `category-web-design.jpg`) or null. Searches each
 * allowed extension since we don't track which one the user uploaded.
 */
function nano_admin_find_category_image(string $slug): ?string
{
    $dir = nano_admin_media_dir();
    foreach (array_keys(NANO_ADMIN_MEDIA_EXTENSIONS) as $ext) {
        $name = nano_admin_category_image_filename($slug, $ext);
        if (is_file($dir . '/' . $name)) {
            return $name;
        }
    }
    return null;
}

function nano_admin_media_thumb_filename(string $original): string
{
    $dot = strrpos($original, '.');
    if ($dot === false) {
        return $original . NANO_ADMIN_THUMB_SUFFIX;
    }
    return substr($original, 0, $dot) . NANO_ADMIN_THUMB_SUFFIX . substr($original, $dot);
}

function nano_admin_media_random_filename(string $ext): string
{
    return date('Y-m-d') . '-' . bin2hex(random_bytes(3)) . '.' . strtolower($ext);
}

/* ------------------------------------------------------------------------ */
/* Listing + used-set                                                        */
/* ------------------------------------------------------------------------ */

/**
 * Return one entry per file in /media/, newest first.
 * @return array<int, array{filename: string, bytes: int, mtime: int}>
 */
function nano_admin_list_media(): array
{
    $dir = nano_admin_media_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $real_root = nano_admin_media_real_dir();
    $items = [];
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $real = realpath($path);
        if ($real === false || dirname($real) !== $real_root) continue;
        $name = basename($path);
        if ($name[0] === '.') continue; // .htaccess, .gitkeep
        // Hide auto-generated thumbnails and category images from the
        // user-facing media grid - both are managed by other admin
        // flows (uploads regenerate thumbs; the Categories page owns
        // the category images).
        if (nano_admin_media_is_thumb($name)) continue;
        if (nano_admin_media_is_category_image($name)) continue;
        $items[] = ['filename' => $name, 'bytes' => (int)filesize($path), 'mtime' => (int)filemtime($path)];
    }
    usort($items, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $items;
}

/**
 * Set of media filenames referenced anywhere across all posts (drafts
 * included). Looks at frontmatter `image:` AND any occurrence of the
 * filename pattern inside post bodies.
 *
 * @return array<string, true>
 */
function nano_admin_media_used_set(): array
{
    $used = [];
    foreach (nano_admin_list_posts(true) as $entry) {
        $img = trim((string)($entry['frontmatter']['image'] ?? ''));
        if ($img !== '') {
            $used[basename($img)] = true;
        }
        $body = nano_admin_read_post($entry['filepath'])['body'];
        if (preg_match_all('/\d{4}-\d{2}-\d{2}-[0-9a-f]{6}\.(?:jpg|jpeg|png|gif|webp)/i', $body, $m)) {
            foreach ($m[0] as $hit) {
                $used[strtolower($hit)] = true;
            }
        }
    }
    return $used;
}

/* ------------------------------------------------------------------------ */
/* Upload pipeline                                                           */
/* ------------------------------------------------------------------------ */

/**
 * Run the full upload pipeline on a single $_FILES entry. Returns a
 * status array. Never throws on validation failure - returns an error
 * message instead so the caller can render it.
 *
 * @return array{ok: bool, filename: ?string, error: ?string}
 */
function nano_admin_media_save_upload(array $file): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'filename' => null, 'error' => 'No file selected.'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'filename' => null, 'error' => 'File exceeds the server upload limit.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'filename' => null, 'error' => 'Upload failed (code ' . $err . ').'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Uploaded file not found.'];
    }
    if ((int)$file['size'] > NANO_ADMIN_MEDIA_MAX_BYTES) {
        return ['ok' => false, 'filename' => null, 'error' => 'File exceeds the 5 MB limit.'];
    }
    $orig_name = (string)($file['name'] ?? '');
    $ext = strtolower((string)pathinfo($orig_name, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg'; // canonicalise on disk
    if (!isset(NANO_ADMIN_MEDIA_EXTENSIONS[$ext])) {
        return ['ok' => false, 'filename' => null, 'error' => 'Allowed types: jpg, png, gif, webp.'];
    }
    $expected_mime = NANO_ADMIN_MEDIA_EXTENSIONS[$ext];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actual_mime = (string)$finfo->file($tmp);
    if ($actual_mime !== $expected_mime && !($ext === 'jpg' && $actual_mime === 'image/jpeg')) {
        return ['ok' => false, 'filename' => null, 'error' => "File contents do not match the .$ext extension."];
    }
    $dir = nano_admin_media_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Media directory missing and cannot be created.'];
    }
    // Random filename collision is astronomically unlikely with 24 random
    // bits + the date prefix, but verifying is essentially free.
    do { $name = nano_admin_media_random_filename($ext); } while (is_file($dir . '/' . $name));
    $dest = $dir . '/' . $name;
    if (!nano_admin_media_reencode($tmp, $ext, $dest)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Could not re-encode the image. Is it a valid ' . strtoupper($ext) . '?'];
    }
    @chmod($dest, 0644);
    // Generate the thumbnail. If it fails (e.g. very small source image),
    // the upload still succeeds - the frontend lazy-falls-back to the
    // original via nano_thumb_url() when no thumbnail exists.
    [$tw, $th] = nano_admin_thumb_dimensions();
    $thumb_path = $dir . '/' . nano_admin_media_thumb_filename($name);
    if (nano_admin_media_generate_thumb($dest, $thumb_path, $tw, $th, $ext)) {
        @chmod($thumb_path, 0644);
    }
    return ['ok' => true, 'filename' => $name, 'error' => null];
}

/**
 * Read thumbnail dimensions from config.json with sane fallbacks.
 * @return array{0:int, 1:int} [width, height]
 */
function nano_admin_thumb_dimensions(): array
{
    $cfg = nano_admin_load_config();
    $w = (int)($cfg['thumb_width'] ?? NANO_ADMIN_THUMB_DEFAULT_WIDTH);
    $h = (int)($cfg['thumb_height'] ?? NANO_ADMIN_THUMB_DEFAULT_HEIGHT);
    if ($w < 100 || $w > 2400) $w = NANO_ADMIN_THUMB_DEFAULT_WIDTH;
    if ($h < 100 || $h > 2400) $h = NANO_ADMIN_THUMB_DEFAULT_HEIGHT;
    return [$w, $h];
}

/**
 * Crop+resize $src into a thumbnail at $dest. Cover-crop with an
 * upper-bias of 35% (matches the .nano-blog-card image object-position
 * so the visual is consistent across full-size hero and small thumb).
 * Tries GD first (standard PHP image extension), falls back to Imagick.
 */
function nano_admin_media_generate_thumb(string $src, string $dest, int $width, int $height, string $ext): bool
{
    if (extension_loaded('gd')) {
        $img = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($src),
            'png'         => @imagecreatefrompng($src),
            'gif'         => @imagecreatefromgif($src),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default       => false,
        };
        if ($img !== false) {
            try {
                $sw = imagesx($img);
                $sh = imagesy($img);
                if ($sw < 1 || $sh < 1) return false;
                $src_aspect = $sw / $sh;
                $tgt_aspect = $width / $height;
                if ($src_aspect > $tgt_aspect) {
                    $crop_w = (int)round($sh * $tgt_aspect);
                    $crop_h = $sh;
                    $crop_x = (int)round(($sw - $crop_w) / 2);
                    $crop_y = 0;
                } else {
                    $crop_w = $sw;
                    $crop_h = (int)round($sw / $tgt_aspect);
                    $crop_x = 0;
                    // 35% from top - keeps subjects in the upper third visible.
                    $crop_y = (int)round(($sh - $crop_h) * 0.35);
                }
                $thumb = imagecreatetruecolor($width, $height);
                if ($ext === 'png') {
                    imagealphablending($thumb, false);
                    imagesavealpha($thumb, true);
                }
                if (!imagecopyresampled($thumb, $img, 0, 0, $crop_x, $crop_y, $width, $height, $crop_w, $crop_h)) {
                    imagedestroy($thumb);
                    return false;
                }
                $ok = match ($ext) {
                    'jpg', 'jpeg' => @imagejpeg($thumb, $dest, 85),
                    'png'         => @imagepng($thumb, $dest, 6),
                    'gif'         => @imagegif($thumb, $dest),
                    'webp'        => function_exists('imagewebp') && @imagewebp($thumb, $dest, 85),
                    default       => false,
                };
                imagedestroy($thumb);
                return (bool)$ok;
            } finally {
                imagedestroy($img);
            }
        }
    }
    if (extension_loaded('imagick')) {
        try {
            $im = new Imagick($src);
            // cropThumbnailImage centers by default; matches GD path's
            // upper-bias closely enough for a fallback.
            $im->cropThumbnailImage($width, $height);
            $im->stripImage();
            $im->writeImage($dest);
            $im->clear();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
    return false;
}

/**
 * Decode + re-encode the image into $dest. Strips any embedded payload
 * (EXIF, ICC, smuggled PHP) by going through a pixel round-trip. Tries
 * GD first - it's the standard PHP image extension and almost always
 * available - then falls back to Imagick. Returns false if neither is
 * loaded or if the source can't be decoded as the claimed type.
 */
function nano_admin_media_reencode(string $src, string $ext, string $dest): bool
{
    if (extension_loaded('gd')) {
        $img = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($src),
            'png'         => @imagecreatefrompng($src),
            'gif'         => @imagecreatefromgif($src),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default       => false,
        };
        if ($img !== false) {
            try {
                if ($ext === 'png') {
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
                $ok = match ($ext) {
                    'jpg', 'jpeg' => @imagejpeg($img, $dest, 85),
                    'png'         => @imagepng($img, $dest, 6),
                    'gif'         => @imagegif($img, $dest),
                    'webp'        => function_exists('imagewebp') && @imagewebp($img, $dest, 85),
                    default       => false,
                };
                return (bool)$ok;
            } finally {
                imagedestroy($img);
            }
        }
    }
    if (extension_loaded('imagick')) {
        try {
            $im = new Imagick($src);
            $im->stripImage();
            $im->writeImage($dest);
            $im->clear();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
    return false;
}

/* ------------------------------------------------------------------------ */
/* Delete                                                                    */
/* ------------------------------------------------------------------------ */

function nano_admin_media_delete(string $filename): bool
{
    if (!nano_admin_media_filename_ok($filename)) {
        return false;
    }
    if (nano_admin_media_is_thumb($filename) || nano_admin_media_is_category_image($filename)) {
        // Thumbnails and category images are managed by other flows.
        return false;
    }
    $path = nano_admin_media_dir() . '/' . $filename;
    $real = is_file($path) ? realpath($path) : false;
    if ($real === false || dirname($real) !== nano_admin_media_real_dir()) {
        return false;
    }
    $ok = (bool)@unlink($real);
    // Best-effort: also remove the matching thumbnail if it exists.
    $thumb_path = nano_admin_media_dir() . '/' . nano_admin_media_thumb_filename($filename);
    if (is_file($thumb_path)) {
        @unlink($thumb_path);
    }
    return $ok;
}

/**
 * Run the upload pipeline for a category image. Reuses every
 * validation step from nano_admin_media_save_upload() (size, MIME,
 * extension, re-encoding) but writes to a fixed `category-<slug>.<ext>`
 * filename. Replaces any existing image (including a different ext)
 * for that category, so each category has exactly one image.
 */
function nano_admin_category_image_save_upload(string $slug, array $file): array
{
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Invalid category slug.'];
    }
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'filename' => null, 'error' => 'No file selected.'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'filename' => null, 'error' => 'File exceeds the server upload limit.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'filename' => null, 'error' => 'Upload failed (code ' . $err . ').'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Uploaded file not found.'];
    }
    if ((int)$file['size'] > NANO_ADMIN_MEDIA_MAX_BYTES) {
        return ['ok' => false, 'filename' => null, 'error' => 'File exceeds the 5 MB limit.'];
    }
    $orig = (string)($file['name'] ?? '');
    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!isset(NANO_ADMIN_MEDIA_EXTENSIONS[$ext])) {
        return ['ok' => false, 'filename' => null, 'error' => 'Allowed types: jpg, png, gif, webp.'];
    }
    $expected_mime = NANO_ADMIN_MEDIA_EXTENSIONS[$ext];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actual_mime = (string)$finfo->file($tmp);
    if ($actual_mime !== $expected_mime && !($ext === 'jpg' && $actual_mime === 'image/jpeg')) {
        return ['ok' => false, 'filename' => null, 'error' => "File contents do not match the .$ext extension."];
    }
    $dir = nano_admin_media_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Media directory missing and cannot be created.'];
    }
    // Remove any previous category image (might be a different ext).
    nano_admin_category_image_delete($slug);

    $name = nano_admin_category_image_filename($slug, $ext);
    $dest = $dir . '/' . $name;
    if (!nano_admin_media_reencode($tmp, $ext, $dest)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Could not re-encode the image. Is it a valid ' . strtoupper($ext) . '?'];
    }
    @chmod($dest, 0644);
    [$tw, $th] = nano_admin_thumb_dimensions();
    $thumb_path = $dir . '/' . nano_admin_media_thumb_filename($name);
    if (nano_admin_media_generate_thumb($dest, $thumb_path, $tw, $th, $ext)) {
        @chmod($thumb_path, 0644);
    }
    return ['ok' => true, 'filename' => $name, 'error' => null];
}

/**
 * Remove the category image (and its thumb) for the given slug, if
 * any. Returns true if anything was deleted.
 */
function nano_admin_category_image_delete(string $slug): bool
{
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        return false;
    }
    $deleted = false;
    $dir = nano_admin_media_dir();
    foreach (array_keys(NANO_ADMIN_MEDIA_EXTENSIONS) as $ext) {
        $name = nano_admin_category_image_filename($slug, $ext);
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            if (@unlink($path)) $deleted = true;
        }
        $thumb = $dir . '/' . nano_admin_media_thumb_filename($name);
        if (is_file($thumb)) @unlink($thumb);
    }
    return $deleted;
}
