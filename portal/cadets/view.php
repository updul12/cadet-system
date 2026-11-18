<?php
/**
 * Single-cadet dossier — personal info, rank history, fitness logs, inventory.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect(purl('index.php'));
}

/* Linked from every module — any grant is enough. With no grants
 * a user may still open their own linked dossier. */
if (allowed_modules() === [] && (int)($user['cadet_id'] ?? 0) !== $id) {
    requireModule('cadets');
}

$stmt = db()->prepare('SELECT * FROM cadets WHERE id = ?');
$stmt->execute([$id]);
$cadet = $stmt->fetch();
if (!$cadet) {
    flash('warning', 'Cadet not found.');
    redirect(purl('cadets/list.php'));
}

$rankHistory = db()->prepare(
    'SELECT previous_rank, new_rank, promotion_date, remarks FROM rank_history
      WHERE cadet_id = ? ORDER BY promotion_date DESC, id DESC'
);
$rankHistory->execute([$id]);
$rankHistory = $rankHistory->fetchAll();

$fitness = db()->prepare(
    'SELECT test_date, push_ups, sit_ups, run_time_mins, overall_grade, remarks
       FROM fitness_logs WHERE cadet_id = ? ORDER BY test_date DESC, id DESC LIMIT 20'
);
$fitness->execute([$id]);
$fitness = $fitness->fetchAll();

$inventory = db()->prepare(
    'SELECT item_name, serial_no, category, item_condition, date_issued, date_returned
       FROM inventory WHERE assigned_cadet_id = ? ORDER BY date_issued DESC'
);
$inventory->execute([$id]);
$inventory = $inventory->fetchAll();

$attendance = db()->prepare(
    'SELECT event_name, event_date, status FROM attendance
      WHERE cadet_id = ? ORDER BY event_date DESC LIMIT 10'
);
$attendance->execute([$id]);
$attendance = $attendance->fetchAll();

$discipline = db()->prepare(
    'SELECT record_date, record_type, description, action_taken, issued_by
       FROM discipline_awards WHERE cadet_id = ? ORDER BY record_date DESC, id DESC'
);
$discipline->execute([$id]);
$discipline = $discipline->fetchAll();

$photoUrl = null;
if (!empty($cadet['photo'])
    && preg_match('#^storage/photos/[a-f0-9]{32}\.(jpg|png|webp)$#', $cadet['photo'])) {
    $photoUrl = $cadet['photo'];
}

$page_title = $cadet['full_name'];
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<nav aria-label="breadcrumb">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(purl('cadets/list.php')) ?>">Roster</a></li>
    <li class="breadcrumb-item active"><?= e($cadet['full_name']) ?></li>
  </ol>
</nav>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div class="d-flex align-items-center gap-3">
    <?php if ($photoUrl): ?>
      <img src="<?= e($photoUrl) ?>" alt="Photo of <?= e($cadet['full_name']) ?>"
           class="rounded-circle border" width="72" height="72" style="object-fit:cover">
    <?php else: ?>
      <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-center"
           style="width:72px;height:72px;font-size:1.75rem">
        <i class="bi bi-person"></i>
      </div>
    <?php endif; ?>
    <div>
      <h1 class="h3 mb-1"><?= e($cadet['full_name']) ?></h1>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="badge text-bg-dark font-monospace"><?= e($cadet['service_no']) ?></span>
        <span class="badge text-bg-primary"><?= e($cadet['current_rank']) ?></span>
        <?= status_badge($cadet['status']) ?>
        <?php if (!empty($cadet['current_role'])): ?>
          <span class="badge text-bg-light border"><?= e($cadet['current_role']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2">
    <?php if (canEdit()): ?>
      <a class="btn btn-outline-primary" href="<?= e(purl('cadets/create.php')) ?>?edit=<?= (int)$cadet['id'] ?>">
        <i class="bi bi-pencil me-1"></i>Edit
      </a>
      <a class="btn btn-outline-success" href="<?= e(purl('operations/promotions.php')) ?>?cadet_id=<?= (int)$cadet['id'] ?>">
        <i class="bi bi-award me-1"></i>Promote
      </a>
      <a class="btn btn-outline-info" href="<?= e(purl('operations/fitness.php')) ?>?cadet_id=<?= (int)$cadet['id'] ?>">
        <i class="bi bi-activity me-1"></i>Log fitness
      </a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= e(purl('cadets/list.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Roster</a>
  </div>
</div>

<div class="row g-3">
  <!-- Personal info -->
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-person-vcard me-2"></i>Personal information</div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-5 text-muted">Service no.</dt><dd class="col-7 font-monospace"><?= e($cadet['service_no']) ?></dd>
          <dt class="col-5 text-muted">Gender</dt><dd class="col-7"><?= e($cadet['gender']) ?></dd>
          <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($cadet['phone'] ?: '—') ?></dd>
          <dt class="col-5 text-muted">Email</dt><dd class="col-7 text-break"><?= e($cadet['email'] ?: '—') ?></dd>
          <dt class="col-5 text-muted">Department</dt><dd class="col-7"><?= e($cadet['department'] ?: '—') ?></dd>
          <dt class="col-5 text-muted">Enrolled</dt><dd class="col-7"><?= format_date($cadet['enrolled_date']) ?></dd>
          <dt class="col-5 text-muted">Rank</dt><dd class="col-7"><?= e($cadet['current_rank']) ?></dd>
          <dt class="col-5 text-muted">Unit role</dt><dd class="col-7"><?= e($cadet['current_role'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>

    <!-- Attendance snapshot -->
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-2"></i>Recent attendance</div>
      <div class="list-group list-group-flush">
        <?php foreach ($attendance as $a): ?>
          <div class="list-group-item d-flex justify-content-between align-items-center py-2">
            <div class="small">
              <strong><?= e($a['event_name']) ?></strong>
              <div class="text-muted"><?= format_date($a['event_date']) ?></div>
            </div>
            <?= status_badge($a['status']) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$attendance): ?>
          <div class="list-group-item text-muted small">No attendance records.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right column tabs -->
  <div class="col-12 col-lg-8">
    <ul class="nav nav-tabs" role="tablist" id="dossierTabs">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabRanks" type="button" role="tab">
          <i class="bi bi-award me-1"></i>Rank history
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabFitness" type="button" role="tab">
          <i class="bi bi-activity me-1"></i>Fitness
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabInv" type="button" role="tab">
          <i class="bi bi-box-seam me-1"></i>Inventory
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDisc" type="button" role="tab">
          <i class="bi bi-clipboard-data me-1"></i>Discipline
        </button>
      </li>
    </ul>

    <div class="tab-content pt-3">
      <!-- Rank history -->
      <div class="tab-pane fade show active" id="tabRanks" role="tabpanel">
        <div class="card shadow-sm border-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr><th>Date</th><th>From</th><th>To</th><th>Remarks</th></tr>
              </thead>
              <tbody>
                <?php foreach ($rankHistory as $r): ?>
                  <tr>
                    <td class="small text-nowrap"><?= format_date($r['promotion_date']) ?></td>
                    <td><span class="badge text-bg-secondary"><?= e($r['previous_rank']) ?></span></td>
                    <td><span class="badge text-bg-primary"><?= e($r['new_rank']) ?></span></td>
                    <td class="small text-muted"><?= e($r['remarks'] ?? '—') ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$rankHistory): ?>
                  <tr><td colspan="4" class="text-center text-muted py-3">No promotions recorded.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Fitness -->
      <div class="tab-pane fade" id="tabFitness" role="tabpanel">
        <div class="card shadow-sm border-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr><th>Date</th><th class="text-center">Push-ups</th><th class="text-center">Sit-ups</th>
                    <th class="text-center">Run (min)</th><th>Grade</th><th class="d-none d-md-table-cell">Remarks</th></tr>
              </thead>
              <tbody>
                <?php foreach ($fitness as $f): ?>
                  <tr>
                    <td class="small text-nowrap"><?= format_date($f['test_date']) ?></td>
                    <td class="text-center"><?= $f['push_ups'] !== null ? (int)$f['push_ups'] : '—' ?></td>
                    <td class="text-center"><?= $f['sit_ups'] !== null ? (int)$f['sit_ups'] : '—' ?></td>
                    <td class="text-center"><?= $f['run_time_mins'] !== null ? e((string)$f['run_time_mins']) : '—' ?></td>
                    <td><?= grade_badge($f['overall_grade']) ?></td>
                    <td class="d-none d-md-table-cell small text-muted"><?= e($f['remarks'] ?? '—') ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$fitness): ?>
                  <tr><td colspan="6" class="text-center text-muted py-3">No fitness tests logged.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Inventory -->
      <div class="tab-pane fade" id="tabInv" role="tabpanel">
        <div class="card shadow-sm border-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr><th>Item</th><th>Serial</th><th>Category</th><th>Condition</th>
                    <th class="d-none d-md-table-cell">Issued</th><th class="d-none d-md-table-cell">Returned</th></tr>
              </thead>
              <tbody>
                <?php foreach ($inventory as $i): ?>
                  <tr>
                    <td><?= e($i['item_name']) ?></td>
                    <td class="font-monospace small"><?= e($i['serial_no']) ?></td>
                    <td class="small"><?= e($i['category']) ?></td>
                    <td><?= status_badge($i['item_condition']) ?></td>
                    <td class="d-none d-md-table-cell small"><?= format_date($i['date_issued']) ?></td>
                    <td class="d-none d-md-table-cell small"><?= format_date($i['date_returned']) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$inventory): ?>
                  <tr><td colspan="6" class="text-center text-muted py-3">No gear assigned.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Discipline -->
      <div class="tab-pane fade" id="tabDisc" role="tabpanel">
        <div class="card shadow-sm border-0">
          <div class="list-group list-group-flush">
            <?php foreach ($discipline as $d): ?>
              <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <strong><?= e($d['description']) ?></strong>
                    <div class="small text-muted">
                      <?= format_date($d['record_date']) ?>
                      <?php if ($d['action_taken']): ?> · Action: <?= e($d['action_taken']) ?><?php endif; ?>
                      <?php if ($d['issued_by']): ?> · By <?= e($d['issued_by']) ?><?php endif; ?>
                    </div>
                  </div>
                  <?= status_badge($d['record_type']) ?>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$discipline): ?>
              <div class="list-group-item text-muted">No discipline or commendation records.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
