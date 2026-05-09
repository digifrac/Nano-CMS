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
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}-[0-9a-f]{6}\.(jpg|jpeg|png|gif|webp)$/', $name);
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
    return ['ok' => true, 'filename' => $name, 'error' => null];
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
    $path = nano_admin_media_dir() . '/' . $filename;
    $real = is_file($path) ? realpath($path) : false;
    if ($real === false || dirname($real) !== nano_admin_media_real_dir()) {
        return false;
    }
    return (bool)@unlink($real);
}
