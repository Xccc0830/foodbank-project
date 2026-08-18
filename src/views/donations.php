<?php
/**
 * 捐贈管理視圖 - SaaS 風格迭代
 */

require_once BASE_PATH . '/src/models/DonationModel.php';
require_once BASE_PATH . '/src/models/DonorModel.php';

$donationModel = new DonationModel();
$formMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_donation') {
    $donation = [
        'donor_name' => trim($_POST['donor_name'] ?? ''),
        'donation_type' => $_POST['donation_type'] ?? 'food',
        'quantity' => (float) ($_POST['quantity'] ?? 0),
        'unit' => trim($_POST['unit'] ?? '件'),
        'item_name' => trim($_POST['item_name'] ?? ''),
        'weight_kg' => (float) ($_POST['weight_kg'] ?? 0),
        'size_description' => trim($_POST['size_description'] ?? ''),
        'expiry_date' => $_POST['expiry_date'] ?: null,
        'pickup_deadline' => $_POST['pickup_deadline'] ?: null,
        'delivery_option' => $_POST['delivery_option'] ?? 'volunteer_delivery',
        'vehicle_type' => $_POST['vehicle_type'] ?? 'none',
        'notes' => trim($_POST['notes'] ?? ''),
        'status' => 'pending',
    ];

    if ($donation['donor_name'] === '' || $donation['item_name'] === '' || $donation['quantity'] <= 0) {
        $formMessage = ['type' => 'error', 'text' => '請填寫商家、物資名稱與有效數量。'];
    } else {
        $formMessage = $donationModel->addDonation($donation)
            ? ['type' => 'success', 'text' => '惜食物資已上架，等待食物銀行評估。']
            : ['type' => 'error', 'text' => '物資上架失敗，請稍後再試。'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'evaluate_donation') {
    $status = $_POST['evaluation_status'] ?? 'assessed';
    $allowedStatuses = ['assessed', 'approved', 'rejected'];

    if (in_array($status, $allowedStatuses, true)) {
        $updated = $donationModel->updateEvaluation(
            (int) ($_POST['donation_id'] ?? 0),
            $status,
            trim($_POST['evaluation_notes'] ?? '')
        );
        $formMessage = $updated
            ? ['type' => 'success', 'text' => '食物銀行評估結果已更新。']
            : ['type' => 'error', 'text' => '評估更新失敗，請稍後再試。'];
    } else {
        $formMessage = ['type' => 'error', 'text' => '無效的評估狀態。'];
    }
}

$donations = $donationModel->getAllDonations();

$pending = array_filter($donations, function ($d) {
    return strtolower((string) $d['status']) === 'pending';
});
$approved = array_filter($donations, function ($d) {
    return strtolower((string) $d['status']) === 'approved';
});
$rejected = array_filter($donations, function ($d) {
    return strtolower((string) $d['status']) === 'rejected';
});
?>

<div class="view-header">
    <div>
        <h1 class="view-title">捐贈管理</h1>
        <p class="view-subtitle">管理與追蹤所有捐贈記錄</p>
    </div>
    <button class="btn btn-primary" onclick="openAddDonationModal()">
        <i class="fas fa-plus"></i> 新增捐贈
    </button>
</div>

<?php if ($formMessage): ?>
    <div class="alert alert-<?php echo $formMessage['type']; ?>">
        <?php echo htmlspecialchars($formMessage['text']); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="toolbar-row">
            <div>
                <h2>捐贈記錄</h2>
                <p class="toolbar-meta">共 <?php echo count($donations); ?> 筆記錄</p>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-secondary btn-sm" onclick="exportToCSV('donations.csv')">
                    <i class="fas fa-download"></i> 匯出
                </button>
            </div>
        </div>
        <div class="toolbar-row compact">
            <input type="text" placeholder="搜尋捐贈者、類型、數量..." class="search-input toolbar-input">
            <select class="filter-select toolbar-select">
                <option value="">全部狀態</option>
                <option value="pending">待審核</option>
                <option value="approved">已批准</option>
                <option value="rejected">已拒絕</option>
            </select>
        </div>
    </div>

    <div class="card-body">
        <?php if (!empty($donations)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>捐贈者</th>
                        <th>物資</th>
                        <th>捐贈類型</th>
                        <th>重量</th>
                        <th>領取期限</th>
                        <th>數量</th>
                        <th>日期</th>
                        <th>接收人員</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donations as $donation): ?>
                        <?php
                        $status = strtolower((string) $donation['status']);
                        $statusClass = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'approved';
                        $statusLabels = [
                            'pending' => '待評估',
                            'assessed' => '已評估',
                            'approved' => '已批准',
                            'rejected' => '已拒絕',
                            'received' => '已收貨',
                            'archived' => '已封存',
                        ];
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($donation['donor_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($donation['item_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($donation['donation_type']); ?></td>
                            <td><?php echo $donation['weight_kg'] !== null ? htmlspecialchars($donation['weight_kg']) . ' kg' : '-'; ?></td>
                            <td><?php echo !empty($donation['pickup_deadline']) ? htmlspecialchars($donation['pickup_deadline']) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit']); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($donation['donation_date'])); ?></td>
                            <td><?php echo $donation['received_by'] ? 'User #' . (int) $donation['received_by'] : '-'; ?></td>
                            <td><span class="status status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabels[$status] ?? $status); ?></span></td>
                            <td>
                                <div class="btn-group">
                                    <a href="#" class="btn btn-secondary btn-sm">查看</a>
                                    <?php if (in_array($status, ['pending', 'assessed'], true)): ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="evaluate_donation">
                                            <input type="hidden" name="donation_id" value="<?php echo (int) $donation['donation_id']; ?>">
                                            <select name="evaluation_status" aria-label="評估結果">
                                                <option value="assessed">已評估</option>
                                                <option value="approved">批准接收</option>
                                                <option value="rejected">拒絕接收</option>
                                            </select>
                                            <input type="text" name="evaluation_notes" placeholder="評估備註">
                                            <button type="submit" class="btn btn-primary btn-sm">送出評估</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>目前沒有捐贈記錄</p>
                <button class="btn btn-primary btn-sm" onclick="openAddDonationModal()">新增捐贈</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid mt-20">
    <div class="stat-card">
        <h3>待審核</h3>
        <div class="stat-number"><?php echo count($pending); ?></div>
        <p class="stat-label">筆捐贈等待處理</p>
    </div>
    <div class="stat-card">
        <h3>已批准</h3>
        <div class="stat-number"><?php echo count($approved); ?></div>
        <p class="stat-label">筆捐贈完成審核</p>
    </div>
    <div class="stat-card">
        <h3>已拒絕</h3>
        <div class="stat-number"><?php echo count($rejected); ?></div>
        <p class="stat-label">筆捐贈被拒絕</p>
    </div>
</div>
