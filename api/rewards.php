<?php
/**
 * 奖励管理 API
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $tokenData = verifyToken();
    $user = $tokenData['user'];
    $db = getDB();

    if ($method === 'GET') {
        $type = $_GET['type'] ?? '';

        if ($type === 'pending') {
            getPendingRedemptions($db, $user);
        } else {
            getRewards($db, $user);
        }
    } else {
        jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

/**
 * 获取奖励列表
 */
function getRewards($db, $user) {
    // 获取奖励
    $stmt = $db->prepare('
        SELECT * FROM rewards
        WHERE family_id = ? AND is_active = 1
        ORDER BY points_cost ASC
    ');
    $stmt->execute([$user['family_id']]);
    $rewards = $stmt->fetchAll();

    // 获取用户的兑换记录
    $stmt = $db->prepare('
        SELECT r.*, rw.title as reward_title
        FROM redemptions r
        JOIN rewards rw ON r.reward_id = rw.id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$user['id']]);
    $myRedemptions = $stmt->fetchAll();

    // 获取待审批的兑换（家长）
    $pendingCount = 0;
    if ($user['role'] === 'parent') {
        $stmt = $db->prepare('
            SELECT COUNT(*) as cnt FROM redemptions r
            JOIN users u ON r.user_id = u.id
            WHERE u.family_id = ? AND r.status = 'pending'
        ');
        $stmt->execute([$user['family_id']]);
        $pendingCount = $stmt->fetch()['cnt'];
    }

    jsonResponse([
        'rewards' => $rewards,
        'my_redemptions' => $myRedemptions,
        'pending_approvals' => $pendingCount
    ]);
}

/**
 * 获取待审批的兑换列表（家长）
 */
function getPendingRedemptions($db, $user) {
    if ($user['role'] !== 'parent') {
        jsonResponse(['error' => '只有家长可以查看'], 403);
    }

    $stmt = $db->prepare('
        SELECT r.*, u.username, rw.title as reward_title, rw.points_cost
        FROM redemptions r
        JOIN users u ON r.user_id = u.id
        JOIN rewards rw ON r.reward_id = rw.id
        WHERE u.family_id = ? AND r.status = 'pending'
        ORDER BY r.created_at DESC
    ');
    $stmt->execute([$user['family_id']]);
    $pending = $stmt->fetchAll();

    jsonResponse(['pending' => $pending]);
}
