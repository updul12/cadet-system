<?php
/**
 * Attendance — mark parade attendance per event name + date.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();
requireModule('records');

$errors = [];

/* Bulk mark attendance for an event session */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = requireRole(['Admin', 'Officer']);
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $user = requireRole(['Admin']);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id, event_name FROM attendance WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $del = db()->prepare('DELETE FROM attendance WHERE id = ?');
            $del->execute([$id]);
            audit_log('attendance_delete', 'attendance', $id, $row['event_name']);
            flash('success', 'Attendance mark removed.');
        } else {
            flash('warning', 'Record not found.');
        }
        redirect(purl('operations/attendance.php'));
    }

    /* mark: one POST per cadet status, or bulk with statuses[cadet_id] */
    $eventName = trim((string)($_POST['event_name'] ?? ''));
    $eventDate = (string)($_POST['event_date'] ?? '');
    $marks     = $_POST['marks'] ?? [];

    if ($eventName === '' || mb_strlen($eventName) > 120) {
        $errors[] = 'Event name is required (max 120 chars).';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || !strtotime($eventDate)) {
        $errors[] = 'Valid event date is required.';
    }
    if (!is_array($marks) || !$marks) {
        $errors[] = 'Select at least one cadet to mark.';
    }

    $validStatus = ['Present', 'Absent', 'Excused'];
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT id FROM cadets WHERE id = ?');
            $ins = $pdo->prepare(
                'INSERT INTO attendance (cadet_id, event_name, event_date, status)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status)'
            );
            $count = 0;
            foreach ($marks as $cadetId => $status) {
                $cadetId = (int)$cadetId;
                if (!in_array((string)$status, $validStatus, true) || $cadetId <= 0) {
                    continue;
                }
                $sel->execute([$cadetId]);
                if (!$sel->fetch()) {
                    continue;
                }
                $ins->execute([$cadetId, $eventName, $eventDate, (string)$status]);
                $count++;
            }
            $pdo->commit();
            audit_log('attendance_mark', 'attendance', null,
                $eventName . ' @ ' . $eventDate . ' — ' . $count . ' marks');
            flash('success', $count . ' attendance mark(s) saved for “' . $eventName . '”.');
            redirect(purl('operations/attendance.php') . '?event_name=' . urlencode($eventName) . '&event_date=' . urlencode($eventDate));
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }
}

/* Filters */
$eventDate = (string)($_GET['event_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    $eventDate = date('Y-m-d');
}
$eventName = trim((string)($_GET['event_name'] ?? ''));

/* Existing marks for selected session */
$marksExisting = [];
if ($eventName !== '') {
    $stmt = db()->prepare(
        'SELECT cadet_id, status FROM attendance WHERE event_name = ? AND event_date = ?'
    );
    $stmt->execute([$eventName, $eventDate]);
    foreach ($stmt->fetchAll() as $row) {
        $marksExisting[(int)$row['cadet_id']] = $row['status'];
    }
}

/* Cadet roll for marking */
$roll = db()->query(
    "SELECT id, service_no, full_name, current_rank FROM cadets WHERE status = 'Active' ORDER BY full_name"
)->fetchAll();

/* Recent attendance history */
$history = db()->query(
    'SELECT a.id, a.event_name, a.event_date, a.status, c.full_name, c.service_no
       FROM attendance a JOIN cadets c ON c.id = a.cadet_id
      ORDER BY a.event_date DESC, a.id DESC LIMIT 100'
)->fetchAll();

/* CSV export of history */
if (isset($_GET['export'])) {
    audit_log('attendance_export', 'attendance', null, count($history) . ' rows');
    send_csv(
        'attendance_' . date('Ymd') . '.csv',
        ['event_name', 'event_date', 'service_no', 'full_name', 'status'],
        array_map(static fn(array $r): array => [
            $r['event_name'], $r['event_date'], $r['service_no'], $r['full_name'], $r['status'],
        ], $history)
    );
}

$page_title = 'Attendance';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-clipboard-check me-2"></i>Attendance</h1>
    <p class="text-muted mb-0">Parade &amp; muster marks</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(purl('operations/attendance.php')) ?>?export=1">
    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
  </a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (canEdit()): ?>
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-pencil-square me-2"></i>Mark attendance</div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3">
      <div class="col-12 col-md-5">
        <label class="form-label" for="a_event">Event name</label>
        <input class="form-control" id="a_event" name="event_name" maxlength="120"
               value="<?= e($eventName) ?>" placeholder="Weekly Parade">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="a_date">Date</label>
        <input class="form-control" type="date" id="a_date" name="event_date" value="<?= e($eventDate) ?>">
      </div>
      <div class="col-6 col-md-4 d-flex align-items-end">
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>Load session</button>
      </div>
    </form>

    <?php if ($eventName !== ''): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark">
      <input type="hidden" name="event_name" value="<?= e($eventName) ?>">
      <input type="hidden" name="event_date" value="<?= e($eventDate) ?>">
      <div class="alert alert-info py-2 small">
        <i class="bi bi-info-circle me-1"></i>
        Marking <strong><?= e($eventName) ?></strong> on <strong><?= format_date($eventDate) ?></strong>.
        Existing marks are pre-selected.
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle" data-empty="No active cadets to mark.">
          <thead class="table-light">
            <tr><th>Cadet</th><th style="min-width:260px">Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($roll as $c): ?>
              <?php $cur = $marksExisting[(int)$c['id']] ?? null; ?>
              <tr>
                <td>
                  <strong><?= e($c['full_name']) ?></strong>
                  <div class="small text-muted"><?= e($c['service_no']) ?> · <?= e($c['current_rank']) ?></div>
                </td>
                <td>
                  <div class="btn-group" role="group" aria-label="Status for <?= e($c['full_name']) ?>">
                    <?php foreach (['Present', 'Absent', 'Excused'] as $st): ?>
                      <?php
                        $checked = ($cur === $st) || ($cur === null && $st === 'Present');
                        $name = 'marks[' . (int)$c['id'] . ']';
                      ?>
                      <input type="radio" class="btn-check" name="<?= e($name) ?>" id="m<?= (int)$c['id'] ?>_<?= e($st) ?>"
                             value="<?= e($st) ?>" <?= $checked ? 'checked' : '' ?>>
                      <label class="btn btn-sm btn-outline-<?= $st === 'Present' ? 'success' : ($st === 'Absent' ? 'danger' : 'warning') ?>"
                             for="m<?= (int)$c['id'] ?>_<?= e($st) ?>"><?= e($st) ?></label>
                    <?php endforeach; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$roll): ?>
              <tr><td colspan="2" class="text-muted">No active cadets on the roster.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($roll): ?>
        <button class="btn btn-success" type="submit"><i class="bi bi-check2-all me-1"></i>Save all marks</button>
      <?php endif; ?>
    </form>
    <?php else: ?>
      <p class="text-muted mb-0 small">Enter an event name and date to load the roll.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-2"></i>Recent marks</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No attendance records yet.">
      <thead class="table-dark">
        <tr>
          <th>Date</th>
          <th>Event</th>
          <th>Cadet</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td class="small text-nowrap"><?= format_date($h['event_date']) ?></td>
            <td class="small"><?= e($h['event_name']) ?></td>
            <td>
              <strong><?= e($h['full_name']) ?></strong>
              <div class="small text-muted font-monospace"><?= e($h['service_no']) ?></div>
            </td>
            <td><?= status_badge($h['status']) ?></td>
            <td class="text-end">
              <?php if (canDelete()): ?>
                <form method="post" class="d-inline" data-confirm="Remove this attendance mark?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$history): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No attendance recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
