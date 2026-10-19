<?php
/**
 * 後台物資盤點清單：列出捐贈方、物資內容、品項、數量與狀態，供食物銀行內部盤點。
 */

if (($currentUser['role'] ?? '') !== 'foodbank_staff') {
    echo '<div class="alert alert-error">只有食物銀行人員可以查看物資盤點。</div>';
    return;
}

$connection = $db->getConnection();
$canInspect = $authService->can('inventory.inspect');
$inventoryMessage = null;

require_once BASE_PATH . '/src/models/InventoryReceiptModel.php';
require_once BASE_PATH . '/src/models/ItemCategoryModel.php';
$receiptModel = new InventoryReceiptModel($connection);
$itemCategoryModel = new ItemCategoryModel();
$receiptCategories = $itemCategoryModel->getAllCategories();
$activeReceiptCategories = array_values(array_filter($receiptCategories, static function ($category) {
    return $category['status'] === 'active';
}));
$receiptCategoryLabels = [];
foreach ($receiptCategories as $receiptCategory) {
    $receiptCategoryLabels[$receiptCategory['category_name']] = $receiptCategory['category_name'];
}
$legacyReceiptCategoryLabels = ['food' => '食品', 'supplies' => '日用品', 'money' => '金錢', 'other' => '其他'];
$receiptKeyword = trim((string) ($_GET['stock_q'] ?? ''));
$receiptSummary = $receiptModel->getSummaryCounts();
$stockRows = $receiptModel->getStockSummary($receiptKeyword);
$allStockRows = $receiptModel->getStockSummary();
$receiptRows = $receiptModel->getRecentReceipts($receiptKeyword);
$receiptStorageError = !$receiptModel->isReady() || $receiptModel->hasError();

$categoryLabels = ['food' => '食品', 'supplies' => '日用品', 'money' => '金錢', 'other' => '其他'];
$inventoryStatusLabels = [
    'rejected' => '已婉拒',
    'dispatching' => '配送中',
    'arrived' => '已送達待清點',
    'inspected' => '已清點驗收',
];
$inventoryStatusClasses = [
    'rejected' => 'suspended',
    'dispatching' => 'in-transit',
    'arrived' => 'approved',
    'inspected' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['receive_stock', 'manage_stock'], true)) {
    if (!$canInspect) {
        $inventoryMessage = ['type' => 'error', 'text' => '您沒有登記物資進出庫的權限。'];
    } else {
        $movementType = ($_POST['action'] ?? '') === 'receive_stock' ? 'in' : ($_POST['movement_type'] ?? 'in');
        $_POST['movement_type'] = $movementType;
        $movementResult = $receiptModel->recordManualMovement($_POST, (int) ($currentUser['user_id'] ?? 0));
        if ($movementResult['status'] === 'success') {
            $inventoryMessage = [
                'type' => 'success',
                'text' => $movementType === 'out' ? '物資已成功取出並更新庫存。' : '物資入庫紀錄已新增。',
            ];
        } elseif ($movementResult['status'] === 'insufficient_stock') {
            $availableText = rtrim(rtrim(number_format((float) $movementResult['available'], 2, '.', ''), '0'), '.');
            $inventoryMessage = ['type' => 'warning', 'text' => "取出數量超過庫存，目前僅剩 {$availableText} " . ($_POST['unit'] ?? '') . '，未建立取出紀錄。'];
        } else {
            $inventoryMessage = ['type' => 'error', 'text' => $receiptModel->hasError()
                ? '無法儲存物資進出庫紀錄，請稍後再試或聯絡系統管理員。'
                : '新增失敗，請確認品項、分類、數量、單位及日期格式正確。'];
        }
        $receiptSummary = $receiptModel->getSummaryCounts();
        $stockRows = $receiptModel->getStockSummary($receiptKeyword);
        $allStockRows = $receiptModel->getStockSummary();
        $receiptRows = $receiptModel->getRecentReceipts($receiptKeyword);
        $receiptStorageError = !$receiptModel->isReady() || $receiptModel->hasError();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_inspected') {
    $donationId = (int) ($_POST['donation_id'] ?? 0);
    if (!$canInspect) {
        $inventoryMessage = ['type' => 'error', 'text' => '您沒有確認清點的權限。'];
    } elseif ($receiptModel->recordDonationInspection(
        $donationId,
        (int) ($currentUser['user_id'] ?? 0),
        is_array($_POST['categories'] ?? null) ? $_POST['categories'] : []
    )) {
        $inventoryMessage = ['type' => 'success', 'text' => '已完成清點，捐贈物資已自動登記入庫。'];
        $receiptSummary = $receiptModel->getSummaryCounts();
        $stockRows = $receiptModel->getStockSummary($receiptKeyword);
        $allStockRows = $receiptModel->getStockSummary();
        $receiptRows = $receiptModel->getRecentReceipts($receiptKeyword);
    } else {
        $inventoryMessage = ['type' => 'error', 'text' => $receiptModel->hasError()
            ? '完成清點時無法寫入入庫紀錄，狀態未變更，請稍後再試或聯絡系統管理員。'
            : '請為每項物資選擇啟用中的分類，並確認此捐贈已全部送達且尚未完成清點。'];
    }
}

$statusFilter = $_GET['status'] ?? '';
$keyword = trim((string) ($_GET['q'] ?? ''));
$startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'] ?? '') ? $_GET['start_date'] : '';
$endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'] ?? '') ? $_GET['end_date'] : '';

$conditions = [];
if ($startDate !== '') {
    $conditions[] = "DATE(n.donation_date) >= '" . $connection->real_escape_string($startDate) . "'";
}
if ($endDate !== '') {
    $conditions[] = "DATE(n.donation_date) <= '" . $connection->real_escape_string($endDate) . "'";
}
if ($keyword !== '') {
    $like = $connection->real_escape_string($keyword);
    $conditions[] = "(n.donor_name LIKE '%{$like}%' OR n.order_number LIKE '%{$like}%' OR COALESCE(i.item_name, n.item_name) LIKE '%{$like}%')";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$rows = [];
$result = $connection->query(
    "SELECT n.donation_id, n.order_number, n.donor_name, n.donation_type, n.donation_date, n.status AS donation_status, n.current_status,
            i.item_id, ir.category AS receipt_category,
            COALESCE(i.item_name, n.item_name) AS item_name, i.brand, COALESCE(i.specification, n.size_description) AS specification,
            COALESCE(i.quantity, n.quantity) AS quantity, COALESCE(i.unit, n.unit) AS unit, COALESCE(i.expiry_date, n.expiry_date) AS expiry_date,
            (SELECT COUNT(*) FROM donation_items di WHERE di.donation_id = n.donation_id) AS donation_item_count,
            (SELECT COUNT(*) FROM deliveries d WHERE d.donation_id = n.donation_id) AS delivery_count,
            (SELECT COUNT(*) FROM deliveries d WHERE d.donation_id = n.donation_id AND d.status = 'delivered') AS delivered_count
     FROM donations n
     LEFT JOIN donation_items i ON i.donation_id = n.donation_id
     LEFT JOIN inventory_receipts ir ON ir.source_donation_id = n.donation_id AND ir.source_item_id = i.item_id
     {$where}
     ORDER BY n.donation_date DESC, n.donation_id DESC, i.item_id ASC"
);
while ($result && ($row = $result->fetch_assoc())) {
    if ($row['donation_status'] === 'rejected') {
        $row['inventory_status'] = 'rejected';
    } elseif ($row['current_status'] === 'inspection_complete') {
        $row['inventory_status'] = 'inspected';
    } elseif ((int) $row['delivery_count'] > 0 && (int) $row['delivered_count'] === (int) $row['delivery_count']) {
        $row['inventory_status'] = 'arrived';
    } elseif ((int) $row['delivery_count'] > 0) {
        $row['inventory_status'] = 'dispatching';
    } else {
        continue;
    }
    if ($statusFilter === '' || $statusFilter === $row['inventory_status']) {
        $rows[] = $row;
    }
}

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['訂單編號', '捐贈方', '物資品項', '品牌', '規格', '分類', '數量', '單位', '效期', '捐贈日期', '狀態']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['order_number'], $row['donor_name'], $row['item_name'], $row['brand'], $row['specification'],
            $categoryLabels[$row['donation_type']] ?? $row['donation_type'], $row['quantity'], $row['unit'],
            $row['expiry_date'], $row['donation_date'], $inventoryStatusLabels[$row['inventory_status']],
        ]);
    }
    fclose($output);
    exit;
}

$summary = array_fill_keys(array_keys($inventoryStatusLabels), 0);
foreach ($rows as $row) {
    $summary[$row['inventory_status']]++;
}
$rowsByDonation = [];
foreach ($rows as $row) {
    $rowsByDonation[(int) $row['donation_id']][] = $row;
}
?>

<div class="view-header">
    <div>
        <h1 class="view-title">物資盤點</h1>
        <p class="view-subtitle">條列捐贈方捐贈的物資內容、品項、數量與狀態，供內部盤點</p>
    </div>
    <a class="btn btn-secondary" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-download"></i> 匯出 CSV</a>
</div>

<?php if ($inventoryMessage): ?>
    <div class="alert alert-<?php echo $inventoryMessage['type'] === 'success' ? 'success' : ($inventoryMessage['type'] === 'warning' ? 'warning' : 'error'); ?>"><?php echo htmlspecialchars($inventoryMessage['text']); ?></div>
<?php endif; ?>
<?php if ($receiptStorageError): ?>
    <div class="alert alert-error">入庫資料目前無法讀取，請確認資料庫設定或聯絡系統管理員。</div>
<?php endif; ?>

<div class="stats-grid">
    <?php foreach (['arrived', 'dispatching', 'inspected'] as $summaryKey): ?>
        <div class="stat-card"><h3><?php echo htmlspecialchars($inventoryStatusLabels[$summaryKey]); ?></h3><div class="stat-number"><?php echo (int) $summary[$summaryKey]; ?></div><p class="stat-label">品項</p></div>
    <?php endforeach; ?>
</div>

<section class="card mt-32 stock-receipt-section">
    <div class="card-header stock-receipt-heading">
        <div>
            <h2>食物銀行物資進出庫</h2>
            <p>登記物資入庫與取出，庫存會即時依進出紀錄計算。</p>
        </div>
        <div class="stock-receipt-stats">
            <div><strong><?php echo (int) $receiptSummary['item_count']; ?></strong><span>物資品項</span></div>
            <div><strong><?php echo (int) $receiptSummary['receipt_count']; ?></strong><span>入庫筆數</span></div>
            <div><strong><?php echo (int) $receiptSummary['today_count']; ?></strong><span>今日入庫</span></div>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$activeReceiptCategories): ?>
            <div class="alert alert-error">目前沒有啟用中的物資分類，請先至「物資分類」新增或啟用分類，才能登記入庫或完成清點。</div>
        <?php endif; ?>
        <?php if ($canInspect): ?>
            <form method="post" class="stock-receipt-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="manage_stock">
                <label class="form-group">
                    <span>異動方式 <b aria-hidden="true">*</b></span>
                    <select name="movement_type" id="stock-movement-type" required>
                        <option value="in">入庫</option>
                        <option value="out">取出</option>
                    </select>
                </label>
                <label class="form-group">
                    <span id="stock-item-label">物資名稱 <b aria-hidden="true">*</b></span>
                    <input type="text" name="item_name" id="stock-item-name" list="stock-item-options" maxlength="150" required placeholder="例如：白米">
                    <datalist id="stock-item-options">
                        <?php foreach ($allStockRows as $stock): ?>
                            <option value="<?php echo htmlspecialchars($stock['item_name']); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <select id="stock-existing-item" aria-label="選擇要取出的庫存物資" style="display:none">
                        <option value="">選擇現有庫存</option>
                        <?php foreach ($allStockRows as $stockIndex => $stock): ?>
                            <option value="<?php echo (int) $stockIndex; ?>"
                                data-item-name="<?php echo htmlspecialchars($stock['item_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-category="<?php echo htmlspecialchars($stock['category'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-unit="<?php echo htmlspecialchars($stock['unit'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($stock['item_name'] . '｜' . ($receiptCategoryLabels[$stock['category']] ?? $stock['category']) . '｜剩餘 ' . rtrim(rtrim(number_format((float) $stock['total_quantity'], 2, '.', ''), '0'), '.') . ' ' . $stock['unit']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-group">
                    <span>物資分類 <b aria-hidden="true">*</b></span>
                    <select name="category" id="stock-item-category" required>
                        <option value="">請選擇分類</option>
                        <?php foreach ($receiptCategories as $receiptCategory): ?>
                            <option value="<?php echo htmlspecialchars($receiptCategory['category_name']); ?>" data-active="<?php echo $receiptCategory['status'] === 'active' ? '1' : '0'; ?>" <?php echo $receiptCategory['status'] !== 'active' ? 'disabled' : ''; ?>><?php echo htmlspecialchars($receiptCategory['category_name']); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($allStockRows as $stock): ?>
                            <?php if (!array_key_exists($stock['category'], $receiptCategoryLabels)): ?>
                                <option value="<?php echo htmlspecialchars($stock['category']); ?>" data-active="0" disabled><?php echo htmlspecialchars($stock['category']); ?>（已移除分類）</option>
                                <?php $receiptCategoryLabels[$stock['category']] = $stock['category']; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-group">
                    <span>數量 <b aria-hidden="true">*</b></span>
                    <input type="number" name="quantity" id="stock-movement-quantity" min="0.01" step="0.01" required placeholder="0">
                </label>
                <label class="form-group">
                    <span>單位 <b aria-hidden="true">*</b></span>
                    <input type="text" name="unit" id="stock-item-unit" maxlength="30" required value="件" placeholder="件、公斤、箱">
                </label>
                <label class="form-group">
                    <span id="stock-date-label">收受日期 <b aria-hidden="true">*</b></span>
                    <input type="date" name="received_date" required value="<?php echo date('Y-m-d'); ?>">
                </label>
                <label class="form-group" id="stock-expiry-field">
                    <span>有效期限</span>
                    <input type="date" name="expiry_date">
                </label>
                <label class="form-group" id="stock-donor-field">
                    <span>捐贈來源／捐贈者</span>
                    <input type="text" name="donor_name" maxlength="150" placeholder="公司、商家或個人">
                </label>
                <label class="form-group stock-receipt-notes">
                    <span>備註</span>
                    <textarea name="notes" rows="2" placeholder="批次、保存方式或其他說明"></textarea>
                </label>
                <div class="stock-receipt-submit">
                    <button class="btn btn-primary" id="stock-submit-button" type="submit"><i class="fas fa-box-open"></i> 登記入庫</button>
                </div>
                <p id="stock-availability-warning" class="stock-availability-warning" role="status" aria-live="polite"></p>
            </form>
        <?php else: ?>
            <div class="alert alert-error">您沒有登記物資入庫的權限。</div>
        <?php endif; ?>

        <div class="stock-ledger-heading">
            <div>
                <h3>目前庫存</h3>
                <p>庫存為 0 的物資會自動從清單隱藏，進出紀錄仍保留。</p>
            </div>
            <form method="get" class="toolbar-row">
                <input type="hidden" name="page" value="inventory">
                <input type="search" name="stock_q" value="<?php echo htmlspecialchars($receiptKeyword); ?>" placeholder="搜尋物資、捐贈者或存放位置">
                <button class="btn btn-secondary btn-sm" type="submit">搜尋</button>
            </form>
        </div>
        <?php if ($stockRows): ?>
            <div class="stock-table-wrap">
                <table class="stock-table">
                    <thead><tr><th>物資品項</th><th>分類</th><th class="stock-number">現有庫存</th><th>入庫批次</th><th>最近異動</th><th>最近效期</th><?php if ($canInspect): ?><th>操作</th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($stockRows as $stock): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($stock['item_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($receiptCategoryLabels[$stock['category']] ?? $legacyReceiptCategoryLabels[$stock['category']] ?? $stock['category']); ?></td>
                                <td class="stock-number"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $stock['total_quantity'], 2, '.', ''), '0'), '.')); ?> <?php echo htmlspecialchars($stock['unit']); ?></td>
                                <td><?php echo (int) $stock['receipt_count']; ?> 批</td>
                                <td class="stock-nowrap"><?php echo htmlspecialchars($stock['latest_received_date']); ?></td>
                                <td class="stock-nowrap"><?php echo htmlspecialchars($stock['next_expiry_date'] ?? '未提供'); ?></td>
                                <?php if ($canInspect): ?>
                                    <td>
                                        <button
                                            class="btn btn-secondary btn-sm stock-issue-button"
                                            type="button"
                                            data-item-name="<?php echo htmlspecialchars($stock['item_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-category="<?php echo htmlspecialchars($stock['category'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-unit="<?php echo htmlspecialchars($stock['unit'], ENT_QUOTES, 'UTF-8'); ?>"
                                        >取出</button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state stock-empty"><i class="fas fa-box-open"></i><p><?php echo $receiptKeyword === '' ? '目前沒有庫存；新增入庫紀錄即可建立庫存清冊。' : '找不到符合條件的庫存物資。'; ?></p></div>
        <?php endif; ?>

        <details class="stock-history">
            <summary>最近進出明細（最多 100 筆）</summary>
            <?php if ($receiptRows): ?>
                <div class="stock-table-wrap">
                    <table class="stock-table">
                        <thead><tr><th>日期</th><th>異動</th><th>物資品項</th><th>分類</th><th class="stock-number">數量</th><th>捐贈來源</th><th>存放位置</th><th>效期／備註</th></tr></thead>
                        <tbody>
                            <?php foreach ($receiptRows as $receipt): ?>
                                <tr>
                                    <td class="stock-nowrap"><?php echo htmlspecialchars($receipt['received_date']); ?></td>
                                    <td><?php echo $receipt['movement_type'] === 'out' ? '取出' : '入庫'; ?></td>
                                    <td><strong><?php echo htmlspecialchars($receipt['item_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($receiptCategoryLabels[$receipt['category']] ?? $legacyReceiptCategoryLabels[$receipt['category']] ?? $receipt['category']); ?></td>
                                    <td class="stock-number"><?php echo $receipt['movement_type'] === 'out' ? '-' : '+'; ?><?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $receipt['quantity'], 2, '.', ''), '0'), '.')); ?> <?php echo htmlspecialchars($receipt['unit']); ?></td>
                                    <td><?php echo htmlspecialchars($receipt['donor_name'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($receipt['storage_location'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($receipt['expiry_date'] ?: '未提供'); ?><?php if ($receipt['notes']): ?><small class="stock-note"><?php echo htmlspecialchars($receipt['notes']); ?></small><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="stock-history-empty">尚無物資進出明細。</p>
            <?php endif; ?>
        </details>
    </div>
    <style>
        .stock-receipt-heading { display:flex; justify-content:space-between; align-items:center; gap:24px; flex-wrap:wrap; }
        .stock-receipt-heading h2, .stock-ledger-heading h3 { margin:0; color:#111827; }
        .stock-receipt-heading p, .stock-ledger-heading p { margin:5px 0 0; color:#6b7280; font-size:13px; }
        .stock-receipt-stats { display:flex; gap:20px; }
        .stock-receipt-stats div { display:flex; flex-direction:column; min-width:72px; text-align:center; }
        .stock-receipt-stats strong { color:#1f2937; font-size:21px; }
        .stock-receipt-stats span { color:#6b7280; font-size:12px; }
        .stock-receipt-form { display:grid; grid-template-columns:repeat(4, minmax(150px, 1fr)); gap:16px; padding:4px 0 24px; border-bottom:1px solid #e5e7eb; }
        .stock-receipt-form .form-group { display:flex; flex-direction:column; gap:7px; margin:0; }
        .stock-receipt-form .form-group > span { color:#374151; font-size:13px; font-weight:600; }
        .stock-receipt-form .form-group b { color:#dc2626; }
        .stock-receipt-form input, .stock-receipt-form select, .stock-receipt-form textarea { width:100%; min-height:40px; padding:9px 11px; border:1px solid #d1d5db; border-radius:7px; background:#fff; color:#111827; }
        .stock-receipt-form textarea { resize:vertical; }
        .stock-receipt-notes { grid-column:span 3; }
        .stock-receipt-submit { display:flex; align-items:end; }
        .stock-availability-warning { grid-column:1 / -1; margin:0; color:#b45309; font-size:13px; font-weight:600; }
        .stock-availability-warning:empty { display:none; }
        .stock-availability-warning.is-error { color:#b91c1c; }
        .stock-availability-warning.is-ok { color:#047857; }
        .stock-ledger-heading { display:flex; justify-content:space-between; align-items:end; gap:16px; margin:24px 0 14px; flex-wrap:wrap; }
        .stock-table-wrap { overflow-x:auto; border:1px solid #e5e7eb; border-radius:8px; }
        .stock-table { width:100%; min-width:850px; border-collapse:collapse; }
        .stock-table th, .stock-table td { padding:12px 14px; text-align:left; border-bottom:1px solid #f1f3f5; vertical-align:top; }
        .stock-table th { background:#f9fafb; color:#4b5563; font-size:12px; white-space:nowrap; }
        .stock-table tbody tr:hover { background:#f9fafb; }
        .stock-table tbody tr:last-child td { border-bottom:0; }
        .stock-table td { color:#374151; font-size:13px; }
        .stock-table td strong { color:#111827; }
        .stock-table .stock-number { text-align:center; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .stock-nowrap { white-space:nowrap; }
        .stock-note { display:block; max-width:260px; margin-top:4px; color:#6b7280; white-space:normal; }
        .stock-empty { padding:22px 10px; }
        .stock-history { margin-top:20px; border-top:1px solid #e5e7eb; padding-top:16px; }
        .stock-history summary { color:#374151; font-weight:600; cursor:pointer; }
        .stock-history .stock-table-wrap { margin-top:14px; }
        .stock-history-empty { color:#6b7280; font-size:13px; }
        @media (max-width: 900px) {
            .stock-receipt-form { grid-template-columns:repeat(2, minmax(130px, 1fr)); }
            .stock-receipt-notes { grid-column:span 2; }
        }
        @media (max-width: 540px) {
            .stock-receipt-form { grid-template-columns:1fr; }
            .stock-receipt-notes { grid-column:span 1; }
            .stock-receipt-stats { width:100%; justify-content:space-between; }
        }
    </style>
    <script>
        (() => {
            const form = document.querySelector('.stock-receipt-form');
            if (!form) return;

            const movement = document.getElementById('stock-movement-type');
            const itemName = document.getElementById('stock-item-name');
            const existingItem = document.getElementById('stock-existing-item');
            const itemLabel = document.getElementById('stock-item-label');
            const category = document.getElementById('stock-item-category');
            const unit = document.getElementById('stock-item-unit');
            const quantity = document.getElementById('stock-movement-quantity');
            const warning = document.getElementById('stock-availability-warning');
            const expiry = document.getElementById('stock-expiry-field');
            const donor = document.getElementById('stock-donor-field');
            const dateLabel = document.getElementById('stock-date-label');
            const submit = document.getElementById('stock-submit-button');
            const balances = <?php echo json_encode(array_map(static function ($stock) {
                return [
                    'item_name' => $stock['item_name'],
                    'category' => $stock['category'],
                    'unit' => $stock['unit'],
                    'quantity' => (float) $stock['total_quantity'],
                ];
            }, $allStockRows), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

            const matchingBalance = () => balances.find((stock) =>
                stock.item_name === itemName.value.trim()
                && stock.category === category.value
                && stock.unit === unit.value.trim()
            );

            document.querySelectorAll('.stock-issue-button').forEach((button) => {
                button.addEventListener('click', () => {
                    movement.value = 'out';
                    movement.dispatchEvent(new Event('change', { bubbles: true }));
                    const option = Array.from(existingItem.options).find((candidate) =>
                        candidate.dataset.itemName === button.dataset.itemName
                        && candidate.dataset.category === button.dataset.category
                        && candidate.dataset.unit === button.dataset.unit
                    );
                    if (!option) {
                        window.alert('找不到這筆庫存，請重新整理頁面後再試。');
                        return;
                    }
                    existingItem.value = option.value;
                    existingItem.dispatchEvent(new Event('change', { bubbles: true }));
                    quantity.focus();
                    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            });

            const updateMovementForm = () => {
                const takingOut = movement.value === 'out';
                itemName.style.display = takingOut ? 'none' : '';
                itemName.required = !takingOut;
                itemName.disabled = false;
                existingItem.style.display = takingOut ? '' : 'none';
                existingItem.required = takingOut;
                existingItem.disabled = !takingOut;
                itemLabel.textContent = takingOut ? '選擇庫存物資 *' : '物資名稱 *';
                Array.from(category.options).forEach((option) => {
                    option.disabled = option.value === '' || (!takingOut && option.dataset.active !== '1');
                });
                if (!takingOut && category.selectedOptions[0]?.disabled) category.value = '';
                expiry.style.display = takingOut ? 'none' : '';
                donor.style.display = takingOut ? 'none' : '';
                if (takingOut) expiry.querySelector('input').value = '';
                dateLabel.textContent = takingOut ? '取出日期 *' : '收受日期 *';
                submit.innerHTML = takingOut
                    ? '<i class="fas fa-boxes-stacked"></i> 確認取出'
                    : '<i class="fas fa-box-open"></i> 登記入庫';
                if (takingOut) updateSelectedItem();
                updateAvailability();
            };

            const updateSelectedItem = () => {
                const option = existingItem.selectedOptions[0];
                if (!option || !option.dataset.itemName) {
                    itemName.value = '';
                    return;
                }
                itemName.value = option.dataset.itemName;
                category.value = option.dataset.category;
                unit.value = option.dataset.unit;
            };

            const updateAvailability = () => {
                warning.textContent = '';
                warning.className = 'stock-availability-warning';
                if (movement.value !== 'out' || itemName.value.trim() === '') return;

                const stock = matchingBalance();
                const available = stock ? stock.quantity : 0;
                const requested = Number(quantity.value);
                if (!stock) {
                    warning.textContent = '找不到此分類與單位的現有庫存，請確認品項資料。';
                    warning.classList.add('is-error');
                } else if (requested > available) {
                    warning.textContent = `取出數量超過庫存，目前僅剩 ${available} ${stock.unit}。`;
                    warning.classList.add('is-error');
                } else {
                    warning.textContent = `目前可取出 ${available} ${stock.unit}。`;
                    warning.classList.add('is-ok');
                }
            };

            movement.addEventListener('change', updateMovementForm);
            existingItem.addEventListener('change', () => {
                updateSelectedItem();
                updateAvailability();
            });
            [itemName, category, unit, quantity].forEach((field) => {
                field.addEventListener('input', updateAvailability);
                field.addEventListener('change', updateAvailability);
            });
            form.addEventListener('submit', (event) => {
                updateAvailability();
                if (movement.value === 'out') {
                    const stock = matchingBalance();
                    if (!stock || Number(quantity.value) > stock.quantity) {
                        event.preventDefault();
                        window.alert(warning.textContent || '取出數量超過目前庫存。');
                    }
                }
            });
            updateMovementForm();
        })();
    </script>
</section>

<div class="card mt-32">
    <div class="card-header">
        <form method="get" class="toolbar-row">
            <input type="hidden" name="page" value="inventory">
            <input type="search" name="q" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="搜尋捐贈方、品項、訂單編號">
            <select name="status" class="filter-select" aria-label="篩選狀態">
                <option value="">全部狀態</option>
                <?php foreach ($inventoryStatusLabels as $statusValue => $statusLabel): ?>
                    <option value="<?php echo $statusValue; ?>" <?php echo $statusFilter === $statusValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusLabel); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" aria-label="開始日期">
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" aria-label="結束日期">
            <button class="btn btn-primary btn-sm" type="submit">篩選</button>
        </form>
    </div>
    <div class="card-body">
        <?php if ($rows): ?>
            <div class="inventory-rows-wrap">
                <div class="inventory-row inventory-row-header" aria-hidden="true">
                    <span>訂單編號</span>
                    <span>捐贈方</span>
                    <span>物資品項</span>
                    <span>分類</span>
                    <span>數量</span>
                    <span>捐贈日期</span>
                    <span>效期</span>
                    <span>狀態</span>
                    <span>操作</span>
                </div>
                <div class="inventory-row-list">
                    <?php foreach ($rowsByDonation as $donationId => $donationRows): ?>
                        <?php $isArrived = $donationRows[0]['inventory_status'] === 'arrived'; ?>
                        <?php $hasAllItems = count($donationRows) === (int) $donationRows[0]['donation_item_count']; ?>
                        <?php $needsInspection = $canInspect && $isArrived && $hasAllItems; ?>
                        <?php if ($needsInspection): ?>
                            <form method="post" class="inventory-inspection-form" onsubmit="return confirm('確認各項分類並完成清點？物資會自動登記入庫。');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="mark_inspected">
                                <input type="hidden" name="donation_id" value="<?php echo (int) $donationId; ?>">
                        <?php endif; ?>
                        <?php foreach ($donationRows as $itemIndex => $row): ?>
                            <article class="inventory-row">
                                <span class="inventory-order-number"><code><?php echo htmlspecialchars($row['order_number'] ?? '-'); ?></code></span>
                                <span class="inventory-donor"><?php echo htmlspecialchars($row['donor_name'] ?? '-'); ?></span>
                                <span class="inventory-item">
                                    <strong><?php echo htmlspecialchars($row['item_name'] ?? '-'); ?></strong>
                                    <?php $detail = trim(($row['brand'] ?? '') . ' ' . ($row['specification'] ?? '')); ?>
                                    <?php if ($detail !== ''): ?><small><?php echo htmlspecialchars($detail); ?></small><?php endif; ?>
                                </span>
                                <span class="inventory-category">
                                    <?php if ($needsInspection && !empty($row['item_id'])): ?>
                                        <select name="categories[<?php echo (int) $row['item_id']; ?>]" aria-label="<?php echo htmlspecialchars($row['item_name'] . '分類'); ?>" required>
                                            <option value="">選分類</option>
                                            <?php foreach ($activeReceiptCategories as $receiptCategory): ?>
                                                <option value="<?php echo (int) $receiptCategory['category_id']; ?>"><?php echo htmlspecialchars($receiptCategory['category_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif (!empty($row['receipt_category'])): ?>
                                        <?php echo htmlspecialchars($receiptCategoryLabels[$row['receipt_category']] ?? $legacyReceiptCategoryLabels[$row['receipt_category']] ?? $row['receipt_category']); ?>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($categoryLabels[$row['donation_type']] ?? $row['donation_type']); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="inventory-quantity"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $row['quantity'], 2, '.', ''), '0'), '.')); ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?></span>
                                <span class="inventory-date"><?php echo htmlspecialchars(substr((string) $row['donation_date'], 0, 10)); ?></span>
                                <span class="inventory-date"><?php echo htmlspecialchars($row['expiry_date'] ?? '-'); ?></span>
                                <span class="inventory-status"><span class="status status-<?php echo $inventoryStatusClasses[$row['inventory_status']]; ?>"><?php echo htmlspecialchars($inventoryStatusLabels[$row['inventory_status']]); ?></span></span>
                                <span class="inventory-action">
                                    <?php if ($needsInspection && $itemIndex === count($donationRows) - 1): ?>
                                        <button class="btn btn-success btn-sm" type="submit">完成清點並入庫</button>
                                    <?php elseif ($canInspect && $isArrived && !$hasAllItems && $itemIndex === count($donationRows) - 1): ?>
                                        <a class="btn btn-secondary btn-sm" href="?page=inventory">清除篩選後清點</a>
                                    <?php else: ?>-<?php endif; ?>
                                </span>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($needsInspection): ?>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-boxes-stacked"></i><p>沒有符合條件的物資</p></div>
        <?php endif; ?>
    </div>

    <style>
        .inventory-rows-wrap { width: 100%; overflow-x: auto; padding: 2px; }
        .inventory-row {
            display: grid;
            grid-template-columns: 205px 150px minmax(190px, 1.4fr) 155px 105px 125px 125px 190px 175px;
            align-items: center;
            gap: 16px;
            min-width: 1530px;
            padding: 16px 18px;
            text-align: left;
        }
        .inventory-row-header {
            color: #374151;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 700;
        }
        .inventory-row-list { display: grid; gap: 10px; padding-top: 10px; }
        .inventory-inspection-form { display: grid; gap: 10px; }
        .inventory-row:not(.inventory-row-header) {
            min-height: 84px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .05);
            transition: background-color .15s ease, box-shadow .15s ease;
        }
        .inventory-row:not(.inventory-row-header):hover {
            background: #f9fafb;
            box-shadow: 0 3px 10px rgba(15, 23, 42, .08);
        }
        .inventory-row > span { min-width: 0; white-space: nowrap; }
        .inventory-category select { width: 100%; min-width: 0; padding: 7px 8px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #374151; }
        .inventory-order-number, .inventory-date { white-space: nowrap; }
        .inventory-order-number code { color: #635bff; font-weight: 700; }
        .inventory-donor, .inventory-item, .inventory-category { text-align: left; }
        .inventory-item { display: flex; flex-direction: column; gap: 4px; }
        .inventory-item strong { color: #111827; font-weight: 600; }
        .inventory-item small { color: #6b7280; font-size: 12px; }
        .inventory-row > :nth-child(5) { display: flex; justify-content: center; text-align: center; }
        .inventory-quantity { white-space: nowrap; }
        .inventory-status, .inventory-action { display: flex; min-width: 0; justify-content: center; white-space: nowrap; }
        .inventory-action { justify-self: stretch; width: 100%; }
        .inventory-action .btn { display: inline-flex; flex: 0 1 auto; box-sizing: border-box; width: auto; max-width: 100%; min-width: 0; margin: 0 auto; transform: translateX(-24px); padding: 6px 10px; font-size: 11px; line-height: 1.3; white-space: normal; overflow-wrap: anywhere; text-align: center; }
    </style>
</div>
