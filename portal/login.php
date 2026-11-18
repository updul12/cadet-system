<?php
/**
 * Login — CSRF, password verification, account status, lockout, session hardening.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_check.php';

/* Already signed in? Go to dashboard. */
if (is_logged_in()) {
    redirect(portal_base_path() . '/index.php');
}

$errors = [];
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    /* Per-IP attempt throttle — no attempt recorded while blocked,
     * so the window cannot be slid forward by the blocked client itself. */
    $ipBlock = ip_throttle_seconds();
    if ($ipBlock > 0) {
        $mins = (int)ceil($ipBlock / 60);
        audit_log('login_blocked_ip', null, null, client_ip() . ' · ' . $username);
        $errors[] = "Too many attempts from this device. Try again in {$mins} minute(s).";
    } elseif ($username === '' || $password === '') {
        $errors[] = 'Username / cadet number and password are required.';
    } else {
        /*
         * Identifier may be the account username OR the cadet service number
         * (letters + digits together, e.g. CA-2026-100). Lockout is keyed on
         * the resolved username so service-number attempts are counted too.
         */
        $row    = find_user_by_identifier($username);
        $lockKey = $row['username'] ?? $username;

        /* Brute-force lockout */
        $lockSeconds = is_account_locked($lockKey);
        if ($lockSeconds > 0) {
            $mins = (int)ceil($lockSeconds / 60);
            record_login_attempt($username, false);
            audit_log('login_blocked_locked', null, $row ? (int)$row['id'] : null, $username);
            $errors[] = "Too many failed attempts. Try again in {$mins} minute(s).";
        } else {
            $valid = $row && password_verify($password, $row['password_hash']);

            if (!$valid) {
                record_login_attempt($username, false);
                if ($row) {
                    register_failed_login($lockKey);
                }
                audit_log('login_fail', null, $row ? (int)$row['id'] : null, $username);
                /* Same message for unknown user and wrong password */
                $errors[] = 'Invalid username / cadet number or password.';
            } elseif ($row['status'] !== 'Active') {
                record_login_attempt($username, false);
                audit_log('login_fail_suspended', 'users', (int)$row['id'], $username);
                $errors[] = 'This account has been suspended. Contact an administrator.';
            } else {
                /* Success — harden session, reset lockout, stamp last_login. */
                record_login_attempt($username, true);
                clear_login_failures((int)$row['id']);
                clear_ip_failures();

                session_regenerate_id(true);
                $_SESSION['user_id']        = (int)$row['id'];
                $_SESSION['username']       = $row['username'];
                $_SESSION['role_level']     = $row['role_level'];
                $_SESSION['last_activity']  = time();
                unset($_SESSION['csrf_token']); // rotate CSRF token on privilege change

                try {
                    $upd = db()->prepare('UPDATE users SET last_login = ? WHERE id = ?');
                    $upd->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
                } catch (Throwable $ex) {
                    error_log('[LOGIN] last_login: ' . $ex->getMessage());
                }

                audit_log('login_success', 'users', (int)$row['id'], $username);
                redirect(portal_base_path() . '/index.php');
            }
        }
    }
}

$page_title = 'Sign in';
require __DIR__ . '/../includes/header_login.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-sm-8 col-md-6 col-lg-5 col-xl-4">
    <div class="card shadow login-card">
      <div class="card-body p-4">
        <div class="text-center mb-4">
          <?php if (system_logo()): ?>
            <img src="<?= e(system_logo()) ?>" alt="" class="login-logo mb-2">
          <?php else: ?>
            <i class="bi bi-shield-fill-check login-shield display-4"></i>
          <?php endif; ?>
          <h1 class="h4 mt-2 mb-0"><?= e(system_name()) ?></h1>
          <p class="text-muted small mb-0">Secure portal — authorised personnel only</p>
        </div>

        <?php foreach (get_flashes() as $f): ?>
          <div class="alert alert-<?= e($f['type'] ?? 'info') ?> alert-dismissible fade show d-none py-2 small"
               role="alert" data-toast>
            <?= e($f['message'] ?? '') ?>
          </div>
        <?php endforeach; ?>

        <?php foreach ($errors as $err): ?>
          <div class="alert alert-danger py-2 small" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($err) ?>
          </div>
        <?php endforeach; ?>

        <form method="post" id="loginForm" novalidate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username or cadet number</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
              <input class="form-control" type="text" id="username" name="username"
                     value="<?= e($username) ?>" required autofocus autocomplete="username"
                     maxlength="50" aria-describedby="usernameFeedback"
                     placeholder="Enter username or cadet number">
              <div class="invalid-feedback" id="usernameFeedback">Please enter your username or cadet number.</div>
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
              <input class="form-control" type="password" id="password" name="password"
                     required autocomplete="current-password" maxlength="200"
                     aria-describedby="passwordFeedback">
              <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                      aria-label="Show password" title="Show/hide password">
                <i class="bi bi-eye" id="toggleIcon"></i>
              </button>
              <div class="invalid-feedback" id="passwordFeedback">Please enter your password.</div>
            </div>
          </div>
          <button class="btn btn-primary w-100 btn-lg" type="submit" id="submitBtn">
            <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var form     = document.getElementById('loginForm');
  var username = document.getElementById('username');
  var password = document.getElementById('password');
  var toggle   = document.getElementById('togglePassword');
  var icon     = document.getElementById('toggleIcon');
  var submit   = document.getElementById('submitBtn');

  toggle.addEventListener('click', function () {
    var showing = password.type === 'text';
    password.type = showing ? 'password' : 'text';
    icon.className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
    password.focus();
  });

  function validateField(el) {
    if (el.value.trim() === '') {
      el.classList.add('is-invalid');
      el.classList.remove('is-valid');
      return false;
    }
    el.classList.remove('is-invalid');
    el.classList.add('is-valid');
    return true;
  }

  [username, password].forEach(function (el) {
    el.addEventListener('input', function () {
      if (el.classList.contains('is-invalid') || el.value.trim() !== '') {
        validateField(el);
      }
    });
  });

  form.addEventListener('submit', function (e) {
    var okUser = validateField(username);
    var okPass = validateField(password);
    if (!okUser || !okPass) {
      e.preventDefault();
      (okUser ? password : username).focus();
      return;
    }
    submit.disabled = true;
    submit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Signing in…';
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer_login.php'; ?>
