<?php
/**
 * Single-post entry point. Routed to from .htaccess clean-URL rewrites
 * which split a request like /<category>/<slug>/ into ?category=<cat>&slug=<slug>
 * before this file runs.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core.php';

$slug_raw = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
$slug = nano_safe_slug($slug_raw);
if ($slug === '' || $slug !== $slug_raw) {
    http_response_code(404);
    exit;
}

$category_raw = isset($_GET['category']) ? (string)$_GET['category'] : '';
$url_category = nano_safe_slug($category_raw);
if ($url_category === '' || $url_category !== $category_raw) {
    http_response_code(404);
    exit;
}

// Draft preview path: requires a logged-in admin session AND a CSRF token
// matching the one issued by the admin. The admin codebase (built in a
// later step) sets these session keys on login. Both checks must pass;
// otherwise drafts are treated as missing posts and 404.
$preview_token = isset($_GET['preview']) ? (string)$_GET['preview'] : '';
$is_admin_preview = false;
if ($preview_token !== '') {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (
        !empty($_SESSION['nano_admin_logged_in'])
        && !empty($_SESSION['nano_csrf_token'])
        && hash_equals((string)$_SESSION['nano_csrf_token'], $preview_token)
    ) {
        $is_admin_preview = true;
    }
}

$path = nano_find_post_by_slug($slug, $is_admin_preview);
if ($path === null) {
    http_response_code(404);
    exit;
}

$post = nano_parse_post($path);
$fm = $post['frontmatter'];

// Belt-and-braces: even if the slug matched a draft, only render it when
// admin preview is genuinely active.
if (!empty($fm['draft']) && !$is_admin_preview) {
    http_response_code(404);
    exit;
}

// The URL must include the post's actual category. Prevents two URLs
// (the right one + any wrong-category URL) from serving identical
// content - that would be a duplicate-content SEO problem.
if ((string)($fm['category'] ?? '') !== $url_category) {
    http_response_code(404);
    exit;
}

$cfg = nano_config();
$site_name = (string)($cfg['site_name'] ?? '');
$category_label = ucfirst(str_replace('-', ' ', (string)$fm['category']));

ob_start();
?>
<article class="nano-blog-post">
  <nav class="nano-blog-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= nano_e(nano_index_url(1)) ?>"><?= nano_e(nano_blog_label()) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= nano_e(nano_category_url((string)$fm['category'])) ?>"><?= nano_e($category_label) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    <span aria-current="page"><?= nano_e((string)$fm['title']) ?></span>
  </nav>
  <header>
    <h1><?= nano_e((string)$fm['title']) ?></h1>
    <time datetime="<?= nano_e((string)$fm['date']) ?>"><?= nano_e(date('j F Y', strtotime((string)$fm['date']))) ?></time>
    <p class="nano-blog-category"><a href="<?= nano_e(nano_category_url((string)$fm['category'])) ?>"><?= nano_e($category_label) ?></a></p>
<?php if (!empty($fm['draft']) && $is_admin_preview): ?>
    <p class="nano-blog-draft-banner">DRAFT PREVIEW</p>
<?php endif; ?>
  </header>
<?php if (!empty($fm['image'])): ?>
  <figure class="nano-blog-hero">
    <img src="<?= nano_e(nano_media_url((string)$fm['image'])) ?>" alt="<?= nano_e((string)($fm['image_alt'] ?? $fm['title'])) ?>" loading="lazy">
  </figure>
<?php endif; ?>
  <div class="nano-blog-content">
<?= $post['html'] ?>
  </div>
</article>
<?php
$content = ob_get_clean();

$page_title = nano_e((string)$fm['title'] . ($site_name !== '' ? ' - ' . $site_name : ''));
$page_description = nano_e((string)$fm['description']);
$meta_tags = nano_render_meta_tags_for_post($fm);

require __DIR__ . '/template.php';
