<?php
/**
 * 用户认证 API
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';

            switch ($action) {
                case 'register':
                    register($db, $input);
                    break;
                case 'login':
                    login($db, $input);
                    break;
                case 'create_child':
                    createChild($db, $input);
                    break;
                default:
                    jsonResponse(['error' => '未知的操作'], 400);
            }
            break;

        case 'GET':
            $tokenData = verifyToken();
            getProfile($db, $tokenData['user']);
            break;

        default:
            jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

/**
 * 注册（家长创建家庭）
 */
function register($db, $input) {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $username = trim($input['username'] ?? '');
    $familyName = trim($input['family_name'] ?? '');

    if (empty($email) || empty($password) || empty($username)) {
        jsonResponse(['error' => '请填写所有必填字段'], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => '邮箱格式不正确'], 400);
    }

    if (strlen($password) < 6) {
        jsonResponse(['error' => '密码至少6位'], 400);
    }

    // 检查邮箱是否已存在
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => '该邮箱已注册'], 400);
    }

    // 创建家庭
    $inviteCode = generateInviteCode();
    $stmt = $db->prepare('INSERT INTO families (name, invite_code) VALUES (?, ?)');
    $stmt->execute([$familyName ?: '我的家庭', $inviteCode]);
    $familyId = $db->lastInsertId();

    // 创建家长账号
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (family_id, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$familyId, $username, $email, $passwordHash, 'parent']);
    $userId = $db->lastInsertId();

    // 获取用户信息
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $token = generateToken($user);

    jsonResponse([
        'message' => '注册成功',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'family_id' => $user['family_id'],
            'family_name' => $familyName ?: '我的家庭',
            'invite_code' => $inviteCode
        ]
    ]);
}

/**
 * 登录
 */
function login($db, $input) {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        jsonResponse(['error' => '请填写邮箱和密码'], 400);
    }

    $stmt = $db->prepare('SELECT u.*, f.name as family_name FROM users u LEFT JOIN families f ON u.family_id = f.id WHERE u.email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['error' => '邮箱或密码错误'], 401);
    }

    $token = generateToken($user);

    jsonResponse([
        'message' => '登录成功',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'points' => $user['points'],
            'family_id' => $user['family_id'],
            'family_name' => $user['family_name'] ?? ''
        ]
    ]);
}

/**
 * 家长创建孩子账号
 */
function createChild($db, $input) {
    $tokenData = verifyToken();
    $user = $tokenData['user'];

    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以创建孩子账号'], 403);
    }

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        jsonResponse(['error' => '请填写用户名和密码'], 400);
    }

    // 检查用户名是否已存在
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND family_id = ?');
    $stmt->execute([$username, $user['family_id']]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => '该用户名已存在'], 400);
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (family_id, username, password_hash, role, points) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$user['family_id'], $username, $passwordHash, 'child', 0]);
    $childId = $db->lastInsertId();

    jsonResponse([
        'message' => '孩子账号创建成功',
        'child' => [
            'id' => $childId,
            'username' => $username,
            'role' => 'child',
            'points' => 0
        ]
    ]);
}

/**
 * 获取用户资料
 */
function getProfile($db, $userData) {
    $stmt = $db->prepare('SELECT u.*, f.name as family_name, f.invite_code FROM users u LEFT JOIN families f ON u.family_id = f.id WHERE u.id = ?');
    $stmt->execute([$userData['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => '用户不存在'], 404);
    }

    jsonResponse([
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'points' => $user['points'],
            'avatar' => $user['avatar'],
            'family_id' => $user['family_id'],
            'family_name' => $user['family_name'] ?? '',
            'invite_code' => $user['invite_code'] ?? ''
        ]
    ]);
}

/**
 * 生成邀请码
 */
function generateInviteCode() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}
