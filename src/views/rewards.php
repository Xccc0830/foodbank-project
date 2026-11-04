<?php
/**
 * 興毅幣折抵：1 興毅幣 = 1 元，於合作商家消費時折抵（不可兌現）
 */

require_once BASE_PATH . '/src/models/RewardModel.php';
require_once BASE_PATH . '/src/models/MerchantDiscountModel.php';

$rewardModel = new RewardModel();
$discountModel = new MerchantDiscountModel();
$userId = (int) ($currentUser['user_id'] ?? 0);
$currentRole = $currentUser['role'] ?? '';
$memberType = $currentUser['member_type'] ?? null;
$isStaff = $currentRole === 'foodbank_staff';
$isDonor = $currentRole === 'member' && $memberType === 'enterprise';
$isVolunteer = $currentRole === 'member' && $memberType === 'general';
$canVerifyLegacy = $isStaff || $isDonor;
$message = $_SESSION['rewards_flash_message'] ?? null;
unset($_SESSION['rewards_flash_message']);
$preview = null;
$previewInput = ['token' => '', 'bill' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectUrl = '?page=rewards';

    if ($action === 'generate_token' && $isVolunteer) {
        $issued = $discountModel->issueToken($userId);
        if ($issued) {
            $_SESSION['discount_token'] = ['token' => $issued['token'], 'expires_at' => time() + $issued['seconds_left']];
            $message = ['type' => 'success', 'text' => '折抵憑證已產生，請在 ' . MerchantDiscountModel::TOKEN_TTL_MINUTES . ' 分鐘內出示給店家。'];
        } else {
            $message = ['type' => 'error', 'text' => '憑證產生失敗，請稍後再試。'];
        }
    } elseif ($action === 'preview_discount' && $isDonor) {
        $previewInput = ['token' => trim((string) ($_POST['token'] ?? '')), 'bill' => trim((string) ($_POST['bill_amount'] ?? ''))];
        $preview = $discountModel->previewRedemption($userId, $previewInput['token'], $previewInput['bill']);
        if (isset($preview['error'])) {
            $message = ['type' => 'error', 'text' => $preview['error']];
            $preview = null;
        }
    } elseif ($action === 'confirm_discount' && $isDonor) {
        $result = $discountModel->redeem($userId, $_POST['token'] ?? '', $_POST['bill_amount'] ?? '');
        $message = isset($result['error'])
            ? ['type' => 'error', 'text' => $result['error']]
            : ['type' => 'success', 'text' => sprintf('折抵完成：%s 消費 %d 元，折抵 %d 元，請收取 %d 元。', $result['rider_name'], $result['bill_amount'], $result['coins'], $result['final_amount'])];
    } elseif ($action === 'reverse_discount' && $isDonor) {
        $result = $discountModel->reverseRedemption($userId, (int) ($_POST['redemption_id'] ?? 0));
        $message = $result === true
            ? ['type' => 'success', 'text' => '已沖銷並退還興毅幣給 GO Rider。']
            : ['type' => 'error', 'text' => $result];
    } elseif ($action === 'refresh_claim' && $isVolunteer) {
        $claim = $rewardModel->refreshClaimToken((int) ($_POST['claim_id'] ?? 0), $userId);
        $message = $claim
            ? ['type' => 'success', 'text' => '新的 QR Code 已開啟，有效時間為 10 分鐘。']
            : ['type' => 'error', 'text' => '此兌換紀錄無法重新開啟。'];
        if (!empty($_POST['claim_id'])) {
            $redirectUrl .= '&claim_id=' . (int) $_POST['claim_id'];
        }
    } elseif ($action === 'fulfill_claim' && $canVerifyLegacy) {
        $fulfillment = $rewardModel->fulfillByToken($_POST['claim_token'] ?? '', $userId, $currentRole);
        $message = ['type' => $fulfillment['success'] ? 'success' : 'error', 'text' => $fulfillment['message']];
    }

    if ($preview === null) {
        $_SESSION['rewards_flash_message'] = $message;
        header('Location: ' . $redirectUrl);
        exit;
    }
}

$balanceSummary = $discountModel->getBalanceSummary($userId);
$balance = $balanceSummary['usable'];
$usageTotals = $discountModel->getUsageTotals($userId);
$e = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$activeToken = null;
if ($isVolunteer && !empty($_SESSION['discount_token']) && $_SESSION['discount_token']['expires_at'] > time()) {
    $activeToken = $_SESSION['discount_token'];
} else {
    unset($_SESSION['discount_token']);
}

$myDiscounts = $isVolunteer ? $discountModel->getRedemptionsByUser($userId) : [];
$merchantRule = $isDonor ? $discountModel->getRule($userId) : null;
$merchantRecords = $isDonor ? $discountModel->getRedemptionsByMerchant($userId) : [];
$settlement = $isStaff ? $discountModel->getMonthlySettlement() : [];

// 舊制兌換券（已扣幣）仍須可使用與核銷
$myLegacyClaims = $rewardModel->getRedemptionsByUser($userId);
$pendingClaims = $isVolunteer
    ? array_values(array_filter($rewardModel->getPendingClaimsByUser($userId), static function ($claim) {
        return ($claim['status'] ?? '') === 'pending';
    }))
    : [];
$selectedClaimId = $isVolunteer ? (int) ($_GET['claim_id'] ?? 0) : 0;
$selectedClaim = $selectedClaimId > 0 ? $rewardModel->getPendingClaimById($selectedClaimId, $userId) : null;
$foodbankPendingClaims = $isStaff ? $rewardModel->getPendingFoodbankClaims() : [];
$claimStatusLabels = ['pending' => '待核銷', 'fulfilled' => '已核銷', 'cancelled' => '已取消'];
$discountStatusLabels = ['completed' => '已完成', 'reversed' => '已沖銷'];
?>

<div class="view-header">
    <div>
        <h1 class="view-title">興毅幣折抵</h1>
        <p class="view-subtitle">1 興毅幣 = 1 元，可在合作商家消費時折抵；須達商家滿額門檻，且不超過商家的折抵上限，不可兌現。</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="reward-verification-notice reward-verification-notice-<?php echo $e($message['type']); ?>" role="alert">
        <span class="reward-verification-notice-icon"><?php echo $message['type'] === 'success' ? '✓' : '!'; ?></span>
        <div>
            <strong><?php echo $message['type'] === 'success' ? '完成' : '未完成'; ?></strong>
            <p><?php echo $e($message['text']); ?></p>
        </div>
    </div>
<?php endif; ?>

<?php if ($isVolunteer || $isStaff): ?>
<div class="stats-grid">
    <div class="stat-card"><h3>目前可用興毅幣</h3><div class="stat-number"><?php echo number_format($balance); ?></div><p class="stat-label">≈ 可折抵 <?php echo number_format($balance); ?> 元<?php if ($balanceSummary['expiring_soon'] > 0): ?>・<?php echo (int) $balanceSummary['expiring_soon']; ?> 幣將於 <?php echo MerchantDiscountModel::EXPIRING_SOON_DAYS; ?> 天內到期<?php endif; ?></p></div>
    <?php if ($isVolunteer): ?>
    <div class="stat-card"><h3>今日已折抵</h3><div class="stat-number"><?php echo (int) $usageTotals['day_used']; ?></div><p class="stat-label">上限 <?php echo MerchantDiscountModel::DAILY_CAP; ?> 幣</p></div>
    <div class="stat-card"><h3>本月已折抵</h3><div class="stat-number"><?php echo (int) $usageTotals['month_used']; ?></div><p class="stat-label">上限 <?php echo number_format(MerchantDiscountModel::MONTHLY_CAP); ?> 幣</p></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isVolunteer): ?>
<div class="card mt-32">
    <div class="card-header"><h2>出示折抵憑證</h2><p>結帳時向店家出示，店家輸入消費金額後系統自動計算折抵。憑證 <?php echo MerchantDiscountModel::TOKEN_TTL_MINUTES; ?> 分鐘內有效、僅能使用一次。</p></div>
    <div class="card-body">
        <?php if ($activeToken): ?>
            <div id="discount-qr-code" aria-label="折抵 QR Code"></div>
            <p class="reward-claim-number">折抵碼：<strong style="font-size:1.6em;letter-spacing:.1em;"><?php echo $e($activeToken['token']); ?></strong></p>
            <p id="discount-countdown" class="toolbar-meta"></p>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (window.QRCode) {
                    new QRCode(document.getElementById('discount-qr-code'), { text: <?php echo json_encode($activeToken['token']); ?>, width: 200, height: 200 });
                }
                var left = <?php echo max(0, (int) $activeToken['expires_at'] - time()); ?>;
                var el = document.getElementById('discount-countdown');
                function tick() {
                    if (left <= 0) {
                        el.textContent = '憑證已失效，請重新產生。';
                        document.getElementById('discount-qr-code').replaceChildren();
                        return;
                    }
                    el.textContent = '剩餘 ' + Math.floor(left / 60) + ' 分 ' + String(left % 60).padStart(2, '0') + ' 秒';
                    left--;
                    window.setTimeout(tick, 1000);
                }
                tick();
            });
            </script>
        <?php endif; ?>
        <form method="post" class="mt-20">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="generate_token">
            <button class="btn btn-primary" type="submit"><?php echo $activeToken ? '重新產生憑證' : '產生折抵憑證'; ?></button>
            <a class="btn btn-secondary" href="?page=merchant_directory">查看折扣商家</a>
        </form>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>使用規則</h2></div>
    <div class="card-body">
        <ul>
            <li>折抵金額 = 取「持有興毅幣」與「消費金額 × 商家折抵上限%」較小者，小數無條件捨去。</li>
            <li>消費金額需達商家滿額門檻才可折抵。例：滿 100 元、上限 20%，消費 150 元最多折 30 元，實付 120 元。</li>
            <li>興毅幣自取得日起 <?php echo MerchantDiscountModel::EXPIRY_MONTHS; ?> 個月內有效，逾期失效；使用時先扣最早取得的幣。</li>
            <li>每人每日最多折抵 <?php echo MerchantDiscountModel::DAILY_CAP; ?> 幣、每月最多 <?php echo number_format(MerchantDiscountModel::MONTHLY_CAP); ?> 幣。</li>
            <li>興毅幣不可兌現、不可轉讓；折抵後若店家誤操作，可在 <?php echo MerchantDiscountModel::REVERSAL_WINDOW_HOURS; ?> 小時內沖銷並退幣。</li>
        </ul>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>我的折抵紀錄</h2></div>
    <div class="card-body">
        <?php if ($myDiscounts): ?>
            <div class="redemptions-table-body">
                <table class="data-table"><thead><tr><th>商家</th><th>消費金額</th><th>折抵興毅幣</th><th>實付</th><th>狀態</th><th>時間</th></tr></thead><tbody>
                <?php foreach ($myDiscounts as $record): ?>
                    <tr>
                        <td><?php echo $e($record['store_name'] ?? '合作商家'); ?></td>
                        <td><?php echo (int) $record['bill_amount']; ?></td>
                        <td><?php echo (int) $record['coins_used']; ?></td>
                        <td><?php echo (int) $record['final_amount']; ?></td>
                        <td><?php echo $e($discountStatusLabels[$record['status']] ?? $record['status']); ?></td>
                        <td><?php echo $e($record['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>尚未使用興毅幣折抵</p></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isDonor): ?>
<div class="card mt-32">
    <div class="card-header">
        <h2>折抵結帳</h2>
        <p>
            <?php if ($merchantRule && $merchantRule['status'] === 'active'): ?>
                目前方案：滿 <?php echo (int) $merchantRule['min_spend']; ?> 元，最多折抵 <?php echo (int) $merchantRule['max_discount_percent']; ?>%。<a href="?page=merchant_rules">修改方案</a>
            <?php else: ?>
                尚未啟用折扣方案，請先到<a href="?page=merchant_rules">店家折扣設定</a>填寫。
            <?php endif; ?>
        </p>
    </div>
    <div class="card-body">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="preview_discount">
            <div class="grid-2">
                <div class="form-group"><label for="token">GO Rider 折抵碼</label><input id="token" name="token" maxlength="20" autocomplete="off" required value="<?php echo $e($previewInput['token']); ?>"></div>
                <div class="form-group"><label for="bill_amount">消費金額（元）</label><input id="bill_amount" name="bill_amount" type="number" min="1" step="1" required value="<?php echo $e($previewInput['bill']); ?>"></div>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">試算折抵</button>
        </form>

        <?php if ($preview): ?>
            <div class="reward-claim-card mt-20">
                <h3>試算結果：<?php echo $e($preview['rider_name']); ?></h3>
                <p>消費金額：<?php echo (int) $preview['bill_amount']; ?> 元　持有興毅幣：<?php echo (int) $preview['balance']; ?></p>
                <p><strong>折抵 <?php echo (int) $preview['coins']; ?> 元，應收 <?php echo (int) $preview['final_amount']; ?> 元</strong></p>
                <?php if ($preview['reason']): ?>
                    <p class="toolbar-meta"><?php echo $e($preview['reason']); ?></p>
                <?php else: ?>
                    <p class="toolbar-meta">憑證剩餘約 <?php echo max(1, (int) ceil($preview['seconds_left'] / 60)); ?> 分鐘。確認後將立即扣除 GO Rider 的興毅幣。</p>
                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="confirm_discount">
                        <input type="hidden" name="token" value="<?php echo $e($previewInput['token']); ?>">
                        <input type="hidden" name="bill_amount" value="<?php echo (int) $preview['bill_amount']; ?>">
                        <button class="btn btn-primary btn-sm" type="submit">確認折抵</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>折抵紀錄</h2><p>誤操作可在 <?php echo MerchantDiscountModel::REVERSAL_WINDOW_HOURS; ?> 小時內沖銷。</p></div>
    <div class="card-body">
        <?php if ($merchantRecords): ?>
            <div class="redemptions-table-body">
                <table class="data-table"><thead><tr><th>編號</th><th>GO Rider</th><th>消費金額</th><th>折抵</th><th>實收</th><th>狀態</th><th>時間</th><th></th></tr></thead><tbody>
                <?php foreach ($merchantRecords as $record): ?>
                    <tr>
                        <td>#<?php echo (int) $record['redemption_id']; ?></td>
                        <td><?php echo $e($record['rider_name'] ?? '未知使用者'); ?></td>
                        <td><?php echo (int) $record['bill_amount']; ?></td>
                        <td><?php echo (int) $record['coins_used']; ?></td>
                        <td><?php echo (int) $record['final_amount']; ?></td>
                        <td><?php echo $e($discountStatusLabels[$record['status']] ?? $record['status']); ?></td>
                        <td><?php echo $e($record['created_at']); ?></td>
                        <td>
                            <?php if ((int) $record['can_reverse']): ?>
                                <form method="post" onsubmit="return confirm('確定要沖銷這筆折抵並退幣嗎？');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="reverse_discount">
                                    <input type="hidden" name="redemption_id" value="<?php echo (int) $record['redemption_id']; ?>">
                                    <button class="btn btn-secondary btn-sm" type="submit">沖銷</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>尚無折抵紀錄</p></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isStaff): ?>
<div class="card mt-32">
    <div class="card-header"><h2>每月商家折抵對帳</h2><p>僅計入未沖銷的折抵。</p></div>
    <div class="card-body">
        <?php if ($settlement): ?>
            <div class="redemptions-table-body">
                <table class="data-table"><thead><tr><th>月份</th><th>商家</th><th>筆數</th><th>消費總額</th><th>折抵興毅幣</th></tr></thead><tbody>
                <?php foreach ($settlement as $row): ?>
                    <tr><td><?php echo $e($row['month']); ?></td><td><?php echo $e($row['store_name']); ?></td><td><?php echo (int) $row['redemption_count']; ?></td><td><?php echo (int) $row['total_bill']; ?></td><td><?php echo (int) $row['total_coins']; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>尚無折抵資料</p></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isVolunteer && $pendingClaims): ?>
<div class="card mt-32">
    <div class="card-header"><h2>舊制兌換券</h2><p>新制上線前已兌換的券仍可使用；不再開放新的實物兌換。</p></div>
    <div class="card-body reward-claims-list">
        <?php foreach ($pendingClaims as $claim): ?>
            <div class="reward-claim-card">
                <h3><?php echo $e($claim['title']); ?></h3>
                <p class="toolbar-meta">兌換編號：<?php echo $e('R' . str_pad((string) $claim['claim_id'], 6, '0', STR_PAD_LEFT)); ?>・<?php echo (int) $claim['points_spent']; ?> 點・待核銷</p>
                <a class="btn btn-primary btn-sm" href="?page=rewards&amp;claim_id=<?php echo (int) $claim['claim_id']; ?>">查看優惠券</a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isVolunteer && $selectedClaim): ?>
<div class="card mt-32">
    <div class="card-header"><h2><?php echo $e($selectedClaim['title']); ?></h2><p>請於核銷時再出示 QR Code</p></div>
    <div class="card-body reward-claim-detail">
        <p class="reward-claim-number">兌換編號：<?php echo $e('R' . str_pad((string) $selectedClaim['claim_id'], 6, '0', STR_PAD_LEFT)); ?></p>
        <a class="btn btn-secondary btn-sm" href="?page=rewards">返回</a>
        <div id="selected-reward-qr-code" aria-label="兌換 QR Code"></div>
        <p id="selected-reward-qr-countdown" class="toolbar-meta"></p>
        <form method="post" action="?page=rewards&amp;claim_id=<?php echo (int) $selectedClaim['claim_id']; ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="refresh_claim">
            <input type="hidden" name="claim_id" value="<?php echo (int) $selectedClaim['claim_id']; ?>">
            <button class="btn btn-primary btn-sm" type="submit">重新開啟 QR Code</button>
        </form>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new QRCode(document.getElementById('selected-reward-qr-code'), { text: <?php echo json_encode($selectedClaim['token_value']); ?>, width: 220, height: 220 });
    var remainingSeconds = <?php echo max(0, strtotime($selectedClaim['token_expires_at']) - time()); ?>;
    var countdown = document.getElementById('selected-reward-qr-countdown');
    window.setInterval(function () {
        remainingSeconds = Math.max(0, remainingSeconds - 1);
        countdown.textContent = remainingSeconds > 0 ? '剩餘 ' + Math.ceil(remainingSeconds / 60) + ' 分鐘' : 'QR Code 已失效，請重新開啟。';
        if (remainingSeconds === 0) { document.getElementById('selected-reward-qr-code').replaceChildren(); }
    }, 1000);
});
</script>
<?php endif; ?>

<?php if ($canVerifyLegacy): ?>
<div class="card mt-32">
    <div class="card-header"><h2>舊制兌換券核銷</h2><p>僅供核銷新制上線前已兌換的券。</p></div>
    <div class="card-body">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="fulfill_claim">
            <div class="form-group"><label for="claim_token">兌換碼</label><input id="claim_token" name="claim_token" required></div>
            <button class="btn btn-primary btn-sm" type="submit">確認核銷</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isStaff && $foodbankPendingClaims): ?>
<div class="card mt-32">
    <div class="card-header"><h2>舊制待核銷兌換</h2></div>
    <div class="card-body">
        <div class="redemptions-table-body">
            <table class="data-table"><thead><tr><th>兌換編號</th><th>品項</th><th>GO Rider</th><th>興毅幣</th><th>兌換時間</th></tr></thead><tbody>
            <?php foreach ($foodbankPendingClaims as $claim): ?>
                <tr>
                    <td><?php echo $e('R' . str_pad((string) $claim['claim_id'], 6, '0', STR_PAD_LEFT)); ?></td>
                    <td><?php echo $e($claim['title']); ?></td>
                    <td><?php echo $e($claim['volunteer_name'] ?? '未知使用者'); ?></td>
                    <td><?php echo (int) $claim['points_spent']; ?> 點</td>
                    <td><?php echo $e($claim['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isVolunteer && $myLegacyClaims): ?>
<div class="card mt-32">
    <div class="card-header"><h2>舊制兌換紀錄</h2></div>
    <div class="card-body">
        <div class="redemptions-table-body">
            <table class="data-table"><thead><tr><th>品項</th><th>花費興毅幣</th><th>狀態</th><th>時間</th></tr></thead><tbody>
            <?php foreach ($myLegacyClaims as $redemption): ?>
                <tr>
                    <td><?php echo $e($redemption['title']); ?></td>
                    <td><?php echo (int) $redemption['points_spent']; ?></td>
                    <td><?php echo $e($claimStatusLabels[$redemption['status']] ?? $redemption['status']); ?></td>
                    <td><?php echo $e($redemption['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>
<?php endif; ?>
