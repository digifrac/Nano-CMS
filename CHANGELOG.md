# Changelog

Notable changes to Nano CMS. Earlier per-commit history lives in git.

## [1.5.0] - 2026-05-28

### Added
- **Per-image background colour (like Cart's image_bg).** Hex colours shown
  behind images (e.g. through the transparent areas of a PNG), set in the
  editor and applied inline per image. Articles have two independent colours:
  a hero-image background (`image_bg`, behind the lead image and any card
  derived from it) and a card-thumbnail background (`thumbnail_bg`, behind a
  separate thumbnail). Categories take one `image_bg` for their card and
  banner. Blank = transparent.
- **Separate thumbnail alt text.** Articles get a `thumbnail_alt` field used
  for the card image when a separate thumbnail is set, falling back to the
  hero image's alt text, then the title.
- **Category sort order.** Category records take an optional `sort_order`
  number; the homepage category grid and the admin category manager order by
  it (lower first), with un-numbered categories falling to the bottom
  alphabetically - mirroring Cart. Articles remain date-sorted (newest
  first), which suits a blog.
- **Managed categories.** Optional `categories/<slug>.json` records (name,
  description, hero image) with a Create / Edit / Delete admin manager.
  Front-end category pages, cards, and breadcrumbs use the record when
  present and fall back to today's derived behaviour when absent - posts
  are untouched (membership still comes from each post's `category:`).
- **Category page header (like Cart's).** A category's archive page now shows
  its banner image and a Markdown description side-by-side above the post grid,
  with the image on the left or right (chosen per category in the editor). The
  description supports headings/bold/lists; a plain-text version is used for the
  meta description. Falls back to just the heading when no image/description is
  set.
- **Image picker in the editors.** Hero/thumbnail fields and the category
  image field get a "Choose..." media-library picker with preview, and the
  markdown toolbar gets an Image button that inserts `![](file)`.
- **Cart-style media manager with folders.** The Media page is a single-pane
  file browser: a breadcrumb, create/delete folders, drag-and-drop upload,
  drag an image onto a folder to move it, rename, delete, "unused" badges,
  and toasts. Images can live in folders and posts/categories reference them
  by path; moving or renaming an image updates the posts and category records
  that reference it. Two permanent folders (`article-images`,
  `category-images`) can't be deleted. Styled with Nano Cart's media CSS
  verbatim so the two products' media managers look identical.
- Homepage sections (hero / Featured / Browse by topic) are separated by
  divider rules.
- **Homepage hero + featured articles.** New `hero` (one, unique) and
  `featured` post flags render a large hero article and a "Featured" row at
  the top of the blog homepage, above the category cards.

### Changed
- Card thumbnails are generated from the original upload (single
  compression), default size 1200x800, quality 90 - sharper, less washed.

## [1.4.0] - 2026-05-28

### Added
- **Web installer (`install.php`).** Creates the outside-webroot config
  directory (DOCUMENT_ROOT-aware, so it never lands inside an addon-domain
  webroot on cPanel/Plesk), writes `bootstrap.php`, and hands off to the
  setup wizard. It self-deletes via a one-click red banner on the admin
  dashboard once setup is complete (deleting earlier would break the
  hand-off). `INSTALL.md` now lists it as the primary install path, with
  the manual `bootstrap.example.php` copy kept as a fallback.
- Dashboard stat cards: published posts, draft posts, and categories.
- Dashboard quick-actions panel: new post, media, categories, edit settings,
  view blog.
- **Dashboard health-check panel.** Verifies PHP version, the image
  extension (GD/Imagick), `fileinfo`, that every required front-end file is
  present, that `config.json` loads, and that `media/` is writable - with a
  red banner if anything fails. Catches a half-finished upgrade in the
  admin instead of via a dead public page.
- **Upgrade guide (`UPGRADE.md`).** Documents the flat-file upgrade flow
  (extract release ZIPs; never overwrite `bootstrap.php`, the config
  directory, or `posts/`/`media/`), with replaced-vs-preserved file lists.

### Changed
- **Admin redesign to match the Nano Cart admin.** The admin now uses a
  shared sticky-header scaffold with one canonical nav and current-page
  highlighting (replacing the per-page nav bar that varied between pages),
  lifted section panels with gradient header bands, zebra-striped tables,
  fieldset-grouped forms with a form-actions separator, and consistent
  shadowed buttons. All admin styling is scoped under the
  `nano-cms-admin-*` class prefix. The CSRF-protected logout, HTTPS
  enforcement, rate limiting, and licence/attribution behaviour are
  unchanged.
- Front-end `template.php` gains a paste-zone heads-up note (about the
  scoped, no-reset CSS) and an optional, commented-out default web-font
  block.

### Fixed
- Setup wizard rejected every base URL. The validation regex used `~` as
  its delimiter while also containing a literal `~` in the character class,
  so the pattern failed to compile and no URL could pass. Switched the
  delimiter to `#`.
