<?php
// Public client config (Meta Pixel ID, etc.) — read from .env
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val);
    }
}

echo json_encode([
    'meta_pixel_id' => $env['META_PIXEL_ID'] ?? '',
    'meta_test_event_code' => $env['META_TEST_EVENT_CODE'] ?? '',
]);
