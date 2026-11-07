<?php
// 折扣商家一覽：所有登入使用者皆可查詢
require_once BASE_PATH . '/src/models/MerchantDiscountModel.php';

$merchantModel = new MerchantDiscountModel();
$keyword = trim((string) ($_GET['q'] ?? ''));
$category = (string) ($_GET['category'] ?? '');
$sort = (string) ($_GET['sort'] ?? 'name');
$merchants = $merchantModel->searchMerchants($keyword, $category, $sort);
$categories = MerchantDiscountModel::CATEGORIES;
$e = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="page-header">
    <h1>折扣商家一覽</h1>
    <p>GO Rider 可在以下合作商家以興毅幣折抵消費（1 興毅幣 = 1 元，不可兌現）。折抵須達商家滿額門檻，且不超過商家設定的折抵上限。</p>
</div>

<div class="card">
    <div class="card-header">
        <form method="get" class="toolbar-row">
            <input type="hidden" name="page" value="merchant_directory">
            <input type="search" name="q" value="<?php echo $e($keyword); ?>" placeholder="搜尋店名、地址或說明">
            <select name="category" class="filter-select" aria-label="店家類別">
                <option value="">全部類別</option>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?php echo $e($key); ?>" <?php echo $category === $key ? 'selected' : ''; ?>><?php echo $e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" class="filter-select" aria-label="排序方式">
                <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>依店名</option>
                <option value="percent" <?php echo $sort === 'percent' ? 'selected' : ''; ?>>折抵上限高到低</option>
                <option value="min_spend" <?php echo $sort === 'min_spend' ? 'selected' : ''; ?>>滿額門檻低到高</option>
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>最近更新</option>
            </select>
            <button class="btn btn-primary btn-sm" type="submit">查詢</button>
            <?php if ($keyword !== '' || $category !== ''): ?>
                <a class="btn btn-secondary btn-sm" href="?page=merchant_directory">清除</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body">
        <p class="text-muted">共 <?php echo count($merchants); ?> 家商家</p>
        <?php if ($merchants): ?>
            <div class="reward-grid">
                <?php foreach ($merchants as $merchant): ?>
                    <div class="reward-card merchant-card">
                        <h3><?php echo $e($merchant['store_name']); ?></h3>
                        <p class="text-muted"><?php echo $e($categories[$merchant['category']] ?? '其他'); ?></p>
                        <div class="reward-cost">
                            滿 <?php echo (int) $merchant['min_spend']; ?> 元・最多折 <?php echo (int) $merchant['max_discount_percent']; ?>%
                        </div>
                        <?php if (!empty($merchant['description'])): ?>
                            <p><?php echo nl2br($e($merchant['description'])); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($merchant['address'])): ?>
                            <p><i class="fa-solid fa-location-dot"></i> <?php echo $e($merchant['address']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($merchant['contact_phone'])): ?>
                            <p><i class="fa-solid fa-phone"></i> <?php echo $e($merchant['contact_phone']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p><?php echo ($keyword !== '' || $category !== '') ? '找不到符合條件的商家，請調整搜尋條件。' : '目前還沒有商家開放興毅幣折抵。'; ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
