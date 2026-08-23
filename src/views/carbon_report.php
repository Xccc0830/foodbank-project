<?php
/**
 * 減碳與社會效益報表
 */

if (!in_array($currentUser['role'] ?? '', ['admin', 'foodbank_staff'], true)) {
    echo '<div class="alert alert-error">只有食物銀行官方人員可以查看減碳報表。</div>';
    return;
}

$connection = $db->getConnection();

// 假設係數：食物廢棄物避免掩埋排放 2.5 kgCO2e/kg；汽車運輸 0.192 kgCO2e/km；機車運輸 0.081 kgCO2e/km
$foodWasteFactor = 2.5;
$carFactor = 0.192;
$motorcycleFactor = 0.081;

$monthlyRows = [];
$result = $connection->query(
    "SELECT DATE_FORMAT(delivered_at, '%Y-%m') AS month,
            COUNT(*) AS delivery_count,
            SUM(weight_kg) AS total_weight,
            SUM(total_distance_km) AS total_distance,
            SUM(CASE WHEN vehicle_type = 'car' THEN total_distance_km * {$carFactor} ELSE total_distance_km * {$motorcycleFactor} END) AS transport_emission,
            SUM(weight_kg * {$foodWasteFactor}) AS food_waste_avoided
     FROM deliveries
     WHERE status = 'delivered' AND delivered_at IS NOT NULL
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
        <h1 class="view-title">減碳與社會效益分析</h1>
        <p class="view-subtitle">依已完成配送估算物資流動與減碳成效</p>
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
    <div class="card-header"><h2>月度成效報表</h2><p>文山區試點成效分析（依配達月份彙總）</p></div>
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
