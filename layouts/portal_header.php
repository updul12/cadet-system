<?php
/**
 * Portal layout header — Bootstrap 5 (local vendor).
 * Pages set $page_title. Requires core bootstrap (auth + helpers).
 * Nav uses purl() so module subfolders resolve correctly.
 */
declare(strict_types=1);

if (!isset($page_title)) {
    $page_title = 'Cadet Unit MS';
}
$u = current_user();

$path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$pos  = strpos($path, '/portal/');
$current = $pos !== false ? substr($path, $pos + 8) : basename($path);

$navGroups = [
    'cadets'  => ['cadets/list.php', 'cadets/create.php', 'cadets/import.php', 'cadets/view.php'],
    'records' => ['operations/promotions.php', 'operations/fitness.php', 'operations/attendance.php', 'operations/discipline.php'],
    'ops'     => ['logistics/inventory.php', 'operations/duties.php'],
    'admin'   => ['admin/manage_users.php', 'admin/news.php', 'admin/inquiries.php', 'admin/applications.php', 'admin/announcements.php', 'admin/backup.php', 'admin/audit_logs.php', 'admin/system_settings.php', 'settings.php'],
];
$group_active = static function (string $group) use ($navGroups, $current): string {
    foreach ($navGroups[$group] ?? [] as $page) {
        if ($current === $page || str_ends_with($current, '/' . $page)) {
            return ' active';
        }
    }
    return '';
};

$sysName = system_name();
$logoUrl = system_logo();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <title><?= e($page_title) ?> · <?= e($sysName) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= e(app_base_path()) ?>/favicon.svg">
  <link rel="stylesheet" href="<?= e(app_base_path()) ?>/assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="<?= e(app_base_path()) ?>/assets/vendor/bootstrap-icons.css">
  <link rel="stylesheet" href="<?= e(app_base_path()) ?>/assets/css/style.css">
  <?php if ($logoUrl): ?>
    <style>:root { --cus-watermark: url('<?= e($logoUrl) ?>'); }</style>
  <?php endif; ?>
</head>
<body class="d-flex flex-column min-vh-100">

<nav class="navbar navbar-expand-lg cus-navbar sticky-top">
  <div class="container-fluid px-3 px-md-4">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= e(purl('index.php')) ?>"
       title="<?= e($sysName) ?>">
      <?php if ($logoUrl): ?>
        <img src="<?= e($logoUrl) ?>" alt="" class="brand-logo">
      <?php else: ?>
        <i class="bi bi-shield-fill-check brand-shield"></i>
      <?php endif; ?>
      <span class="brand-name"><?= e($sysName) ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
            aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link<?= nav_active('index.php') ?>" href="<?= e(purl('index.php')) ?>">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
          </a>
        </li>

        <?php if (has_module('cadets')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $group_active('cadets') ?>" href="#" role="button"
             data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-people me-1"></i>Cadets
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item<?= nav_active('cadets/list.php') ?>" href="<?= e(purl('cadets/list.php')) ?>"><i class="bi bi-list-ul me-2"></i>Roster</a></li>
            <?php if (canEdit()): ?>
              <li><a class="dropdown-item<?= nav_active('cadets/create.php') ?>" href="<?= e(purl('cadets/create.php')) ?>"><i class="bi bi-person-plus me-2"></i>Enlist cadet</a></li>
              <li><a class="dropdown-item<?= nav_active('cadets/import.php') ?>" href="<?= e(purl('cadets/import.php')) ?>"><i class="bi bi-file-earmark-arrow-up me-2"></i>Import (Excel / CSV)</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?= e(purl('cadets/list.php')) ?>"><i class="bi bi-folder2-open me-2"></i>Dossiers</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (has_module('records')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $group_active('records') ?>" href="#" role="button"
             data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-clipboard-data me-1"></i>Records
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item<?= nav_active('operations/promotions.php') ?>" href="<?= e(purl('operations/promotions.php')) ?>"><i class="bi bi-award me-2"></i>Promotions</a></li>
            <li><a class="dropdown-item<?= nav_active('operations/fitness.php') ?>" href="<?= e(purl('operations/fitness.php')) ?>"><i class="bi bi-activity me-2"></i>Fitness</a></li>
            <li><a class="dropdown-item<?= nav_active('operations/attendance.php') ?>" href="<?= e(purl('operations/attendance.php')) ?>"><i class="bi bi-clipboard-check me-2"></i>Attendance</a></li>
            <li><a class="dropdown-item<?= nav_active('operations/discipline.php') ?>" href="<?= e(purl('operations/discipline.php')) ?>"><i class="bi bi-journal-check me-2"></i>Discipline</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (has_module('operations')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $group_active('ops') ?>" href="#" role="button"
             data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-box-seam me-1"></i>Operations
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item<?= nav_active('logistics/inventory.php') ?>" href="<?= e(purl('logistics/inventory.php')) ?>"><i class="bi bi-box-seam me-2"></i>Inventory</a></li>
            <li><a class="dropdown-item<?= nav_active('operations/duties.php') ?>" href="<?= e(purl('operations/duties.php')) ?>"><i class="bi bi-calendar-week me-2"></i>Duties</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $group_active('admin') ?>" href="#" role="button"
             data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-gear me-1"></i>Admin
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item<?= nav_active('admin/manage_users.php') ?>" href="<?= e(purl('admin/manage_users.php')) ?>"><i class="bi bi-person-gear me-2"></i>User accounts</a></li>
            <li><a class="dropdown-item<?= nav_active('admin/news.php') ?>" href="<?= e(purl('admin/news.php')) ?>"><i class="bi bi-megaphone me-2"></i>News posts</a></li>
            <li><a class="dropdown-item<?= nav_active('admin/inquiries.php') ?>" href="<?= e(purl('admin/inquiries.php')) ?>"><i class="bi bi-envelope-paper me-2"></i>Contact inquiries</a></li>
            <li><a class="dropdown-item<?= nav_active('admin/applications.php') ?>" href="<?= e(purl('admin/applications.php')) ?>"><i class="bi bi-file-earmark-person me-2"></i>Applications</a></li>
            <li><a class="dropdown-item<?= nav_active('admin/announcements.php') ?>" href="<?= e(purl('admin/announcements.php')) ?>"><i class="bi bi-pin-angle me-2"></i>Announcements</a></li>
            <li><a class="dropdown-item<?= nav_active('admin/backup.php') ?>" href="<?= e(purl('admin/backup.php')) ?>"><i class="bi bi-database-down me-2"></i>Database backup</a></li>
            <li><a class="dropdown-item<?= nav_active('admin/audit_logs.php') ?>" href="<?= e(purl('admin/audit_logs.php')) ?>"><i class="bi bi-journal-text me-2"></i>Audit log</a></li>
            <li><a class="dropdown-item<?= nav_active('admin/system_settings.php') ?>" href="<?= e(purl('admin/system_settings.php')) ?>"><i class="bi bi-palette me-2"></i>System settings</a></li>
            <li><a class="dropdown-item<?= nav_active('settings.php') ?>" href="<?= e(purl('settings.php')) ?>"><i class="bi bi-sliders me-2"></i>My settings</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

      <?php if ($u): ?>
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button"
             data-bs-toggle="dropdown" aria-expanded="false" title="Account menu">
            <?= avatar($u['photo'], $u['username'], 30, 'nav-avatar') ?>
            <span class="d-none d-sm-inline fw-semibold"><?= e($u['username']) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="px-3 py-2 d-flex align-items-center gap-2">
              <?= avatar($u['photo'], $u['username'], 36) ?>
              <span class="lh-sm">
                <span class="d-block fw-semibold small"><?= e($u['username']) ?></span>
                <?= role_badge($u['role_level']) ?>
              </span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item<?= nav_active('settings.php') ?>" href="<?= e(purl('settings.php')) ?>">
                <i class="bi bi-person-circle me-2"></i>My details &amp; settings
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="<?= e(app_base_path()) ?>/index.php">
                <i class="bi bi-globe me-2"></i>Public website
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li class="px-3 py-1">
              <form method="post" action="<?= e(purl('logout.php')) ?>">
                <?= csrf_field() ?>
                <button class="dropdown-item text-danger px-0" type="submit">
                  <i class="bi bi-box-arrow-right me-2"></i>Sign out
                </button>
              </form>
            </li>
          </ul>
        </li>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4 flex-grow-1">
<?php foreach (get_flashes() as $f): ?>
  <div class="alert alert-<?= e($f['type'] ?? 'info') ?> alert-dismissible fade show d-none" role="alert" data-toast>
    <?= e($f['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endforeach; ?>
