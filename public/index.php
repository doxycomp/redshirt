<?php

declare(strict_types=1);

// Return a clean JSON 500 for any uncaught error instead of leaking SQL /
// host details (or a stack trace when display_errors is on).
set_exception_handler(function (Throwable $e): void {
    error_log('index.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
});

$env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);
if ($env === false) {
    error_log('index.php: .env not found or unreadable');
    http_response_code(500);
    exit('Configuration error');
}

$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
foreach ($required as $key) {
    if (!isset($env[$key]) || $env[$key] === '') {
        // Log server-side; do not reveal which key is missing to the client.
        error_log("index.php: missing required .env key: $key");
        http_response_code(500);
        exit('Configuration error');
    }
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_NAME']);
if (isset($env['DB_PORT']) && $env['DB_PORT'] !== '') {
    $dsn .= ';port=' . $env['DB_PORT'];
}

$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// Schema is created once via private/migrate.php, not on every request.

// Harden the session cookie before the session is started.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['initialized'])) {
    // Mitigate session fixation exactly once per new session. The previous
    // empty(session_id()) check never fired, so this never ran.
    session_regenerate_id(true);
    $_SESSION['initialized'] = true;
}

$sessionId = session_id();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$auditId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    $clientData = isset($_POST['client_data']) ? json_decode($_POST['client_data'], true) : [];
    if (!is_array($clientData)) {
        $clientData = [];
    }

    if (isset($_GET['action']) && $_GET['action'] === 'heartbeat') {
        // Only accept an audit_id that belongs to the current session.
        // Otherwise a client could bump last_heartbeat on rows it does not
        // own (IDOR) or trigger an uncaught FK violation with a bogus id.
        $auditLogId = null;
        if (isset($_POST['audit_id']) && is_string($_POST['audit_id']) && ctype_digit($_POST['audit_id'])) {
            $check = $pdo->prepare("SELECT id FROM audit_log WHERE id = :id AND session_id = :sid");
            $check->execute([':id' => $_POST['audit_id'], ':sid' => $sessionId]);
            $owned = $check->fetchColumn();
            if ($owned !== false) {
                $auditLogId = (int) $owned;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO heartbeat_log (device_id, hostname, ip, source, audit_log_id, created_at)
            VALUES (:device_id, :hostname, :ip, 'web', :audit_log_id, NOW())
        ");
        $stmt->execute([
            ':device_id'    => $sessionId,
            // Web clients can't report a hostname; gethostname() would only
            // ever store the server's own name, so leave it NULL.
            ':hostname'     => null,
            ':ip'           => $ip,
            ':audit_log_id' => $auditLogId,
        ]);
        if ($auditLogId !== null) {
            $pdo->prepare("UPDATE audit_log SET last_heartbeat = NOW() WHERE id = :id")
                ->execute([':id' => $auditLogId]);
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO audit_log (note, ip, user_agent, resolution, timezone, session_id, created_at)
        VALUES (:note, :ip, :user_agent, :resolution, :timezone, :session_id, NOW())
    ");
    $stmt->execute([
        ':note'       => $note,
        ':ip'         => $ip,
        ':user_agent' => $userAgent,
        ':resolution' => $clientData['resolution'] ?? null,
        ':timezone'   => $clientData['timezone'] ?? null,
        ':session_id' => $sessionId,
    ]);
    $auditId = (int) $pdo->lastInsertId();

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'audit_id' => $auditId]);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO audit_log (ip, user_agent, session_id, created_at)
    VALUES (:ip, :user_agent, :session_id, NOW())
");
$stmt->execute([
    ':ip'         => $ip,
    ':user_agent' => $userAgent,
    ':session_id' => $sessionId,
]);
$auditId = (int) $pdo->lastInsertId();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Redshirt - Audit Trail</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0d1117;
    color: #c9d1d9;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}
.card {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 12px;
    padding: 2rem;
    width: 100%;
    max-width: 520px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
}
h1 {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: #f0f6fc;
}
.subtitle {
    font-size: 0.85rem;
    color: #8b949e;
    margin-bottom: 1.5rem;
}
.form-group {
    margin-bottom: 1rem;
}
label {
    display: block;
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 0.35rem;
    color: #c9d1d9;
}
textarea, input[type="text"] {
    width: 100%;
    padding: 0.6rem 0.75rem;
    background: #0d1117;
    border: 1px solid #30363d;
    border-radius: 8px;
    color: #c9d1d9;
    font-size: 0.95rem;
    font-family: inherit;
    resize: vertical;
    transition: border-color 0.2s;
}
textarea:focus, input[type="text"]:focus {
    outline: none;
    border-color: #58a6ff;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #238636;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0.6rem 1.5rem;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s;
}
.btn:hover { background: #2ea043; }
.status {
    margin-top: 1rem;
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    font-size: 0.85rem;
    display: none;
}
.status.success { display: block; background: #1b3622; color: #7ee787; border: 1px solid #238636; }
.status.error { display: block; background: #361b1b; color: #ff7b72; border: 1px solid #da3633; }
a { color: #58a6ff; text-decoration: none; }
a:hover { text-decoration: underline; }
.footer {
    margin-top: 1.5rem;
    text-align: center;
    font-size: 0.8rem;
    color: #484f58;
}
</style>
</head>
<body>
<div class="card">
    <h1>Audit Trail</h1>
    <p class="subtitle">Your visit has been logged.</p>

    <form id="auditForm" method="POST" action="">
        <div class="form-group">
            <label for="note">Note</label>
            <textarea id="note" name="note" rows="3" placeholder="Write a note..."></textarea>
        </div>
        <input type="hidden" name="client_data" id="clientData">
        <button type="submit" class="btn">&#128190; Save Note</button>
    </form>

    <div id="status" class="status"></div>
</div>
<div class="footer">
    <a href="https://github.com/doxycomp/redshirt" target="_blank">Redshirt</a> &mdash; Audit Trail &amp; Heartbeat Monitoring
</div>

<script>
(function() {
    const resolution = screen.width + 'x' + screen.height;
    let timezone = '';
    try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch(e) {}

    const clientData = JSON.stringify({ resolution: resolution, timezone: timezone });
    document.getElementById('clientData').value = clientData;

    const form = document.getElementById('auditForm');
    const statusEl = document.getElementById('status');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        statusEl.className = 'status';
        statusEl.style.display = 'none';

        const formData = new FormData(form);
        fetch('', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    statusEl.className = 'status success';
                    statusEl.textContent = 'Note saved successfully.';
                    statusEl.style.display = 'block';
                } else {
                    statusEl.className = 'status error';
                    statusEl.textContent = 'Failed to save note.';
                    statusEl.style.display = 'block';
                }
            })
            .catch(function() {
                statusEl.className = 'status error';
                statusEl.textContent = 'Network error.';
                statusEl.style.display = 'block';
            });
    });

    var auditId = <?php echo json_encode($auditId); ?>;
    setInterval(function() {
        var payload = new URLSearchParams();
        payload.set('action', 'heartbeat');
        payload.set('audit_id', auditId);
        fetch('?action=heartbeat', { method: 'POST', body: payload });
    }, 30000);
})();
</script>
</body>
</html>
