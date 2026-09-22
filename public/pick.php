<?php
// /pick — self-maintaining pool picker.
// Enumerates services in this Railway project whose names start with the
// POOL_PREFIX (default "shl-"), keeps those whose latest deployment is
// SUCCESS and whose staticUrl still answers, returns one at random.
//   Retire a link = stop that service  (liveness check drops it).
//   Add a link     = create a "shl-NN" service (auto-deploys from repo).
// Nothing here and nothing in the bot is ever redeployed.

$rtok = getenv('RAILWAY_API_TOKEN') ?: '';
$pid  = getenv('RAILWAY_PROJECT_ID') ?: '';
$prefix = getenv('POOL_PREFIX') ?: 'shl-';

if ($rtok === '' || $pid === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'picker not configured']);
    exit;
}

$gql = json_encode([
    'query' => 'query($id:String!){project(id:$id){services(first:100){edges{node{name deployments(last:1){edges{node{status staticUrl}}}}}}}}',
    'variables' => ['id' => $pid],
]);

$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAuthorization: Bearer $rtok\r\n",
    'content' => $gql,
    'timeout' => 15,
    'ignore_errors' => true,
], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

$body = @file_get_contents('https://api.railway.com/graphql/v2', false, $ctx);
$data = $body ? json_decode($body, true) : null;
$edges = $data['data']['project']['services']['edges'] ?? [];

$candidates = [];
foreach ($edges as $e) {
    $node = $e['node'];
    if (strpos($node['name'], $prefix) !== 0) {
        continue;
    }
    $dep = ($node['deployments']['edges'] ?? [])[0]['node'] ?? null;
    if (!$dep) {
        continue;
    }
    $url = $dep['staticUrl'] ?? '';
    if ($dep['status'] !== 'SUCCESS' || strpos($url, 'up.railway.app') === false) {
        continue;
    }
    $candidates[] = $url;
}

// Liveness pass: keep URLs that still answer (stopped services 502 fast).
$live = [];
foreach ($candidates as $url) {
    $ctx2 = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 6,
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $h = @get_headers($url, false, $code, 6, $ctx2);
    if ($h && $code >= 200 && $code < 400) {
        $live[] = $url;
    }
}
$pool = $live ?: $candidates;

header('Content-Type: application/json');
if (!$pool) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'no pool members', 'candidates' => $candidates]);
    exit;
}

$chosen = $pool[array_rand($pool)];
echo json_encode(['ok' => true, 'url' => 'https://' . rtrim($chosen, '/'), 'live' => count($live), 'total' => count($candidates)]);
