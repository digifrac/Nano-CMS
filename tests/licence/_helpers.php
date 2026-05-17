<?php
/**
 * Shared assertion helper for the licence test suite.
 *
 * Tracks per-section pass/fail counts in $GLOBALS so the runner can
 * roll them up without each test file needing its own scaffolding.
 */

if (!isset($GLOBALS['nano_test_passes'])) {
    $GLOBALS['nano_test_passes'] = 0;
    $GLOBALS['nano_test_fails']  = 0;
    $GLOBALS['nano_test_failures'] = [];
}

function nano_check(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['nano_test_passes']++;
        echo "  PASS  $name\n";
    } else {
        $GLOBALS['nano_test_fails']++;
        $GLOBALS['nano_test_failures'][] = $name . ($detail !== '' ? "  ($detail)" : '');
        echo "  FAIL  $name" . ($detail !== '' ? "  $detail" : '') . "\n";
    }
}

function nano_section(string $title): void
{
    echo "\n[$title]\n";
}
