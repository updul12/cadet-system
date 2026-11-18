<?php
/**
 * Contact inquiries — Admin only (read / resolve / delete).
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireRole(['Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'resolve' || $action === 'reopen') {
        $new = $action === 'resolve' ? 'Resolved' : 'Unread';
        $stmt = db()->prepare('SELECT id, sender_name, status FROM contact_inquiries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $upd = db()->prepare('UPDATE contact_inquiries SET status = ? WHERE id = ?');
            $upd->execute([$new, $id]);
            audit_log('inquiry_' . $action, 'contact_inquiries', $id, $row['sender_name'] . ' → ' . $new);
            flash('success', 'Inquiry marked ' . strtolower($new) . '.');
        } else {
            flash('warning', 'Inquiry not found.');
        }
        redirect(purl('admin/inquiries.php'));
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('SELECT id, sender_name FROM contact_inquiries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $del = db()->prepare('DELETE FROM contact_inquiries WHERE id = ?');
            $del->execute([$id]);
            audit_log('inquiry_delete', 'contact_inquiries', $id, $row['sender_name']);
            flash('success', 'Inquiry deleted.');
        } else {
            flash('warning', 'Inquiry not found.');
        }
        redirect(purl('admin/inquiries.php'));
    }

    redirect(purl('admin/inquiries.php'));
}

/* Filters */
$fStatus = (string)($_GET['status'] ?? '');
if (!in_array($fStatus, ['Unread', 'Resolved'], true)) {
    $fStatus = '';
}
$where = [];
$args  = [];
if ($fStatus !== '') {
    $where[] = 'status = ?';
    $args[]  = $fStatus;
}
$wSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare(
    "SELECT id, sender_name, sender_email, subject, message, status, received_at, ip_address
       FROM contact_inquiries {$wSql}
      ORDER BY (status = 'Unread') DESC, received_at DESC
      LIMIT 200"
);
$stmt->execute($args);
$inquiries = $stmt->fetchAll();

$unread = (int)db()->query("SELECT COUNT(*) FROM contact_inquiries WHERE status = 'Unread'")->fetchColumn();

$page_title = 'Contact inquiries';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-envelope-paper me-2"></i>Contact inquiries</h1>
    <p class="text-muted mb-0"><?= (int)$unread ?> unread message<?= $unread === 1 ? '' : 's' ?> from the public website.</p>
  </div>
  <div class="btn-group">
    <a class="btn btn-sm <?= $fStatus === '' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(purl('admin/inquiries.php')) ?>">All</a>
    <a class="btn btn-sm <?= $fStatus === 'Unread' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(purl('admin/inquiries.php')) ?>?status=Unread">Unread</a>
    <a class="btn btn-sm <?= $fStatus === 'Resolved' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(purl('admin/inquiries.php')) ?>?status=Resolved">Resolved</a>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No contact inquiries yet.">
      <thead class="table-dark">
        <tr>
          <th>From</th>
          <th>Subject / message</th>
          <th>Received</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inquiries as $iq): ?>
          <tr class="<?= $iq['status'] === 'Unread' ? 'table-warning' : '' ?>">
            <td class="small">
              <strong><?= e($iq['sender_name']) ?></strong><br>
              <a href="mailto:<?= e($iq['sender_email']) ?>"><?= e($iq['sender_email']) ?></a>
            </td>
            <td>
              <?php if (!empty($iq['subject'])): ?>
                <div class="fw-semibold"><?= e($iq['subject']) ?></div>
              <?php endif; ?>
              <div class="small text-muted" style="white-space:pre-wrap"><?= e(mb_strimwidth((string)$iq['message'], 0, 300, '…')) ?></div>
            </td>
            <td class="small text-nowrap text-muted">
              <?= e(date('d M Y H:i', strtotime((string)$iq['received_at']))) ?>
            </td>
            <td>
              <?= status_badge((string)$iq['status']) ?>
            </td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $iq['status'] === 'Unread' ? 'resolve' : 'reopen' ?>">
                <input type="hidden" name="id" value="<?= (int)$iq['id'] ?>">
                <button class="btn btn-sm btn-outline-success" type="submit"
                        title="<?= $iq['status'] === 'Unread' ? 'Mark resolved' : 'Mark unread' ?>">
                  <i class="bi bi-check2-circle"></i>
                </button>
              </form>
              <form method="post" class="d-inline" data-confirm="Delete this inquiry?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$iq['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$inquiries): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No inquiries match this filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
