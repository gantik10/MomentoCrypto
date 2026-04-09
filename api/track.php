<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$dataFile = __DIR__ . '/analytics.json';

// Initialize file if not exists
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode(['visits' => [], 'clicks' => []]));
}

$data = json_decode(file_get_contents($dataFile), true);
if (!$data) $data = ['visits' => [], 'clicks' => []];

$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? '';

// Get country from IP
function getCountry($ip) {
    // Use ip-api.com free tier
    $resp = @file_get_contents("http://ip-api.com/json/{$ip}?fields=countryCode,country");
    if ($resp) {
        $geo = json_decode($resp, true);
        if ($geo && isset($geo['country'])) {
            return ['code' => $geo['countryCode'], 'name' => $geo['country']];
        }
    }
    return ['code' => 'XX', 'name' => 'Unknown'];
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip = explode(',', $ip)[0];
$geo = getCountry(trim($ip));

$entry = [
    'ts' => time(),
    'date' => date('Y-m-d'),
    'time' => date('H:i:s'),
    'ip' => hash('sha256', $ip), // hashed for privacy
    'country' => $geo['name'],
    'country_code' => $geo['code'],
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ref' => $input['ref'] ?? '',
    'page' => $input['page'] ?? '/',
];

if ($type === 'visit') {
    $entry['device'] = $input['device'] ?? 'unknown';
    $data['visits'][] = $entry;
} elseif ($type === 'click') {
    $entry['button'] = $input['button'] ?? '';
    $entry['section'] = $input['section'] ?? '';
    $data['clicks'][] = $entry;
}

// Keep last 30 days only
$cutoff = time() - (30 * 86400);
$data['visits'] = array_values(array_filter($data['visits'], fn($v) => $v['ts'] > $cutoff));
$data['clicks'] = array_values(array_filter($data['clicks'], fn($c) => $c['ts'] > $cutoff));

file_put_contents($dataFile, json_encode($data));
echo json_encode(['ok' => true]);
