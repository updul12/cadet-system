<?php
/**
 * My settings — account details, profile picture and password change.
 * Available to every signed-in user (own record only).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_check.php';

$user = requireLogin();

$errors     = [];
$photoError = null;

/** Load the signed-in user's account with linked cadet details. */
function load_account(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.cadet_id, u.username, u.photo, u.role_level, u.status,
                u.last_login, u.created_at,
                c.service_no, c.full_name, c.gender, c.phone, c.email,
                c.department, c.current_rank, c.current_role, c.status AS cadet_status,
                c.enrolled_date, c.photo AS cadet_photo
           FROM users u
           LEFT JOIN cadets c ON c.id = u.cadet_id
          WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$account = load_account((int)$user['id']);
if (!$account) {
    flash('warning', 'Your account record could not be loaded.');
    redirect(portal_base_path() . '/index.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    /* --- Profile picture ------------------------------------------- */
    if ($action === 'update_photo') {
        $path = handle_photo_upload($_FILES['photo'] ?? [], $photoError);
        if ($photoError !== null) {
            $errors[] = $photoError;
        } elseif ($path !== null) {
            $old = $account['photo'];
            $upd = db()->prepare('UPDATE users SET photo = ? WHERE id = ?');
            $upd->execute([$path, (int)$user['id']]);
            delete_photo($old);
            audit_log('user_photo_update', 'users', (int)$user['id'], $path);
            flash('success', 'Profile picture updated.');
            redirect(purl('settings.php'));
        } else {
            $errors[] = 'Choose a JPG, PNG or WebP image (max 2 MB) to upload.';
        }
        $account = load_account((int)$user['id']);
    }

    /* --- Remove profile picture ------------------------------------ */
    elseif ($action === 'remove_photo') {
        $old = $account['photo'];
        $upd = db()->prepare('UPDATE users SET photo = NULL WHERE id = ?');
        $upd->execute([(int)$user['id']]);
        delete_photo($old);
        audit_log('user_photo_remove', 'users', (int)$user['id']);
        flash('success', 'Profile picture removed.');
        redirect(purl('settings.php'));
    }

    /* --- Change password -------------------------------------------- */
    elseif ($action === 'update_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $pass    = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');

        if ($current === '') {
            $errors[] = 'Enter your current password.';
        }
        $errors = array_merge($errors, password_policy_errors($pass));
        if ($pass !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        $hash = (string)($stmt->fetchColumn() ?: '');

        if (!$errors && !password_verify($current, $hash)) {
            $errors[] = 'Your current password is incorrect.';
            audit_log('password_change_fail', 'users', (int)$user['id']);
        }

        if (!$errors) {
            $upd = db()->prepare(
                'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?'
            );
            $upd->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$user['id']]);
            audit_log('password_change', 'users', (int)$user['id']);
            flash('success', 'Your password has been changed.');
            redirect(purl('settings.php'));
        }
        $account = load_account((int)$user['id']);
    }

    /* Surface any validation errors as flashes after redirect. */
    foreach ($errors as $err) {
        flash('danger', $err);
    }
    redirect(purl('settings.php'));
}

/* Effective avatar: account picture, else linked cadet picture. */
$avatarPhoto = $account['photo'] ?: ($account['cadet_photo'] ?? null);
$displayName  = $account['full_name'] ?: $account['username'];

$page_title = 'My settings';
$page_base  = '';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-sliders me-2"></i>My settings</h1>
    <p class="text-muted mb-0">Your account details, profile picture and password.</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(purl('index.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Back to dashboard</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2 small" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($err) ?>
  </div>
<?php endforeach; ?>

<div class="row g-3">
  <!-- Profile card -->
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body text-center p-4">
        <div class="mb-3"><?= avatar($avatarPhoto, $displayName, 112, 'settings-avatar') ?></div>
        <h2 class="h5 mb-1"><?= e($displayName) ?></h2>
        <div class="text-muted small mb-2">@<?= e($account['username']) ?></div>
        <div class="d-flex justify-content-center gap-2 flex-wrap mb-3">
          <?= role_badge($account['role_level']) ?>
          <?= status_badge($account['status']) ?>
        </div>
        <?php if ($account['full_name']): ?>
          <div class="small text-muted mb-3">
            <?= e($account['current_rank']) ?><?= $account['service_no'] ? ' · ' . e($account['service_no']) : '' ?>
          </div>
        <?php endif; ?>

        <hr class="my-3">
        <h3 class="h6 text-uppercase text-muted mb-3">Profile picture</h3>
        <form method="post" enctype="multipart/form-data" class="text-start">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_photo">
          <label class="form-label" for="settings_photo">New picture</label>
          <input class="form-control" type="file" id="settings_photo" name="photo"
                 accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
          <div class="form-text mb-3">JPG, PNG or WebP · max 2 MB · shown in the top menu.</div>
          <button class="btn btn-primary w-100" type="submit">
            <i class="bi bi-upload me-1"></i><?= $account['photo'] ? 'Replace picture' : 'Upload picture' ?>
          </button>
        </form>
        <?php if ($account['photo']): ?>
          <form method="post" class="mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_photo">
            <button class="btn btn-outline-danger w-100" type="submit"
                    data-confirm="Remove your profile picture?">
              <i class="bi bi-trash me-1"></i>Remove picture
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Details + password -->
  <div class="col-12 col-lg-8 d-flex flex-column gap-3">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-person-vcard me-2"></i>My details</div>
      <div class="card-body">
        <div class="row g-3">
          <?php
          $details = [
              ['Username', '@' . $account['username'], false],
              ['Role', role_badge($account['role_level']) . ' <span class="ms-1 small text-muted">' . e($account['role_level']) . '</span>', true],
              ['Account status', status_badge($account['status']), true],
              ['Last sign-in', $account['last_login'] ? e(date('d M Y, H:i', strtotime((string)$account['last_login']))) : '<span class="text-muted">Never</span>', true],
              ['Account created', e(date('d M Y', strtotime((string)$account['created_at']))), false],
              ['Cadet number', $account['service_no'] ? '<span class="fw-semibold">' . e($account['service_no']) . '</span>' : '<span class="text-muted">Not linked</span>', true],
              ['Full name', $account['full_name'] ? e($account['full_name']) : '<span class="text-muted">—</span>', true],
              ['Rank / role', $account['full_name'] ? e(trim(($account['current_rank'] ?? '') . ' ' . ($account['current_role'] ?? '')) ?: '—') : '<span class="text-muted">—</span>', true],
              ['Department', $account['department'] ? e($account['department']) : '<span class="text-muted">—</span>', true],
              ['Phone', $account['phone'] ? e($account['phone']) : '<span class="text-muted">—</span>', true],
              ['Email', $account['email'] ? e($account['email']) : '<span class="text-muted">—</span>', true],
              ['Cadet status', $account['full_name'] ? status_badge((string)$account['cadet_status']) : '<span class="text-muted">—</span>', true],
          ];
          foreach ($details as [$label, $value, $raw]): ?>
          <div class="col-12 col-md-6">
            <div class="small text-muted text-uppercase letter-spaced"><?= e($label) ?></div>
            <div class="fw-semibold mt-1"><?= $raw ? $value : e((string)$value) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($account['cadet_id']): ?>
          <div class="mt-3 pt-3 border-top">
            <a class="btn btn-sm btn-outline-success" href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$account['cadet_id'] ?>">
              <i class="bi bi-folder2-open me-1"></i>Open my cadet dossier
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-key me-2"></i>Change password</div>
      <div class="card-body">
        <form method="post" class="row g-3 needs-validation" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_password">
          <div class="col-12">
            <label class="form-label" for="cur_pass">Current password *</label>
            <input class="form-control" type="password" id="cur_pass" name="current_password"
                   required autocomplete="current-password" maxlength="200">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="new_pass">New password *</label>
            <input class="form-control" type="password" id="new_pass" name="password"
                   required minlength="8" autocomplete="new-password" maxlength="200">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="new_pass2">Confirm new password *</label>
            <input class="form-control" type="password" id="new_pass2" name="confirm"
                   required minlength="8" autocomplete="new-password" maxlength="200">
          </div>
          <div class="col-12">
            <div class="form-text mb-2">Min 8 chars with an uppercase letter, lowercase letter, digit and special character.</div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Update password</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
