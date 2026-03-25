<?php
/**
 * 家庭公告 API（南瓜之家）
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
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
            getAnnouncements($db, $user);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createAnnouncement($db, $user, $input);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateAnnouncement($db, $user, $input);
            break;
        case 'DELETE':
            $id = intval($_GET['id'] ?? 0);
            deleteAnnouncement($db, $user, $id);
            break;
        default:
            jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

/**
 * 获取公告列表
 */
function getAnnouncements($db, $user) {
    $stmt = $db->prepare('
        SELECT a.*, u.username as author_name
        FROM announcements a
        JOIN users u ON a.created_by = u.id
        WHERE a.family_id = ?
        ORDER BY a.is_pinned DESC, a.created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$user['family_id']]);
    $list = $stmt->fetchAll();

    // 判断当前用户是否可以编辑（爸妈）
    $canEdit = in_array($user['role'], ['parent']);

    jsonResponse([
        'announcements' => $list,
        'can_edit' => $canEdit
    ]);
}

/**
 * 创建公告（仅爸妈）
 */
function createAnnouncement($db, $user, $input) {
    // 仅爸妈可以发布公告
    if (!in_array($user['role'], ['parent'])) {
        jsonResponse(['error' => '只有家长可以发布公告'], 403);
    }

    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $isPinned = isset($input['is_pinned']) ? 1 : 0;

    if (empty($title) && empty($content)) {
        jsonResponse(['error' => '标题和内容不能同时为空'], 400);
    }

    $stmt = $db->prepare('
        INSERT INTO announcements (family_id, title, content, is_pinned, created_by)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['family_id'],
        $title ?: '公告',
        $content,
        $isPinned,
        $user['id']
    ]);

    jsonResponse([
        'message' => '公告已发布',
        'id' => $db->lastInsertId()
    ]);
}

/**
 * 更新公告（仅爸妈）
 */
function updateAnnouncement($db, $user, $input) {
    if (!in_array($user['role'], ['parent'])) {
        jsonResponse(['error' => '只有家长可以修改公告'], 403);
    }

    $id = intval($input['id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $isPinned = isset($input['is_pinned']) ? 1 : 0;

    if ($id <= 0) {
        jsonResponse(['error' => '无效的公告ID'], 400);
    }

    // 验证归属
    $stmt = $db->prepare('SELECT * FROM announcements WHERE id = ? AND family_id = ?');
    $stmt->execute([$id, $user['family_id']]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => '公告不存在'], 404);
    }

    $stmt = $db->prepare('UPDATE announcements SET title = ?, content = ?, is_pinned = ? WHERE id = ?');
    $stmt->execute([$title ?: '公告', $content, $isPinned, $id]);

    jsonResponse(['message' => '公告已更新']);
}

/**
 * 删除公告（仅爸妈）
 */
function deleteAnnouncement($db, $user, $id) {
    if (!in_array($user['role'], ['parent'])) {
        jsonResponse(['error' => '只有家长可以删除公告'], 403);
    }

    if ($id <= 0) {
        jsonResponse(['error' => '无效的公告ID'], 400);
    }

    $stmt = $db->prepare('DELETE FROM announcements WHERE id = ? AND family_id = ?');
    $stmt->execute([$id, $user['family_id']]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => '公告不存在'], 404);
    }

    jsonResponse(['message' => '公告已删除']);
}
