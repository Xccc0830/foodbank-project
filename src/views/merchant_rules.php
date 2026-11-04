<?php
// 店家折扣設定：愛心商家自行填寫折扣方式（1 興毅幣 = 1 元，自訂滿額門檻與折抵上限）
require_once BASE_PATH . '/src/models/MerchantDiscountModel.php';

$merchantModel = new MerchantDiscountModel();
$merchantId = (int) ($currentUser['user_id'] ?? 0);
$flashKey = 'merchant_rules_flash';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rule') {
    $result = $merchantModel->saveRule($merchantId, $_POST);
    $_SESSION[$flashKey] = $result === true
        ? ['type' => 'success', 'text' => '折扣方案已儲存。']
        : ['type' => 'error', 'text' => $result];
    header('Location: ?page=merchant_rules');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);

$rule = $merchantModel->getRule($merchantId);
$values = $rule ?: [
    'store_name' => $currentUser['enterprise_name'] ?? ($currentUser['full_name'] ?? ''),
    'category' => 'other',
    'address' => '',
    'contact_phone' => '',
    'description' => '',
    'min_spend' => 100,
    'max_discount_percent' => 10,
    'status' => 'active',
];
$e = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="page-header">
    <h1>店家折扣設定</h1>
    <p>填寫貴店接受興毅幣折抵的方式。1 興毅幣 = 1 元，不可兌現；折抵金額 = min(騎手持有幣數, 消費金額 × 折抵上限%)，且消費須達滿額門檻。</p>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>"><?php echo $e($flash['text']); ?></div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>折扣方案</h3></div>
        <div class="card-body">
            <form method="POST" action="?page=merchant_rules">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_rule">
                <div class="form-group">
                    <label for="store_name">店家名稱</label>
                    <input type="text" id="store_name" name="store_name" maxlength="100" required value="<?php echo $e($values['store_name']); ?>">
                </div>
                <div class="form-group">
                    <label for="category">店家類別</label>
                    <select id="category" name="category">
                        <?php foreach (MerchantDiscountModel::CATEGORIES as $key => $label): ?>
                            <option value="<?php echo $e($key); ?>" <?php echo $values['category'] === $key ? 'selected' : ''; ?>><?php echo $e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="min_spend">滿額門檻（元，0 = 不限）</label>
                    <input type="number" id="min_spend" name="min_spend" min="0" max="100000" step="1" required value="<?php echo (int) $values['min_spend']; ?>">
                </div>
                <div class="form-group">
                    <label for="max_discount_percent">單筆折抵上限（占消費金額 %）</label>
                    <input type="number" id="max_discount_percent" name="max_discount_percent" min="1" max="100" step="1" required value="<?php echo (int) $values['max_discount_percent']; ?>">
                </div>
                <div class="form-group">
                    <label for="address">店家地址</label>
                    <input type="text" id="address" name="address" maxlength="200" value="<?php echo $e($values['address']); ?>">
                </div>
                <div class="form-group">
                    <label for="contact_phone">聯絡電話</label>
                    <input type="text" id="contact_phone" name="contact_phone" maxlength="30" value="<?php echo $e($values['contact_phone']); ?>">
                </div>
                <div class="form-group">
                    <label for="description">折扣說明（例：限內用、不與其他優惠併用）</label>
                    <textarea id="description" name="description" rows="3" maxlength="500"><?php echo $e($values['description']); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="status">方案狀態</label>
                    <select id="status" name="status">
                        <option value="active" <?php echo $values['status'] === 'active' ? 'selected' : ''; ?>>啟用（顯示於折扣商家一覽）</option>
                        <option value="inactive" <?php echo $values['status'] === 'inactive' ? 'selected' : ''; ?>>暫停</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">儲存折扣方案</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>試算範例</h3></div>
        <div class="card-body">
            <p>目前設定：消費滿 <strong id="previewMin"><?php echo (int) $values['min_spend']; ?></strong> 元，最多折抵 <strong id="previewPercent"><?php echo (int) $values['max_discount_percent']; ?></strong>%。</p>
            <p>例如消費 <strong id="previewBill"></strong> 元、騎手持有足夠興毅幣時，最多折抵 <strong id="previewCoins"></strong> 元，實付 <strong id="previewFinal"></strong> 元。</p>
            <p class="text-muted">折扣成本由貴店自行吸收，食物銀行每月提供折抵對帳。核銷請至「興毅幣折抵」頁輸入騎手憑證與消費金額。</p>
        </div>
    </div>
</div>

<script>
(function () {
    var minInput = document.getElementById('min_spend');
    var percentInput = document.getElementById('max_discount_percent');
    function refresh() {
        var min = Math.max(0, parseInt(minInput.value, 10) || 0);
        var percent = Math.min(100, Math.max(0, parseInt(percentInput.value, 10) || 0));
        var bill = Math.max(min, 100);
        var coins = Math.floor(bill * percent / 100);
        document.getElementById('previewMin').textContent = min;
        document.getElementById('previewPercent').textContent = percent;
        document.getElementById('previewBill').textContent = bill;
        document.getElementById('previewCoins').textContent = coins;
        document.getElementById('previewFinal').textContent = bill - coins;
    }
    minInput.addEventListener('input', refresh);
    percentInput.addEventListener('input', refresh);
    refresh();
})();
</script>
