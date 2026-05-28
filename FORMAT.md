# Nano CMS - On-Disk File Format

This document is the authoritative contract for the on-disk format used by
Nano CMS. The frontend codebase and the admin codebase are independent -
they share no PHP code - but both must conform to this format. Any change
to this document is a contract change requiring matching updates in both
codebases and a bump to `format_version` in `config.json`.

---

## Format version

Current: **1.3**

The version string is recorded in `config.json` at the `format_version`
field. Future Nano CMS releases check this on startup. An admin running an
older format than the version recorded by the last save refuses to operate
rather than silently corrupting data.

**Changes since 1.0:**

- *(1.1)* Two optional grid-layout fields added to `config.json`:
  `categories_per_row` and `articles_per_row` (default 3 each).
- *(1.1)* Four optional thumbnail-dimension fields added to
  `config.json`: `thumb_width` (default 600) and `thumb_height`
  (default 400) for article thumbnails, plus `cat_thumb_width` and
  `cat_thumb_height` for category images. The category pair falls
  back to the article pair when not set.
- *(1.1)* Convention added: media uploads through the admin save a
  pre-cropped thumbnail alongside the original at
  `<base>-thumb.<ext>` (see "Media" section).
- *(1.1)* Convention added: per-category hero images at
  `/media/category-<slug>.<ext>`. File existence is the metadata.
- *(1.2)* New optional frontmatter field on posts: `thumbnail`. When
  set, article cards use this image instead of an auto-thumbnail of
  `image`. Lets the operator upload a card-specific thumbnail
  composed independently of the full-size hero.
- *(1.3)* New optional `licence_key` field on `config.json`. Holds a
  `base64(payload).base64(signature)` Ed25519 string issued by Digital
  Fracture. When present and valid for the request host, the
  "Powered by Nano CMS" footer attribution is suppressed. Empty or
  absent = unlicensed, footer renders as usual.
- *(1.3.1)* Three optional image-pipeline fields added to `config.json`:
  `source_max_width` (default 1600) caps the pixel width of re-encoded
  source images so phone photos are downscaled at upload; `image_quality_jpeg`
  and `image_quality_webp` (both default 85, range 60-95) make encode
  quality configurable. Existing installs unchanged: the defaults match
  the previously-hardcoded values for quality, and the dimension cap
  only affects new uploads.
- *(1.3.1)* Pipeline fix: EXIF orientation is now applied to JPEG
  uploads before re-encoding. Portrait phone photos no longer display
  sideways. Imagick path uses `autoOrient()` where available.

Existing 1.0, 1.1, 1.2, and 1.3 installs remain compatible. All new fields
and conventions degrade gracefully when absent.

---

## Directory layout

```
/blog/                              ← default install path inside webroot
  /posts/                           ← Markdown post files
  /media/                           ← uploaded images
  /categories/                      ← optional category records (one JSON each)

/blog-config/                       ← OUTSIDE webroot
  config.json                       ← site settings, password hash
  rate-limit.json                   ← login attempt tracking
```

The path to `/blog-config/` is configured per deployment in
`bootstrap.php` via the `NANO_CONFIG_PATH` and `NANO_RATE_LIMIT_PATH`
constants. Both files MUST live outside webroot - they contain the
password hash and security state.

---

## Posts

### File location and naming

One post = one file. **No subdirectories under `/posts/`.**

Filename format:

```
YYYY-MM-DD-slug.md
```

Examples:

```
/posts/2026-05-06-bridging-static-sites.md
/posts/2026-04-12-why-flat-file.md
```

The date prefix gives natural sort order in directory listings. The slug
gives a human-readable hint when browsing via SFTP.

**The frontmatter `slug` field is authoritative for URLs.** The slug in
the filename exists only for human convenience. The admin keeps the two
synchronized: every save renames the file to match
`YYYY-MM-DD-{frontmatter slug}.md`. The frontend never parses the
filename to determine slug - it reads the slug from frontmatter.

### File structure

A post file is, in order:

1. A YAML-style frontmatter block, delimited by `---` lines, at the top.
2. A blank line.
3. The post body in Markdown.

Example:

```
---
title: Bridging Static Sites and SEO
slug: bridging-static-sites-seo
date: 2026-05-06
updated: 2026-05-08
category: web-design
description: A 150-character meta description targeting your keyword.
image: 2026-05-06-a4f8b2.jpg
image_alt: Diagram showing a static site with a blog directory bolted on
draft: false
---

Post content in Markdown here.

Embed a video with: [video:youtube:dQw4w9WgXcQ]
Or: [video:vimeo:123456789]
```

### Frontmatter fields

| Field         | Type     | Required | Description                                                |
|---------------|----------|----------|------------------------------------------------------------|
| `title`       | string   | yes      | Post title. Used in `<title>` and headings.                |
| `slug`        | string   | yes      | URL slug. Authoritative. `[a-z0-9-]+` only.                |
| `date`        | ISO date | yes      | Original publish date (`YYYY-MM-DD`).                      |
| `updated`     | ISO date | no       | Last meaningful edit. Auto-set by admin on save.           |
| `category`    | string   | yes      | Single category. Free-form. `[a-z0-9-]+` recommended.      |
| `description` | string   | yes      | Meta description. Aim for ~150 characters.                 |
| `image`       | string   | no       | Hero image filename, relative to `/media/`. Shown full-size on the single-post page. |
| `image_alt`   | string   | no       | Alt text for hero image. Falls back to `title` if absent.  |
| `thumbnail`   | string   | no       | Optional separate filename used on article cards. Falls back to `image`'s auto-thumbnail when absent. Added in 1.2. |
| `draft`       | boolean  | no       | `true` hides post from public output. Defaults to `false`. |
| `hero`        | boolean  | no       | `true` shows the post large at the top of the homepage. Only one post is the hero - saving a post as hero clears the flag on others. Emitted only when true. |
| `featured`    | boolean  | no       | `true` includes the post in the homepage "Featured" row (the hero is excluded from it). Emitted only when true. |

**`updated` semantics:** the admin auto-sets this to today's date
whenever the body or any frontmatter field changes on save. The user
may override (or remove) it manually for trivial edits where an
"updated" signal isn't desired.

**`category` rules:** one category per post in v1. The list of valid
categories is the union of `category` values across all existing
posts - there is no master list. The admin offers an autocomplete
based on this union when editing.

**`image_alt` fallback:** if `image_alt` is missing, the rendered
`<img>` tag uses `title` as alt text. Supplying `image_alt` explicitly
is strongly preferred for accessibility and SEO.

**`draft` behavior:** when `true`, the post is excluded from the
public listing, the sitemap, and the RSS feed. The public URL returns
404. While logged in to the admin, drafts can be previewed at
`/blog/<slug>/?preview=<csrf_token>` - the request must carry both a
valid admin session AND a matching CSRF token.

### Frontmatter quoting rules

Frontmatter is one `key: value` pair per line. The format is YAML-style
but minimal:

- A value is everything after the first `:` on the line, with leading
  and trailing whitespace trimmed.
- A value is **bare** by default. No quoting is needed for values that
  contain colons, hashes, brackets, or punctuation - only the first
  colon on the line is interpreted as the key/value delimiter.
- A value that already starts AND ends with the same quote character
  (`"..."` or `'...'`) MUST be wrapped in the *other* quote character
  to disambiguate from a quoted scalar. Likewise for values whose
  leading/trailing whitespace is significant.
- Inside double-quoted values, `\\` decodes to `\` and `\"` decodes to
  `"`. Inside single-quoted values, `''` decodes to a single `'`. No
  other escape sequences are recognized.
- Values cannot contain a literal newline. Long descriptions stay on
  one line.

Writers (the admin) prefer the quote character that is NOT present in
the value to avoid escapes. Backslash-escaping is only used when both
quote characters appear in the value.

### Body: Markdown plus shortcodes

The body is rendered with [Parsedown](https://github.com/erusev/parsedown)
in safe mode. Safe mode strips raw HTML and dangerous URLs, so any HTML
typed directly in the body is removed.

**Shortcodes** are expanded *after* Markdown rendering to bypass safe
mode for trusted output:

| Shortcode                    | Renders to                                  |
|------------------------------|---------------------------------------------|
| `[video:youtube:VIDEO_ID]`   | Responsive YouTube iframe embed.            |
| `[video:vimeo:VIDEO_ID]`     | Responsive Vimeo iframe embed.              |

**Render order is mandatory:** Markdown safe-mode pass first, then
shortcode expansion. This order ensures iframes produced by shortcodes
are not stripped by safe mode. Implementations may use placeholder
tokens (replace shortcodes with tokens before render, swap tokens for
iframes after) or post-render regex expansion - either approach is
acceptable as long as safe mode runs before any iframe HTML exists.

No other shortcodes exist in v1.

---

## Media

`/media/` holds uploaded images. Allowed extensions:

- `jpg`, `jpeg`
- `png`
- `gif`
- `webp`

No other file types are accepted.

**Filename convention:** all media uploaded through the admin is given
a sanitized, randomized filename:

```
YYYY-MM-DD-{6-hex-chars}.{ext}
```

Example: `2026-05-06-a4f8b2.jpg`. User-supplied filenames are never
preserved (avoids collisions, sanitization issues, and information
leakage about the user's local filesystem).

**Category images (added in 1.1):** in addition to randomly-named
post hero images, the admin can attach one image per category. These
live in `/media/` with a fixed filename:

```
category-<slug>.<ext>
category-<slug>-thumb.<ext>      (auto-generated thumbnail)
```

Example: `category-web-design.jpg`. The file's existence at this
path IS the metadata - no JSON sidecar, no `config.json` entry. The
frontend's `nano_category_image_url($slug)` checks this naming
convention and returns the URL when found.

**Thumbnail companion file (added in 1.1):** every successful upload
(both regular media and category images) also writes a pre-cropped
thumbnail at the same path with a `-thumb` suffix before the extension:

```
2026-05-06-a4f8b2.jpg          (original)
2026-05-06-a4f8b2-thumb.jpg    (auto-generated thumbnail)
```

Dimensions come from `thumb_width` and `thumb_height` in `config.json`
(defaults 600x400 / 3:2). The crop is cover-style with an upper bias
of 35% so subjects in the upper third survive the crop.

The frontend's `nano_thumb_url()` returns the thumbnail URL when the
file exists, falling back to the original. So any media that pre-dates
1.1 (or where thumbnail generation failed) renders normally; only new
uploads benefit from the smaller card-grid payload until the original
is re-uploaded.

**Validation requirements (admin-side):** every uploaded file must be
verified with `finfo_file()` against its claimed extension AND
re-encoded through GD or Imagick before being saved. Re-encoding
strips any embedded payload (e.g. PHP smuggled into EXIF). Files that
fail re-encoding are rejected.

**Application-level size limit:** 5 MB, enforced in PHP regardless of
`upload_max_filesize` / `post_max_size` server settings.

`/media/.htaccess` disables PHP execution in this directory:

```apache
php_flag engine off
<FilesMatch "\.(php|phtml|phar)$">
    Deny from all
</FilesMatch>
```

---

## Category records (`categories/<slug>.json`)

Optional, additive metadata for a category. A category's *membership* is
always driven by the `category:` field of the posts that use it - a record
never changes that. A record only adds a display name, description, and hero
image for the category's page and homepage card. A category with **no**
record still works: its name is derived from the slug and its image falls
back to the legacy `media/category-<slug>.<ext>` convention.

```json
{
  "slug": "web-design",
  "name": "Web Design",
  "description": "Articles on building and styling websites.",
  "image": "2026-05-28-a4f8b2.jpg",
  "created": "2026-05-28T10:00:00Z",
  "updated": "2026-05-28T10:00:00Z"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `slug` | string | `[a-z0-9-]+`. The filename stem and the value posts reference. Authoritative; never rewritten. |
| `name` | string | Display name (heading, card, breadcrumb). Required when a record exists. |
| `description` | string | Shown on the category page; used as its meta description. Optional. |
| `image` | string | A `/media/` filename (chosen in the editor). Optional; falls back to the `category-<slug>` convention then to none. |
| `created` / `updated` | string | ISO-8601 UTC timestamps, maintained by the admin. |

Records are managed in the admin Categories page; deleting a record reverts
the category to plain derived behaviour (it does not touch posts).

---

## `config.json`

Lives **outside webroot** at the path declared by `NANO_CONFIG_PATH` in
`bootstrap.php`.

```json
{
  "format_version": "1.3",
  "site_name": "Acme Corp Blog",
  "base_url": "https://acmecorp.com/blog",
  "author": "David Smith",
  "publisher_name": "Acme Corp",
  "publisher_logo": "https://acmecorp.com/logo.png",
  "posts_per_page": 10,
  "categories_per_row": 3,
  "articles_per_row": 3,
  "thumb_width": 600,
  "thumb_height": 400,
  "licence_key": "",
  "password_hash": "$2y$10$...",
  "created": "2026-05-06T10:30:00Z",
  "admin_version_last_used": "1.3.1"
}
```

| Field                     | Type          | Description                                                         |
|---------------------------|---------------|---------------------------------------------------------------------|
| `format_version`          | string        | On-disk format version. This document describes 1.3.                |
| `site_name`               | string        | Used in `<title>` suffix, RSS, OpenGraph `site_name`.               |
| `base_url`                | string (URL)  | Absolute base URL of the blog. Used to build canonical URLs.        |
| `author`                  | string        | Default author name. Used in JSON-LD `author` and RSS.              |
| `publisher_name`          | string        | Publisher name in JSON-LD `BlogPosting.publisher`.                  |
| `publisher_logo`          | string (URL)  | Publisher logo URL in JSON-LD `BlogPosting.publisher.logo`.         |
| `posts_per_page`          | integer       | Pagination size for category archive pages. Default 10.             |
| `categories_per_row`      | integer       | Category cards per row on the homepage. Allowed `3` or `4`. Default `3`. Optional, added in 1.1. |
| `articles_per_row`        | integer       | Article cards per row on category archives. Allowed `3` or `4`. Default `3`. Optional, added in 1.1. |
| `thumb_width`             | integer       | Width in pixels of auto-generated article thumbnails. Range 100-2400. Default `600`. Optional, added in 1.1. |
| `thumb_height`            | integer       | Height in pixels of auto-generated article thumbnails. Range 100-2400. Default `400`. Optional, added in 1.1. |
| `cat_thumb_width`         | integer       | Width in pixels of auto-generated category-image thumbnails. Range 100-2400. Falls back to `thumb_width` when absent. Optional, added in 1.1. |
| `cat_thumb_height`        | integer       | Height in pixels of auto-generated category-image thumbnails. Range 100-2400. Falls back to `thumb_height` when absent. Optional, added in 1.1. |
| `source_max_width`        | integer       | Maximum pixel width for re-encoded source images. Sources wider than this are downscaled at upload time. Range 400-4000. Default `1600`. Prevents large phone photos from being served at full resolution. Optional, added in 1.3.1. |
| `image_quality_jpeg`      | integer       | JPEG encode quality for re-encoded sources and thumbnails. Range 60-95. Default `85`. Optional, added in 1.3.1. |
| `image_quality_webp`      | integer       | WebP encode quality for re-encoded sources and thumbnails. Range 60-95. Default `85`. Optional, added in 1.3.1. |
| `licence_key`             | string        | Ed25519 `base64(payload).base64(signature)` licence issued by Digital Fracture. Suppresses the footer attribution when present and valid for the request host. Empty / missing = unlicensed. Optional, added in 1.3. |
| `password_hash`           | string        | bcrypt hash from PHP `password_hash()`. Single password per site.   |
| `created`                 | ISO 8601 UTC  | Set by setup wizard. Informational.                                 |
| `admin_version_last_used` | semver string | Bumped on every save. See compatibility check below.                |

**Compatibility check:** on startup the admin compares
`admin_version_last_used` against its own version constant. If the
admin running is older than the value recorded, it refuses to operate
and asks the user to upload a newer admin. This prevents an old
admin from overwriting data written by a newer format.

---

## `rate-limit.json`

Lives **outside webroot** at the path declared by `NANO_RATE_LIMIT_PATH`
in `bootstrap.php` (typically alongside `config.json`).

This file tracks failed login attempts per IP so the rate-limiter can
enforce: 5 failures in 15 minutes → 1-hour IP block.

The schema is an internal implementation detail of the admin. It is
**not** part of the cross-codebase contract - only the admin reads or
writes it; the frontend never touches it. The schema may change between
admin versions without bumping `format_version`.

The file is self-pruning: entries older than the relevant window
(15 minutes for failure counting, 1 hour for active blocks) are dropped
on next read.

If the file is missing, the admin treats it as empty and creates it on
first failed login.

The file lives outside webroot specifically so that lockout state
survives the admin folder being removed and re-uploaded - an attacker
cannot reset their lockout by forcing a fresh admin install.

---

## Generated files

The admin regenerates these on every save. Both must always reflect
current published content (drafts excluded) at the moment of the most
recent save.

### `sitemap.xml`

XML sitemap at `/blog/sitemap.xml`. Includes:

- One `<url>` entry per published (non-draft) post
- One `<url>` entry per category archive page that contains at least
  one published post
- The blog index URL
- `<lastmod>` populated from `updated` if present, else `date`

Drafts MUST NOT appear in the sitemap.

### `feed.xml`

RSS 2.0 feed at `/blog/feed.xml`. Includes the most recent N published
posts (where N matches `posts_per_page`). Each item carries title,
link (absolute URL), description (the post `description` field),
publish date, and GUID (the canonical URL).

Drafts MUST NOT appear in the feed.

---

## Stability policy

The on-disk format is the only contract shared between the two
codebases. Stability rules:

- The frontmatter required-field set is stable. Adding a new optional
  field is a minor version bump (1.0 → 1.1).
- Removing a field, renaming a field, or changing a field's type is a
  major version bump (1.0 → 2.0) and requires a migration path.
- The shortcode set is stable. Adding new shortcodes is a minor bump.
- The `config.json` schema follows the same rules. Adding optional
  fields is minor; removing or renaming is major.
- The `rate-limit.json` schema is internal and not versioned.

Backward compatibility goal: an installation set up under format 1.0
should remain readable by all 1.x admin versions without manual
migration.
