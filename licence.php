<?php
/**
 * Nano CMS - licence verification.
 *
 * Library file. Loaded by index.php / post.php (after core.php) and by
 * admin/licence.php. Verifies customer licence keys against the
 * embedded Digital Fracture public key using Ed25519.
 *
 * No network calls. No phone-home. All verification is local. See the
 * private nano-licence-tools repo for the generator and signing key.
 */

if (!defined('NANO_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/**
 * Digital Fracture master public key (Ed25519, base64-encoded).
 *
 * Safe to ship in MIT-licensed code: this is the verification half of
 * the keypair. Only the matching private key (held offline in the
 * private nano-licence-tools toolkit) can mint a valid licence.
 *
 * The `_V1` suffix leaves room for rotation if the private key is ever
 * compromised: a future release would add NANO_LICENCE_PUBKEY_V2,
 * accept either during a transition window, then drop V1.
 */
const NANO_LICENCE_PUBKEY_V1 = 'OW0ZWPowsYFF4Hv49r8Kc8OcM31COddoOk5j1UVCWfY=';

/* ------------------------------------------------------------------------ */
/* Canonical host (from base_url)                                            */
/* ------------------------------------------------------------------------ */

/**
 * Return the host the licence is bound to, derived from `base_url` in
 * config.json. Never reads `$_SERVER['HTTP_HOST']` - the request header
 * is attacker-controlled and was previously a Host-spoof / cache-poison
 * vector for the licence check.
 *
 * Returns '' on any failure (config missing, base_url unset, malformed).
 * Callers treat '' as a hard "do not verify" signal.
 */
function nano_licence_canonical_host(): string
{
    try {
        $base = (string)(nano_config()['base_url'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
    if ($base === '') {
        return '';
    }
    $parts = parse_url($base);
    $host = $parts['host'] ?? null;
    if (!is_string($host) || $host === '') {
        return '';
    }
    $host = strtolower($host);
    // Preserve a non-default port (8000, 8443, ...) so `nano_is_dev_host()`
    // can still see the port-in-host signal that base_urls like
    // `http://example.com:8000/blog` carry. parse_url splits port off into
    // its own key, so without this we'd lose the dev-shape marker.
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $default_port = $scheme === 'http' ? 80 : ($scheme === 'https' ? 443 : 0);
    if ($port > 0 && $port !== $default_port) {
        return $host . ':' . $port;
    }
    return $host;
}

/* ------------------------------------------------------------------------ */
/* Dev-host detection                                                        */
/* ------------------------------------------------------------------------ */

/**
 * Return true for hosts that should bypass the licence check entirely.
 * Lets developers preview locally and run on `.test`/`.local` zones
 * without seeing the "Powered by Nano CMS" footer.
 *
 *   - localhost / 127.0.0.1 / ::1 (exact, with or without brackets)
 *   - any host containing a port (a colon in HTTP_HOST)
 *   - *.test       (RFC 6761 reserved for testing)
 *   - *.local      (mDNS reserved local domain)
 */
function nano_is_dev_host(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return true;
    }
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '[::1]') {
        return true;
    }
    // Production HTTP_HOST does not include the default port for the
    // scheme (80/443), so a colon means either a non-default port or
    // an IPv6 host. Both are dev-shaped for our purposes.
    if (strpos($host, ':') !== false) {
        return true;
    }
    foreach (['.test', '.local'] as $suffix) {
        if (substr($host, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------------ */
/* Licence verification                                                      */
/* ------------------------------------------------------------------------ */

/**
 * Verify a licence key against the current host. Returns true iff:
 *   - the key parses cleanly (well-formed `base64.base64`)
 *   - the Ed25519 signature matches the embedded public key
 *   - `product` is `nano-cms`
 *   - `domain` matches the host (with `www.` stripped) OR is `*` for an
 *     `agency-unlimited` tier
 *   - the licence has not expired
 *
 * Never throws, never logs, never echoes. The caller decides what to
 * do with `false`. See nano_licence_inspect() for a structured result
 * with a human-readable reason (used by the admin status display).
 */
function nano_verify_licence(string $licence_key, string $current_host): bool
{
    return nano_licence_inspect($licence_key, $current_host)['ok'];
}

/**
 * Detailed verification result for the admin Licence page.
 *
 * Returns `['ok' => bool, 'reason' => ?string, 'payload' => ?array]`.
 * `payload` is populated whenever the licence parses, even if a later
 * check fails - lets the admin show "your licence covers X, this site
 * runs on Y" without re-decoding the key on the caller side.
 */
function nano_licence_inspect(string $licence_key, string $current_host): array
{
    $licence_key = trim($licence_key);
    if ($licence_key === '') {
        return ['ok' => false, 'reason' => 'No licence key set.', 'payload' => null];
    }
    if (substr_count($licence_key, '.') !== 1) {
        return ['ok' => false, 'reason' => 'Malformed licence key (expected base64.base64).', 'payload' => null];
    }

    [$payload_b64, $signature_b64] = explode('.', $licence_key, 2);
    $payload_json = base64_decode($payload_b64, true);
    $signature    = base64_decode($signature_b64, true);
    if ($payload_json === false || $signature === false) {
        return ['ok' => false, 'reason' => 'Licence key contains invalid base64.', 'payload' => null];
    }
    if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return ['ok' => false, 'reason' => 'Signature length is wrong.', 'payload' => null];
    }

    $payload = json_decode($payload_json, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'reason' => 'Licence payload is not valid JSON.', 'payload' => null];
    }

    foreach (['product', 'domain', 'tier', 'licence_id', 'issued'] as $field) {
        if (!array_key_exists($field, $payload)) {
            return ['ok' => false, 'reason' => "Licence payload missing field '$field'.", 'payload' => $payload];
        }
    }

    if ((string)$payload['product'] !== 'nano-cms') {
        $other = (string)$payload['product'];
        return ['ok' => false, 'reason' => "Licence is for product '$other', not nano-cms.", 'payload' => $payload];
    }

    $pubkey = base64_decode(NANO_LICENCE_PUBKEY_V1, true);
    if ($pubkey === false || strlen($pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['ok' => false, 'reason' => 'Embedded public key is malformed.', 'payload' => $payload];
    }

    // Verify against the RAW decoded payload bytes - the bytes the signer
    // actually signed. Re-encoding the parsed array would risk a key-order
    // or whitespace change that breaks the signature even when the licence
    // is genuine.
    try {
        $sig_ok = sodium_crypto_sign_verify_detached($signature, $payload_json, $pubkey);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'Signature verification raised an error.', 'payload' => $payload];
    }
    if (!$sig_ok) {
        return ['ok' => false, 'reason' => 'Signature does not match the embedded public key.', 'payload' => $payload];
    }

    // Wildcard `*` is only honoured for the agency-unlimited tier. The
    // toolkit's generator enforces this server-side; we mirror the gate
    // here as defence in depth.
    $licence_domain = strtolower((string)$payload['domain']);
    $check_host     = strtolower(trim($current_host));
    if (str_starts_with($check_host, 'www.')) {
        $check_host = substr($check_host, 4);
    }
    $tier = (string)$payload['tier'];
    if ($licence_domain === '*' && $tier === 'agency-unlimited') {
        // wildcard pass
    } elseif ($licence_domain !== $check_host) {
        return [
            'ok' => false,
            'reason' => "Licence covers '$licence_domain', site runs on '$check_host'.",
            'payload' => $payload,
        ];
    }

    $expires = $payload['expires'] ?? null;
    if ($expires !== null && $expires !== '') {
        $ts = strtotime((string)$expires);
        if ($ts === false) {
            // Fail closed on an unparseable expiry. Only Digital Fracture
            // can sign payloads, so a garbage `expires` would have to be
            // their mistake - but treating it as "no expiry" would be a
            // silent forgiveness of a malformed licence, which we'd
            // rather catch loudly than ignore.
            return ['ok' => false, 'reason' => "Licence has an unparseable expiry value: $expires.", 'payload' => $payload];
        }
        if ($ts < time()) {
            return ['ok' => false, 'reason' => "Licence expired on $expires.", 'payload' => $payload];
        }
    }

    return ['ok' => true, 'reason' => null, 'payload' => $payload];
}

/* ------------------------------------------------------------------------ */
/* Footer rendering                                                          */
/* ------------------------------------------------------------------------ */

/**
 * Render the "Powered by Nano CMS - Developed by Digital Fracture"
 * footer iff the site is unlicensed. Called from inside index.php
 * and post.php's output buffer so the footer lands inside the
 * `<main class="nano-blog">` wrapper that template.php emits.
 *
 * Returns an empty string in every "no footer" case (dev host, valid
 * licence, or any verification error) so callers can echo it
 * unconditionally without inserting blank markup.
 *
 * Silent on failure by design: the spec calls for either "no footer"
 * or the attribution string - never an error message in front of a
 * visitor.
 */
function nano_render_licence_footer(): string
{
    $footer_html = '<footer class="nano-blog-footer">'
                 . 'Powered by <a href="https://nanocms.co.uk/" target="_blank" rel="noopener noreferrer">Nano CMS</a>'
                 . ' &mdash; Developed by '
                 . '<a href="https://digitalfracture.co.uk/" target="_blank" rel="noopener noreferrer">Digital Fracture</a>'
                 . '</footer>';

    // Canonical host (from config base_url), NOT $_SERVER['HTTP_HOST'].
    // HTTP_HOST is attacker-controlled and was previously a single-request
    // bypass (Host: localhost on a production deploy) plus a reverse-proxy
    // cache-poisoning vector. Anchoring to base_url ties the licence check
    // to the operator's signed site identity instead.
    $host = nano_licence_canonical_host();
    if ($host === '') {
        // Config missing or base_url unset - fail-safe to "show the footer"
        // rather than risk a silent suppression on a misconfigured site.
        return $footer_html;
    }
    if (nano_is_dev_host($host)) {
        return '';
    }

    // nano_config() throws if config.json is missing or malformed. We
    // catch here so a config problem cannot suppress the whole page
    // just to render a small footer - fail safe to "show the footer".
    try {
        $licence_key = (string)(nano_config()['licence_key'] ?? '');
    } catch (Throwable $e) {
        $licence_key = '';
    }

    if ($licence_key !== '' && nano_verify_licence($licence_key, $host)) {
        return '';
    }

    return $footer_html;
}
