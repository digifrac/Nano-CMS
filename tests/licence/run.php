<?php
/**
 * Top-level runner for the licence test suite.
 *
 *   php tests/licence/run.php
 *
 * No PHPUnit, no Composer - matches the rest of the codebase's
 * no-dependency philosophy. Each 0N-*.php file is included in order
 * and contributes to a shared pass/fail tally.
 */
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$start = microtime(true);

foreach (['01-verify.php', '02-config.php', '03-footer.php', '04-admin.php', '05-admin-render.php'] as $f) {
    echo "\n==================================================\n";
    echo "  $f\n";
    echo "==================================================\n";
    require __DIR__ . '/' . $f;
}

$elapsed = number_format((microtime(true) - $start) * 1000, 1);

echo "\n==================================================\n";
echo "  TOTAL\n";
echo "==================================================\n";
echo "  PASSED: {$GLOBALS['nano_test_passes']}\n";
echo "  FAILED: {$GLOBALS['nano_test_fails']}\n";
echo "  TIME:   {$elapsed}ms\n";

if ($GLOBALS['nano_test_fails'] > 0) {
    echo "\nFAILURES:\n";
    foreach ($GLOBALS['nano_test_failures'] as $msg) {
        echo "  - $msg\n";
    }
    exit(1);
}
echo "  OK\n";
exit(0);
