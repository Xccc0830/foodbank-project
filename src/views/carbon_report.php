<?php
/**
 * 永續效益報表
 */

$isOfficial = ($currentUser['role'] ?? '') === 'foodbank_staff';
$isEnterpriseMember = ($currentUser['role'] ?? '') === 'member' && ($currentUser['member_type'] ?? '') === 'enterprise';
if (!$isOfficial && !$isEnterpriseMember) {
    echo '<div class="alert alert-error">只有管理介面或企業會員可以查看永續報表。</div>';
    return;
}

$connection = $db->getConnection();

// 假設係數：食物廢棄物避免掩埋排放 2.5 kgCO2e/kg；汽車運輸 0.192 kgCO2e/km；機車運輸 0.081 kgCO2e/km
$foodWasteFactor = 2.5;
$carFactor = 0.192;
$motorcycleFactor = 0.081;

if ($isEnterpriseMember) {
    $enterpriseId = (int) ($currentUser['user_id'] ?? 0);
    $selectedYear = (int) ($_GET['year'] ?? date('Y'));
    if ($selectedYear < 2020 || $selectedYear > ((int) date('Y') + 1)) {
        $selectedYear = (int) date('Y');
    }
    $yearStart = $selectedYear . '-01-01 00:00:00';
    $yearEnd = ($selectedYear + 1) . '-01-01 00:00:00';
    $yearStartEscaped = $connection->real_escape_string($yearStart);
    $yearEndEscaped = $connection->real_escape_string($yearEnd);

    $summary = [
        'donation_quantity' => 0,
        'donation_count' => 0,
        'beneficiary_count' => 0,
        'delivery_count' => 0,
        'volunteer_count' => 0,
        'activity_count' => 0,
    ];
    $summaryResult = $connection->query(
        "SELECT COALESCE(SUM(quantity), 0) AS donation_quantity, COUNT(*) AS donation_count
         FROM donations
         WHERE donor_id = {$enterpriseId}
           AND donation_date >= '{$yearStartEscaped}'
           AND donation_date < '{$yearEndEscaped}'"
    );
    if ($summaryResult) {
        $summary = array_merge($summary, $summaryResult->fetch_assoc() ?: []);
    }

    $deliverySummaryResult = $connection->query(
        "SELECT COUNT(DISTINCT d.delivery_id) AS delivery_count,
                COUNT(DISTINCT CASE WHEN n.beneficiary_id IS NOT NULL THEN n.beneficiary_id END) AS beneficiary_count,
                COUNT(DISTINCT d.volunteer_id) AS volunteer_count
         FROM deliveries d
         INNER JOIN donations n ON n.donation_id = d.donation_id
         WHERE n.donor_id = {$enterpriseId}
           AND d.status = 'delivered'
           AND d.delivered_at >= '{$yearStartEscaped}'
           AND d.delivered_at < '{$yearEndEscaped}'"
    );
    if ($deliverySummaryResult) {
        $summary = array_merge($summary, $deliverySummaryResult->fetch_assoc() ?: []);
    }

    $activityRows = [];
    $activityResult = $connection->query(
        "SELECT COUNT(*) AS activity_count
         FROM activity_assignments
         WHERE user_id = {$enterpriseId}
           AND status IN ('registered', 'attended')
           AND created_at >= '{$yearStartEscaped}'
           AND created_at < '{$yearEndEscaped}'"
    );
    if ($activityResult) {
        $summary = array_merge($summary, $activityResult->fetch_assoc() ?: []);
    }
    $activityListResult = $connection->query(
        "SELECT a.title, a.start_at, aa.status
         FROM activity_assignments aa
         INNER JOIN activities a ON a.activity_id = aa.activity_id
         WHERE aa.user_id = {$enterpriseId}
           AND aa.status IN ('registered', 'attended')
           AND aa.created_at >= '{$yearStartEscaped}'
           AND aa.created_at < '{$yearEndEscaped}'
         ORDER BY a.start_at DESC"
    );
    if ($activityListResult) {
        while ($activity = $activityListResult->fetch_assoc()) {
            $activityRows[] = $activity;
        }
    }

    $recordRows = [];
    $recordResult = $connection->query(
        "SELECT d.delivered_at, n.item_name, n.quantity, n.unit, d.delivery_method,
                CASE WHEN d.delivery_method = 'food_bank' THEN '忠信派車' ELSE '志工派車' END AS delivery_label
         FROM deliveries d
         INNER JOIN donations n ON n.donation_id = d.donation_id
         WHERE n.donor_id = {$enterpriseId}
           AND d.status = 'delivered'
           AND d.delivered_at >= '{$yearStartEscaped}'
           AND d.delivered_at < '{$yearEndEscaped}'
         ORDER BY d.delivered_at DESC"
    );
    if ($recordResult) {
        while ($record = $recordResult->fetch_assoc()) {
            $recordRows[] = $record;
        }
    }

    $trendRows = array_fill(1, 12, 0);
    $trendResult = $connection->query(
        "SELECT MONTH(donation_date) AS month, COALESCE(SUM(quantity), 0) AS total_quantity
         FROM donations
         WHERE donor_id = {$enterpriseId}
           AND donation_date >= '{$yearStartEscaped}'
           AND donation_date < '{$yearEndEscaped}'
         GROUP BY MONTH(donation_date)
         ORDER BY month ASC"
    );
    if ($trendResult) {
        while ($trend = $trendResult->fetch_assoc()) {
            $trendRows[(int) $trend['month']] = (float) $trend['total_quantity'];
        }
    }

    if (($_GET['export'] ?? '') === 'csv') {
        ob_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sustainability-report-' . $selectedYear . '.csv"');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'w');
        fputcsv($output, ['企業年度永續報告', $selectedYear]);
        fputcsv($output, ['捐贈總量', $summary['donation_quantity']]);
        fputcsv($output, ['捐贈筆數', $summary['donation_count']]);
        fputcsv($output, ['受助人次', $summary['beneficiary_count']]);
        fputcsv($output, ['公益配送次數', $summary['delivery_count']]);
        fputcsv($output, ['合作配送會員', $summary['volunteer_count']]);
        fputcsv($output, ['公益活動紀錄', $summary['activity_count']]);
        fputcsv($output, []);
        fputcsv($output, ['捐贈紀錄']);
        fputcsv($output, ['日期', '物資', '數量', '配送方式']);
        foreach ($recordRows as $record) {
            fputcsv($output, [$record['delivered_at'], $record['item_name'], $record['quantity'] . ' ' . $record['unit'], $record['delivery_label']]);
        }
        fputcsv($output, []);
        fputcsv($output, ['公益活動紀錄']);
        fputcsv($output, ['活動名稱', '日期', '狀態']);
        foreach ($activityRows as $activity) {
            fputcsv($output, [$activity['title'], $activity['start_at'], $activity['status'] === 'attended' ? '已參與' : '已報名']);
        }
        fclose($output);
        exit;
    }
    $maxTrendQuantity = max(1, max($trendRows));
    ?>
    <div class="view-header sustainability-header">
        <div>
            <h1 class="view-title">永續報告</h1>
            <p class="view-subtitle"><?php echo htmlspecialchars(($currentUser['enterprise_name'] ?? $currentUser['full_name'] ?? '企業會員') . ' ' . $selectedYear . ' 年度公益成果'); ?></p>
        </div>
        <a class="btn btn-primary" href="?page=carbon_report&amp;year=<?php echo $selectedYear; ?>&amp;export=csv"><i class="fas fa-download"></i> 下載年度報告</a>
    </div>

    <div class="stats-grid sustainability-stats">
        <div class="stat-card"><h3>捐贈總量</h3><div class="stat-number"><?php echo number_format((float) $summary['donation_quantity']); ?></div><p class="stat-label">件／包／箱等合計</p></div>
        <div class="stat-card"><h3>受助人次</h3><div class="stat-number"><?php echo number_format((int) $summary['beneficiary_count']); ?></div><p class="stat-label">已完成配送的關懷戶</p></div>
        <div class="stat-card"><h3>公益配送次數</h3><div class="stat-number"><?php echo number_format((int) $summary['delivery_count']); ?></div><p class="stat-label">已完成配送</p></div>
        <div class="stat-card"><h3>合作配送會員</h3><div class="stat-number"><?php echo number_format((int) $summary['volunteer_count']); ?></div><p class="stat-label">參與完成配送的人數</p></div>
    </div>

    <div class="card mt-32">
        <div class="card-header"><h2><?php echo $selectedYear; ?> 年度捐贈紀錄</h2><p>資料由系統完成的配送自動產生。</p></div>
        <div class="card-body">
            <?php if ($recordRows): ?>
                <table class="data-table"><thead><tr><th>日期</th><th>物資</th><th>數量</th><th>配送方式</th><th>狀態</th></tr></thead><tbody>
                    <?php foreach ($recordRows as $record): ?><tr><td><?php echo htmlspecialchars(date('Y-m-d', strtotime($record['delivered_at']))); ?></td><td><?php echo htmlspecialchars($record['item_name'] ?? '未填寫'); ?></td><td><?php echo htmlspecialchars($record['quantity'] . ' ' . $record['unit']); ?></td><td><?php echo htmlspecialchars($record['delivery_label']); ?></td><td><span class="status status-success">已完成</span></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else: ?><div class="empty-state"><i class="fas fa-seedling"></i><p>本年度尚無完成配送的捐贈紀錄。</p></div><?php endif; ?>
        </div>
    </div>

    <div class="card mt-32">
        <div class="card-header"><h2>年度捐贈趨勢</h2><p>依捐贈建立月份統計數量。</p></div>
        <div class="card-body sustainability-chart" aria-label="年度捐贈量趨勢圖">
            <?php foreach ($trendRows as $month => $quantity): ?><div class="sustainability-chart-column"><span class="sustainability-chart-value"><?php echo number_format($quantity); ?></span><div class="sustainability-chart-bar" style="height: <?php echo max(8, (int) round(($quantity / $maxTrendQuantity) * 150)); ?>px"></div><span><?php echo $month; ?>月</span></div><?php endforeach; ?>
        </div>
    </div>

    <div class="card mt-32">
        <div class="card-header"><h2>公益活動紀錄</h2><p>企業會員參與的活動筆數：<?php echo number_format((int) $summary['activity_count']); ?>。</p></div>
        <div class="card-body">
            <?php if ($activityRows): ?>
                <table class="data-table"><thead><tr><th>活動名稱</th><th>日期</th><th>狀態</th></tr></thead><tbody>
                    <?php foreach ($activityRows as $activity): ?><tr><td><?php echo htmlspecialchars($activity['title']); ?></td><td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($activity['start_at']))); ?></td><td><?php echo $activity['status'] === 'attended' ? '已參與' : '已報名'; ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else: ?><div class="empty-state"><i class="fas fa-calendar-check"></i><p>本年度尚無公益活動紀錄。</p></div><?php endif; ?>
        </div>
    </div>
    <style>
        .sustainability-header { align-items: flex-start; }
        .sustainability-chart { display: flex; align-items: flex-end; gap: 12px; min-height: 210px; overflow-x: auto; padding-top: 24px; }
        .sustainability-chart-column { min-width: 48px; display: flex; flex-direction: column; align-items: center; gap: 6px; color: #64748b; font-size: 12px; }
        .sustainability-chart-value { color: #0f766e; font-weight: 700; }
        .sustainability-chart-bar { width: 28px; min-height: 8px; border-radius: 6px 6px 2px 2px; background: #14b8a6; }
    </style>
    <?php
    return;
}

$monthlyRows = [];
$reportOwnerCondition = $isEnterpriseMember
    ? ' AND n.donor_id = ' . (int) ($currentUser['user_id'] ?? 0)
    : '';
$result = $connection->query(
    "SELECT DATE_FORMAT(delivered_at, '%Y-%m') AS month,
            COUNT(*) AS delivery_count,
            SUM(weight_kg) AS total_weight,
            SUM(total_distance_km) AS total_distance,
            SUM(CASE WHEN vehicle_type = 'car' THEN total_distance_km * {$carFactor} ELSE total_distance_km * {$motorcycleFactor} END) AS transport_emission,
            SUM(weight_kg * {$foodWasteFactor}) AS food_waste_avoided
    FROM deliveries d
    LEFT JOIN donations n ON n.donation_id = d.donation_id
    WHERE d.status = 'delivered' AND d.delivered_at IS NOT NULL{$reportOwnerCondition}
     GROUP BY month
     ORDER BY month DESC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['net_saved'] = (float) $row['food_waste_avoided'] - (float) $row['transport_emission'];
        $monthlyRows[] = $row;
    }
}

$totals = [
    'delivery_count' => array_sum(array_column($monthlyRows, 'delivery_count')),
    'total_weight' => array_sum(array_column($monthlyRows, 'total_weight')),
    'total_distance' => array_sum(array_column($monthlyRows, 'total_distance')),
    'net_saved' => array_sum(array_column($monthlyRows, 'net_saved')),
];
?>

<div class="view-header">
    <div>
        <h1 class="view-title">永續效益報表</h1>
        <p class="view-subtitle"><?php echo $isEnterpriseMember ? '查看企業捐贈帶來的環境與社會效益' : '追蹤平台物資流動對環境與社會的正面影響'; ?></p>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-circle-info"></i>
    <span>估算假設：每公斤惜食避免掩埋約減少 <?php echo $foodWasteFactor; ?> kgCO2e；汽車運輸每公里 <?php echo $carFactor; ?> kgCO2e；機車運輸每公里 <?php echo $motorcycleFactor; ?> kgCO2e。實際成效應由環保單位係數校正。</span>
</div>

<div class="stats-grid">
    <div class="stat-card"><h3>累計完成配送</h3><div class="stat-number"><?php echo number_format((int) $totals['delivery_count']); ?></div><p class="stat-label">趟次</p></div>
    <div class="stat-card"><h3>累計物資重量</h3><div class="stat-number"><?php echo number_format((float) $totals['total_weight'], 1); ?></div><p class="stat-label">公斤</p></div>
    <div class="stat-card"><h3>累計配送里程</h3><div class="stat-number"><?php echo number_format((float) $totals['total_distance'], 1); ?></div><p class="stat-label">公里</p></div>
    <div class="stat-card"><h3>估算淨減碳量</h3><div class="stat-number"><?php echo number_format((float) $totals['net_saved'], 1); ?></div><p class="stat-label">kgCO2e</p></div>
</div>

<div class="card mt-32">
    <div class="card-header"><h2>月度永續成效報表</h2><p>展示物資流動的環境與社會效益（依配達月份彙總）</p></div>
    <div class="card-body">
        <?php if ($monthlyRows): ?>
            <table class="data-table">
                <thead><tr><th>月份</th><th>配送趟次</th><th>物資重量 (kg)</th><th>配送里程 (km)</th><th>惜食避免排放</th><th>運輸排放</th><th>淨減碳量</th></tr></thead>
                <tbody>
                <?php foreach ($monthlyRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['month']); ?></td>
                        <td><?php echo (int) $row['delivery_count']; ?></td>
                        <td><?php echo number_format((float) $row['total_weight'], 1); ?></td>
                        <td><?php echo number_format((float) $row['total_distance'], 1); ?></td>
                        <td><?php echo number_format((float) $row['food_waste_avoided'], 1); ?> kgCO2e</td>
                        <td><?php echo number_format((float) $row['transport_emission'], 1); ?> kgCO2e</td>
                        <td><strong><?php echo number_format((float) $row['net_saved'], 1); ?> kgCO2e</strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-leaf"></i><p>尚無已完成的配送資料可供分析</p></div>
        <?php endif; ?>
    </div>
</div>
