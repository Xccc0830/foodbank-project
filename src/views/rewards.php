<?php
/**
 * 公益點數兌換
 */

require_once BASE_PATH . '/src/models/RewardModel.php';

$rewardModel = new RewardModel();
$message = null;
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'redeem_reward') {
        $message = $rewardModel->redeem((int) $currentUser['user_id'], (int) $_POST['reward_id'])
            ? ['type' => 'success', 'text' => '兌換成功，請至食物銀行或合作店家出示兌換紀錄。']
            : ['type' => 'error', 'text' => '兌換失敗，可能點數不足或庫存已用完。'];
    } elseif ($action === 'create_reward' && $isAdmin) {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'cost_points' => (int) ($_POST['cost_points'] ?? 0),
            'stock' => $_POST['stock'] !== '' ? (int) $_POST['stock'] : null,
            'status' => 'active',
        ];
        $message = ($data['title'] !== '' && $data['cost_points'] > 0 && $rewardModel->createReward($data))
            ? ['type' => 'success', 'text' => '兌換品項已新增。']
            : ['type' => 'error', 'text' => '請填寫獎勵名稱與有效點數門檻。'];
    }
}

$balance = $rewardModel->getBalance((int) $currentUser['user_id']);
$catalog = $rewardModel->getActiveCatalog();
$myRedemptions = $rewardModel->getRedemptionsByUser((int) $currentUser['user_id']);
?>

<div class="view-header">
    <div>
        <h1 class="view-title">公益點數兌換</h1>
        <p class="view-subtitle">點數僅作為公益貢獻紀錄，不具現金兌換功能</p>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><h3>目前可用點數</h3><div class="stat-number"><?php echo number_format($balance); ?></div><p class="stat-label">公益點數</p></div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>兌換目錄</h2><p>選擇非現金獎勵進行兌換</p></div>
    <div class="card-body">
        <?php if ($catalog): ?>
            <div class="reward-grid">
                <?php foreach ($catalog as $reward): ?>
                    <div class="reward-card">
                        <h3><?php echo htmlspecialchars($reward['title']); ?></h3>
                        <p><?php echo htmlspecialchars($reward['description'] ?? ''); ?></p>
                        <span class="reward-cost"><?php echo (int) $reward['cost_points']; ?> 點</span>
                        <?php if ($reward['stock'] !== null): ?><p class="toolbar-meta">剩餘 <?php echo (int) $reward['stock']; ?> 份</p><?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="action" value="redeem_reward">
                            <input type="hidden" name="reward_id" value="<?php echo (int) $reward['reward_id']; ?>">
                            <button class="btn btn-primary btn-sm" type="submit" <?php echo ($balance < (int) $reward['cost_points'] || ($reward['stock'] !== null && (int) $reward['stock'] <= 0)) ? 'disabled' : ''; ?>>立即兌換</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-gift"></i><p>目前沒有可兌換的獎勵</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>我的兌換紀錄</h2></div>
    <div class="card-body">
        <?php if ($myRedemptions): ?>
            <table class="data-table"><thead><tr><th>獎勵</th><th>花費點數</th><th>狀態</th><th>時間</th></tr></thead><tbody>
            <?php foreach ($myRedemptions as $redemption): ?>
                <tr><td><?php echo htmlspecialchars($redemption['title']); ?></td><td><?php echo (int) $redemption['points_spent']; ?></td><td><?php echo ['pending' => '處理中', 'fulfilled' => '已兌付', 'cancelled' => '已取消'][$redemption['status']] ?? $redemption['status']; ?></td><td><?php echo htmlspecialchars($redemption['created_at']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>尚未兌換過獎勵</p></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="card mt-32">
    <div class="card-header"><h2>新增兌換品項（管理者）</h2></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="create_reward">
            <div class="grid-2">
                <div class="form-group"><label>獎勵名稱*</label><input name="title" required></div>
                <div class="form-group"><label>所需點數*</label><input type="number" name="cost_points" min="1" required></div>
            </div>
            <div class="form-group"><label>說明</label><textarea name="description"></textarea></div>
            <div class="form-group"><label>庫存（留空為不限量）</label><input type="number" name="stock" min="0"></div>
            <button class="btn btn-primary btn-sm" type="submit">新增品項</button>
        </form>
    </div>
</div>
<?php endif; ?>
