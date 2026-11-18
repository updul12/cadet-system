<?php
/**
 * System settings — branding (system name + logo).
 * Admin only: only administrators may change the Cadet·MS name/logo.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user  = requireRole(['Admin']);
$errors = [];

$currentName = system_name();
$currentLogo = get_setting('system_logo');
$logoUrl     = system_logo();
$currentWaNumber = get_setting('whatsapp_number');
$currentWaMsg    = get_setting('whatsapp_message', whatsapp_default_message());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    /* --- Rename the system ------------------------------------------ */
    if ($action === 'update_name') {
        $name = trim((string)($_POST['system_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            $errors[] = 'System name is required (1–60 characters).';
        } elseif (!preg_match('/^[\p{L}\p{N} .&\'·\/-]+$/u', $name)) {
            $errors[] = 'The system name may only contain letters, numbers, spaces and . & \' · / -';
        }
        if (!$errors) {
            set_setting('system_name', $name, (int)$user['id']);
            audit_log('settings_name', 'system_settings', null, '→ ' . $name);
            flash('success', 'System name updated to “' . $name . '”.');
            redirect(purl('admin/system_settings.php'));
        }
        $currentName = $name;
    }

    /* --- Upload logo ------------------------------------------------- */
    elseif ($action === 'update_logo') {
        $upErr = null;
        $path  = handle_photo_upload($_FILES['logo'] ?? [], $upErr);
        if ($upErr !== null) {
            $errors[] = $upErr;
        } elseif ($path !== null) {
            $old = $currentLogo;
            set_setting('system_logo', $path, (int)$user['id']);
            if ($old !== '' && $old !== $path) {
                delete_photo($old);
            }
            audit_log('settings_logo', 'system_settings', null, $path);
            flash('success', 'System logo updated.');
            redirect(purl('admin/system_settings.php'));
        } else {
            $errors[] = 'Choose a JPG, PNG or WebP image (max 2 MB) to upload.';
        }
    }

    /* --- Remove logo -------------------------------------------------- */
    elseif ($action === 'remove_logo') {
        $old = $currentLogo;
        set_setting('system_logo', '', (int)$user['id']);
        delete_photo($old);
        audit_log('settings_logo_remove', 'system_settings', null, $old);
        flash('success', 'System logo removed.');
        redirect(purl('admin/system_settings.php'));
    }

    /* --- WhatsApp click-to-chat --------------------------------------- */
    elseif ($action === 'update_whatsapp') {
        $number = trim((string)($_POST['whatsapp_number'] ?? ''));
        $msg    = trim((string)($_POST['whatsapp_message'] ?? ''));

        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($number !== '' && (strlen($digits) < 8 || strlen($digits) > 15)) {
            $errors[] = 'WhatsApp number must be 8–15 digits (country code included, no +).';
        }
        if (mb_strlen($msg) > 300) {
            $errors[] = 'Default WhatsApp message must be 300 characters or fewer.';
        }
        if (!$errors) {
            set_setting('whatsapp_number', $digits, (int)$user['id']);
            set_setting('whatsapp_message', $msg, (int)$user['id']);
            audit_log('settings_whatsapp', 'system_settings', null, '→ ' . ($digits !== '' ? $digits : '(cleared)'));
            flash('success', $digits !== '' ? 'WhatsApp contact updated.' : 'WhatsApp contact removed.');
            redirect(purl('admin/system_settings.php'));
        }
        $currentWaNumber = $number;
        $currentWaMsg    = $msg;
    }

    foreach ($errors as $err) {
        flash('danger', $err);
    }
    redirect(purl('admin/system_settings.php'));
}

$page_title = 'System settings';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-palette me-2"></i>System settings</h1>
    <p class="text-muted mb-0">Branding for the whole portal — name, logo and background watermark.</p>
  </div>
  <span class="badge text-bg-warning"><i class="bi bi-shield-lock me-1"></i>Administrator only</span>
</div>

<div class="row g-3">
  <!-- System name -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-type-h1 me-2"></i>System name</div>
      <div class="card-body">
        <p class="text-muted small">Shown in the top bar, browser tab, login screen and footer.
          Only administrators can change it.</p>
        <form method="post" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_name">
          <label class="form-label" for="system_name">Name *</label>
          <input class="form-control" id="system_name" name="system_name" required maxlength="60"
                 value="<?= e($currentName) ?>">
          <div class="form-text mb-3">Current: <strong><?= e($currentName) ?></strong></div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Save name</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Logo -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-image me-2"></i>System logo</div>
      <div class="card-body">
        <p class="text-muted small">Used in the top bar and login card, and as a faint
          watermark on the system background.</p>

        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="border rounded p-2 d-flex align-items-center justify-content-center bg-white" style="width:96px;height:96px">
            <?php if ($logoUrl): ?>
              <img src="<?= e($logoUrl) ?>" alt="System logo" style="max-width:100%;max-height:100%">
            <?php else: ?>
              <i class="bi bi-image text-muted fs-2"></i>
            <?php endif; ?>
          </div>
          <div class="small text-muted">
            <?= $logoUrl ? 'Logo uploaded.' : 'No logo yet — the default shield icon is used.' ?><br>
            JPG, PNG or WebP · max 2 MB.
          </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_logo">
          <label class="form-label" for="system_logo_file">Upload / replace logo</label>
          <input class="form-control mb-2" type="file" id="system_logo_file" name="logo"
                 accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
          <button class="btn btn-primary" type="submit"><i class="bi bi-upload me-1"></i>Upload logo</button>
        </form>

        <?php if ($logoUrl): ?>
          <form method="post" class="mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_logo">
            <button class="btn btn-outline-danger" type="submit" data-confirm="Remove the system logo everywhere?">
              <i class="bi bi-trash me-1"></i>Remove logo
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- WhatsApp click-to-chat -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-whatsapp me-2"></i>WhatsApp click-to-chat</div>
      <div class="card-body">
        <p class="text-muted small">Shown as a “Chat with us” button on the public contact page and as a
          floating button on every public page. Leave the number blank to hide both.</p>
        <form method="post" class="row g-3 needs-validation" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_whatsapp">
          <div class="col-12 col-md-5">
            <label class="form-label" for="whatsapp_number">Phone number</label>
            <input class="form-control" id="whatsapp_number" name="whatsapp_number" maxlength="20"
                   placeholder="e.g. 233241234567"
                   value="<?= e($currentWaNumber) ?>">
            <div class="form-text">International format without + or leading 0 (Ghana: 233…).</div>
          </div>
          <div class="col-12 col-md-7">
            <label class="form-label" for="whatsapp_message">Pre-filled message</label>
            <input class="form-control" id="whatsapp_message" name="whatsapp_message" maxlength="300"
                   value="<?= e($currentWaMsg) ?>">
            <div class="form-text">Sent with the chat so you know where the inquiry came from.</div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Save WhatsApp settings</button>
            <?php if ($currentWaNumber !== ''): ?>
              <span class="ms-2 small text-muted">Current: <?= e(whatsapp_link()) ?></span>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
