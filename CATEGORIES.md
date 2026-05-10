# Nano CMS - Category Landing on the Blog Homepage

This document describes the v1.1 redesign of the blog homepage. Format
version bumps to 1.1 because one optional field is added to
`config.json` (`cards_per_row`). Existing 1.0 installs remain
compatible - the field falls back to its default (3) when absent.

---

## What changed in 1.1

The blog homepage at `{base_url}/` no longer renders the post list.
Instead, it renders a category landing - one card per category, each
showing the category name and a post count. The visitor picks a topic,
lands on the category archive, and reads articles there.

```
{base_url}/                                Category landing
{base_url}/{cat}/                          Article list for that category
{base_url}/{cat}/page/2/                   Paginated category archive
{base_url}/{cat}/{post-slug}/              Single article
```

The `category/` URL prefix from v1.0 is dropped. URLs now use the
shorter, semantic shape `/<category>/<post>/`. Wrong-category access
to a post (e.g. requesting `/holidays/post-x/` when post-x is in a
different category) returns 404 to prevent duplicate-content SEO
issues.

---

## Why

A "homepage that lists every recent post regardless of topic" is a
weaker fit for small SEO blogs than a "homepage that lets the visitor
choose a topic." Most visitors arrive at a specific post via search;
when they land on the bare blog URL they are exploring, and the most
useful first action is to pick the topic that matches their interest.

A standalone `/categories/` hub page was prototyped and rejected: it
sits in a confusing middle space between "homepage" and "category
archive" and serves no clear use case. Folding the category landing
into the homepage gives every visitor a clear path without inventing a
third URL.

---

## Card content

Each card shows:

- **Category label** (the heading, derived as
  `ucfirst(str_replace('-', ' ', $slug))`).
- **Post count** with correct singular/plural ("1 article" / "N articles").

That's it. The card is the entire `<a>` element, linking to the
matching `/category/{slug}/` archive. No latest-post title, no date,
no description - the card is purely a navigation aid, not a preview.

---

## Sort order

Categories are sorted **by post count descending, ties alphabetical
by label**. Most active topics surface first.

---

## Layout

Both views (homepage category landing AND category archive article
list) use the same shared CSS Grid wrapper class `.nano-blog-grid`.
Column count is controlled per-grid via two independent config fields:
`categories_per_row` (homepage) and `articles_per_row` (archives).
Each is read by `index.php` and set inline on the grid as the
`--nano-cards-per-row` CSS variable.

- Desktop: 3 or 4 columns per grid (independently configurable, default 3).
- Tablet (max-width 900px): both grids collapse to 2 columns.
- Phone (max-width 600px): both grids collapse to 1 column.

Both card variants (category cards and article cards) use the same
visual treatment - border, shadow, hover lift.

## Admin control

The two values are editable from the admin settings page at
`/admin/settings.php`. Allowed values are 3 or 4 each; any other
value silently falls back to 3 to avoid 500-ing the page on a bad
config edit. The two settings are independent, so any of 3-3, 3-4,
4-3, 4-4 is valid.

---

## Pagination

The bare homepage no longer paginates - it just lists every category
once. Hitting `{base_url}/page/N/` for `N > 1` returns 404 to prevent
duplicate-content SEO issues.

Category archives still paginate, and now use the clean URL form
`{base_url}/category/{slug}/page/N/` instead of the v1.0
`?page=N` query string. The query-string form continues to work for
back-compat with any old internal links.

---

## Empty state

A site with zero published posts (no categories) renders a single
"No posts yet." line.

---

## Sitemap

Unchanged from v1.0. The sitemap entry for `{base_url}/` still
represents the blog homepage; that homepage just renders different
content now. No new URL is added or removed.

---

## Files affected

- `index.php` - branches on `?category` to render either the category
  landing or the article list. Reads `cards_per_row` config and sets
  `--nano-cards-per-row` inline.
- `core.php` - new `nano_list_categories_with_counts()` helper.
- `.htaccess` - one new rewrite rule for clean category pagination
  (`^category/([a-z0-9-]+)/page/([0-9]+)/?$`). All other rules
  unchanged.
- `assets/nano.css` - shared `.nano-blog-grid` with CSS variable
  controlling column count, plus polished card styling
  (border accent on hover, gentle translate, refined typography).
  Underline rules removed from hyperlinks across the board.
- `admin/settings.php` - new admin page exposing `cards_per_row`.
  Linked from every admin page's nav bar.
- `admin/index.php`, `admin/edit.php`, `admin/media.php`,
  `admin/help.php` - nav bars gain a "Settings" link.
- `FORMAT.md` - one row added to `config.json` table, format version
  bumped to 1.1.

No changes to sitemap generator, RSS generator, or post rendering.

---

## Stability

The homepage URL `{base_url}/` is the same URL as in v1.0 and continues
to be the canonical landing page for the blog. Visitors arriving from
old bookmarks land on the new layout without redirect.

The visual treatment of the category cards may evolve without a
format-version bump, since it is implementation detail rather than
on-disk contract.

---

## Future work (not in 1.1)

**Per-post separate thumbnail and hero image fields.** Currently the
post's `image` frontmatter field is the source for both the article
card thumbnail (cropped via the auto-thumbnail pipeline) and the
single-post hero (rendered full-size). For images whose composition
fights the auto-crop in either context, a Joomla-style two-image
pattern (separate `thumbnail:` and `image:` frontmatter fields,
either of which can fall back to the other) is the planned next
step. Tracked for v1.2.
