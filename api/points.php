<?php
/**
 * 积分和奖励 API
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
            $type = $_GET['type'] ?? '';
            if ($type === 'rank') {
                getRanking($db, $user);
            } else {
                getPointsHistory($db, $user);
            }
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';

            switch ($action) {
                case 'adjust':
                    adjustPoints($db, $user, $input);
                    break;
                case 'add_reward':
                    addReward($db, $user, $input);
                    break;
                case 'redeem':
                    redeemReward($db, $user, $input);
                    break;
                case 'approve_redemption':
                    approveRedemption($db, $user, $input);
                    break;
                default:
                    jsonResponse(['error' => '未知的操作'], 400);
            }
            break;
        default:
            jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

/**
 * 获取积分历史
 */
function getPointsHistory($db, $user) {
    $stmt = $db->prepare('
        SELECT pl.*, t.title as task_title
        FROM point_logs pl
        LEFT JOIN tasks t ON pl.task_id = t.id
        WHERE pl.user_id = ?
        ORDER BY pl.created_at DESC
        LIMIT 50
    ');
    $stmt->execute([$user['id']]);
    $history = $stmt->fetchAll();

    // 获取当前积分
    $stmt = $db->prepare('SELECT points FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $currentPoints = $stmt->fetch()['points'];

    jsonResponse([
        'current_points' => $currentPoints,
        'history' => $history
    ]);
}

/**
 * 获取家庭积分排行
 */
function getRanking($db, $user) {
    $stmt = $db->prepare('
        SELECT id, username, points, role, avatar
        FROM users
        WHERE family_id = ?
        ORDER BY points DESC
    ');
    $stmt->execute([$user['family_id']]);
    $ranking = $stmt->fetchAll();

    jsonResponse(['ranking' => $ranking]);
}

/**
 * 调整积分（仅家长）
 */
function adjustPoints($db, $user, $input) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以调整积分'], 403);
    }

    $targetUserId = intval($input['user_id'] ?? 0);
    $amount = intval($input['amount'] ?? 0);
    $type = $input['type'] ?? 'bonus'; // bonus 或 deduct
    $note = trim($input['note'] ?? '');

    if ($targetUserId <= 0) {
        jsonResponse(['error' => '请选择用户'], 400);
    }

    if ($amount <= 0 || $amount > 1000) {
        jsonResponse(['error' => '积分调整必须在1-1000之间'], 400);
    }

    // 检查目标用户是否在同一家庭
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND family_id = ?');
    $stmt->execute([$targetUserId, $user['family_id']]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        jsonResponse(['error' => '用户不存在'], 404);
    }

    $adjustAmount = $type === 'deduct' ? -$amount : $amount;
    $logType = $type === 'deduct' ? 'deduct' : 'bonus';

    // 更新积分
    $stmt = $db->prepare('UPDATE users SET points = points + ? WHERE id = ?');
    $stmt->execute([$adjustAmount, $targetUserId]);

    // 记录
    $stmt = $db->prepare('INSERT INTO point_logs (user_id, amount, type, note) VALUES (?, ?, ?, ?)');
    $stmt->execute([$targetUserId, $adjustAmount, $logType, $note ?: ($type === 'deduct' ? '扣分' : '奖励')]);

    jsonResponse([
        'message' => '积分调整成功',
        'new_balance' => getUserPoints($db, $targetUserId)
    ]);
}

/**
 * 添加奖励（仅家长）
 */
function addReward($db, $user, $input) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以添加奖励'], 403);
    }

    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $pointsCost = intval($input['points_cost'] ?? 0);
    $stock = intval($input['stock'] ?? -1);

    if (empty($title)) {
        jsonResponse(['error' => '奖励名称不能为空'], 400);
    }

    if ($pointsCost < 1) {
        jsonResponse(['error' => '所需积分必须大于0'], 400);
    }

    $stmt = $db->prepare('
        INSERT INTO rewards (family_id, title, description, points_cost, stock)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['family_id'],
        $title,
        $description,
        $pointsCost,
        $stock
    ]);

    jsonResponse([
        'message' => '奖励添加成功',
        'reward' => [
            'id' => $db->lastInsertId(),
            'title' => $title,
            'points_cost' => $pointsCost
        ]
    ]);
}

/**
 * 兑换奖励（孩子）
 */
function redeemReward($db, $user, $input) {
    $rewardId = intval($input['reward_id'] ?? 0);

    if ($rewardId <= 0) {
        jsonResponse(['error' => '无效的奖励ID'], 400);
    }

    // 获取奖励
    $stmt = $db->prepare('SELECT * FROM rewards WHERE id = ? AND family_id = ? AND is_active = 1');
    $stmt->execute([$rewardId, $user['family_id']]);
    $reward = $stmt->fetch();

    if (!$reward) {
        jsonResponse(['error' => '奖励不存在或已下架'], 404);
    }

    // 检查库存
    if ($reward['stock'] === 0) {
        jsonResponse(['error' => '该奖励已兑换完'], 400);
    }

    // 检查积分是否足够
    if ($user['points'] < $reward['points_cost']) {
        jsonResponse(['error' => '积分不足'], 400);
    }

    // 创建兑换申请
    $stmt = $db->prepare('
        INSERT INTO redemptions (user_id, reward_id, points_spent, status)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$user['id'], $rewardId, $reward['points_cost'], 'pending']);

    jsonResponse([
        'message' => '兑换申请已提交，等待家长审批',
        'redemption_id' => $db->lastInsertId()
    ]);
}

/**
 * 审批兑换（仅家长）
 */
function approveRedemption($db, $user, $input) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以审批兑换'], 403);
    }

    $redemptionId = intval($input['redemption_id'] ?? 0);
    $action = $input['action'] ?? 'approve'; // approve 或 reject

    if ($redemptionId <= 0) {
        jsonResponse(['error' => '无效的兑换ID'], 400);
    }

    // 获取兑换记录
    $stmt = $db->prepare('
        SELECT r.*, u.username, u.points as user_points
        FROM redemptions r
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND u.family_id = ?
    ');
    $stmt->execute([$redemptionId, $user['family_id']]);
    $redemption = $stmt->fetch();

    if (!$redemption) {
        jsonResponse(['error' => '兑换记录不存在'], 404);
    }

    if ($redemption['status'] !== 'pending') {
        jsonResponse(['error' => '该兑换已处理'], 400);
    }

    if ($action === 'approve') {
        // 扣除积分
        $stmt = $db->prepare('UPDATE users SET points = points - ? WHERE id = ?');
        $stmt->execute([$redemption['points_spent'], $redemption['user_id']]);

        // 记录积分变动
        $stmt = $db->prepare('INSERT INTO point_logs (user_id, amount, type, note) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $redemption['user_id'],
            -$redemption['points_spent'],
            'redeem',
            '兑换奖励'
        ]);

        // 减少库存
        $stmt = $db->prepare('UPDATE rewards SET stock = stock - 1 WHERE id = ? AND stock > 0');
        $stmt->execute([$redemption['reward_id']]);

        // 更新兑换状态
        $stmt = $db->prepare('UPDATE redemptions SET status = ?, approved_by = ?, completed_at = NOW() WHERE id = ?');
        $stmt->execute([$redemptionId, $user['id'], 'approved']);

        jsonResponse([
            'message' => '兑换已审批通过',
            'points_deducted' => $redemption['points_spent']
        ]);
    } else {
        // 拒绝
        $stmt = $db->prepare('UPDATE redemptions SET status = ?, approved_by = ? WHERE id = ?');
        $stmt->execute(['rejected', $user['id'], $redemptionId]);

        jsonResponse(['message' => '兑换已拒绝']);
    }
}

/**
 * 获取用户积分
 */
function getUserPoints($db, $userId) {
    $stmt = $db->prepare('SELECT points FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch()['points'] ?? 0;
}
