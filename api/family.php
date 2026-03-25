<?php
/**
 * 家庭资料 API
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $tokenData = verifyToken();
    $user = $tokenData['user'];
    $db = getDB();

    switch ($method) {
        case 'GET':
            getFamilyInfo($db, $user);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createFamilyInfo($db, $user, $input);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateFamilyInfo($db, $user, $input);
            break;
        case 'DELETE':
            $infoId = intval($_GET['id'] ?? 0);
            deleteFamilyInfo($db, $user, $infoId);
            break;
        default:
            jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

/**
 * 获取家庭资料
 */
function getFamilyInfo($db, $user) {
    $category = $_GET['category'] ?? '';

    $sql = '
        SELECT fi.*, u.username as created_by_name
        FROM family_info fi
        JOIN users u ON fi.created_by = u.id
        WHERE fi.family_id = ?
    ';
    $params = [$user['family_id']];

    if ($category) {
        $sql .= ' AND fi.category = ?';
        $params[] = $category;
    }

    $sql .= ' ORDER BY fi.category, fi.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $info = $stmt->fetchAll();

    // 处理生日倒计时
    foreach ($info as &$item) {
        if ($item['category'] === 'birthday' && !empty($item['content'])) {
            $item['days_until'] = calculateDaysUntil($item['content']);
            $item['next_birthday'] = getNextBirthday($item['content']);
        }
    }

    // 获取家庭成员
    $stmt = $db->prepare('SELECT id, username, role, points FROM users WHERE family_id = ?');
    $stmt->execute([$user['family_id']]);
    $members = $stmt->fetchAll();

    // 获取家庭信息
    $stmt = $db->prepare('SELECT * FROM families WHERE id = ?');
    $stmt->execute([$user['family_id']]);
    $family = $stmt->fetch();

    jsonResponse([
        'family' => $family,
        'members' => $members,
        'info' => $info
    ]);
}

/**
 * 创建家庭资料（仅家长）
 */
function createFamilyInfo($db, $user, $input) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以添加家庭资料'], 403);
    }

    $category = $input['category'] ?? '';
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $remindDays = intval($input['remind_days'] ?? 7);

    $validCategories = ['birthday', 'document', 'event', 'note'];
    if (!in_array($category, $validCategories)) {
        jsonResponse(['error' => '无效的分类'], 400);
    }

    if (empty($title)) {
        jsonResponse(['error' => '标题不能为空'], 400);
    }

    $stmt = $db->prepare('
        INSERT INTO family_info (family_id, category, title, content, remind_days, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['family_id'],
        $category,
        $title,
        $content,
        $remindDays,
        $user['id']
    ]);

    jsonResponse([
        'message' => '资料添加成功',
        'info' => [
            'id' => $db->lastInsertId(),
            'category' => $category,
            'title' => $title
        ]
    ]);
}

/**
 * 更新家庭资料（仅家长）
 */
function updateFamilyInfo($db, $user, $input) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以修改家庭资料'], 403);
    }

    $infoId = intval($input['id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $remindDays = intval($input['remind_days'] ?? 7);

    if ($infoId <= 0) {
        jsonResponse(['error' => '无效的ID'], 400);
    }

    $stmt = $db->prepare('SELECT * FROM family_info WHERE id = ? AND family_id = ?');
    $stmt->execute([$infoId, $user['family_id']]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => '资料不存在'], 404);
    }

    $stmt = $db->prepare('
        UPDATE family_info SET title = ?, content = ?, remind_days = ?
        WHERE id = ?
    ');
    $stmt->execute([$title, $content, $remindDays, $infoId]);

    jsonResponse(['message' => '资料已更新']);
}

/**
 * 删除家庭资料（仅家长）
 */
function deleteFamilyInfo($db, $user, $infoId) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以删除家庭资料'], 403);
    }

    $stmt = $db->prepare('DELETE FROM family_info WHERE id = ? AND family_id = ?');
    $stmt->execute([$infoId, $user['family_id']]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => '资料不存在'], 404);
    }

    jsonResponse(['message' => '资料已删除']);
}

/**
 * 计算距离指定日期的天数
 */
function calculateDaysUntil($date) {
    $target = strtotime($date);
    $today = strtotime(date('Y-m-d'));
    $diff = $target - $today;

    if ($diff < 0) {
        // 已经过了，计算到明年的天数
        $nextYear = date('Y') + 1;
        $target = strtotime(date('m-d', $target) . '-' . $nextYear);
        $diff = $target - $today;
    }

    return ceil($diff / 86400);
}

/**
 * 获取下一个生日日期
 */
function getNextBirthday($date) {
    $birthday = date('m-d', strtotime($date));
    $year = date('Y');

    if (strtotime($year . '-' . $birthday) < strtotime(date('Y-m-d'))) {
        $year++;
    }

    return $year . '-' . $birthday;
}
