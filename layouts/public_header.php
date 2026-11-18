<?php
/**
 * Public site header — no auth required. Assets resolve from project root.
 * CSRF/session bootstrap comes from auth_check.php (included by the page).
 */
declare(strict_types=1);
if (!isset($page_title)) {
    $page_title = 'Cadet Unit MS';
}
$sysName = system_name();
$logoUrl = system_logo();
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicNav = [
    'index.php' => 'Home',
    'about.php' => 'About',
    'news.php'  => 'News',
    'join.php'  => 'Recruitment',
    'contact.php' => 'Contact',
];
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
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2"
       href="<?= e(app_base_path()) ?>/index.php" title="<?= e($sysName) ?>">
      <?php if ($logoUrl): ?>
        <img src="<?= e($logoUrl) ?>" alt="" class="brand-logo">
      <?php else: ?>
        <i class="bi bi-shield-fill-check brand-shield"></i>
      <?php endif; ?>
      <span class="brand-name"><?= e($sysName) ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav"
            aria-controls="publicNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="publicNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php foreach ($publicNav as $file => $label): ?>
          <li class="nav-item">
            <a class="nav-link<?= $current === $file ? ' active' : '' ?>"
               href="<?= e(app_base_path()) ?>/<?= e($file) ?>"><?= e($label) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <?php if (is_logged_in()): ?>
            <a class="btn btn-outline-light btn-sm" href="<?= e(portal_base_path()) ?>/index.php">
              <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
          <?php else: ?>
            <a class="btn btn-outline-light btn-sm" href="<?= e(portal_base_path()) ?>/login.php">
              <i class="bi bi-box-arrow-in-right me-1"></i>Member Portal
            </a>
          <?php endif; ?>
        </li>
      </ul>
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
