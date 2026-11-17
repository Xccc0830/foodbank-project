<?php
require_once BASE_PATH . '/src/models/DonationModel.php';

$stores = [];
foreach ($rewardModel->getPartnerStoreOffers() as $offer) {
    $donorId = (int) $offer['donor_id'];
    if (!isset($stores[$donorId])) {
        $stores[$donorId] = ['profile' => $offer, 'offers' => []];
    }
    $stores[$donorId]['offers'][] = $offer;
}
$displayValue = static function ($value) {
    $value = trim((string) $value);
    return htmlspecialchars($value !== '' ? $value : '尚未提供', ENT_QUOTES, 'UTF-8');
};
?>

<div class="view-header">
    <div>
        <h1 class="view-title">合作商家與優惠</h1>
        <p class="view-subtitle">查看合作店家與各項優惠的使用方式。</p>
    </div>
    <a class="btn btn-secondary btn-sm" href="?page=rewards">返回心意幣兌換</a>
</div>

<div class="card mt-32">
    <div class="card-header">
        <h2>可使用心意幣的商家</h2>
        <p>僅顯示啟用中的方案；已兌完的方案不能再領取，但已領取的券仍可核銷。地址為店家登記的取貨地址，營業資訊由店家提供，前往前請先確認。</p>
    </div>
    <div class="card-body">
        <?php if (!$stores): ?>
            <div class="empty-state"><i class="fas fa-store"></i><p>目前沒有提供優惠的合作商家</p></div>
        <?php else: ?>
            <div class="reward-store-grid">
                <?php foreach ($stores as $store): $profile = $store['profile']; ?>
                    <section class="reward-store-card">
                        <h3><?php echo $displayValue($profile['donor_name']); ?></h3>
                        <dl class="reward-store-details">
                            <div><dt>店家登記地址（取貨地址）</dt><dd><?php echo $displayValue($profile['default_pickup_address']); ?></dd></div>
                            <div><dt>營業日</dt><dd><?php echo $displayValue(DonationModel::formatOpenDays($profile['open_days'])); ?></dd></div>
                            <div><dt>營業時間</dt><dd><?php echo $displayValue($profile['business_hours']); ?></dd></div>
                            <div><dt>公休日</dt><dd><?php echo $displayValue($profile['closed_days']); ?></dd></div>
                        </dl>
                        <h4>可用優惠</h4>
                        <ul class="reward-store-offers">
                            <?php foreach ($store['offers'] as $offer): ?>
                                <li>
                                    <strong><?php echo $displayValue($offer['title']); ?></strong>
                                    <p><span class="status status-info"><?php echo htmlspecialchars(rewardRuleText($offer), ENT_QUOTES, 'UTF-8'); ?></span></p>
                                    <?php if ($offer['stock'] !== null && (int) $offer['stock'] <= 0): ?><p>已兌完，已領取的券仍可核銷。</p><?php endif; ?>
                                    <?php if (trim((string) $offer['description']) !== ''): ?><p><?php echo nl2br(htmlspecialchars($offer['description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                                    <a class="btn btn-secondary btn-sm" href="?page=rewards#reward-donor-<?php echo (int) $offer['item_id']; ?>">查看兌換</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
