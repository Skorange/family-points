<?php
// Webhook Receiver for FamilyPoints
file_put_contents('/tmp/webhook_debug.log', date('Y-m-d H:i:s') . " - Webhook received\n", FILE_APPEND);

$secret = 'family_points_secret_2024';
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

file_put_contents('/tmp/webhook_debug.log', date('Y-m-d H:i:s') . " - Payload: $payload\n", FILE_APPEND);
file_put_contents('/tmp/webhook_debug.log', date('Y-m-d H:i:s') . " - Signature: $signature\n", FILE_APPEND);

if (!verifySignature($payload, $signature, $secret)) {
    http_response_code(403);
    die('Invalid signature');
}

file_put_contents('/tmp/webhook_debug.log', date('Y-m-d H:i:s') . " - Signature verified, running deploy\n", FILE_APPEND);

chdir('/var/www/family-points');
$output = [];
$return = 0;
exec('bash deploy.sh >> /var/log/family-points-deploy.log 2>&1', $output, $return);

file_put_contents('/tmp/webhook_debug.log', date('Y-m-d H:i:s') . " - Exec return: $return\n", FILE_APPEND);
file_put_contents('/tmp/webhook_debug.log', date('Y-m-d H:i:s') . " - Output: " . implode(',', $output) . "\n", FILE_APPEND);

echo 'Deploy triggered';

function verifySignature($payload, $signature, $secret) {
    if (empty($signature)) return false;
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}
