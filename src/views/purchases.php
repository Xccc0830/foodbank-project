<?php
/**
 * 採購管理視圖 - SaaS 風格迭代
 */

$purchases = [
    [
        'code' => 'PUR20260815001',
        'supplier_id' => 'supplier-abc',
        'purchase_date' => '2026-08-15',
        'delivery_date' => '2026-08-20',
        'amount' => 5000,
        'status' => 'pending'
    ],
    [
        'code' => 'PUR20260814001',
        'supplier_id' => 'supplier-xyz',
        'purchase_date' => '2026-08-14',
        'delivery_date' => '2026-08-18',
        'amount' => 3500,
        'status' => 'approved'
    ]
];

$suppliers = [
    [
        'id' => 'supplier-abc',
        'name' => 'ABC 食品供應公司',
        'contact' => '王經理',
        'phone' => '010-1234-5678',
        'email' => 'contact@abc.com',
        'city' => '北京',
        'status' => 'active'
    ],
    [
        'id' => 'supplier-xyz',
        'name' => 'XYZ 商貿公司',
        'contact' => '李主任',
        'phone' => '010-9876-5432',
        'email' => 'contact@xyz.com',
        'city' => '上海',
        'status' => 'active'
    ]
];

$supplierNames = [];
foreach ($suppliers as $supplier) {
    $supplierNames[$supplier['id']] = $supplier['name'];
}
?>

<div class="view-header">
    <div>
        <h1 class="view-title">採購管理</h1>
        <p class="view-subtitle">管理採購單流程與供應商資訊</p>
    </div>
    <button class="btn btn-primary" onclick="openNewPurchaseModal()">
        <i class="fas fa-plus"></i> 新增採購單
    </button>
</div>

<div class="alert alert-info">
    <i class="fas fa-circle-info"></i>
    <div>
        <strong>流程提示</strong>
        <div>採購流程：草稿 → 待審核 → 已批准 → 已收貨</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="toolbar-row compact">
            <input type="text" placeholder="搜尋採購編號、供應商..." class="search-input toolbar-input">
            <select class="filter-select toolbar-select">
                <option value="">全部狀態</option>
                <option value="draft">草稿</option>
                <option value="pending">待審核</option>
                <option value="approved">已批准</option>
                <option value="received">已收貨</option>
                <option value="cancelled">已取消</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <table class="data-table">
            <thead>
                <tr>
                    <th>採購編號</th>
                    <th>供應商</th>
                    <th>採購日期</th>
                    <th>預計交貨</th>
                    <th>總金額</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($purchases as $purchase): ?>
                    <tr data-purchase-code="<?php echo htmlspecialchars($purchase['code'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-purchase-supplier-id="<?php echo htmlspecialchars($purchase['supplier_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <td><code><?php echo htmlspecialchars($purchase['code']); ?></code></td>
                        <td><?php echo htmlspecialchars($supplierNames[$purchase['supplier_id']] ?? '未指定供應商'); ?></td>
                        <td><?php echo htmlspecialchars($purchase['purchase_date']); ?></td>
                        <td><?php echo htmlspecialchars($purchase['delivery_date']); ?></td>
                        <td>NT$<?php echo number_format((float) $purchase['amount'], 2); ?></td>
                        <td><span class="status status-<?php echo htmlspecialchars($purchase['status']); ?>"><?php echo htmlspecialchars($purchase['status']); ?></span></td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-secondary btn-sm" data-action="view-purchase"
                                    data-code="<?php echo htmlspecialchars($purchase['code'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-supplier="<?php echo htmlspecialchars($supplierNames[$purchase['supplier_id']] ?? '未指定供應商', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-purchase-date="<?php echo htmlspecialchars($purchase['purchase_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-delivery-date="<?php echo htmlspecialchars($purchase['delivery_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-amount="<?php echo htmlspecialchars(number_format((float) $purchase['amount'], 2), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-status="<?php echo htmlspecialchars($purchase['status'], ENT_QUOTES, 'UTF-8'); ?>">查看</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-action="edit-purchase"
                                    data-code="<?php echo htmlspecialchars($purchase['code'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-supplier="<?php echo htmlspecialchars($supplierNames[$purchase['supplier_id']] ?? '未指定供應商', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-purchase-date="<?php echo htmlspecialchars($purchase['purchase_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-delivery-date="<?php echo htmlspecialchars($purchase['delivery_date'], ENT_QUOTES, 'UTF-8'); ?>">編輯</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-20">
    <div class="card-header">
        <div class="toolbar-row">
            <div>
                <h3>供應商管理</h3>
                <p class="toolbar-meta">管理合作供應商與聯繫資訊</p>
            </div>
            <button class="btn btn-success" onclick="openAddSupplierModal()">
                <i class="fas fa-plus"></i> 新增供應商
            </button>
        </div>
    </div>
    <div class="card-body">
        <table class="data-table">
            <thead>
                <tr>
                    <th>供應商名稱</th>
                    <th>聯繫人</th>
                    <th>電話</th>
                    <th>郵箱</th>
                    <th>城市</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $supplier): ?>
                    <tr data-supplier-id="<?php echo htmlspecialchars($supplier['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <td><strong><?php echo htmlspecialchars($supplier['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($supplier['contact']); ?></td>
                        <td><?php echo htmlspecialchars($supplier['phone']); ?></td>
                        <td><?php echo htmlspecialchars($supplier['email']); ?></td>
                        <td><?php echo htmlspecialchars($supplier['city']); ?></td>
                        <td><span class="status status-active">活躍</span></td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-secondary btn-sm" data-action="edit-supplier"
                                    data-supplier-id="<?php echo htmlspecialchars($supplier['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-name="<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-contact="<?php echo htmlspecialchars($supplier['contact'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-phone="<?php echo htmlspecialchars($supplier['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-email="<?php echo htmlspecialchars($supplier['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-city="<?php echo htmlspecialchars($supplier['city'], ENT_QUOTES, 'UTF-8'); ?>">編輯</button>
                                <button type="button" class="btn btn-danger btn-sm" data-action="delete-supplier"
                                    data-supplier-id="<?php echo htmlspecialchars($supplier['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-name="<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?>">刪除</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="stats-grid mt-20">
    <div class="stat-card">
        <h3>本月採購單</h3>
        <div class="stat-number">12</div>
        <p class="stat-label">筆採購單</p>
    </div>
    <div class="stat-card">
        <h3>待審核</h3>
        <div class="stat-number">3</div>
        <p class="stat-label">筆待審核</p>
    </div>
    <div class="stat-card">
        <h3>本月採購額</h3>
        <div class="stat-number">45,000</div>
        <p class="stat-label">TWD</p>
    </div>
    <div class="stat-card">
        <h3>活躍供應商</h3>
        <div class="stat-number">8</div>
        <p class="stat-label">家供應商</p>
    </div>
</div>
