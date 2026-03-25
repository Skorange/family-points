<?php
/**
 * 家庭日历事件 API（南瓜之家）
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

    // 只有爸爸妈妈可以管理日历事件
    $canManage = in_array($user['member_id'], ['p_dad', 'p_mom']);

    switch ($method) {
        case 'GET':
            getEvents($db, $user, $canManage);
            break;
        case 'POST':
            if (!$canManage) jsonResponse(['error' => '权限不足'], 403);
            createEvent($db, $user);
            break;
        case 'PUT':
            if (!$canManage) jsonResponse(['error' => '权限不足'], 403);
            updateEvent($db, $user);
            break;
        case 'DELETE':
            if (!$canManage) jsonResponse(['error' => '权限不足'], 403);
            $id = intval($_GET['id'] ?? 0);
            deleteEvent($db, $user, $id);
            break;
        default:
            jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

/**
 * 获取事件列表（支持月份筛选）
 */
function getEvents($db, $user, $canManage) {
    $year = intval($_GET['year'] ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));

    // 获取指定月份的事件
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = sprintf('%04d-%02d-31', $year, $month);

    $stmt = $db->prepare('
        SELECT e.*, u.username as creator_name
        FROM events e
        JOIN users u ON e.created_by = u.id
        WHERE e.family_id = ?
          AND e.event_date BETWEEN ? AND ?
        ORDER BY e.event_date ASC, e.id ASC
    ');
    $stmt->execute([$user['family_id'], $startDate, $endDate]);
    $events = $stmt->fetchAll();

    // 计算即将到来的生日
    $stmt2 = $db->prepare('
        SELECT title, content as event_date, "birthday" as category
        FROM family_info
        WHERE family_id = ? AND category = "birthday"
        ORDER BY MONTH(STR_TO_DATE(content, "%Y-%m-%d")), DAY(STR_TO_DATE(content, "%Y-%m-%d"))
    ');
    $stmt2->execute([$user['family_id']]);
    $birthdays = $stmt2->fetchAll();

    // 补充距离天数
    foreach ($birthdays as &$b) {
        $b['days_until'] = calculateDaysUntil($b['event_date']);
        $b['title'] = '🎂 ' . $b['title'];
    }

    jsonResponse([
        'events' => $events,
        'birthdays' => $birthdays,
        'can_manage' => $canManage
    ]);
}

/**
 * 创建事件（仅爸爸妈妈）
 */
function createEvent($db, $user) {
    $input = json_decode(file_get_contents('php://input'), true);

    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $eventDate = trim($input['event_date'] ?? '');
    $category = $input['category'] ?? 'event'; // birthday/event/travel/reward
    $isAllDay = isset($input['is_all_day']) ? 1 : 0;

    if (empty($title) || empty($eventDate)) {
        jsonResponse(['error' => '标题和日期不能为空'], 400);
    }

    if (!in_array($category, ['birthday', 'event', 'travel', 'reward'])) {
        $category = 'event';
    }

    $stmt = $db->prepare('
        INSERT INTO events (family_id, title, description, event_date, category, is_all_day, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['family_id'],
        $title,
        $description,
        $eventDate,
        $category,
        $isAllDay,
        $user['id']
    ]);

    jsonResponse([
        'message' => '日程已添加',
        'id' => $db->lastInsertId()
    ]);
}

/**
 * 更新事件
 */
function updateEvent($db, $user) {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = intval($input['id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $eventDate = trim($input['event_date'] ?? '');
    $category = $input['category'] ?? 'event';
    $isAllDay = isset($input['is_all_day']) ? 1 : 0;

    if ($id <= 0) {
        jsonResponse(['error' => '无效的事件ID'], 400);
    }

    // 验证归属
    $stmt = $db->prepare('SELECT * FROM events WHERE id = ? AND family_id = ?');
    $stmt->execute([$id, $user['family_id']]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => '事件不存在'], 404);
    }

    $stmt = $db->prepare('
        UPDATE events SET title = ?, description = ?, event_date = ?, category = ?, is_all_day = ?
        WHERE id = ?
    ');
    $stmt->execute([$title, $description, $eventDate, $category, $isAllDay, $id]);

    jsonResponse(['message' => '日程已更新']);
}

/**
 * 删除事件
 */
function deleteEvent($db, $user, $id) {
    if ($id <= 0) {
        jsonResponse(['error' => '无效的事件ID'], 400);
    }

    $stmt = $db->prepare('DELETE FROM events WHERE id = ? AND family_id = ?');
    $stmt->execute([$id, $user['family_id']]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => '事件不存在'], 404);
    }

    jsonResponse(['message' => '日程已删除']);
}

/**
 * 计算距离指定日期的天数
 */
function calculateDaysUntil($date) {
    $target = strtotime($date);
    $today = strtotime(date('Y-m-d'));
    $diff = $target - $today;

    if ($diff < 0) {
        $nextYear = date('Y') + 1;
        $target = strtotime(date('m-d', $target) . '-' . $nextYear);
        $diff = $target - $today;
    }

    return ceil($diff / 86400);
}
