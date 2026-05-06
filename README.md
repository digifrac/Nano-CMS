# Nano CMS

A flat-file PHP blog system for adding SEO-driven content to existing static HTML/CSS sites.

> **Status: in active development.** Not yet ready for production use. See [Roadmap](#roadmap) below.

---

## What it is

Nano CMS solves a specific problem: client websites that should stay as fast, simple static HTML, but need a steady stream of fresh blog content for search ranking. Rather than converting the whole site to WordPress or Joomla, Nano CMS slots a small blog system into an existing static site with minimal disruption.

It is deliberately **not** a general-purpose CMS. It does one thing — serve a blog with strong SEO output — and tries to do it well in as little code as possible.

---

## How it works

- **Markdown files on disk are the database.** No MySQL, no SQLite, no setup steps.
- **The frontend** lives permanently inside the client's webroot. It renders blog posts and indexes via clean URLs, generates a sitemap and RSS feed, and outputs full SEO metadata (Open Graph, Twitter Cards, JSON-LD schema) on every page.
- **The admin is a universal, portable folder.** Identical across all deployments. Upload it temporarily via SFTP when you want to publish, then remove it. No persistent admin = drastically reduced attack surface on client sites.
- **Per-site config lives outside webroot.** Password hashes and site settings stored in a JSON file that's structurally unreachable via HTTP.
- **No frameworks.** No Bootstrap, no Tailwind, no React, no jQuery, no build step. Hand-written PHP, scoped CSS, and minimal vanilla JavaScript.

Total target size: roughly 1500-1800 lines across the frontend and admin codebases combined.

---

## Who it's for

Web developers who build static sites for clients and want to add ranking blog content without taking on the weight of a full CMS. The tool assumes operators are fluent with Markdown and comfortable with SFTP. It is not aimed at non-technical end users — for those, WordPress is the right answer.

If you've ever installed WordPress just to publish four blog posts a year on a client site, this is for you.

---

## Why not WordPress or Joomla?

Both are excellent for sites that need them. But for a static HTML client site that needs occasional SEO content:

- WordPress requires a database, ongoing security updates, plugin maintenance, and significant attack surface
- Joomla brings a steeper learning curve and more weight than the use case justifies
- Both fundamentally convert the site into a database-driven application; the static HTML is gone

Nano CMS keeps the host site static. The blog adds rendered HTML pages alongside the existing site, sharing its design and CSS. When the admin is removed, only flat files remain — there is no service to maintain, no version to update, no plugin to patch.

---

## Why not Pico, Bludit, or Grav?

These are all good flat-file CMSes that influenced this project. The differences:

- **Pico** is closest in spirit but is designed as a standalone site builder, not as a drop-in for existing static sites.
- **Bludit** has more features (multi-user, plugins, themes) — useful in many cases, scope creep here.
- **Grav** is significantly larger and more feature-rich, with its own template language and plugin architecture.

Nano CMS deliberately stays smaller than all of them. The portable admin pattern — admin uploaded temporarily, removed after use — is also unusual in this category.

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

Markdown files in `/posts/` and uploaded media in `/media/` are the only persistence — there is no database to dump and restore. Backups are the developer's responsibility; the CMS itself does not run them.

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

Adapt to your preferred backup target — cloud sync, restic, tarballs, anything works because all state is files.

---

## Roadmap

**v0.1 (current — in development):**
- Frontend rendering, parser, file format
- Basic single-post and index pages
- `nano.css` neutral default stylesheet

**v0.2:**
- Sitemap and RSS generators
- Universal portable admin (login, post editor, media manager)
- Setup wizard for first-time deployment

**v0.3:**
- Documentation, deployment guide, example installation
- First production deployment to a real client site

**v1.0:**
- Stable file format
- Polished admin UX
- Public release

**Possible future (no commitment):**
- Two-factor authentication for the admin
- Tag support alongside categories
- Image gallery shortcode
- Dark mode CSS variants

Features explicitly **not** planned: multi-user accounts, plugin system, theme system, WYSIWYG editor, comments, scheduled publishing, post revisions. The project will not accept feature requests for any of these.

---

## Contributing

The project is in early-stage solo development and not yet ready for outside contributions. Once v1 is stable, contribution guidelines will be added.

Bug reports and architectural feedback are welcome via GitHub Issues even now.

---

## License

[MIT](LICENSE) © 2026 Digital Fracture

---

## See also

- [`CLAUDE.md`](CLAUDE.md) — full architectural specification (internal docs)
- [Digital Fracture](https://digitalfracture.co.uk) — the developer's site
