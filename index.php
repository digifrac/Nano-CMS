<?php
/**
 * Blog listing entry point. The bare blog homepage renders a category
 * landing - one card per category with a post count. A category
 * archive (?category=slug, routed via .htaccess) renders the post
 * list for that category. Pagination only applies to category archives.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/licence.php';

header('Content-Type: text/html; charset=UTF-8');

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
$cat_description = '';

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
// Thumbnail aspect ratios drive card-image display. Article cards and
// category cards have independent dimension settings so their grids
// can look different from one another.
$thumb_w = (int)($cfg['thumb_width'] ?? 600);
$thumb_h = (int)($cfg['thumb_height'] ?? 400);
if ($thumb_w < 100 || $thumb_w > 2400) $thumb_w = 600;
if ($thumb_h < 100 || $thumb_h > 2400) $thumb_h = 400;
$cat_thumb_w = (int)($cfg['cat_thumb_width'] ?? $thumb_w);
$cat_thumb_h = (int)($cfg['cat_thumb_height'] ?? $thumb_h);
if ($cat_thumb_w < 100 || $cat_thumb_w > 2400) $cat_thumb_w = $thumb_w;
if ($cat_thumb_h < 100 || $cat_thumb_h > 2400) $cat_thumb_h = $thumb_h;
$article_grid_style = '--nano-thumb-aspect: ' . $thumb_w . ' / ' . $thumb_h . ';';
$category_grid_style = '--nano-thumb-aspect: ' . $cat_thumb_w . ' / ' . $cat_thumb_h . ';';

if ($category !== null) {
    $all = nano_list_posts(['category' => $category]);
    $total = count($all);
    $total_pages = max(1, (int)ceil($total / $posts_per_page));
    if ($page > $total_pages && $total > 0) {
        http_response_code(404);
        exit;
    }
    $slice = array_slice($all, ($page - 1) * $posts_per_page, $posts_per_page);
    $cat_record = nano_load_category($category);
    $heading = ($cat_record !== null && trim((string)($cat_record['name'] ?? '')) !== '')
        ? (string)$cat_record['name']
        : ucfirst(str_replace('-', ' ', $category));
    $cat_description = $cat_record !== null ? trim((string)($cat_record['description'] ?? '')) : '';
    $cat_image = ($cat_record !== null && trim((string)($cat_record['image'] ?? '')) !== '')
        ? nano_media_url(trim((string)$cat_record['image']))
        : null;
    $cat_pos = ($cat_record !== null && (($cat_record['image_position'] ?? '') === 'right')) ? 'right' : 'left';
    $cat_desc_html = $cat_description !== '' ? nano_render_markdown($cat_description) : '';
    // Meta description must stay plain text even when the description is markdown.
    $cat_meta_desc = $cat_desc_html !== ''
        ? trim((string)preg_replace('/\s+/', ' ', strip_tags($cat_desc_html)))
        : '';
} else {
    // Bare homepage no longer paginates, so /page/N/ for N>1 is a 404.
    // Prevents duplicate-content SEO issues from query-string variants.
    if ($page > 1) {
        http_response_code(404);
        exit;
    }
    $categories = nano_list_categories_with_counts();
    $heading = $site_name;
    // Homepage hero + featured articles. nano_list_posts() is published-only
    // and newest-first, so the first hero we hit is the newest hero.
    $hero_post = null;
    $featured_posts = [];
    foreach (nano_list_posts() as $entry) {
        $fm = $entry['frontmatter'];
        if ($hero_post === null && !empty($fm['hero'])) {
            $hero_post = $fm;
        }
        if (!empty($fm['featured'])) {
            $featured_posts[] = $fm;
        }
    }
    if ($hero_post !== null) {
        $hero_slug = (string)($hero_post['slug'] ?? '');
        $featured_posts = array_values(array_filter(
            $featured_posts,
            static fn(array $p): bool => (string)($p['slug'] ?? '') !== $hero_slug
        ));
    }
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
<?php $has_banner = $cat_image !== null; $has_desc = $cat_desc_html !== ''; if ($has_banner || $has_desc): ?>
  <header class="nano-blog-category-header nano-blog-image-<?= nano_e($cat_pos) ?> <?= $has_banner ? 'has-banner' : 'no-banner' ?>">
<?php if ($has_banner): ?>
    <figure class="nano-blog-category-banner">
      <img src="<?= nano_e($cat_image) ?>" alt="<?= nano_e($heading) ?>" loading="lazy">
    </figure>
<?php endif; ?>
<?php if ($has_desc): ?>
    <div class="nano-blog-category-description"><?= $cat_desc_html ?></div>
<?php endif; ?>
  </header>
<?php endif; ?>
<?php if (empty($slice)): ?>
  <p>No posts in this category yet.</p>
<?php else: ?>
  <div class="nano-blog-grid" style="--nano-cards-per-row: <?= (int)$articles_per_row ?>; <?= $article_grid_style ?>">
<?php foreach ($slice as $entry): $fm = $entry['frontmatter']; $card_img = nano_card_image_url($fm); ?>
    <article class="nano-blog-card<?= $card_img !== null ? ' has-image' : '' ?>">
      <a href="<?= nano_e(nano_post_url((string)$fm['slug'], (string)$fm['category'])) ?>">
<?php if ($card_img !== null): ?>
        <img src="<?= nano_e($card_img) ?>" alt="<?= nano_e((string)($fm['image_alt'] ?? $fm['title'])) ?>" loading="lazy">
<?php endif; ?>
        <div class="nano-blog-card-text">
          <h2><?= nano_e((string)$fm['title']) ?></h2>
          <time datetime="<?= nano_e((string)$fm['date']) ?>"><?= nano_e(date('j F Y', strtotime((string)$fm['date']))) ?></time>
          <p><?= nano_e((string)$fm['description']) ?></p>
        </div>
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
<?php if ($hero_post !== null): $hero_img = nano_card_image_url($hero_post); ?>
  <a class="nano-blog-hero<?= $hero_img !== null ? ' has-image' : '' ?>" href="<?= nano_e(nano_post_url((string)$hero_post['slug'], (string)$hero_post['category'])) ?>" style="<?= $article_grid_style ?>">
<?php if ($hero_img !== null): ?>
    <img src="<?= nano_e($hero_img) ?>" alt="<?= nano_e((string)($hero_post['image_alt'] ?? $hero_post['title'])) ?>" loading="lazy">
<?php endif; ?>
    <div class="nano-blog-hero-body">
      <h2><?= nano_e((string)$hero_post['title']) ?></h2>
      <time datetime="<?= nano_e((string)$hero_post['date']) ?>"><?= nano_e(date('j F Y', strtotime((string)$hero_post['date']))) ?></time>
      <p><?= nano_e((string)$hero_post['description']) ?></p>
    </div>
  </a>
<?php endif; ?>
<?php if (!empty($featured_posts)): ?>
  <h2 class="nano-blog-section-title">Featured</h2>
  <div class="nano-blog-grid" style="--nano-cards-per-row: <?= (int)$articles_per_row ?>; <?= $article_grid_style ?>">
<?php foreach ($featured_posts as $fp): $fp_img = nano_card_image_url($fp); ?>
    <article class="nano-blog-card<?= $fp_img !== null ? ' has-image' : '' ?>">
      <a href="<?= nano_e(nano_post_url((string)$fp['slug'], (string)$fp['category'])) ?>">
<?php if ($fp_img !== null): ?>
        <img src="<?= nano_e($fp_img) ?>" alt="<?= nano_e((string)($fp['image_alt'] ?? $fp['title'])) ?>" loading="lazy">
<?php endif; ?>
        <div class="nano-blog-card-text">
          <h2><?= nano_e((string)$fp['title']) ?></h2>
          <time datetime="<?= nano_e((string)$fp['date']) ?>"><?= nano_e(date('j F Y', strtotime((string)$fp['date']))) ?></time>
          <p><?= nano_e((string)$fp['description']) ?></p>
        </div>
      </a>
    </article>
<?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if (empty($categories)): ?>
  <p>No posts yet.</p>
<?php else: ?>
  <h2 class="nano-blog-section-title">Browse by topic</h2>
  <div class="nano-blog-grid" style="--nano-cards-per-row: <?= (int)$categories_per_row ?>; <?= $category_grid_style ?>">
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
<?= nano_render_licence_footer() ?>
<?php
$content = ob_get_clean();

$page_title = nano_e(
    $category !== null
        ? $heading . ' - ' . $site_name
        : $site_name
);
$page_description = nano_e(
    $category !== null
        ? ($cat_meta_desc !== '' ? $cat_meta_desc : 'Posts in the ' . $category . ' category.')
        : $site_name . ' - browse by topic.'
);
$meta_tags = nano_render_meta_tags_for_index($category, $page);

require __DIR__ . '/template.php';
