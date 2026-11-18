<?php
/** 500 — Server / database error. */
declare(strict_types=1);
$code = 500;
$title = 'Something went wrong';
$icon = 'bi-exclamation-octagon';
$lead = 'We could not complete that request. The issue has been logged — please try again shortly.';
$actionLabel = 'Back to home';
$actionHref = '/index.php';
require __DIR__ . '/_shell.php';
