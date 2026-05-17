<?php
/**
 * Tests for the verification primitives in licence.php:
 *   - nano_is_dev_host
 *   - nano_verify_licence (bool wrapper)
 *   - nano_licence_inspect (structured result)
 */
declare(strict_types=1);

if (!defined('NANO_BOOTSTRAPPED')) {
    define('NANO_BOOTSTRAPPED', true);
}
$repo = dirname(__DIR__, 2);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/fixtures.php';
require_once $repo . '/licence.php';

/* ------------------------------------------------------------------ */
nano_section('nano_is_dev_host');
nano_check('localhost is dev',                nano_is_dev_host('localhost'));
nano_check('127.0.0.1 is dev',                nano_is_dev_host('127.0.0.1'));
nano_check('::1 is dev',                      nano_is_dev_host('::1'));
nano_check('[::1] is dev',                    nano_is_dev_host('[::1]'));
nano_check('host with port is dev',           nano_is_dev_host('example.com:8000'));
nano_check('localhost:8000 is dev',           nano_is_dev_host('localhost:8000'));
nano_check('.test suffix is dev',             nano_is_dev_host('myproject.test'));
nano_check('.local suffix is dev',            nano_is_dev_host('macbook.local'));
nano_check('empty host treated as dev',       nano_is_dev_host(''));
nano_check('example.com is NOT dev',          !nano_is_dev_host('example.com'));
nano_check('www.example.com is NOT dev',      !nano_is_dev_host('www.example.com'));
nano_check('subdomain prod is NOT dev',       !nano_is_dev_host('blog.example.com'));
nano_check('.testbed is NOT dev',             !nano_is_dev_host('example.testbed'));
nano_check('.localdomain is NOT dev',         !nano_is_dev_host('example.localdomain'));

/* ------------------------------------------------------------------ */
nano_section('nano_verify_licence - malformed input');
nano_check('empty key rejected',              !nano_verify_licence('', 'example.com'));
nano_check('whitespace key rejected',         !nano_verify_licence('   ', 'example.com'));
nano_check('no dot separator rejected',       !nano_verify_licence('justonepart', 'example.com'));
nano_check('two dots rejected',               !nano_verify_licence('a.b.c', 'example.com'));
nano_check('bad base64 rejected',             !nano_verify_licence('!!!.!!!', 'example.com'));
nano_check('right shape, garbage bytes',      !nano_verify_licence('YWJj.YWJj', 'example.com'));

/* ------------------------------------------------------------------ */
nano_section('nano_verify_licence - real licences');
nano_check('single licence on its domain',          nano_verify_licence(NANO_FX_SINGLE_EXAMPLE_COM, 'example.com'));
nano_check('single licence on www.<domain>',        nano_verify_licence(NANO_FX_SINGLE_EXAMPLE_COM, 'www.example.com'));
nano_check('single licence on wrong domain',        !nano_verify_licence(NANO_FX_SINGLE_EXAMPLE_COM, 'other.com'));
nano_check('single licence case-insensitive host',  nano_verify_licence(NANO_FX_SINGLE_EXAMPLE_COM, 'EXAMPLE.COM'));
nano_check('unlimited wildcard on any host A',      nano_verify_licence(NANO_FX_AGENCY_UNLIMITED_WILDCARD, 'example.com'));
nano_check('unlimited wildcard on any host B',      nano_verify_licence(NANO_FX_AGENCY_UNLIMITED_WILDCARD, 'unrelated.org'));
nano_check('nano-cart licence rejected on nano-cms',!nano_verify_licence(NANO_FX_WRONG_PRODUCT_CART, 'example.com'));

/* Tamper resistance */
$tampered_payload = preg_replace('/^./', 'X', NANO_FX_SINGLE_EXAMPLE_COM);
nano_check('tampered payload rejected',             !nano_verify_licence($tampered_payload, 'example.com'));

[$p, $s] = explode('.', NANO_FX_SINGLE_EXAMPLE_COM, 2);
$tampered_sig = $p . '.' . preg_replace('/^./', 'X', $s);
nano_check('tampered signature rejected',           !nano_verify_licence($tampered_sig, 'example.com'));

/* Wildcard gate: a wildcard "*" combined with any tier other than
 * agency-unlimited must be rejected even with a genuine signature. */
nano_check('wildcard with single tier rejected',    !nano_verify_licence(NANO_FX_WILDCARD_BAD_TIER, 'example.com'));
nano_check('wildcard with single tier rejected B',  !nano_verify_licence(NANO_FX_WILDCARD_BAD_TIER, 'random.io'));

/* Expiry */
nano_check('expired licence rejected',              !nano_verify_licence(NANO_FX_EXPIRED, 'example.com'));

/* ------------------------------------------------------------------ */
nano_section('nano_licence_inspect - reason surface');
$r = nano_licence_inspect('', 'example.com');
nano_check('empty -> reason mentions no licence',
    isset($r['reason']) && stripos($r['reason'], 'no licence') !== false);

$r = nano_licence_inspect(NANO_FX_SINGLE_EXAMPLE_COM, 'other.com');
nano_check('wrong-domain reason names both domains',
    isset($r['reason'])
    && strpos($r['reason'], 'example.com') !== false
    && strpos($r['reason'], 'other.com') !== false);

$r = nano_licence_inspect(NANO_FX_WRONG_PRODUCT_CART, 'example.com');
nano_check('wrong-product reason names the product',
    isset($r['reason']) && strpos($r['reason'], 'nano-cart') !== false);

$r = nano_licence_inspect(NANO_FX_EXPIRED, 'example.com');
nano_check('expired reason mentions expiry date',
    isset($r['reason']) && strpos($r['reason'], '2020-12-31') !== false);

$r = nano_licence_inspect(NANO_FX_WILDCARD_BAD_TIER, 'example.com');
nano_check('wildcard-bad-tier reason mentions licence domain',
    isset($r['reason']) && strpos($r['reason'], '*') !== false);

$r = nano_licence_inspect(NANO_FX_SINGLE_EXAMPLE_COM, 'example.com');
nano_check('valid -> ok=true, no reason, payload populated',
    $r['ok'] === true && $r['reason'] === null
    && is_array($r['payload']) && ($r['payload']['tier'] ?? null) === 'single');
