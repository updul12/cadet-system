<?php
/**
 * Discipline & awards — commendations and offences.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();
requireModule('records');

$errors = [];
$TYPES = ['Commendation', 'Offense'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = requireRole(['Admin', 'Officer']);
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $user = requireRole(['Admin']);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id, description FROM discipline_awards WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $del = db()->prepare('DELETE FROM discipline_awards WHERE id = ?');
            $del->execute([$id]);
            audit_log('discipline_delete', 'discipline_awards', $id, $row['description']);
            flash('success', 'Record deleted.');
        } else {
            flash('warning', 'Record not found.');
        }
        redirect(purl('operations/discipline.php'));
    }

    /* create */
    $cadetId    = (int)($_POST['cadet_id'] ?? 0);
    $recordDate = (string)($_POST['record_date'] ?? '');
    $recordType = (string)($_POST['record_type'] ?? '');
    $description = trim((string)($_POST['description'] ?? ''));
    $actionTaken = trim((string)($_POST['action_taken'] ?? ''));
    $issuedBy    = trim((string)($_POST['issued_by'] ?? ''));

    $stmt = db()->prepare('SELECT id, full_name FROM cadets WHERE id = ?');
    $stmt->execute([$cadetId]);
    $cadet = $stmt->fetch();

    if (!$cadet) {
        $errors[] = 'Choose a valid cadet.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate) || !strtotime($recordDate)) {
        $errors[] = 'Valid record date is required.';
    }
    if (!in_array($recordType, $TYPES, true)) {
        $errors[] = 'Record type must be Commendation or Offense.';
    }
    if ($description === '' || mb_strlen($description) > 255) {
        $errors[] = 'Description is required (max 255 chars).';
    }
    if (mb_strlen($actionTaken) > 255) {
        $errors[] = 'Action taken too long (max 255).';
    }
    if (mb_strlen($issuedBy) > 100) {
        $errors[] = 'Issued by too long (max 100).';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO discipline_awards (cadet_id, record_date, record_type, description, action_taken, issued_by)
             VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([
            $cadetId, $recordDate, $recordType, $description,
            $actionTaken !== '' ? $actionTaken : null,
            $issuedBy !== '' ? $issuedBy : null,
        ]);
        $newId = (int)db()->lastInsertId();
        audit_log('discipline_create', 'discipline_awards', $newId,
            $cadet['full_name'] . ' ' . $recordType);
        flash('success', $recordType . ' recorded for ' . $cadet['full_name'] . '.');
        redirect(purl('operations/discipline.php'));
    }
}

/* Filters */
$typeF = (string)($_GET['type'] ?? '');
$sql = 'SELECT d.*, c.full_name, c.service_no
          FROM discipline_awards d JOIN cadets c ON c.id = d.cadet_id';
$args = [];
if (in_array($typeF, $TYPES, true)) {
    $sql .= ' WHERE d.record_type = ?';
    $args[] = $typeF;
}
$sql .= ' ORDER BY d.record_date DESC, d.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$records = $stmt->fetchAll();

$cadets = db()->query('SELECT id, service_no, full_name FROM cadets ORDER BY full_name')->fetchAll();

$page_title = 'Discipline & awards';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-clipboard-data me-2"></i>Discipline & awards</h1>
    <p class="text-muted mb-0"><?= count($records) ?> record(s)</p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(purl('operations/discipline.php')) ?>">All</a>
    <a class="btn btn-outline-success btn-sm" href="<?= e(purl('operations/discipline.php')) ?>?type=Commendation">Commendations</a>
    <a class="btn btn-outline-danger btn-sm" href="<?= e(purl('operations/discipline.php')) ?>?type=Offense">Offences</a>
    <?php if (canEdit()): ?>
      <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#discForm">
        <i class="bi bi-plus-lg me-1"></i>New record
      </button>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (canEdit()): ?>
<div class="collapse <?= $errors ? 'show' : '' ?>" id="discForm">
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">New record</h2>
      <form method="post" class="row g-3 needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12 col-md-4">
          <label class="form-label" for="d_cadet">Cadet *</label>
          <select class="form-select" id="d_cadet" name="cadet_id" required>
            <option value="">— select —</option>
            <?php foreach ($cadets as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['full_name'] . ' · ' . $c['service_no']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="d_date">Date *</label>
          <input class="form-control" type="date" id="d_date" name="record_date" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="d_type">Type *</label>
          <select class="form-select" id="d_type" name="record_type" required>
            <option value="Commendation">Commendation</option>
            <option value="Offense">Offense</option>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label" for="d_issued">Issued by</label>
          <input class="form-control" id="d_issued" name="issued_by" maxlength="100"
                 placeholder="Capt. M. Ochieng">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="d_desc">Description *</label>
          <textarea class="form-control" id="d_desc" name="description" required rows="2"
                    maxlength="255" placeholder="What happened / what is being recognised?"></textarea>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="d_action">Action taken</label>
          <textarea class="form-control" id="d_action" name="action_taken" rows="2"
                    maxlength="255" placeholder="Warning, commendation letter, extra duties…"></textarea>
        </div>
        <div class="col-12">
          <button class="btn btn-success" type="submit"><i class="bi bi-check-lg me-1"></i>Save record</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No discipline records yet.">
      <thead class="table-dark">
        <tr>
          <th>Date</th>
          <th>Cadet</th>
          <th>Type</th>
          <th>Description</th>
          <th class="d-none d-lg-table-cell">Action</th>
          <th class="d-none d-xl-table-cell">Issued by</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
          <tr>
            <td class="small text-nowrap"><?= format_date($r['record_date']) ?></td>
            <td class="small">
              <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$r['cadet_id'] ?>" class="text-decoration-none">
                <strong><?= e($r['full_name']) ?></strong>
              </a>
              <div class="text-muted font-monospace"><?= e($r['service_no']) ?></div>
            </td>
            <td><?= status_badge($r['record_type']) ?></td>
            <td class="small"><?= e($r['description']) ?></td>
            <td class="d-none d-lg-table-cell small text-muted"><?= e($r['action_taken'] ?? '—') ?></td>
            <td class="d-none d-xl-table-cell small text-muted"><?= e($r['issued_by'] ?? '—') ?></td>
            <td class="text-end">
              <?php if (canDelete()): ?>
                <form method="post" class="d-inline" data-confirm="Delete this record?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No discipline records.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
