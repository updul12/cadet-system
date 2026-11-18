<?php
/**
 * Add / edit cadet dossier — with secure photo upload.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

requireModule('cadets');
$user = requireRole(['Admin', 'Officer']);

$editId = (int)($_GET['edit'] ?? $_POST['edit'] ?? 0);
$cadet  = null;
$errors = [];
$oldPhoto = null;

if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM cadets WHERE id = ?');
    $stmt->execute([$editId]);
    $cadet = $stmt->fetch();
    if (!$cadet) {
        flash('warning', 'Cadet not found.');
        redirect(purl('cadets/list.php'));
    }
    $oldPhoto = $cadet['photo'] ?? null;
}

$rankRows = db()->query(
    'SELECT rank_name, abbreviation, category FROM cadet_ranks ORDER BY seniority_level'
)->fetchAll();
$rankNames = array_column($rankRows, 'rank_name');

$in = [
    'service_no'    => (string)($cadet['service_no'] ?? ''),
    'full_name'     => (string)($cadet['full_name'] ?? ''),
    'gender'        => (string)($cadet['gender'] ?? 'Male'),
    'phone'         => (string)($cadet['phone'] ?? ''),
    'email'         => (string)($cadet['email'] ?? ''),
    'department'    => (string)($cadet['department'] ?? ''),
    'current_rank'  => (string)($cadet['current_rank'] ?? 'Recruit / Cadet'),
    'current_role'  => (string)($cadet['current_role'] ?? ''),
    'status'        => (string)($cadet['status'] ?? 'Active'),
    'enrolled_date' => (string)($cadet['enrolled_date'] ?? date('Y-m-d')),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $editId = (int)($_POST['edit'] ?? 0);
    $cadet  = null;
    $oldPhoto = null;
    if ($editId > 0) {
        $stmt = db()->prepare('SELECT * FROM cadets WHERE id = ?');
        $stmt->execute([$editId]);
        $cadet = $stmt->fetch();
        if (!$cadet) {
            flash('warning', 'Cadet not found.');
            redirect(purl('cadets/list.php'));
        }
        $oldPhoto = $cadet['photo'] ?? null;
    }

    $in = [
        'service_no'    => trim((string)($_POST['service_no'] ?? '')),
        'full_name'     => trim((string)($_POST['full_name'] ?? '')),
        'gender'        => (string)($_POST['gender'] ?? 'Male'),
        'phone'         => trim((string)($_POST['phone'] ?? '')),
        'email'         => trim((string)($_POST['email'] ?? '')),
        'department'    => trim((string)($_POST['department'] ?? '')),
        'current_rank'  => trim((string)($_POST['current_rank'] ?? '')),
        'current_role'  => trim((string)($_POST['current_role'] ?? '')),
        'status'        => (string)($_POST['status'] ?? 'Active'),
        'enrolled_date' => (string)($_POST['enrolled_date'] ?? ''),
    ];

    /* Validation */
    if (!preg_match('/^[A-Za-z0-9\-\/]{3,30}$/', $in['service_no'])) {
        $errors[] = 'Service number is required (3–30 chars: letters, numbers, - /).';
    } elseif ($editId === 0 && !is_service_no_format($in['service_no'])) {
            $errors[] = 'New cadet IDs must follow the format ' . service_no_prefix()
                . '#### — e.g. ' . service_no_prefix() . '0001. Type the number manually.';
    }
    if ($in['full_name'] === '' || mb_strlen($in['full_name']) > 100) {
        $errors[] = 'Full name is required (max 100 characters).';
    }
    if (!in_array($in['gender'], ['Male', 'Female', 'Other'], true)) {
        $errors[] = 'Invalid gender selection.';
    }
    if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if ($in['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{5,30}$/', $in['phone'])) {
        $errors[] = 'Invalid phone number.';
    }
    if (!in_array($in['status'], ['Active', 'Alumnus', 'Discharged'], true)) {
        $errors[] = 'Invalid status.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['enrolled_date']) || !strtotime($in['enrolled_date'])) {
        $errors[] = 'Valid enrolment date is required.';
    }
    $allowedRanks = $rankNames;
    if ($editId > 0 && $cadet !== null && $cadet['current_rank'] !== ''
        && !in_array($cadet['current_rank'], $allowedRanks, true)) {
        $allowedRanks[] = $cadet['current_rank'];
    }
    if ($in['current_rank'] === '') {
        $in['current_rank'] = 'Recruit / Cadet';
    }
    if (!in_array($in['current_rank'], $allowedRanks, true)) {
        $errors[] = 'Select a valid rank from the list.';
    }

    /* Unique service number (exclude self on edit) */
    if (!$errors) {
        $sql = 'SELECT COUNT(*) FROM cadets WHERE service_no = ?';
        $args = [$in['service_no']];
        if ($editId > 0) {
            $sql .= ' AND id <> ?';
            $args[] = $editId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'Service number “' . $in['service_no'] . '” is already in use.';
        }
    }

    /* Photo upload */
    $newPhoto = $oldPhoto;
    if (!$errors && isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upErr = null;
        $path = handle_photo_upload($_FILES['photo'], $upErr);
        if ($upErr !== null) {
            $errors[] = $upErr;
        } elseif ($path !== null) {
            $newPhoto = $path;
        }
    }

    if (!$errors) {
        try {
            if ($editId > 0) {
                $stmt = db()->prepare(
                    'UPDATE cadets SET service_no=?, full_name=?, gender=?, phone=?, email=?,
                            department=?, current_rank=?, `current_role`=?, photo=?, status=?, enrolled_date=?
                      WHERE id=?'
                );
                $stmt->execute([
                    $in['service_no'], $in['full_name'], $in['gender'], $in['phone'], $in['email'],
                    $in['department'], $in['current_rank'], $in['current_role'], $newPhoto,
                    $in['status'], $in['enrolled_date'], $editId,
                ]);
                if ($newPhoto !== $oldPhoto) {
                    delete_photo($oldPhoto);
                }
                audit_log('cadet_update', 'cadets', $editId, $in['service_no']);
                flash('success', 'Cadet “' . $in['full_name'] . '” updated.');
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO cadets (service_no, full_name, gender, phone, email, department,
                                         current_rank, `current_role`, photo, status, enrolled_date)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $in['service_no'], $in['full_name'], $in['gender'], $in['phone'], $in['email'],
                    $in['department'], $in['current_rank'], $in['current_role'], $newPhoto,
                    $in['status'], $in['enrolled_date'],
                ]);
                $newId = (int)db()->lastInsertId();
                audit_log('cadet_create', 'cadets', $newId, $in['service_no']);
                flash('success', 'Cadet “' . $in['full_name'] . '” enlisted successfully.');
            }
            redirect(purl('cadets/list.php'));
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'Service number already exists.';
            } else {
                throw $ex;
            }
        }
    }

    /* Re-fill form with submitted values on error */
    $in['service_no'] = $in['service_no'];
}

$page_title = $editId > 0 ? 'Edit cadet' : 'Enlist cadet';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<nav aria-label="breadcrumb">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(purl('cadets/list.php')) ?>">Roster</a></li>
    <li class="breadcrumb-item active"><?= $editId > 0 ? 'Edit' : 'Enlist' ?></li>
  </ol>
</nav>

<h1 class="h3 mb-3">
  <i class="bi <?= $editId > 0 ? 'bi-pencil-square' : 'bi-person-plus' ?> me-2"></i>
  <?= $editId > 0 ? 'Edit cadet' : 'Enlist new cadet' ?>
</h1>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card shadow-sm border-0">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-3 needs-validation" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="edit" value="<?= (int)$editId ?>">

      <div class="col-12 col-md-4">
        <label class="form-label" for="service_no">Cadet ID / Service number *</label>
        <input class="form-control font-monospace" id="service_no" name="service_no" required
               maxlength="30" value="<?= e($in['service_no']) ?>"
               placeholder="<?= e(service_no_prefix()) ?>0001"
               aria-describedby="service_noHelp">
        <div class="form-text" id="service_noHelp">
          Type the number manually — format <code><?= e(service_no_prefix()) ?>####</code>
          (e.g. <code><?= e(service_no_prefix()) ?>0001</code>).
        </div>
      </div>
      <div class="col-12 col-md-5">
        <label class="form-label" for="full_name">Full name *</label>
        <input class="form-control" id="full_name" name="full_name" required maxlength="100"
               value="<?= e($in['full_name']) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="gender">Gender *</label>
        <select class="form-select" id="gender" name="gender" required>
          <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
            <option value="<?= e($g) ?>" <?= $in['gender'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-4">
        <label class="form-label" for="phone">Phone</label>
        <input class="form-control" id="phone" name="phone" maxlength="30" value="<?= e($in['phone']) ?>">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="email">Email</label>
        <input class="form-control" type="email" id="email" name="email" maxlength="120" value="<?= e($in['email']) ?>">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="department">Department</label>
        <input class="form-control" id="department" name="department" maxlength="80" value="<?= e($in['department']) ?>">
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label" for="current_rank">Current rank *</label>
        <select class="form-select" id="current_rank" name="current_rank" required>
          <?php foreach ($rankRows as $r): ?>
            <option value="<?= e($r['rank_name']) ?>" <?= $in['current_rank'] === $r['rank_name'] ? 'selected' : '' ?>>
              <?= e($r['rank_name']) ?> (<?= e($r['abbreviation']) ?>)
            </option>
          <?php endforeach; ?>
          <?php if ($in['current_rank'] !== '' && !in_array($in['current_rank'], $rankNames, true)): ?>
            <option value="<?= e($in['current_rank']) ?>" selected><?= e($in['current_rank']) ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="current_role">Unit role</label>
        <input class="form-control" id="current_role" name="current_role" maxlength="60"
               value="<?= e($in['current_role']) ?>" placeholder="e.g. Signals">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="status">Status *</label>
        <select class="form-select" id="status" name="status" required>
          <?php foreach (['Active', 'Alumnus', 'Discharged'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $in['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="enrolled_date">Enrolled *</label>
        <input class="form-control" type="date" id="enrolled_date" name="enrolled_date" required
               value="<?= e($in['enrolled_date']) ?>">
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label" for="photo">Photo <span class="text-muted small">(JPG/PNG/WebP, max 2 MB)</span></label>
        <input class="form-control" type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
        <?php if ($oldPhoto): ?>
          <div class="form-text">Current photo will be replaced if a new one is uploaded.</div>
        <?php endif; ?>
      </div>

      <div class="col-12 d-flex gap-2 pt-2">
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check-lg me-1"></i><?= $editId > 0 ? 'Save changes' : 'Enlist cadet' ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= e(purl('cadets/list.php')) ?>">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';
  var input = document.getElementById('service_no');
  if (!input) return;
  input.focus();
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
