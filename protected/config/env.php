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
        // A variable already set in the real environment wins over .env,
        // so a one-off command can override e.g. DB_USER without editing the file.
        if (getenv($key) !== false) {
            continue;
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
 * Parses a comma-separated list of CIDR blocks/IPs (as stored in
 * TRUSTED_PROXY_CIDRS) into a clean array, dropping blanks - so a
 * trailing comma or extra whitespace in .env doesn't produce a stray
 * empty-string entry that Yii's own trustedHosts matching would have to
 * guard against separately.
 */
function parseCidrList(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
}
