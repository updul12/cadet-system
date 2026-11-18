<?php
/**
 * Shared error-page shell — no DB, no session, no app bootstrap.
 * Standalone so it renders even when the database is down.
 */
declare(strict_types=1);

$code = $code ?? 500;
$title = $title ?? 'Something went wrong';
$icon = $icon ?? 'bi-exclamation-triangle';
$lead = $lead ?? 'Please try again in a moment.';
$actionLabel = $actionLabel ?? 'Back to home';

/* Best-effort branding without touching the DB. */
$sysName = 'Cadet Unit MS';
$logoUrl = null;

/* App root = this script's directory minus known subfolders (/error, /portal/...). */
$dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if (str_ends_with($dir, '/error')) {
    $dir = substr($dir, 0, -6) ?: '';
}
if (preg_match('#^(.*)/portal(?:/.*)?$#', $dir, $m)) {
    $dir = $m[1] ?: '';
}
$base = rtrim($dir, '/');
$actionHref = $base . '/index.php';

header('Content-Type: text/html; charset=UTF-8');
http_response_code($code);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= (int)$code ?> · <?= htmlspecialchars($title) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($base) ?>/favicon.svg">
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/vendor/bootstrap-icons.css">
  <style>
    body { background:#f4f6f9; }
    .err-card { max-width:520px; margin:8vh auto; }
    .err-code { font-size:5rem; font-weight:800; letter-spacing:-.05em; color:#0d6efd; line-height:1; }
    .err-icon { font-size:2.5rem; color:#0d6efd; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <main class="container flex-grow-1">
    <div class="err-card">
      <div class="card shadow-sm border-0 text-center">
        <div class="card-body p-4 p-md-5">
          <i class="bi <?= htmlspecialchars($icon) ?> err-icon mb-2 d-block"></i>
          <div class="err-code mb-2"><?= (int)$code ?></div>
          <h1 class="h4 mb-2"><?= htmlspecialchars($title) ?></h1>
          <p class="text-muted mb-4"><?= htmlspecialchars($lead) ?></p>
          <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
            <a class="btn btn-primary" href="<?= htmlspecialchars($actionHref) ?>">
              <i class="bi bi-house me-1"></i><?= htmlspecialchars($actionLabel) ?>
            </a>
            <button class="btn btn-outline-secondary" type="button" onclick="history.back()">
              <i class="bi bi-arrow-left me-1"></i>Go back
            </button>
          </div>
        </div>
      </div>
      <p class="text-center text-muted small mt-3 mb-0">
        <?= htmlspecialchars($sysName) ?> · If this keeps happening, contact the unit administrator.
      </p>
    </div>
  </main>
</body>
</html>
