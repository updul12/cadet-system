<?php
/** 403 — Access denied / insufficient role. */
declare(strict_types=1);
$code = 403;
$title = 'Access denied';
$icon = 'bi-shield-x';
$lead = 'Your account does not have permission to view this page. Ask an administrator to grant access.';
$actionLabel = 'Back to home';
$actionHref = '/index.php';
require __DIR__ . '/_shell.php';
