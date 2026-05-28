# Changelog

Notable changes to Nano CMS. Earlier per-commit history lives in git.

## [Unreleased]

### Added
- **Managed categories.** Optional `categories/<slug>.json` records (name,
  description, hero image) with a Create / Edit / Delete admin manager.
  Front-end category pages, cards, and breadcrumbs use the record when
  present and fall back to today's derived behaviour when absent - posts
  are untouched (membership still comes from each post's `category:`).
- **Image picker in the editors.** Hero/thumbnail fields and the category
  image field get a "Choose..." media-library picker with preview, and the
  markdown toolbar gets an Image button that inserts `![](file)`.
- **Cart-style media manager.** The Media page is now a single-pane manager
  (drag-and-drop upload, thumbnail grid with "unused" badges, in-page
  delete + toasts).
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
