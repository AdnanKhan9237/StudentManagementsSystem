<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
// Limit viewing to admins and accounts for now
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();

// CSRF helpers
function notifications_csrf_token(): string {
    if (!isset($_SESSION['csrf_token_notifications'])) {
        $_SESSION['csrf_token_notifications'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token_notifications'];
}
function notifications_csrf_verify(string $token): bool {
    return isset($_SESSION['csrf_token_notifications']) && hash_equals($_SESSION['csrf_token_notifications'], $token);
}

// Acknowledge action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ack') {
    $id = (int)($_POST['id'] ?? 0);
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($id <= 0 || !notifications_csrf_verify($token)) {
        http_response_code(400);
        echo 'Invalid request';
        exit;
    }
    try {
        $stmt = $db->prepare('UPDATE notifications SET acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ?');
        $stmt->execute([(int)$session->getUserId(), $id]);
        header('Location: notifications.php?ack=1');
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Failed to acknowledge';
        exit;
    }
}

// Filters via GET
$type = trim((string)($_GET['type'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$onlyUnack = (int)($_GET['only_unack'] ?? 0);

$where = [];
$params = [];
if ($type !== '') { $where[] = 'n.type LIKE ?'; $params[] = '%' . $type . '%'; }
if (in_array($level, ['info','warning','error'], true)) { $where[] = 'n.level = ?'; $params[] = $level; }
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'n.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'n.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($onlyUnack === 1) { $where[] = 'n.acknowledged = 0'; }
$whereSql = empty($where) ? '1=1' : implode(' AND ', $where);

$stmt = $db->prepare("SELECT n.id, n.type, n.title, n.body, n.level, n.meta_json, n.created_at, n.acknowledged, n.acknowledged_by, n.acknowledged_at FROM notifications n WHERE $whereSql ORDER BY n.created_at DESC, n.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notifications</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="assets/css/design-system.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3">Notifications</h1>
    <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
  </div>

  <div class="card mb-4">
    <div class="card-header">Filters</div>
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <input type="text" name="type" class="form-control" value="<?php echo htmlspecialchars($type); ?>" placeholder="e.g. attendance_anomaly">
        </div>
        <div class="col-md-3">
          <label class="form-label">Level</label>
          <select name="level" class="form-select">
            <option value="">All</option>
            <option value="info" <?php echo $level==='info'?'selected':''; ?>>Info</option>
            <option value="warning" <?php echo $level==='warning'?'selected':''; ?>>Warning</option>
            <option value="error" <?php echo $level==='error'?'selected':''; ?>>Error</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">From</label>
          <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">To</label>
          <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>">
        </div>
        <div class="col-md-3">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="only_unack" value="1" id="onlyUnack" <?php echo $onlyUnack===1?'checked':''; ?>>
            <label class="form-check-label" for="onlyUnack">Unacknowledged only</label>
          </div>
        </div>
        <div class="col-12">
          <button class="btn btn-primary" type="submit"><i class="fa fa-filter"></i> Apply</button>
          <a class="btn btn-outline-secondary" href="notifications.php"><i class="fa fa-rotate"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Recent Notifications</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Level</th>
              <th>Title</th>
              <th>Body</th>
              <th>Meta</th>
              <th>Ack</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><code><?php echo htmlspecialchars($r['type']); ?></code></td>
                <td>
                  <span class="badge <?php echo $r['level']==='info'?'bg-info':($r['level']==='warning'?'bg-warning text-dark':'bg-danger'); ?>">
                    <?php echo htmlspecialchars($r['level']); ?>
                  </span>
                </td>
                <td><?php echo htmlspecialchars($r['title']); ?></td>
                <td><?php echo nl2br(htmlspecialchars($r['body'] ?? '')); ?></td>
                <td><small class="text-muted"><?php echo htmlspecialchars($r['meta_json'] ?? ''); ?></small></td>
                <td><?php echo ((int)($r['acknowledged'] ?? 0) === 1) ? 'Yes' : 'No'; ?></td>
                <td>
                  <?php if ((int)($r['acknowledged'] ?? 0) === 0): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="ack">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(notifications_csrf_token()); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-success"><i class="fa fa-check"></i> Acknowledge</button>
                    </form>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="8" class="text-center text-muted">No notifications found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
