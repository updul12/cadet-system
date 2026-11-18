<?php
/**
 * Duty roster — shift assignments.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();
requireModule('operations');

$errors = [];
$STATUSES = ['Pending', 'Completed', 'Excused'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = requireRole(['Admin', 'Officer']);
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $user = requireRole(['Admin']);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id, duty_name FROM duty_roster WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $del = db()->prepare('DELETE FROM duty_roster WHERE id = ?');
            $del->execute([$id]);
            audit_log('duty_delete', 'duty_roster', $id, $row['duty_name']);
            flash('success', 'Duty removed.');
        } else {
            flash('warning', 'Duty not found.');
        }
        redirect(purl('operations/duties.php'));
    }

    if ($action === 'status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, $STATUSES, true)) {
            flash('danger', 'Invalid status.');
            redirect(purl('operations/duties.php'));
        }
        $stmt = db()->prepare('SELECT id, duty_name FROM duty_roster WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash('warning', 'Duty not found.');
            redirect(purl('operations/duties.php'));
        }
        $upd = db()->prepare('UPDATE duty_roster SET status = ? WHERE id = ?');
        $upd->execute([$status, $id]);
        audit_log('duty_status', 'duty_roster', $id, $row['duty_name'] . ' → ' . $status);
        flash('success', 'Duty status updated to ' . $status . '.');
        redirect(purl('operations/duties.php'));
    }

    /* create */
    $dutyName   = trim((string)($_POST['duty_name'] ?? ''));
    $cadetId    = (int)($_POST['assigned_cadet_id'] ?? 0);
    $dutyDate   = (string)($_POST['duty_date'] ?? '');
    $shiftTime  = trim((string)($_POST['shift_time'] ?? ''));
    $status     = (string)($_POST['status'] ?? 'Pending');

    if ($dutyName === '' || mb_strlen($dutyName) > 120) {
        $errors[] = 'Duty name is required (max 120 chars).';
    }
    if ($cadetId <= 0) {
        $errors[] = 'Assign a cadet.';
    } else {
        $stmt = db()->prepare('SELECT id FROM cadets WHERE id = ?');
        $stmt->execute([$cadetId]);
        if (!$stmt->fetch()) {
            $errors[] = 'Cadet not found.';
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dutyDate) || !strtotime($dutyDate)) {
        $errors[] = 'Valid duty date is required.';
    }
    if ($shiftTime === '' || mb_strlen($shiftTime) > 60) {
        $errors[] = 'Shift time is required (e.g. 06:00-14:00).';
    }
    if (!in_array($status, $STATUSES, true)) {
        $errors[] = 'Invalid status.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO duty_roster (duty_name, assigned_cadet_id, duty_date, shift_time, status)
             VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$dutyName, $cadetId, $dutyDate, $shiftTime, $status]);
        $newId = (int)db()->lastInsertId();
        audit_log('duty_create', 'duty_roster', $newId, $dutyName . ' @ ' . $dutyDate);
        flash('success', 'Duty “' . $dutyName . '” assigned.');
        redirect(purl('operations/duties.php'));
    }
}

/* Filters: from date, status */
$from   = (string)($_GET['from'] ?? '');
$statusF = (string)($_GET['status'] ?? '');
$sql = 'SELECT d.*, c.full_name, c.service_no, c.current_rank
          FROM duty_roster d JOIN cadets c ON c.id = d.assigned_cadet_id';
$where = [];
$args = [];
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'd.duty_date >= ?';
    $args[] = $from;
}
if (in_array($statusF, $STATUSES, true)) {
    $where[] = 'd.status = ?';
    $args[] = $statusF;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY d.duty_date DESC, d.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$duties = $stmt->fetchAll();

$cadets = db()->query(
    "SELECT id, service_no, full_name FROM cadets WHERE status = 'Active' ORDER BY full_name"
)->fetchAll();

$page_title = 'Duty roster';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-calendar-week me-2"></i>Duty roster</h1>
    <p class="text-muted mb-0"><?= count($duties) ?> shift(s)</p>
  </div>
  <?php if (canEdit()): ?>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#dutyForm">
      <i class="bi bi-plus-lg me-1"></i>Assign duty
    </button>
  <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-6 col-md-3">
    <label class="visually-hidden" for="d_from">From date</label>
    <input class="form-control" type="date" id="d_from" name="from" value="<?= e($from) ?>">
  </div>
  <div class="col-6 col-md-3">
    <label class="visually-hidden" for="d_status">Status</label>
    <select class="form-select" id="d_status" name="status">
      <option value="">All statuses</option>
      <?php foreach ($STATUSES as $s): ?>
        <option value="<?= e($s) ?>" <?= $statusF === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-2 d-grid">
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
  </div>
</form>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (canEdit()): ?>
<div class="collapse <?= $errors ? 'show' : '' ?>" id="dutyForm">
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">New duty assignment</h2>
      <form method="post" class="row g-3 needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12 col-md-3">
          <label class="form-label" for="du_name">Duty name *</label>
          <input class="form-control" id="du_name" name="duty_name" required maxlength="120"
                 placeholder="Gate guard, Parade orderly…">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label" for="du_cadet">Cadet *</label>
          <select class="form-select" id="du_cadet" name="assigned_cadet_id" required>
            <option value="">— select —</option>
            <?php foreach ($cadets as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['full_name'] . ' · ' . $c['service_no']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="du_date">Date *</label>
          <input class="form-control" type="date" id="du_date" name="duty_date" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="du_shift">Shift *</label>
          <input class="form-control" id="du_shift" name="shift_time" required maxlength="60"
                 placeholder="06:00-14:00">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="du_status">Status *</label>
          <select class="form-select" id="du_status" name="status" required>
            <?php foreach ($STATUSES as $s): ?>
              <option value="<?= e($s) ?>" <?= $s === 'Pending' ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <button class="btn btn-success" type="submit"><i class="bi bi-check-lg me-1"></i>Assign</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No duties assigned yet.">
      <thead class="table-dark">
        <tr>
          <th>Date</th>
          <th>Duty</th>
          <th>Cadet</th>
          <th>Shift</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($duties as $d): ?>
          <tr>
            <td class="small text-nowrap"><?= format_date($d['duty_date']) ?></td>
            <td><strong><?= e($d['duty_name']) ?></strong></td>
            <td class="small">
              <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$d['assigned_cadet_id'] ?>" class="text-decoration-none">
                <?= e($d['full_name']) ?>
              </a>
              <div class="text-muted font-monospace"><?= e($d['service_no']) ?></div>
            </td>
            <td class="small font-monospace"><?= e($d['shift_time']) ?></td>
            <td>
              <?php if (canEdit()): ?>
                <form method="post" class="d-flex align-items-center gap-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    <?php foreach ($STATUSES as $s): ?>
                      <option value="<?= e($s) ?>" <?= $d['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?>
                <?= status_badge($d['status']) ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if (canDelete()): ?>
                <form method="post" class="d-inline" data-confirm="Delete this duty assignment?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$duties): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No duties scheduled.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
