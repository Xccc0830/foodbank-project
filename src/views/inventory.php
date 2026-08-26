<?php
/**
 * 庫存管理視圖 - SaaS 風格迭代
 */

require_once BASE_PATH . '/src/models/InventoryModel.php';

$inventoryModel = new InventoryModel();
$message = null;

// 處理新增庫存項目
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_inventory') {
    $data = [
        'item_name' => trim($_POST['item_name'] ?? ''),
        'category' => trim($_POST['category'] ?? 'other'),
        'quantity_on_hand' => (float) ($_POST['quantity_on_hand'] ?? 0),
        'unit' => trim($_POST['unit'] ?? '件'),
        'reorder_level' => is_numeric($_POST['reorder_level'] ?? null) ? (float) $_POST['reorder_level'] : 0,
        'expiry_date' => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
        'location' => trim($_POST['location'] ?? ''),
        'status' => 'available',
    ];

    $insertId = $inventoryModel->addInventoryItem($data);
    if ($insertId) {
        $message = ['type' => 'success', 'text' => '庫存項目已新增。'];
    } else {
        $message = ['type' => 'error', 'text' => '新增庫存項目失敗，請稍後再試。'];
    }
}

$inventoryItems = $inventoryModel->getAllInventory();
$lowStockItems = $inventoryModel->getLowStockItems();

$availableItems = array_filter($inventoryItems, function ($i) {
    return strtolower((string) $i['status']) === 'available';
});
?>

<div class="view-header">
    <div>
        <h1 class="view-title">庫存管理</h1>
        <p class="view-subtitle">管理食物銀行的庫存、重訂點與保存資訊</p>
    </div>
    <button class="btn btn-primary" onclick="openAddInventoryModal()">
        <i class="fas fa-plus"></i> 新增項目
    </button>
</div>

<?php if (!empty($lowStockItems)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-triangle-exclamation"></i>
        <div>
            <strong>庫存不足提醒</strong>
            <div>目前有 <?php echo count($lowStockItems); ?> 項低於重訂點，請優先補貨。</div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="toolbar-row compact">
            <input type="text" placeholder="搜尋物品名稱、分類、位置..." class="search-input toolbar-input">
            <select class="filter-select toolbar-select">
                <option value="">全部分類</option>
                <option value="food">食物</option>
                <option value="supplies">用品</option>
                <option value="other">其他</option>
            </select>
        </div>
    </div>

    <div class="card-body">
        <?php if (!empty($inventoryItems)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>項目代碼</th>
                        <th>項目名稱</th>
                        <th>分類</th>
                        <th>現有數量</th>
                        <th>重訂點</th>
                        <th>保質期</th>
                        <th>位置</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventoryItems as $item): ?>
                        <?php
                        $status = strtolower((string) $item['status']);
                        $statusClass = $status === 'inactive' ? 'inactive' : 'active';
                        $isLow = (float) $item['quantity_on_hand'] <= (float) $item['reorder_level'];
                        $expiry = !empty($item['expiry_date']) ? date('Y-m-d', strtotime($item['expiry_date'])) : '-';
                        ?>
                        <tr<?php echo $isLow ? ' style="background: rgba(245, 158, 11, 0.06);"' : ''; ?>>
                            <td><code><?php echo htmlspecialchars($item['item_code']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td><?php echo htmlspecialchars($item['quantity_on_hand']); ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                            <td><?php echo htmlspecialchars($item['reorder_level']); ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                            <td><?php echo $expiry; ?></td>
                            <td><?php echo htmlspecialchars($item['location'] ?? '-'); ?></td>
                            <td><span class="status status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
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
                <i class="fas fa-box-open"></i>
                <p>目前沒有庫存項目</p>
                <button class="btn btn-primary btn-sm" onclick="openAddInventoryModal()">新增庫存項目</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid mt-20">
    <div class="stat-card">
        <h3>總項目數</h3>
        <div class="stat-number"><?php echo count($inventoryItems); ?></div>
        <p class="stat-label">個庫存項目</p>
    </div>
    <div class="stat-card">
        <h3>可用項目</h3>
        <div class="stat-number"><?php echo count($availableItems); ?></div>
        <p class="stat-label">個可供分發</p>
    </div>
    <div class="stat-card">
        <h3>低庫存</h3>
        <div class="stat-number"><?php echo count($lowStockItems); ?></div>
        <p class="stat-label">個項目需補貨</p>
    </div>
    <div class="stat-card">
        <h3>總庫存量</h3>
        <div class="stat-number"><?php echo number_format($inventoryModel->getTotalInventoryValue(), 0); ?></div>
        <p class="stat-label">件</p>
    </div>
</div>
