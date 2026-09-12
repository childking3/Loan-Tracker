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
 * Fails loudly at boot if any of $keys is missing/empty in .env, rather
 * than letting a blank value (e.g. empty DB_DSN) surface later as a
 * confusing downstream error. Deliberately stricter than HumHub, which
 * uses phpdotenv's safeLoad() and empty DB defaults - fine for its
 * installer flow, but this app has no installer.
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
 * IPv4-only CIDR match (no IPv6 seen anywhere in this deployment). $cidr
 * may be a bare IP (treated as /32) or a real block ("10.10.2.0/24").
 * Used by web.php to check REMOTE_ADDR against trusted proxy subnets
 * before Yii::$app exists to do that matching itself.
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
