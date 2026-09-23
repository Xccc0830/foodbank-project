<?php
/**
 * 公益點數兌換
 */

require_once BASE_PATH . '/src/models/RewardModel.php';

$rewardModel = new RewardModel();
$message = null;
$isAdmin = ($currentUser['role'] ?? '') === 'admin';
$isDonor = ($currentUser['role'] ?? '') === 'donor';

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
    } elseif ($action === 'create_donor_reward' && $isDonor) {
        $message = $rewardModel->createDonorReward((int) $currentUser['user_id'], [
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'cost_points' => $_POST['cost_points'] ?? 0,
            'stock' => $_POST['stock'] !== '' ? $_POST['stock'] : null,
            'category' => $_POST['category'] ?? 'other',
        ])
            ? ['type' => 'success', 'text' => '獎勵方案已新增。']
            : ['type' => 'error', 'text' => '新增失敗，請確認資料格式後再試。'];
    } elseif ($action === 'update_donor_redemption' && $isDonor) {
        $status = $_POST['status'] ?? '';
        $message = $rewardModel->updateDonorRedemptionStatus(
            (int) $currentUser['user_id'],
            (int) ($_POST['redemption_id'] ?? 0),
            $status
        )
            ? ['type' => 'success', 'text' => '兌換紀錄狀態已更新。']
            : ['type' => 'error', 'text' => '狀態更新失敗，可能紀錄已處理或不存在。'];
    }
}

$balance = $rewardModel->getBalance((int) $currentUser['user_id']);
$catalog = $rewardModel->getActiveCatalog();
$myRedemptions = $rewardModel->getRedemptionsByUser((int) $currentUser['user_id']);

$donorRewards = [];
$donorRedemptions = [];
if ($isDonor) {
    $donorId = (int) $currentUser['user_id'];
    $donorRewards = $rewardModel->getDonorRewards($donorId);
    $donorRedemptions = $rewardModel->getDonorRedemptions($donorId);
}
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

<?php if ($isDonor): ?>
<div class="card mt-32">
    <div class="card-header"><h2>獎勵兌換紀錄</h2><p>查看志工兌換你提供的獎勵方案</p></div>
    <div class="card-body">
        <?php if ($donorRedemptions): ?>
            <table class="data-table"><thead><tr><th>志工</th><th>獎勵方案</th><th>兌換點數</th><th>日期</th><th>狀態</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($donorRedemptions as $redemption): ?>
                <tr>
                    <td><?php echo htmlspecialchars($redemption['volunteer_name']); ?></td>
                    <td><?php echo htmlspecialchars($redemption['title']); ?></td>
                    <td><?php echo (int) $redemption['points_spent']; ?> 點</td>
                    <td><?php echo htmlspecialchars($redemption['created_at']); ?></td>
                    <td><?php echo ['pending' => '待處理', 'fulfilled' => '已完成', 'cancelled' => '已取消'][$redemption['status']] ?? $redemption['status']; ?></td>
                    <td>
                        <?php if ($redemption['status'] === 'pending'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="update_donor_redemption">
                                <input type="hidden" name="redemption_id" value="<?php echo (int) $redemption['redemption_id']; ?>">
                                <button class="btn btn-primary btn-sm" type="submit" name="status" value="fulfilled">標記完成</button>
                                <button class="btn btn-secondary btn-sm" type="submit" name="status" value="cancelled">取消</button>
                            </form>
                        <?php else: ?>
                            <span class="toolbar-meta">無可用操作</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>目前沒有兌換紀錄</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>我的獎勵方案</h2><p>設定志工可在貴店家兌換的獎勵與優惠</p></div>
    <div class="card-body">
        <?php if ($donorRewards): ?>
            <table class="data-table"><thead><tr><th>方案名稱</th><th>所需點數</th><th>分類</th><th>庫存</th><th>狀態</th></tr></thead><tbody>
            <?php foreach ($donorRewards as $reward): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($reward['title']); ?></strong><br><small><?php echo htmlspecialchars($reward['description'] ?? ''); ?></small></td>
                    <td><?php echo (int) $reward['cost_points']; ?> 點</td>
                    <td><?php echo ['discount' => '折扣', 'product' => '商品', 'experience' => '體驗', 'other' => '其他'][$reward['category']] ?? $reward['category']; ?></td>
                    <td><?php echo $reward['stock'] !== null ? (int) $reward['stock'] . ' 份' : '不限量'; ?></td>
                    <td><span class="status status-<?php echo $reward['status']; ?>"><?php echo $reward['status'] === 'active' ? '啟用' : '停用'; ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-gifts"></i><p>尚未設定任何獎勵方案</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>新增獎勵方案</h2><p>讓志工可以兌換你提供的優惠或商品</p></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="create_donor_reward">
            <div class="grid-2">
                <div class="form-group"><label>方案名稱*</label><input name="title" placeholder="例：消費 9 折優惠券" required></div>
                <div class="form-group"><label>所需點數*</label><input type="number" name="cost_points" min="1" placeholder="例：30" required></div>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>分類*</label>
                    <select name="category" required>
                        <option value="discount">折扣優惠</option>
                        <option value="product">實體商品</option>
                        <option value="experience">體驗活動</option>
                        <option value="other">其他</option>
                    </select>
                </div>
                <div class="form-group"><label>庫存（留空為不限量）</label><input type="number" name="stock" min="0" placeholder="例：50"></div>
            </div>
            <div class="form-group"><label>說明</label><textarea name="description" placeholder="詳細描述此獎勵方案的內容和兌換方式"></textarea></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> 新增方案</button>
        </form>
    </div>
</div>
<?php endif; ?>

