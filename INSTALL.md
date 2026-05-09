# Nano CMS - Installation

Deploys to any shared PHP host with HTTPS. Two ZIPs, ten minutes.

---

## 1. Prerequisites

The host must have:

- **PHP 8.0 or newer** (we use match expressions and named-arg helpers)
- **HTTPS** with a valid certificate (the admin refuses HTTP, no localhost exemption)
- **GD** *or* **Imagick** PHP extension (uploads decode + re-encode through one of them)
- **fileinfo** PHP extension (MIME validation on uploads)
- **mod_rewrite** (Apache / LiteSpeed) for clean URLs
- **`AllowOverride All`** so the shipped `.htaccess` files take effect
- A directory **outside the webroot** that PHP can write to (for `config.json` and `rate-limit.json`)

If you're not sure the host meets these, upload `nano-preflight.php` to the
target webroot, load it once over HTTPS, and read the green/red table.
Delete the file when you're done - it leaks host paths.

---

## 2. Build the two release ZIPs

From the repo root, on your machine:

```sh
git archive --format=zip --prefix=blog/ -o nano-cms-frontend.zip HEAD \
  ":(exclude)admin" ":(exclude)nano-preflight.php" \
  ":(exclude)posts/example.md" ":(exclude).gitignore"

git archive --format=zip --prefix=admin/ -o nano-cms-admin.zip HEAD:admin
```

PowerShell users: replace the trailing `\` with backticks (`` ` ``).

You now have two ZIPs:

- **`nano-cms-frontend.zip`** - the permanent half. Stays on the client site
  forever. Wrapper folder is `blog/`.
- **`nano-cms-admin.zip`** - the portable half. Uploaded only when publishing,
  deleted after every session. Wrapper folder is `admin/`.

The two share only the on-disk file format, never any PHP code.

---

## 3. First-time deployment

Adjust paths to match your host. The examples below assume:

- webroot: `/home/clientuser/example.com/`
- blog URL: `https://example.com/blog/`
- config dir (outside webroot): `/home/clientuser/blog-config/`

### 3a. Upload the frontend

Upload `nano-cms-frontend.zip` into `/home/clientuser/example.com/` and
extract in place. You should get `/home/clientuser/example.com/blog/...`
with `index.php`, `post.php`, `lib/`, `assets/`, `posts/`, `media/`, etc.

Delete the ZIP.

### 3b. Create the config directory outside webroot

```sh
mkdir /home/clientuser/blog-config
chmod 750 /home/clientuser/blog-config
```

Confirm the web user can write to it. On most cPanel hosts the same user
that owns the home directory runs PHP, so this is automatic.

### 3c. Configure `bootstrap.php`

Inside `/home/clientuser/example.com/blog/`, copy `bootstrap.example.php`
to `bootstrap.php` and **replace the entire file contents** with exactly
five lines:

```php
<?php
define('NANO_BOOTSTRAPPED', true);
define('NANO_CONFIG_PATH', '/home/clientuser/blog-config/config.json');
define('NANO_RATE_LIMIT_PATH', '/home/clientuser/blog-config/rate-limit.json');
define('NANO_CONTENT_PATH', __DIR__);
```

No docblock, no closing `?>`, no extra `<?php` tags. Just those five lines.

### 3d. Check the `.htaccess` install path

Open `/home/clientuser/example.com/blog/.htaccess`. The `RewriteBase` line
is `/blog/` by default - matching the URL the blog will live at. Change
it only if you're installing at a different path (e.g. `/news/`, `/`).

### 3e. Upload the admin

Upload `nano-cms-admin.zip` into `/home/clientuser/example.com/blog/` and
extract. You should get `/home/clientuser/example.com/blog/admin/...`.

Delete the ZIP.

### 3f. Run the setup wizard

Visit `https://example.com/blog/admin/setup.php` over HTTPS.

Fill in:

- **Password** - 12 characters minimum
- **Site name** - whatever you like
- **Base URL** - `https://example.com/blog`
- **Author name** - your name (or the site name; it's free-form)
- **Publisher name** - your name or business
- **Publisher logo URL** - optional
- **Posts per page** - `10` is sensible

Submit. You'll land on a "Setup complete" page that prominently tells you
to delete `setup.php` from the server. **Do that now.** The file's job is
done; leaving it in place adds an unused URL to the attack surface.

### 3g. Sign in

`https://example.com/blog/admin/` - log in with the password you just set.

---

## 4. Subsequent publishing sessions

The admin folder is meant to live on the server only while you're
publishing. Workflow:

1. Re-upload `nano-cms-admin.zip` to `/home/clientuser/example.com/blog/`,
   extract → recreates the `admin/` folder.
2. Sign in (your password and rate-limit state survived in `blog-config/`).
3. Edit, publish, upload media.
4. **SFTP-delete** the entire `/blog/admin/` folder. The frontend keeps
   rendering the same posts. Bots scanning for admin paths can't find one
   because there isn't one.

The same `nano-cms-admin.zip` works on every Nano CMS site you ship -
it's universal.

---

## 5. Backups

The CMS *is* the filesystem. Back up:

- `/blog/posts/` - your Markdown post files
- `/blog/media/` - your uploaded images
- `/home/clientuser/blog-config/config.json` - site settings + password hash

`rsync -a` or any file-level backup tool covers everything.

---

## 6. Troubleshooting

**HTTP 500 on `setup.php`** - PHP died. Likely causes: typo in
`bootstrap.php`, second `<?php` tag, or a missing `require`. Temporarily
add these two lines after the opening `<?php` of `setup.php` to see the
error in the browser:

```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```

Reload, read the error, fix it, **remove the lines**.

**"Nano CMS admin requires HTTPS"** - the host isn't sending HTTPS
indicators. Either the request really is HTTP (fix the URL), or the host
is behind a proxy that doesn't set `HTTP_X_FORWARDED_PROTO`. Talk to the
host.

**"directory does not exist"** on setup submit - the path in
`NANO_CONFIG_PATH` points at a directory that doesn't exist. Create it.

**Uploads return "Could not re-encode"** - GD or Imagick is missing on
the host. Run the pre-flight check; install whichever your host control
panel offers.

**Clean URLs return 404** - mod_rewrite is off, or `AllowOverride` is
restricted. Talk to the host.

**Admin loads but posts don't appear on the public site** - check
`bootstrap.php`'s `NANO_CONTENT_PATH` actually points at the directory
containing `posts/`. The default `__DIR__` is right unless you moved files
around.
