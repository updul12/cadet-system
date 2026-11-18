<?php
/**
 * Audit log viewer — Admin only.
 * Filters: action, username, date range; CSV export.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireRole(['Admin']);

/* Distinct actions for the filter dropdown. */
$actionList = db()->query('SELECT DISTINCT action FROM audit_log ORDER BY action')
    ->fetchAll(PDO::FETCH_COLUMN);

/* --- Filters (validated before use in SQL) -------------------------- */
$fAction = (string)($_GET['action'] ?? '');
$fUser   = trim((string)($_GET['username'] ?? ''));
$fFrom   = (string)($_GET['from'] ?? '');
$fTo     = (string)($_GET['to'] ?? '');
if (!in_array($fAction, $actionList, true)) {
    $fAction = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) {
    $fFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) {
    $fTo = '';
}

$where = [];
$args  = [];
if ($fAction !== '') {
    $where[] = 'action = ?';
    $args[]  = $fAction;
}
if ($fUser !== '') {
    $where[] = 'username LIKE ?';
    $args[]  = '%' . $fUser . '%';
}
if ($fFrom !== '') {
    $where[] = 'created_at >= ?';
    $args[]  = $fFrom . ' 00:00:00';
}
if ($fTo !== '') {
    $where[] = 'created_at <= ?';
    $args[]  = $fTo . ' 23:59:59';
}
$wSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

/* --- CSV export (respects filters, capped) -------------------------- */
if (isset($_GET['export'])) {
    $stmt = db()->prepare(
        "SELECT id, username, action, entity, entity_id, detail, ip_address, created_at
           FROM audit_log {$wSql}
          ORDER BY created_at DESC, id DESC
          LIMIT 5000"
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    audit_log('audit_export', 'audit_log', null, count($rows) . ' rows');
    send_csv(
        'audit_log_' . date('Ymd_His') . '.csv',
        ['id', 'username', 'action', 'entity', 'entity_id', 'detail', 'ip_address', 'created_at'],
        array_map(static fn(array $r): array => [
            $r['id'], $r['username'] ?? '', $r['action'], $r['entity'] ?? '',
            $r['entity_id'] ?? '', $r['detail'] ?? '', $r['ip_address'] ?? '', $r['created_at'],
        ], $rows)
    );
}

/* --- Paginated listing ---------------------------------------------- */
$perPage = 50;
$total   = 0;
try {
    $cnt = db()->prepare("SELECT COUNT(*) FROM audit_log {$wSql}");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();
} catch (Throwable $ex) {
    error_log('[AUDIT-VIEW] ' . $ex->getMessage());
}
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$entries = [];
try {
    $stmt = db()->prepare(
        "SELECT id, username, action, entity, entity_id, detail, ip_address, created_at
           FROM audit_log {$wSql}
          ORDER BY created_at DESC, id DESC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($args);
    $entries = $stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('[AUDIT-VIEW] ' . $ex->getMessage());
}

/* Query string for pagination links (keeps filters). */
$qs = http_build_query(array_filter([
    'action'   => $fAction,
    'username' => $fUser,
    'from'     => $fFrom,
    'to'       => $fTo,
], static fn(string $v): bool => $v !== ''));

$page_title = 'Audit log';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';

function audit_badge(string $action): string
{
    if (str_contains($action, 'fail') || str_contains($action, 'block') || str_ends_with($action, '_delete')) {
        $cls = 'bg-danger';
    } elseif (str_contains($action, 'login_success') || str_ends_with($action, '_create') || str_ends_with($action, '_import')) {
        $cls = 'bg-success';
    } elseif (str_ends_with($action, '_update') || str_ends_with($action, '_edit') || str_ends_with($action, '_role')
        || str_ends_with($action, '_status') || str_contains($action, 'password') || str_contains($action, 'settings')) {
        $cls = 'bg-warning text-dark';
    } else {
        $cls = 'bg-secondary';
    }
    return '<span class="badge ' . $cls . '">' . e($action) . '</span>';
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-journal-text me-2"></i>Audit log</h1>
    <p class="text-muted mb-0"><?= (int)$total ?> record<?= $total === 1 ? '' : 's' ?> — sign-ins, changes and admin actions.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?= e(purl('admin/audit_logs.php')) ?>?<?= e($qs ? $qs . '&export=1' : 'export=1') ?>">
    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
  </a>
</div>

<div class="card shadow-sm border-0 mb-4">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label small" for="f_action">Action</label>
        <select class="form-select form-select-sm" id="f_action" name="action">
          <option value="">— All actions —</option>
          <?php foreach ($actionList as $a): ?>
            <option value="<?= e($a) ?>" <?= $fAction === $a ? 'selected' : '' ?>><?= e($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small" for="f_user">Username</label>
        <input class="form-control form-control-sm" id="f_user" name="username" maxlength="50"
               value="<?= e($fUser) ?>" placeholder="contains…">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small" for="f_from">From</label>
        <input class="form-control form-control-sm" type="date" id="f_from" name="from" value="<?= e($fFrom) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small" for="f_to">To</label>
        <input class="form-control form-control-sm" type="date" id="f_to" name="to" value="<?= e($fTo) ?>">
      </div>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(purl('admin/audit_logs.php')) ?>" title="Clear filters"><i class="bi bi-x-lg"></i></a>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No audit entries yet.">
      <thead class="table-dark">
        <tr>
          <th style="width:170px">When</th>
          <th>User</th>
          <th>Action</th>
          <th class="d-none d-lg-table-cell">Record</th>
          <th class="d-none d-xl-table-cell">Detail</th>
          <th class="d-none d-lg-table-cell">IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($entries as $r): ?>
        <tr>
          <td class="small text-nowrap"><?= e(date('d M Y H:i:s', strtotime((string)$r['created_at']))) ?></td>
          <td class="small"><?= $r['username'] ? e($r['username']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= audit_badge((string)$r['action']) ?></td>
          <td class="small d-none d-lg-table-cell text-muted">
            <?= $r['entity'] ? e($r['entity'] . ($r['entity_id'] !== null ? ' #' . (int)$r['entity_id'] : '')) : '—' ?>
          </td>
          <td class="small d-none d-xl-table-cell text-break" style="max-width:340px">
            <?= $r['detail'] ? e($r['detail']) : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="small d-none d-lg-table-cell text-muted font-monospace"><?= e($r['ip_address'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$entries): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No log entries match these filters.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Audit log pages">
  <ul class="pagination pagination-sm justify-content-center">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(purl('admin/audit_logs.php')) ?>?<?= e($qs ? $qs . '&page=' : 'page=') . (int)($page - 1) ?>">Prev</a>
    </li>
    <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="<?= e(purl('admin/audit_logs.php')) ?>?<?= e($qs ? $qs . '&page=' : 'page=') . $p ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(purl('admin/audit_logs.php')) ?>?<?= e($qs ? $qs . '&page=' : 'page=') . (int)($page + 1) ?>">Next</a>
    </li>
  </ul>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
