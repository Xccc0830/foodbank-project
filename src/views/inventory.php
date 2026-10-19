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

$categoryLabels = ['food' => '食品', 'supplies' => '日用品', 'money' => '金錢', 'other' => '其他'];
$inventoryStatusLabels = [
    'pending' => '待評估／待配送',
    'rejected' => '已婉拒',
    'dispatching' => '配送中',
    'arrived' => '已送達待清點',
    'inspected' => '已清點驗收',
];
$inventoryStatusClasses = [
    'pending' => 'pending',
    'rejected' => 'suspended',
    'dispatching' => 'in-transit',
    'arrived' => 'approved',
    'inspected' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_inspected') {
    $donationId = (int) ($_POST['donation_id'] ?? 0);
    $pendingCheck = $connection->query(
        "SELECT n.donation_id FROM donations n
         WHERE n.donation_id = {$donationId} AND n.status <> 'rejected' AND n.current_status <> 'inspection_complete'
           AND EXISTS (SELECT 1 FROM deliveries d WHERE d.donation_id = n.donation_id AND d.status = 'delivered')
           AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.donation_id = n.donation_id AND d.status NOT IN ('delivered', 'cancelled'))"
    );
    if (!$canInspect) {
        $inventoryMessage = ['type' => 'error', 'text' => '您沒有確認清點的權限。'];
    } elseif ($pendingCheck && $pendingCheck->num_rows === 1) {
        $connection->query("UPDATE donations SET current_status = 'inspection_complete', status_updated_at = NOW() WHERE donation_id = {$donationId}");
        $inventoryMessage = ['type' => 'success', 'text' => '已確認清點並完成查收。'];
    } else {
        $inventoryMessage = ['type' => 'error', 'text' => '此捐贈尚未全部送達，或已完成清點。'];
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
            COALESCE(i.item_name, n.item_name) AS item_name, i.brand, COALESCE(i.specification, n.size_description) AS specification,
            COALESCE(i.quantity, n.quantity) AS quantity, COALESCE(i.unit, n.unit) AS unit, COALESCE(i.expiry_date, n.expiry_date) AS expiry_date,
            (SELECT COUNT(*) FROM deliveries d WHERE d.donation_id = n.donation_id) AS delivery_count,
            (SELECT COUNT(*) FROM deliveries d WHERE d.donation_id = n.donation_id AND d.status = 'delivered') AS delivered_count
     FROM donations n
     LEFT JOIN donation_items i ON i.donation_id = n.donation_id
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
        $row['inventory_status'] = 'pending';
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
?>

<div class="view-header">
    <div>
        <h1 class="view-title">物資盤點</h1>
        <p class="view-subtitle">條列捐贈方捐贈的物資內容、品項、數量與狀態，供內部盤點</p>
    </div>
    <a class="btn btn-secondary" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-download"></i> 匯出 CSV</a>
</div>

<?php if ($inventoryMessage): ?>
    <div class="alert alert-<?php echo $inventoryMessage['type'] === 'success' ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($inventoryMessage['text']); ?></div>
<?php endif; ?>

<div class="stats-grid">
    <?php foreach (['arrived', 'dispatching', 'inspected', 'pending'] as $summaryKey): ?>
        <div class="stat-card"><h3><?php echo htmlspecialchars($inventoryStatusLabels[$summaryKey]); ?></h3><div class="stat-number"><?php echo (int) $summary[$summaryKey]; ?></div><p class="stat-label">品項</p></div>
    <?php endforeach; ?>
</div>

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
            <table class="data-table">
                <thead><tr><th>訂單編號</th><th>捐贈方</th><th>物資品項</th><th>分類</th><th>數量</th><th>效期</th><th>捐贈日期</th><th>狀態</th><th>操作</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($row['order_number'] ?? '-'); ?></code></td>
                            <td><?php echo htmlspecialchars($row['donor_name'] ?? '-'); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($row['item_name'] ?? '-'); ?></strong>
                                <?php $detail = trim(($row['brand'] ?? '') . ' ' . ($row['specification'] ?? '')); ?>
                                <?php if ($detail !== ''): ?><br><small><?php echo htmlspecialchars($detail); ?></small><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($categoryLabels[$row['donation_type']] ?? $row['donation_type']); ?></td>
                            <td><?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $row['quantity'], 2, '.', ''), '0'), '.')); ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['expiry_date'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars(substr((string) $row['donation_date'], 0, 10)); ?></td>
                            <td><span class="status status-<?php echo $inventoryStatusClasses[$row['inventory_status']]; ?>"><?php echo htmlspecialchars($inventoryStatusLabels[$row['inventory_status']]); ?></span></td>
                            <td>
                                <?php if ($canInspect && $row['inventory_status'] === 'arrived'): ?>
                                    <form method="post" onsubmit="return confirm('確認已清點並檢查現場物資？');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="mark_inspected">
                                        <input type="hidden" name="donation_id" value="<?php echo (int) $row['donation_id']; ?>">
                                        <button class="btn btn-success btn-sm" type="submit">完成清點</button>
                                    </form>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-boxes-stacked"></i><p>沒有符合條件的物資</p></div>
        <?php endif; ?>
    </div>
</div>
