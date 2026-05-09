<?php
/**
 * Blog listing entry point. Renders the index, category archives, and
 * paginated pages. Routed to from .htaccess clean-URL rewrites.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core.php';

$category_raw = isset($_GET['category']) ? (string)$_GET['category'] : null;
$category = null;
if ($category_raw !== null) {
    $category = nano_safe_slug($category_raw);
    if ($category === '' || $category !== $category_raw) {
        http_response_code(404);
        exit;
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));

$cfg = nano_config();
$site_name = (string)($cfg['site_name'] ?? 'Blog');
$posts_per_page = max(1, (int)($cfg['posts_per_page'] ?? 10));

$all = nano_list_posts($category !== null ? ['category' => $category] : []);
$total = count($all);
$total_pages = max(1, (int)ceil($total / $posts_per_page));
if ($page > $total_pages && $total > 0) {
    http_response_code(404);
    exit;
}
$slice = array_slice($all, ($page - 1) * $posts_per_page, $posts_per_page);

$heading = $category !== null
    ? ucfirst(str_replace('-', ' ', $category))
    : $site_name;

ob_start();
?>
<div class="nano-blog-list">
<?php if ($category !== null): ?>
  <nav class="nano-blog-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= nano_e(nano_index_url(1)) ?>"><?= nano_e($site_name) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    <span><?= nano_e($heading) ?></span>
  </nav>
<?php endif; ?>
  <h1><?= nano_e($heading) ?></h1>
<?php if (empty($slice)): ?>
  <p>No posts yet.</p>
<?php else: foreach ($slice as $entry): $fm = $entry['frontmatter']; ?>
  <article class="nano-blog-card">
    <a href="<?= nano_e(nano_post_url((string)$fm['slug'])) ?>">
<?php if (!empty($fm['image'])): ?>
      <img src="<?= nano_e(nano_media_url((string)$fm['image'])) ?>" alt="<?= nano_e((string)($fm['image_alt'] ?? $fm['title'])) ?>" loading="lazy">
<?php endif; ?>
      <h2><?= nano_e((string)$fm['title']) ?></h2>
      <time datetime="<?= nano_e((string)$fm['date']) ?>"><?= nano_e(date('j F Y', strtotime((string)$fm['date']))) ?></time>
      <p><?= nano_e((string)$fm['description']) ?></p>
    </a>
  </article>
<?php endforeach; endif; ?>
<?php if ($total_pages > 1): ?>
  <nav class="nano-blog-pagination">
<?php
    // Build prev/next URLs. Category archives keep the category in the path
    // and pass page via query string; the bare index uses /page/N/.
    $prev_url = null;
    $next_url = null;
    if ($page > 1) {
        if ($category !== null) {
            $prev_url = nano_category_url($category) . ($page > 2 ? '?page=' . ($page - 1) : '');
        } else {
            $prev_url = nano_index_url($page - 1);
        }
    }
    if ($page < $total_pages) {
        $next_url = $category !== null
            ? nano_category_url($category) . '?page=' . ($page + 1)
            : nano_index_url($page + 1);
    }
?>
<?php if ($prev_url !== null): ?>
    <a href="<?= nano_e($prev_url) ?>" rel="prev">&laquo; Newer</a>
<?php endif; ?>
    <span>Page <?= (int)$page ?> of <?= (int)$total_pages ?></span>
<?php if ($next_url !== null): ?>
    <a href="<?= nano_e($next_url) ?>" rel="next">Older &raquo;</a>
<?php endif; ?>
  </nav>
<?php endif; ?>
</div>
<?php
$content = ob_get_clean();

$page_title = nano_e(
    $category !== null
        ? $heading . ' - ' . $site_name
        : $site_name
);
$page_description = nano_e(
    $category !== null
        ? 'Posts in the ' . $category . ' category.'
        : $site_name . ' - latest posts.'
);
$meta_tags = nano_render_meta_tags_for_index($category, $page);

require __DIR__ . '/template.php';
