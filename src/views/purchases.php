<?php
/**
 * 採購管理視圖 - SaaS 風格迭代
 */

$purchases = [
    [
        'code' => 'PUR20260815001',
        'supplier' => 'ABC 食品供應公司',
        'purchase_date' => '2026-08-15',
        'delivery_date' => '2026-08-20',
        'amount' => 5000,
        'status' => 'pending'
    ],
    [
        'code' => 'PUR20260814001',
        'supplier' => 'XYZ 商貿公司',
        'purchase_date' => '2026-08-14',
        'delivery_date' => '2026-08-18',
        'amount' => 3500,
        'status' => 'approved'
    ]
];

$suppliers = [
    [
        'name' => 'ABC 食品供應公司',
        'contact' => '王經理',
        'phone' => '010-1234-5678',
        'email' => 'contact@abc.com',
        'city' => '北京',
        'status' => 'active'
    ]
];
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
                    <tr>
                        <td><code><?php echo htmlspecialchars($purchase['code']); ?></code></td>
                        <td><?php echo htmlspecialchars($purchase['supplier']); ?></td>
                        <td><?php echo htmlspecialchars($purchase['purchase_date']); ?></td>
                        <td><?php echo htmlspecialchars($purchase['delivery_date']); ?></td>
                        <td>HK$<?php echo number_format((float) $purchase['amount'], 2); ?></td>
                        <td><span class="status status-<?php echo htmlspecialchars($purchase['status']); ?>"><?php echo htmlspecialchars($purchase['status']); ?></span></td>
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
                    <tr>
                        <td><strong><?php echo htmlspecialchars($supplier['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($supplier['contact']); ?></td>
                        <td><?php echo htmlspecialchars($supplier['phone']); ?></td>
                        <td><?php echo htmlspecialchars($supplier['email']); ?></td>
                        <td><?php echo htmlspecialchars($supplier['city']); ?></td>
                        <td><span class="status status-active">活躍</span></td>
                        <td>
                            <div class="btn-group">
                                <a href="#" class="btn btn-secondary btn-sm">編輯</a>
                                <a href="#" class="btn btn-danger btn-sm">刪除</a>
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
        <p class="stat-label">HKD</p>
    </div>
    <div class="stat-card">
        <h3>活躍供應商</h3>
        <div class="stat-number">8</div>
        <p class="stat-label">家供應商</p>
    </div>
</div>
