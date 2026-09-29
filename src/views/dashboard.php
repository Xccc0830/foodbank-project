<?php
/**
 * 儀表板視圖 - 精修版
 */

require_once BASE_PATH . '/src/models/DonationModel.php';

$donationModel = new DonationModel();

$dashboardRole = $currentUser['role'] ?? 'foodbank_staff';
$dashboardMemberType = $currentUser['member_type'] ?? null;
$isEnterpriseMember = $dashboardRole === 'member' && $dashboardMemberType === 'enterprise';
$canViewDonations = $dashboardRole === 'foodbank_staff' || $isEnterpriseMember;
$recentDonations = $canViewDonations
    ? array_slice($donationModel->getAllDonations(null, $isEnterpriseMember ? (int) $currentUser['user_id'] : null), 0, 5)
    : [];
$enterpriseSustainability = [
    'delivery_count' => 0,
    'total_weight' => 0,
    'total_distance' => 0,
    'food_waste_avoided' => 0,
];
if ($isEnterpriseMember) {
    $enterpriseUserId = (int) $currentUser['user_id'];
    $sustainabilityResult = $connection->query(
        "SELECT COUNT(DISTINCT d.delivery_id) AS delivery_count,
                COALESCE(SUM(d.weight_kg), 0) AS total_weight,
                COALESCE(SUM(d.total_distance_km), 0) AS total_distance
         FROM deliveries d
         INNER JOIN donations n ON n.donation_id = d.donation_id
         WHERE n.donor_id = {$enterpriseUserId}
           AND d.status = 'delivered'"
    );
    if ($sustainabilityResult && ($sustainabilityRow = $sustainabilityResult->fetch_assoc())) {
        $enterpriseSustainability['delivery_count'] = (int) $sustainabilityRow['delivery_count'];
        $enterpriseSustainability['total_weight'] = (float) $sustainabilityRow['total_weight'];
        $enterpriseSustainability['total_distance'] = (float) $sustainabilityRow['total_distance'];
        $enterpriseSustainability['food_waste_avoided'] = $enterpriseSustainability['total_weight'] * 2.5;
    }
}
$donationListPage = $isEnterpriseMember ? 'donation_materials' : 'donation_materials_review';
$dashboardRoleLabels = [
    'foodbank_staff' => '管理介面',
    'member' => $isEnterpriseMember ? '企業會員工作台' : '一般會員工作台',
];
?>

<div class="view-header">
    <div>
        <h1 class="view-title"><?php echo htmlspecialchars($dashboardRoleLabels[$dashboardRole] ?? '管理介面'); ?></h1>
        <p class="view-subtitle"><?php echo date('Y-m-d'); ?>，歡迎 <?php echo htmlspecialchars($currentUser['full_name'] ?? '使用者'); ?></p>
    </div>
    <?php if ($isEnterpriseMember): ?>
    <a class="btn btn-primary" href="?page=donation_materials">
        <i class="fas fa-plus"></i> 新增捐贈
    </a>
    <?php endif; ?>
</div>

<div class="card role-intro mb-20">
    <div class="card-body">
        <?php if ($dashboardRole === 'member' && !$isEnterpriseMember): ?>
            <h2>你的公益任務</h2><p>報名公益活動，或前往配送任務接單；完成配送後會記錄興毅幣。</p>
            <a href="?page=deliveries" class="btn btn-primary btn-sm">查看可接任務</a>
        <?php elseif ($dashboardRole === 'foodbank_staff'): ?>
            <h2>管理介面</h2><p>處理物資審查、配送與公益活動，確保物資完成媒合。</p>
            <a href="?page=rewards" class="btn btn-secondary btn-sm">管理興毅幣兌換</a>
        <?php elseif ($isEnterpriseMember): ?>
            <h2>企業惜食行動</h2><p>報名公益活動，也可將企業剩餘食物或物資捐贈給食物銀行。</p>
            <a href="?page=donation_materials" class="btn btn-primary btn-sm">上架剩食物資</a>
        <?php else: ?>
            <h2>忠信食物銀行管理</h2><p>管理平台模組、帳號權限、稽核紀錄與整體公益服務成效。</p>
            <a href="?page=settings" class="btn btn-primary btn-sm">前往系統設置</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2 mt-32">
    <?php if ($canViewDonations): ?>
    <div class="card">
        <div class="card-header">
            <h2>最近捐贈</h2>
            <p>最新捐贈紀錄</p>
        </div>
        <div class="card-body recent-donations-body">
            <?php if (!empty($recentDonations)): ?>
                <table class="data-table recent-donations-table">
                    <thead>
                        <tr>
                            <th>捐贈者</th>
                            <th>類型</th>
                            <th>數量</th>
                            <th>狀態</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDonations as $donation): ?>
                            <?php
                            $donationTypeLabels = [
                                'food' => '食物',
                                'supplies' => '用品',
                                'other' => '其他',
                            ];
                            $donationStatusLabels = [
                                'pending' => '待評估',
                                'assessed' => '已評估',
                                'approved' => '已批准',
                                'received' => '已收貨',
                                'rejected' => '已拒絕',
                                'published' => '已發布',
                                'archived' => '已封存',
                            ];
                            $donationStatus = strtolower((string) ($donation['status'] ?? ''));
                            $donationStatusLabel = $donationStatusLabels[$donationStatus] ?? '其他狀態';
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($donation['donor_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($donationTypeLabels[$donation['donation_type']] ?? $donation['donation_type']); ?></td>
                                <td><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit']); ?></td>
                                <td><span class="status <?php echo htmlspecialchars('status-' . str_replace(' ', '_', $donationStatus)); ?>"><?php echo htmlspecialchars($donationStatusLabel); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mt-20">
                    <a href="?page=<?php echo htmlspecialchars($donationListPage, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary btn-sm">查看全部</a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>目前沒有捐贈記錄</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($isEnterpriseMember): ?>
<div class="card mt-32">
    <div class="card-header">
        <h2>我的永續報告</h2>
        <p>依企業會員已完成配送的物資估算公益與環境效益</p>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <div class="stat-card"><h3>完成配送</h3><div class="stat-number"><?php echo number_format($enterpriseSustainability['delivery_count']); ?></div><p class="stat-label">趟次</p></div>
            <div class="stat-card"><h3>捐贈重量</h3><div class="stat-number"><?php echo number_format($enterpriseSustainability['total_weight'], 1); ?></div><p class="stat-label">公斤</p></div>
            <div class="stat-card"><h3>避免浪費估算</h3><div class="stat-number"><?php echo number_format($enterpriseSustainability['food_waste_avoided'], 1); ?></div><p class="stat-label">kgCO2e</p></div>
            <div class="stat-card"><h3>配送里程</h3><div class="stat-number"><?php echo number_format($enterpriseSustainability['total_distance'], 1); ?></div><p class="stat-label">公里</p></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card mt-32">
    <div class="card-header">
        <h2>快速操作</h2>
        <p>常用管理功能</p>
    </div>
    <div class="card-body">
        <div class="grid-4">
            <?php if ($isEnterpriseMember): ?>
            <a class="btn btn-primary" href="?page=donation_materials"><i class="fas fa-gift"></i> 新增捐贈</a>
            <?php endif; ?>
            <a href="?page=settings" class="btn btn-secondary"><i class="fas fa-gear"></i> 系統設置</a>
            <button class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> 列印報告</button>
        </div>
    </div>
</div>
