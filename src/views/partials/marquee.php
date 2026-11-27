<?php
/**
 * 跑馬燈：輪播最新廣告、訊息與即將舉辦的公益活動。
 * 使用前需先載入 AdvertisementModel 與 ActivityModel。
 */

$marqueeItems = [];
$marqueeAds = isset($activeAds) ? array_slice($activeAds, 0, 8) : (new AdvertisementModel())->getActiveAds(8);
$marqueeActivities = isset($newsActivities) ? $newsActivities : (new ActivityModel())->getAllActivities();
foreach ($marqueeAds as $marqueeAd) {
    $category = ($marqueeAd['content_type'] ?? 'advertisement') === 'message' ? '訊息' : '廣告';
    $marqueeItems[] = $category . '｜' . $marqueeAd['title'];
}
foreach ($marqueeActivities as $marqueeActivity) {
    if (in_array($marqueeActivity['status'], ['planned', 'ongoing'], true) && strtotime($marqueeActivity['start_at']) >= strtotime('today')) {
        $marqueeItems[] = '活動｜' . $marqueeActivity['title'] . '（' . date('m/d H:i', strtotime($marqueeActivity['start_at'])) . '）';
    }
}
$marqueeItems = array_slice($marqueeItems, 0, 12);
?>
<?php if ($marqueeItems): ?>
    <div class="news-marquee" role="marquee" aria-label="最新消息跑馬燈">
        <i class="fas fa-bullhorn" aria-hidden="true"></i>
        <div class="news-marquee-viewport">
            <div class="news-marquee-track">
                <?php foreach (array_merge($marqueeItems, $marqueeItems) as $marqueeText): ?>
                    <span class="news-marquee-item"><?php echo htmlspecialchars($marqueeText); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
