<?php
/**
 * Cadet roster — live search by service number or name, status filter.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();
requireModule('cadets');

/* Admin-only delete */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'delete') {
        redirect(purl('cadets/list.php'));
    }
    $user = requireRole(['Admin']);
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT id, full_name, service_no, photo FROM cadets WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        flash('warning', 'Cadet not found.');
        redirect(purl('cadets/list.php'));
    }
    /* Cascade removes related rank/fitness/attendance/duty/discipline rows.
       Inventory uses ON DELETE SET NULL — clear photo file first. */
    delete_photo($target['photo'] ?? null);
    $del = db()->prepare('DELETE FROM cadets WHERE id = ?');
    $del->execute([$id]);
    audit_log('cadet_delete', 'cadets', $id, $target['service_no'] . ' ' . $target['full_name']);
    flash('success', 'Cadet "' . $target['full_name'] . '" and related records deleted.');
    redirect(purl('cadets/list.php'));
}

$q     = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$validStatuses = ['Active', 'Alumnus', 'Discharged'];
if (!in_array($status, $validStatuses, true)) {
    $status = '';
}

/* CSV export of current filter */
if (isset($_GET['export'])) {
    $rows = db()->query(
        'SELECT service_no, full_name, gender, department, current_rank, `current_role`, status, enrolled_date, phone, email
           FROM cadets ORDER BY full_name'
    )->fetchAll();
    audit_log('cadets_export', 'cadets', null, count($rows) . ' rows');
    send_csv(
        'cadet_roster_' . date('Ymd') . '.csv',
        ['service_no', 'full_name', 'gender', 'department', 'rank', 'role', 'status', 'enrolled_date', 'phone', 'email'],
        array_map(static fn(array $r): array => [
            $r['service_no'], $r['full_name'], $r['gender'], $r['department'] ?? '',
            $r['current_rank'], $r['current_role'] ?? '', $r['status'], $r['enrolled_date'],
            $r['phone'] ?? '', $r['email'] ?? '',
        ], $rows)
    );
}

$where  = [];
$args   = [];
if ($q !== '') {
    $where[] = '(service_no LIKE ? OR full_name LIKE ?)';
    $like = '%' . $q . '%';
    $args[] = $like;
    $args[] = $like;
}
if ($status !== '') {
    $where[] = 'status = ?';
    $args[] = $status;
}
$sql = 'SELECT id, service_no, full_name, gender, department, current_rank, `current_role`, status, enrolled_date
          FROM cadets';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY full_name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$cadets = $stmt->fetchAll();

$page_title = 'Cadet roster';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-people me-2"></i>Cadet roster</h1>
    <p class="text-muted mb-0"><?= count($cadets) ?> record(s)<?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="<?= e(purl('cadets/list.php')) ?>?export=1">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
    </a>
    <?php if (canEdit()): ?>
      <a class="btn btn-outline-primary" href="<?= e(purl('cadets/import.php')) ?>">
        <i class="bi bi-file-earmark-arrow-up me-1"></i>Import
      </a>
      <a class="btn btn-primary" href="<?= e(purl('cadets/create.php')) ?>"><i class="bi bi-person-plus me-1"></i>Enlist cadet</a>
    <?php endif; ?>
  </div>
</div>

<form class="row g-2 mb-3" method="get" role="search">
  <div class="col-12 col-md-6">
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="search" class="form-control" name="q" id="rosterSearch"
             placeholder="Search service no. or name…" value="<?= e($q) ?>"
             autocomplete="off" aria-label="Search cadets">
      <?php if ($q !== ''): ?>
        <a class="btn btn-outline-secondary" href="<?= e(purl('cadets/list.php')) ?>" title="Clear"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <select class="form-select" name="status" onchange="this.form.submit()" aria-label="Filter by status">
      <option value="">All statuses</option>
      <?php foreach ($validStatuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-3 d-grid">
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
  </div>
</form>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No cadets match your search yet." id="rosterTable">
      <thead class="table-dark">
        <tr>
          <th>Service no.</th>
          <th>Full name</th>
          <th class="d-none d-md-table-cell">Rank</th>
          <th class="d-none d-lg-table-cell">Department</th>
          <th>Status</th>
          <th class="d-none d-xl-table-cell">Enrolled</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cadets as $c): ?>
        <tr>
          <td class="font-monospace small"><?= e($c['service_no']) ?></td>
          <td>
            <strong><?= e($c['full_name']) ?></strong>
            <div class="small text-muted d-md-none"><?= e($c['current_rank']) ?></div>
          </td>
          <td class="d-none d-md-table-cell"><span class="badge text-bg-dark"><?= e($c['current_rank']) ?></span></td>
          <td class="d-none d-lg-table-cell small"><?= e($c['department'] ?? '—') ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td class="d-none d-xl-table-cell small"><?= format_date($c['enrolled_date']) ?></td>
          <td class="text-end text-nowrap">
            <div class="btn-group" role="group">
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$c['id'] ?>" title="View dossier">
                <i class="bi bi-eye"></i>
              </a>
              <?php if (canEdit()): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(purl('cadets/create.php')) ?>?edit=<?= (int)$c['id'] ?>" title="Edit">
                  <i class="bi bi-pencil"></i>
                </a>
              <?php endif; ?>
              <?php if (canDelete()): ?>
                <form method="post" action="<?= e(purl('cadets/list.php')) ?>" class="d-inline"
                      data-confirm="Permanently delete <?= e($c['full_name']) ?> and ALL related records?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$cadets): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">
          <i class="bi bi-inbox display-6 d-block mb-2"></i>No cadets found.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
