<?php
/**
 * Promotions — record and track rank advancements.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();
requireModule('records');

$errors = [];
$prefillCadet = (int)($_GET['cadet_id'] ?? $_POST['cadet_id'] ?? 0);

$rankRows = db()->query(
    'SELECT rank_name, abbreviation, category FROM cadet_ranks ORDER BY seniority_level'
)->fetchAll();
$rankNames = array_column($rankRows, 'rank_name');

/* Create promotion (Admin + Officer) */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = requireRole(['Admin', 'Officer']);
    csrf_check();
    $action = (string)($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $user = requireRole(['Admin']);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id FROM rank_history WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            $del = db()->prepare('DELETE FROM rank_history WHERE id = ?');
            $del->execute([$id]);
            audit_log('promotion_delete', 'rank_history', $id);
            flash('success', 'Promotion record removed.');
        } else {
            flash('warning', 'Record not found.');
        }
        redirect(purl('operations/promotions.php'));
    }

    /* create */
    $cadetId = (int)($_POST['cadet_id'] ?? 0);
    $prev    = trim((string)($_POST['previous_rank'] ?? ''));
    $new     = trim((string)($_POST['new_rank'] ?? ''));
    $date    = (string)($_POST['promotion_date'] ?? '');
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $stmt = db()->prepare('SELECT id, full_name, current_rank FROM cadets WHERE id = ?');
    $stmt->execute([$cadetId]);
    $cadet = $stmt->fetch();

    if (!$cadet) {
        $errors[] = 'Choose a valid cadet.';
    }
    if ($prev === '' || mb_strlen($prev) > 60) {
        $errors[] = 'Previous rank is required (max 60 chars).';
    } elseif (!in_array($prev, $rankNames, true)
              && !($cadet && $prev === $cadet['current_rank'])) {
        $errors[] = 'Previous rank must be a valid rank from the list.';
    }
    if ($new === '' || mb_strlen($new) > 60) {
        $errors[] = 'New rank is required (max 60 chars).';
    } elseif (!in_array($new, $rankNames, true)) {
        $errors[] = 'New rank must be a valid rank from the list.';
    } elseif ($cadet && $new === $cadet['current_rank']) {
        $errors[] = 'The cadet already holds that rank.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        $errors[] = 'Valid promotion date is required.';
    }
    if (mb_strlen($remarks) > 255) {
        $errors[] = 'Remarks must be 255 characters or fewer.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rank_history (cadet_id, previous_rank, new_rank, promotion_date, remarks)
                 VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$cadetId, $prev, $new, $date, $remarks !== '' ? $remarks : null]);
            $rid = (int)$pdo->lastInsertId();

            /* Keep dossier rank in sync */
            $upd = $pdo->prepare('UPDATE cadets SET current_rank = ? WHERE id = ?');
            $upd->execute([$new, $cadetId]);

            $pdo->commit();
            audit_log('promotion_create', 'rank_history', $rid,
                $cadet['full_name'] . ': ' . $prev . ' → ' . $new);
            flash('success', 'Promotion of ' . $cadet['full_name'] . ' to ' . $new . ' recorded.');
            redirect(purl('operations/promotions.php'));
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }
    $prefillCadet = $cadetId;
}

/* Lists */
$promotions = db()->query(
    'SELECT r.id, r.previous_rank, r.new_rank, r.promotion_date, r.remarks,
            c.id AS cadet_id, c.full_name, c.service_no
       FROM rank_history r JOIN cadets c ON c.id = r.cadet_id
      ORDER BY r.promotion_date DESC, r.id DESC'
)->fetchAll();

$cadets = db()->query(
    'SELECT id, service_no, full_name, current_rank FROM cadets ORDER BY full_name'
)->fetchAll();

$page_title = 'Promotions';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-award me-2"></i>Promotions</h1>
    <p class="text-muted mb-0"><?= count($promotions) ?> record(s)</p>
  </div>
  <?php if (canEdit()): ?>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#promoForm">
      <i class="bi bi-plus-lg me-1"></i>Record promotion
    </button>
  <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (canEdit()): ?>
<div class="collapse <?= $errors ? 'show' : '' ?>" id="promoForm">
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">New promotion entry</h2>
      <form method="post" class="row g-3 needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12 col-md-4">
          <label class="form-label" for="p_cadet">Cadet *</label>
          <select class="form-select" id="p_cadet" name="cadet_id" required>
            <option value="">— select —</option>
            <?php foreach ($cadets as $c): ?>
              <option value="<?= (int)$c['id'] ?>" data-rank="<?= e($c['current_rank']) ?>"
                      <?= $prefillCadet === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['full_name'] . ' · ' . $c['service_no']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="p_prev">Previous rank *</label>
          <select class="form-select" id="p_prev" name="previous_rank" required>
            <option value="">— select —</option>
            <?php foreach ($rankRows as $r): ?>
              <option value="<?= e($r['rank_name']) ?>"><?= e($r['rank_name']) ?> (<?= e($r['abbreviation']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="p_new">New rank *</label>
          <select class="form-select" id="p_new" name="new_rank" required>
            <option value="">— select —</option>
            <?php foreach ($rankRows as $r): ?>
              <option value="<?= e($r['rank_name']) ?>"><?= e($r['rank_name']) ?> (<?= e($r['abbreviation']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="p_date">Date *</label>
          <input class="form-control" type="date" id="p_date" name="promotion_date" required
                 value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-12 col-md-8">
          <label class="form-label" for="p_remarks">Remarks</label>
          <input class="form-control" id="p_remarks" name="remarks" maxlength="255"
                 placeholder="Meritorious service, examination pass…">
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end">
          <button class="btn btn-success w-100" type="submit"><i class="bi bi-check-lg me-1"></i>Record</button>
        </div>
      </form>
      <script>
      document.getElementById('p_cadet').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var rank = opt.getAttribute('data-rank') || '';
        if (!rank) return;
        var sel = document.getElementById('p_prev');
        var has = false;
        for (var i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value === rank) { has = true; break; }
        }
        if (!has) {
          var o = document.createElement('option');
          o.value = rank;
          o.textContent = rank;
          sel.appendChild(o);
        }
        sel.value = rank;
      });
      </script>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No promotion records yet.">
      <thead class="table-dark">
        <tr>
          <th>Date</th>
          <th>Cadet</th>
          <th>From</th>
          <th>To</th>
          <th class="d-none d-lg-table-cell">Remarks</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($promotions as $p): ?>
          <tr>
            <td class="small text-nowrap"><?= format_date($p['promotion_date']) ?></td>
            <td>
              <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$p['cadet_id'] ?>" class="text-decoration-none">
                <strong><?= e($p['full_name']) ?></strong>
              </a>
              <div class="small text-muted font-monospace"><?= e($p['service_no']) ?></div>
            </td>
            <td><span class="badge text-bg-secondary"><?= e($p['previous_rank']) ?></span></td>
            <td><span class="badge text-bg-primary"><?= e($p['new_rank']) ?></span></td>
            <td class="d-none d-lg-table-cell small text-muted"><?= e($p['remarks'] ?? '—') ?></td>
            <td class="text-end">
              <?php if (canDelete()): ?>
                <form method="post" class="d-inline" data-confirm="Delete this promotion record?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$promotions): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No promotions recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
