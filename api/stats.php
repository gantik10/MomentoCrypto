<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simple auth
$password = 'MomentoCrypto2026!';
$auth = $_GET['key'] ?? '';
if ($auth !== $password) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$dataFile = __DIR__ . '/analytics.json';
if (!file_exists($dataFile)) {
    echo json_encode(['visits' => [], 'clicks' => []]);
    exit;
}

$data = json_decode(file_get_contents($dataFile), true);

// Filter by date range
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');

$filteredVisits = array_filter($data['visits'] ?? [], fn($v) => $v['date'] >= $from && $v['date'] <= $to);
$filteredClicks = array_filter($data['clicks'] ?? [], fn($c) => $c['date'] >= $from && $c['date'] <= $to);

// Aggregate
$totalVisits = count($filteredVisits);
$totalClicks = count($filteredClicks);
$uniqueIPs = count(array_unique(array_column($filteredVisits, 'ip')));

// By country
$countries = [];
foreach ($filteredVisits as $v) {
    $c = $v['country'] ?? 'Unknown';
    $cc = $v['country_code'] ?? 'XX';
    if (!isset($countries[$c])) $countries[$c] = ['name' => $c, 'code' => $cc, 'visits' => 0, 'clicks' => 0];
    $countries[$c]['visits']++;
}
foreach ($filteredClicks as $cl) {
    $c = $cl['country'] ?? 'Unknown';
    $cc = $cl['country_code'] ?? 'XX';
    if (!isset($countries[$c])) $countries[$c] = ['name' => $c, 'code' => $cc, 'visits' => 0, 'clicks' => 0];
    $countries[$c]['clicks']++;
}
usort($countries, fn($a, $b) => $b['visits'] - $a['visits']);

// By day
$daily = [];
foreach ($filteredVisits as $v) {
    $d = $v['date'];
    if (!isset($daily[$d])) $daily[$d] = ['date' => $d, 'visits' => 0, 'clicks' => 0, 'unique' => []];
    $daily[$d]['visits']++;
    $daily[$d]['unique'][$v['ip']] = true;
}
foreach ($filteredClicks as $cl) {
    $d = $cl['date'];
    if (!isset($daily[$d])) $daily[$d] = ['date' => $d, 'visits' => 0, 'clicks' => 0, 'unique' => []];
    $daily[$d]['clicks']++;
}
ksort($daily);
$dailyOut = [];
foreach ($daily as $d) {
    $dailyOut[] = ['date' => $d['date'], 'visits' => $d['visits'], 'clicks' => $d['clicks'], 'unique' => count($d['unique'])];
}

// By button
$buttons = [];
foreach ($filteredClicks as $cl) {
    $b = $cl['button'] ?? 'unknown';
    if (!isset($buttons[$b])) $buttons[$b] = 0;
    $buttons[$b]++;
}
arsort($buttons);

// Device breakdown
$devices = ['mobile' => 0, 'tablet' => 0, 'desktop' => 0];
foreach ($filteredVisits as $v) {
    $dev = $v['device'] ?? 'desktop';
    if (isset($devices[$dev])) $devices[$dev]++;
}

// Hourly distribution
$hours = array_fill(0, 24, 0);
foreach ($filteredVisits as $v) {
    $h = (int)date('G', $v['ts']);
    $hours[$h]++;
}

echo json_encode([
    'total_visits' => $totalVisits,
    'unique_visitors' => $uniqueIPs,
    'total_clicks' => $totalClicks,
    'ctr' => $totalVisits > 0 ? round($totalClicks / $totalVisits * 100, 1) : 0,
    'countries' => array_values($countries),
    'daily' => $dailyOut,
    'buttons' => $buttons,
    'devices' => $devices,
    'hours' => $hours,
    'from' => $from,
    'to' => $to,
]);
