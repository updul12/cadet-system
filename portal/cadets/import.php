<?php
/**
 * Bulk cadet import — upload an Excel (.xlsx) or CSV spreadsheet, e.g. one
 * exported from a Google Form response sheet.
 * Rows with problems are skipped; valid rows are created.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../core/spreadsheet.php';

requireModule('cadets');
$user = requireRole(['Admin', 'Officer']);

/** Hard limits for one upload. */
const CADET_IMPORT_MAX_ROWS  = 1000;
const CADET_IMPORT_MAX_BYTES = 2097152; /* 2 MB */

/** Columns offered in the downloadable template (in order). */
const CADET_IMPORT_COLUMNS = [
    'service_no', 'full_name', 'gender', 'phone', 'email',
    'department', 'rank', 'role', 'status', 'enrolled_date',
];

function import_service_key(string $serviceNo): string
{
    return strtoupper((string)preg_replace('/[\s\/\-]+/', '', $serviceNo));
}

function import_cell(array $row, array $fieldCols, string $field): string
{
    $col = $fieldCols[$field] ?? -1;
    return $col < 0 ? '' : trim((string)($row[$col] ?? ''));
}

function import_row_is_empty(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string)$cell) !== '') {
            return false;
        }
    }
    return true;
}

/** Template — CSV always, .xlsx when the workbook writer is usable. */
if (isset($_GET['template'])) {
    $header = CADET_IMPORT_COLUMNS;
    $samples = [
        ['', 'Ama Mensah', 'Female', '0244 123 456', 'ama.mensah@example.com',
         'Signals', 'Recruit / Cadet', 'Signaller', 'Active', date('Y-m-d')],
        [service_no_prefix() . '0042', 'Kwame Boateng', 'Male', '020 987 6543', '',
         'Logistics', 'Lance Corporal', '', '', ''],
    ];

    if (($_GET['template'] ?? '') !== 'xlsx') {
        send_csv('cadets_import_template.csv', $header, $samples);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cadet_tpl_');
    $xlsx = ($tmp !== false ? $tmp : sys_get_temp_dir() . '/cadet_tpl_') . '.xlsx';
    if ($tmp !== false) {
        @unlink($tmp);
    }
    try {
        xlsx_write($xlsx, array_merge([$header], $samples));
        $bytes = file_get_contents($xlsx);
    } catch (Throwable $ex) {
        $bytes = false;
    }
    @unlink($xlsx);

    if ($bytes === false || $bytes === '') {
        send_csv('cadets_import_template.csv', $header, $samples);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="cadets_import_template.xlsx"');
    header('Content-Length: ' . strlen($bytes));
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $bytes;
    exit;
}

$errors = [];

/* ---------------------------------------------------------------------
 * Import
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'import') {
        redirect(purl('cadets/import.php'));
    }

    $file = $_FILES['cadets_file'] ?? null;
    if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Choose an Excel or CSV file to upload.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed (code ' . (int)$file['error'] . ').';
    } elseif ((int)$file['size'] > CADET_IMPORT_MAX_BYTES) {
        $errors[] = 'File must be 2 MB or smaller (about ' . CADET_IMPORT_MAX_ROWS . ' rows).';
    }

    $ext = '';
    if (!$errors) {
        $ext     = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $mime    = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $okCsv   = ['text/plain', 'text/csv', 'application/csv', 'text/comma-separated-values',
            'text/x-csv', 'application/x-csv', 'application/vnd.ms-excel', 'application/octet-stream'];
        $okXlsx  = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
            'application/binary'];

        if ($ext === 'xlsx') {
            if (!in_array($mime, $okXlsx, true)) {
                $errors[] = 'The file does not look like an Excel workbook (content type ' . $mime . ').';
            }
        } elseif (in_array($ext, ['csv', 'txt'], true)) {
            if (!in_array($mime, $okCsv, true)) {
                $errors[] = 'The file does not look like a CSV (content type ' . $mime . ').';
            }
        } else {
            $errors[] = 'Only .xlsx, .csv or .txt files are accepted.';
        }
    }

    $rows = [];
    if (!$errors) {
        /* Read one row beyond the limit so truncation can be reported. */
        $cap = CADET_IMPORT_MAX_ROWS + 2;
        try {
            $rows = $ext === 'xlsx'
                ? xlsx_rows((string)$file['tmp_name'], $cap)
                : csv_rows((string)$file['tmp_name'], $cap);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('[CADET-IMPORT] ' . $ex->getMessage());
            $errors[] = 'The file could not be read. Re-save it as .csv and try again.';
        }
    }

    if (!$errors) {
        if (count($rows) < 2) {
            $errors[] = 'The file has no data rows — only a header row (or nothing) was found.';
        } else {
            $fieldCols  = spreadsheet_map_headers($rows[0]);
            $missingCol = [];
            foreach (['full_name', 'gender'] as $required) {
                if (!isset($fieldCols[$required])) {
                    $missingCol[] = $required;
                }
            }
            if ($missingCol) {
                $errors[] = 'Header row is missing required column(s): ' . implode(', ', $missingCol)
                    . '. Download the template to see the expected headers.';
            }
        }
    }

    if (!$errors) {
        $rankNames = array_map('strval', db()->query(
            'SELECT rank_name FROM cadet_ranks ORDER BY seniority_level'
        )->fetchAll(PDO::FETCH_COLUMN));
        $rankLookup = [];
        foreach ($rankNames as $rn) {
            $rankLookup[mb_strtolower($rn)] = $rn;
        }
        $defaultRank = $rankNames[0] ?? 'Cadet';

        $used = [];
        foreach (db()->query('SELECT service_no FROM cadets') as $existing) {
            $used[import_service_key((string)$existing['service_no'])] = true;
        }

        $prefix    = service_no_prefix();
        $autoNum   = null;
        $autoWidth = 4;
        $allocServiceNo = static function () use (&$used, &$autoNum, &$autoWidth, $prefix): string {
            if ($autoNum === null) {
                $base = next_service_no();
                $autoNum = preg_match('/(\d+)$/', $base, $m) ? (int)$m[1] : 1;
                $autoWidth = preg_match('/(\d+)$/', $base, $m) ? strlen($m[1]) : 4;
            } else {
                $autoNum++;
            }
            $number = str_pad((string)$autoNum, $autoWidth, '0', STR_PAD_LEFT);
            while (isset($used[import_service_key($prefix . $number)])) {
                $autoNum++;
                $number = str_pad((string)$autoNum, $autoWidth, '0', STR_PAD_LEFT);
            }
            return $prefix . $number;
        };

        $insert = db()->prepare(
            'INSERT INTO cadets (service_no, full_name, gender, phone, email, department,
                                 current_rank, `current_role`, photo, status, enrolled_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );

        $created   = 0;
        $problems  = [];
        $notes     = [];
        $dataRows  = array_slice($rows, 1);

        foreach ($dataRows as $i => $row) {
            if ($i >= CADET_IMPORT_MAX_ROWS) {
                $problems[] = 'Row limit reached — only the first '
                    . CADET_IMPORT_MAX_ROWS . ' rows were processed.';
                break;
            }
            if (!is_array($row) || import_row_is_empty($row)) {
                continue;
            }

            $sheetRow = $i + 2; /* header occupies row 1 */
            $errs     = [];

            $full = import_cell($row, $fieldCols, 'full_name');
            $label = $full !== '' ? mb_substr($full, 0, 40) : 'unnamed row';

            if ($full === '') {
                $errs[] = 'full name is required';
            } elseif (mb_strlen($full) > 100) {
                $errs[] = 'full name is longer than 100 characters';
            }

            $gender = null;
            $rawGender = import_cell($row, $fieldCols, 'gender');
            if ($rawGender !== '') {
                $gender = ['male' => 'Male', 'm' => 'Male',
                           'female' => 'Female', 'f' => 'Female',
                           'other' => 'Other', 'o' => 'Other'][mb_strtolower($rawGender)] ?? null;
                if ($gender === null) {
                    $errs[] = 'gender must be Male, Female or Other (got “' . $rawGender . '”)';
                }
            } else {
                $errs[] = 'gender is required (Male / Female / Other)';
            }

            $serviceNo = import_cell($row, $fieldCols, 'service_no');
            $autoService = $serviceNo === '';
            if (!$autoService) {
                if (!preg_match('/^[A-Za-z0-9\-\/]{3,30}$/', $serviceNo)) {
                    $errs[] = 'invalid service number “' . $serviceNo . '”';
                } elseif (isset($used[import_service_key($serviceNo)])) {
                    $errs[] = 'service number “' . $serviceNo . '” already exists';
                }
            }

            $phone = import_cell($row, $fieldCols, 'phone');
            if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{5,30}$/', $phone)) {
                $errs[] = 'invalid phone number “' . $phone . '”';
            }

            $email = import_cell($row, $fieldCols, 'email');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errs[] = 'invalid email address “' . $email . '”';
            }

            $department = import_cell($row, $fieldCols, 'department');
            if (mb_strlen($department) > 80) {
                $errs[] = 'department is longer than 80 characters';
            }

            $unitRole = import_cell($row, $fieldCols, 'current_role');
            if (mb_strlen($unitRole) > 60) {
                $errs[] = 'role is longer than 60 characters';
            }

            $rank = $defaultRank;
            $rawRank = import_cell($row, $fieldCols, 'current_rank');
            if ($rawRank !== '') {
                if (mb_strlen($rawRank) > 60) {
                    $errs[] = 'rank is longer than 60 characters';
                } elseif (isset($rankLookup[mb_strtolower($rawRank)])) {
                    $rank = $rankLookup[mb_strtolower($rawRank)];
                } else {
                    $rank = $rawRank;
                    $notes[] = 'Row ' . $sheetRow . ': rank “' . $rawRank
                        . '” is not on the rank ladder — saved as typed.';
                }
            }

            $status = 'Active';
            $rawStatus = import_cell($row, $fieldCols, 'status');
            if ($rawStatus !== '') {
                $status = ucfirst(mb_strtolower($rawStatus));
                if (!in_array($status, ['Active', 'Alumnus', 'Discharged'], true)) {
                    $errs[] = 'status must be Active, Alumnus or Discharged (got “' . $rawStatus . '”)';
                }
            }

            $enrolled = date('Y-m-d');
            $rawDate = import_cell($row, $fieldCols, 'enrolled_date');
            if ($rawDate !== '') {
                $parsed = spreadsheet_date_value($rawDate);
                if ($parsed === null) {
                    $errs[] = 'invalid enrolment date “' . $rawDate . '” (use YYYY-MM-DD)';
                } else {
                    $enrolled = $parsed;
                }
            }

            if ($errs) {
                $problems[] = 'Row ' . $sheetRow . ' (' . $label . '): ' . implode('; ', $errs);
                continue;
            }

            if ($autoService) {
                $serviceNo = $allocServiceNo();
            }

            try {
                $insert->execute([
                    $serviceNo, $full, $gender, $phone, $email !== '' ? $email : null,
                    $department !== '' ? $department : null, $rank,
                    $unitRole !== '' ? $unitRole : null, null, $status, $enrolled,
                ]);
                $used[import_service_key($serviceNo)] = true;
                $created++;
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    $problems[] = 'Row ' . $sheetRow . ' (' . $label . '): service number “'
                        . $serviceNo . '” already exists';
                    continue;
                }
                throw $ex;
            }
        }

        if ($created === 0 && $problems === []) {
            $errors[] = 'No rows were found to import.';
        } else {
            audit_log('cadets_import', 'cadets', null,
                $created . ' created, ' . count($problems) . ' skipped');
            if ($created > 0) {
                flash('success', $created . ' cadet(s) imported from the file.');
            }
            if ($problems !== []) {
                flash('warning', count($problems) . ' row(s) were skipped — see the import report.');
            }
            $_SESSION['cadet_import_report'] = [
                'created'     => $created,
                'skipped'     => count($problems),
                'problems'    => array_slice($problems, 0, 100),
                'notes_total' => count($notes),
                'notes'       => array_slice($notes, 0, 50),
            ];
            redirect(purl('cadets/import.php'));
        }
    }
}

/* Import report left behind by the previous redirect (PRG). */
$report = $_SESSION['cadet_import_report'] ?? null;
unset($_SESSION['cadet_import_report']);

$defaultRankLabel = (string)(db()->query(
    'SELECT rank_name FROM cadet_ranks ORDER BY seniority_level LIMIT 1'
)->fetchColumn() ?: 'Cadet');

$page_title = 'Import cadets';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';

$templateUrl = purl('cadets/import.php') . '?template=';
?>

<nav aria-label="breadcrumb">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(purl('cadets/list.php')) ?>">Roster</a></li>
    <li class="breadcrumb-item active">Import</li>
  </ol>
</nav>

<h1 class="h3 mb-1"><i class="bi bi-file-earmark-arrow-up me-2"></i>Import cadets</h1>
<p class="text-muted mb-3">
  Upload an Excel or CSV file to add up to <?= CADET_IMPORT_MAX_ROWS ?> new cadets in one go.
</p>

<?php if (is_array($report)): ?>
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
        <span class="badge text-bg-success fs-6"><i class="bi bi-check2-circle me-1"></i><?= (int)$report['created'] ?> imported</span>
        <span class="badge text-bg-warning fs-6"><i class="bi bi-x-circle me-1"></i><?= (int)$report['skipped'] ?> skipped</span>
      </div>

      <?php if (!empty($report['problems'])): ?>
        <h2 class="h6 mt-3 mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Skipped rows</h2>
        <ul class="small mb-0" style="max-height:220px;overflow-y:auto">
          <?php foreach ($report['problems'] as $p): ?>
            <li><?= e($p) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if ((int)$report['skipped'] > count($report['problems'])): ?>
          <div class="form-text">Showing the first <?= count($report['problems']) ?> of <?= (int)$report['skipped'] ?> problems.</div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!empty($report['notes'])): ?>
        <h2 class="h6 mt-3 mb-1"><i class="bi bi-info-circle me-1"></i>Import notes</h2>
        <ul class="small mb-0" style="max-height:160px;overflow-y:auto">
          <?php foreach ($report['notes'] as $n): ?>
            <li><?= e($n) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if ((int)($report['notes_total'] ?? 0) > count($report['notes'])): ?>
          <div class="form-text">Showing the first <?= count($report['notes']) ?> of <?= (int)$report['notes_total'] ?> notes.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-upload me-2"></i>Upload a spreadsheet</h2>
        <form method="post" enctype="multipart/form-data" class="row g-3 needs-validation" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="import">

          <div class="col-12">
            <label class="form-label" for="cadets_file">File</label>
            <input class="form-control" type="file" id="cadets_file" name="cadets_file" required
                   accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
            <div class="form-text">
              Excel <code>.xlsx</code> or CSV <code>.csv</code> — max 2 MB and
              <?= CADET_IMPORT_MAX_ROWS ?> rows. Existing service numbers are skipped, not overwritten.
            </div>
            <div class="invalid-feedback">Choose a file to upload.</div>
          </div>

          <div class="col-12 d-flex flex-wrap gap-2 pt-1">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-file-earmark-arrow-up me-1"></i>Import cadets
            </button>
            <a class="btn btn-outline-secondary" href="<?= e($templateUrl) ?>csv">
              <i class="bi bi-filetype-csv me-1"></i>CSV template
            </a>
            <a class="btn btn-outline-secondary" href="<?= e($templateUrl) ?>xlsx">
              <i class="bi bi-filetype-xlsx me-1"></i>Excel template
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-light fw-semibold">
        <i class="bi bi-google me-2"></i>Importing from a Google Form
      </div>
      <div class="card-body">
        <p class="small text-muted mb-2">
          Google Forms cannot be read directly — the responses land in a Google Sheet,
          and that sheet is what you download and upload here.
        </p>
        <ol class="small mb-0 ps-3">
          <li>Build your Google Form using the questions listed below (full name, gender, phone, email…).</li>
          <li>In Google Forms open <strong>Responses</strong> → <strong>Link to Sheets</strong>.</li>
          <li>Check the sheet's header row matches the columns below. The
              <code>Timestamp</code> column is understood and becomes the enrolment date.</li>
          <li>In the sheet choose <strong>File → Download → Comma Separated Values (.csv)</strong>
              or <strong>Microsoft Excel (.xlsx)</strong>.</li>
          <li>Upload that file here. Rows with problems are skipped and listed in the report.</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm border-0 mt-3">
  <div class="card-header bg-light fw-semibold">
    <i class="bi bi-table me-2"></i>Expected columns
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-dark">
        <tr>
          <th style="width:22%">Column</th>
          <th style="width:18%">Required</th>
          <th>Format / notes</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="font-monospace">full_name</td>
          <td><span class="badge text-bg-danger">Yes</span></td>
          <td>Max 100 characters, e.g. <em>Ama Mensah</em></td>
        </tr>
        <tr>
          <td class="font-monospace">gender</td>
          <td><span class="badge text-bg-danger">Yes</span></td>
          <td><code>Male</code>, <code>Female</code> or <code>Other</code> (M / F also accepted)</td>
        </tr>
        <tr>
          <td class="font-monospace">service_no</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td>Leave blank to auto-assign <?= e(service_no_prefix()) ?>####. Duplicates are skipped.</td>
        </tr>
        <tr>
          <td class="font-monospace">phone</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td>Digits, spaces, <code>+ - ( )</code> only, e.g. <em>024 123 4567</em></td>
        </tr>
        <tr>
          <td class="font-monospace">email</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td>Valid address, e.g. <em>ama@example.com</em></td>
        </tr>
        <tr>
          <td class="font-monospace">department</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td>Max 80 characters, e.g. <em>Signals</em></td>
        </tr>
        <tr>
          <td class="font-monospace">rank</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td>Defaults to “<?= e($defaultRankLabel) ?>”.
              Unknown ranks are saved as typed and flagged in the report.</td>
        </tr>
        <tr>
          <td class="font-monospace">role</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td>Unit role, max 60 characters, e.g. <em>Platoon Commander</em></td>
        </tr>
        <tr>
          <td class="font-monospace">status</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td><code>Active</code> (default), <code>Alumnus</code> or <code>Discharged</code></td>
        </tr>
        <tr>
          <td class="font-monospace">enrolled_date</td>
          <td><span class="badge text-bg-secondary">No</span></td>
          <td><code>YYYY-MM-DD</code> (also D/M/Y and Excel dates). Defaults to today.</td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="card-body border-top">
    <p class="small text-muted mb-0">
      Header names are matched loosely and ignore case, spaces and punctuation —
      <em>“Full Name”</em>, <em>full_name</em> and <em>“What is your full name?”</em> all work.
      Any other columns (Timestamp, remarks, file links…) are ignored.
      Prefer <code>YYYY-MM-DD</code> for dates: <code>03/04/2026</code> is read as 3 April 2026.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
