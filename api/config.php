<?php
/**
 * 数据库配置
 */
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'family_points');
define('DB_USER', getenv('DB_USER') ?: 'familyuser');
define('DB_PASS', getenv('DB_PASS') ?: 'family_pass_2024');

/**
 * JWT 密钥
 */
define('JWT_SECRET', 'family_points_jwt_secret_2024');

/**
 * 获取数据库连接
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => '数据库连接失败']);
            exit;
        }
    }
    return $pdo;
}

/**
 * 返回 JSON 响应
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 验证 JWT Token
 */
function verifyToken() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
        jsonResponse(['error' => '未提供认证令牌'], 401);
    }

    $token = $matches[1];
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        jsonResponse(['error' => '无效的令牌格式'], 401);
    }

    [$header, $payload, $signature] = $parts;

    $expected = base64_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );
    $expected = rtrim(strtr($expected, '+/', '-_'), '=');

    if ($signature !== $expected) {
        jsonResponse(['error' => '令牌验证失败'], 401);
    }

    $payloadData = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

    if (!$payloadData || $payloadData['exp'] < time()) {
        jsonResponse(['error' => '令牌已过期'], 401);
    }

    return $payloadData;
}

/**
 * 生成 JWT Token
 */
function generateToken($user) {
    $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $header = rtrim(strtr($header, '+/', '-_'), '=');

    $payload = base64_encode(json_encode([
        'iss' => 'family_points',
        'exp' => time() + 86400, // 24小时
        'iat' => time(),
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'] ?? null,
            'username' => $user['username'],
            'member_id' => $user['member_id'] ?? '',
            'role' => $user['role'],
            'family_id' => $user['family_id']
        ]
    ]));
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');

    $signature = base64_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );
    $signature = rtrim(strtr($signature, '+/', '-_'), '=');

    return "$header.$payload.$signature";
}
