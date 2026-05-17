<?php
/**
 * Tests for the public footer renderer in licence.php.
 *
 * Each scenario spawns a subprocess because nano_config()'s static
 * cache, the HTTP_HOST superglobal, and the config file all need to
 * be in their expected state per-test.
 */
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/fixtures.php';

$repo = dirname(__DIR__, 2);
$tmp  = sys_get_temp_dir() . '/nano-cms-tests-footer-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$cfg_path   = $tmp . '/config.json';
$runner_php = $tmp . '/runner.php';
file_put_contents($runner_php, '<?php
define("NANO_BOOTSTRAPPED", true);
define("NANO_CONFIG_PATH", $argv[1]);
define("NANO_CONTENT_PATH", dirname($argv[1]));
$_SERVER["HTTP_HOST"] = $argv[2];
require $argv[3];
require $argv[4];
echo nano_render_licence_footer();
');

$fetch = function (string $host, string $licence_key) use ($cfg_path, $runner_php, $repo): string {
    file_put_contents($cfg_path, json_encode([
        'base_url'    => 'https://example.com/blog',
        'licence_key' => $licence_key,
    ]));
    return shell_exec(sprintf(
        'php %s %s %s %s %s',
        escapeshellarg($runner_php),
        escapeshellarg($cfg_path),
        escapeshellarg($host),
        escapeshellarg($repo . '/core.php'),
        escapeshellarg($repo . '/licence.php')
    )) ?? '';
};

nano_section('dev-host bypass: footer must be empty');
foreach (['localhost', '127.0.0.1', 'mysite.test', 'mac.local', 'example.com:8000'] as $dev) {
    $out = $fetch($dev, '');
    nano_check("$dev -> empty", $out === '', 'got: ' . substr($out, 0, 80));
}

nano_section('production host, no licence: footer must appear');
$out = $fetch('example.com', '');
nano_check('empty licence -> footer present',
    strpos($out, 'Powered by') !== false && strpos($out, 'nano-blog-footer') !== false,
    'got: ' . substr($out, 0, 80));

nano_section('production host, valid licence: footer must be suppressed');
$out = $fetch('example.com', NANO_FX_SINGLE_EXAMPLE_COM);
nano_check('valid licence for this domain -> empty', $out === '', 'got: ' . substr($out, 0, 80));

$out = $fetch('www.example.com', NANO_FX_SINGLE_EXAMPLE_COM);
nano_check('valid licence on www. of same domain -> empty', $out === '', 'got: ' . substr($out, 0, 80));

nano_section('production host, licence for DIFFERENT domain: footer reappears');
$out = $fetch('other.com', NANO_FX_SINGLE_EXAMPLE_COM);
nano_check('wrong-domain licence -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('wildcard agency-unlimited: any production host -> empty');
foreach (['example.com', 'other.com', 'blog.acme.io'] as $h) {
    $out = $fetch($h, NANO_FX_AGENCY_UNLIMITED_WILDCARD);
    nano_check("$h -> empty (wildcard)", $out === '', 'got: ' . substr($out, 0, 80));
}

nano_section('wildcard with non-unlimited tier: rejected, footer present');
$out = $fetch('example.com', NANO_FX_WILDCARD_BAD_TIER);
nano_check('wildcard+single tier -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('expired licence: footer present');
$out = $fetch('example.com', NANO_FX_EXPIRED);
nano_check('expired -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('malformed licence on production host: footer present');
$out = $fetch('example.com', 'not-a-licence-at-all');
nano_check('malformed -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('footer HTML shape');
$out = $fetch('example.com', '');
nano_check('contains nano-blog-footer class',     strpos($out, 'class="nano-blog-footer"') !== false);
nano_check('links to nanocms.co.uk',              strpos($out, 'href="https://nanocms.co.uk/"') !== false);
nano_check('links to digitalfracture.co.uk',      strpos($out, 'href="https://digitalfracture.co.uk/"') !== false);
nano_check('rel="noopener" on outbound links',    substr_count($out, 'rel="noopener"') === 2);

/* Cleanup */
foreach (glob($tmp . '/*') as $f) @unlink($f);
@rmdir($tmp);
