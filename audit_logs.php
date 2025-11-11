<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();

// Filters
$user_id = trim((string)($_GET['user_id'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$entity_type = trim((string)($_GET['entity_type'] ?? ''));
$entity_id = trim((string)($_GET['entity_id'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$where = [];
$params = [];
if ($user_id !== '' && ctype_digit($user_id)) { $where[] = 'a.user_id = ?'; $params[] = (int)$user_id; }
if ($action !== '') { $where[] = 'a.action LIKE ?'; $params[] = '%' . $action . '%'; }
if ($entity_type !== '') { $where[] = 'a.entity_type LIKE ?'; $params[] = '%' . $entity_type . '%'; }
if ($entity_id !== '' && ctype_digit($entity_id)) { $where[] = 'a.entity_id = ?'; $params[] = (int)$entity_id; }
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'a.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$whereSql = empty($where) ? '1=1' : implode(' AND ', $where);

$stmt = $db->prepare("SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.before_json, a.after_json, a.note, a.extra_json, a.created_at FROM audit_logs a WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Audit Logs</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
    .json-block { max-width: 520px; max-height: 120px; overflow: auto; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; }
  </style>
  </head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3">Audit Logs</h1>
    <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
  </div>

  <div class="card mb-4">
    <div class="card-header">Filters</div>
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label">User ID</label>
          <input type="number" name="user_id" class="form-control" value="<?php echo htmlspecialchars($user_id); ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Action</label>
          <input type="text" name="action" class="form-control" value="<?php echo htmlspecialchars($action); ?>" placeholder="create/update/delete">
        </div>
        <div class="col-md-3">
          <label class="form-label">Entity Type</label>
          <input type="text" name="entity_type" class="form-control" value="<?php echo htmlspecialchars($entity_type); ?>" placeholder="attendance, student, ...">
        </div>
        <div class="col-md-2">
          <label class="form-label">Entity ID</label>
          <input type="number" name="entity_id" class="form-control" value="<?php echo htmlspecialchars($entity_id); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">From</label>
          <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">To</label>
          <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>">
        </div>
        <div class="col-12">
          <button class="btn btn-primary" type="submit"><i class="fa fa-filter"></i> Apply</button>
          <a class="btn btn-outline-secondary" href="audit_logs.php"><i class="fa fa-rotate"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Recent Audits</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
          <thead>
            <tr>
              <th>Date</th>
              <th>User ID</th>
              <th>Action</th>
              <th>Entity</th>
              <th>Entity ID</th>
              <th>Before</th>
              <th>After</th>
              <th>Note</th>
              <th>Extra</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><code><?php echo (int)$r['user_id']; ?></code></td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['action']); ?></span></td>
                <td><code><?php echo htmlspecialchars($r['entity_type']); ?></code></td>
                <td><?php echo (int)$r['entity_id']; ?></td>
                <td><div class="json-block"><?php echo htmlspecialchars($r['before_json'] ?? ''); ?></div></td>
                <td><div class="json-block"><?php echo htmlspecialchars($r['after_json'] ?? ''); ?></div></td>
                <td><small><?php echo htmlspecialchars($r['note'] ?? ''); ?></small></td>
                <td><small class="text-muted"><?php echo htmlspecialchars($r['extra_json'] ?? ''); ?></small></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="9" class="text-center text-muted">No audit logs found.</td></tr>
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
