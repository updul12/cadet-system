<?php
/**
 * User account management — Admin only.
 * Actions: create, toggle_status, set_role, reset_password, delete
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireRole(['Admin']);

/* ---------------------------------------------------------------------
 * CSV export (all accounts) and import template — admin only
 * ------------------------------------------------------------------- */
if (isset($_GET['export'])) {
    $rows = db()->query(
        "SELECT u.id, u.username, u.role_level, u.status, u.last_login, u.created_at,
                c.service_no, c.full_name
           FROM users u
           LEFT JOIN cadets c ON c.id = u.cadet_id
          ORDER BY FIELD(u.role_level, 'Admin','Officer','Viewer'), u.username"
    )->fetchAll();

    audit_log('users_export', 'users', null, count($rows) . ' rows');
    send_csv(
        'users_' . date('Ymd_His') . '.csv',
        ['id', 'username', 'role_level', 'status', 'cadet_service_no', 'cadet_full_name', 'last_login', 'created_at'],
        array_map(static fn(array $r): array => [
            $r['id'], $r['username'], $r['role_level'], $r['status'],
            $r['service_no'] ?? '', $r['full_name'] ?? '',
            $r['last_login'] ?? '', $r['created_at'] ?? '',
        ], $rows)
    );
}

if (isset($_GET['template'])) {
    send_csv(
        'users_import_template.csv',
        ['username', 'role_level', 'password', 'service_no'],
        [
            ['jdoe', 'Officer', 'ChangeMe!123', 'TaTUAFCC0001'],
            ['asmith', 'Viewer', 'ChangeMe!123', ''],
        ]
    );
}

$ROLES   = ['Admin', 'Officer', 'Viewer'];
$errors  = [];
$formTarget = '';
$actions = ['create', 'import', 'edit_user', 'toggle_status', 'set_role', 'reset_password', 'delete'];

function active_admin_count(int $excludeId = 0): int
{
    $sql = 'SELECT COUNT(*) FROM users WHERE role_level = \'Admin\' AND status = \'Active\'';
    $args = [];
    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $args[] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return (int)$stmt->fetchColumn();
}

/* ---------------------------------------------------------------------
 * Module grants — only an administrator assigns modules.
 * Admin role implies every module (no rows stored).
 * ------------------------------------------------------------------- */

/** Whitelisted module keys posted from a form ([] for Admin). */
function posted_modules(string $role): array
{
    if ($role === 'Admin') {
        return [];
    }
    $posted = $_POST['modules'] ?? [];
    if (!is_array($posted)) {
        return [];
    }
    $keys = array_values(array_intersect(array_map('strval', $posted), module_keys()));
    sort($keys);
    return $keys;
}

/** Module keys currently granted to a user (sorted). */
function user_modules_of(int $userId): array
{
    $stmt = db()->prepare('SELECT module_key FROM user_modules WHERE user_id = ? ORDER BY module_key');
    $stmt->execute([$userId]);
    $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
    sort($keys);
    return $keys;
}

/** Replace all module grants for one user. */
function save_user_modules(int $userId, array $keys): void
{
    $del = db()->prepare('DELETE FROM user_modules WHERE user_id = ?');
    $del->execute([$userId]);
    if ($keys === []) {
        return;
    }
    $ins = db()->prepare('INSERT INTO user_modules (user_id, module_key) VALUES (?, ?)');
    foreach ($keys as $key) {
        $ins->execute([$userId, $key]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, $actions, true)) {
        redirect(purl('admin/manage_users.php'));
    }

    /* --- Create ------------------------------------------------------- */
    if ($action === 'create') {
        $formTarget = 'createForm';
        $in = [
            'username'   => trim((string)($_POST['username'] ?? '')),
            'role_level' => trim((string)($_POST['role_level'] ?? 'Viewer')),
            'password'   => (string)($_POST['password'] ?? ''),
            'confirm'    => (string)($_POST['confirm'] ?? ''),
            'cadet_id'   => (int)($_POST['cadet_id'] ?? 0),
        ];

        /*
         * A linked cadet's ID number (TaTUAFCC0001) IS the login username —
         * resolved first so the username is forced to that number.
         */
        if ($in['cadet_id'] > 0) {
            $chk = db()->prepare('SELECT id, service_no FROM cadets WHERE id = ?');
            $chk->execute([$in['cadet_id']]);
            $cadet = $chk->fetch();
            if (!$cadet) {
                $errors[] = 'Selected cadet not found.';
                $in['cadet_id'] = 0;
            } else {
                $in['username'] = trim((string)$cadet['service_no']);
                $dup = db()->prepare('SELECT username FROM users WHERE cadet_id = ? LIMIT 1');
                $dup->execute([$in['cadet_id']]);
                $taken = $dup->fetchColumn();
                if ($taken) {
                    $errors[] = 'Cadet ' . $cadet['service_no'] . ' is already linked to the account "' . $taken . '".';
                }
            }
        }

        /* Username format (for linked accounts this is the cadet number). */
        if (!preg_match('/^[a-zA-Z0-9_.\/-]{3,50}$/', $in['username'])) {
            $errors[] = 'Username must be 3–50 characters: letters, numbers, dots, dashes, underscores or slashes.';
        }
        if (!in_array($in['role_level'], $ROLES, true)) {
            $errors[] = 'Choose a valid role.';
        }
        $errors = array_merge($errors, password_policy_errors($in['password']));
        if ($in['password'] !== $in['confirm']) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO users (cadet_id, username, password_hash, role_level, status)
                     VALUES (?, ?, ?, ?, \'Active\')'
                );
                $stmt->execute([
                    $in['cadet_id'] > 0 ? $in['cadet_id'] : null,
                    $in['username'],
                    password_hash($in['password'], PASSWORD_DEFAULT),
                    $in['role_level'],
                ]);
                $newId = (int)db()->lastInsertId();
                $mods  = posted_modules($in['role_level']);
                save_user_modules($newId, $mods);
                audit_log('user_create', 'users', $newId,
                    $in['username'] . ' as ' . $in['role_level']
                    . ($mods ? ' | modules: ' . implode(', ', $mods) : ''));
                flash('success', 'Account "' . $in['username'] . '" created with the ' . $in['role_level'] . ' role.');
                redirect(purl('admin/manage_users.php'));
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    $errors[] = 'The username "' . $in['username'] . '" is already taken.';
                } else {
                    throw $ex;
                }
            }
        }
    }

    /* --- Bulk import from CSV ---------------------------------------- */
    elseif ($action === 'import') {
        $formTarget = 'importForm';
        $file = $_FILES['users_csv'] ?? null;

        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose a CSV file to upload.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed (code ' . (int)$file['error'] . ').';
        } elseif ((int)$file['size'] > 512 * 1024) {
            $errors[] = 'CSV must be 512 KB or smaller (about 500 rows).';
        } else {
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $okMime = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
            if (!in_array($ext, ['csv', 'txt'], true)) {
                $errors[] = 'Only .csv files are accepted.';
            } elseif (!in_array($mime, $okMime, true)) {
                $errors[] = 'The file does not look like a CSV (content type ' . $mime . ').';
            }
        }

        if (!$errors) {
            $fh = fopen($file['tmp_name'], 'r');
            if ($fh === false) {
                $errors[] = 'Could not read the uploaded file.';
            } else {
                $headerLine = (string)fgets($fh);
                if (str_starts_with($headerLine, "\xEF\xBB\xBF")) {
                    $headerLine = substr($headerLine, 3);
                }
                $cols = array_map(
                    static fn($h): string => strtolower(trim((string)$h, " \t\r\n\"\;,")),
                    str_getcsv(rtrim($headerLine, "\r\n"))
                );
                $need = ['username', 'role_level', 'password'];
                $missing = array_diff($need, $cols);
                $serviceIdx = array_search('service_no', $cols, true);

                if ($missing) {
                    $errors[] = 'Header row must include: username, role_level, password'
                        . ' (optional: service_no). Missing — ' . implode(', ', $missing) . '.';
                } else {
                    $idx = [
                        'username'   => (int)array_search('username', $cols, true),
                        'role_level' => (int)array_search('role_level', $cols, true),
                        'password'   => (int)array_search('password', $cols, true),
                        'service_no' => $serviceIdx === false ? -1 : (int)$serviceIdx,
                    ];

                    /* Existing usernames + cadet map (service no → id). */
                    $existing = [];
                    foreach (db()->query('SELECT username FROM users') as $r) {
                        $existing[strtolower((string)$r['username'])] = true;
                    }
                    $cadetMap = [];
                    foreach (db()->query('SELECT id, service_no FROM cadets') as $r) {
                        $key = strtoupper((string)preg_replace('/[\s\/\-]+/', '', (string)$r['service_no']));
                        $cadetMap[$key] = (int)$r['id'];
                    }

                    $created   = 0;
                    $problems  = [];
                    $line      = 1;
                    $insertSt  = db()->prepare(
                        'INSERT INTO users (cadet_id, username, password_hash, role_level, status)
                         VALUES (?, ?, ?, ?, \'Active\')'
                    );

                    while (($row = fgetcsv($fh)) !== false) {
                        $line++;
                        if ($row === [null] || ($row !== false && count($row) === 1 && trim((string)$row[0]) === '')) {
                            continue;
                        }
                        if ($created >= 500) {
                            $problems[] = 'Row limit reached (500 per upload).';
                            break;
                        }

                        $username = trim((string)($row[$idx['username']] ?? ''));
                        $role     = trim((string)($row[$idx['role_level']] ?? ''));
                        $pass     = (string)($row[$idx['password']] ?? '');
                        $svc      = $idx['service_no'] >= 0 ? trim((string)($row[$idx['service_no']] ?? '')) : '';

                        $rowErrors = [];
                        if (!preg_match('/^[a-zA-Z0-9_.\/-]{3,50}$/', $username)) {
                            $rowErrors[] = 'invalid username';
                        }
                        if (!in_array($role, $ROLES, true)) {
                            $rowErrors[] = 'role must be Admin, Officer or Viewer';
                        }
                        $rowErrors = array_merge($rowErrors, password_policy_errors($pass));
                        if (isset($existing[strtolower($username)])) {
                            $rowErrors[] = 'username already exists';
                        }

                        $cadetId = null;
                        if ($svc !== '') {
                            $key = strtoupper((string)preg_replace('/[\s\/\-]+/', '', $svc));
                            if (isset($cadetMap[$key])) {
                                $cadetId = $cadetMap[$key];
                            } else {
                                $rowErrors[] = 'cadet number "' . $svc . '" not found';
                            }
                        }

                        if ($rowErrors) {
                            $problems[] = 'Line ' . $line . ' (' . ($username !== '' ? $username : '?') . '): ' . implode('; ', $rowErrors);
                            continue;
                        }

                        $insertSt->execute([$cadetId, $username, password_hash($pass, PASSWORD_DEFAULT), $role]);
                        $existing[strtolower($username)] = true;
                        $created++;
                    }
                    fclose($fh);

                    audit_log('users_import', 'users', null, $created . ' created, ' . count($problems) . ' skipped');
                    if ($created > 0) {
                        flash('success', $created . ' account(s) created from the CSV.');
                    }
                    if ($problems) {
                        $msg = implode(' · ', array_slice($problems, 0, 8));
                        flash('warning', 'Skipped ' . count($problems) . ' row(s): ' . mb_substr($msg, 0, 700));
                    }
                    redirect(purl('admin/manage_users.php'));
                }
            }
        }
    }

    /* --- Toggle Active / Suspended ----------------------------------- */
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) {
            flash('danger', 'You cannot suspend your own account.');
            redirect(purl('admin/manage_users.php'));
        }
        $stmt = db()->prepare('SELECT id, username, status FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            flash('warning', 'User not found.');
            redirect(purl('admin/manage_users.php'));
        }
        $newStatus = $target['status'] === 'Active' ? 'Suspended' : 'Active';
        if ($newStatus === 'Suspended' && active_admin_count($id) < 1) {
            flash('danger', 'Cannot suspend — this is the last active administrator.');
            redirect(purl('admin/manage_users.php'));
        }
        $upd = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $upd->execute([$newStatus, $id]);
        audit_log('user_status', 'users', $id, $target['username'] . ' → ' . $newStatus);
        flash('success', 'Account "' . $target['username'] . '" is now ' . $newStatus . '.');
        redirect(purl('admin/manage_users.php'));
    }

    /* --- Change role -------------------------------------------------- */
    elseif ($action === 'set_role') {
        $id        = (int)($_POST['id'] ?? 0);
        $roleLevel = trim((string)($_POST['role_level'] ?? ''));
        if (!in_array($roleLevel, $ROLES, true)) {
            flash('danger', 'Choose a valid role.');
            redirect(purl('admin/manage_users.php'));
        }
        if ($id === (int)$user['id']) {
            flash('danger', 'You cannot change your own role.');
            redirect(purl('admin/manage_users.php'));
        }
        $stmt = db()->prepare('SELECT id, username, role_level FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            flash('warning', 'User not found.');
            redirect(purl('admin/manage_users.php'));
        }
        if ($target['role_level'] === 'Admin' && $roleLevel !== 'Admin' && active_admin_count($id) < 1) {
            flash('danger', 'Cannot demote — this is the last active administrator.');
            redirect(purl('admin/manage_users.php'));
        }
        $upd = db()->prepare('UPDATE users SET role_level = ? WHERE id = ?');
        $upd->execute([$roleLevel, $id]);
        if ($roleLevel === 'Admin') {
            save_user_modules($id, []); /* Admins hold every module — no grants needed */
        }
        audit_log('user_role', 'users', $id, $target['username'] . ': ' . $target['role_level'] . ' → ' . $roleLevel);
        flash('success', 'Role for "' . $target['username'] . '" changed to ' . $roleLevel . '.');
        redirect(purl('admin/manage_users.php'));
    }

    /* --- Reset password ----------------------------------------------- */
    elseif ($action === 'reset_password') {
        $id      = (int)($_POST['id'] ?? 0);
        $pass    = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        $errors  = password_policy_errors($pass);
        if ($pass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        if (!$errors) {
            $stmt = db()->prepare('SELECT id, username FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                flash('warning', 'User not found.');
                redirect(purl('admin/manage_users.php'));
            }
            $upd = db()->prepare(
                'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?'
            );
            $upd->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            audit_log('user_password_reset', 'users', $id, $target['username']);
            flash('success', 'Password reset for "' . $target['username'] . '".');
            redirect(purl('admin/manage_users.php'));
        }
        flash('danger', implode(' ', $errors));
        redirect(purl('admin/manage_users.php'));
    }

    /* --- Edit any account (admin) — username, role, status, cadet,
           optional password, optional profile picture ------------------ */
    elseif ($action === 'edit_user') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT id, cadet_id, username, role_level, status, photo FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            flash('warning', 'User not found.');
            redirect(purl('admin/manage_users.php'));
        }
        $isSelf = ($id === (int)$user['id']);

        $in = [
            'username'   => trim((string)($_POST['username'] ?? '')),
            /* Missing/empty field = keep current value (fields are disabled for self). */
            'role_level' => (string)($_POST['role_level'] ?? ''),
            'status'     => (string)($_POST['status'] ?? ''),
            'cadet_id'   => (int)($_POST['cadet_id'] ?? 0),
            'password'   => (string)($_POST['password'] ?? ''),
            'confirm'    => (string)($_POST['confirm'] ?? ''),
        ];
        if ($in['role_level'] === '') {
            $in['role_level'] = (string)$target['role_level'];
        }
        if ($in['status'] === '') {
            $in['status'] = (string)$target['status'];
        }

        /*
         * The linked cadet's ID number (TaTUAFCC0001) IS the login username —
         * resolved first so the username is always forced to that number.
         */
        if ($in['cadet_id'] > 0) {
            $chk = db()->prepare('SELECT id, service_no FROM cadets WHERE id = ?');
            $chk->execute([$in['cadet_id']]);
            $cadet = $chk->fetch();
            if (!$cadet) {
                $errors[] = 'Selected cadet not found.';
                $in['cadet_id'] = 0;
            } else {
                $in['username'] = trim((string)$cadet['service_no']);
                if ($in['cadet_id'] !== (int)$target['cadet_id']) {
                    $dupCadet = db()->prepare('SELECT username FROM users WHERE cadet_id = ? AND id <> ?');
                    $dupCadet->execute([$in['cadet_id'], $id]);
                    $taken = $dupCadet->fetchColumn();
                    if ($taken) {
                        $errors[] = 'Cadet ' . $cadet['service_no'] . ' is already linked to "' . $taken . '".';
                    }
                }
            }
        }

        if (!preg_match('/^[a-zA-Z0-9_.\/-]{3,50}$/', $in['username'])) {
            $errors[] = 'Username must be 3–50 characters: letters, numbers, dots, dashes, underscores or slashes.';
        }
        if (!in_array($in['role_level'], $ROLES, true)) {
            $errors[] = 'Choose a valid role.';
        }
        if (!in_array($in['status'], ['Active', 'Suspended'], true)) {
            $errors[] = 'Choose a valid account status.';
        }
        if ($isSelf && $in['role_level'] !== (string)$target['role_level']) {
            $errors[] = 'You cannot change your own role.';
        }
        if ($isSelf && $in['status'] !== (string)$target['status']) {
            $errors[] = 'You cannot change your own account status.';
        }
        if ($in['password'] !== '') {
            $errors = array_merge($errors, password_policy_errors($in['password']));
            if ($in['password'] !== $in['confirm']) {
                $errors[] = 'New passwords do not match.';
            }
        }

        if (!$errors) {
            $dup = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $dup->execute([$in['username'], $id]);
            if ($dup->fetch()) {
                $errors[] = 'The username "' . $in['username'] . '" is already taken.';
            }
        }

        /* Optional picture replace / remove */
        $newPhoto = $target['photo'];
        if (!$errors) {
            $file = $_FILES['photo'] ?? null;
            if (is_array($file) && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                $upErr = null;
                $path  = handle_photo_upload($file, $upErr);
                if ($upErr !== null) {
                    $errors[] = $upErr;
                } elseif ($path !== null) {
                    $newPhoto = $path;
                }
            } elseif (!empty($_POST['remove_photo'])) {
                $newPhoto = null;
            }
        }

        if (!$errors) {
            $newMods = posted_modules($in['role_level']);
            $oldMods = user_modules_of($id);

            $upd = db()->prepare(
                'UPDATE users SET username = ?, role_level = ?, status = ?, cadet_id = ?, photo = ?
                  WHERE id = ?'
            );
            $upd->execute([
                $in['username'], $in['role_level'], $in['status'],
                $in['cadet_id'] > 0 ? $in['cadet_id'] : null,
                $newPhoto, $id,
            ]);
            save_user_modules($id, $newMods);
            if ($newPhoto !== (string)$target['photo']) {
                delete_photo($target['photo'] ?? null);
            }

            if ($in['password'] !== '') {
                $updPw = db()->prepare(
                    'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?'
                );
                $updPw->execute([password_hash($in['password'], PASSWORD_DEFAULT), $id]);
                audit_log('user_password_change', 'users', $id, $target['username']);
            }

            $changes = [];
            foreach (['username', 'role_level', 'status'] as $f) {
                if ((string)$target[$f] !== $in[$f]) {
                    $changes[] = $f . ': ' . $target[$f] . ' → ' . $in[$f];
                }
            }
            if ((int)$target['cadet_id'] !== $in['cadet_id']) {
                $changes[] = 'cadet link → ' . ($in['cadet_id'] > 0 ? '#' . $in['cadet_id'] : 'none');
            }
            if ($newPhoto !== $target['photo']) {
                $changes[] = 'picture → ' . ($newPhoto ? 'updated' : 'removed');
            }
            if ($in['password'] !== '') {
                $changes[] = 'password reset';
            }
            if ($oldMods !== $newMods) {
                $changes[] = 'modules: [' . implode(', ', $oldMods) . '] → [' . implode(', ', $newMods) . ']';
            }

            audit_log('user_edit', 'users', $id, $in['username'] . ($changes ? ' | ' . implode(', ', $changes) : ' | no field changes'));
            flash('success', 'Account "' . $in['username'] . '" updated.');
            redirect(purl('admin/manage_users.php'));
        }
    }

    /* --- Delete -------------------------------------------------------- */
    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) {
            flash('danger', 'You cannot delete your own account.');
            redirect(purl('admin/manage_users.php'));
        }
        $stmt = db()->prepare('SELECT id, username, role_level, status FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            flash('warning', 'User not found.');
            redirect(purl('admin/manage_users.php'));
        }
        if ($target['role_level'] === 'Admin' && $target['status'] === 'Active' && active_admin_count($id) < 1) {
            flash('danger', 'Cannot delete — this is the last active administrator.');
            redirect(purl('admin/manage_users.php'));
        }
        $del = db()->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$id]);
        audit_log('user_delete', 'users', $id, $target['username']);
        flash('success', 'Account "' . $target['username'] . '" deleted.');
        redirect(purl('admin/manage_users.php'));
    }
}

/* --- Cadets available for linking (linked ones are disabled) --------- */
$cadetOptions = db()->query(
    "SELECT c.id, c.service_no, c.full_name,
            (SELECT u.username FROM users u WHERE u.cadet_id = c.id LIMIT 1) AS linked_user
       FROM cadets c
      ORDER BY c.full_name"
)->fetchAll();

/* --- List accounts --------------------------------------------------- */
$stmt = db()->query(
    "SELECT u.id, u.cadet_id, u.username, u.photo, u.role_level, u.status,
            u.failed_attempts, u.locked_until, u.last_login, u.created_at,
            c.full_name AS cadet_name, c.service_no, c.photo AS cadet_photo
       FROM users u
       LEFT JOIN cadets c ON c.id = u.cadet_id
      ORDER BY FIELD(u.role_level, 'Admin','Officer','Viewer'), u.username"
);
$accounts = $stmt->fetchAll();

/* Module grants per account (Admin rows are always "all"). */
$modsByUser = [];
foreach (db()->query('SELECT user_id, module_key FROM user_modules') as $mr) {
    $modsByUser[(int)$mr['user_id']][] = (string)$mr['module_key'];
}
foreach ($accounts as &$acc) {
    $acc['modules'] = $modsByUser[(int)$acc['id']] ?? [];
}
unset($acc);

$page_title = 'User accounts';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-person-gear me-2"></i>User accounts</h1>
    <p class="text-muted mb-0">Create or import accounts, assign roles, reset passwords and lock access.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createForm">
      <i class="bi bi-person-plus me-1"></i>Create account
    </button>
    <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#importForm">
      <i class="bi bi-file-earmark-arrow-up me-1"></i>Import CSV
    </button>
    <a class="btn btn-outline-secondary" href="<?= e(purl('admin/manage_users.php')) ?>?export=1">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
    </a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2 small" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($err) ?>
  </div>
<?php endforeach; ?>

<!-- Bulk import -->
<div class="collapse <?= $formTarget === 'importForm' ? 'show' : '' ?>" id="importForm">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Import users from CSV</h2>
      <form method="post" enctype="multipart/form-data" class="row g-3 needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <div class="col-12 col-md-5">
          <label class="form-label" for="users_csv">CSV file *</label>
          <input class="form-control" type="file" id="users_csv" name="users_csv"
                 accept=".csv,.txt,text/csv" required>
          <div class="form-text">Max 512 KB · about 500 rows per upload.</div>
        </div>
        <div class="col-12 col-md-7">
          <label class="form-label">Required columns</label>
          <div class="border rounded p-2 bg-light small font-monospace">
            username, role_level, password[, service_no]
          </div>
          <div class="form-text mt-1">
            <code>role_level</code> = Admin / Officer / Viewer.
            <code>service_no</code> (optional) links the account to a cadet so they can sign in
            with their cadet number.
            <a href="<?= e(purl('admin/manage_users.php')) ?>?template=1" class="text-decoration-underline">Download the template</a>.
          </div>
        </div>
        <div class="col-12">
          <button class="btn btn-success" type="submit">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Upload &amp; create accounts
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="collapse <?= $formTarget === 'createForm' ? 'show' : '' ?>" id="createForm">
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">New account</h2>
      <form method="post" class="row g-3 needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12 col-md-4">
          <label class="form-label" for="cu_username">Username *</label>
          <input class="form-control" id="cu_username" name="username" required maxlength="50"
                 pattern="[A-Za-z0-9_.\/\-]{3,50}" value="<?= e($_POST['username'] ?? '') ?>">
          <div class="form-text">3–50 chars: letters, numbers, . _ - / — e.g. <code>TaTUAFCC0001</code>.
            When a cadet is linked, the username becomes that cadet's ID number automatically.</div>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label" for="cu_cadet">Link to cadet <span class="text-muted">(optional)</span></label>
          <select class="form-select" id="cu_cadet" name="cadet_id">
            <option value="0" selected>— No cadet link —</option>
            <?php foreach ($cadetOptions as $c): ?>
              <?php $disabled = $c['linked_user'] !== null && (int)($_POST['cadet_id'] ?? 0) !== (int)$c['id']; ?>
              <option value="<?= (int)$c['id'] ?>" data-service="<?= e($c['service_no']) ?>"
                      <?= $disabled ? 'disabled' : '' ?>
                      <?= ((string)($_POST['cadet_id'] ?? '0') === (string)$c['id']) ? 'selected' : '' ?>>
                <?= e($c['service_no'] . ' — ' . $c['full_name']) ?><?= $c['linked_user'] ? ' (linked: ' . e($c['linked_user']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">A linked cadet can sign in with their cadet number.</div>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label" for="cu_role">Role *</label>
          <select class="form-select" id="cu_role" name="role_level" required>
            <?php foreach ($ROLES as $r): ?>
              <option value="<?= e($r) ?>" <?= (($_POST['role_level'] ?? 'Viewer') === $r) ? 'selected' : '' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12" id="cu_modules_wrap">
          <label class="form-label d-block mb-1">Modules <span class="text-muted fw-normal">(admin assigns)</span></label>
          <div class="d-flex flex-wrap gap-3">
            <?php foreach (module_keys() as $mk): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="modules[]" value="<?= e($mk) ?>"
                       id="cu_mod_<?= e($mk) ?>"
                       <?= in_array($mk, (array)($_POST['modules'] ?? []), true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="cu_mod_<?= e($mk) ?>"><?= e(module_label($mk)) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Only these modules appear for the account. Admins always get everything.</div>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label" for="cu_pass">Password *</label>
          <input class="form-control" type="password" id="cu_pass" name="password" required
                 minlength="8" autocomplete="new-password">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label" for="cu_confirm">Confirm *</label>
          <input class="form-control" type="password" id="cu_confirm" name="confirm" required
                 minlength="8" autocomplete="new-password">
        </div>
        <div class="col-6 col-md-4 d-flex align-items-end">
          <div class="w-100">
            <div class="form-text mb-2">Min 8 chars with upper, lower, digit and special character.</div>
            <button class="btn btn-success w-100" type="submit"><i class="bi bi-check-lg me-1"></i>Create</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No user accounts yet.">
      <thead class="table-dark">
        <tr>
          <th>Username</th>
          <th class="d-none d-md-table-cell">Linked cadet</th>
          <th>Role</th>
          <th class="d-none d-xl-table-cell">Modules</th>
          <th>Status</th>
          <th class="d-none d-lg-table-cell">Last login</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($accounts as $a): ?>
        <?php
          $isSelf = ((int)$a['id'] === (int)$user['id']);
          $locked = ($a['locked_until'] !== null && strtotime((string)$a['locked_until']) > time());
        ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?= avatar($a['photo'] ?: $a['cadet_photo'], $a['cadet_name'] ?: $a['username'], 32) ?>
              <div class="lh-sm min-w-0">
                <strong><?= e($a['username']) ?></strong>
                <?php if ($isSelf): ?><span class="badge text-bg-warning ms-1">you</span><?php endif; ?>
                <?php if ($locked): ?><span class="badge text-bg-danger ms-1"><i class="bi bi-lock me-1"></i>locked</span><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="d-none d-md-table-cell small">
            <?= $a['cadet_name'] ? e($a['cadet_name'] . ' (' . $a['service_no'] . ')') : '<span class="text-muted">—</span>' ?>
          </td>
          <td>
            <?php if ($isSelf): ?>
              <?= role_badge($a['role_level']) ?>
            <?php else: ?>
              <form method="post" class="d-flex align-items-center gap-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_role">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <select name="role_level" class="form-select form-select-sm" style="width:auto"
                        onchange="this.form.submit()">
                  <?php foreach ($ROLES as $r): ?>
                    <option value="<?= e($r) ?>" <?= $a['role_level'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </td>
          <td class="d-none d-xl-table-cell">
            <?php if ($a['role_level'] === 'Admin'): ?>
              <span class="badge bg-dark">All</span>
            <?php else: ?>
              <?php foreach (module_keys() as $mk): ?>
                <span class="badge <?= in_array($mk, $a['modules'], true) ? 'bg-success' : 'bg-secondary bg-opacity-25 text-muted' ?>">
                  <?= e(module_label($mk)) ?>
                </span>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isSelf): ?>
              <?= status_badge($a['status']) ?>
            <?php else: ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm <?= $a['status'] === 'Active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                        type="submit"
                        data-confirm="<?= $a['status'] === 'Active'
                          ? 'Suspend ' . e($a['username']) . '? They will be unable to sign in.'
                          : 'Reactivate ' . e($a['username']) . '?' ?>">
                  <?= status_badge($a['status']) ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
          <td class="d-none d-lg-table-cell small text-muted">
            <?= $a['last_login'] ? e(date('d M Y H:i', strtotime((string)$a['last_login']))) : 'Never' ?>
          </td>
          <td class="text-end text-nowrap">
            <div class="btn-group" role="group">
              <button class="btn btn-sm btn-outline-primary" type="button"
                      data-bs-toggle="modal" data-bs-target="#editModal"
                      data-id="<?= (int)$a['id'] ?>" data-username="<?= e($a['username']) ?>"
                      data-role="<?= e($a['role_level']) ?>" data-status="<?= e($a['status']) ?>"
                      data-cadet="<?= (int)($a['cadet_id'] ?? 0) ?>"
                      data-photo="<?= $a['photo'] ? '1' : '0' ?>"
                      data-self="<?= $isSelf ? '1' : '0' ?>"
                      data-modules="<?= e(implode(',', $a['modules'])) ?>"
                      title="Edit user">
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary" type="button"
                      data-bs-toggle="modal" data-bs-target="#resetModal"
                      data-id="<?= (int)$a['id'] ?>" data-username="<?= e($a['username']) ?>"
                      title="Reset password">
                <i class="bi bi-key"></i>
              </button>
              <?php if (!$isSelf): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"
                        data-confirm="Permanently delete <?= e($a['username']) ?>?">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$accounts): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No accounts found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Reset password modal -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content needs-validation" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="id" id="reset_id" value="">
      <div class="modal-header">
        <h2 class="modal-title h5">Reset password — <span id="reset_uname"></span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="reset_pass">New password *</label>
          <input class="form-control" type="password" id="reset_pass" name="password"
                 required minlength="8" autocomplete="new-password">
          <div class="form-text">Min 8 chars: upper, lower, digit, special.</div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="reset_confirm">Confirm *</label>
          <input class="form-control" type="password" id="reset_confirm" name="confirm"
                 required minlength="8" autocomplete="new-password">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Reset</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit account modal (admin can edit every account) -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content needs-validation" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="id" id="edit_id" value="">
      <div class="modal-header">
        <h2 class="modal-title h5">Edit account — <span id="edit_uname_title"></span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label" for="edit_username">Username *</label>
            <input class="form-control" id="edit_username" name="username" required maxlength="50"
                   pattern="[A-Za-z0-9_.\/\-]{3,50}">
            <div class="form-text">The linked cadet's ID number (e.g. <code>TaTUAFCC0001</code>)
              is used as the login username.</div>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="edit_role">Role</label>
            <select class="form-select" id="edit_role" name="role_level">
              <?php foreach ($ROLES as $r): ?>
                <option value="<?= e($r) ?>"><?= e($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="edit_status">Status</label>
            <select class="form-select" id="edit_status" name="status">
              <option value="Active">Active</option>
              <option value="Suspended">Suspended</option>
            </select>
            <div class="form-text" id="edit_self_note" hidden>Own role/status can't change.</div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="edit_cadet">Linked cadet</label>
            <select class="form-select" id="edit_cadet" name="cadet_id">
              <option value="0">— No cadet link —</option>
              <?php foreach ($cadetOptions as $c): ?>
                <option value="<?= (int)$c['id'] ?>"
                        data-linked="<?= e((string)($c['linked_user'] ?? '')) ?>"
                        data-service="<?= e($c['service_no']) ?>">
                  <?= e($c['service_no'] . ' — ' . $c['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">A linked cadet can sign in with that cadet number.</div>
          </div>
          <div class="col-12" id="edit_modules_wrap">
            <label class="form-label d-block mb-1">Modules <span class="text-muted fw-normal">(admin assigns)</span></label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach (module_keys() as $mk): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="modules[]" value="<?= e($mk) ?>"
                         id="em_mod_<?= e($mk) ?>">
                  <label class="form-check-label" for="em_mod_<?= e($mk) ?>"><?= e(module_label($mk)) ?></label>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="form-text">Only these modules appear for the account. Admins always get everything.</div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="edit_photo">Profile picture
              <span class="text-muted small">(optional)</span></label>
            <input class="form-control" type="file" id="edit_photo" name="photo"
                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <div class="form-check mt-1" id="edit_remove_wrap" hidden>
              <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="edit_remove">
              <label class="form-check-label" for="edit_remove">Remove the current picture</label>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="edit_pass">New password
              <span class="text-muted small">(optional)</span></label>
            <input class="form-control" type="password" id="edit_pass" name="password"
                   minlength="8" autocomplete="new-password" maxlength="200">
            <div class="form-text">Leave blank to keep the current password.</div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="edit_pass2">Confirm new password</label>
            <input class="form-control" type="password" id="edit_pass2" name="confirm"
                   minlength="8" autocomplete="new-password" maxlength="200">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save changes</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('resetModal').addEventListener('show.bs.modal', function (e) {
  var btn = e.relatedTarget;
  document.getElementById('reset_id').value = btn.getAttribute('data-id');
  document.getElementById('reset_uname').textContent = btn.getAttribute('data-username');
});

/* Suggest the cadet's service number as the sign-in username. */
(function () {
  'use strict';
  var sel  = document.getElementById('cu_cadet');
  var user = document.getElementById('cu_username');
  if (!sel || !user) return;
  sel.addEventListener('change', function () {
    var opt = sel.options[sel.selectedIndex];
    var svc = opt ? (opt.getAttribute('data-service') || '') : '';
    /* The cadet's ID number always becomes the login username. */
    if (svc) { user.value = svc; }
  });
})();

/* ---- Edit-account modal: fill fields from the triggering row -------- */
(function () {
  'use strict';
  var modal = document.getElementById('editModal');
  if (!modal) return;

  modal.addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    if (!btn) return;

    var username = btn.getAttribute('data-username') || '';
    var isSelf   = btn.getAttribute('data-self') === '1';
    var hasPhoto = btn.getAttribute('data-photo') === '1';

    document.getElementById('edit_id').value           = btn.getAttribute('data-id') || '';
    document.getElementById('edit_uname_title').textContent = username;
    document.getElementById('edit_username').value     = username;

    var role   = document.getElementById('edit_role');
    var status = document.getElementById('edit_status');
    role.value   = btn.getAttribute('data-role') || 'Viewer';
    status.value = btn.getAttribute('data-status') || 'Active';
    role.disabled   = isSelf;
    status.disabled = isSelf;

    /* Linked cadet: select current, grey out cadets owned by other accounts */
    var sel = document.getElementById('edit_cadet');
    var current = btn.getAttribute('data-cadet') || '0';
    sel.value = current;
    Array.prototype.forEach.call(sel.options, function (opt) {
      var linked = opt.getAttribute('data-linked') || '';
      var owned  = linked !== '' && linked !== username;
      opt.disabled = owned && opt.value !== current;
      if (owned && opt.value !== current && opt.textContent.indexOf('(linked') === -1) {
        opt.textContent += ' (linked: ' + linked + ')';
      }
    });
    if (sel.value === '') { sel.value = '0'; }

    /* Module grants (admins always get everything — section hidden) */
    var granted = (btn.getAttribute('data-modules') || '').split(',').filter(Boolean);
    Array.prototype.forEach.call(
      document.querySelectorAll('#edit_modules_wrap input[name="modules[]"]'),
      function (cb) { cb.checked = granted.indexOf(cb.value) !== -1; }
    );
    var mwrap = document.getElementById('edit_modules_wrap');
    if (mwrap) { mwrap.hidden = role.value === 'Admin'; }

    /* Reset optional fields */
    document.getElementById('edit_remove_wrap').hidden = !hasPhoto;
    document.getElementById('edit_remove').checked = false;
    document.getElementById('edit_photo').value  = '';
    document.getElementById('edit_pass').value   = '';
    document.getElementById('edit_pass2').value  = '';

    var form = modal.querySelector('form');
    if (form) { form.classList.remove('was-validated'); }
  });
})();

/* Edit modal: picking a cadet sets the username to that cadet's ID number. */
(function () {
  'use strict';
  var sel  = document.getElementById('edit_cadet');
  var user = document.getElementById('edit_username');
  if (!sel || !user) return;
  sel.addEventListener('change', function () {
    var opt = sel.options[sel.selectedIndex];
    var svc = opt ? (opt.getAttribute('data-service') || '') : '';
    if (svc) { user.value = svc; }
  });
})();

/* Module checkboxes only apply to Officer/Viewer — hidden for Admin. */
(function () {
  'use strict';
  function bind(roleId, wrapId) {
    var role = document.getElementById(roleId);
    var wrap = document.getElementById(wrapId);
    if (!role || !wrap) return;
    var sync = function () { wrap.hidden = role.value === 'Admin'; };
    role.addEventListener('change', sync);
    sync();
  }
  bind('cu_role', 'cu_modules_wrap');
  bind('edit_role', 'edit_modules_wrap');
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
