<?php
// Meta Conversions API helper — fires Purchase event server-side
// Required: META_PIXEL_ID + META_CAPI_TOKEN in .env
// event_id == order_id so the Pixel client-side event on success.php dedupes against this.

function mc_load_env() {
    $envFile = __DIR__ . '/../.env';
    $env = [];
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$key, $val] = explode('=', $line, 2);
            $env[trim($key)] = trim($val);
        }
    }
    return $env;
}

/**
 * Fire Meta Purchase event via Conversions API.
 * $order = ['order_id', 'package', 'amount', 'currency', 'attribution' => {fbc, fbp, ip, user_agent, landing_url, utm_*}]
 */
function mc_send_meta_purchase($order) {
    $env = mc_load_env();
    $pixelId = $env['META_PIXEL_ID'] ?? '';
    $capiToken = $env['META_CAPI_TOKEN'] ?? '';
    if (!$pixelId || !$capiToken) {
        file_put_contents(__DIR__ . '/meta_capi.log', date('Y-m-d H:i:s') . " | SKIP order={$order['order_id']} | reason=no META_PIXEL_ID or META_CAPI_TOKEN in .env\n", FILE_APPEND);
        return ['skipped' => true];
    }

    $attr = $order['attribution'] ?? [];

    $userData = array_filter([
        'client_ip_address' => $attr['ip'] ?? '',
        'client_user_agent' => $attr['user_agent'] ?? '',
        'fbc' => $attr['fbc'] ?? '',
        'fbp' => $attr['fbp'] ?? '',
    ]);

    $customData = [
        'currency' => $order['currency'] ?? 'USD',
        'value' => floatval($order['amount']),
        'content_type' => 'product',
        'content_ids' => [$order['package'] ?? 'unknown'],
        'content_name' => 'MomentoCrypto ' . ucfirst($order['package'] ?? 'unknown'),
        'num_items' => 1,
    ];

    $eventData = [
        'event_name' => 'Purchase',
        'event_time' => time(),
        'event_id' => $order['order_id'],
        'event_source_url' => $attr['landing_url'] ?? 'https://momentocrypto.com/br',
        'action_source' => 'website',
        'user_data' => (object) $userData,
        'custom_data' => $customData,
    ];

    $body = ['data' => [$eventData]];
    if (!empty($env['META_TEST_EVENT_CODE'])) {
        $body['test_event_code'] = $env['META_TEST_EVENT_CODE'];
    }

    $url = "https://graph.facebook.com/v18.0/{$pixelId}/events?access_token={$capiToken}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents(__DIR__ . '/meta_capi.log',
        date('Y-m-d H:i:s') . " | order={$order['order_id']} | value={$order['amount']} {$order['currency']} | http={$httpCode} | resp=" . substr($response, 0, 500) . "\n",
        FILE_APPEND);

    return ['http' => $httpCode, 'response' => $response];
}
