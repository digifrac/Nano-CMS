<?php
/**
 * Test fixtures for licence verification.
 *
 * All licence strings below were signed with the production Digital
 * Fracture private key. The first three are unremarkable licences
 * produced by `nano-licence-tools/generate.php`; the last two were
 * synthesised directly because the toolkit's input validation refuses
 * to emit them (wildcard domain with non-unlimited tier; past expiry).
 * Both belong in the test suite because the *verifier* is the
 * defence-in-depth check - a future key compromise or buggy generator
 * shouldn't open a hole.
 */

/* Tier=single, domain=example.com */
const NANO_FX_SINGLE_EXAMPLE_COM =
    'eyJwcm9kdWN0IjoibmFuby1jbXMiLCJkb21haW4iOiJleGFtcGxlLmNvbSIsInRpZXIiOiJzaW5nbGUiLCJsaWNlbmNlX2lkIjoiNmMxNmU5ZjctMjJjYy00NDlhLTkwYjAtNTQ2MjllOWM5YmJjIiwiaXNzdWVkIjoiMjAyNi0wNS0xNyIsImV4cGlyZXMiOm51bGwsImtleV92ZXJzaW9uIjoxfQ==.MTTjCYyXTrzObXZiWOHWJwecOgJK6TZVyRRFRhIR8CDSQ+llN1OrTCUWVUeLyoXODVR4UdKaQE3iKLnBiILPDg==';

/* Tier=agency-unlimited, domain=* (wildcard) */
const NANO_FX_AGENCY_UNLIMITED_WILDCARD =
    'eyJwcm9kdWN0IjoibmFuby1jbXMiLCJkb21haW4iOiIqIiwidGllciI6ImFnZW5jeS11bmxpbWl0ZWQiLCJsaWNlbmNlX2lkIjoiZWZmMjI0OTYtMWI5Yi00OWY2LWFlNTYtNDNmNzdlMzk4ODNkIiwiaXNzdWVkIjoiMjAyNi0wNS0xNyIsImV4cGlyZXMiOm51bGwsImtleV92ZXJzaW9uIjoxfQ==.9/Q5MrWSp6pqmdujOn5hxuufneHvZy8E12kCPPT5qots1iIwn3qTjNKtdpnv4yxKYIRcyH0tzyMonYIO1HA2Cw==';

/* Wrong product (nano-cart on a nano-cms install) */
const NANO_FX_WRONG_PRODUCT_CART =
    'eyJwcm9kdWN0IjoibmFuby1jYXJ0IiwiZG9tYWluIjoiZXhhbXBsZS5jb20iLCJ0aWVyIjoic2luZ2xlIiwibGljZW5jZV9pZCI6ImJiNzI0MDA2LWVmNjUtNDQwMS05YWEwLTk2OGNjZGM0ZGEwZSIsImlzc3VlZCI6IjIwMjYtMDUtMTciLCJleHBpcmVzIjpudWxsLCJrZXlfdmVyc2lvbiI6MX0=.pa1++atrmAwzabWAI5XnwBDvHiLKzAyahSOZmYyHzPI+Y49uYHm08fdtFlV5IkoW3n0RvcHDGVFkt6iMn3WxAQ==';

/* Synthesised: wildcard "*" paired with tier=single - the verifier's
 * wildcard gate must reject this even though the signature is genuine. */
const NANO_FX_WILDCARD_BAD_TIER =
    'eyJwcm9kdWN0IjoibmFuby1jbXMiLCJkb21haW4iOiIqIiwidGllciI6InNpbmdsZSIsImxpY2VuY2VfaWQiOiIwMDAwMDAwMC0wMDAwLTQwMDAtODAwMC0wMDAwMDAwMDAwMDEiLCJpc3N1ZWQiOiIyMDI2LTA1LTE3IiwiZXhwaXJlcyI6bnVsbCwia2V5X3ZlcnNpb24iOjF9.ZxCbgZdW9mcJCP3IUM0NdG+lrfTgabCCfUKU0nnphez7jSUckSOVxJFFJelEkSUevEcO7wA0x+a4hw3KuMonDQ==';

/* Synthesised: expired (expires=2020-12-31) but otherwise valid. */
const NANO_FX_EXPIRED =
    'eyJwcm9kdWN0IjoibmFuby1jbXMiLCJkb21haW4iOiJleGFtcGxlLmNvbSIsInRpZXIiOiJzaW5nbGUiLCJsaWNlbmNlX2lkIjoiMDAwMDAwMDAtMDAwMC00MDAwLTgwMDAtMDAwMDAwMDAwMDAyIiwiaXNzdWVkIjoiMjAyMC0wMS0wMSIsImV4cGlyZXMiOiIyMDIwLTEyLTMxIiwia2V5X3ZlcnNpb24iOjF9.L3LtoPEMIxQooNj/wQ2OxQHKFaD5k3PdmKJ7vuxLUGlzKuiULGGVHtc3q8IIacL7BMaV9eV40SUyL+s4miSpAQ==';
