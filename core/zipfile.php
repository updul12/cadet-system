<?php
/**
 * Minimal ZIP reader/writer — .xlsx files are ZIP archives.
 * Uses ext-zip (ZipArchive) when the extension is enabled, otherwise
 * falls back to a small pure-PHP parser/writer (stored + deflate only).
 */
declare(strict_types=1);

/** Raw bytes of one entry inside a ZIP archive, or null when absent/unreadable. */
function zip_read_entry(string $file, string $entry): ?string
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (strcasecmp($name, $entry) === 0) {
                    $data = $zip->getFromIndex($i);
                    return $data === false ? null : $data;
                }
            }
            return null;
        } finally {
            $zip->close();
        }
    }
    return zip_read_entry_pure($file, $entry);
}

/** Pure-PHP ZIP reader (central directory → local header → inflate). */
function zip_read_entry_pure(string $file, string $entry): ?string
{
    $data = file_get_contents($file);
    if ($data === false || $data === '') {
        return null;
    }
    $len = strlen($data);

    /* End-of-central-directory signature "PK\5\6" lives in the last 64 KB. */
    $eocdPos = -1;
    $from = max(0, $len - 65557);
    for ($p = $len - 22; $p >= $from; $p--) {
        if (substr($data, $p, 4) === "PK\x05\x06") {
            $eocdPos = $p;
            break;
        }
    }
    if ($eocdPos < 0) {
        return null;
    }

    $count    = unpack('v', substr($data, $eocdPos + 10, 2))[1];
    $cdOffset = unpack('V', substr($data, $eocdPos + 16, 4))[1];
    if ($cdOffset <= 0 || $cdOffset >= $len) {
        return null;
    }

    $p = $cdOffset;
    for ($i = 0; $i < $count; $i++) {
        if (substr($data, $p, 4) !== "PK\x01\x02") {
            break;
        }
        $method   = unpack('v', substr($data, $p + 10, 2))[1];
        $csize    = unpack('V', substr($data, $p + 20, 4))[1];
        $nameLen  = unpack('v', substr($data, $p + 28, 2))[1];
        $extraLen = unpack('v', substr($data, $p + 30, 2))[1];
        $cmtLen   = unpack('v', substr($data, $p + 32, 2))[1];
        $lhOffset = unpack('V', substr($data, $p + 42, 4))[1];
        $name     = substr($data, $p + 46, $nameLen);
        $p       += 46 + $nameLen + $extraLen + $cmtLen;

        if (strcasecmp($name, $entry) !== 0) {
            continue;
        }
        if ($csize === 0xFFFFFFFF || $lhOffset + 30 > $len) {
            return null; /* zip64 / corrupt — out of scope for sheet files */
        }
        if (substr($data, $lhOffset, 4) !== "PK\x03\x04") {
            return null;
        }
        $localNameLen  = unpack('v', substr($data, $lhOffset + 26, 2))[1];
        $localExtraLen = unpack('v', substr($data, $lhOffset + 28, 2))[1];
        $body = substr($data, $lhOffset + 30 + $localNameLen + $localExtraLen, $csize);
        if (strlen($body) < $csize) {
            return null;
        }
        if ($method === 0) {
            return $body;
        }
        if ($method === 8) {
            $out = @gzinflate($body);
            return $out === false ? null : $out;
        }
        return null;
    }
    return null;
}

/**
 * Build an uncompressed (stored) ZIP archive from name => content pairs.
 * Enough for files Excel / Google Sheets accept — our sheet parts are tiny.
 */
function zip_build(array $entries): string
{
    $central = '';
    $body    = '';
    $offset  = 0;
    $count   = 0;
    $dosDate = 33; /* 1980-01-01 */

    foreach ($entries as $name => $content) {
        $name    = (string)$name;
        $content = (string)$content;
        $size    = strlen($content);
        $crc     = crc32($content);

        $local = "PK\x03\x04"
            . pack('v', 20)        /* version needed */
            . pack('v', 0)         /* flags */
            . pack('v', 0)         /* method: stored */
            . pack('v', 0)         /* mod time */
            . pack('v', $dosDate)  /* mod date */
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', strlen($name))
            . pack('v', 0)         /* extra length */
            . $name
            . $content;

        $central .= "PK\x01\x02"
            . pack('v', 20)        /* version made by */
            . pack('v', 20)        /* version needed */
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', strlen($name))
            . pack('v', 0)         /* extra */
            . pack('v', 0)         /* comment */
            . pack('v', 0)         /* disk start */
            . pack('v', 0)         /* internal attrs */
            . pack('V', 0)         /* external attrs */
            . pack('V', $offset)
            . $name;

        $body   .= $local;
        $offset += strlen($local);
        $count++;
    }

    $eocd = "PK\x05\x06"
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', $count)
        . pack('v', $count)
        . pack('V', strlen($central))
        . pack('V', $offset)
        . pack('v', 0);

    return $body . $central . $eocd;
}
