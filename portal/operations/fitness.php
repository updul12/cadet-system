<?php
/**
 * Fitness logs — record and track PT metrics.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();
requireModule('records');

$errors = [];
$prefillCadet = (int)($_GET['cadet_id'] ?? $_POST['cadet_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = requireRole(['Admin', 'Officer']);
    csrf_check();
    $action = (string)($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $user = requireRole(['Admin']);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id FROM fitness_logs WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            $del = db()->prepare('DELETE FROM fitness_logs WHERE id = ?');
            $del->execute([$id]);
            audit_log('fitness_delete', 'fitness_logs', $id);
            flash('success', 'Fitness record deleted.');
        } else {
            flash('warning', 'Record not found.');
        }
        redirect(purl('operations/fitness.php'));
    }

    $cadetId   = (int)($_POST['cadet_id'] ?? 0);
    $testDate  = (string)($_POST['test_date'] ?? '');
    $push      = $_POST['push_ups'] !== '' && $_POST['push_ups'] !== null ? (int)$_POST['push_ups'] : null;
    $sit       = $_POST['sit_ups'] !== '' && $_POST['sit_ups'] !== null ? (int)$_POST['sit_ups'] : null;
    $runMins   = $_POST['run_time_mins'] !== '' && $_POST['run_time_mins'] !== null ? (float)$_POST['run_time_mins'] : null;
    $grade     = (string)($_POST['overall_grade'] ?? 'C');
    $remarks   = trim((string)($_POST['remarks'] ?? ''));

    $stmt = db()->prepare('SELECT id, full_name FROM cadets WHERE id = ?');
    $stmt->execute([$cadetId]);
    $cadet = $stmt->fetch();

    if (!$cadet) {
        $errors[] = 'Choose a valid cadet.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $testDate) || !strtotime($testDate)) {
        $errors[] = 'Valid test date is required.';
    }
    if ($push !== null && ($push < 0 || $push > 500)) {
        $errors[] = 'Push-ups must be between 0 and 500.';
    }
    if ($sit !== null && ($sit < 0 || $sit > 500)) {
        $errors[] = 'Sit-ups must be between 0 and 500.';
    }
    if ($runMins !== null && ($runMins < 0 || $runMins > 120)) {
        $errors[] = 'Run time must be between 0 and 120 minutes.';
    }
    if (!in_array($grade, ['A', 'B', 'C', 'D'], true)) {
        $errors[] = 'Invalid grade.';
    }
    if (mb_strlen($remarks) > 255) {
        $errors[] = 'Remarks too long.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO fitness_logs (cadet_id, test_date, push_ups, sit_ups, run_time_mins, overall_grade, remarks)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([$cadetId, $testDate, $push, $sit, $runMins, $grade, $remarks !== '' ? $remarks : null]);
        $fid = (int)db()->lastInsertId();
        audit_log('fitness_create', 'fitness_logs', $fid, $cadet['full_name'] . ' grade ' . $grade);
        flash('success', 'Fitness test for ' . $cadet['full_name'] . ' recorded.');
        redirect(purl('operations/fitness.php'));
    }
    $prefillCadet = $cadetId;
}

/* Filter by cadet */
$filterCadet = (int)($_GET['cadet_id'] ?? 0);
$sql = 'SELECT f.id, f.test_date, f.push_ups, f.sit_ups, f.run_time_mins, f.overall_grade, f.remarks,
               c.id AS cadet_id, c.full_name, c.service_no
          FROM fitness_logs f JOIN cadets c ON c.id = f.cadet_id';
$args = [];
if ($filterCadet > 0) {
    $sql .= ' WHERE f.cadet_id = ?';
    $args[] = $filterCadet;
}
$sql .= ' ORDER BY f.test_date DESC, f.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$logs = $stmt->fetchAll();

$cadets = db()->query('SELECT id, service_no, full_name FROM cadets ORDER BY full_name')->fetchAll();

$page_title = 'Fitness logs';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-activity me-2"></i>Fitness logs</h1>
    <p class="text-muted mb-0"><?= count($logs) ?> record(s)</p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($filterCadet > 0): ?>
      <a class="btn btn-outline-secondary" href="<?= e(purl('operations/fitness.php')) ?>"><i class="bi bi-x-lg me-1"></i>Clear filter</a>
    <?php endif; ?>
    <?php if (canEdit()): ?>
      <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#fitForm">
        <i class="bi bi-plus-lg me-1"></i>Log test
      </button>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (canEdit()): ?>
<div class="collapse <?= $errors ? 'show' : '' ?>" id="fitForm">
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">New fitness test</h2>
      <form method="post" class="row g-3 needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12 col-md-4">
          <label class="form-label" for="f_cadet">Cadet *</label>
          <select class="form-select" id="f_cadet" name="cadet_id" required>
            <option value="">— select —</option>
            <?php foreach ($cadets as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $prefillCadet === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['full_name'] . ' · ' . $c['service_no']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="f_date">Date *</label>
          <input class="form-control" type="date" id="f_date" name="test_date" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label" for="f_push">Push-ups</label>
          <input class="form-control" type="number" id="f_push" name="push_ups" min="0" max="500" inputmode="numeric">
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label" for="f_sit">Sit-ups</label>
          <input class="form-control" type="number" id="f_sit" name="sit_ups" min="0" max="500" inputmode="numeric">
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label" for="f_run">Run (min)</label>
          <input class="form-control" type="number" id="f_run" name="run_time_mins" min="0" max="120"
                 step="0.01" inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="f_grade">Grade *</label>
          <select class="form-select" id="f_grade" name="overall_grade" required>
            <?php foreach (['A', 'B', 'C', 'D'] as $g): ?>
              <option value="<?= e($g) ?>" <?= $g === 'C' ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-8">
          <label class="form-label" for="f_remarks">Remarks</label>
          <input class="form-control" id="f_remarks" name="remarks" maxlength="255">
        </div>
        <div class="col-12 col-md-2 d-flex align-items-end">
          <button class="btn btn-success w-100" type="submit"><i class="bi bi-check-lg me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No fitness tests logged yet.">
      <thead class="table-dark">
        <tr>
          <th>Date</th>
          <th>Cadet</th>
          <th class="text-center">Push-ups</th>
          <th class="text-center">Sit-ups</th>
          <th class="text-center">Run (min)</th>
          <th>Grade</th>
          <th class="d-none d-lg-table-cell">Remarks</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $f): ?>
          <tr>
            <td class="small text-nowrap"><?= format_date($f['test_date']) ?></td>
            <td>
              <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$f['cadet_id'] ?>" class="text-decoration-none">
                <?= e($f['full_name']) ?>
              </a>
            </td>
            <td class="text-center"><?= $f['push_ups'] !== null ? (int)$f['push_ups'] : '—' ?></td>
            <td class="text-center"><?= $f['sit_ups'] !== null ? (int)$f['sit_ups'] : '—' ?></td>
            <td class="text-center"><?= $f['run_time_mins'] !== null ? e((string)$f['run_time_mins']) : '—' ?></td>
            <td><?= grade_badge($f['overall_grade']) ?></td>
            <td class="d-none d-lg-table-cell small text-muted"><?= e($f['remarks'] ?? '—') ?></td>
            <td class="text-end">
              <?php if (canDelete()): ?>
                <form method="post" class="d-inline" data-confirm="Delete this fitness record?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No fitness records.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
