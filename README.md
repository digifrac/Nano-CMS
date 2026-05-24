# Nano CMS

A flat-file PHP blog system for adding SEO-driven content to existing static HTML/CSS sites.

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-FF4D00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=white)](https://buymeacoffee.com/digitalfracture)

> **Status: v1.3.1.** Live on at least one production host. See [INSTALL.md](INSTALL.md) for deployment and [CATEGORIES.md](CATEGORIES.md) for the v1.1 / v1.2 changes.

---

## What it is

Nano CMS solves a specific problem: client websites that should stay as fast, simple static HTML, but need a steady stream of fresh blog content for search ranking. Rather than converting the whole site to WordPress or Joomla, Nano CMS slots a small blog system into an existing static site with minimal disruption.

It is deliberately **not** a general-purpose CMS. It does one thing - serve a blog with strong SEO output - and tries to do it well in as little code as possible.

---

## How it works

- **Markdown files on disk are the database.** No MySQL, no SQLite, no setup steps.
- **The frontend** lives permanently inside the client's webroot. It renders blog posts and indexes via clean URLs, generates a sitemap and RSS feed, and outputs full SEO metadata (Open Graph, Twitter Cards, JSON-LD schema) on every page.
- **The admin is a universal, portable folder.** Identical across all deployments. Upload it temporarily via SFTP when you want to publish, then remove it. No persistent admin = drastically reduced attack surface on client sites.
- **Per-site config lives outside webroot.** Password hashes and site settings stored in a JSON file that's structurally unreachable via HTTP.
- **No frameworks.** No Bootstrap, no Tailwind, no React, no jQuery, no build step. Hand-written PHP, scoped CSS, and minimal vanilla JavaScript.

Total size: around 5000 lines of hand-written PHP, CSS, and minimal JS, and the whole CMS deploys in under 360KB on disk (vendored Parsedown included). For comparison: Grav core is ~30k lines, Eleventy is ~10k, WordPress is ~500k - a CMS this small is the point.

---

## Who it's for

Web developers who build static sites for clients and want to add ranking blog content without taking on the weight of a full CMS. The tool assumes operators are fluent with Markdown and comfortable with SFTP. It is not aimed at non-technical end users - for those, WordPress is the right answer.

If you've ever installed WordPress just to publish four blog posts a year on a client site, this is for you.

---

## Why not WordPress or Joomla?

Both are excellent for sites that need them. But for a static HTML client site that needs occasional SEO content:

- WordPress requires a database, ongoing security updates, plugin maintenance, and significant attack surface
- Joomla brings a steeper learning curve and more weight than the use case justifies
- Both fundamentally convert the site into a database-driven application; the static HTML is gone

Nano CMS keeps the host site static. The blog adds rendered HTML pages alongside the existing site, sharing its design and CSS. When the admin is removed, only flat files remain - there is no service to maintain, no version to update, no plugin to patch.

---

## Why not Pico, Bludit, or Grav?

These are all good flat-file CMSes that influenced this project. The differences:

- **Pico** is closest in spirit but is designed as a standalone site builder, not as a drop-in for existing static sites.
- **Bludit** has more features (multi-user, plugins, themes) - useful in many cases, scope creep here.
- **Grav** is significantly larger and more feature-rich, with its own template language and plugin architecture.

Nano CMS deliberately stays smaller than all of them. The portable admin pattern - admin uploaded temporarily, removed after use - is also unusual in this category.

---

## Architecture in one diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                     CLIENT SITE (permanent)                     │
│                                                                 │
│  /public_html/blog/                                             │
│    ├── posts/         ← Markdown files (the "database")         │
│    ├── media/         ← uploaded images                         │
│    ├── assets/        ← nano.css, optional theme overrides      │
│    ├── core.php       ← parser, renderer                        │
│    ├── index.php      ← blog listing                            │
│    ├── post.php       ← single post                             │
│    ├── template.php   ← per-site HTML wrapper                   │
│    ├── bootstrap.php  ← per-site config paths                   │
│    ├── sitemap.xml    ← regenerated on save                     │
│    └── feed.xml       ← regenerated on save                     │
│                                                                 │
│  /blog-config/         ← OUTSIDE webroot                        │
│    ├── config.json     ← password hash, site settings           │
│    └── rate-limit.json ← login attempt tracking                 │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│              UNIVERSAL PORTABLE ADMIN (ephemeral)               │
│                                                                 │
│  Uploaded to /public_html/blog/admin/ when publishing.          │
│  Removed afterwards. Identical for every deployment.            │
│  Contains zero site-specific data.                              │
└─────────────────────────────────────────────────────────────────┘
```

---

## SEO features

Every published post includes, automatically:

- Custom `<title>` and meta description from frontmatter
- Canonical URL
- Open Graph tags (Facebook, LinkedIn sharing)
- Twitter Card tags
- JSON-LD `BlogPosting` schema (rich results in Google)
- Semantic HTML5 structure
- Clean URLs via `.htaccess`
- `loading="lazy"` and descriptive `alt` text on images (from frontmatter)
- XML sitemap submission-ready
- RSS 2.0 feed

Combined with the host site's existing CSS, posts inherit the site's design while shipping with technical SEO most WordPress sites need three plugins to achieve.

---

## Requirements

- PHP 8.1 or later
- Apache with `mod_rewrite` (for clean URLs)
- HTTPS (required for the admin login)
- SFTP access to client sites (for deploying frontend and uploading admin)

Tested on shared hosting (cPanel-style). No special privileges required.

---

## Backup

Markdown files in `/posts/` and uploaded media in `/media/` are the only persistence - there is no database to dump and restore. Backups are the developer's responsibility; the CMS itself does not run them.

A simple `cron` + `rsync` line on a backup machine handles this for any number of client sites:

```bash
# Daily 03:00 backup of one client's blog content and config
0 3 * * * rsync -az -e "ssh -p 22" \
  user@clientsite.com:/home/clientuser/public_html/blog/posts/ \
  /backups/clientname/posts/

0 3 * * * rsync -az -e "ssh -p 22" \
  user@clientsite.com:/home/clientuser/public_html/blog/media/ \
  /backups/clientname/media/

0 3 * * * rsync -az -e "ssh -p 22" \
  user@clientsite.com:/home/clientuser/blog-config/ \
  /backups/clientname/config/
```

Adapt to your preferred backup target - cloud sync, restic, tarballs, anything works because all state is files.

---

## What's in 1.0

- Frontend rendering, parser, locked-in file format (see [FORMAT.md](FORMAT.md))
- Single-post and index pages with category archives, breadcrumbs, clean URLs
- `nano.css` neutral default stylesheet
- Sitemap and RSS generators, regenerated atomically on every save
- Universal portable admin: login, post editor, media manager
- First-time setup wizard, version-compat check, atomic config write
- HTTPS-only admin, CSRF on every POST, bcrypt + rate-limited login, browser-session cookies, idle-timeout sessions, password-hash-bound sessions
- Image upload pipeline with GD/Imagick re-encode (defends against EXIF-payload smuggling)
- Deployment guide ([INSTALL.md](INSTALL.md)) and pre-flight host check script

## What's new in 1.3

- **Cryptographic licence verification.** Customers can now suppress the "Powered by Nano CMS" footer attribution by pasting a paid Ed25519 licence key into the admin Licence page (or directly into `config.json`). Verification is fully offline against a public key embedded in the build - no phone-home, no licence server, no telemetry. Localhost, `*.test`, `*.local`, and ports-in-host are auto-bypassed for development. See "Removing the footer attribution" below.
- **Footer attribution by default.** Nano-rendered pages now show a small `Powered by Nano CMS - Developed by Digital Fracture` footer until a valid licence is present. Renders inside `<main class="nano-blog">` so it inherits the host site's content scope, and styling uses the existing `nano.css` custom-property tokens.
- **New admin Licence page** at `/admin/licence.php` with paste, verify, and remove flows. Verbose error reasons in the admin (operator-only) - silent on the public frontend.

## What's new in 1.2

- **Joomla-style separate card thumbnail per post.** New optional `thumbnail` frontmatter field. When set, the article card on the category archive uses this image instead of an auto-cropped thumbnail of `image`. Lets you upload a card-specific image whose composition is tuned for the small grid context, while `image` stays the full-size single-post hero.
- **Site name editable from admin Settings.** Previously fixed at install time by the setup wizard; now changeable any time without editing `config.json` by hand.
- **Common-size hint chips on the Settings page.** Quick-reference list of standard thumbnail dimensions (3:2, 16:9, 4:3, 1:1) so you don't have to guess.

## What's new in 1.1

- **Blog homepage redesigned as a category landing.** Visitors arriving at `{base_url}/` see a card grid of categories (sorted by post count) instead of a feed of recent posts. Pick a topic, click into the category archive, read articles there. See [CATEGORIES.md](CATEGORIES.md) for the rationale.
- **Independent 3- or 4-column grids** for the homepage category landing and the category archive article list. Each grid has its own setting (`categories_per_row` and `articles_per_row`), so any of 3-3, 3-4, 4-3, or 4-4 works.
- **New admin settings page** at `/admin/settings.php` exposes the grid settings and thumbnail dimensions. Future settings can grow there without adding new admin pages.
- **Auto-generated thumbnails on every upload** - the admin's image pipeline saves a pre-cropped thumb (default 600&times;400, 3:2) alongside each original. Article cards use the thumb so category archives stay light; single-post heroes keep the full-size image. Existing media falls back to the original until re-uploaded.
- **One image per category, optional.** The new admin Categories page lets you attach a hero image to each category. The image appears at the top of that category's card on the homepage. No JSON metadata - the file's existence at `/media/category-<slug>.<ext>` is the metadata.
- **Polished card visuals** - subtle accent on hover, gentle lift, cleaner typography. Underline rules removed from hyperlinks site-wide; links signal interactivity through colour and hover state instead.
- **URL structure simplified.** The `/category/` prefix is removed. Category archives are now `/{cat}/`, posts are `/{cat}/{slug}/`. Pagination is `/{cat}/page/N/`.
- **Admin login screen and every admin page now show the version** in a small footer line, so a non-developer can tell at a glance which version is running.

**Possible future (no commitment):**
- Two-factor authentication for the admin
- Tag support alongside categories
- Image gallery shortcode
- Dark mode CSS variants
- Admin settings page for `site_name`, `posts_per_page`, etc. (currently set at install via the setup wizard, only changeable by hand-editing `config.json`)

Features explicitly **not** planned: multi-user accounts, plugin system, theme system, WYSIWYG editor, comments, scheduled publishing, post revisions. The project will not accept feature requests for any of these.

---

## Removing the footer attribution

Nano CMS displays a small `Powered by Nano CMS - Developed by Digital Fracture` footer on the pages it renders. The CMS itself is MIT-licensed and free for any use; the footer covers ongoing development. To remove it on production sites, purchase a perpetual per-domain licence:

- **Single domain:** £29
- **Agency 3-pack:** £69 (covers up to three client domains)
- **Agency unlimited:** £249 (single wildcard licence covers any domain you own)

Buy at [digitalfracture.co.uk/nano-licence.html](https://digitalfracture.co.uk/nano-licence.html).

Once you have a licence string, either:

1. **Paste it in the admin** under **Licence** (then remove the admin folder again as normal), or
2. **Edit `config.json` directly** via SFTP and put the string in the `licence_key` field.

Either way the frontend verifies the signature on every page render against the embedded public key. There is no network call and no licence server - lose the licence file and re-issue is free.

Localhost, `*.test`, `*.local`, and any host with a port in the URL skip the licence check entirely so local development never shows the footer.

---

## Contributing

Solo-developed. Bug reports and architectural feedback are welcome via GitHub Issues. Pull requests are not currently accepted — the scope discipline that keeps the codebase small is hard to enforce from outside the project.

---

## License

[MIT](LICENSE) © 2026 Digital Fracture

---

## See also

- [`INSTALL.md`](INSTALL.md) - step-by-step deployment guide
- [`FORMAT.md`](FORMAT.md) - on-disk file format contract (post files, frontmatter, config schema)
- [`CATEGORIES.md`](CATEGORIES.md) - categories index page spec (1.1)
- [Digital Fracture](https://digitalfracture.co.uk) - the developer's site
