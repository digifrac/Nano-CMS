<?php
/**
 * Tests for the v1.3.x config schema:
 *   - NANO_ADMIN_VERSION is the current version constant
 *   - Saving and reloading config preserves the new licence_key field
 *   - nano_admin_version_check refuses when last_used > current
 *   - Allows an older config (clean upgrade path)
 */
declare(strict_types=1);

$repo       = dirname(__DIR__, 2);
$tmp        = sys_get_temp_dir() . '/nano-cms-tests-config-' . bin2hex(random_bytes(4));
$cfg_path   = $tmp . '/config.json';
$rate_path  = $tmp . '/rate-limit.json';
mkdir($tmp, 0700, true);

if (!defined('NANO_BOOTSTRAPPED'))     define('NANO_BOOTSTRAPPED', true);
if (!defined('NANO_CONFIG_PATH'))      define('NANO_CONFIG_PATH', $cfg_path);
if (!defined('NANO_RATE_LIMIT_PATH'))  define('NANO_RATE_LIMIT_PATH', $rate_path);

require_once __DIR__ . '/_helpers.php';
require_once $repo . '/admin/core.php';

nano_section('admin version constant');
nano_check('NANO_ADMIN_VERSION === ' . NANO_ADMIN_VERSION, is_string(NANO_ADMIN_VERSION) && preg_match('/^\d+\.\d+\.\d+$/', NANO_ADMIN_VERSION) === 1);

nano_section('config round-trip with licence_key field');
$initial = [
    'format_version'  => '1.3',
    'site_name'       => 'Test Site',
    'base_url'        => 'https://example.com/blog',
    'author'          => 'Tester',
    'publisher_name'  => 'Test',
    'publisher_logo'  => '',
    'posts_per_page'  => 10,
    'licence_key'     => '',
    'password_hash'   => '$2y$10$dummyhashforsmoketestingonlyxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'created'         => gmdate('Y-m-d\TH:i:s\Z'),
];
nano_admin_save_config($initial);
$read = json_decode(file_get_contents($cfg_path), true);
nano_check('config persisted as valid JSON',           is_array($read));
nano_check('format_version is 1.3',                    ($read['format_version'] ?? null) === '1.3');
nano_check('licence_key field present',                array_key_exists('licence_key', $read));
nano_check('licence_key defaults to empty string',     ($read['licence_key'] ?? null) === '');
nano_check('admin_version_last_used bumped to ' . NANO_ADMIN_VERSION,
    ($read['admin_version_last_used'] ?? null) === NANO_ADMIN_VERSION);

nano_section('version compatibility check (subprocess)');
$child = '<?php
define("NANO_BOOTSTRAPPED", true);
define("NANO_CONFIG_PATH", $argv[1]);
define("NANO_RATE_LIMIT_PATH", $argv[2]);
require $argv[3];
$cfg = json_decode(file_get_contents($argv[1]), true);
$cfg["admin_version_last_used"] = $argv[4];
file_put_contents($argv[1], json_encode($cfg));
nano_admin_version_check();
echo "NO_REFUSAL\n";
';
$script = $tmp . '/_check.php';
file_put_contents($script, $child);
$cmd = function (string $last_used) use ($script, $cfg_path, $rate_path, $repo): string {
    return shell_exec(sprintf(
        'php %s %s %s %s %s',
        escapeshellarg($script),
        escapeshellarg($cfg_path),
        escapeshellarg($rate_path),
        escapeshellarg($repo . '/admin/core.php'),
        escapeshellarg($last_used)
    )) ?? '';
};

$out = $cmd('9.0.0');
nano_check('refuses when last_used > current (9.0.0 vs ' . NANO_ADMIN_VERSION . ')',
    strpos($out, 'NO_REFUSAL') === false
    && strpos($out, 'last edited with admin version 9.0.0') !== false);

$out2 = $cmd('1.2.0');
nano_check('allows upgrade from older config (1.2.0 -> ' . NANO_ADMIN_VERSION . ')',
    strpos($out2, 'NO_REFUSAL') !== false);

/* Cleanup */
foreach (glob($tmp . '/*') as $f) @unlink($f);
@rmdir($tmp);
