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
const NANO_ADMIN_THUMB_DEFAULT_WIDTH = 1200;
const NANO_ADMIN_THUMB_DEFAULT_HEIGHT = 800;
const NANO_ADMIN_THUMB_SUFFIX = '-thumb';
// Permanent media folders the manager always keeps and never lets you delete.
const NANO_ADMIN_MEDIA_STRUCTURAL = ['article-images', 'category-images'];
const NANO_ADMIN_IMAGE_QUALITY_DEFAULT = 90;
const NANO_ADMIN_SOURCE_MAX_WIDTH_DEFAULT = 1600;

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
function nano_admin_media_save_upload(array $file, string $subdir = ''): array
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
    $subdir = trim($subdir, '/');
    if ($subdir !== '' && !nano_admin_media_dir_ok($subdir)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Invalid destination folder.'];
    }
    $dir = nano_admin_media_dir() . ($subdir !== '' ? '/' . $subdir : '');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
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
    if (nano_admin_media_generate_thumb($tmp, $thumb_path, $tw, $th, $ext)) {
        @chmod($thumb_path, 0644);
    }
    // Return the media-relative path (folder/name or just name).
    return ['ok' => true, 'filename' => ($subdir !== '' ? $subdir . '/' : '') . $name, 'error' => null];
}

/**
 * Read thumbnail dimensions from config.json with sane fallbacks.
 * Article cards use the `thumb_width` / `thumb_height` pair.
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
 * Read category-image thumbnail dimensions from config.json. Falls
 * back to article thumb dimensions, which fall back to the defaults.
 * Lets the operator tune category-card and article-card crops
 * independently without forcing both pairs to be set.
 * @return array{0:int, 1:int} [width, height]
 */
function nano_admin_cat_thumb_dimensions(): array
{
    $cfg = nano_admin_load_config();
    [$art_w, $art_h] = nano_admin_thumb_dimensions();
    $w = (int)($cfg['cat_thumb_width'] ?? $art_w);
    $h = (int)($cfg['cat_thumb_height'] ?? $art_h);
    if ($w < 100 || $w > 2400) $w = $art_w;
    if ($h < 100 || $h > 2400) $h = $art_h;
    return [$w, $h];
}

/**
 * JPEG or WebP encode quality from config.json. Range 60-95.
 * Defaults to 85 for both formats when unset or out of range.
 */
function nano_admin_image_quality(string $ext): int
{
    $cfg = nano_admin_load_config();
    $key = ($ext === 'webp') ? 'image_quality_webp' : 'image_quality_jpeg';
    $q = (int)($cfg[$key] ?? NANO_ADMIN_IMAGE_QUALITY_DEFAULT);
    if ($q < 60 || $q > 95) $q = NANO_ADMIN_IMAGE_QUALITY_DEFAULT;
    return $q;
}

/**
 * Maximum width in pixels for re-encoded source images. Sources wider
 * than this are downscaled at upload time. Range 400-4000. Default 1600.
 * Prevents 4000px phone photos becoming multi-megabyte heroes.
 */
function nano_admin_source_max_width(): int
{
    $cfg = nano_admin_load_config();
    $w = (int)($cfg['source_max_width'] ?? NANO_ADMIN_SOURCE_MAX_WIDTH_DEFAULT);
    if ($w < 400 || $w > 4000) $w = NANO_ADMIN_SOURCE_MAX_WIDTH_DEFAULT;
    return $w;
}

/**
 * Apply EXIF orientation to a GD image resource. Returns a (possibly
 * new) image resource. Caller is responsible for freeing the OLD one
 * if a new resource is returned. JPEG only (other formats don't carry
 * EXIF in the formats Nano CMS supports).
 *
 * Without this, portrait phone photos display sideways because
 * re-encoding strips the orientation tag without applying the rotation.
 *
 * @param \GdImage $img
 * @return \GdImage
 */
function nano_admin_apply_exif_orientation($img, string $src)
{
    if (!function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($src);
    if (!$exif || empty($exif['Orientation'])) return $img;
    switch ((int)$exif['Orientation']) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
        case 3: return imagerotate($img, 180, 0) ?: $img;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); return $img;
        case 5:
            $r = imagerotate($img, -90, 0);
            if ($r === false) return $img;
            imageflip($r, IMG_FLIP_HORIZONTAL);
            return $r;
        case 6: return imagerotate($img, -90, 0) ?: $img;
        case 7:
            $r = imagerotate($img, 90, 0);
            if ($r === false) return $img;
            imageflip($r, IMG_FLIP_HORIZONTAL);
            return $r;
        case 8: return imagerotate($img, 90, 0) ?: $img;
    }
    return $img;
}

/**
 * Resample a GD image down to $width if currently wider, preserving
 * aspect ratio. Returns the existing image unchanged if already within
 * the cap or if resampling fails. Caller frees the OLD image when a
 * new one is returned.
 *
 * @param \GdImage $img
 * @return \GdImage
 */
function nano_admin_resize_to_width($img, int $width)
{
    $sw = imagesx($img);
    $sh = imagesy($img);
    if ($sw <= $width) return $img;
    $new_h = (int)round($sh * $width / $sw);
    if ($new_h < 1) return $img;
    $resized = imagecreatetruecolor($width, $new_h);
    if ($resized === false) return $img;
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    if (!imagecopyresampled($resized, $img, 0, 0, 0, 0, $width, $new_h, $sw, $sh)) {
        imagedestroy($resized);
        return $img;
    }
    return $resized;
}

/**
 * Downscale $src into a thumbnail at $dest, PRESERVING the source aspect
 * ratio and never upscaling. No crop is baked into the file: $width/$height
 * are a bounding box the image is scaled to fit inside. The front-end frames
 * each image per-image with CSS object-fit/object-position/background (set in
 * the post and category editors), so cards can choose cover/contain and a
 * focal point without the thumbnail having thrown pixels away. This is what
 * makes the per-image controls actually work - a pre-cropped thumb couldn't.
 * Tries GD first (standard PHP image extension), falls back to Imagick.
 */
function nano_admin_media_generate_thumb(string $src, string $dest, int $width, int $height, string $ext): bool
{
    $quality_jpg = nano_admin_image_quality('jpg');
    $quality_webp = nano_admin_image_quality('webp');
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
                if ($ext === 'jpg' || $ext === 'jpeg') {
                    $oriented = nano_admin_apply_exif_orientation($img, $src);
                    if ($oriented !== $img) { imagedestroy($img); $img = $oriented; }
                }
                $sw = imagesx($img);
                $sh = imagesy($img);
                if ($sw < 1 || $sh < 1) return false;
                // Scale to fit inside the WxH box, preserving aspect ratio and
                // never enlarging (scale capped at 1.0). No crop - the front-end
                // frames the image per-image with CSS.
                $scale = min($width / $sw, $height / $sh, 1.0);
                $dw = max(1, (int)round($sw * $scale));
                $dh = max(1, (int)round($sh * $scale));
                $thumb = imagecreatetruecolor($dw, $dh);
                if ($ext === 'png') {
                    imagealphablending($thumb, false);
                    imagesavealpha($thumb, true);
                }
                if (!imagecopyresampled($thumb, $img, 0, 0, 0, 0, $dw, $dh, $sw, $sh)) {
                    imagedestroy($thumb);
                    return false;
                }
                $ok = match ($ext) {
                    'jpg', 'jpeg' => @imagejpeg($thumb, $dest, $quality_jpg),
                    'png'         => @imagepng($thumb, $dest, 6),
                    'gif'         => @imagegif($thumb, $dest),
                    'webp'        => function_exists('imagewebp') && @imagewebp($thumb, $dest, $quality_webp),
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
            if (method_exists($im, 'autoOrient')) { $im->autoOrient(); }
            // bestfit = true scales to fit inside the box, preserving aspect
            // and not cropping. Only downscale: skip when already within box.
            if ($im->getImageWidth() > $width || $im->getImageHeight() > $height) {
                $im->thumbnailImage($width, $height, true);
            }
            $im->stripImage();
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $im->setImageCompressionQuality($quality_jpg);
            } elseif ($ext === 'webp') {
                $im->setImageCompressionQuality($quality_webp);
            }
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
 * (EXIF, ICC, smuggled PHP) by going through a pixel round-trip. Also
 * applies EXIF orientation (JPEGs only, so portrait phone photos render
 * upright) and downscales to source_max_width if the source is wider.
 * Tries GD first (standard PHP image extension), falls back to Imagick.
 * Returns false if neither is loaded or if the source can't be decoded
 * as the claimed type.
 */
function nano_admin_media_reencode(string $src, string $ext, string $dest): bool
{
    $max_width = nano_admin_source_max_width();
    $quality_jpg = nano_admin_image_quality('jpg');
    $quality_webp = nano_admin_image_quality('webp');

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
                if ($ext === 'jpg' || $ext === 'jpeg') {
                    $oriented = nano_admin_apply_exif_orientation($img, $src);
                    if ($oriented !== $img) {
                        imagedestroy($img);
                        $img = $oriented;
                    }
                }
                $resized = nano_admin_resize_to_width($img, $max_width);
                if ($resized !== $img) {
                    imagedestroy($img);
                    $img = $resized;
                }
                if ($ext === 'png') {
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
                $ok = match ($ext) {
                    'jpg', 'jpeg' => @imagejpeg($img, $dest, $quality_jpg),
                    'png'         => @imagepng($img, $dest, 6),
                    'gif'         => @imagegif($img, $dest),
                    'webp'        => function_exists('imagewebp') && @imagewebp($img, $dest, $quality_webp),
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
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            if ($im->getImageWidth() > $max_width) {
                $im->scaleImage($max_width, 0);
            }
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $im->setImageCompressionQuality($quality_jpg);
            } elseif ($ext === 'webp') {
                $im->setImageCompressionQuality($quality_webp);
            }
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
    [$tw, $th] = nano_admin_cat_thumb_dimensions();
    $thumb_path = $dir . '/' . nano_admin_media_thumb_filename($name);
    if (nano_admin_media_generate_thumb($tmp, $thumb_path, $tw, $th, $ext)) {
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

/* ------------------------------------------------------------------------ */
/* Folders (Cart-style media browser)                                        */
/* ------------------------------------------------------------------------ */

function nano_admin_media_seg_ok(string $s): bool
{
    return (bool)preg_match('/^(?:[a-z0-9]|[a-z0-9][a-z0-9-]*[a-z0-9])$/', $s);
}

/** A media-relative directory. '' is the media home; up to 3 levels deep. */
function nano_admin_media_dir_ok(string $rel): bool
{
    $rel = trim($rel, '/');
    if ($rel === '') return true;
    if (str_contains($rel, '..')) return false;
    $parts = explode('/', $rel);
    if (count($parts) > 3) return false;
    foreach ($parts as $p) {
        if (!nano_admin_media_seg_ok($p)) return false;
    }
    return true;
}

/** A media-relative file path (folder segments + a basename with extension). */
function nano_admin_media_path_ok(string $rel): bool
{
    $rel = trim($rel, '/');
    if ($rel === '' || str_contains($rel, '..')) return false;
    $parts = explode('/', $rel);
    $file = (string)array_pop($parts);
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    if (!isset(NANO_ADMIN_MEDIA_EXTENSIONS[$ext])) return false;
    if (!nano_admin_media_seg_ok(strtolower((string)pathinfo($file, PATHINFO_FILENAME)))) return false;
    foreach ($parts as $p) {
        if (!nano_admin_media_seg_ok($p)) return false;
    }
    return count($parts) <= 3;
}

function nano_admin_media_fs(string $relative): string
{
    return rtrim(nano_admin_media_dir() . '/' . trim($relative, '/'), '/');
}

function nano_admin_media_contained(string $abs): bool
{
    $root = realpath(nano_admin_media_dir());
    $real = realpath($abs);
    if ($root === false || $real === false) return false;
    return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
}

/** Immediate subfolders of a media-relative folder. */
function nano_admin_media_subfolders(string $dir): array
{
    $abs = nano_admin_media_fs($dir);
    $out = [];
    if (!is_dir($abs)) return $out;
    foreach (scandir($abs) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        if (!is_dir($abs . '/' . $e) || !nano_admin_media_seg_ok($e)) continue;
        $out[] = ['name' => $e, 'path' => ($dir !== '' ? $dir . '/' : '') . $e];
    }
    usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/** Image source files (non-thumb) directly inside a media-relative folder. */
function nano_admin_media_scan_dir(string $dir): array
{
    $abs = nano_admin_media_fs($dir);
    $files = [];
    if (!is_dir($abs)) return $files;
    foreach (scandir($abs) ?: [] as $entry) {
        if ($entry === '' || $entry[0] === '.') continue;
        if (!is_file($abs . '/' . $entry)) continue;
        $ext = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
        if (!isset(NANO_ADMIN_MEDIA_EXTENSIONS[$ext])) continue;
        if (nano_admin_media_is_thumb($entry)) continue;
        if ($dir === '' && nano_admin_media_is_category_image($entry)) continue;
        $files[] = [
            'name'  => $entry,
            'path'  => ($dir !== '' ? $dir . '/' : '') . $entry,
            'bytes' => (int)filesize($abs . '/' . $entry),
            'mtime' => (int)filemtime($abs . '/' . $entry),
        ];
    }
    usort($files, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $files;
}

/** Every image path across all folders (flat), for the editor picker. */
function nano_admin_media_all_images(string $dir = ''): array
{
    $out = [];
    foreach (nano_admin_media_scan_dir($dir) as $f) {
        $out[] = $f['path'];
    }
    foreach (nano_admin_media_subfolders($dir) as $sf) {
        $out = array_merge($out, nano_admin_media_all_images($sf['path']));
    }
    return $out;
}

function nano_admin_media_mkdir(string $parent, string $name): array
{
    $parent = trim($parent, '/');
    $name = strtolower(trim($name));
    if (!nano_admin_media_dir_ok($parent)) return ['ok' => false, 'error' => 'Invalid parent folder.'];
    if (!nano_admin_media_seg_ok($name)) return ['ok' => false, 'error' => 'Folder name: lowercase letters, numbers and hyphens.'];
    $rel = ($parent !== '' ? $parent . '/' : '') . $name;
    if (!nano_admin_media_dir_ok($rel)) return ['ok' => false, 'error' => 'Too many levels of folders here.'];
    $abs = nano_admin_media_fs($rel);
    if (is_dir($abs)) return ['ok' => false, 'error' => 'A folder named "' . $name . '" already exists here.'];
    if (!@mkdir($abs, 0755, true) && !is_dir($abs)) return ['ok' => false, 'error' => 'Could not create the folder.'];
    return ['ok' => true, 'created' => $rel];
}

function nano_admin_media_rmtree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p)) {
            nano_admin_media_rmtree($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

function nano_admin_media_deletefolder(string $path): array
{
    $path = trim($path, '/');
    if ($path === '' || !nano_admin_media_dir_ok($path)) return ['ok' => false, 'error' => 'Invalid folder.'];
    if (in_array($path, NANO_ADMIN_MEDIA_STRUCTURAL, true)) {
        return ['ok' => false, 'error' => 'This folder is part of Nano CMS and cannot be deleted.'];
    }
    $abs = nano_admin_media_fs($path);
    if (!is_dir($abs) || !nano_admin_media_contained($abs)) return ['ok' => false, 'error' => 'Folder not found.'];
    nano_admin_media_rmtree($abs);
    return ['ok' => true, 'removed' => $path];
}

function nano_admin_media_move(string $path, string $to): array
{
    $path = trim($path, '/');
    $to = trim($to, '/');
    if (!nano_admin_media_path_ok($path)) return ['ok' => false, 'error' => 'Invalid file.'];
    if (!nano_admin_media_dir_ok($to)) return ['ok' => false, 'error' => 'Invalid destination folder.'];
    $name = basename($path);
    $parent = strpos($path, '/') !== false ? substr($path, 0, strrpos($path, '/')) : '';
    if ($to === $parent) return ['ok' => true, 'unchanged' => true];
    $src = nano_admin_media_fs($path);
    if (!is_file($src) || !nano_admin_media_contained($src)) return ['ok' => false, 'error' => 'File not found.'];
    $dest_dir = nano_admin_media_fs($to);
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) return ['ok' => false, 'error' => 'Destination folder missing.'];
    $new_rel = ($to !== '' ? $to . '/' : '') . $name;
    if (is_file(nano_admin_media_fs($new_rel))) return ['ok' => false, 'error' => 'A file named "' . $name . '" already exists there.'];
    if (!@rename($src, nano_admin_media_fs($new_rel))) return ['ok' => false, 'error' => 'Could not move the file.'];
    $old_thumb = nano_admin_media_fs(nano_admin_media_thumb_filename($path));
    if (is_file($old_thumb)) @rename($old_thumb, nano_admin_media_fs(nano_admin_media_thumb_filename($new_rel)));
    return ['ok' => true, 'refs' => nano_admin_media_rewrite_ref($path, $new_rel)];
}

function nano_admin_media_rename(string $path, string $newname): array
{
    $path = trim($path, '/');
    if (!nano_admin_media_path_ok($path)) return ['ok' => false, 'error' => 'Invalid file.'];
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $newbase = strtolower(trim($newname));
    if (!nano_admin_media_seg_ok($newbase)) return ['ok' => false, 'error' => 'Name: lowercase letters, numbers and hyphens.'];
    $parent = strpos($path, '/') !== false ? substr($path, 0, strrpos($path, '/')) : '';
    $src = nano_admin_media_fs($path);
    if (!is_file($src) || !nano_admin_media_contained($src)) return ['ok' => false, 'error' => 'File not found.'];
    $new_rel = ($parent !== '' ? $parent . '/' : '') . $newbase . '.' . $ext;
    if ($new_rel === $path) return ['ok' => true, 'unchanged' => true];
    if (is_file(nano_admin_media_fs($new_rel))) return ['ok' => false, 'error' => 'A file named "' . $newbase . '" already exists here.'];
    if (!@rename($src, nano_admin_media_fs($new_rel))) return ['ok' => false, 'error' => 'Could not rename the file.'];
    $old_thumb = nano_admin_media_fs(nano_admin_media_thumb_filename($path));
    if (is_file($old_thumb)) @rename($old_thumb, nano_admin_media_fs(nano_admin_media_thumb_filename($new_rel)));
    return ['ok' => true, 'refs' => nano_admin_media_rewrite_ref($path, $new_rel)];
}

function nano_admin_media_delete_path(string $path): array
{
    $path = trim($path, '/');
    if (!nano_admin_media_path_ok($path)) return ['ok' => false, 'error' => 'Invalid file.'];
    $src = nano_admin_media_fs($path);
    if (!is_file($src) || !nano_admin_media_contained($src)) return ['ok' => false, 'error' => 'File not found.'];
    if (!@unlink($src)) return ['ok' => false, 'error' => 'Could not delete the file.'];
    $thumb = nano_admin_media_fs(nano_admin_media_thumb_filename($path));
    if (is_file($thumb)) @unlink($thumb);
    return ['ok' => true, 'deleted' => $path];
}

/**
 * When an image is moved or renamed, update every reference to it: post
 * frontmatter and bodies (raw string swap of the path) and managed category
 * records (image field). Returns the number of files updated.
 */
function nano_admin_media_rewrite_ref(string $old, string $new): int
{
    if ($old === '' || $old === $new) return 0;
    $changed = 0;
    foreach (nano_admin_list_posts(true) as $entry) {
        $fp = $entry['filepath'];
        $content = (string)file_get_contents($fp);
        if (strpos($content, $old) === false) continue;
        $updated = str_replace($old, $new, $content);
        if ($updated === $content) continue;
        $tmp = $fp . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $updated) !== false && @rename($tmp, $fp)) {
            @chmod($fp, 0644);
            $changed++;
        } elseif (is_file($tmp)) {
            @unlink($tmp);
        }
    }
    if (function_exists('nano_admin_list_category_records') && function_exists('nano_admin_save_category')) {
        foreach (nano_admin_list_category_records() as $rec) {
            if ((string)($rec['image'] ?? '') === $old) {
                $rec['image'] = $new;
                if (nano_admin_save_category($rec)) $changed++;
            }
        }
    }
    return $changed;
}
