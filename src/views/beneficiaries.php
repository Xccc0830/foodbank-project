<?php
/**
 * 受益者管理視圖 - SaaS 風格迭代
 */

require_once BASE_PATH . '/src/models/BeneficiaryModel.php';
require_once BASE_PATH . '/src/models/InventoryModel.php';

$beneficiaryModel = new BeneficiaryModel();
$inventoryModel = new InventoryModel();
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
    $inventoryId = (int) ($_POST['inventory_id'] ?? 0);
    $quantity = (float) ($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $conn = $db->getConnection();

    if ($benId > 0 && $inventoryId > 0 && $quantity > 0) {
        $conn->begin_transaction();
        $stockStmt = $conn->prepare("SELECT quantity_on_hand, status FROM inventory WHERE inventory_id = ? FOR UPDATE");
        $stockAvailable = false;
        if ($stockStmt) {
            $stockStmt->bind_param('i', $inventoryId);
            if ($stockStmt->execute()) {
                $stock = $stockStmt->get_result()->fetch_assoc();
                $stockAvailable = $stock
                    && !in_array(strtolower((string) $stock['status']), ['removed', 'expired'], true)
                    && (float) $stock['quantity_on_hand'] >= $quantity;
            }
            $stockStmt->close();
        }

        if (!$stockAvailable) {
            $conn->rollback();
            $message = ['type' => 'error', 'text' => '庫存不足或物資目前不可分配，請重新確認數量。'];
        } else {
            $stmt = $conn->prepare("INSERT INTO beneficiary_distributions (beneficiary_id, distribution_date, approved_by, status, notes) VALUES (?, NOW(), ?, 'approved', ?)");
        if ($stmt) {
            $approvedBy = (int) ($currentUser['user_id'] ?? 0);
            $stmt->bind_param('iis', $benId, $approvedBy, $notes);
            if ($stmt->execute()) {
                $distributionId = (int) $conn->insert_id;
                $itemStmt = $conn->prepare("INSERT INTO distribution_items (distribution_id, inventory_id, quantity, notes) VALUES (?, ?, ?, ?)");
                if ($itemStmt) {
                    $itemStmt->bind_param('iids', $distributionId, $inventoryId, $quantity, $notes);
                    if ($itemStmt->execute()) {
                        $updateStock = $conn->prepare("UPDATE inventory SET quantity_on_hand = quantity_on_hand - ?, status = CASE WHEN quantity_on_hand - ? <= reorder_level THEN 'low_stock' ELSE status END, last_updated = NOW() WHERE inventory_id = ? AND quantity_on_hand >= ?");
                        if ($updateStock) {
                            $updateStock->bind_param('ddid', $quantity, $quantity, $inventoryId, $quantity);
                            $stockUpdated = $updateStock->execute() && $updateStock->affected_rows === 1;
                            $updateStock->close();
                        } else {
                            $stockUpdated = false;
                        }

                        if ($stockUpdated) {
                            $transactionStmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, transaction_type, quantity, reference_type, reference_id, notes, performed_by) VALUES (?, 'out', ?, 'beneficiary_distribution', ?, ?, ?)");
                            if ($transactionStmt) {
                                $transactionStmt->bind_param('idisi', $inventoryId, $quantity, $distributionId, $notes, $approvedBy);
                                $transactionRecorded = $transactionStmt->execute();
                                $transactionStmt->close();
                            } else {
                                $transactionRecorded = false;
                            }
                        } else {
                            $transactionRecorded = false;
                        }

                        if ($stockUpdated && $transactionRecorded) {
                            $conn->commit();
                            $message = ['type' => 'success', 'text' => '分配紀錄已建立，庫存已扣除。'];
                        } else {
                            $conn->rollback();
                            $message = ['type' => 'error', 'text' => '庫存扣除或異動記錄失敗，資料未變更。'];
                        }
                    } else {
                        $conn->rollback();
                        $message = ['type' => 'error', 'text' => '建立分配項目失敗，請重新操作。'];
                    }
                    $itemStmt->close();
                } else {
                    $conn->rollback();
                    $message = ['type' => 'error', 'text' => '建立分配項目失敗，請重新操作。'];
                }
            } else {
                $conn->rollback();
                $message = ['type' => 'error', 'text' => '建立分配紀錄失敗：資料庫寫入錯誤。'];
            }
            $stmt->close();
        } else {
            $conn->rollback();
            $message = ['type' => 'error', 'text' => '建立分配紀錄失敗：無法準備資料庫語句。'];
        }
        }
    } elseif ($benId > 0) {
        $message = ['type' => 'error', 'text' => '請選擇分配物資並填寫大於 0 的數量。'];
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
$inventoryOptions = array_map(function ($item) {
    return [
        'id' => (int) $item['inventory_id'],
        'name' => (string) $item['item_name'],
        'unit' => (string) $item['unit'],
        'available' => (float) $item['quantity_on_hand'],
    ];
}, array_filter($inventoryModel->getAllInventory(), function ($item) {
    return strtolower((string) $item['status']) !== 'removed' && (float) $item['quantity_on_hand'] > 0;
}));
$inventoryOptionsJson = htmlspecialchars(json_encode(array_values($inventoryOptions), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$distributionByBeneficiary = [];
$distributionHistoryByBeneficiary = [];
$distributionConnection = $db->getConnection();
$distributionResult = $distributionConnection->query(
    "SELECT d.beneficiary_id, d.status, d.notes, d.distribution_date,
            GROUP_CONCAT(
                CONCAT(
                    i.item_name,
                    ' ',
                    TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(di.quantity, 2))),
                    COALESCE(i.unit, '')
                )
                ORDER BY di.detail_id SEPARATOR '、'
            ) AS item_summary
     FROM beneficiary_distributions d
     INNER JOIN distribution_items di ON di.distribution_id = d.distribution_id
     INNER JOIN inventory i ON i.inventory_id = di.inventory_id
     INNER JOIN (
         SELECT beneficiary_id, MAX(distribution_id) AS latest_id
         FROM beneficiary_distributions
         GROUP BY beneficiary_id
     ) latest ON latest.latest_id = d.distribution_id
     GROUP BY d.distribution_id, d.beneficiary_id, d.status, d.notes, d.distribution_date"
);
if ($distributionResult) {
    while ($distribution = $distributionResult->fetch_assoc()) {
        $distributionByBeneficiary[(int) $distribution['beneficiary_id']] = $distribution;
    }
}
$historyResult = $distributionConnection->query(
    "SELECT d.beneficiary_id, d.distribution_date, d.status, d.notes,
            i.item_name, i.unit, di.quantity
     FROM beneficiary_distributions d
     INNER JOIN distribution_items di ON di.distribution_id = d.distribution_id
     INNER JOIN inventory i ON i.inventory_id = di.inventory_id
     ORDER BY d.distribution_date DESC, di.detail_id ASC"
);
if ($historyResult) {
    while ($history = $historyResult->fetch_assoc()) {
        $beneficiaryId = (int) $history['beneficiary_id'];
        $distributionHistoryByBeneficiary[$beneficiaryId][] = [
            'date' => date('Y-m-d H:i', strtotime($history['distribution_date'])),
            'item' => (string) $history['item_name'],
            'quantity' => (float) $history['quantity'],
            'unit' => (string) ($history['unit'] ?? ''),
            'status' => (string) $history['status'],
            'notes' => (string) ($history['notes'] ?? ''),
        ];
    }
}

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

    <div class="card-body beneficiaries-table-body">
        <?php if (!empty($beneficiaries)): ?>
            <table class="data-table beneficiaries-table">
                <thead>
                    <tr>
                        <th>代碼</th>
                        <th>姓名</th>
                        <th>聯繫方式</th>
                        <th>家庭成員</th>
                        <th>收入級別</th>
                        <th>註冊日期</th>
                        <th>狀態</th>
                        <th>分配紀錄</th>
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
                        $distribution = $distributionByBeneficiary[(int) $beneficiary['beneficiary_id']] ?? null;
                        $distributionHistory = $distributionHistoryByBeneficiary[(int) $beneficiary['beneficiary_id']] ?? [];
                        $distributionStatusLabels = [
                            'pending' => '已分配',
                            'approved' => '已批准',
                            'completed' => '已完成',
                            'cancelled' => '已取消',
                        ];
                        $statusLabels = [
                            'active' => '活躍',
                            'inactive' => '非活躍',
                            'suspended' => '暫停',
                        ];
                        ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($beneficiary['beneficiary_code']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($fullName); ?></strong></td>
                            <td><?php echo htmlspecialchars($contact); ?></td>
                            <td><?php echo (int) ($beneficiary['family_size'] ?? 0); ?></td>
                            <td><?php echo $incomeLabels[$income] ?? htmlspecialchars($beneficiary['income_level']); ?></td>
                            <td><?php echo !empty($beneficiary['registration_date']) ? date('Y-m-d', strtotime($beneficiary['registration_date'])) : '-'; ?></td>
                            <td><span class="status status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabels[$status] ?? $beneficiary['status']); ?></span></td>
                            <td>
                                <?php if ($distribution): ?>
                                    <strong><?php echo htmlspecialchars($distribution['item_summary'] ?? ''); ?></strong>
                                    <small class="distribution-notes">
                                        <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($distribution['distribution_date']))); ?>
                                        ・<?php echo htmlspecialchars($distributionStatusLabels[$distribution['status']] ?? $distribution['status']); ?>
                                    </small>
                                    <?php if (!empty($distribution['notes'])): ?><small class="distribution-notes"><?php echo htmlspecialchars($distribution['notes']); ?></small><?php endif; ?>
                                    <button type="button" class="btn btn-secondary btn-sm distribution-history-button"
                                        data-action="distribution-history"
                                        data-full-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-history="<?php echo htmlspecialchars(json_encode($distributionHistory, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">查看歷史</button>
                                <?php else: ?>
                                    <span class="muted-text">尚未分配</span>
                                <?php endif; ?>
                            </td>
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
                                    <button type="button" class="btn btn-secondary btn-sm" data-action="assign" data-beneficiary-id="<?php echo (int) $beneficiary['beneficiary_id']; ?>" data-full-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>" data-inventory-options="<?php echo $inventoryOptionsJson; ?>">
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
