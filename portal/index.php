<?php
/**
 * Dashboard — summary metrics + quick-action cards.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_check.php';

$user = requireLogin();

/* Summary counts (single round-trip where possible). */
$counts = [];
$qs = [
    'cadets_active' => "SELECT COUNT(*) FROM cadets WHERE status = 'Active'",
    'cadets_total'  => 'SELECT COUNT(*) FROM cadets',
    'promotions'    => 'SELECT COUNT(*) FROM rank_history',
    'fitness'       => 'SELECT COUNT(*) FROM fitness_logs',
    'attendance'    => 'SELECT COUNT(*) FROM attendance',
    'inventory'     => 'SELECT COUNT(*) FROM inventory',
    'duties'        => "SELECT COUNT(*) FROM duty_roster WHERE status = 'Pending'",
    'discipline'    => 'SELECT COUNT(*) FROM discipline_awards',
];
foreach ($qs as $k => $sql) {
    $counts[$k] = (int)db()->query($sql)->fetchColumn();
}

/* Recent activity */
$recentCadets = db()->query(
    'SELECT id, service_no, full_name, current_rank, status, enrolled_date
       FROM cadets ORDER BY created_at DESC LIMIT 5'
)->fetchAll();

$upcomingDuties = db()->query(
    "SELECT d.id, d.assigned_cadet_id, d.duty_name, d.duty_date, d.shift_time, d.status, c.full_name
       FROM duty_roster d JOIN cadets c ON c.id = d.assigned_cadet_id
      WHERE d.duty_date >= CURDATE()
      ORDER BY d.duty_date ASC LIMIT 5"
)->fetchAll();

$recentDiscipline = db()->query(
    'SELECT d.id, d.cadet_id, d.record_date, d.record_type, d.description, c.full_name
       FROM discipline_awards d JOIN cadets c ON c.id = d.cadet_id
      ORDER BY d.record_date DESC, d.id DESC LIMIT 5'
)->fetchAll();

$announcements = [];
try {
    $announcements = db()->query(
        'SELECT title, body, priority, created_at FROM portal_announcements
          ORDER BY created_at DESC LIMIT 3'
    )->fetchAll();
} catch (Throwable $ex) {
    error_log('[DASH-ANN] ' . $ex->getMessage());
}

$page_title = 'Dashboard';
$page_base  = '';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Dashboard</h1>
    <p class="text-muted mb-0">Welcome back — here is the unit at a glance.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (canEdit() && has_module('cadets')): ?>
      <a class="btn btn-primary" href="<?= e(purl('cadets/create.php')) ?>"><i class="bi bi-person-plus me-1"></i>Enlist cadet</a>
    <?php endif; ?>
    <?php if (canEdit() && has_module('records')): ?>
      <a class="btn btn-outline-primary" href="<?= e(purl('operations/attendance.php')) ?>"><i class="bi bi-clipboard-check me-1"></i>Mark attendance</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($announcements): ?>
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-megaphone me-2"></i>Unit announcements</div>
      <div class="list-group list-group-flush">
        <?php foreach ($announcements as $a): ?>
          <div class="list-group-item">
            <div class="d-flex justify-content-between gap-2">
              <strong class="text-break"><?= e($a['title']) ?></strong>
              <?php if ($a['priority'] === 'Urgent'): ?>
                <span class="badge text-bg-danger flex-shrink-0">Urgent</span>
              <?php endif; ?>
            </div>
            <div class="small text-muted" style="white-space:pre-wrap"><?= e($a['body']) ?></div>
            <div class="small text-muted mt-1"><?= e(format_date($a['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (canEdit()): ?>
<div class="row g-3 mb-4">
  <?php
  $cards = [
      ['cadets_active', 'Active cadets', 'bi-people-fill', 'primary', 'cadets/list.php', 'cadets'],
      ['promotions', 'Promotions', 'bi-award-fill', 'success', 'operations/promotions.php', 'records'],
      ['fitness', 'Fitness tests', 'bi-activity', 'info', 'operations/fitness.php', 'records'],
      ['attendance', 'Attendance marks', 'bi-clipboard-check', 'dark', 'operations/attendance.php', 'records'],
      ['inventory', 'Gear items', 'bi-box-seam', 'secondary', 'logistics/inventory.php', 'operations'],
      ['duties', 'Pending duties', 'bi-calendar-week', 'danger', 'operations/duties.php', 'operations'],
  ];
  $cards = array_values(array_filter($cards, static fn(array $c): bool => has_module($c[5])));
  foreach ($cards as [$key, $label, $icon, $color, $href, $mod]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= e($href) ?>" class="text-decoration-none">
      <div class="card shadow-sm border-0 h-100 quick-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fs-3 fw-bold text-<?= e($color) ?>"><?= (int)$counts[$key] ?></div>
              <div class="small text-muted"><?= e($label) ?></div>
            </div>
            <i class="bi <?= e($icon) ?> fs-3 text-<?= e($color) ?> opacity-50"></i>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$showCadets = has_module('cadets');
$showOps    = has_module('operations');
$showRec    = has_module('records');
?>
<?php if (!$showCadets && !$showOps && !$showRec): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-shield-lock fs-1 d-block mb-2"></i>
      No modules are assigned to your account yet — ask an administrator to grant access.
    </div>
  </div>
<?php else: ?>
<div class="row g-3">
  <?php if ($showCadets): ?>
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-person-lines-fill me-2"></i>Newest cadets</div>
      <div class="list-group list-group-flush">
        <?php foreach ($recentCadets as $c): ?>
          <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$c['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
            <div>
              <strong><?= e($c['full_name']) ?></strong>
              <div class="small text-muted"><?= e($c['service_no']) ?> · <?= e($c['current_rank']) ?></div>
            </div>
            <?= status_badge($c['status']) ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$recentCadets): ?>
          <div class="list-group-item text-muted">No cadets yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($showOps || $showRec): ?>
  <div class="col-12 col-lg-6 d-flex flex-column">
    <?php if ($showOps): ?>
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-event me-2"></i>Upcoming duties</div>
      <div class="list-group list-group-flush">
        <?php foreach ($upcomingDuties as $d): ?>
          <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
            <div class="min-w-0">
              <strong><?= e($d['duty_name']) ?></strong>
              <div class="small text-muted text-break"><?= format_date($d['duty_date']) ?> · <?= e($d['shift_time']) ?> ·
                <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)($d['assigned_cadet_id'] ?? 0) ?>" class="text-decoration-none"><?= e($d['full_name']) ?></a>
              </div>
            </div>
            <?= status_badge($d['status']) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$upcomingDuties): ?>
          <div class="list-group-item text-muted">No upcoming duties.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($showRec): ?>
    <div class="card shadow-sm border-0 <?= $showOps ? 'flex-grow-1' : '' ?>">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-data me-2"></i>Recent discipline &amp; awards</div>
      <div class="list-group list-group-flush">
        <?php foreach ($recentDiscipline as $r): ?>
          <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$r['cadet_id'] ?>"
             class="list-group-item list-group-item-action d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center gap-2">
            <div class="min-w-0 flex-grow-1">
              <strong class="text-break"><?= e($r['full_name']) ?></strong>
              <div class="small text-muted text-break"><?= format_date($r['record_date']) ?> — <?= e($r['description']) ?></div>
            </div>
            <?= status_badge($r['record_type']) ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$recentDiscipline): ?>
          <div class="list-group-item text-muted">No records.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
