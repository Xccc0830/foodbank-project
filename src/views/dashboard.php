<?php
/**
 * 儀表板視圖 - 精修版
 */

require_once BASE_PATH . '/src/models/BeneficiaryModel.php';
require_once BASE_PATH . '/src/models/InventoryModel.php';
require_once BASE_PATH . '/src/models/DonationModel.php';

$beneficiaryModel = new BeneficiaryModel();
$inventoryModel = new InventoryModel();
$donationModel = new DonationModel();

$recentDonations = array_slice($donationModel->getAllDonations(null), 0, 5);
$lowStockItems = array_slice($inventoryModel->getLowStockItems(), 0, 5);
?>

<div class="view-header">
    <div>
        <h1 class="view-title">儀表板</h1>
        <p class="view-subtitle"><?php echo date('Y-m-d'); ?> 即時營運總覽</p>
    </div>
    <button class="btn btn-primary" onclick="openAddDonationModal()">
        <i class="fas fa-plus"></i> 新增捐贈
    </button>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <h3>活躍受益者</h3>
        <div class="stat-number"><?php echo number_format($beneficiaryModel->countActiveBeneficiaries()); ?></div>
        <p class="stat-label">位受益者</p>
    </div>

    <div class="stat-card">
        <h3>庫存項目</h3>
        <div class="stat-number"><?php echo number_format($inventoryModel->countAvailableItems()); ?></div>
        <p class="stat-label">個項目</p>
    </div>

    <div class="stat-card">
        <h3>總庫存量</h3>
        <div class="stat-number"><?php echo number_format($inventoryModel->getTotalInventoryValue()); ?></div>
        <p class="stat-label">件物品</p>
    </div>

    <div class="stat-card">
        <h3>家庭成員</h3>
        <div class="stat-number"><?php echo number_format($beneficiaryModel->getTotalFamilyMembers()); ?></div>
        <p class="stat-label">位家庭成員</p>
    </div>
</div>

<div class="grid-2 mt-32">
    <div class="card">
        <div class="card-header">
            <h2>最近捐贈</h2>
            <p>最新捐贈紀錄</p>
        </div>
        <div class="card-body">
            <?php if (!empty($recentDonations)): ?>
                <table class="data-table">
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
                            <?php $statusClass = 'status-' . strtolower(str_replace(' ', '_', (string) $donation['status'])); ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($donation['donor_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($donation['donation_type']); ?></td>
                                <td><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit']); ?></td>
                                <td><span class="status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($donation['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mt-20">
                    <a href="?page=donations" class="btn btn-secondary btn-sm">查看全部</a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>目前沒有捐贈記錄</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>低庫存提醒</h2>
            <p>優先補貨項目</p>
        </div>
        <div class="card-body">
            <?php if (!empty($lowStockItems)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>物品名稱</th>
                            <th>現有數量</th>
                            <th>重訂點</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStockItems as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                <td><?php echo (int) $item['quantity_on_hand']; ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                                <td><?php echo (int) $item['reorder_level']; ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mt-20">
                    <a href="?page=inventory" class="btn btn-secondary btn-sm">前往庫存頁</a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-circle-check"></i>
                    <p>目前沒有低庫存項目</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header">
        <h2>快速操作</h2>
        <p>常用管理功能</p>
    </div>
    <div class="card-body">
        <div class="grid-3">
            <button class="btn btn-primary" onclick="openAddDonationModal()"><i class="fas fa-gift"></i> 新增捐贈</button>
            <button class="btn btn-primary" onclick="openAddBeneficiaryModal()"><i class="fas fa-user-plus"></i> 新增受益者</button>
            <button class="btn btn-primary" onclick="openAddInventoryModal()"><i class="fas fa-box"></i> 新增庫存</button>
            <button class="btn btn-secondary" onclick="openNewPurchaseModal()"><i class="fas fa-cart-shopping"></i> 新增採購單</button>
            <a href="?page=settings" class="btn btn-secondary"><i class="fas fa-gear"></i> 系統設置</a>
            <button class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> 列印報告</button>
        </div>
    </div>
</div>
