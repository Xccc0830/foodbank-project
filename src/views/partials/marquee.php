<?php
/**
 * 跑馬燈：輪播最新廣告與即將舉辦的公益活動。
 * 使用前需先載入 AdvertisementModel 與 ActivityModel。
 */

$marqueeItems = [];
foreach ((new AdvertisementModel())->getActiveAds(8) as $marqueeAd) {
    $marqueeItems[] = '廣告｜' . $marqueeAd['title'];
}
foreach ((new ActivityModel())->getAllActivities() as $marqueeActivity) {
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
