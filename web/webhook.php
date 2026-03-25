<?php
$secret = 'family_points_secret_2024';
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (empty($signature)) {
    http_response_code(403);
    die('No signature');
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    die('Invalid signature');
}

chdir('/var/www/family-points');
exec('bash deploy.sh >> /var/log/family-points-deploy.log 2>&1');

echo 'Deploy triggered';
