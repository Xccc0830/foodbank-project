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
$quarterInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['partner_quarter'] ?? '')
    : ($_GET['partner_quarter'] ?? '');
$currentQuarter = date('Y') . '-Q' . (string) ceil((int) date('n') / 3);
$partnerQuarter = is_string($quarterInput) && preg_match('/^\d{4}-Q[1-4]$/D', $quarterInput)
    ? $quarterInput
    : $currentQuarter;
$quarterYear = (int) substr($partnerQuarter, 0, 4);
$quarterNumber = (int) substr($partnerQuarter, 6, 1);
$quarterStart = sprintf('%04d-%02d-01', $quarterYear, (($quarterNumber - 1) * 3) + 1);
$quarterEnd = date('Y-m-d', strtotime('+3 months', strtotime($quarterStart)));
$quarterStartEscaped = $connection->real_escape_string($quarterStart);
$quarterEndEscaped = $connection->real_escape_string($quarterEnd);
$partnerReviewMessage = null;

if (!$isVolunteer && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_partner_esg_review') {
    $donorId = (int) ($_POST['donor_id'] ?? 0);
    $reviewStatus = $_POST['review_status'] ?? '';
    $reviewNote = trim((string) ($_POST['review_note'] ?? ''));
    if ($donorId <= 0 || !in_array($reviewStatus, ['certified', 'not_certified'], true)
        || $reviewNote === '' || mb_strlen($reviewNote, 'UTF-8') > 2000) {
        $partnerReviewMessage = ['type' => 'error', 'text' => '請選擇認證結果並填寫 1 至 2000 字的評估說明。'];
    } else {
        $review = $connection->prepare(
            "INSERT INTO partner_esg_reviews (donor_id, quarter, status, review_note, reviewed_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), review_note = VALUES(review_note),
                                     reviewed_by = VALUES(reviewed_by), reviewed_at = NOW()"
        );
        if (!$review) {
            throw new RuntimeException('無法準備季度 ESG 評估: ' . $connection->error);
        }
        $reviewerId = (int) $currentUser['user_id'];
        $review->bind_param('isssi', $donorId, $partnerQuarter, $reviewStatus, $reviewNote, $reviewerId);
        if (!$review->execute()) {
            throw new RuntimeException('無法儲存季度 ESG 評估: ' . $review->error);
        }
        $partnerReviewMessage = ['type' => 'success', 'text' => '季度商家 ESG 評估已儲存。'];
    }
}

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

if (!$isVolunteer) {
    $partnerMetricsResult = $connection->query(
        "SELECT u.user_id AS donor_id, COALESCE(NULLIF(u.enterprise_name, ''), u.full_name) AS donor_name,
                COALESCE(d.delivered_donations, 0) AS delivered_donations,
                COALESCE(d.delivered_weight_kg, 0) AS delivered_weight_kg,
                COALESCE(r.redemption_count, 0) AS redemption_count,
                COALESCE(r.points_spent, 0) AS points_spent,
                review.status AS review_status, review.review_note, review.reviewed_at
         FROM users u
         LEFT JOIN (
             SELECT n.donor_id, COUNT(DISTINCT n.donation_id) AS delivered_donations,
                    SUM(d.weight_kg) AS delivered_weight_kg
             FROM donations n
             JOIN deliveries d ON d.donation_id = n.donation_id
             WHERE d.status = 'delivered'
               AND d.delivered_at >= '{$quarterStartEscaped}' AND d.delivered_at < '{$quarterEndEscaped}'
             GROUP BY n.donor_id
         ) d ON d.donor_id = u.user_id
         LEFT JOIN (
             SELECT dri.donor_id, COUNT(rc.claim_id) AS redemption_count,
                    SUM(rc.points_spent) AS points_spent
             FROM reward_claims rc
             JOIN donor_reward_items dri ON dri.item_id = rc.donor_item_id
             WHERE rc.source_type = 'donor' AND rc.status = 'fulfilled'
               AND rc.redeemed_at >= '{$quarterStartEscaped}' AND rc.redeemed_at < '{$quarterEndEscaped}'
             GROUP BY dri.donor_id
         ) r ON r.donor_id = u.user_id
         LEFT JOIN partner_esg_reviews review ON review.donor_id = u.user_id AND review.quarter = '{$partnerQuarter}'
         WHERE u.role = 'member' AND u.member_type = 'enterprise' AND u.status = 'active'
         ORDER BY delivered_donations DESC, redemption_count DESC, donor_name"
    );
    if (!$partnerMetricsResult) {
        throw new RuntimeException('無法載入季度商家指標: ' . $connection->error);
    }
    $partnerMetrics = $partnerMetricsResult->fetch_all(MYSQLI_ASSOC);

    if (($_GET['export'] ?? '') === 'partner_esg_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="partner_esg_' . $partnerQuarter . '.csv"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['季度', $partnerQuarter]);
        fputcsv($output, ['合作商家', '完成捐贈單數', '送達重量 (kg)', '優惠核銷次數', '已使用點數', '認證結果', '評估說明']);
        foreach ($partnerMetrics as $row) {
            fputcsv($output, [
                $row['donor_name'], $row['delivered_donations'], $row['delivered_weight_kg'],
                $row['redemption_count'], $row['points_spent'],
                $row['review_status'] === 'certified' ? '已認證' : ($row['review_status'] === 'not_certified' ? '未認證' : '未評估'),
                $row['review_note'],
            ]);
        }
        fclose($output);
        exit;
    }
    $policyAuditResult = $connection->query(
        "SELECT log_id, action_type, record_id, new_values, action_timestamp
         FROM audit_logs WHERE LEFT(action_type, 7) = 'policy_'
         ORDER BY action_timestamp DESC, log_id DESC LIMIT 50"
    );
    if (!$policyAuditResult) {
        throw new RuntimeException('無法載入政策稽核紀錄: ' . $connection->error);
    }
    $policyAudits = $policyAuditResult->fetch_all(MYSQLI_ASSOC);
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
    <div class="card mt-32">
        <div class="card-header">
            <div class="toolbar-row">
                <div>
                    <h2>合作商家季度 ESG 指標與認證</h2>
                    <p>檢視季度完成捐贈、送達重量及優惠核銷；認證由食物銀行人員依文件標準複核，不由系統自行推定。</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="?page=reports&amp;partner_quarter=<?php echo urlencode($partnerQuarter); ?>&amp;export=partner_esg_csv">匯出季度 CSV</a>
            </div>
        </div>
        <div class="card-body">
            <?php if ($partnerReviewMessage): ?>
                <div class="alert alert-<?php echo $partnerReviewMessage['type'] === 'success' ? 'success' : 'error'; ?>" role="alert">
                    <?php echo htmlspecialchars($partnerReviewMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <form method="get" class="filter-bar">
                <input type="hidden" name="page" value="reports">
                <label for="partner-quarter">評估季度</label>
                <input id="partner-quarter" type="text" name="partner_quarter" pattern="\d{4}-Q[1-4]" value="<?php echo htmlspecialchars($partnerQuarter, ENT_QUOTES, 'UTF-8'); ?>" required>
                <button class="btn btn-primary btn-sm" type="submit">查詢季度</button>
            </form>
            <p class="toolbar-meta"><?php echo htmlspecialchars($quarterStart, ENT_QUOTES, 'UTF-8'); ?> 至 <?php echo htmlspecialchars(date('Y-m-d', strtotime($quarterEnd . ' -1 day')), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($partnerMetrics): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>合作商家</th><th>完成捐贈單</th><th>送達重量（kg）</th><th>優惠核銷</th><th>使用點數</th><th>季度認證結果</th><th>評估說明／更新</th></tr></thead>
                        <tbody>
                        <?php foreach ($partnerMetrics as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['donor_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) $row['delivered_donations']; ?></td>
                                <td><?php echo number_format((float) $row['delivered_weight_kg'], 2); ?></td>
                                <td><?php echo (int) $row['redemption_count']; ?></td>
                                <td><?php echo (int) $row['points_spent']; ?></td>
                                <td>
                                    <?php if ($row['review_status'] === 'certified'): ?>
                                        <span class="status status-success">已認證</span>
                                    <?php elseif ($row['review_status'] === 'not_certified'): ?>
                                        <span class="status status-warning">未認證</span>
                                    <?php else: ?>
                                        <span class="status">待評估</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" class="delivery-action-form">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="save_partner_esg_review">
                                        <input type="hidden" name="donor_id" value="<?php echo (int) $row['donor_id']; ?>">
                                        <input type="hidden" name="partner_quarter" value="<?php echo htmlspecialchars($partnerQuarter, ENT_QUOTES, 'UTF-8'); ?>">
                                        <select name="review_status" aria-label="季度認證結果">
                                            <option value="certified" <?php echo $row['review_status'] === 'certified' ? 'selected' : ''; ?>>認證通過</option>
                                            <option value="not_certified" <?php echo $row['review_status'] === 'not_certified' ? 'selected' : ''; ?>>未通過</option>
                                        </select>
                                        <textarea name="review_note" maxlength="2000" rows="2" placeholder="填寫依據與改善建議" required><?php echo htmlspecialchars($row['review_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                        <button class="btn btn-primary btn-sm" type="submit">儲存評估</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-store"></i><p>目前沒有啟用中的愛心商家。</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-32">
        <div class="card-header"><h2>配送政策稽核紀錄</h2><p>時間或 GPS 異常只建立複核案件，不會自動判定違規或停權。</p></div>
        <div class="card-body">
            <?php if ($policyAudits): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>時間</th><th>稽核項目</th><th>記錄編號</th><th>異常摘要</th></tr></thead>
                        <tbody>
                        <?php foreach ($policyAudits as $audit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($audit['action_timestamp'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($audit['action_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) $audit['record_id']; ?></td>
                                <td><code><?php echo htmlspecialchars((string) $audit['new_values'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>目前沒有政策稽核待複核記錄。</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
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

