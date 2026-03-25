<?php
/**
 * Webhook Receiver for FamilyPoints
 * Receives GitHub webhook and triggers deployment
 */

$secret = 'family_points_secret_2024'; // 改成你自己的密钥

// 验证请求
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!verifySignature($payload, $signature, $secret)) {
    http_response_code(403);
    die('Invalid signature');
}

// 执行部署脚本
$output = [];
$returnCode = 0;
exec('bash /var/www/family-points/deploy.sh 2>&1', $output, $returnCode);

echo "Deploy completed with code: $returnCode\n";
echo implode("\n", $output);

if ($returnCode !== 0) {
    http_response_code(500);
}

function verifySignature($payload, $signature, $secret) {
    if (empty($signature)) return false;
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}
