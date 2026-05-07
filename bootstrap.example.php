<?php
/**
 * Per-site bootstrap. Copy to bootstrap.php on each deployment and edit
 * the paths below to match the host.
 *
 * bootstrap.php itself is gitignored so that per-deployment paths and
 * any local secrets never enter version control.
 *
 * Three constants must be defined here:
 *
 *   NANO_BOOTSTRAPPED    - sentinel that lets core.php / library files
 *                          know they were loaded through the proper
 *                          entry point and not fetched directly.
 *
 *   NANO_CONFIG_PATH     - absolute path to config.json. MUST live
 *                          OUTSIDE the webroot. Contains password hash
 *                          and site settings.
 *
 *   NANO_RATE_LIMIT_PATH - absolute path to rate-limit.json. MUST live
 *                          OUTSIDE the webroot. Tracks failed login
 *                          attempts; conventionally lives next to
 *                          config.json.
 *
 *   NANO_CONTENT_PATH    - absolute path to the directory containing
 *                          /posts/ and /media/. Almost always the
 *                          directory where bootstrap.php itself lives.
 */

define('NANO_BOOTSTRAPPED', true);

define('NANO_CONFIG_PATH', '/home/clientuser/blog-config/config.json');
define('NANO_RATE_LIMIT_PATH', '/home/clientuser/blog-config/rate-limit.json');
define('NANO_CONTENT_PATH', __DIR__);
