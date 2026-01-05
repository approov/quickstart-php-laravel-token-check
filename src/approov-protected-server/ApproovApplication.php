<?php declare(strict_types=1);

/**
 * ApproovApplication
 *
 * Lightweight, single-file PHP router that exposes the Approov quickstart
 * endpoints on port 8080 when run with the built-in PHP server:
 *
 *   php -S 0.0.0.0:8080 src/approov-protected-server/ApproovApplication.php
 *
 * Endpoints:
 *   GET  /unprotected
 *   GET  /token-check
 *   GET  /token-binding
 *   GET  /token-double-binding
 *   GET  /approov-state
 *   POST /approov/enable
 *   POST /approov/disable
 *   POST /token-binding/enable
 *   POST /token-binding/disable
 *
 * The Approov token is verified with HS256 using the base64-decoded secret
 * provided in the APPROOV_BASE64_SECRET environment variable. Token binding
 * checks hash the expected header values with SHA-256 and compare the
 * base64-encoded result against the `pay` claim present in the token.
 */

const STATE_FILE = __DIR__ . '/approov_state.json';

/**
 * Returns default feature flags.
 */
function defaultState(): array
{
    return [
        'approovEnabled' => true,
        'tokenBindingEnabled' => true,
    ];
}

/**
 * Loads persisted feature flags from disk, falling back to defaults.
 */
function loadState(): array
{
    if (!file_exists(STATE_FILE)) {
        return defaultState();
    }

    $raw = file_get_contents(STATE_FILE);
    $decoded = json_decode($raw ?? '', true);

    if (!is_array($decoded)) {
        return defaultState();
    }

    return [
        'approovEnabled' => array_key_exists('approovEnabled', $decoded)
            ? (bool) $decoded['approovEnabled']
            : true,
        'tokenBindingEnabled' => array_key_exists('tokenBindingEnabled', $decoded)
            ? (bool) $decoded['tokenBindingEnabled']
            : true,
    ];
}

/**
 * Persists feature flags to disk.
 */
function persistState(array $state): void
{
    $stateToPersist = [
        'approovEnabled' => (bool) ($state['approovEnabled'] ?? true),
        'tokenBindingEnabled' => (bool) ($state['tokenBindingEnabled'] ?? true),
    ];

    file_put_contents(STATE_FILE, json_encode($stateToPersist, JSON_PRETTY_PRINT));
}

/**
 * Sends a JSON response and terminates execution.
 */
function jsonResponse($body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    echo json_encode($body);
    exit;
}

/**
 * Helper for an empty JSON response ({}).
 */
function emptyResponse(int $status): void
{
    jsonResponse(new stdClass(), $status);
}

/**
 * Sends a 401 Unauthorized response with a standard message.
 */
function unauthorized(): void
{
    jsonResponse(['error' => 'Approov authentication failed'], 401);
}

/**
 * Case-insensitive header fetcher.
 */
function readHeader(string $name): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    if (empty($headers)) {
        // Fallback for environments where getallheaders is not available.
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $normalized = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$normalized] = $value;
            }
        }
    }

    foreach ($headers as $key => $value) {
        if (strcasecmp($key, $name) === 0) {
            return is_array($value) ? implode(', ', $value) : $value;
        }
    }

    return null;
}

/**
 * Decodes a base64url string strictly.
 */
function base64UrlDecodeStrict(string $segment): ?string
{
    $segment = strtr($segment, '-_', '+/');
    $padding = strlen($segment) % 4;
    if ($padding) {
        $segment .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($segment, true);

    return $decoded === false ? null : $decoded;
}

/**
 * Loads the Approov secret from environment, decoding it from base64.
 */
function loadApproovSecret(): ?string
{
    $base64 = getenv('APPROOV_BASE64_SECRET') ?: '';

    if ($base64 === '') {
        foreach ([__DIR__ . '/../../.env', __DIR__ . '/../.env'] as $envPath) {
            if (!is_readable($envPath)) {
                continue;
            }

            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = ltrim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === 'APPROOV_BASE64_SECRET') {
                    $base64 = trim($v, " \t\n\r\0\x0B\"'");
                    break 2;
                }
            }
        }
    }
    if ($base64 === '') {
        return null;
    }

    $decoded = base64_decode($base64, true);
    return $decoded === false ? null : $decoded;
}

/**
 * Trims a string or returns null if input is null.
 */
function trimOrNull(?string $value): ?string
{
    return $value === null ? null : trim($value);
}

/**
 * SHA-256 hashes a string and returns it as standard base64 text.
 */
function hashBase64(string $value): string
{
    return base64_encode(hash('sha256', $value, true));
}

/**
 * Validates the exp claim for presence and non-expiration.
 */
function validateExpiration(array $claims): bool
{
    if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
        return false;
    }

    return (int) $claims['exp'] >= time();
}

/**
 * Verifies HS256 Approov token signature and expiry.
 *
 * @return array|null  Token claims on success, null on failure.
 */
function verifyApproovToken(string $token): ?array
{
    $secret = loadApproovSecret();

    if ($secret === null) {
        return null;
    }

    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        return null;
    }

    [$h64, $p64, $s64] = $parts;

    $headerJson = base64UrlDecodeStrict($h64);
    $payloadJson = base64UrlDecodeStrict($p64);
    $signature = base64UrlDecodeStrict($s64);

    if ($headerJson === null || $payloadJson === null || $signature === null) {
        return null;
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        return null;
    }

    if (($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $signedContent = $h64 . '.' . $p64;
    $expectedSig = hash_hmac('sha256', $signedContent, $secret, true);

    if (!hash_equals($expectedSig, $signature)) {
        return null;
    }

    if (!validateExpiration($payload)) {
        return null;
    }

    return $payload;
}

/**
 * Returns true for routes that require binding.
 */
function needsBindingCheck(string $path): bool
{
    return in_array($path, ['/token-binding', '/token-double-binding'], true);
}

/**
 * Extracts the binding value from headers for the given path.
 */
function extractBindingValue(string $path): ?string
{
    $auth = trimOrNull(readHeader('Authorization'));

    if ($path === '/token-binding') {
        return ($auth === null || $auth === '') ? null : $auth;
    }

    if ($path === '/token-double-binding') {
        $digest = trimOrNull(readHeader('Content-Digest'));

        if ($auth === null || $auth === '' || $digest === null || $digest === '') {
            return null;
        }

        return $auth . $digest;
    }

    return null;
}

/**
 * Compares expected binding hash from claims with a freshly computed one.
 */
function isBindingValid(array $claims, ?string $bindingValue): bool
{
    if ($bindingValue === null || !array_key_exists('pay', $claims)) {
        return false;
    }

    $expectedPay = hashBase64($bindingValue);

    return hash_equals($expectedPay, (string) $claims['pay']);
}

/**
 * Marker object when Approov is disabled.
 */
function disabledAuthentication(): array
{
    return ['principal' => 'approov-disabled'];
}

/**
 * Determines if the request path should skip Approov filtering.
 */
function shouldNotFilter(string $path): bool
{
    $protected = ['/token-check', '/token-binding', '/token-double-binding'];

    return !in_array($path, $protected, true);
}

/**
 * Main filter-like flow that enforces Approov requirements.
 */
function doFilterInternal(array $state, string $path): array
{
    if (shouldNotFilter($path)) {
        return [];
    }

    if (!$state['approovEnabled']) {
        return disabledAuthentication();
    }

    $token = trimOrNull(readHeader('Approov-Token') ?? readHeader('approov-token'));

    if ($token === null || $token === '') {
        unauthorized();
    }

    $claims = verifyApproovToken($token);

    if ($claims === null) {
        unauthorized();
    }

    if ($state['tokenBindingEnabled'] && needsBindingCheck($path)) {
        $bindingValue = extractBindingValue($path);
        if ($bindingValue === null || !isBindingValid($claims, $bindingValue)) {
            unauthorized();
        }
    }

    return ['principal' => 'approov-authenticated', 'claims' => $claims];
}

/**
 * Handles routing for the built-in server.
 */
function routeRequest(): void
{
    $state = loadState();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $authContext = doFilterInternal($state, $path);

    switch ($path) {
        case '/unprotected':
            jsonResponse(['ok' => true]);

        case '/token-check':
        case '/token-binding':
        case '/token-double-binding':
            jsonResponse([
                'ok' => true,
                'auth' => $authContext['principal'] ?? null,
                'approovEnabled' => $state['approovEnabled'],
                'tokenBindingEnabled' => $state['tokenBindingEnabled'],
            ]);

        case '/approov-state':
            jsonResponse($state);
            break;

        case '/approov/enable':
            if ($method !== 'POST') {
                emptyResponse(405);
            }
            $updated = [
                'approovEnabled' => true,
                'tokenBindingEnabled' => true,
            ];
            persistState($updated);
            jsonResponse($updated);

        case '/approov/disable':
            if ($method !== 'POST') {
                emptyResponse(405);
            }
            $updated = [
                'approovEnabled' => false,
                'tokenBindingEnabled' => false,
            ];
            persistState($updated);
            jsonResponse($updated);

        case '/token-binding/enable':
            if ($method !== 'POST') {
                emptyResponse(405);
            }
            $state['tokenBindingEnabled'] = true;
            $state['approovEnabled'] = true;
            persistState($state);
            jsonResponse($state);

        case '/token-binding/disable':
            if ($method !== 'POST') {
                emptyResponse(405);
            }
            $state['tokenBindingEnabled'] = false;
            persistState($state);
            jsonResponse($state);

        default:
            emptyResponse(404);
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['argv'][0] ?? '')) {
    echo "Run this file with the PHP built-in server:\n";
    echo "  php -S 0.0.0.0:8080 " . __FILE__ . "\n";
    exit(0);
}

routeRequest();
