<?php
/**
 * Tiny hand-rolled .env loader — no Composer, so no vlucas/phpdotenv.
 * Parses KEY=VALUE lines from protected/.env, skips blanks and #comments,
 * strips surrounding quotes, and exposes values via env().
 */

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        throw new \RuntimeException("Missing .env file at $path. Copy .env.example to .env and fill in real values.");
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[-1] === '"') ||
            ($value[0] === "'" && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

function env(string $key, $default = null)
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

/**
 * Fails loudly and immediately if any of $keys is missing or empty in
 * the loaded .env, rather than letting a blank value silently reach
 * whatever component depends on it (an empty DB_DSN, for instance,
 * would otherwise surface many calls later as a confusing PDO/framework
 * error instead of a clear one naming the actual missing setting).
 *
 * Deliberately stricter than HumHub's own env loading, not modeled on
 * it: HumHub uses phpdotenv's safeLoad() (silently continues if .env is
 * entirely missing) and its config defaults DB credentials to empty
 * strings, only surfacing a problem later at the connection attempt.
 * That's a reasonable tradeoff for HumHub's own installer-driven setup
 * flow, but this app has no installer - a missing or incomplete .env
 * here should fail at boot, with a message naming exactly what's
 * missing, not several steps downstream.
 */
function requireEnv(array $keys): void
{
    $missing = [];
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        throw new \RuntimeException(
            'Missing required .env value(s): ' . implode(', ', $missing) . '. Check protected/.env against .env.example.'
        );
    }
}

/**
 * IPv4-only CIDR match (this app has never seen an IPv6 address anywhere
 * in its own diagnostics, and every proxy/network address involved so
 * far is IPv4). $cidr may be a bare IP ("10.10.2.2", treated as /32) or
 * a real CIDR block ("10.10.2.0/24"). Used by web.php to decide whether
 * a request's REMOTE_ADDR falls inside one of this deployment's trusted
 * proxy subnets, before Yii::$app exists to do that matching itself
 * (yii\web\Request::$trustedHosts does its own equivalent CIDR matching
 * internally, but only once the Request component is constructed - this
 * exists for the one decision, $isSecureRequest in web.php, that has to
 * be made before that, while building the config the component is
 * constructed from).
 */
function ipInCidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }

    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $bits = (int) $bits;
    if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
        return false;
    }

    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Parses a comma-separated list of CIDR blocks/IPs (as stored in
 * TRUSTED_PROXY_CIDRS) into a clean array, dropping blanks - so a
 * trailing comma or extra whitespace in .env doesn't produce a stray
 * empty-string entry that ipInCidr()/trustedHosts would have to guard
 * against separately.
 */
function parseCidrList(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
}
