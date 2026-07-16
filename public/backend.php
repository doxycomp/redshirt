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

$anonymize = filter_var($env['ANONYMIZE_OUTPUT'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$adminPasswordHash = $env['ADMIN_PASS'] ?? '';
if ($adminPasswordHash === '' || $adminPasswordHash === 'change_me') {
    http_response_code(500);
    exit('ADMIN_PASS not configured in .env');
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
    $auditWhere = "AND (a.session_id = :did_filter OR a.id IN (SELECT DISTINCT audit_log_id FROM heartbeat_log WHERE device_id = :did_filter2 AND audit_log_id IS NOT NULL))";
    $auditParams[':did_filter'] = $deviceFilter;
    $auditParams[':did_filter2'] = $deviceFilter;
    $heartbeatWhere = "AND device_id = :did_filter";
    $heartbeatParams[':did_filter'] = $deviceFilter;
}

$sort = $_GET['sort'] ?? 'created_at_desc';
$groupByIp = isset($_GET['group_by_ip']);

$auditOrder = match ($sort) {
    'ip_asc'        => 'a.ip ASC, a.created_at DESC',
    'ip_desc'       => 'a.ip DESC, a.created_at DESC',
    'id_asc'        => 'a.id ASC',
    'id_desc'       => 'a.id DESC',
    'note_asc'      => 'a.note ASC, a.created_at DESC',
    'note_desc'     => 'a.note DESC, a.created_at DESC',
    'created_at_asc' => 'a.created_at ASC',
    default         => 'a.created_at DESC',
};

$heartbeatOrder = match ($sort) {
    'ip_asc'        => 'ip ASC, created_at DESC',
    'ip_desc'       => 'ip DESC, created_at DESC',
    'id_asc'        => 'id ASC',
    'id_desc'       => 'id DESC',
    'created_at_asc' => 'created_at ASC',
    default         => 'created_at DESC',
};

$auditFields = $groupByIp
    ? "a.ip, COUNT(*) AS cnt, GROUP_CONCAT(a.id ORDER BY a.created_at DESC) AS ids, MAX(a.created_at) AS last_seen, MIN(a.created_at) AS first_seen, MAX(a.note) AS last_note, MAX(a.user_agent) AS last_user_agent"
    : "a.id, a.note, a.ip, a.user_agent, a.resolution, a.timezone, a.last_heartbeat, a.created_at, a.updated_at";

$auditFrom = $groupByIp
    ? "FROM audit_log a WHERE 1=1 $auditWhere GROUP BY a.ip ORDER BY " . ($sort === 'ip_asc' ? 'a.ip ASC' : ($sort === 'ip_desc' ? 'a.ip DESC' : 'MAX(a.created_at) DESC'))
    : "FROM audit_log a WHERE 1=1 $auditWhere ORDER BY $auditOrder";

$auditStmt = $pdo->prepare("SELECT $auditFields $auditFrom");
$auditStmt->execute($auditParams);
$auditLogs = $auditStmt->fetchAll();

$heartbeatStmt = $pdo->prepare("
    SELECT id, device_id, hostname, ip, source, audit_log_id, created_at
    FROM heartbeat_log
    WHERE 1=1 $heartbeatWhere
    ORDER BY $heartbeatOrder
");
$heartbeatStmt->execute($heartbeatParams);
$heartbeatLogs = $heartbeatStmt->fetchAll();

$anonLabel = '[anonymized]';
if ($anonymize) {
    foreach ($auditLogs as &$row) {
        $row['ip'] = $anonLabel;
        $row['user_agent'] = $anonLabel;
        if (isset($row['last_user_agent'])) {
            $row['last_user_agent'] = $anonLabel;
        }
    }
    unset($row);
    foreach ($heartbeatLogs as &$row) {
        $row['ip'] = $anonLabel;
    }
    unset($row);
}
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
.form-check-input { background-color: #0d1117; border-color: #30363d; }
.form-check-input:checked { background-color: #238636; border-color: #238636; }
.badge-info { background: #1f6feb; }
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
        <div class="col-auto">
            <label for="sort" class="form-label small mb-1">Sort</label>
            <select class="form-select form-select-sm" id="sort" name="sort" onchange="this.form.submit()">
                <option value="created_at_desc" <?php echo $sort === 'created_at_desc' ? 'selected' : ''; ?>>Newest first</option>
                <option value="created_at_asc" <?php echo $sort === 'created_at_asc' ? 'selected' : ''; ?>>Oldest first</option>
                <option value="ip_asc" <?php echo $sort === 'ip_asc' ? 'selected' : ''; ?>>IP (A-Z)</option>
                <option value="ip_desc" <?php echo $sort === 'ip_desc' ? 'selected' : ''; ?>>IP (Z-A)</option>
                <option value="id_asc" <?php echo $sort === 'id_asc' ? 'selected' : ''; ?>>ID (asc)</option>
                <option value="id_desc" <?php echo $sort === 'id_desc' ? 'selected' : ''; ?>>ID (desc)</option>
                <option value="note_asc" <?php echo $sort === 'note_asc' ? 'selected' : ''; ?>>Note (A-Z)</option>
                <option value="note_desc" <?php echo $sort === 'note_desc' ? 'selected' : ''; ?>>Note (Z-A)</option>
            </select>
        </div>
        <div class="col-auto">
            <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="groupByIp" name="group_by_ip" onchange="this.form.submit()" <?php echo $groupByIp ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="groupByIp">Group by IP</label>
            </div>
        </div>
        <?php if ($deviceFilter !== ''): ?>
            <div class="col-auto">
                <a href="backend.php" class="btn btn-outline-light btn-sm">Clear filter</a>
            </div>
        <?php endif; ?>
    </form>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Audit Log<?php echo $groupByIp ? ' (grouped by IP)' : ''; ?></span>
            <span class="badge text-bg-secondary"><?php echo count($auditLogs); ?> <?php echo $groupByIp ? 'IPs' : 'entries'; ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <?php if ($groupByIp): ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Entries</th>
                            <th>Entry IDs</th>
                            <th>First Seen</th>
                            <th>Last Seen</th>
                            <th>Last Note</th>
                            <th>Last UA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($auditLogs)): ?>
                            <tr><td colspan="7" class="empty-state text-center py-4">No audit entries found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($auditLogs as $row): ?>
                            <tr>
                                <td class="text-nowrap"><code><?php echo htmlspecialchars($row['ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><span class="badge text-bg-info"><?php echo (int) $row['cnt']; ?></span></td>
                                <td class="small text-muted" style="max-width:250px; word-break:break-all"><?php echo htmlspecialchars($row['ids'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($row['first_seen'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($row['last_seen'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['last_note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-truncate" style="max-width:180px"><?php echo htmlspecialchars((string) ($row['last_user_agent'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php else: ?>
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
                <?php endif; ?>
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
