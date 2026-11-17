<?php
/**
 * 心意幣兌換
 */

require_once BASE_PATH . '/src/models/RewardModel.php';

$rewardModel = new RewardModel();
$message = null;
$currentRole = $currentUser['role'] ?? '';
$memberType = $currentUser['member_type'] ?? null;
$canCreateCatalogReward = ($currentUser['role'] ?? '') === 'foodbank_staff';
$isDonor = $currentRole === 'member' && $memberType === 'enterprise';
$isVolunteer = $currentRole === 'member' && $memberType === 'general';
$canVerify = $currentRole === 'foodbank_staff' || $isDonor;
$message = $_SESSION['rewards_flash_message'] ?? null;
unset($_SESSION['rewards_flash_message']);

function rewardRuleText($reward) {
    $points = (int) ($reward['points_spent'] ?? $reward['cost_points'] ?? 0);
    $rule = $reward['rule_type'] ?? 'direct_exchange';
    if ($rule === 'min_spend_discount') {
        return '【滿額折抵】滿 ' . (int) $reward['min_spend'] . ' 元折 ' . (int) $reward['discount_amount'] . ' 元（需 ' . $points . ' 點）';
    }
    if ($rule === 'min_spend_percentage') {
        return '【滿額打折】滿 ' . (int) $reward['min_spend'] . ' 元享 ' . rtrim(rtrim(number_format((float) $reward['discount_rate'] * 10, 1), '0'), '.') . ' 折（需 ' . $points . ' 點）';
    }
    return '【商品直換】' . $points . ' 點，無需現金';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'redeem_reward') {
        if (!$isVolunteer) {
            $message = ['type' => 'error', 'text' => '只有 GO Rider 可以使用心意幣兌換。'];
        } else {
            $claim = $rewardModel->redeem(
                (int) $currentUser['user_id'],
                $_POST['source_type'] ?? 'foodbank',
                (int) ($_POST['source_id'] ?? 0)
            );
            if ($claim) {
                $_SESSION['reward_claim_token'] = $claim['token'];
                $_SESSION['reward_claim_id'] = $claim['claim_id'];
                $message = ['type' => 'success', 'text' => '兌換成功，請在 10 分鐘內出示 QR Code。'];
            } else {
                $message = ['type' => 'error', 'text' => '兌換失敗，可能心意幣不足或庫存已用完。'];
            }
        }
    } elseif ($action === 'refresh_claim' && $isVolunteer) {
        $claim = $rewardModel->refreshClaimToken((int) $_POST['claim_id'], (int) $currentUser['user_id']);
        if ($claim) {
            $message = ['type' => 'success', 'text' => '新的 QR Code 已開啟，有效時間為 10 分鐘。'];
        } else {
            $message = ['type' => 'error', 'text' => '此兌換紀錄無法重新開啟。'];
        }
    } elseif ($action === 'fulfill_claim' && $canVerify) {
        $fulfillment = $rewardModel->fulfillByToken($_POST['claim_token'] ?? '', (int) $currentUser['user_id'], $currentRole, $_POST['bill_amount'] ?? null);
        $message = [
            'type' => $fulfillment['success'] ? 'success' : 'error',
            'text' => $fulfillment['message'],
        ];
    } elseif ($action === 'create_reward' && $canCreateCatalogReward) {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'cost_points' => (int) ($_POST['cost_points'] ?? 0),
            'stock' => ($_POST['stock'] ?? '') !== '' ? (int) $_POST['stock'] : null,
            'status' => 'active',
        ];
        $message = ($data['title'] !== '' && $data['cost_points'] > 0 && ($data['stock'] === null || $data['stock'] >= 0) && $rewardModel->createReward($data))
            ? ['type' => 'success', 'text' => '兌換品項已新增。']
            : ['type' => 'error', 'text' => '請填寫獎勵名稱與有效心意幣門檻。'];
    } elseif ($action === 'update_reward' && $canCreateCatalogReward) {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'cost_points' => (int) ($_POST['cost_points'] ?? 0),
            'stock' => ($_POST['stock'] ?? '') !== '' ? (int) $_POST['stock'] : null,
        ];
        $message = ($data['title'] !== '' && $data['cost_points'] > 0 && ($data['stock'] === null || $data['stock'] >= 0) && $rewardModel->updateReward((int) ($_POST['reward_id'] ?? 0), $data))
            ? ['type' => 'success', 'text' => '兌換品項已更新。']
            : ['type' => 'error', 'text' => '請確認品項名稱、所需心意幣與庫存格式。'];
    } elseif ($action === 'delete_reward' && $canCreateCatalogReward) {
        $message = $rewardModel->deleteReward((int) ($_POST['reward_id'] ?? 0))
            ? ['type' => 'success', 'text' => '兌換品項已刪除。']
            : ['type' => 'error', 'text' => '品項刪除失敗，請稍後再試。'];
    } elseif ($action === 'update_donor_reward' && ($isDonor || $currentRole === 'foodbank_staff')) {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'category' => $_POST['category'] ?? 'other',
            'cost_points' => ($_POST['rule_type'] ?? '') === 'min_spend_discount' ? ($_POST['discount_amount'] ?? '') : ($_POST['cost_points'] ?? ''),
            'stock' => $_POST['stock'] ?? null,
            'rule_type' => $_POST['rule_type'] ?? 'direct_exchange',
            'min_spend' => $_POST['min_spend'] ?? 0,
            'discount_amount' => $_POST['discount_amount'] ?? null,
            'discount_rate' => $_POST['discount_rate'] ?? null,
        ];
        $message = $rewardModel->updateDonorReward((int) ($_POST['item_id'] ?? 0), (int) $currentUser['user_id'], $data, $currentRole === 'foodbank_staff')
            ? ['type' => 'success', 'text' => '獎勵方案已更新。']
            : ['type' => 'error', 'text' => '請確認方案名稱及優惠條件；折抵金額不得超過最低消費。'];
    } elseif ($action === 'delete_donor_reward' && ($isDonor || $currentRole === 'foodbank_staff')) {
        $message = $rewardModel->deleteDonorReward((int) ($_POST['item_id'] ?? 0), (int) $currentUser['user_id'], $currentRole === 'foodbank_staff')
            ? ['type' => 'success', 'text' => '獎勵方案已刪除。']
            : ['type' => 'error', 'text' => '獎勵方案刪除失敗，請稍後再試。'];
    } elseif ($action === 'create_donor_reward' && $isDonor) {
        $message = $rewardModel->createDonorReward((int) $currentUser['user_id'], [
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'cost_points' => ($_POST['rule_type'] ?? '') === 'min_spend_discount' ? ($_POST['discount_amount'] ?? '') : ($_POST['cost_points'] ?? ''),
            'stock' => $_POST['stock'] ?? null,
            'category' => $_POST['category'] ?? 'other',
            'rule_type' => $_POST['rule_type'] ?? 'direct_exchange',
            'min_spend' => $_POST['min_spend'] ?? 0,
            'discount_amount' => $_POST['discount_amount'] ?? null,
            'discount_rate' => $_POST['discount_rate'] ?? null,
        ])
            ? ['type' => 'success', 'text' => '獎勵方案已新增。']
            : ['type' => 'error', 'text' => '新增失敗，請確認方案名稱及優惠條件；折抵金額不得超過最低消費。'];
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

    $_SESSION['rewards_flash_message'] = $message;
    $redirectUrl = '?page=rewards';
    if (($_POST['editor_flow'] ?? '') === '1' && in_array($action, ['create_donor_reward', 'update_donor_reward'], true)) {
        unset($_SESSION['reward_editor_input']);
        if (($message['type'] ?? '') === 'error') {
            $fields = ['title', 'description', 'category', 'stock', 'cost_points', 'min_spend', 'discount_amount', 'discount_rate', 'rule_type'];
            $input = [];
            foreach ($fields as $field) {
                if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
                    $input[$field] = (string) $_POST[$field];
                }
            }
            $_SESSION['reward_editor_input'] = $input;
            $redirectUrl .= '&editor=' . ($action === 'create_donor_reward' ? 'create' : 'edit');
            if ($action === 'update_donor_reward') {
                $redirectUrl .= '&item_id=' . (int) ($_POST['item_id'] ?? 0);
            }
            if (in_array($input['rule_type'] ?? '', ['direct_exchange', 'min_spend_discount', 'min_spend_percentage'], true)) {
                $redirectUrl .= '&template=' . $input['rule_type'];
            }
        }
    }
    if ($action === 'refresh_claim' && !empty($_POST['claim_id'])) {
        $redirectUrl .= '&claim_id=' . (int) $_POST['claim_id'];
    }
    redirectTo($redirectUrl);
    exit;
}

$editor = $_GET['editor'] ?? '';
if (($editor === 'create' && $isDonor) || ($editor === 'edit' && ($isDonor || $canCreateCatalogReward))) {
    require BASE_PATH . '/src/views/reward_editor.php';
    return;
}
if (($_GET['stores'] ?? '') === '1') {
    require BASE_PATH . '/src/views/reward_stores.php';
    return;
}

$balance = $rewardModel->getBalance((int) $currentUser['user_id']);
$catalog = $rewardModel->getAvailableRewards();
$foodbankPendingClaims = $currentRole === 'foodbank_staff'
    ? $rewardModel->getPendingFoodbankClaims()
    : [];
$foodbankRedemptions = $currentRole === 'foodbank_staff'
    ? $rewardModel->getFoodbankRedemptions()
    : [];
$pendingClaims = $isVolunteer
    ? array_values(array_filter(
        $rewardModel->getPendingClaimsByUser((int) $currentUser['user_id']),
        static function ($claim) {
            return ($claim['status'] ?? '') === 'pending';
        }
    ))
    : [];
$selectedClaimId = $isVolunteer ? (int) ($_GET['claim_id'] ?? 0) : 0;
$selectedClaim = $selectedClaimId > 0
    ? $rewardModel->getPendingClaimById($selectedClaimId, (int) $currentUser['user_id'])
    : null;

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
        <h1 class="view-title">心意幣兌換</h1>
        <p class="view-subtitle">愛心店家優惠以 1 點折抵 1 元；心意幣不可兌換現金。</p>
    </div>
    <div>
        <a class="btn btn-secondary" href="?page=rewards&amp;stores=1">查看合作商家</a>
        <?php if ($isDonor): ?><a class="btn btn-primary" href="?page=rewards&amp;editor=create">設定店家優惠</a><?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="reward-verification-notice reward-verification-notice-<?php echo htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8'); ?>" role="alert">
        <span class="reward-verification-notice-icon"><?php echo $message['type'] === 'success' ? '✓' : '!'; ?></span>
        <div>
            <strong><?php echo $message['type'] === 'success' ? '操作成功' : '操作未完成'; ?></strong>
            <p><?php echo htmlspecialchars($message['text']); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><h3>目前可用心意幣</h3><div class="stat-number"><?php echo number_format($balance); ?></div><p class="stat-label">心意幣</p></div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>兌換目錄</h2><p>選擇獎勵或愛心店家優惠進行兌換</p></div>
    <div class="card-body">
        <?php if ($catalog): ?>
            <div class="reward-grid">
                <?php foreach ($catalog as $reward): ?>
                    <div class="reward-card" id="reward-<?php echo $reward['source_type'] === 'donor' ? 'donor' : 'foodbank'; ?>-<?php echo (int) $reward['source_id']; ?>">
                        <h3><?php echo htmlspecialchars($reward['title']); ?></h3>
                        <?php if ($reward['source_type'] === 'donor'): ?><p class="toolbar-meta">提供店家：<?php echo htmlspecialchars($reward['donor_name'] ?? '未知店家'); ?></p><?php endif; ?>
                        <p><?php echo htmlspecialchars($reward['description'] ?? ''); ?></p>
                        <span class="reward-cost"><?php echo (int) $reward['cost_points']; ?> 點</span>
                        <?php if ($reward['source_type'] === 'donor'): ?><p><strong class="status status-info"><?php echo htmlspecialchars(rewardRuleText($reward)); ?></strong></p><?php endif; ?>
                        <?php if ($reward['stock'] !== null): ?><p class="toolbar-meta">剩餘 <?php echo (int) $reward['stock']; ?> 份</p><?php endif; ?>
                        <?php if ($canCreateCatalogReward && $reward['source_type'] === 'foodbank'): ?>
                            <button class="btn btn-secondary btn-sm" type="button" data-action="edit-reward">編輯品項</button>
                            <form method="post" class="reward-management-form" hidden>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_reward">
                                <input type="hidden" name="reward_id" value="<?php echo (int) $reward['source_id']; ?>">
                                <div class="form-group"><label>品項名稱</label><input name="title" value="<?php echo htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                                <div class="form-group"><label>說明</label><textarea name="description"><?php echo htmlspecialchars($reward['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                <div class="grid-2">
                                    <div class="form-group"><label>所需心意幣</label><input type="number" name="cost_points" min="1" value="<?php echo (int) $reward['cost_points']; ?>" required></div>
                                    <div class="form-group"><label>庫存</label><input type="number" name="stock" min="0" value="<?php echo $reward['stock'] !== null ? (int) $reward['stock'] : ''; ?>" placeholder="不限量"></div>
                                </div>
                                <button class="btn btn-primary btn-sm" type="submit">儲存修改</button>
                                <button class="btn btn-secondary btn-sm" type="button" data-action="cancel-edit-reward">取消</button>
                            </form>
                            <form method="post" class="reward-delete-form" onsubmit="return confirm('確定要刪除此兌換品項嗎？');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_reward">
                                <input type="hidden" name="reward_id" value="<?php echo (int) $reward['source_id']; ?>">
                                <button class="btn btn-danger btn-sm" type="submit">刪除品項</button>
                            </form>
                        <?php elseif ($reward['source_type'] === 'donor' && ($currentRole === 'foodbank_staff' || ((int) ($reward['source_owner_id'] ?? 0) === (int) $currentUser['user_id']))): ?>
                            <a class="btn btn-secondary btn-sm" href="?page=rewards&amp;editor=edit&amp;item_id=<?php echo (int) $reward['source_id']; ?>">編輯方案</a>
                            <form method="post" class="reward-delete-form" onsubmit="return confirm('確定要刪除此愛心商家品項嗎？');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_donor_reward">
                                <input type="hidden" name="item_id" value="<?php echo (int) $reward['source_id']; ?>">
                                <button class="btn btn-danger btn-sm" type="submit">刪除品項</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($isVolunteer): ?>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="redeem_reward">
                                <input type="hidden" name="source_type" value="<?php echo htmlspecialchars($reward['source_type']); ?>">
                                <input type="hidden" name="source_id" value="<?php echo (int) $reward['source_id']; ?>">
                                <button class="btn btn-primary btn-sm" type="submit" <?php echo ($balance < (int) $reward['cost_points'] || ($reward['stock'] !== null && (int) $reward['stock'] <= 0)) ? 'disabled' : ''; ?>>立即兌換</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-gift"></i><p>目前沒有可兌換的獎勵</p></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isVolunteer && $pendingClaims): ?>
<div class="card mt-32">
    <div class="card-header"><h2>我的優惠券</h2><p>點擊兌換券後才會顯示 QR Code</p></div>
    <div class="card-body reward-claims-list">
        <?php foreach ($pendingClaims as $claim): ?>
            <div class="reward-claim-card">
                <h3><?php echo htmlspecialchars($claim['title']); ?></h3>
                <p class="toolbar-meta">兌換編號：<?php echo htmlspecialchars('R' . str_pad((string) $claim['claim_id'], 6, '0', STR_PAD_LEFT)); ?></p>
                  <p class="toolbar-meta"><?php echo (int) $claim['points_spent']; ?> 點・待核銷</p>
                <a class="btn btn-primary btn-sm" href="?page=rewards&amp;claim_id=<?php echo (int) $claim['claim_id']; ?>">查看優惠券</a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isVolunteer && $selectedClaim): ?>
<div class="card mt-32">
    <div class="card-header"><h2><?php echo htmlspecialchars($selectedClaim['title']); ?></h2><p>請於核銷時再出示 QR Code</p></div>
    <div class="card-body reward-claim-detail">
        <p class="reward-claim-number">兌換編號：<?php echo htmlspecialchars('R' . str_pad((string) $selectedClaim['claim_id'], 6, '0', STR_PAD_LEFT)); ?></p>
        <?php if ($selectedClaim['source_type'] === 'donor'): ?>
            <p>提供店家：<?php echo htmlspecialchars($selectedClaim['donor_name'] ?? '未知店家'); ?></p>
            <p><strong><?php echo htmlspecialchars(rewardRuleText($selectedClaim)); ?></strong></p>
            <?php if (!empty($selectedClaim['reward_description'])): ?><p><?php echo nl2br(htmlspecialchars($selectedClaim['reward_description'])); ?></p><?php endif; ?>
            <p>請於指定店家消費並出示此券；滿額優惠須達最低消費，點數已於領券時扣除。</p>
        <?php endif; ?>
        <a class="btn btn-secondary btn-sm" href="?page=rewards">返回我的優惠券</a>
        <div id="selected-reward-qr-code" aria-label="兌換 QR Code"></div>
        <p id="selected-reward-qr-countdown" class="toolbar-meta"></p>
        <p class="toolbar-meta">請向指定的食物銀行或愛心商家出示此 QR Code，核銷完成後即可領取。</p>
        <form method="post" action="?page=rewards&amp;claim_id=<?php echo (int) $selectedClaim['claim_id']; ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="refresh_claim">
            <input type="hidden" name="claim_id" value="<?php echo (int) $selectedClaim['claim_id']; ?>">
            <button class="btn btn-primary btn-sm" type="submit">重新開啟 QR Code</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canVerify): ?>
<div class="card mt-32">
    <div class="card-header">
        <h2>兌換核銷</h2>
        <p>
            <?php if ($isDonor): ?>
                僅能核銷由您提供的愛心店家優惠券
            <?php else: ?>
                食物銀行方僅能核銷食物銀行兌換品；愛心店家優惠券請由提供該優惠的店家核銷
            <?php endif; ?>
        </p>
    </div>
    <div class="card-body">
        <button class="btn btn-secondary btn-sm" type="button" id="start-reward-scanner">啟動相機掃描</button>
        <button class="btn btn-secondary btn-sm" type="button" id="stop-reward-scanner" hidden>關閉相機</button>
        <div id="reward-qr-reader" style="max-width: 420px;"></div>
        <p id="reward-scanner-status" class="toolbar-meta">可掃描 QR Code，或輸入兌換編號（例如 R000001）核銷。</p>
        <form method="post" class="mt-20" id="reward-fulfill-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="fulfill_claim">
            <div class="form-group"><label for="claim_token">無法掃描時輸入兌換碼</label><input id="claim_token" name="claim_token" required></div>
            <button class="btn btn-secondary btn-sm" type="button" id="reward-preview">查詢優惠券</button>
            <p id="reward-preview-status" role="status"></p>
            <div id="reward-calculator" hidden>
                <p id="reward-rule-summary"></p>
                <div class="form-group" id="reward-bill-group" hidden><label for="reward-bill">消費總金額（元）</label><input id="reward-bill" type="number" name="bill_amount" min="0" max="99999999.99" step="0.01"></div>
                <p id="reward-calculation"></p>
            </div>
            <button class="btn btn-primary btn-sm" type="submit" id="reward-confirm" disabled>確認核銷</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($currentRole === 'foodbank_staff'): ?>
<div class="card mt-32">
    <div class="card-header"><h2>食物銀行待核銷兌換</h2><p>GO Rider 完成兌換後會立即出現在這裡，請確認兌換編號或 QR Code 後核銷。</p></div>
    <div class="card-body">
        <?php if ($foodbankPendingClaims): ?>
            <div class="redemptions-table-body">
                <table class="data-table redemptions-table redemptions-pending-table">
                    <thead><tr><th>兌換編號</th><th>兌換品項</th><th>GO Rider</th><th>心意幣</th><th>兌換時間</th></tr></thead>
                    <tbody>
                    <?php foreach ($foodbankPendingClaims as $claim): ?>
                        <tr>
                            <td><?php echo htmlspecialchars('R' . str_pad((string) $claim['claim_id'], 6, '0', STR_PAD_LEFT)); ?></td>
                            <td><?php echo htmlspecialchars($claim['title']); ?></td>
                            <td><?php echo htmlspecialchars($claim['volunteer_name'] ?? '未知使用者'); ?></td>
                            <td><?php echo (int) $claim['points_spent']; ?> 點</td>
                            <td><?php echo htmlspecialchars($claim['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>目前沒有待核銷兌換</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>食物銀行兌換紀錄</h2><p>查看所有食物銀行兌換品的申請與核銷狀態。</p></div>
    <div class="card-body">
        <?php if ($foodbankRedemptions): ?>
            <div class="redemptions-table-body">
                <table class="data-table redemptions-table redemptions-history-table">
                    <thead><tr><th>兌換編號</th><th>兌換品項</th><th>GO Rider</th><th>心意幣</th><th>狀態</th><th>時間</th></tr></thead>
                    <tbody>
                    <?php foreach ($foodbankRedemptions as $redemption): ?>
                        <tr>
                            <td><?php echo htmlspecialchars('R' . str_pad((string) $redemption['claim_id'], 6, '0', STR_PAD_LEFT)); ?></td>
                            <td><?php echo htmlspecialchars($redemption['title']); ?></td>
                            <td><?php echo htmlspecialchars($redemption['volunteer_name'] ?? '未知使用者'); ?></td>
                            <td><?php echo (int) $redemption['points_spent']; ?> 點</td>
                            <td><span class="status status-<?php echo $redemption['status'] === 'fulfilled' ? 'completed' : ($redemption['status'] === 'pending' ? 'pending' : ($redemption['status'] === 'cancelled' ? 'cancelled' : 'info')); ?>"><?php echo ['pending' => '待核銷', 'fulfilled' => '已核銷', 'cancelled' => '已取消'][$redemption['status']] ?? $redemption['status']; ?></span></td>
                            <td><?php echo htmlspecialchars($redemption['redeemed_at'] ?: $redemption['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>尚未有食物銀行兌換紀錄</p></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

        <?php if ($selectedClaim): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            new QRCode(document.getElementById('selected-reward-qr-code'), {
                text: <?php echo json_encode($selectedClaim['token_value']); ?>, width: 220, height: 220
            });
            var remainingSeconds = <?php echo max(0, strtotime($selectedClaim['token_expires_at']) - time()); ?>;
            var countdown = document.getElementById('selected-reward-qr-countdown');
            countdown.textContent = '剩餘 ' + Math.ceil(remainingSeconds / 60) + ' 分鐘';
            window.setInterval(function () {
                remainingSeconds = Math.max(0, remainingSeconds - 1);
                countdown.textContent = remainingSeconds > 0
                    ? '剩餘 ' + Math.ceil(remainingSeconds / 60) + ' 分鐘'
                    : 'QR Code 已失效，請重新開啟。';
                if (remainingSeconds === 0) {
                    document.getElementById('selected-reward-qr-code').replaceChildren();
                }
            }, 1000);
        });
        </script>
        <?php endif; ?>
        <?php if ($canVerify): ?>
        <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.Html5Qrcode) return;
            var scanner = new Html5Qrcode('reward-qr-reader');
            var startButton = document.getElementById('start-reward-scanner');
            var stopButton = document.getElementById('stop-reward-scanner');
            var status = document.getElementById('reward-scanner-status');
            var isScanning = false;
            var stopScanner = function (statusText) {
                if (!isScanning) {
                    startButton.hidden = false;
                    stopButton.hidden = true;
                    status.textContent = statusText;
                    return;
                }
                scanner.stop().then(function () {
                    isScanning = false;
                    stopButton.hidden = true;
                    startButton.hidden = false;
                    status.textContent = statusText;
                }).catch(function () {
                    status.textContent = '相機關閉失敗，請重新整理頁面後再試。';
                });
            };
            startButton.addEventListener('click', function () {
                if (isScanning) return;
                isScanning = true;
                status.textContent = '正在啟動相機，請允許瀏覽器使用相機。';
                scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: 220 },
                    function (decodedText) {
                        document.getElementById('claim_token').value = decodedText;
                        document.getElementById('claim_token').dispatchEvent(new Event('input'));
                        document.getElementById('reward-preview').click();
                        stopScanner('已讀取 QR Code，相機已關閉。');
                    }
                ).then(function () {
                    startButton.hidden = true;
                    stopButton.hidden = false;
                }).catch(function () {
                    isScanning = false;
                    startButton.hidden = false;
                    stopButton.hidden = true;
                    status.textContent = '相機無法啟動，請改用兌換碼輸入。';
                });
            });
            stopButton.addEventListener('click', function () {
                stopScanner('相機已關閉。');
            });
        });
        </script>
        <?php endif; ?>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('reward-fulfill-form');
            if (!form) return;
            var token = document.getElementById('claim_token');
            var status = document.getElementById('reward-preview-status');
            var calculator = document.getElementById('reward-calculator');
            var summary = document.getElementById('reward-rule-summary');
            var billGroup = document.getElementById('reward-bill-group');
            var bill = document.getElementById('reward-bill');
            var calculation = document.getElementById('reward-calculation');
            var confirm = document.getElementById('reward-confirm');
            var claim = null;
            var requestId = 0;
            function reset() {
                requestId++;
                claim = null;
                calculator.hidden = true;
                confirm.disabled = true;
                bill.required = false;
                bill.value = '';
                status.textContent = '';
            }
            function calculate() {
                if (!claim) return;
                var discount = claim.source_type === 'donor' && claim.rule_type && claim.rule_type !== 'direct_exchange';
                billGroup.hidden = !discount;
                bill.required = discount;
                var ruleText = !discount ? '商品直換' :
                    claim.rule_type === 'min_spend_discount'
                        ? '滿 ' + claim.min_spend + ' 元折 ' + claim.discount_amount + ' 元'
                        : '滿 ' + claim.min_spend + ' 元享 ' + (Number(claim.discount_rate) * 10).toFixed(1).replace(/\.0$/, '') + ' 折';
                summary.textContent = claim.title + '｜' + ruleText + '（扣除 ' + claim.points_spent + ' 點，已於領券時扣除）';
                if (!discount) {
                    calculation.textContent = '商品直換，無需現金。';
                    confirm.disabled = false;
                    return;
                }
                var valid = /^\d{1,8}(?:\.\d{1,2})?$/.test(bill.value) && bill.checkValidity();
                var total = Number(bill.value);
                var eligible = valid && total >= Number(claim.min_spend);
                confirm.disabled = !eligible;
                if (!eligible) {
                    calculation.textContent = '未達門檻：最低消費 ' + claim.min_spend + ' 元。';
                    return;
                }
                var cash = claim.rule_type === 'min_spend_discount'
                    ? Math.max(0, total - Number(claim.discount_amount))
                    : Math.floor((Math.round(total * 100) * Math.round(Number(claim.discount_rate) * 100) + 5000) / 10000);
                calculation.textContent = '已達門檻（滿 ' + claim.min_spend + ' 元）｜本次折抵：' +
                    (total - cash).toFixed(2) + ' 元｜應收現金：$' + cash.toFixed(2);
            }
            token.addEventListener('input', reset);
            bill.addEventListener('input', calculate);
            document.getElementById('reward-preview').addEventListener('click', function () {
                reset();
                if (!token.value.trim()) {
                    status.textContent = '請先輸入兌換碼。';
                    return;
                }
                var current = requestId;
                var data = new FormData();
                data.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
                data.append('claim_token', token.value);
                status.textContent = '正在查詢優惠券…';
                fetch('?action=reward_claim_preview', { method: 'POST', body: data })
                    .then(function (response) {
                        if (!response.ok) throw new Error('查詢失敗，請重新登入或稍後再試。');
                        return response.json();
                    })
                    .then(function (result) {
                        if (current !== requestId) return;
                        if (!result.success) {
                            status.textContent = result.message;
                            return;
                        }
                        claim = result.claim;
                        status.textContent = '已取得優惠券，請確認後核銷。';
                        calculator.hidden = false;
                        calculate();
                    })
                    .catch(function () {
                        if (current === requestId) status.textContent = '查詢失敗，請重新登入或稍後再試。';
                    });
            });
        });
        </script>

<?php if ($canCreateCatalogReward): ?>
<div class="card mt-32">
    <div class="card-header"><h2>新增食物銀行兌換品項</h2><p>設定心意幣可以兌換的物資或禮品</p></div>
    <div class="card-body">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_reward">
            <div class="grid-2">
                <div class="form-group"><label>獎勵名稱*</label><input name="title" required></div>
                <div class="form-group"><label>所需心意幣*</label><input type="number" name="cost_points" min="1" required></div>
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
    <div class="card-header"><h2>獎勵兌換紀錄</h2><p>查看 GO Rider 兌換你提供的獎勵方案</p></div>
    <div class="card-body">
        <?php if ($donorRedemptions): ?>
            <table class="data-table donor-redemptions-table"><thead><tr><th>GO Rider</th><th>獎勵方案</th><th>兌換心意幣</th><th>日期</th><th>狀態</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($donorRedemptions as $redemption): ?>
                <tr>
                    <td><?php echo htmlspecialchars($redemption['volunteer_name']); ?></td>
                    <td><?php echo htmlspecialchars($redemption['title']); ?></td>
                    <td><?php echo (int) $redemption['points_spent']; ?> 點</td>
                    <td><?php echo htmlspecialchars($redemption['created_at']); ?></td>
                    <td><span class="status status-<?php echo $redemption['status'] === 'fulfilled' ? 'completed' : ($redemption['status'] === 'pending' ? 'pending' : ($redemption['status'] === 'cancelled' ? 'cancelled' : 'info')); ?>"><?php echo ['pending' => '待核銷', 'fulfilled' => '已核銷', 'cancelled' => '已取消'][$redemption['status']] ?? $redemption['status']; ?></span></td>
                    <td>
                        <?php if ($redemption['status'] === 'pending' && ($redemption['rule_type'] ?? 'direct_exchange') === 'direct_exchange'): ?>
                            <form method="post" class="inline-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_donor_redemption">
                                <input type="hidden" name="redemption_id" value="<?php echo (int) $redemption['redemption_id']; ?>">
                                <button class="btn btn-primary btn-sm" type="submit" name="status" value="fulfilled">標記完成</button>
                            </form>
                        <?php else: ?>
                            <span class="toolbar-meta"><?php echo $redemption['status'] === 'pending' ? '請使用上方核銷計算機' : '無可用操作'; ?></span>
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
    <div class="card-header"><h2>我的獎勵方案</h2><p>設定 GO Rider 可在貴店家兌換的獎勵與優惠</p>
        <a class="btn btn-primary btn-sm" href="?page=rewards&amp;editor=create">選擇優惠模板並新增方案</a>
    </div>
    <div class="card-body">
        <?php if ($donorRewards): ?>
            <div class="rewards-table-wrapper">
            <table class="data-table donor-rewards-table"><thead><tr><th>方案名稱</th><th>所需心意幣</th><th>分類</th><th>庫存</th><th>狀態</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($donorRewards as $reward): ?>
                <tr>
                    <td><strong class="donor-reward-title"><?php echo htmlspecialchars($reward['title']); ?></strong><br><small><?php echo htmlspecialchars($reward['description'] ?? ''); ?></small></td>
                    <td><?php echo (int) $reward['cost_points']; ?> 點</td>
                    <td><?php echo ['discount' => '折扣', 'product' => '商品', 'experience' => '體驗', 'other' => '其他'][$reward['category']] ?? $reward['category']; ?></td>
                    <td><?php echo $reward['stock'] !== null ? (int) $reward['stock'] . ' 份' : '不限量'; ?></td>
                    <td><span class="status status-<?php echo $reward['status']; ?>"><?php echo $reward['status'] === 'active' ? '啟用' : '停用'; ?></span></td>
                    <td>
                        <?php if ($reward['status'] === 'active'): ?>
                            <a class="btn btn-secondary btn-sm" href="?page=rewards&amp;editor=edit&amp;item_id=<?php echo (int) $reward['item_id']; ?>">編輯方案</a>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('確定要刪除此獎勵方案嗎？');" style="display: inline-block;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete_donor_reward">
                            <input type="hidden" name="item_id" value="<?php echo (int) $reward['item_id']; ?>">
                            <button class="btn btn-danger btn-sm" type="submit">刪除方案</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-gifts"></i><p>尚未設定任何獎勵方案</p></div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>
