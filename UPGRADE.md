# Upgrading Nano CMS

Nano CMS is flat-file: the code is PHP files on disk, your data is Markdown
posts and images on disk, and your configuration lives outside the webroot.
Upgrading is replacing the **code** with a new release and leaving your
**data and config** untouched. Done the way below, an upgrade cannot lose
your posts, images, or settings.

---

## The one rule that prevents broken sites

**Deploy the official release ZIPs. Do not upload a folder of hand-picked
files, and do not upload your own working copy of the project.**

Two things go wrong when people cherry-pick files or copy a development
folder over a live blog:

1. **A required file gets missed.** If even one front-end file (for example
   `template.php` or `lib/Parsedown.php`) is absent, public pages fail. A
   complete ZIP can never miss a file.
2. **`bootstrap.php` or `config.json` gets overwritten.** `bootstrap.php`
   holds the absolute path to your config directory and is unique to your
   server. A development copy points somewhere else, so overwriting it
   breaks the link to your configuration and takes the whole blog down. The
   release ZIPs contain `bootstrap.example.php`, never `bootstrap.php`, and
   never `config.json`, so they cannot clobber it.

If you only remember one thing: **extract the ZIP, never overwrite
`bootstrap.php`, config, or your content folders.**

---

## Files: what a release replaces, and what it must never touch

**Replaced by the release (safe to overwrite):**

- Front end (`nano-cms-frontend.zip`): `core.php`, `index.php`, `post.php`,
  `template.php`, `generators.php`, `licence.php`, `install.php`,
  `.htaccess`, `assets/`, `lib/`
- Admin (`nano-cms-admin.zip`, when present): everything under `admin/`

**Never overwritten or deleted by you during an upgrade (your data and config):**

- `bootstrap.php` (your server's config path; written once by `install.php`
  or copied by hand from `bootstrap.example.php`)
- Your config directory outside the webroot (holds `config.json` and
  `rate-limit.json`)
- `posts/` and `media/`
- `sitemap.xml` and `feed.xml` (regenerated automatically on the next save)

The front-end ZIP carries empty `.gitkeep`-style placeholders for `posts/`
and `media/`, so extracting it adds nothing to those folders and removes
nothing from them.

> **`install.php` reappears after an upgrade.** It ships in the front-end
> ZIP, so re-extracting puts it back. That is harmless: it refuses to run
> while `bootstrap.php` exists, and the admin dashboard shows a one-click
> banner to delete it again. Remove it once you have finished, the same as
> after first install.

---

## Safe upgrade, step by step

1. **Back up first.** Restore is trivial if anything goes wrong:

   ```sh
   rsync -az --delete /home/clientuser/example.com/blog/ /var/backups/blog-$(date +%F)/
   rsync -az --delete /home/clientuser/blog-config/      /var/backups/blog-config-$(date +%F)/
   ```

2. **Read the [CHANGELOG.md](CHANGELOG.md) entry** for the new version to
   see whether anything beyond a file replacement is needed.

3. **Download the release ZIPs** for the new version
   (`nano-cms-frontend.zip`, and `nano-cms-admin.zip` if you are editing
   content this session).

4. **Extract over your blog.** Unzip the front-end ZIP into the directory
   that contains your blog (it writes into `blog/`), then unzip the admin
   ZIP into `blog/` (it writes into `blog/admin/`). Upload in **binary**
   mode if your client asks. Because the ZIPs contain no `bootstrap.php`,
   no config, and no content, your data is left exactly as it was.

5. **Confirm it landed.** Sign in to the admin dashboard and check the
   **Health check** panel at the bottom: it verifies the PHP version, the
   image extension (GD/Imagick), that every required front-end file is
   present, that `config.json` loads, and that `media/` is writable. All
   green means the upgrade landed cleanly; a missing file is named there so
   you can re-extract. A red banner appears at the top of the dashboard if
   anything failed. Then load the public blog (a listing page and a single
   post) to confirm the front end renders. The admin also refuses to load
   if it was downgraded below the version that last wrote your config,
   naming the version it expects.

6. **Remove the admin folder** (and `install.php`, if it reappeared) when
   you have finished editing, the same as after first install.

If something is wrong the cause is written to your server's error log;
restoring the backup from step 1 reverts cleanly.

---

## Version-specific notes

### 1.4.0

Admin redesign and web installer. No data migration.

- The admin has a new look (shared sticky header, stat cards, restyled
  forms and tables). Nothing about your posts, media, or `config.json`
  changes; it is a front-of-house change to the admin only.
- A new `install.php` web installer is the primary first-install path (see
  [INSTALL.md](INSTALL.md)). It does not affect existing installs - it
  refuses to run once `bootstrap.php` exists.

---

## When a release changes the on-disk format

If a future release bumps `format_version` in `config.json`, the release
notes will spell out exactly what changes in your files. The flat-file
design keeps migrations small, usually one of:

- A one-time `migrate.php` script you run once to rewrite affected files, or
- A field rename or addition you can do by hand on a small set of posts.

Major format changes are deliberately rare. The contracts in
[FORMAT.md](FORMAT.md) are designed to be stable.
