<?php
require_once BASE_PATH . '/src/models/DonationModel.php';

$search = isset($_GET['store_search']) && is_string($_GET['store_search']) ? trim($_GET['store_search']) : '';
$ruleType = isset($_GET['store_rule']) && is_string($_GET['store_rule']) ? $_GET['store_rule'] : '';
$ruleTypes = [
    'direct_exchange' => '商品直換',
    'min_spend_discount' => '滿額折抵',
    'min_spend_percentage' => '滿額打折',
];
$invalidRule = $ruleType !== '' && !array_key_exists($ruleType, $ruleTypes);
$stores = [];
foreach ($rewardModel->getPartnerStoreOffers() as $offer) {
    if ($ruleType !== '' && $offer['rule_type'] !== $ruleType) {
        continue;
    }
    if ($search !== '') {
        $searchable = implode(' ', [
            $offer['donor_name'], $offer['default_pickup_address'], $offer['title'], $offer['description'],
        ]);
        if (mb_stripos($searchable, $search, 0, 'UTF-8') === false) {
            continue;
        }
    }
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
        <p>地址為店家登記的取貨地址，營業資訊由店家提供，前往前請先確認。</p>
    </div>
    <div class="card-body">
        <form method="get" class="reward-store-filter">
            <input type="hidden" name="page" value="rewards">
            <input type="hidden" name="stores" value="1">
            <div class="form-group">
                <label for="store-search">搜尋店家、地址或優惠</label>
                <input id="store-search" type="search" name="store_search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="輸入關鍵字">
            </div>
            <div class="form-group">
                <label for="store-rule">優惠方式</label>
                <select id="store-rule" name="store_rule">
                    <option value="">全部優惠</option>
                    <?php if ($invalidRule): ?><option value="<?php echo htmlspecialchars($ruleType, ENT_QUOTES, 'UTF-8'); ?>" selected>無效的優惠方式</option><?php endif; ?>
                    <?php foreach ($ruleTypes as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $ruleType === $value ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">搜尋</button>
            <a class="btn btn-secondary btn-sm" href="?page=rewards&amp;stores=1">清除</a>
        </form>
        <?php if ($invalidRule): ?><p role="alert">優惠方式無效，請重新選擇。</p><?php endif; ?>
        <p class="reward-store-summary">共 <?php echo count($stores); ?> 間商家。已兌完的方案不能再領取，但已領取的券仍可核銷。</p>
        <?php if (!$stores): ?>
            <div class="empty-state"><i class="fas fa-store"></i><p><?php echo $search !== '' || $ruleType !== '' ? '沒有符合條件的商家，請調整搜尋或篩選條件。' : '目前沒有提供優惠的合作商家'; ?></p></div>
        <?php else: ?>
            <div class="reward-store-list">
                <?php foreach ($stores as $store): $profile = $store['profile']; ?>
                    <section class="reward-store-row">
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
                                    <?php if ($offer['rule_type'] === 'direct_exchange' && ($offer['retail_price'] === null || (int) $offer['cost_points'] > (int) $offer['retail_price'])): ?><p role="alert">店家尚未補登有效售價，此方案暫停兌換。</p><?php endif; ?>
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
