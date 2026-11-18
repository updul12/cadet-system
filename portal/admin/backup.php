<?php
/** Database backup — Admin only (exports a .sql dump). */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireRole(['Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();

    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $out = "-- " . system_name() . " backup\n-- " . date('Y-m-d H:i:s') . "\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $t) {
        $create = db()->query("SHOW CREATE TABLE `$t`")->fetch();
        $out .= "DROP TABLE IF EXISTS `$t`;\n{$create[1]};\n\n";

        $rows = db()->query("SELECT * FROM `$t`")->fetchAll();
        if (!$rows) {
            continue;
        }
        $cols = array_map(static fn($c) => "`$c`", array_keys($rows[0]));
        $out .= "INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES\n";
        $vals = [];
        foreach ($rows as $r) {
            $parts = [];
            foreach ($r as $v) {
                $parts[] = $v === null ? 'NULL' : db()->quote((string)$v);
            }
            $vals[] = '(' . implode(', ', $parts) . ')';
        }
        $out .= implode(",\n", $vals) . ";\n\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

    audit_log('db_backup', 'database', null, count($tables) . ' tables');
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="cadet_db_' . date('Ymd_His') . '.sql"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

$size = @filesize(__DIR__ . '/../../cadet_db.sql');
$page_title = 'Database backup';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-database-down me-2"></i>Export database</div>
      <div class="card-body p-4">
        <p class="text-muted">
          Downloads a full SQL dump of every table (schema + data).
          Keep a copy off this machine after each major update.
        </p>
        <ul class="small text-muted mb-3">
          <li>Tables included: cadets, users, attendance, inventory, news, applications…</li>
          <li>File format: plain <code>.sql</code> (phpMyAdmin compatible)</li>
          <li>Restore: Import in phpMyAdmin or <code>mysql -u root &lt; file.sql</code></li>
        </ul>
        <form method="post">
          <?= csrf_field() ?>
          <button class="btn btn-primary btn-lg" type="submit">
            <i class="bi bi-download me-1"></i>Download backup now
          </button>
        </form>
        <div class="small text-muted mt-3">
          Last schema file on disk: <?= $size ? number_format((int)$size / 1024, 1) . ' KB' : 'n/a' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
