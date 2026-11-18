<?php
/** UI helpers — badges, dates, avatars, settings, CSV, photo upload, nav. */
declare(strict_types=1);

function role_badge(?string $role): string
{
    $map = ['Admin' => 'danger', 'Officer' => 'primary', 'Viewer' => 'secondary'];
    $cls = $map[$role] ?? 'secondary';
    $icon = ['Admin' => 'bi-shield-lock-fill', 'Officer' => 'bi-person-badge-fill', 'Viewer' => 'bi-eye-fill'][$role] ?? 'bi-person';
    return '<span class="badge text-bg-' . $cls . '"><i class="bi ' . $icon . ' me-1"></i>' . e($role ?? '') . '</span>';
}

function status_badge(string $status): string
{
    $map = [
        'Active' => 'success', 'Alumnus' => 'info', 'Discharged' => 'secondary',
        'Suspended' => 'danger', 'Present' => 'success', 'Absent' => 'danger',
        'Excused' => 'warning', 'Pending' => 'secondary', 'Completed' => 'success',
        'New' => 'success', 'Good' => 'info', 'Fair' => 'warning', 'Damaged' => 'danger',
        'Commendation' => 'success', 'Offense' => 'danger',
        'Unread' => 'warning', 'Resolved' => 'success',
        'Reviewed' => 'info', 'Accepted' => 'success', 'Rejected' => 'danger',
        'Urgent' => 'danger', 'Normal' => 'secondary',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . e($status) . '</span>';
}

function grade_badge(string $grade): string
{
    $map = ['A' => 'success', 'B' => 'info', 'C' => 'warning', 'D' => 'danger'];
    $cls = $map[$grade] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">Grade ' . e($grade) . '</span>';
}

function format_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : '—';
}

function photo_url(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === '') {
        return null;
    }
    if (!preg_match('#^storage/photos/[a-f0-9]{32}\.(jpg|png|webp)$#', $relativePath)) {
        return null;
    }
    return app_base_path() . '/' . $relativePath;
}

function avatar(?string $photo, ?string $name, int $size = 36, string $class = ''): string
{
    $name  = trim((string)$name);
    $label = $name !== '' ? $name : 'User';
    $style = sprintf('width:%dpx;height:%dpx;font-size:%dpx;', $size, $size, max(10, (int)round($size * 0.4)));

    $url = photo_url($photo);
    if ($url !== null) {
        return '<img src="' . e($url) . '" alt="' . e($label) . '" width="' . $size . '" height="' . $size
            . '" class="avatar rounded-circle ' . e($class) . '" style="' . $style . '" loading="lazy">';
    }

    $initials = initials_of($name);
    $palette  = ['#111827', '#4b5563', '#16a34a'];
    $bg       = $palette[crc32($name !== '' ? $name : 'u') % count($palette)];

    return '<span class="avatar avatar-initials rounded-circle ' . e($class) . '"'
        . ' style="' . $style . 'background:' . $bg . ';" aria-hidden="true">' . e($initials) . '</span>';
}

function initials_of(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($words as $w) {
        if ($w === '') {
            continue;
        }
        $letters .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($letters) >= 2) {
            break;
        }
    }
    return $letters !== '' ? $letters : 'U';
}

function all_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM system_settings');
        foreach ($rows as $row) {
            $cache[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
    } catch (Throwable $ex) {
        error_log('[SETTINGS] ' . $ex->getMessage());
    }
    return $cache;
}

function get_setting(string $key, string $default = ''): string
{
    $s = all_settings();
    $v = $s[$key] ?? '';
    return $v !== '' ? $v : $default;
}

function set_setting(string $key, string $value, ?int $userId = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_by   = VALUES(updated_by)'
    );
    $stmt->execute([$key, $value !== '' ? $value : null, $userId]);
    all_settings(true);
}

function system_name(): string
{
    return get_setting('system_name', 'Cadet Unit MS');
}

function system_logo(): ?string
{
    $v = get_setting('system_logo');
    return $v !== '' ? photo_url($v) : null;
}

/** Digits-only WhatsApp number in international format (no +, no leading 0). */
function whatsapp_number(): string
{
    $digits = preg_replace('/\D+/', '', get_setting('whatsapp_number')) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '0')) {
        $cc = preg_replace('/\D+/', '', get_setting('whatsapp_country_code', '233')) ?: '233';
        $digits = $cc . substr($digits, 1);
    }
    return $digits;
}

function whatsapp_default_message(): string
{
    return get_setting(
        'whatsapp_message',
        'Hello, I am inquiring about the Cadet Unit. I would like to know more about recruitment and training schedules.'
    );
}

/** Click-to-chat URL; pass '' for no pre-filled text. */
function whatsapp_link(?string $message = null): string
{
    $url = 'https://wa.me/' . whatsapp_number();
    $msg = $message ?? whatsapp_default_message();
    if ($msg !== '') {
        $url .= '?text=' . rawurlencode($msg);
    }
    return $url;
}

function next_service_no(): string
{
    $prefix = service_no_prefix();
    $last   = '';
    try {
        $stmt = db()->prepare('SELECT service_no FROM cadets WHERE service_no LIKE ? ORDER BY service_no DESC LIMIT 1');
        $stmt->execute([$prefix . '%']);
        $last = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $ex) {
        error_log('[SERVICE-NO] ' . $ex->getMessage());
    }
    $n = 1;
    if ($last !== '' && preg_match('/(\d+)$/', $last, $m)) {
        $n = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function is_service_no_format(string $serviceNo): bool
{
    return (bool)preg_match('/^' . preg_quote(service_no_prefix(), '/') . '\d{1,6}$/i', $serviceNo);
}

function send_csv(string $filename, array $header, array $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    $guard = static function (array $row): array {
        return array_map(static function ($v): string {
            $v = (string)$v;
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                $v = "'" . $v;
            }
            return $v;
        }, $row);
    };

    fputcsv($out, $guard($header));
    foreach ($rows as $row) {
        fputcsv($out, $guard($row));
    }
    fclose($out);
    exit;
}

/** Absolute portal URL for a module path, e.g. purl('cadets/list.php'). */
function purl(string $path): string
{
    return portal_base_path() . '/' . ltrim($path, '/');
}

/** Active nav highlight — matches by path suffix so module subfolders work. */
function nav_active(string $script): string
{
    $path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $current = $path;
    $pos = strpos($path, '/portal/');
    if ($pos !== false) {
        $current = substr($path, $pos + 8);
    } else {
        $current = basename($path);
    }
    return ($current === $script || str_ends_with($current, '/' . $script)) ? ' active' : '';
}

function handle_photo_upload(array $file, ?string &$error): ?string
{
    $error = null;

    if (!isset($file['error']) || is_array($file['error'])) {
        $error = 'Invalid upload payload.';
        return null;
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'Image is larger than the server limit (upload_max_filesize). Try a smaller file.',
            UPLOAD_ERR_FORM_SIZE  => 'Image is larger than the form size limit. Try a smaller file.',
            UPLOAD_ERR_PARTIAL    => 'Upload was interrupted — please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server error: could not write the uploaded file.',
        ];
        $error = $map[(int)$file['error']] ?? 'Upload failed (code ' . (int)$file['error'] . ').';
        return null;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        $error = 'Photo must be 2 MB or smaller.';
        return null;
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        $error = 'Only JPG, PNG or WebP images are allowed.';
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        $error = 'File content does not match an allowed image type.';
        return null;
    }

    $dir = dirname(__DIR__) . '/storage/photos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $error = 'Upload directory unavailable.';
        return null;
    }

    $name = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = $dir . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $error = 'Could not save the uploaded file.';
        return null;
    }
    @chmod($dest, 0644);

    return 'storage/photos/' . $name;
}

function delete_photo(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }
    if (!preg_match('#^storage/photos/[a-f0-9]{32}\.(jpg|png|webp)$#', $relativePath)) {
        return;
    }
    $abs = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($abs)) {
        @unlink($abs);
    }
}
