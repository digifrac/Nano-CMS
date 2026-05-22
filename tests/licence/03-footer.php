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
// HTTP_HOST is deliberately set to a known *attacker-shaped* value
// (localhost). The footer renderer must ignore it and verify against
// the canonical host derived from config.json's base_url - so changing
// HTTP_HOST across tests would muddy the signal. Pinning it once lets
// each test express its intent purely through base_url + licence_key.
file_put_contents($runner_php, '<?php
define("NANO_BOOTSTRAPPED", true);
define("NANO_CONFIG_PATH", $argv[1]);
define("NANO_CONTENT_PATH", dirname($argv[1]));
$_SERVER["HTTP_HOST"] = "localhost"; // attacker-shaped; must be ignored
require $argv[2];
require $argv[3];
echo nano_render_licence_footer();
');

$fetch = function (string $base_url, string $licence_key) use ($cfg_path, $runner_php, $repo): string {
    file_put_contents($cfg_path, json_encode([
        'base_url'    => $base_url,
        'licence_key' => $licence_key,
    ]));
    return shell_exec(sprintf(
        'php %s %s %s %s',
        escapeshellarg($runner_php),
        escapeshellarg($cfg_path),
        escapeshellarg($repo . '/core.php'),
        escapeshellarg($repo . '/licence.php')
    )) ?? '';
};

nano_section('dev-host bypass via base_url: footer must be empty');
foreach ([
    'http://localhost/blog',
    'http://127.0.0.1/blog',
    'https://mysite.test/blog',
    'https://mac.local/blog',
    'http://example.com:8000/blog',
] as $dev_base) {
    $out = $fetch($dev_base, '');
    nano_check("$dev_base -> empty", $out === '', 'got: ' . substr($out, 0, 80));
}

nano_section('Host-header spoof must NOT bypass on a production base_url');
// HTTP_HOST is pinned to "localhost" by the runner. Pre-fix this would
// have suppressed the footer; post-fix the canonical host (example.com)
// is what gets checked, so the footer must still appear.
$out = $fetch('https://example.com/blog', '');
nano_check('Host: localhost cannot suppress footer on production base_url',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('production base_url, no licence: footer must appear');
$out = $fetch('https://example.com/blog', '');
nano_check('empty licence -> footer present',
    strpos($out, 'Powered by') !== false && strpos($out, 'nano-blog-footer') !== false,
    'got: ' . substr($out, 0, 80));

nano_section('production base_url, valid licence: footer must be suppressed');
$out = $fetch('https://example.com/blog', NANO_FX_SINGLE_EXAMPLE_COM);
nano_check('valid licence for this domain -> empty', $out === '', 'got: ' . substr($out, 0, 80));

$out = $fetch('https://www.example.com/blog', NANO_FX_SINGLE_EXAMPLE_COM);
nano_check('valid licence on www. of same domain -> empty', $out === '', 'got: ' . substr($out, 0, 80));

nano_section('production base_url, licence for DIFFERENT domain: footer reappears');
$out = $fetch('https://other.com/blog', NANO_FX_SINGLE_EXAMPLE_COM);
nano_check('wrong-domain licence -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('wildcard agency-unlimited: any production base_url -> empty');
foreach (['https://example.com/blog', 'https://other.com/blog', 'https://blog.acme.io/'] as $b) {
    $out = $fetch($b, NANO_FX_AGENCY_UNLIMITED_WILDCARD);
    nano_check("$b -> empty (wildcard)", $out === '', 'got: ' . substr($out, 0, 80));
}

nano_section('wildcard with non-unlimited tier: rejected, footer present');
$out = $fetch('https://example.com/blog', NANO_FX_WILDCARD_BAD_TIER);
nano_check('wildcard+single tier -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('expired licence: footer present');
$out = $fetch('https://example.com/blog', NANO_FX_EXPIRED);
nano_check('expired -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('malformed licence on production base_url: footer present');
$out = $fetch('https://example.com/blog', 'not-a-licence-at-all');
nano_check('malformed -> footer present',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('missing base_url: fail-safe to show footer');
$out = $fetch('', '');
nano_check('empty base_url -> footer present (fail-safe)',
    strpos($out, 'Powered by') !== false, 'got: ' . substr($out, 0, 80));

nano_section('footer HTML shape');
$out = $fetch('https://example.com/blog', '');
nano_check('contains nano-blog-footer class',     strpos($out, 'class="nano-blog-footer"') !== false);
nano_check('links to nanocms.co.uk',              strpos($out, 'href="https://nanocms.co.uk/"') !== false);
nano_check('links to digitalfracture.co.uk',      strpos($out, 'href="https://digitalfracture.co.uk/"') !== false);
nano_check('rel="noopener noreferrer" on outbound links',
    substr_count($out, 'rel="noopener noreferrer"') === 2);

/* Cleanup */
foreach (glob($tmp . '/*') as $f) @unlink($f);
@rmdir($tmp);
