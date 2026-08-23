<?php
/**
 * 數據分析與報表
 */

if (!in_array($currentUser['role'] ?? '', ['admin', 'foodbank_staff'], true)) {
    echo '<div class="alert alert-error">只有食物銀行官方人員可以查看數據分析。</div>';
    return;
}

$connection = $db->getConnection();
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$startDateEscaped = $connection->real_escape_string($startDate);
$endDateEscaped = $connection->real_escape_string($endDate);

$donationStats = [];
$result = $connection->query("SELECT status, COUNT(*) AS total FROM donations WHERE donation_date BETWEEN '{$startDateEscaped} 00:00:00' AND '{$endDateEscaped} 23:59:59' GROUP BY status");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $donationStats[$row['status']] = (int) $row['total'];
    }
}

$deliveryStats = ['open' => 0, 'claimed' => 0, 'picked_up' => 0, 'delivered' => 0, 'exception' => 0, 'cancelled' => 0];
$result = $connection->query("SELECT status, COUNT(*) AS total FROM deliveries WHERE created_at BETWEEN '{$startDateEscaped} 00:00:00' AND '{$endDateEscaped} 23:59:59' GROUP BY status");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $deliveryStats[$row['status']] = (int) $row['total'];
    }
}
$deliveryTotal = array_sum($deliveryStats);
$matchRate = $deliveryTotal > 0 ? round($deliveryStats['delivered'] / $deliveryTotal * 100, 1) : 0;

$volunteerRanking = [];
$result = $connection->query(
    "SELECT u.full_name, COUNT(DISTINCT d.delivery_id) AS delivery_count, COALESCE(SUM(pt.points), 0) AS total_points
     FROM users u
     LEFT JOIN deliveries d ON d.volunteer_id = u.user_id AND d.status = 'delivered'
     LEFT JOIN point_transactions pt ON pt.user_id = u.user_id AND pt.transaction_type = 'earned'
     WHERE u.role = 'volunteer'
     GROUP BY u.user_id
     ORDER BY total_points DESC
     LIMIT 10"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $volunteerRanking[] = $row;
    }
}

$lowStock = [];
$result = $connection->query("SELECT item_name, quantity_on_hand, reorder_level, unit FROM inventory WHERE quantity_on_hand <= reorder_level ORDER BY quantity_on_hand ASC LIMIT 10");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $lowStock[] = $row;
    }
}

if (($_GET['export'] ?? '') === 'volunteers_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="volunteer_ranking.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['志工姓名', '完成配送次數', '累積公益點數']);
    foreach ($volunteerRanking as $row) {
        fputcsv($output, [$row['full_name'], $row['delivery_count'], $row['total_points']]);
    }
    fclose($output);
    exit;
}
?>

<div class="view-header">
    <div>
        <h1 class="view-title">數據分析</h1>
        <p class="view-subtitle">查詢指定期間的物資流動、任務媒合率與志工排行</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="get" class="toolbar-row compact">
            <input type="hidden" name="page" value="reports">
            <div class="form-group"><label>開始日期</label><input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>"></div>
            <div class="form-group"><label>結束日期</label><input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>"></div>
            <button class="btn btn-primary" type="submit">查詢</button>
        </form>
    </div>
</div>

<div class="stats-grid mt-20">
    <div class="stat-card"><h3>待評估捐贈</h3><div class="stat-number"><?php echo $donationStats['pending'] ?? 0; ?></div><p class="stat-label">筆</p></div>
    <div class="stat-card"><h3>已批准捐贈</h3><div class="stat-number"><?php echo $donationStats['approved'] ?? 0; ?></div><p class="stat-label">筆</p></div>
    <div class="stat-card"><h3>任務媒合率</h3><div class="stat-number"><?php echo $matchRate; ?>%</div><p class="stat-label">已配達 / 全部任務</p></div>
    <div class="stat-card"><h3>異常任務</h3><div class="stat-number"><?php echo $deliveryStats['exception']; ?></div><p class="stat-label">筆待處理</p></div>
</div>

<div class="card mt-32">
    <div class="card-header">
        <div class="toolbar-row">
            <div><h2>志工配送排行</h2><p class="toolbar-meta">依累積公益點數排序</p></div>
            <div class="toolbar-actions"><a class="btn btn-secondary btn-sm" href="?page=reports&amp;start_date=<?php echo urlencode($startDate); ?>&amp;end_date=<?php echo urlencode($endDate); ?>&amp;export=volunteers_csv"><i class="fas fa-download"></i> 匯出 CSV</a></div>
        </div>
    </div>
    <div class="card-body">
        <?php if ($volunteerRanking): ?>
            <table class="data-table">
                <thead><tr><th>排名</th><th>志工姓名</th><th>完成配送次數</th><th>累積公益點數</th></tr></thead>
                <tbody>
                <?php foreach ($volunteerRanking as $index => $row): ?>
                    <tr><td><?php echo $index + 1; ?></td><td><?php echo htmlspecialchars($row['full_name']); ?></td><td><?php echo (int) $row['delivery_count']; ?></td><td><strong><?php echo (int) $row['total_points']; ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-ranking-star"></i><p>目前尚無志工點數紀錄</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>低庫存項目</h2><p>需優先補貨的物資</p></div>
    <div class="card-body">
        <?php if ($lowStock): ?>
            <table class="data-table">
                <thead><tr><th>物資名稱</th><th>現有數量</th><th>重訂點</th></tr></thead>
                <tbody>
                <?php foreach ($lowStock as $row): ?>
                    <tr><td><?php echo htmlspecialchars($row['item_name']); ?></td><td><?php echo htmlspecialchars($row['quantity_on_hand']); ?> <?php echo htmlspecialchars($row['unit']); ?></td><td><?php echo htmlspecialchars($row['reorder_level']); ?> <?php echo htmlspecialchars($row['unit']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-boxes-stacked"></i><p>目前沒有低庫存項目</p></div>
        <?php endif; ?>
    </div>
</div>
