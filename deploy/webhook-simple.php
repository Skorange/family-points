<?php
file_put_contents('/tmp/webhook_simple.log', "Webhook called at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

$secret = 'family_points_secret_2024';
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

file_put_contents('/tmp/webhook_simple.log', "Payload: $payload\n", FILE_APPEND);
file_put_contents('/tmp/webhook_simple.log', "Signature: $signature\n", FILE_APPEND);

if (empty($signature)) {
    http_response_code(403);
    file_put_contents('/tmp/webhook_simple.log', "No signature\n", FILE_APPEND);
    die('No signature');
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
file_put_contents('/tmp/webhook_simple.log', "Expected: $expected\n", FILE_APPEND);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    file_put_contents('/tmp/webhook_simple.log', "Invalid signature\n", FILE_APPEND);
    die('Invalid signature');
}

file_put_contents('/tmp/webhook_simple.log', "Signature OK, running deploy\n", FILE_APPEND);

chdir('/var/www/family-points');
exec('bash deploy.sh >> /var/log/family-points-deploy.log 2>&1');

file_put_contents('/tmp/webhook_simple.log', "Deploy triggered\n", FILE_APPEND);

echo 'Deploy triggered';
