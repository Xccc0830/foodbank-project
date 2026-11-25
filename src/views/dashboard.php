<?php
/**
 * 儀表板視圖 - 精修版
 */

require_once BASE_PATH . '/src/models/DonationModel.php';

$donationModel = new DonationModel();

$dashboardRole = $currentUser['role'] ?? 'foodbank_staff';
$dashboardMemberType = $currentUser['member_type'] ?? null;
$isEnterpriseMember = $dashboardRole === 'member' && $dashboardMemberType === 'enterprise';
$canViewDonations = $dashboardRole === 'foodbank_staff' || $isEnterpriseMember;
$recentDonations = $canViewDonations
    ? array_slice($donationModel->getAllDonations(null, $isEnterpriseMember ? (int) $currentUser['user_id'] : null), 0, 5)
    : [];
$enterpriseSustainability = [
    'delivery_count' => 0,
    'total_weight' => 0,
    'total_distance' => 0,
    'food_waste_avoided' => 0,
];
if ($isEnterpriseMember) {
    $enterpriseUserId = (int) $currentUser['user_id'];
    $sustainabilityResult = $connection->query(
        "SELECT COUNT(DISTINCT d.delivery_id) AS delivery_count,
                COALESCE(SUM(d.weight_kg), 0) AS total_weight,
                COALESCE(SUM(d.total_distance_km), 0) AS total_distance
         FROM deliveries d
         INNER JOIN donations n ON n.donation_id = d.donation_id
         WHERE n.donor_id = {$enterpriseUserId}
           AND d.status = 'delivered'"
    );
    if ($sustainabilityResult && ($sustainabilityRow = $sustainabilityResult->fetch_assoc())) {
        $enterpriseSustainability['delivery_count'] = (int) $sustainabilityRow['delivery_count'];
        $enterpriseSustainability['total_weight'] = (float) $sustainabilityRow['total_weight'];
        $enterpriseSustainability['total_distance'] = (float) $sustainabilityRow['total_distance'];
        $enterpriseSustainability['food_waste_avoided'] = $enterpriseSustainability['total_weight'] * 2.5;
    }
}
$donationListPage = $isEnterpriseMember ? 'donation_materials' : 'donation_materials_review';
$dashboardRoleLabels = [
    'foodbank_staff' => '管理介面',
    'member' => $isEnterpriseMember ? '企業公益行動中心' : 'GO Rider 服務中心',
];

require_once BASE_PATH . '/src/models/AdvertisementModel.php';
require_once BASE_PATH . '/src/models/ActivityModel.php';

$homeAds = array_slice((new AdvertisementModel())->getActiveAds(3), 0, 3);
if (!$homeAds) {
    $homeAds = AdvertisementModel::demoAds();
}

$homeDonationRecords = [];
$homeDonationResult = $connection->query(
    "SELECT n.donor_name, COALESCE(n.item_name, '物資') AS item_name, n.quantity, n.unit, n.donation_date
     FROM donations n
     WHERE n.status <> 'rejected'
       AND EXISTS (SELECT 1 FROM deliveries d WHERE d.donation_id = n.donation_id AND d.status = 'delivered')
     ORDER BY n.donation_date DESC
     LIMIT 8"
);
while ($homeDonationResult && ($homeDonationRow = $homeDonationResult->fetch_assoc())) {
    $homeDonationRecords[] = $homeDonationRow;
}

$riderLeaderboard = [];
$leaderboardResult = $connection->query(
    "SELECT u.full_name, COUNT(*) AS delivery_count, COALESCE(SUM(d.weight_kg), 0) AS total_weight
     FROM deliveries d
     INNER JOIN users u ON u.user_id = d.volunteer_id
     WHERE d.status = 'delivered' AND d.delivery_method = 'volunteer'
     GROUP BY d.volunteer_id, u.full_name
     ORDER BY delivery_count DESC, total_weight DESC
     LIMIT 10"
);
while ($leaderboardResult && ($leaderboardRow = $leaderboardResult->fetch_assoc())) {
    $riderLeaderboard[] = $leaderboardRow;
}

$canAcceptRiderTasks = $authService->can('rider.accept_task');

if ($isEnterpriseMember) {
    $dashboardHero = [
        'eyebrow' => '企業公益行動中心',
        'title' => '把企業剩食，轉化為看得見的公益影響。',
        'description' => '集中管理物資捐贈、追蹤配送進度，並查看每一次惜食行動帶來的永續成果。',
        'primary' => ['href' => '?page=donation_materials', 'label' => '新增物資捐贈', 'icon' => 'fa-box-open'],
        'secondary' => ['href' => '?page=carbon_report', 'label' => '查看永續報告', 'icon' => 'fa-leaf'],
    ];
    $dashboardHeroSteps = [
        ['icon' => 'fa-box-open', 'label' => '送出物資'],
        ['icon' => 'fa-clipboard-check', 'label' => '完成媒合'],
        ['icon' => 'fa-earth-asia', 'label' => '累積影響'],
    ];
    $dashboardActionDefinitions = [
        ['page' => 'donation_materials', 'icon' => 'fa-box-open', 'title' => '新增捐贈', 'description' => '建立剩食與物資捐贈'],
        ['page' => 'carbon_report', 'icon' => 'fa-leaf', 'title' => '永續報告', 'description' => '查看減碳與公益成效'],
        ['page' => 'activities', 'icon' => 'fa-calendar-check', 'title' => '公益活動', 'description' => '參與企業公益行動'],
        ['page' => 'rewards', 'icon' => 'fa-gift', 'title' => '心意幣回饋', 'description' => '瀏覽合作獎勵與兌換'],
    ];
} elseif ($dashboardRole === 'foodbank_staff') {
    $dashboardHero = [
        'eyebrow' => '食物銀行管理工作台',
        'title' => '讓每一份善意，都能即時抵達下一站。',
        'description' => '集中處理捐贈審查、物流配送與活動安排，讓團隊合作保持清楚而有效率。',
        'primary' => ['href' => '?page=donation_materials_review', 'label' => '處理捐贈審查', 'icon' => 'fa-clipboard-check'],
        'secondary' => ['href' => '?page=deliveries', 'label' => '查看配送任務', 'icon' => 'fa-route'],
    ];
    $dashboardHeroSteps = [
        ['icon' => 'fa-hand-holding-heart', 'label' => '接收捐贈'],
        ['icon' => 'fa-clipboard-check', 'label' => '審查整理'],
        ['icon' => 'fa-truck-fast', 'label' => '安排配送'],
    ];
    $dashboardActionDefinitions = [
        ['page' => 'donation_materials_review', 'icon' => 'fa-clipboard-check', 'title' => '捐贈審查', 'description' => '檢視待評估的捐贈物資'],
        ['page' => 'deliveries', 'icon' => 'fa-route', 'title' => '配送任務', 'description' => '追蹤取貨、配送與完成狀態'],
        ['page' => 'activities', 'icon' => 'fa-calendar-check', 'title' => '公益活動', 'description' => '安排活動與參與名單'],
        ['page' => 'inventory', 'icon' => 'fa-boxes-stacked', 'title' => '物資盤點', 'description' => '掌握庫存與到期提醒'],
    ];
} else {
    $dashboardHero = [
        'eyebrow' => $canAcceptRiderTasks ? 'GO Rider 公益服務中心' : '會員公益行動中心',
        'title' => $canAcceptRiderTasks
            ? '用一趟運送，完成一段惜食旅程。'
            : '從一次參與，開始你的惜食行動。',
        'description' => $canAcceptRiderTasks
            ? '查看待配送任務、接單前往取貨，並累積每一趟運送帶來的心意回饋。'
            : '瀏覽最新消息與公益活動；準備好後，也可以申請加入 GO Rider。',
        'primary' => $canAcceptRiderTasks
            ? ['href' => '?page=deliveries', 'label' => '查看可接任務', 'icon' => 'fa-route']
            : ['href' => '?page=activities', 'label' => '探索公益活動', 'icon' => 'fa-calendar-check'],
        'secondary' => $canAcceptRiderTasks
            ? ['href' => '?page=activities', 'label' => '瀏覽公益活動', 'icon' => 'fa-calendar-check']
            : ['href' => '?page=rider_application', 'label' => '申請 GO Rider', 'icon' => 'fa-id-card'],
    ];
    $dashboardHeroSteps = $canAcceptRiderTasks
        ? [
            ['icon' => 'fa-list-check', 'label' => '瀏覽任務'],
            ['icon' => 'fa-location-dot', 'label' => '前往取貨'],
            ['icon' => 'fa-circle-check', 'label' => '完成配送'],
        ]
        : [
            ['icon' => 'fa-magnifying-glass', 'label' => '探索活動'],
            ['icon' => 'fa-hands-helping', 'label' => '參與行動'],
            ['icon' => 'fa-heart', 'label' => '累積回饋'],
        ];
    $dashboardActionDefinitions = $canAcceptRiderTasks
        ? [
            ['page' => 'deliveries', 'icon' => 'fa-route', 'title' => '配送任務', 'description' => '查看並接取待運送物資'],
            ['page' => 'activities', 'icon' => 'fa-calendar-check', 'title' => '公益活動', 'description' => '參與最新的公益行動'],
            ['page' => 'rewards', 'icon' => 'fa-gift', 'title' => '心意幣兌換', 'description' => '使用累積回饋兌換獎勵'],
            ['page' => 'notifications', 'icon' => 'fa-bell', 'title' => '通知中心', 'description' => '查看任務與平台更新'],
        ]
        : [
            ['page' => 'activities', 'icon' => 'fa-calendar-check', 'title' => '公益活動', 'description' => '查看並報名公益行動'],
            ['page' => 'rider_application', 'icon' => 'fa-id-card', 'title' => '申請 GO Rider', 'description' => '加入物資配送服務'],
            ['page' => 'news', 'icon' => 'fa-newspaper', 'title' => '最新消息', 'description' => '掌握平台與合作資訊'],
            ['page' => 'notifications', 'icon' => 'fa-bell', 'title' => '通知中心', 'description' => '查看個人化平台提醒'],
        ];
}

$dashboardQuickActions = array_values(array_filter(
    $dashboardActionDefinitions,
    static fn (array $action): bool => $authService->can('page.' . $action['page'])
));
$dashboardAdIcons = ['fa-bowl-rice', 'fa-hand-holding-heart', 'fa-truck-fast'];
?>

<div class="dashboard-page">
    <section class="dashboard-hero" aria-labelledby="dashboard-hero-title">
        <div class="dashboard-hero-copy">
            <span class="dashboard-eyebrow"><?php echo htmlspecialchars($dashboardHero['eyebrow']); ?></span>
            <h1 id="dashboard-hero-title"><?php echo htmlspecialchars($dashboardHero['title']); ?></h1>
            <p class="dashboard-hero-description"><?php echo htmlspecialchars($dashboardHero['description']); ?></p>
            <div class="dashboard-hero-meta">
                <span><i class="fas fa-calendar-day" aria-hidden="true"></i><?php echo date('Y 年 n 月 j 日'); ?></span>
                <span><i class="fas fa-user" aria-hidden="true"></i><?php echo htmlspecialchars($currentUser['full_name'] ?? '使用者'); ?></span>
                <span><?php echo htmlspecialchars($dashboardRoleLabels[$dashboardRole] ?? '管理介面'); ?></span>
            </div>
            <div class="dashboard-hero-actions">
                <a class="btn btn-accent" href="<?php echo htmlspecialchars($dashboardHero['primary']['href'], ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas <?php echo htmlspecialchars($dashboardHero['primary']['icon']); ?>" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($dashboardHero['primary']['label']); ?>
                </a>
                <a class="btn btn-hero-secondary" href="<?php echo htmlspecialchars($dashboardHero['secondary']['href'], ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas <?php echo htmlspecialchars($dashboardHero['secondary']['icon']); ?>" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($dashboardHero['secondary']['label']); ?>
                </a>
            </div>
        </div>
        <div class="dashboard-hero-visual" aria-label="物資服務流程">
            <div class="dashboard-flow-header">
                <span>共同完成</span>
                <strong>一趟有溫度的媒合</strong>
            </div>
            <ol class="dashboard-hero-steps">
                <?php foreach ($dashboardHeroSteps as $stepIndex => $step): ?>
                    <li>
                        <span class="dashboard-hero-step-number"><?php echo $stepIndex + 1; ?></span>
                        <span class="dashboard-hero-step-icon"><i class="fas <?php echo htmlspecialchars($step['icon']); ?>" aria-hidden="true"></i></span>
                        <strong><?php echo htmlspecialchars($step['label']); ?></strong>
                    </li>
                <?php endforeach; ?>
            </ol>
            <div class="dashboard-hero-note">
                <span class="dashboard-hero-note-icon"><i class="fas fa-heart" aria-hidden="true"></i></span>
                <span><strong>每一份物資</strong>都有下一站</span>
            </div>
        </div>
    </section>

    <?php include BASE_PATH . '/src/views/partials/marquee.php'; ?>

    <section class="dashboard-section" aria-labelledby="dashboard-updates-title">
        <div class="dashboard-section-heading">
            <div>
                <span class="dashboard-section-kicker">平台脈動</span>
                <h2 id="dashboard-updates-title">看見正在發生的公益行動</h2>
            </div>
            <a class="dashboard-text-link" href="?page=news">
                查看最新消息 <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <div class="dashboard-insight-grid">
            <article class="card dashboard-insight-card dashboard-insight-card-ads">
                <header class="dashboard-card-heading">
                    <span class="dashboard-card-icon dashboard-card-icon-gold"><i class="fas fa-rectangle-ad" aria-hidden="true"></i></span>
                    <span>
                        <h3>企業合作訊息</h3>
                        <p>最新刊登的合作與活動</p>
                    </span>
                    <span class="dashboard-count-badge"><?php echo count($homeAds); ?> 則</span>
                </header>
                <div class="dashboard-card-content">
                    <div class="fancy-ad-list">
                        <?php foreach ($homeAds as $adIndex => $homeAd): ?>
                            <article class="fancy-ad fancy-ad-<?php echo $adIndex % 3; ?>">
                                <div class="fancy-ad-icon"><i class="fas <?php echo $dashboardAdIcons[$adIndex % 3]; ?>" aria-hidden="true"></i></div>
                                <div class="fancy-ad-text">
                                    <span class="fancy-ad-badge">合作訊息</span>
                                    <strong><?php echo htmlspecialchars($homeAd['title']); ?></strong>
                                    <p><?php echo htmlspecialchars(mb_strimwidth($homeAd['content'], 0, 72, '…')); ?></p>
                                    <small><i class="fas fa-building" aria-hidden="true"></i> <?php echo htmlspecialchars($homeAd['advertiser_name'] ?? ''); ?></small>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <a href="?page=news#ads" class="dashboard-text-link">
                        瀏覽所有合作訊息 <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </article>

            <article class="card dashboard-insight-card">
                <header class="dashboard-card-heading">
                    <span class="dashboard-card-icon dashboard-card-icon-teal"><i class="fas fa-box-open" aria-hidden="true"></i></span>
                    <span>
                        <h3>企業捐贈紀錄</h3>
                        <p>已完成配送的愛心物資</p>
                    </span>
                    <span class="dashboard-count-badge"><?php echo count($homeDonationRecords); ?> 筆</span>
                </header>
                <div class="dashboard-card-content">
                    <?php if ($homeDonationRecords): ?>
                        <ul class="dashboard-feed-list">
                            <?php foreach ($homeDonationRecords as $record): ?>
                                <?php $donationTimestamp = strtotime((string) $record['donation_date']); ?>
                                <li>
                                    <span class="dashboard-feed-mark"><i class="fas fa-box" aria-hidden="true"></i></span>
                                    <span class="dashboard-feed-copy">
                                        <strong><?php echo htmlspecialchars($record['donor_name']); ?></strong>
                                        <small><?php echo htmlspecialchars($record['item_name']); ?> · <?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $record['quantity'], 2, '.', ''), '0'), '.')); ?> <?php echo htmlspecialchars($record['unit']); ?></small>
                                    </span>
                                    <time datetime="<?php echo htmlspecialchars((string) $record['donation_date']); ?>">
                                        <?php echo $donationTimestamp ? htmlspecialchars(date('m/d', $donationTimestamp)) : '—'; ?>
                                    </time>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-box-open" aria-hidden="true"></i><p>目前沒有已送達的捐贈紀錄</p></div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="card dashboard-insight-card">
                <header class="dashboard-card-heading">
                    <span class="dashboard-card-icon dashboard-card-icon-blue"><i class="fas fa-ranking-star" aria-hidden="true"></i></span>
                    <span>
                        <h3>GO Rider 榜單</h3>
                        <p>依完成配送任務數排序</p>
                    </span>
                    <span class="dashboard-count-badge"><?php echo count($riderLeaderboard); ?> 位</span>
                </header>
                <div class="dashboard-card-content">
                    <?php if ($riderLeaderboard): ?>
                        <ol class="dashboard-leaderboard-list">
                            <?php foreach ($riderLeaderboard as $index => $rider): ?>
                                <li>
                                    <span class="dashboard-leaderboard-rank dashboard-rank-<?php echo min($index + 1, 4); ?>"><?php echo $index + 1; ?></span>
                                    <span class="dashboard-rider-avatar"><?php echo htmlspecialchars(mb_substr($rider['full_name'], 0, 1)); ?></span>
                                    <span class="dashboard-rider-name">
                                        <strong><?php echo htmlspecialchars($rider['full_name']); ?></strong>
                                        <small><?php echo number_format((float) $rider['total_weight'], 1); ?> kg 物資</small>
                                    </span>
                                    <strong class="dashboard-delivery-count"><?php echo (int) $rider['delivery_count']; ?> 趟</strong>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-ranking-star" aria-hidden="true"></i><p>尚無完成的運送任務</p></div>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </section>

    <?php if ($isEnterpriseMember): ?>
        <section class="dashboard-impact-section" aria-labelledby="dashboard-impact-title">
            <div class="dashboard-impact-copy">
                <span class="dashboard-section-kicker">企業永續成果</span>
                <h2 id="dashboard-impact-title">讓每一次捐贈都留下可追蹤的影響</h2>
                <p>依已完成配送的物資，估算公益投入、環境效益與運送里程。</p>
                <a class="btn btn-primary" href="?page=carbon_report">
                    <i class="fas fa-chart-line" aria-hidden="true"></i> 查看年度報告
                </a>
            </div>
            <div class="dashboard-impact-stats">
                <div><span>完成配送</span><strong><?php echo number_format($enterpriseSustainability['delivery_count']); ?></strong><small>趟次</small></div>
                <div><span>捐贈重量</span><strong><?php echo number_format($enterpriseSustainability['total_weight'], 1); ?></strong><small>公斤</small></div>
                <div><span>避免浪費估算</span><strong><?php echo number_format($enterpriseSustainability['food_waste_avoided'], 1); ?></strong><small>kgCO2e</small></div>
                <div><span>配送里程</span><strong><?php echo number_format($enterpriseSustainability['total_distance'], 1); ?></strong><small>公里</small></div>
            </div>
        </section>
    <?php endif; ?>

    <section class="dashboard-workspace <?php echo $canViewDonations ? '' : 'dashboard-workspace-single'; ?>" aria-labelledby="dashboard-workspace-title">
        <div class="dashboard-section-heading dashboard-workspace-heading">
            <div>
                <span class="dashboard-section-kicker">工作區</span>
                <h2 id="dashboard-workspace-title"><?php echo $canViewDonations ? '從最近捐贈，接續下一步' : '開始你的下一個公益行動'; ?></h2>
            </div>
        </div>

        <div class="dashboard-workspace-grid">
            <?php if ($canViewDonations): ?>
                <article class="card dashboard-recent-card">
                    <header class="dashboard-card-heading">
                        <span class="dashboard-card-icon dashboard-card-icon-blue"><i class="fas fa-inbox" aria-hidden="true"></i></span>
                        <span>
                            <h3>最近捐贈</h3>
                            <p>最新建立的物資捐贈紀錄</p>
                        </span>
                        <a class="dashboard-text-link" href="?page=<?php echo htmlspecialchars($donationListPage, ENT_QUOTES, 'UTF-8'); ?>">查看全部</a>
                    </header>
                    <div class="dashboard-card-content recent-donations-body">
                        <?php if (!empty($recentDonations)): ?>
                            <table class="data-table recent-donations-table">
                                <thead>
                                    <tr>
                                        <th>捐贈者</th>
                                        <th>類型</th>
                                        <th>數量</th>
                                        <th>狀態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentDonations as $donation): ?>
                                        <?php
                                        $donationTypeLabels = [
                                            'food' => '食物',
                                            'supplies' => '用品',
                                            'other' => '其他',
                                        ];
                                        $donationStatusLabels = [
                                            'pending' => '待評估',
                                            'assessed' => '已評估',
                                            'approved' => '已批准',
                                            'received' => '已收貨',
                                            'rejected' => '已拒絕',
                                            'published' => '已發布',
                                            'archived' => '已封存',
                                        ];
                                        $donationStatus = strtolower((string) ($donation['status'] ?? ''));
                                        $donationStatusLabel = $donationStatusLabels[$donationStatus] ?? '其他狀態';
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($donation['donor_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($donationTypeLabels[$donation['donation_type']] ?? $donation['donation_type']); ?></td>
                                            <td><?php echo htmlspecialchars($donation['quantity']); ?> <?php echo htmlspecialchars($donation['unit']); ?></td>
                                            <td><span class="status <?php echo htmlspecialchars('status-' . str_replace(' ', '_', $donationStatus)); ?>"><?php echo htmlspecialchars($donationStatusLabel); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox" aria-hidden="true"></i>
                                <p>目前沒有捐贈記錄</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endif; ?>

            <aside class="card dashboard-quick-card" aria-labelledby="dashboard-quick-title">
                <header class="dashboard-card-heading">
                    <span class="dashboard-card-icon dashboard-card-icon-gold"><i class="fas fa-bolt" aria-hidden="true"></i></span>
                    <span>
                        <h3 id="dashboard-quick-title">快速操作</h3>
                        <p>前往你最常使用的功能</p>
                    </span>
                </header>
                <div class="dashboard-card-content">
                    <div class="dashboard-quick-actions">
                        <?php foreach ($dashboardQuickActions as $action): ?>
                            <a class="dashboard-quick-action" href="?page=<?php echo htmlspecialchars($action['page'], ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="dashboard-quick-action-icon"><i class="fas <?php echo htmlspecialchars($action['icon']); ?>" aria-hidden="true"></i></span>
                                <span>
                                    <strong><?php echo htmlspecialchars($action['title']); ?></strong>
                                    <small><?php echo htmlspecialchars($action['description']); ?></small>
                                </span>
                                <i class="fas fa-arrow-right dashboard-quick-action-arrow" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                        <button class="dashboard-quick-action dashboard-quick-action-button" type="button" onclick="printTable()">
                            <span class="dashboard-quick-action-icon"><i class="fas fa-print" aria-hidden="true"></i></span>
                            <span>
                                <strong>列印目前頁面</strong>
                                <small>輸出成紙本或 PDF 報告</small>
                            </span>
                            <i class="fas fa-arrow-right dashboard-quick-action-arrow" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</div>
