<?php

declare(strict_types=1);

require __DIR__ . '/../private/db.php';

set_exception_handler(function (Throwable $e): void {
    error_log('backend.php: ' . $e->getMessage());
    http_response_code(500);
    exit('Internal server error');
});

$env = loadEnv();
$pdo = getDb();

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$adminPasswordHash = $env['ADMIN_PASSWORD'] ?? '';
if ($adminPasswordHash === '' || $adminPasswordHash === 'change_me') {
    http_response_code(500);
    exit('ADMIN_PASSWORD not configured in .env');
}

if (isset($_GET['logout'])) {
    unset($_SESSION['authenticated']);
    session_destroy();
    header('Location: backend.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($adminPasswordHash, hash('sha256', $_POST['password']))) {
        $_SESSION['authenticated'] = true;
        header('Location: backend.php');
        exit;
    }
    $loginError = 'Invalid password.';
}

if (empty($_SESSION['authenticated'])) {
    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Redshirt - Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #0d1117; }
.login-card {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 12px;
    max-width: 400px;
}
.form-control { background: #0d1117; border-color: #30363d; color: #c9d1d9; }
.form-control:focus { border-color: #58a6ff; box-shadow: 0 0 0 0.15rem rgba(88,166,255,0.25); }
.btn-primary { background: #238636; border-color: #238636; }
.btn-primary:hover { background: #2ea043; border-color: #2ea043; }
</style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100 p-3">
<div class="login-card p-4 w-100">
    <h4 class="fw-semibold mb-1" style="color:#f0f6fc">Redshirt Dashboard</h4>
    <p class="small mb-3" style="color:#8b949e">Enter the admin password to continue.</p>
    <?php if (isset($loginError)): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary w-100">Sign in</button>
    </form>
</div>
</body>
</html>
    <?php
    exit;
}

$deviceFilter = $_GET['device_id'] ?? '';
$deviceIds = $pdo->query("SELECT DISTINCT device_id FROM heartbeat_log ORDER BY device_id")->fetchAll(PDO::FETCH_COLUMN);

$auditWhere = '';
$auditParams = [];
$heartbeatWhere = '';
$heartbeatParams = [];

if ($deviceFilter !== '') {
    $auditWhere = "AND (a.session_id = :did OR a.id IN (SELECT DISTINCT audit_log_id FROM heartbeat_log WHERE device_id = :did AND audit_log_id IS NOT NULL))";
    $auditParams[':did'] = $deviceFilter;
    $heartbeatWhere = "AND device_id = :did";
    $heartbeatParams[':did'] = $deviceFilter;
}

$auditStmt = $pdo->prepare("
    SELECT a.id, a.note, a.ip, a.user_agent, a.resolution, a.timezone, a.last_heartbeat, a.created_at, a.updated_at
    FROM audit_log a
    WHERE 1=1 $auditWhere
    ORDER BY a.created_at DESC
    LIMIT 100
");
$auditStmt->execute($auditParams);
$auditLogs = $auditStmt->fetchAll();

$heartbeatStmt = $pdo->prepare("
    SELECT id, device_id, hostname, ip, source, audit_log_id, created_at
    FROM heartbeat_log
    WHERE 1=1 $heartbeatWhere
    ORDER BY created_at DESC
    LIMIT 100
");
$heartbeatStmt->execute($heartbeatParams);
$heartbeatLogs = $heartbeatStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Redshirt - Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #0d1117; color: #c9d1d9; }
.navbar { background: #161b22 !important; border-bottom: 1px solid #30363d; }
.navbar-brand { color: #f0f6fc !important; font-weight: 600; }
.card { background: #161b22; border: 1px solid #30363d; }
.table { color: #c9d1d9; --bs-table-bg: transparent; --bs-table-border-color: #21262d; }
.table thead th { color: #8b949e; font-weight: 500; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom-width: 1px; }
.form-select, .form-control { background-color: #0d1117; border-color: #30363d; color: #c9d1d9; }
.form-select:focus, .form-control:focus { border-color: #58a6ff; box-shadow: 0 0 0 0.15rem rgba(88,166,255,0.25); background-color: #0d1117; color: #c9d1d9; }
.btn-outline-light { --bs-btn-border-color: #30363d; --bs-btn-color: #c9d1d9; }
.btn-outline-light:hover { background: #21262d; color: #f0f6fc; }
.badge-web { background: #1f6feb; }
.badge-api { background: #8957e5; }
.empty-state { color: #484f58; font-style: italic; }
a { color: #58a6ff; }
</style>
</head>
<body>
<nav class="navbar px-3">
    <span class="navbar-brand">Redshirt Dashboard</span>
    <div class="d-flex gap-2 align-items-center">
        <span class="text-muted small">authenticated</span>
        <a href="?logout=1" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container-fluid py-4 px-3">
    <form method="GET" class="row g-3 mb-4 align-items-end">
        <div class="col-auto">
            <label for="deviceId" class="form-label small mb-1">Filter by Device ID</label>
            <select class="form-select form-select-sm" id="deviceId" name="device_id" onchange="this.form.submit()">
                <option value="">All devices</option>
                <?php foreach ($deviceIds as $did): ?>
                    <option value="<?php echo htmlspecialchars($did, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $deviceFilter === $did ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($did, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($deviceFilter !== ''): ?>
            <div class="col-auto">
                <a href="backend.php" class="btn btn-outline-light btn-sm">Clear filter</a>
            </div>
        <?php endif; ?>
    </form>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Audit Log</span>
            <span class="badge text-bg-secondary"><?php echo count($auditLogs); ?> entries</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Note</th>
                            <th>IP</th>
                            <th>User Agent</th>
                            <th>Resolution</th>
                            <th>Timezone</th>
                            <th>Last Heartbeat</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($auditLogs)): ?>
                            <tr><td colspan="8" class="empty-state text-center py-4">No audit entries found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($auditLogs as $row): ?>
                            <tr>
                                <td class="text-muted small">#<?php echo (int) $row['id']; ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-nowrap"><code><?php echo htmlspecialchars($row['ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td class="text-truncate" style="max-width:200px"><?php echo htmlspecialchars($row['user_agent'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['resolution'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['timezone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-nowrap"><?php echo $row['last_heartbeat'] ? htmlspecialchars($row['last_heartbeat'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">&mdash;</span>'; ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Heartbeat Log</span>
            <span class="badge text-bg-secondary"><?php echo count($heartbeatLogs); ?> entries</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Device ID</th>
                            <th>Hostname</th>
                            <th>IP</th>
                            <th>Source</th>
                            <th>Audit Ref</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($heartbeatLogs)): ?>
                            <tr><td colspan="7" class="empty-state text-center py-4">No heartbeat entries found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($heartbeatLogs as $row): ?>
                            <tr>
                                <td class="text-muted small">#<?php echo (int) $row['id']; ?></td>
                                <td class="text-nowrap"><code><?php echo htmlspecialchars($row['device_id'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><?php echo htmlspecialchars((string) ($row['hostname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-nowrap"><code><?php echo htmlspecialchars($row['ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><span class="badge <?php echo $row['source'] === 'web' ? 'badge-web' : 'badge-api'; ?>"><?php echo htmlspecialchars($row['source'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo $row['audit_log_id'] ? '#' . (int) $row['audit_log_id'] : '<span class="text-muted">&mdash;</span>'; ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
