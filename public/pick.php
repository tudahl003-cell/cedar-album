<?php
// /pick — self-maintaining multi-account pool picker.
//
// Pool members are spread across SEVERAL Railway accounts (to dodge any
// single account's daily service-creation cap). This endpoint is the one
// stable thing the bot calls: it asks each account for its live "shl-NN"
// members, liveness-probes them, and returns one at random.
//
//   Retire a link = stop that service (its /ping.php 502s, picker drops it).
//   Add a link     = create a "shl-NN" service in any pool account.
// Nothing here and nothing in the bot is ever redeployed.
//
// Env:
//   POOL_ACCOUNTS  JSON array: [{"token":"***","pid":"<projectId>"}, ...]
//   POOL_TTL       candidate-cache lifetime in seconds (default 600)

$accountsRaw = getenv('POOL_ACCOUNTS') ?: '';
$ttl = (int)(getenv('POOL_TTL') ?: 600);
$accounts = json_decode($accountsRaw, true);
if (!is_array($accounts) || count($accounts) === 0) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'picker not configured (POOL_ACCOUNTS empty)']);
    exit;
}

$memberRe = '/^shl-\d{2}$/';   // pool members are named shl-01 .. shl-NN

function rail_gql(string $token, string $pid): array {
    $gql = json_encode([
        'query' => 'query($id:String!){project(id:$id){services(first:100){edges{node{name deployments(last:1){edges{node{status staticUrl}}}}}}}}',
        'variables' => ['id' => $pid],
    ]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $token,
        'content' => $gql, 'timeout' => 12, 'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = @file_get_contents('https://api.railway.com/graphql/v2', false, $ctx);
    return $body ? (json_decode($body, true) ?? []) : [];
}

// Candidate list (cached) = every SUCCESS "shl-NN" staticUrl across all
// configured accounts.
$cacheFile = sys_get_temp_dir() . '/pool_candidates';
$candidates = [];
$mtime = @filemtime($cacheFile);
if ($mtime !== false && (time() - $mtime) < $ttl) {
    $c = @json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($c)) { $candidates = $c; }
}
if (!$candidates) {
    foreach ($accounts as $a) {
        $token = (string)($a['token'] ?? '');
        $pid   = (string)($a['pid'] ?? '');
        if ($token === '' || $pid === '') { continue; }
        $data = rail_gql($token, $pid);
        $edges = $data['data']['project']['services']['edges'] ?? [];
        foreach ($edges as $e) {
            $node = $e['node'];
            if (!preg_match($memberRe, (string)$node['name'])) { continue; }
            $dep = ($node['deployments']['edges'] ?? [])[0]['node'] ?? null;
            if (!$dep) { continue; }
            $url = (string)($dep['staticUrl'] ?? '');
            if ($dep['status'] !== 'SUCCESS' || strpos($url, 'up.railway.app') === false) { continue; }
            $candidates[] = $url;
        }
    }
    $candidates = array_values(array_unique($candidates));
    sort($candidates);
    @file_put_contents($cacheFile, json_encode($candidates));
}

// Liveness pass: a running member answers 200 at /ping.php; a stopped one
// 502s through the Railway proxy. Keep only the live ones.
$live = [];
foreach ($candidates as $url) {
    $ctx2 = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 5, 'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $h = @get_headers($url . '/ping.php', false, $code, 5, $ctx2);
    if ($h && $code >= 200 && $code < 400) { $live[] = $url; }
}
$pool = $live ?: $candidates;   // if every ping times out, fall back to raw list

header('Content-Type: application/json');
if (!$pool) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'no pool members', 'candidates' => $candidates]);
    exit;
}
$chosen = $pool[array_rand($pool)];
echo json_encode([
    'ok' => true,
    'url' => 'https://' . rtrim($chosen, '/'),
    'live' => count($live),
    'total' => count($candidates),
]);
