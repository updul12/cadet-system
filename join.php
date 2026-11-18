<?php
/** Recruitment page — eligibility info + application form. */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth_check.php';

$errors = [];
$in = ['full_name' => '', 'email' => '', 'phone' => '', 'department' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    foreach ($in as $k => $_) {
        $in[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $honeypot = (string)($_POST['website'] ?? '');

    if ($honeypot !== '') {
        flash('success', 'Thank you — your application has been received.');
        redirect('join.php');
    }
    if ($in['full_name'] === '' || mb_strlen($in['full_name']) > 100) {
        $errors[] = 'Full name is required (max 100 characters).';
    }
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($in['message'] === '' || mb_strlen($in['message']) > 3000) {
        $errors[] = 'Please tell us why you want to join (max 3000 characters).';
    }

    if (!$errors) {
        try {
            $st = db()->prepare(
                'INSERT INTO recruitment_applications (full_name, email, phone, department, message, ip_address)
                 VALUES (?,?,?,?,?,?)'
            );
            $st->execute([$in['full_name'], $in['email'], $in['phone'], $in['department'], $in['message'], client_ip()]);
            audit_log('recruitment_apply', 'recruitment_applications', (int)db()->lastInsertId(), $in['full_name']);
            flash('success', 'Thank you — your application has been received. The unit will contact you.');
            redirect('join.php');
        } catch (Throwable $ex) {
            error_log('[JOIN] ' . $ex->getMessage());
            $errors[] = 'Could not submit your application right now. Please try again later.';
        }
    }
    foreach ($errors as $err) {
        flash('danger', $err);
    }
    redirect('join.php');
}

$page_title = 'Join Us';
require __DIR__ . '/includes/public_header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <h1 class="h2 mb-2">Join the Unit</h1>
    <p class="text-muted mb-4">Ready to serve? Check eligibility below and send your interest — we will get back to you.</p>

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h2 class="h6"><i class="bi bi-person-check me-2 text-primary"></i>Eligibility</h2>
            <ul class="small text-muted mb-0 ps-3">
              <li>Registered student of the university</li>
              <li>Medically fit for training</li>
              <li>Good academic standing</li>
              <li>Willing to commit to parade schedule</li>
            </ul>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h2 class="h6"><i class="bi bi-calendar-event me-2 text-primary"></i>Training</h2>
            <ul class="small text-muted mb-0 ps-3">
              <li>Weekly parade — Saturday mornings</li>
              <li>Field exercises each semester</li>
              <li>First aid &amp; fitness sessions</li>
              <li>Leadership workshops</li>
            </ul>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h2 class="h6"><i class="bi bi-clipboard-check me-2 text-primary"></i>You will need</h2>
            <ul class="small text-muted mb-0 ps-3">
              <li>Student ID / registration</li>
              <li>Medical fitness certificate</li>
              <li>Passport photograph</li>
              <li>Completed application form</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-person me-2"></i>Application form</div>
      <div class="card-body p-4">
        <form method="post" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <div class="position-absolute" style="left:-9999px" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="j_name">Full name *</label>
              <input class="form-control" id="j_name" name="full_name" required maxlength="100"
                     value="<?= e($in['full_name']) ?>" autocomplete="name">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="j_email">Email *</label>
              <input class="form-control" type="email" id="j_email" name="email" required maxlength="120"
                     value="<?= e($in['email']) ?>" autocomplete="email">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="j_phone">Phone</label>
              <input class="form-control" id="j_phone" name="phone" maxlength="30"
                     value="<?= e($in['phone']) ?>" autocomplete="tel">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="j_dept">Programme / department</label>
              <input class="form-control" id="j_dept" name="department" maxlength="80"
                     value="<?= e($in['department']) ?>" placeholder="e.g. Engineering">
            </div>
            <div class="col-12">
              <label class="form-label" for="j_msg">Why do you want to join? *</label>
              <textarea class="form-control" id="j_msg" name="message" rows="5" required
                        maxlength="3000"><?= e($in['message']) ?></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-success" type="submit">
                <i class="bi bi-send me-1"></i>Submit application
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
