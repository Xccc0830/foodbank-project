<?php
/**
 * 受益者管理視圖 - SaaS 風格迭代
 */

require_once BASE_PATH . '/src/models/BeneficiaryModel.php';

$beneficiaryModel = new BeneficiaryModel();
$message = null;

// 處理新增受益者
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_beneficiary') {
    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? null) ?: null,
        'phone' => trim($_POST['phone'] ?? null) ?: null,
        'address' => trim($_POST['address'] ?? null) ?: null,
        'family_size' => (int) ($_POST['family_size'] ?? 0),
        'income_level' => $_POST['income_level'] ?? 'low',
        'notes' => trim($_POST['notes'] ?? null) ?: null,
        'status' => 'active',
    ];

    $insertId = $beneficiaryModel->addBeneficiary($data);
    if ($insertId) {
        $message = ['type' => 'success', 'text' => '受益者已新增。'];
    } else {
        $message = ['type' => 'error', 'text' => '新增受益者失敗，請稍後再試。'];
    }
}

// 處理分配（建立 beneficiary_distributions 紀錄）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_beneficiary') {
    $benId = (int) ($_POST['beneficiary_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $conn = $db->getConnection();

    if ($benId > 0) {
        $stmt = $conn->prepare("INSERT INTO beneficiary_distributions (beneficiary_id, distribution_date, approved_by, status, notes) VALUES (?, NOW(), NULL, 'pending', ?)");
        if ($stmt) {
            $stmt->bind_param('is', $benId, $notes);
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => '已為受益者建立分配紀錄（待處理）。'];
            } else {
                $message = ['type' => 'error', 'text' => '建立分配紀錄失敗：資料庫寫入錯誤。'];
            }
            $stmt->close();
        } else {
            $message = ['type' => 'error', 'text' => '建立分配紀錄失敗：無法準備資料庫語句。'];
        }
    } else {
        $message = ['type' => 'error', 'text' => '無效的受益者ID，請重新操作。'];
    }
}

// 處理刪除受益者（硬刪）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_beneficiary') {
    $benId = (int) ($_POST['beneficiary_id'] ?? 0);
    if ($benId > 0) {
        if ($beneficiaryModel->deleteBeneficiary($benId)) {
            $message = ['type' => 'success', 'text' => '受益者資料已刪除。'];
        } else {
            $message = ['type' => 'error', 'text' => '刪除受益者失敗，請稍後再試。'];
        }
    } else {
        $message = ['type' => 'error', 'text' => '無效的受益者ID，請重新操作。'];
    }
}

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

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
<?php endif; ?>

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
                                    <button type="button" class="btn btn-secondary btn-sm" 
                                        data-action="view"
                                        data-beneficiary-id="<?php echo (int) $beneficiary['beneficiary_id']; ?>"
                                        data-beneficiary-code="<?php echo htmlspecialchars($beneficiary['beneficiary_code']); ?>"
                                        data-first-name="<?php echo htmlspecialchars($beneficiary['first_name']); ?>"
                                        data-last-name="<?php echo htmlspecialchars($beneficiary['last_name']); ?>"
                                        data-phone="<?php echo htmlspecialchars($beneficiary['phone'] ?? ''); ?>"
                                        data-email="<?php echo htmlspecialchars($beneficiary['email'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($beneficiary['address'] ?? ''); ?>"
                                        data-family-size="<?php echo (int) ($beneficiary['family_size'] ?? 0); ?>"
                                        data-income-level="<?php echo htmlspecialchars($beneficiary['income_level']); ?>"
                                        data-registration-date="<?php echo htmlspecialchars($beneficiary['registration_date'] ?? ''); ?>"
                                        data-notes="<?php echo htmlspecialchars($beneficiary['notes'] ?? ''); ?>"
                                    >
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M2 12s4-8 10-8 10 8 10 8-4 8-10 8S2 12 2 12z" stroke="#111827" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="#111827" stroke-width="1.2"/></svg>
                                        查看
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" data-action="assign" data-beneficiary-id="<?php echo (int) $beneficiary['beneficiary_id']; ?>" data-full-name="<?php echo htmlspecialchars($fullName); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2v6" stroke="#111827" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 11h14" stroke="#111827" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 22h8" stroke="#111827" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        分配
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" data-action="delete" data-beneficiary-id="<?php echo (int) $beneficiary['beneficiary_id']; ?>" aria-label="刪除受益者">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3 6h18" stroke="#FFFFFF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 6v12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2V6M10 6V4a2 2 0 0 1 2-2h0a2 2 0 0 1 2 2v2" stroke="#FFFFFF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        刪除
                                    </button>
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
