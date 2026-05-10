<?php
/**
 * Blog listing entry point. The bare blog homepage renders a category
 * landing - one card per category with a post count. A category
 * archive (?category=slug, routed via .htaccess) renders the post
 * list for that category. Pagination only applies to category archives.
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

// Each grid has an independent setting. Allowed values are 3 or 4;
// any other value (missing, typo, etc.) silently falls back to 3
// rather than 500-ing the page.
$categories_per_row = (int)($cfg['categories_per_row'] ?? 3);
if ($categories_per_row !== 3 && $categories_per_row !== 4) {
    $categories_per_row = 3;
}
$articles_per_row = (int)($cfg['articles_per_row'] ?? 3);
if ($articles_per_row !== 3 && $articles_per_row !== 4) {
    $articles_per_row = 3;
}

if ($category !== null) {
    $all = nano_list_posts(['category' => $category]);
    $total = count($all);
    $total_pages = max(1, (int)ceil($total / $posts_per_page));
    if ($page > $total_pages && $total > 0) {
        http_response_code(404);
        exit;
    }
    $slice = array_slice($all, ($page - 1) * $posts_per_page, $posts_per_page);
    $heading = ucfirst(str_replace('-', ' ', $category));
} else {
    // Bare homepage no longer paginates, so /page/N/ for N>1 is a 404.
    // Prevents duplicate-content SEO issues from query-string variants.
    if ($page > 1) {
        http_response_code(404);
        exit;
    }
    $categories = nano_list_categories_with_counts();
    $heading = $site_name;
}

ob_start();
?>
<div class="nano-blog-list">
<?php if ($category !== null): ?>
  <nav class="nano-blog-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= nano_e(nano_index_url(1)) ?>"><?= nano_e(nano_blog_label()) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    <span aria-current="page"><?= nano_e($heading) ?></span>
  </nav>
  <h1><?= nano_e($heading) ?></h1>
<?php if (empty($slice)): ?>
  <p>No posts in this category yet.</p>
<?php else: ?>
  <div class="nano-blog-grid" style="--nano-cards-per-row: <?= (int)$articles_per_row ?>;">
<?php foreach ($slice as $entry): $fm = $entry['frontmatter']; ?>
    <article class="nano-blog-card">
      <a href="<?= nano_e(nano_post_url((string)$fm['slug'], (string)$fm['category'])) ?>">
<?php if (!empty($fm['image'])): ?>
        <img src="<?= nano_e(nano_thumb_url((string)$fm['image'])) ?>" alt="<?= nano_e((string)($fm['image_alt'] ?? $fm['title'])) ?>" loading="lazy">
<?php endif; ?>
        <h2><?= nano_e((string)$fm['title']) ?></h2>
        <time datetime="<?= nano_e((string)$fm['date']) ?>"><?= nano_e(date('j F Y', strtotime((string)$fm['date']))) ?></time>
        <p><?= nano_e((string)$fm['description']) ?></p>
      </a>
    </article>
<?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($total_pages > 1): ?>
  <nav class="nano-blog-pagination">
<?php
    $prev_url = null;
    $next_url = null;
    if ($page > 1) {
        $prev_url = $page > 2
            ? nano_category_url($category) . 'page/' . ($page - 1) . '/'
            : nano_category_url($category);
    }
    if ($page < $total_pages) {
        $next_url = nano_category_url($category) . 'page/' . ($page + 1) . '/';
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
<?php else: ?>
  <h1><?= nano_e($heading) ?></h1>
<?php if (empty($categories)): ?>
  <p>No posts yet.</p>
<?php else: ?>
  <div class="nano-blog-grid" style="--nano-cards-per-row: <?= (int)$categories_per_row ?>;">
<?php foreach ($categories as $c):
    $post_word = $c['count'] === 1 ? 'article' : 'articles';
    $cat_image = nano_category_image_url($c['slug']);
?>
    <a class="nano-blog-category-card<?= $cat_image !== null ? ' has-image' : '' ?>" href="<?= nano_e(nano_category_url($c['slug'])) ?>">
<?php if ($cat_image !== null): ?>
      <img src="<?= nano_e($cat_image) ?>" alt="<?= nano_e($c['label']) ?>" loading="lazy">
<?php endif; ?>
      <div class="nano-blog-category-card-text">
        <h2><?= nano_e($c['label']) ?></h2>
        <p class="nano-blog-category-count"><?= (int)$c['count'] ?> <?= $post_word ?></p>
      </div>
    </a>
<?php endforeach; ?>
  </div>
<?php endif; ?>
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
        : $site_name . ' - browse by topic.'
);
$meta_tags = nano_render_meta_tags_for_index($category, $page);

require __DIR__ . '/template.php';
