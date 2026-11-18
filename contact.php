<?php
/**
 * Public contact & inquiries — stores messages for admin follow-up.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth_check.php';

$errors = [];
$in = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

/** Simple per-IP throttle: max 5 inquiries per hour. */
function contact_throttled(): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM contact_inquiries
              WHERE ip_address = ? AND received_at >= (NOW() - INTERVAL 1 HOUR)'
        );
        $stmt->execute([client_ip()]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Throwable $ex) {
        error_log('[CONTACT-THROTTLE] ' . $ex->getMessage());
        return false; /* fail open so legitimate mail still arrives if table missing */
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();

    $in['name']    = trim((string)($_POST['name'] ?? ''));
    $in['email']   = trim((string)($_POST['email'] ?? ''));
    $in['subject'] = trim((string)($_POST['subject'] ?? ''));
    $in['message'] = trim((string)($_POST['message'] ?? ''));

    /* Honeypot — bots fill this hidden field; humans do not. */
    $honeypot = (string)($_POST['website'] ?? '');
    if ($honeypot !== '') {
        flash('success', 'Thank you — your message has been received.');
        redirect('contact.php');
    }

    if ($in['name'] === '' || mb_strlen($in['name']) > 100) {
        $errors[] = 'Your name is required (max 100 characters).';
    }
    if ($in['email'] === '' || !filter_var($in['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($in['email']) > 120) {
        $errors[] = 'A valid email address is required (max 120 characters).';
    }
    if (mb_strlen($in['subject']) > 150) {
        $errors[] = 'Subject must be 150 characters or fewer.';
    }
    if ($in['message'] === '' || mb_strlen($in['message']) > 5000) {
        $errors[] = 'Message is required (max 5000 characters).';
    }

    if (!$errors && contact_throttled()) {
        $errors[] = 'Too many messages from this device. Please try again later.';
        audit_log('contact_throttled', null, null, client_ip());
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO contact_inquiries (sender_name, sender_email, subject, message, ip_address)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$in['name'], $in['email'], $in['subject'], $in['message'], client_ip()]);
            audit_log('contact_submit', 'contact_inquiries', (int)db()->lastInsertId(), $in['subject'] ?: $in['name']);
            flash('success', 'Thank you — your message has been received. The unit will get back to you.');
            redirect('contact.php');
        } catch (Throwable $ex) {
            error_log('[CONTACT] ' . $ex->getMessage());
            $errors[] = 'Your message could not be sent right now. Please try again later.';
        }
    }

    foreach ($errors as $err) {
        flash('danger', $err);
    }
    redirect('contact.php');
}

$page_title = 'Contact';
require __DIR__ . '/includes/public_header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <h1 class="h2 mb-4">Contact &amp; Inquiries</h1>

    <?php if (whatsapp_number() !== ''): ?>
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-body p-4 text-center">
        <span class="badge text-bg-success p-2 px-3 rounded-pill mb-3">
          <i class="bi bi-whatsapp me-1"></i>Instant response
        </span>
        <h2 class="h4 fw-bold mb-2">Chat with us on WhatsApp</h2>
        <p class="text-muted mb-3">
          Have questions about admissions, parade schedules, or unit requirements?
          Message the administrative team directly for a faster reply.
        </p>
        <a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener"
           class="btn btn-success btn-lg px-4 rounded-pill fw-semibold">
          <i class="bi bi-whatsapp me-1"></i>Open WhatsApp chat
        </a>
      </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <form method="post" class="needs-validation" novalidate>
          <?= csrf_field() ?>

          <!-- Honeypot: hidden from humans, attractive to bots -->
          <div class="position-absolute" style="left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="c_name">Your name *</label>
              <input class="form-control" id="c_name" name="name" required maxlength="100"
                     value="<?= e($in['name']) ?>" autocomplete="name">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="c_email">Email *</label>
              <input class="form-control" type="email" id="c_email" name="email" required maxlength="120"
                     value="<?= e($in['email']) ?>" autocomplete="email">
            </div>
            <div class="col-12">
              <label class="form-label" for="c_subject">Subject</label>
              <input class="form-control" id="c_subject" name="subject" maxlength="150"
                     value="<?= e($in['subject']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="c_message">Message *</label>
              <textarea class="form-control" id="c_message" name="message" rows="6" required
                        maxlength="5000"><?= e($in['message']) ?></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-success" type="submit">
                <i class="bi bi-send me-1"></i>Send message
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
