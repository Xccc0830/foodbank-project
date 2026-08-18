<?php
/**
 * 受益者管理視圖 - SaaS 風格迭代
 */

require_once BASE_PATH . '/src/models/BeneficiaryModel.php';

$beneficiaryModel = new BeneficiaryModel();
$beneficiaries = $beneficiaryModel->getAllBeneficiaries();

$activeBeneficiaries = array_filter($beneficiaries, function ($b) {
    return strtolower((string) $b['status']) === 'active';
});
$lowIncomeBeneficiaries = array_filter($beneficiaries, function ($b) {
    return strtolower((string) $b['income_level']) === 'low' && strtolower((string) $b['status']) === 'active';
});
$mediumIncomeBeneficiaries = array_filter($beneficiaries, function ($b) {
    return strtolower((string) $b['income_level']) === 'medium' && strtolower((string) $b['status']) === 'active';
});
$totalFamilyMembers = array_sum(array_map(function ($b) {
    return (int) ($b['family_size'] ?? 0);
}, $activeBeneficiaries));
?>

<div class="view-header">
    <div>
        <h1 class="view-title">受益者管理</h1>
        <p class="view-subtitle">管理受益者檔案、家庭規模與收入等級</p>
    </div>
    <button class="btn btn-primary" onclick="openAddBeneficiaryModal()">
        <i class="fas fa-user-plus"></i> 新增受益者
    </button>
</div>

<div class="card">
    <div class="card-header">
        <div class="toolbar-row compact">
            <input type="text" placeholder="搜尋姓名、電話、代碼..." class="search-input toolbar-input">
            <select class="filter-select toolbar-select">
                <option value="">全部狀態</option>
                <option value="active">活躍</option>
                <option value="inactive">非活躍</option>
                <option value="suspended">暫停</option>
            </select>
        </div>
    </div>

    <div class="card-body">
        <?php if (!empty($beneficiaries)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>代碼</th>
                        <th>姓名</th>
                        <th>聯繫方式</th>
                        <th>家庭成員</th>
                        <th>收入級別</th>
                        <th>註冊日期</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($beneficiaries as $beneficiary): ?>
                        <?php
                        $fullName = trim(($beneficiary['first_name'] ?? '') . ' ' . ($beneficiary['last_name'] ?? ''));
                        $status = strtolower((string) $beneficiary['status']);
                        $statusClass = $status === 'inactive' ? 'inactive' : 'active';
                        $income = strtolower((string) $beneficiary['income_level']);
                        $incomeLabels = ['low' => '低', 'medium' => '中', 'high' => '高'];
                        $contact = $beneficiary['phone'] ?: ($beneficiary['email'] ?: '-');
                        ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($beneficiary['beneficiary_code']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($fullName); ?></strong></td>
                            <td><?php echo htmlspecialchars($contact); ?></td>
                            <td><?php echo (int) ($beneficiary['family_size'] ?? 0); ?></td>
                            <td><?php echo $incomeLabels[$income] ?? htmlspecialchars($beneficiary['income_level']); ?></td>
                            <td><?php echo !empty($beneficiary['registration_date']) ? date('Y-m-d', strtotime($beneficiary['registration_date'])) : '-'; ?></td>
                            <td><span class="status status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($beneficiary['status']); ?></span></td>
                            <td>
                                <div class="btn-group">
                                    <a href="#" class="btn btn-secondary btn-sm">查看</a>
                                    <a href="#" class="btn btn-secondary btn-sm">分配</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>目前沒有受益者記錄</p>
                <button class="btn btn-primary btn-sm" onclick="openAddBeneficiaryModal()">新增受益者</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid mt-20">
    <div class="stat-card">
        <h3>活躍受益者</h3>
        <div class="stat-number"><?php echo count($activeBeneficiaries); ?></div>
        <p class="stat-label">位可服務受益者</p>
    </div>
    <div class="stat-card">
        <h3>家庭成員</h3>
        <div class="stat-number"><?php echo $totalFamilyMembers; ?></div>
        <p class="stat-label">位家庭成員</p>
    </div>
    <div class="stat-card">
        <h3>低收入</h3>
        <div class="stat-number"><?php echo count($lowIncomeBeneficiaries); ?></div>
        <p class="stat-label">位低收入受益者</p>
    </div>
    <div class="stat-card">
        <h3>中收入</h3>
        <div class="stat-number"><?php echo count($mediumIncomeBeneficiaries); ?></div>
        <p class="stat-label">位中收入受益者</p>
    </div>
</div>
