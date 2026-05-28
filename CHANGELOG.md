# Changelog

Notable changes to Nano CMS. Earlier per-commit history lives in git.

## [Unreleased]

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
