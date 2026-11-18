<?php
/**
 * Spreadsheet helpers for bulk imports — CSV and .xlsx reading, .xlsx
 * writing, flexible header mapping and date coercion. No external libraries.
 *
 * Row arrays are always lists of trimmed-ish strings; row 0 is the header.
 */
declare(strict_types=1);

require_once __DIR__ . '/zipfile.php';

/** Upper bound handed to the readers (1 header row + 1000 data rows). */
const SPREADSHEET_MAX_ROWS = 1001;

/* ---------------------------------------------------------------------
 * CSV
 * ------------------------------------------------------------------- */

/** @return list<list<string>> */
function csv_rows(string $file, int $maxRows = SPREADSHEET_MAX_ROWS): array
{
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        throw new RuntimeException('The uploaded file could not be opened.');
    }

    /* Sniff the delimiter from the header line: Excel in some locales uses ; */
    $first = (string)fgets($fh);
    if (str_starts_with($first, "\xEF\xBB\xBF")) {
        $first = substr($first, 3);
    }
    rewind($fh);

    $delim = ',';
    if (trim($first) !== '') {
        $counts = [
            ','  => substr_count($first, ','),
            ';'  => substr_count($first, ';'),
            "\t" => substr_count($first, "\t"),
        ];
        arsort($counts);
        $delim = (string)array_key_first($counts);
    }

    $rows = [];
    while (($raw = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($raw === null) {
            continue;
        }
        if ($raw === [null]) {
            $raw = ['']; /* blank line → one empty cell */
        }
        $row = [];
        foreach ($raw as $cell) {
            $row[] = trim(str_replace("\xC2\xA0", ' ', (string)$cell));
        }
        /* Blank lines are kept so reported row numbers match the file. */
        $rows[] = $row !== [] ? $row : [''];
        if (count($rows) >= $maxRows) {
            break;
        }
    }
    fclose($fh);

    if ($rows !== [] && isset($rows[0][0]) && str_starts_with($rows[0][0], "\xEF\xBB\xBF")) {
        $rows[0][0] = substr($rows[0][0], 3);
    }
    return $rows;
}

/* ---------------------------------------------------------------------
 * XLSX — read
 * ------------------------------------------------------------------- */

/** @return list<list<string>> */
function xlsx_rows(string $file, int $maxRows = SPREADSHEET_MAX_ROWS): array
{
    if (!class_exists('XMLReader')) {
        throw new RuntimeException('This server is missing the XML extension required to read .xlsx files.');
    }

    $workbook = zip_read_entry($file, 'xl/workbook.xml');
    if ($workbook === null) {
        throw new RuntimeException('The file is not a valid .xlsx workbook (xl/workbook.xml missing).');
    }

    $sheetPath = xlsx_first_sheet_path($file) ?? 'xl/worksheets/sheet1.xml';

    $sheetXml = zip_read_entry($file, $sheetPath)
        ?? zip_read_entry($file, 'xl/worksheets/sheet1.xml');
    if ($sheetXml === null) {
        throw new RuntimeException('The workbook contains no readable worksheet.');
    }

    $shared     = xlsx_shared_strings($file);
    $dateStyles = xlsx_date_styles($file);

    return xlsx_parse_sheet($sheetXml, $shared, $dateStyles, $maxRows);
}

/** Path of the first worksheet inside the archive, e.g. "xl/worksheets/sheet1.xml". */
function xlsx_first_sheet_path(string $file): ?string
{
    $workbookXml = zip_read_entry($file, 'xl/workbook.xml');
    if ($workbookXml === null) {
        return null;
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($workbookXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return null;
    }
    $sheet = $dom->getElementsByTagName('sheet')->item(0);
    if (!$sheet instanceof DOMElement) {
        return null;
    }
    $rid = $sheet->getAttributeNS(
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'id'
    );
    if ($rid === '') {
        $rid = $sheet->getAttribute('r:id');
    }

    if ($rid !== '') {
        $relsXml = zip_read_entry($file, 'xl/_rels/workbook.xml.rels');
        if ($relsXml !== null) {
            $rels = new DOMDocument();
            if (@$rels->loadXML($relsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                    if ($rel instanceof DOMElement && $rel->getAttribute('Id') === $rid) {
                        $target = (string)$rel->getAttribute('Target');
                        if ($target !== '') {
                            return str_starts_with($target, '/')
                                ? ltrim($target, '/')
                                : 'xl/' . ltrim($target, '/');
                        }
                    }
                }
            }
        }
    }

    return 'xl/worksheets/sheet1.xml';
}

/** Shared-string table: index => text. */
function xlsx_shared_strings(string $file): array
{
    $xml = zip_read_entry($file, 'xl/sharedStrings.xml');
    if ($xml === null) {
        return [];
    }
    $reader = new XMLReader();
    if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return [];
    }

    $out   = [];
    $cur   = null;
    while ($reader->read()) {
        $type = $reader->nodeType;
        $name = spreadsheet_local_name($reader->name);

        if ($type === XMLReader::ELEMENT && $name === 'si') {
            if ($reader->isEmptyElement) {
                $out[] = '';
            } else {
                $cur = '';
            }
        } elseif ($type === XMLReader::ELEMENT && $cur !== null && $name === 't') {
            $cur .= spreadsheet_read_text($reader, 't');
        } elseif ($type === XMLReader::END_ELEMENT && $name === 'si' && $cur !== null) {
            $out[] = $cur;
            $cur = null;
        }
    }
    $reader->close();
    return $out;
}

/** Style indexes whose number format is a date/time format. */
function xlsx_date_styles(string $file): array
{
    $xml = zip_read_entry($file, 'xl/styles.xml');
    if ($xml === null) {
        return [];
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return [];
    }

    $custom = [];
    foreach ($dom->getElementsByTagName('numFmt') as $el) {
        if ($el instanceof DOMElement) {
            $custom[(int)$el->getAttribute('numFmtId')] = (string)$el->getAttribute('formatCode');
        }
    }

    /* Built-in date/time formats (ECMA-376 §18.8.30), incl. East-Asian variants. */
    $dateIds = [];
    foreach ([14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
              45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58] as $id) {
        $dateIds[$id] = true;
    }

    $container = null;
    foreach ($dom->getElementsByTagName('cellXfs') as $el) {
        $container = $el;
        break;
    }
    if (!$container instanceof DOMElement) {
        return [];
    }

    $styles = [];
    $index  = 0;
    foreach ($container->getElementsByTagName('xf') as $xf) {
        if ($xf instanceof DOMElement) {
            $fmtId = (int)$xf->getAttribute('numFmtId');
            if (isset($dateIds[$fmtId])
                || (isset($custom[$fmtId]) && spreadsheet_format_is_date($custom[$fmtId]))) {
                $styles[$index] = true;
            }
        }
        $index++;
    }
    return $styles;
}

/** True when a custom number format codes a date/time rather than a number. */
function spreadsheet_format_is_date(string $code): bool
{
    if ($code === '') {
        return false;
    }
    $stripped = preg_replace('/\[[^\]]*\]|"[^"]*"|\\\\./u', '', $code) ?? '';
    if (str_contains($stripped, '@')) {
        return false; /* text placeholder */
    }
    return (bool)preg_match('/[dmyhs]/i', $stripped);
}

/** Stream the worksheet XML into rows of strings. */
function xlsx_parse_sheet(string $sheetXml, array $shared, array $dateStyles, int $maxRows): array
{
    $reader = new XMLReader();
    if (!$reader->XML($sheetXml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('The worksheet XML could not be parsed.');
    }

    $rows = [];
    $row  = [];
    $inRow = false;
    $maxCols = 0;

    while ($reader->read()) {
        $type = $reader->nodeType;
        $name = spreadsheet_local_name($reader->name);

        if ($type === XMLReader::ELEMENT && $name === 'row') {
            $inRow = !$reader->isEmptyElement;
            $row   = [];
            if (!$inRow) {
                /* <row/> self-closing: keep it so row numbers stay aligned. */
                $rows[] = $row;
                if (count($rows) >= $maxRows) {
                    break;
                }
            }
            continue;
        }
        if ($type === XMLReader::END_ELEMENT && $name === 'row') {
            $rows[] = $row;
            $inRow  = false;
            $row    = [];
            if (count($rows) >= $maxRows) {
                break;
            }
            continue;
        }
        if (!$inRow || $type !== XMLReader::ELEMENT || $name !== 'c') {
            continue;
        }

        $col    = spreadsheet_col_index((string)$reader->getAttribute('r'));
        $cellT  = (string)$reader->getAttribute('t');
        $style  = (int)($reader->getAttribute('s') ?? 0);
        if ($col < 0) {
            $col = count($row);
        }
        if ($col > 64) {
            $col = 64; /* defence against absurd references */
        }

        $raw      = '';
        $inline   = '';
        $hasValue = false;

        if (!$reader->isEmptyElement) {
            while ($reader->read()) {
                $t = $reader->nodeType;
                $n = spreadsheet_local_name($reader->name);
                if ($t === XMLReader::END_ELEMENT && $n === 'c') {
                    break;
                }
                if ($t === XMLReader::ELEMENT && $n === 'v') {
                    $raw      = $reader->isEmptyElement ? '' : spreadsheet_read_text($reader, 'v');
                    $hasValue = true;
                } elseif ($t === XMLReader::ELEMENT && $n === 't') {
                    $inline   .= $reader->isEmptyElement ? '' : spreadsheet_read_text($reader, 't');
                    $hasValue = true;
                }
            }
        }

        while (count($row) < $col) {
            $row[] = '';
        }
        $row[$col] = spreadsheet_cell_value($cellT, $raw, $inline, $style, $dateStyles, $shared, $hasValue);
        $maxCols   = max($maxCols, $col + 1);
    }
    $reader->close();

    if ($maxCols > 0) {
        foreach ($rows as $i => $r) {
            $rows[$i] = array_pad($r, $maxCols, '');
        }
    }
    return $rows;
}

function spreadsheet_cell_value(
    string $type,
    string $raw,
    string $inline,
    int $style,
    array $dateStyles,
    array $shared,
    bool $hasValue
): string {
    switch ($type) {
        case 's':
            return $hasValue ? (string)($shared[(int)$raw] ?? '') : '';
        case 'inlineStr':
            return $inline;
        case 'b':
            return trim($raw) === '1' ? 'TRUE' : 'FALSE';
        case 'e':
            return '';
        case 'str':
            return $raw;
        case 'd':
            return (bool)preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) ? $m[1] : $raw;
        default:
            if (!$hasValue) {
                return '';
            }
            if (isset($dateStyles[$style]) && is_numeric($raw)) {
                return spreadsheet_serial_to_date((float)$raw);
            }
            return spreadsheet_normalize_number($raw);
    }
}

/** Map an A1-style reference ("AB12") to a zero-based column index. */
function spreadsheet_col_index(string $ref): int
{
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) {
        return -1;
    }
    $n = 0;
    foreach (str_split(strtoupper($m[1])) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** "xl:row" / "row" → "row" */
function spreadsheet_local_name(string $name): string
{
    $pos = strrpos($name, ':');
    return $pos === false ? $name : substr($name, $pos + 1);
}

/** Read all character data until the closing tag of the current element. */
function spreadsheet_read_text(XMLReader $reader, string $element): string
{
    $out = '';
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT
            && spreadsheet_local_name($reader->name) === $element) {
            break;
        }
        if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
            $out .= $reader->value;
        }
    }
    return $out;
}

/** "123.0" → "123"; scientific notation → plain digits; other values untouched. */
function spreadsheet_normalize_number(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        return $raw;
    }
    $f = (float)$raw;
    if (is_finite($f) && floor($f) === $f && abs($f) < 1e15) {
        return sprintf('%.0f', $f);
    }
    return $raw;
}

/** Excel serial (1900 system) → Y-m-d. */
function spreadsheet_serial_to_date(float $serial): string
{
    $days = (int)floor($serial);
    if ($days < 1) {
        return '';
    }
    try {
        $dt = (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d days', $days));
        return $dt->format('Y-m-d');
    } catch (Throwable) {
        return '';
    }
}

/* ---------------------------------------------------------------------
 * XLSX — write (used for the downloadable import template)
 * ------------------------------------------------------------------- */

function xlsx_write(string $destination, array $rows): void
{
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';

    $r = 0;
    foreach ($rows as $cells) {
        $r++;
        $sheet .= '<row r="' . $r . '">';
        $c = 0;
        foreach ((array)$cells as $value) {
            $c++;
            $text = (string)$value;
            if ($text === '') {
                continue;
            }
            $sheet .= '<c r="' . spreadsheet_col_ref($c - 1) . $r . '" t="inlineStr">'
                . '<is><t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</t></is></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Cadets" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
        . ' Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
        . ' Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
        . ' Target="xl/workbook.xml"/></Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $zip = zip_build([
        '[Content_Types].xml'      => $contentTypes,
        '_rels/.rels'              => $rootRels,
        'xl/workbook.xml'          => $workbook,
        'xl/_rels/workbook.xml.rels' => $wbRels,
        'xl/styles.xml'            => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ]);

    if (file_put_contents($destination, $zip) === false) {
        throw new RuntimeException('Could not write the .xlsx file.');
    }
}

/** Zero-based column index → "A", "B", … "AA". */
function spreadsheet_col_ref(int $index): string
{
    $index = max(0, $index);
    $ref = '';
    do {
        $ref  = chr(65 + ($index % 26)) . $ref;
        $index = intdiv($index, 26) - 1;
    } while ($index >= 0);
    return $ref;
}

/* ---------------------------------------------------------------------
 * Header mapping
 * ------------------------------------------------------------------- */

/**
 * Map a header row onto cadet fields. Matching is case-insensitive and
 * ignores punctuation/space, so "Full Name", "full_name" and
 * "What is your full name?" all resolve to full_name.
 *
 * @return array<string,int> field => column index (only fields present)
 */
function spreadsheet_map_headers(array $headerRow): array
{
    $norm = [];
    foreach ($headerRow as $i => $header) {
        $s = mb_strtolower(trim((string)$header));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
        $norm[(int)$i] = $s;
    }

    $exact = [
        'service_no' => ['serviceno', 'servicenumber', 'cadetno', 'cadetnumber', 'cadetid',
            'cadetserviceno', 'regno', 'registrationno', 'service'],
        'full_name'  => ['fullname', 'cadetname', 'name', 'surname', 'firstname', 'nameofcadet'],
        'gender'     => ['gender', 'sex'],
        'phone'      => ['phone', 'phoneno', 'phonenumber', 'mobile', 'mobilephone',
            'telephone', 'contactnumber', 'contact'],
        'email'      => ['email', 'emailaddress', 'emailid', 'mail'],
        'department' => ['department', 'section', 'faculty', 'squadron', 'flight'],
        'current_rank'  => ['currentrank', 'rank', 'rankname', 'cadetrank', 'grade', 'rankgrade'],
        'current_role'  => ['currentrole', 'role', 'appointment', 'unitrole', 'position'],
        'status'        => ['status', 'cadetstatus', 'currentstatus'],
        'enrolled_date' => ['enrolleddate', 'enrollmentdate', 'enrolmentdate', 'dateenrolled',
            'datejoined', 'joineddate', 'dateofenrolment', 'dateofenrollment', 'date',
            'timestamp', 'datesubmitted', 'submissiondate', 'submittedon'],
    ];

    $contains = [
        'service_no'    => ['serviceno', 'cadetno', 'cadetid', 'regno', 'registration'],
        'full_name'     => ['fullname', 'yourname', 'name'],
        'gender'        => ['gender', 'sex'],
        'phone'         => ['phone', 'mobile', 'telephone', 'contactnumber'],
        'email'         => ['email', 'mail'],
        'department'    => ['department', 'section', 'faculty'],
        'current_rank'  => ['rank'],
        'current_role'  => ['role', 'appointment', 'position'],
        'status'        => ['status'],
        'enrolled_date' => ['enrolled', 'enrolment', 'enrollment', 'joined', 'timestamp', 'submitted'],
    ];

    $map  = [];
    $used = [];

    foreach ($exact as $field => $keys) {
        foreach ($keys as $key) {
            foreach ($norm as $i => $header) {
                if ($header === '' || isset($used[$i]) || $header !== $key) {
                    continue;
                }
                $map[$field] = $i;
                $used[$i]    = true;
                continue 3;
            }
        }
    }

    foreach ($contains as $field => $keys) {
        if (isset($map[$field])) {
            continue;
        }
        foreach ($keys as $key) {
            foreach ($norm as $i => $header) {
                if ($header === '' || isset($used[$i]) || !str_contains($header, $key)) {
                    continue;
                }
                $map[$field] = $i;
                $used[$i]    = true;
                continue 3;
            }
        }
    }

    return $map;
}

/* ---------------------------------------------------------------------
 * Dates
 * ------------------------------------------------------------------- */

/**
 * Coerce a spreadsheet cell to Y-m-d, or null when it cannot be read.
 * Accepts ISO (what Google Forms writes), D/M/Y, M/D/Y, Excel serials
 * and anything strtotime understands.
 */
function spreadsheet_date_value(string $value): ?string
{
    $v = trim(str_replace("\xC2\xA0", ' ', $value));
    if ($v === '') {
        return null;
    }

    /* Drop a trailing time component: "2026-11-17 10:30:00", "17/11/2026 10:30". */
    if (preg_match('/^(\d{1,4}[\/.\-]\d{1,2}[\/.\-]\d{1,4})(?:[ T]+\d{1,2}:\d.*)?$/', $v, $m)) {
        $v = $m[1];
    }

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1])
            ? sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3])
            : null;
    }

    /* Excel serial stored as a bare number (1927-2173). */
    if (preg_match('/^\d{5}(\.0+)?$/', $v)) {
        $date = spreadsheet_serial_to_date((float)$v);
        return $date === '' ? null : $date;
    }

    $formats = [
        'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'j.n.Y',
        'm/d/Y', 'n/j/Y', 'm-d-Y', 'n-j-Y',
        'Y/m/d', 'd M Y', 'j M Y', 'd M y', 'j M y', 'M j, Y', 'F j, Y',
    ];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $v, new DateTimeZone('UTC'));
        if ($dt !== false && $dt->format($fmt) === $v) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d', $ts);
}
