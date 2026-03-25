<?php
/**
 * 任务管理 API
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
            getTasks($db, $user);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['action']) && $input['action'] === 'complete') {
                completeTask($db, $user, $input);
            } elseif (isset($input['action']) && $input['action'] === 'claim') {
                claimTask($db, $user, $input);
            } else {
                createTask($db, $user, $input);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateTask($db, $user, $input);
            break;
        case 'DELETE':
            $taskId = $_GET['id'] ?? 0;
            deleteTask($db, $user, $taskId);
            break;
        default:
            jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

/**
 * 获取任务列表
 */
function getTasks($db, $user) {
    $familyId = $user['family_id'];

    // 获取家庭所有任务
    $stmt = $db->prepare('
        SELECT t.*, u.username as created_by_name,
            (SELECT completed_at FROM task_completions tc WHERE tc.task_id = t.id AND tc.user_id = ? ORDER BY completed_at DESC LIMIT 1) as last_completed
        FROM tasks t
        JOIN users u ON t.created_by = u.id
        WHERE t.family_id = ?
        ORDER BY t.created_at DESC
    ');
    $stmt->execute([$user['id'], $familyId]);
    $tasks = $stmt->fetchAll();

    // 检查每日/每周任务是否可完成
    foreach ($tasks as &$task) {
        $task['can_complete'] = canCompleteTask($task);
    }

    jsonResponse(['tasks' => $tasks]);
}

/**
 * 创建任务（仅家长）
 */
function createTask($db, $user, $input) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以创建任务'], 403);
    }

    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $points = intval($input['points'] ?? 10);
    $deadline = $input['deadline'] ?? null;
    $repeatType = $input['repeat_type'] ?? 'once';

    if (empty($title)) {
        jsonResponse(['error' => '任务名称不能为空'], 400);
    }

    if ($points < 1 || $points > 500) {
        jsonResponse(['error' => '积分必须在1-500之间'], 400);
    }

    $stmt = $db->prepare('
        INSERT INTO tasks (family_id, title, description, points, deadline, repeat_type, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['family_id'],
        $title,
        $description,
        $points,
        $deadline ?: null,
        $repeatType,
        $user['id']
    ]);

    jsonResponse([
        'message' => '任务创建成功',
        'task' => [
            'id' => $db->lastInsertId(),
            'title' => $title,
            'points' => $points,
            'repeat_type' => $repeatType
        ]
    ]);
}

/**
 * 完成任务（孩子）
 */
function completeTask($db, $user, $input) {
    $taskId = intval($input['task_id'] ?? 0);

    if ($taskId <= 0) {
        jsonResponse(['error' => '无效的任务ID'], 400);
    }

    // 获取任务
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND family_id = ?');
    $stmt->execute([$taskId, $user['family_id']]);
    $task = $stmt->fetch();

    if (!$task) {
        jsonResponse(['error' => '任务不存在'], 404);
    }

    // 检查是否可以完成
    if (!canCompleteTask($task)) {
        jsonResponse(['error' => '该任务今天已完成或不可用'], 400);
    }

    // 记录完成
    $stmt = $db->prepare('INSERT INTO task_completions (task_id, user_id) VALUES (?, ?)');
    $stmt->execute([$taskId, $user['id']]);

    // 自动发放积分（按需求：自动到账）
    $stmt = $db->prepare('UPDATE users SET points = points + ? WHERE id = ?');
    $stmt->execute([$task['points'], $user['id']]);

    // 记录积分变动
    $stmt = $db->prepare('INSERT INTO point_logs (user_id, task_id, amount, type, note) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$user['id'], $taskId, $task['points'], 'earn', '完成任务：' . $task['title']]);

    jsonResponse([
        'message' => '任务完成！获得 ' . $task['points'] . ' 积分',
        'points_earned' => $task['points'],
        'new_balance' => getUserPoints($db, $user['id'])
    ]);
}

/**
 * 认领任务（孩子）
 */
function claimTask($db, $user, $input) {
    $taskId = intval($input['task_id'] ?? 0);

    if ($taskId <= 0) {
        jsonResponse(['error' => '无效的任务ID'], 400);
    }

    jsonResponse(['message' => '任务已认领'], 200);
}

/**
 * 更新任务（仅家长）
 */
function updateTask($db, $user, $input) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以修改任务'], 403);
    }

    $taskId = intval($input['id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $points = intval($input['points'] ?? 10);
    $deadline = $input['deadline'] ?? null;
    $repeatType = $input['repeat_type'] ?? 'once';

    if ($taskId <= 0) {
        jsonResponse(['error' => '无效的任务ID'], 400);
    }

    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND family_id = ?');
    $stmt->execute([$taskId, $user['family_id']]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => '任务不存在'], 404);
    }

    $stmt = $db->prepare('
        UPDATE tasks SET title = ?, description = ?, points = ?, deadline = ?, repeat_type = ?
        WHERE id = ?
    ');
    $stmt->execute([$title, $description, $points, $deadline ?: null, $repeatType, $taskId]);

    jsonResponse(['message' => '任务已更新']);
}

/**
 * 删除任务（仅家长）
 */
function deleteTask($db, $user, $taskId) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以删除任务'], 403);
    }

    $taskId = intval($taskId);

    $stmt = $db->prepare('DELETE FROM tasks WHERE id = ? AND family_id = ?');
    $stmt->execute([$taskId, $user['family_id']]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => '任务不存在'], 404);
    }

    jsonResponse(['message' => '任务已删除']);
}

/**
 * 检查任务是否可以完成
 */
function canCompleteTask($task) {
    $repeatType = $task['repeat_type'];

    if ($repeatType === 'once') {
        return empty($task['last_completed']);
    }

    if ($repeatType === 'daily') {
        if (empty($task['last_completed'])) {
            return true;
        }
        $lastDate = date('Y-m-d', strtotime($task['last_completed']));
        $today = date('Y-m-d');
        return $lastDate !== $today;
    }

    if ($repeatType === 'weekly') {
        if (empty($task['last_completed'])) {
            return true;
        }
        $lastWeek = date('W', strtotime($task['last_completed']));
        $currentWeek = date('W');
        $lastYear = date('Y', strtotime($task['last_completed']));
        $currentYear = date('Y');
        return $lastWeek !== $currentWeek || $lastYear !== $currentYear;
    }

    return true;
}

/**
 * 获取用户积分
 */
function getUserPoints($db, $userId) {
    $stmt = $db->prepare('SELECT points FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result['points'] ?? 0;
}
