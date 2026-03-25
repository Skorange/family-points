<?php
/**
 * 用户认证 API（南瓜之家）
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
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
                case 'login':
                    login($db, $input);
                    break;
                case 'setup':
                    setup($db, $input);
                    break;
                case 'create_child':
                    createChild($db, $input);
                    break;
                case 'create_parent':
                    createParent($db, $input);
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
 * 登录（通过成员ID）
 * member_id: 预设成员标识符（如 p_dad, c_pumpkin）或数据库中的username
 */
function login($db, $input) {
    $memberId = trim($input['member_id'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($memberId) || empty($password)) {
        jsonResponse(['error' => '请选择成员并输入密码'], 400);
    }

    // member_id 可能是预设ID（如 p_dad）或 username
    // 先用 username 查找，找不到再用 member_id 查找
    $stmt = $db->prepare('SELECT u.*, f.name as family_name, f.invite_code FROM users u LEFT JOIN families f ON u.family_id = f.id WHERE u.username = ?');
    $stmt->execute([$memberId]);
    $user = $stmt->fetch();

    if (!$user) {
        // 用 member_id 列查找（预设账号的 id 存储在 member_id 字段）
        $stmt = $db->prepare('SELECT u.*, f.name as family_name, f.invite_code FROM users u LEFT JOIN families f ON u.family_id = f.id WHERE u.member_id = ?');
        $stmt->execute([$memberId]);
        $user = $stmt->fetch();
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['error' => '密码错误'], 401);
    }

    $token = generateToken($user);

    jsonResponse([
        'message' => '登录成功',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'points' => $user['points'],
            'avatar' => $user['avatar'] ?? '',
            'family_id' => $user['family_id'],
            'family_name' => $user['family_name'] ?? '南瓜之家'
        ]
    ]);
}

/**
 * 初始化：创建家庭 + 第一个成员
 */
function setup($db, $input) {
    $memberId = trim($input['member_id'] ?? '');
    $memberName = trim($input['member_name'] ?? '');
    $role = $input['role'] ?? 'parent';
    $password = $input['password'] ?? '';

    if (empty($memberId) || empty($memberName) || empty($password)) {
        jsonResponse(['error' => '请填写完整信息'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => '密码至少6位'], 400);
    }

    // 创建家庭
    $inviteCode = generateInviteCode();
    $stmt = $db->prepare('INSERT INTO families (name, invite_code) VALUES (?, ?)');
    $stmt->execute(['南瓜之家', $inviteCode]);
    $familyId = $db->lastInsertId();

    // 创建成员账号
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (family_id, username, member_id, password_hash, role, points) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$familyId, $memberName, $memberId, $passwordHash, $role, $role === 'child' ? 0 : 0]);
    $userId = $db->lastInsertId();

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $token = generateToken($user);

    jsonResponse([
        'message' => '初始化成功',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'points' => $user['points'],
            'family_id' => $user['family_id'],
            'family_name' => '南瓜之家',
            'avatar' => ''
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
        jsonResponse(['error' => '只有家长可以创建账号'], 403);
    }

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        jsonResponse(['error' => '请填写用户名和密码'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => '密码至少6位'], 400);
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE family_id = ? AND (username = ? OR member_id = ?)');
    $stmt->execute([$user['family_id'], $username, $username]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => '该用户名已存在'], 400);
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (family_id, username, member_id, password_hash, role, points) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$user['family_id'], $username, '', $passwordHash, 'child', 0]);

    jsonResponse(['message' => '账号创建成功']);
}

/**
 * 家长创建家长账号
 */
function createParent($db, $input) {
    $tokenData = verifyToken();
    $user = $tokenData['user'];

    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以创建账号'], 403);
    }

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        jsonResponse(['error' => '请填写用户名和密码'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => '密码至少6位'], 400);
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE family_id = ? AND (username = ? OR member_id = ?)');
    $stmt->execute([$user['family_id'], $username, $username]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => '该用户名已存在'], 400);
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (family_id, username, member_id, password_hash, role, points) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$user['family_id'], $username, '', $passwordHash, 'parent', 0]);

    jsonResponse(['message' => '家长账号创建成功']);
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
            'role' => $user['role'],
            'points' => $user['points'],
            'avatar' => $user['avatar'] ?? '',
            'family_id' => $user['family_id'],
            'family_name' => $user['family_name'] ?? '南瓜之家'
        ]
    ]);
}

/**
 * 生成邀请码
 */
function generateInviteCode() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}
