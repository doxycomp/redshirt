<?php

declare(strict_types=1);

/**
 * Redshirt RPC / Heartbeat API.
 *
 * Accepts authenticated POSTs (e.g. from a Raspberry Pi cronjob) and logs them
 * to heartbeat_log. Auth is a static Bearer token from the .env.
 *
 *   POST /endpoint.php
 *   Authorization: Bearer <BEARER_TOKEN>
 *   Content-Type: application/json
 *   {"device": "raspi-01", "message": "ok", "temperature_c": 47.8, "wlan_count": 12}
 *
 * Responses are always JSON.
 */

require __DIR__ . '/../private/db.php';

/** Send a JSON response and stop. */
function respond(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

// Any uncaught error becomes a clean JSON 500 (no SQL / stack-trace leakage).
set_exception_handler(function (Throwable $e): void {
    error_log('endpoint.php: ' . $e->getMessage());
    respond(500, ['status' => 'error', 'message' => 'Internal server error']);
});

/** Extract the token from the "Authorization: Bearer <token>" header. */
function bearerToken(): ?string
{
    // Apache/CGI does not always populate HTTP_AUTHORIZATION, so fall back to
    // getallheaders(). (See docs/endpoint.md for the .htaccess note.)
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }

    return null;
}

// --- Method ---------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['status' => 'error', 'message' => 'Method not allowed, use POST']);
}

// --- Authentication -------------------------------------------------------
$configuredToken = loadEnv()['BEARER_TOKEN'] ?? '';
if ($configuredToken === '') {
    // Refuse rather than accepting any token when the server is misconfigured.
    error_log('endpoint.php: BEARER_TOKEN is not configured in .env');
    respond(500, ['status' => 'error', 'message' => 'Server not configured']);
}

$provided = bearerToken();
// hash_equals is timing-safe; the null guard avoids comparing against null.
if ($provided === null || !hash_equals($configuredToken, $provided)) {
    header('WWW-Authenticate: Bearer');
    respond(401, ['status' => 'error', 'message' => 'Unauthorized']);
}

// --- Payload --------------------------------------------------------------
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65535) {
    respond(413, ['status' => 'error', 'message' => 'Payload missing or too large']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(400, ['status' => 'error', 'message' => 'Invalid JSON body']);
}

$device = isset($data['device']) && is_string($data['device']) ? trim($data['device']) : '';
if ($device === '') {
    respond(400, ['status' => 'error', 'message' => 'Field "device" is required']);
}
$device = mb_substr($device, 0, 128);

$message = isset($data['message']) && is_scalar($data['message'])
    ? mb_substr(trim((string) $data['message']), 0, 255)
    : null;

$hostname = isset($data['hostname']) && is_string($data['hostname'])
    ? mb_substr(trim($data['hostname']), 0, 255)
    : null;

// Store the full payload as canonical JSON so extra fields (temperature_c,
// wlan_count, ...) are preserved for the dashboard without a schema change.
$payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// --- Persist --------------------------------------------------------------
$pdo = getDb();
$stmt = $pdo->prepare("
    INSERT INTO heartbeat_log (device_id, hostname, ip, source, message, payload, created_at)
    VALUES (:device_id, :hostname, :ip, 'api', :message, :payload, NOW())
");
$stmt->execute([
    ':device_id' => $device,
    ':hostname'  => $hostname,
    ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
    ':message'   => $message,
    ':payload'   => $payload,
]);

respond(201, ['status' => 'ok', 'id' => (int) $pdo->lastInsertId()]);
