<?php
/** Portal announcements — Admin only (bulletin board posts). */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireRole(['Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));
        $prio  = ($_POST['priority'] ?? 'Normal') === 'Urgent' ? 'Urgent' : 'Normal';
        if ($title !== '' && $body !== '') {
            $st = db()->prepare('INSERT INTO portal_announcements (title, body, priority, created_by) VALUES (?,?,?,?)');
            $st->execute([$title, $body, $prio, (int)$user['id']]);
            audit_log('announcement_create', 'portal_announcements', (int)db()->lastInsertId(), $title);
            flash('success', 'Announcement posted to the dashboard.');
        } else {
            flash('danger', 'Title and message are required.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('DELETE FROM portal_announcements WHERE id = ?');
        $st->execute([$id]);
        audit_log('announcement_delete', 'portal_announcements', $id);
        flash('success', 'Announcement removed.');
    }
    redirect(purl('admin/announcements.php'));
}

$rows = db()->query(
    'SELECT a.*, u.username FROM portal_announcements a
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.created_at DESC LIMIT 50'
)->fetchAll();

$page_title = 'Announcements';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <h1 class="h3 mb-1"><i class="bi bi-megaphone me-2"></i>Dashboard announcements</h1>
  <p class="text-muted mb-0">Posts appear on the portal dashboard for all signed-in users.</p>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-2"></i>New announcement</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label" for="a_title">Title *</label>
            <input class="form-control" id="a_title" name="title" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label" for="a_body">Message *</label>
            <textarea class="form-control" id="a_body" name="body" rows="5" required maxlength="5000"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label" for="a_prio">Priority</label>
            <select class="form-select" id="a_prio" name="priority">
              <option value="Normal">Normal</option>
              <option value="Urgent">Urgent</option>
            </select>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i>Post</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-list-ul me-2"></i>Recent posts</div>
      <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): ?>
          <div class="list-group-item d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0">
              <div class="fw-semibold">
                <?= e($r['title']) ?>
                <?php if ($r['priority'] === 'Urgent'): ?>
                  <span class="badge text-bg-danger ms-1">Urgent</span>
                <?php endif; ?>
              </div>
              <div class="small text-muted" style="white-space:pre-wrap"><?= e($r['body']) ?></div>
              <div class="small text-muted mt-1">
                <?= e($r['username'] ?? 'system') ?> · <?= e(date('d M Y H:i', strtotime((string)$r['created_at']))) ?>
              </div>
            </div>
            <form method="post" class="flex-shrink-0" data-confirm="Delete this announcement?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <div class="list-group-item text-muted">No announcements yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
