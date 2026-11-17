<?php
/**
 * 數據分析與報表
 */

if (!(($currentUser['role'] ?? '') === 'foodbank_staff' || (($currentUser['role'] ?? '') === 'member' && ($currentUser['member_type'] ?? '') === 'general'))) {
    echo '<div class="alert alert-error">只有食物銀行官方人員可以查看數據分析。</div>';
    return;
}

$isVolunteer = ($currentUser['role'] ?? '') === 'member' && ($currentUser['member_type'] ?? '') === 'general';
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
// 配送次數與心意幣分開算，避免兩張表一起 JOIN 時心意幣被乘上配送次數。
// 食物銀行人員看「查詢期間」內的排行；GO Rider 沒有期間篩選，看全部累積。
$rankingDeliveryPeriod = $isVolunteer ? '' : " AND d.delivered_at BETWEEN '{$startDateEscaped} 00:00:00' AND '{$endDateEscaped} 23:59:59'";
$rankingPointsPeriod = $isVolunteer ? '' : " AND pt.created_at BETWEEN '{$startDateEscaped} 00:00:00' AND '{$endDateEscaped} 23:59:59'";
$result = $connection->query(
    "SELECT u.full_name,
            (SELECT COUNT(*) FROM deliveries d
              WHERE d.volunteer_id = u.user_id AND d.delivery_method = 'volunteer' AND d.status = 'delivered'{$rankingDeliveryPeriod}) AS delivery_count,
            GREATEST(COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
              WHERE pt.user_id = u.user_id AND pt.transaction_type = 'earned'{$rankingPointsPeriod}), 0), 0) AS total_points
     FROM users u
     WHERE u.role = 'member' AND u.member_type = 'general'
     ORDER BY total_points DESC, delivery_count DESC
     LIMIT 10"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $volunteerRanking[] = $row;
    }
}

if (!$isVolunteer && ($_GET['export'] ?? '') === 'volunteers_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="volunteer_ranking.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['GO Rider 姓名', '完成配送次數（' . $startDate . ' ~ ' . $endDate . '）', '心意幣（' . $startDate . ' ~ ' . $endDate . '）']);
    foreach ($volunteerRanking as $row) {
        fputcsv($output, [$row['full_name'], $row['delivery_count'], $row['total_points']]);
    }
    fclose($output);
    exit;
}
?>

<div class="view-header">
    <div>
        <h1 class="view-title"><?php echo $isVolunteer ? '榮譽榜' : '數據分析'; ?></h1>
        <p class="view-subtitle"><?php echo $isVolunteer ? '查看一般會員心意幣與完成配送排行' : '查詢指定期間的物資流動、任務媒合率與會員排行'; ?></p>
    </div>
</div>
<?php if (!$isVolunteer): ?>
<div class="card">
    <div class="card-body">
        <form method="get" class="filter-bar">
            <input type="hidden" name="page" value="reports">
            <span class="filter-label">查詢期間</span>
            <div class="filter-dates">
                <i class="far fa-calendar" aria-hidden="true"></i>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" aria-label="開始日期">
                <span class="filter-dates-sep">至</span>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" aria-label="結束日期">
            </div>
            <button class="btn btn-primary btn-sm filter-submit" type="submit"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> 查詢</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$isVolunteer): ?>
<div class="stats-grid mt-20">
    <div class="stat-card"><h3>待評估捐贈</h3><div class="stat-number"><?php echo $donationStats['pending'] ?? 0; ?></div><p class="stat-label">筆</p></div>
    <div class="stat-card"><h3>已批准捐贈</h3><div class="stat-number"><?php echo $donationStats['approved'] ?? 0; ?></div><p class="stat-label">筆</p></div>
    <div class="stat-card"><h3>任務媒合率</h3><div class="stat-number"><?php echo $matchRate; ?>%</div><p class="stat-label">已配達 / 全部任務</p></div>
    <div class="stat-card"><h3>異常任務</h3><div class="stat-number"><?php echo $deliveryStats['exception']; ?></div><p class="stat-label">筆待處理</p></div>
</div>
<?php endif; ?>

<div class="card mt-32">
    <div class="card-header">
        <div class="toolbar-row">
            <div><h2>GO Rider 派車排行</h2><p class="toolbar-meta"><?php echo $isVolunteer ? '依累積心意幣排序' : '依查詢期間（' . htmlspecialchars($startDate) . ' ~ ' . htmlspecialchars($endDate) . '）賺得的心意幣排序'; ?></p></div>
            <?php if (!$isVolunteer): ?><div class="toolbar-actions"><a class="btn btn-secondary btn-sm" href="?page=reports&amp;start_date=<?php echo urlencode($startDate); ?>&amp;end_date=<?php echo urlencode($endDate); ?>&amp;export=volunteers_csv"><i class="fas fa-download"></i> 匯出 CSV</a></div><?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($volunteerRanking): ?>
            <table class="data-table ranking-table">
                <thead><tr><th>排名</th><th>GO Rider</th><th>完成配送次數</th><th>累積心意幣</th></tr></thead>
                <tbody>
                <?php foreach ($volunteerRanking as $index => $row): ?>
                    <?php $pos = $index + 1; $rankClass = $pos <= 3 ? 'rank-' . $pos : ''; ?>
                    <tr class="<?php echo $rankClass; ?>">
                        <td>
                            <?php if ($pos <= 3): ?>
                                <?php if ($pos === 1): ?>
                                    <span class="rank-badge medal medal-1" title="第1名：金牌" aria-label="第一名獎牌">
                                        <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
                                            <defs>
                                                <linearGradient id="g-gold" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="#FFF7D9" />
                                                    <stop offset="100%" stop-color="#D4AF37" />
                                                </linearGradient>
                                                <linearGradient id="r-gold" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="#FF7A7A" />
                                                    <stop offset="100%" stop-color="#FF3B3B" />
                                                </linearGradient>
                                            </defs>
                                            <circle cx="32" cy="22" r="14" fill="url(#g-gold)" stroke="#b88b24" stroke-width="2" />
                                            <path d="M24 40 L20 60 L32 54 L44 60 L40 40 Z" fill="url(#r-gold)" />
                                            <text x="32" y="26" text-anchor="middle" font-size="14" font-weight="700" fill="#3b2f00"><?php echo $pos; ?></text>
                                        </svg>
                                    </span>
                                <?php elseif ($pos === 2): ?>
                                    <span class="rank-badge medal medal-2" title="第2名：銀牌" aria-label="第二名獎牌">
                                        <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
                                            <defs>
                                                <linearGradient id="g-silver" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="#F6F7F8" />
                                                    <stop offset="100%" stop-color="#C0C0C0" />
                                                </linearGradient>
                                                <linearGradient id="r-silver" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="#BFC8D1" />
                                                    <stop offset="100%" stop-color="#9CA6B2" />
                                                </linearGradient>
                                            </defs>
                                            <circle cx="32" cy="22" r="14" fill="url(#g-silver)" stroke="#9ea6ad" stroke-width="2" />
                                            <path d="M24 40 L20 60 L32 54 L44 60 L40 40 Z" fill="url(#r-silver)" />
                                            <text x="32" y="26" text-anchor="middle" font-size="14" font-weight="700" fill="#1f2937"><?php echo $pos; ?></text>
                                        </svg>
                                    </span>
                                <?php else: ?>
                                    <span class="rank-badge medal medal-3" title="第3名：銅牌" aria-label="第三名獎牌">
                                        <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
                                            <defs>
                                                <linearGradient id="g-bronze" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="#F9EDE1" />
                                                    <stop offset="100%" stop-color="#CD7F32" />
                                                </linearGradient>
                                                <linearGradient id="r-bronze" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="#E7C1A2" />
                                                    <stop offset="100%" stop-color="#B66B3A" />
                                                </linearGradient>
                                            </defs>
                                            <circle cx="32" cy="22" r="14" fill="url(#g-bronze)" stroke="#9b5f2a" stroke-width="2" />
                                            <path d="M24 40 L20 60 L32 54 L44 60 L40 40 Z" fill="url(#r-bronze)" />
                                            <text x="32" y="26" text-anchor="middle" font-size="14" font-weight="700" fill="#2b1a0e"><?php echo $pos; ?></text>
                                        </svg>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo $pos; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><?php echo (int) $row['delivery_count']; ?></td>
                        <td><strong><?php echo (int) $row['total_points']; ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-ranking-star"></i><p>目前尚無 GO Rider 點數紀錄</p></div>
        <?php endif; ?>
    </div>
</div>

