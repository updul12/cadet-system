<?php
/** Minimal shell for the login page (no navbar) — navy canvas + logo watermark. */
declare(strict_types=1);
$page_title = $page_title ?? 'Sign in';
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
<body class="login-page d-flex flex-column min-vh-100">
<main class="container-fluid px-3 py-4 flex-grow-1">
