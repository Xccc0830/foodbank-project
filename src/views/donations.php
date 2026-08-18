<?php
/**
 * 捐贈管理視圖 - SaaS 風格迭代
 */

require_once BASE_PATH . '/src/models/DonationModel.php';
require_once BASE_PATH . '/src/models/DonorModel.php';

$donationModel = new DonationModel();
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
                        <th>捐贈類型</th>
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
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($donation['donor_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($donation['donation_type']); ?></td>
                            <td><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit']); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($donation['donation_date'])); ?></td>
                            <td><?php echo $donation['received_by'] ? 'User #' . (int) $donation['received_by'] : '-'; ?></td>
                            <td><span class="status status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($donation['status']); ?></span></td>
                            <td>
                                <div class="btn-group">
                                    <a href="#" class="btn btn-secondary btn-sm">查看</a>
                                    <a href="#" class="btn btn-secondary btn-sm">編輯</a>
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
