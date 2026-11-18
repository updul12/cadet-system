<?php
/** Recruitment applications — Admin only (review / status / delete). */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireRole(['Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if (in_array($action, ['Reviewed', 'Accepted', 'Rejected'], true)) {
        $st = db()->prepare('UPDATE recruitment_applications SET status = ? WHERE id = ?');
        $st->execute([$action, $id]);
        audit_log('application_' . strtolower($action), 'recruitment_applications', $id);
        flash('success', 'Application marked ' . strtolower($action) . '.');
    } elseif ($action === 'delete') {
        $st = db()->prepare('DELETE FROM recruitment_applications WHERE id = ?');
        $st->execute([$id]);
        audit_log('application_delete', 'recruitment_applications', $id);
        flash('success', 'Application deleted.');
    }
    redirect(purl('admin/applications.php'));
}

$rows = db()->query(
    'SELECT * FROM recruitment_applications ORDER BY (status = "New") DESC, received_at DESC LIMIT 200'
)->fetchAll();
$newCount = (int)db()->query('SELECT COUNT(*) FROM recruitment_applications WHERE status = "New"')->fetchColumn();

$page_title = 'Applications';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-file-earmark-person me-2"></i>Recruitment applications</h1>
    <p class="text-muted mb-0"><?= (int)$newCount ?> new application<?= $newCount === 1 ? '' : 's' ?> from join.php.</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(app_base_path()) ?>/join.php" target="_blank" rel="noopener">
    <i class="bi bi-eye me-1"></i>View public form
  </a>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No recruitment applications yet.">
      <thead class="table-dark">
        <tr>
          <th>Applicant</th><th>Contact</th><th>Programme</th><th>Message</th>
          <th>Received</th><th>Status</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['status'] === 'New' ? 'table-warning' : '' ?>">
            <td class="small"><strong><?= e($r['full_name']) ?></strong></td>
            <td class="small">
              <a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><br>
              <?= e($r['phone'] ?: '—') ?>
            </td>
            <td class="small"><?= e($r['department'] ?: '—') ?></td>
            <td class="small text-muted" style="max-width:260px;white-space:pre-wrap"><?= e(mb_strimwidth((string)$r['message'], 0, 180, '…')) ?></td>
            <td class="small text-nowrap text-muted"><?= e(date('d M Y H:i', strtotime((string)$r['received_at']))) ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="text-end text-nowrap">
              <?php foreach (['Reviewed', 'Accepted', 'Rejected'] as $s): ?>
                <?php if ($r['status'] !== $s): ?>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $s ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-<?= $s === 'Accepted' ? 'success' : ($s === 'Rejected' ? 'danger' : 'secondary') ?>"
                            title="Mark <?= $s ?>"><?= $s[0] ?></button>
                  </form>
                <?php endif; ?>
              <?php endforeach; ?>
              <form method="post" class="d-inline" data-confirm="Delete this application?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No applications yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
