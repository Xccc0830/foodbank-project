<?php
/**
 * 公益點數兌換
 */

require_once BASE_PATH . '/src/models/RewardModel.php';

$rewardModel = new RewardModel();
$message = null;
$currentRole = $currentUser['role'] ?? '';
$canCreateCatalogReward = in_array($currentUser['role'] ?? '', ['admin', 'foodbank_staff'], true);
$isDonor = ($currentUser['role'] ?? '') === 'donor';
$isVolunteer = $currentRole === 'volunteer';
$canVerify = in_array($currentRole, ['admin', 'foodbank_staff', 'donor'], true);
$message = $_SESSION['rewards_flash_message'] ?? null;
unset($_SESSION['rewards_flash_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'redeem_reward') {
        if (!$isVolunteer) {
            $message = ['type' => 'error', 'text' => '只有志工可以使用公益點數兌換。'];
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
                $message = ['type' => 'error', 'text' => '兌換失敗，可能點數不足或庫存已用完。'];
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
        $fulfillment = $rewardModel->fulfillByToken($_POST['claim_token'] ?? '', (int) $currentUser['user_id'], $currentRole);
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
            : ['type' => 'error', 'text' => '請填寫獎勵名稱與有效點數門檻。'];
    } elseif ($action === 'update_reward' && $canCreateCatalogReward) {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'cost_points' => (int) ($_POST['cost_points'] ?? 0),
            'stock' => ($_POST['stock'] ?? '') !== '' ? (int) $_POST['stock'] : null,
        ];
        $message = ($data['title'] !== '' && $data['cost_points'] > 0 && ($data['stock'] === null || $data['stock'] >= 0) && $rewardModel->updateReward((int) ($_POST['reward_id'] ?? 0), $data))
            ? ['type' => 'success', 'text' => '兌換品項已更新。']
            : ['type' => 'error', 'text' => '請確認品項名稱、所需點數與庫存格式。'];
    } elseif ($action === 'delete_reward' && $canCreateCatalogReward) {
        $message = $rewardModel->deleteReward((int) ($_POST['reward_id'] ?? 0))
            ? ['type' => 'success', 'text' => '兌換品項已刪除。']
            : ['type' => 'error', 'text' => '品項刪除失敗，請稍後再試。'];
    } elseif ($action === 'update_donor_reward' && ($isDonor || $currentRole === 'admin')) {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'category' => $_POST['category'] ?? 'other',
            'cost_points' => (int) ($_POST['cost_points'] ?? 0),
            'stock' => ($_POST['stock'] ?? '') !== '' ? (int) $_POST['stock'] : null,
        ];
        $validCategories = ['discount', 'product', 'experience', 'other'];
        $message = ($data['title'] !== '' && $data['cost_points'] > 0 && ($data['stock'] === null || $data['stock'] >= 0) && in_array($data['category'], $validCategories, true) && $rewardModel->updateDonorReward((int) ($_POST['item_id'] ?? 0), (int) $currentUser['user_id'], $data, $currentRole === 'admin'))
            ? ['type' => 'success', 'text' => '獎勵方案已更新。']
            : ['type' => 'error', 'text' => '請確認方案名稱、分類、所需點數與庫存格式。'];
    } elseif ($action === 'delete_donor_reward' && ($isDonor || $currentRole === 'admin')) {
        $message = $rewardModel->deleteDonorReward((int) ($_POST['item_id'] ?? 0), (int) $currentUser['user_id'], $currentRole === 'admin')
            ? ['type' => 'success', 'text' => '獎勵方案已刪除。']
            : ['type' => 'error', 'text' => '獎勵方案刪除失敗，請稍後再試。'];
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

    $_SESSION['rewards_flash_message'] = $message;
    $redirectUrl = '?page=rewards';
    if ($action === 'refresh_claim' && !empty($_POST['claim_id'])) {
        $redirectUrl .= '&claim_id=' . (int) $_POST['claim_id'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$balance = $rewardModel->getBalance((int) $currentUser['user_id']);
$catalog = $rewardModel->getAvailableRewards();
$myRedemptions = $rewardModel->getRedemptionsByUser((int) $currentUser['user_id']);
$myFulfillments = $canVerify
    ? $rewardModel->getFulfillmentsByUser((int) $currentUser['user_id'])
    : [];
$foodbankPendingClaims = in_array($currentRole, ['admin', 'foodbank_staff'], true)
    ? $rewardModel->getPendingFoodbankClaims()
    : [];
$foodbankRedemptions = in_array($currentRole, ['admin', 'foodbank_staff'], true)
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
        <h1 class="view-title">公益點數兌換</h1>
        <p class="view-subtitle">點數僅作為公益貢獻紀錄，不具現金兌換功能</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="reward-verification-notice reward-verification-notice-<?php echo htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8'); ?>" role="alert">
        <span class="reward-verification-notice-icon"><?php echo $message['type'] === 'success' ? '✓' : '!'; ?></span>
        <div>
            <strong><?php echo $message['type'] === 'success' ? '核銷成功' : '核銷未完成'; ?></strong>
            <p><?php echo htmlspecialchars($message['text']); ?></p>
        </div>
    </div>
<?php endif; ?>

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
                        <?php if ($canCreateCatalogReward && $reward['source_type'] === 'foodbank'): ?>
                            <button class="btn btn-secondary btn-sm" type="button" data-action="edit-reward">編輯品項</button>
                            <form method="post" class="reward-management-form" hidden>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_reward">
                                <input type="hidden" name="reward_id" value="<?php echo (int) $reward['source_id']; ?>">
                                <div class="form-group"><label>品項名稱</label><input name="title" value="<?php echo htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                                <div class="form-group"><label>說明</label><textarea name="description"><?php echo htmlspecialchars($reward['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                <div class="grid-2">
                                    <div class="form-group"><label>所需點數</label><input type="number" name="cost_points" min="1" value="<?php echo (int) $reward['cost_points']; ?>" required></div>
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
                        <?php elseif ($reward['source_type'] === 'donor' && ($currentRole === 'admin' || ((int) ($reward['source_owner_id'] ?? 0) === (int) $currentUser['user_id']))): ?>
                            <button class="btn btn-secondary btn-sm" type="button" data-action="edit-reward">編輯品項</button>
                            <form method="post" class="reward-management-form" hidden>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_donor_reward">
                                <input type="hidden" name="item_id" value="<?php echo (int) $reward['source_id']; ?>">
                                <div class="form-group"><label>品項名稱</label><input name="title" value="<?php echo htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                                <div class="form-group"><label>說明</label><textarea name="description"><?php echo htmlspecialchars($reward['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                <div class="grid-2">
                                    <div class="form-group"><label>分類</label>
                                        <select name="category" required>
                                            <?php foreach (['discount' => '折扣優惠', 'product' => '實體商品', 'experience' => '體驗活動', 'other' => '其他'] as $categoryValue => $categoryLabel): ?>
                                                <option value="<?php echo $categoryValue; ?>" <?php echo ($reward['category'] ?? 'other') === $categoryValue ? 'selected' : ''; ?>><?php echo $categoryLabel; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group"><label>所需點數</label><input type="number" name="cost_points" min="1" value="<?php echo (int) $reward['cost_points']; ?>" required></div>
                                </div>
                                <div class="form-group"><label>庫存</label><input type="number" name="stock" min="0" value="<?php echo $reward['stock'] !== null ? (int) $reward['stock'] : ''; ?>" placeholder="不限量"></div>
                                <button class="btn btn-primary btn-sm" type="submit">儲存修改</button>
                                <button class="btn btn-secondary btn-sm" type="button" data-action="cancel-edit-reward">取消</button>
                            </form>
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
        <form method="post" class="mt-20">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="fulfill_claim">
            <div class="form-group"><label for="claim_token">無法掃描時輸入兌換碼</label><input id="claim_token" name="claim_token" required></div>
            <button class="btn btn-primary btn-sm" type="submit">確認核銷</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($currentRole, ['admin', 'foodbank_staff'], true)): ?>
<div class="card mt-32">
    <div class="card-header"><h2>食物銀行待核銷兌換</h2><p>志工完成兌換後會立即出現在這裡，請確認兌換編號或 QR Code 後核銷。</p></div>
    <div class="card-body">
        <?php if ($foodbankPendingClaims): ?>
            <div class="redemptions-table-body">
                <table class="data-table redemptions-table">
                    <thead><tr><th>兌換編號</th><th>兌換品項</th><th>志工</th><th>點數</th><th>兌換時間</th></tr></thead>
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
                <table class="data-table redemptions-table">
                    <thead><tr><th>兌換編號</th><th>兌換品項</th><th>志工</th><th>點數</th><th>狀態</th><th>時間</th></tr></thead>
                    <tbody>
                    <?php foreach ($foodbankRedemptions as $redemption): ?>
                        <tr>
                            <td><?php echo htmlspecialchars('R' . str_pad((string) $redemption['claim_id'], 6, '0', STR_PAD_LEFT)); ?></td>
                            <td><?php echo htmlspecialchars($redemption['title']); ?></td>
                            <td><?php echo htmlspecialchars($redemption['volunteer_name'] ?? '未知使用者'); ?></td>
                            <td><?php echo (int) $redemption['points_spent']; ?> 點</td>
                            <td><?php echo ['pending' => '待核銷', 'fulfilled' => '已核銷', 'cancelled' => '已取消'][$redemption['status']] ?? $redemption['status']; ?></td>
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

<div class="card mt-32">
    <div class="card-header"><h2>我的兌換紀錄</h2></div>
    <div class="card-body">
        <?php if ($myRedemptions): ?>
            <div class="redemptions-table-body">
                <table class="data-table redemptions-table"><thead><tr><th>獎勵</th><th>花費點數</th><th>狀態</th><th>時間</th></tr></thead><tbody>
                <?php foreach ($myRedemptions as $redemption): ?>
                    <tr><td><?php echo htmlspecialchars($redemption['title']); ?></td><td><?php echo (int) $redemption['points_spent']; ?></td><td><?php echo ['pending' => '處理中', 'fulfilled' => '已兌換完成', 'cancelled' => '已取消'][$redemption['status']] ?? $redemption['status']; ?></td><td><?php echo htmlspecialchars($redemption['created_at']); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>尚未兌換過獎勵</p></div>
        <?php endif; ?>

        <?php if ($canVerify): ?>
        <div class="mt-32">
            <div class="card-header"><h2>我的核銷紀錄</h2><p>顯示由目前帳號完成核銷的兌換憑證</p></div>
            <?php if ($myFulfillments): ?>
                <div class="redemptions-table-body">
                    <table class="data-table redemptions-table">
                        <thead><tr><th>兌換品項</th><th>兌換者</th><th>花費點數</th><th>核銷時間</th></tr></thead>
                        <tbody>
                        <?php foreach ($myFulfillments as $fulfillment): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fulfillment['title']); ?></td>
                                <td><?php echo htmlspecialchars($fulfillment['volunteer_name'] ?? '未知使用者'); ?></td>
                                <td><?php echo (int) $fulfillment['points_spent']; ?> 點</td>
                                <td><?php echo htmlspecialchars($fulfillment['redeemed_at'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>尚未完成任何核銷</p></div>
            <?php endif; ?>
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
                        status.textContent = '已讀取 QR Code，請按「確認核銷」。';
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
    </div>
</div>

<?php if ($canCreateCatalogReward): ?>
<div class="card mt-32">
    <div class="card-header"><h2>新增食物銀行兌換品項</h2><p>設定公益點數可以兌換的物資或禮品</p></div>
    <div class="card-body">
        <form method="post">
            <?php echo csrfField(); ?>
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
            <table class="data-table"><thead><tr><th>方案名稱</th><th>所需點數</th><th>分類</th><th>庫存</th><th>狀態</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($donorRewards as $reward): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($reward['title']); ?></strong><br><small><?php echo htmlspecialchars($reward['description'] ?? ''); ?></small></td>
                    <td><?php echo (int) $reward['cost_points']; ?> 點</td>
                    <td><?php echo ['discount' => '折扣', 'product' => '商品', 'experience' => '體驗', 'other' => '其他'][$reward['category']] ?? $reward['category']; ?></td>
                    <td><?php echo $reward['stock'] !== null ? (int) $reward['stock'] . ' 份' : '不限量'; ?></td>
                    <td><span class="status status-<?php echo $reward['status']; ?>"><?php echo $reward['status'] === 'active' ? '啟用' : '停用'; ?></span></td>
                    <td>
                        <button class="btn btn-secondary btn-sm" type="button" data-action="edit-donor-reward">編輯方案</button>
                        <form method="post" onsubmit="return confirm('確定要刪除此獎勵方案嗎？');" style="display: inline-block;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete_donor_reward">
                            <input type="hidden" name="item_id" value="<?php echo (int) $reward['item_id']; ?>">
                            <button class="btn btn-danger btn-sm" type="submit">刪除方案</button>
                        </form>
                    </td>
                </tr>
                <tr hidden>
                    <td colspan="6">
                        <form method="post" class="reward-management-form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="update_donor_reward">
                            <input type="hidden" name="item_id" value="<?php echo (int) $reward['item_id']; ?>">
                            <div class="grid-2">
                                <div class="form-group"><label>方案名稱</label><input name="title" value="<?php echo htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                                <div class="form-group"><label>所需點數</label><input type="number" name="cost_points" min="1" value="<?php echo (int) $reward['cost_points']; ?>" required></div>
                            </div>
                            <div class="grid-2">
                                <div class="form-group"><label>分類</label>
                                    <select name="category" required>
                                        <?php foreach (['discount' => '折扣優惠', 'product' => '實體商品', 'experience' => '體驗活動', 'other' => '其他'] as $categoryValue => $categoryLabel): ?>
                                            <option value="<?php echo $categoryValue; ?>" <?php echo $reward['category'] === $categoryValue ? 'selected' : ''; ?>><?php echo $categoryLabel; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group"><label>庫存</label><input type="number" name="stock" min="0" value="<?php echo $reward['stock'] !== null ? (int) $reward['stock'] : ''; ?>" placeholder="不限量"></div>
                            </div>
                            <div class="form-group"><label>說明</label><textarea name="description"><?php echo htmlspecialchars($reward['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                            <button class="btn btn-primary btn-sm" type="submit">儲存修改</button>
                            <button class="btn btn-secondary btn-sm" type="button" data-action="cancel-donor-reward-edit">取消</button>
                        </form>
                    </td>
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
            <?php echo csrfField(); ?>
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
