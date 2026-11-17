<?php
// 此頁由 rewards.php 在驗證頁面權限及角色後載入。
$editing = $editor === 'edit';
$itemId = $editing ? (int) ($_GET['item_id'] ?? 0) : 0;
$reward = [];
if ($editing) {
    foreach ($rewardModel->getAvailableRewards() as $candidate) {
        if ($candidate['source_type'] === 'donor' && (int) $candidate['source_id'] === $itemId
            && ($canCreateCatalogReward || (int) $candidate['source_owner_id'] === (int) $currentUser['user_id'])) {
            $reward = $candidate;
            break;
        }
    }
    if (!$reward) {
        http_response_code(404);
        ?>
        <div class="card"><div class="card-body">
            <p role="alert">找不到可編輯的方案，或您無權編輯此方案。</p>
            <a class="btn btn-secondary btn-sm" href="?page=rewards">返回心意幣兌換</a>
        </div></div>
        <?php
        return;
    }
}

$templates = [
    'direct_exchange' => ['商品直換', '固定點數兌換商品，不需現金。', '例如：80 點換價值 80 元的便當'],
    'min_spend_discount' => ['滿額現金折抵', '達最低消費後，每點折抵 1 元。', '例如：滿 100 元，花 20 點折 20 元'],
    'min_spend_percentage' => ['滿額點數打折', '達最低消費後，以點數解鎖整單折扣。', '例如：滿 150 元，花 15 點享 9 折'],
];
$requestedTemplate = $_GET['template'] ?? ($editing ? $reward['rule_type'] : '');
$template = is_string($requestedTemplate) && isset($templates[$requestedTemplate]) ? $requestedTemplate : '';
$editorInput = $_SESSION['reward_editor_input'] ?? [];
unset($_SESSION['reward_editor_input']);
$values = array_replace($reward, $editorInput);
$baseUrl = '?page=rewards&editor=' . ($editing ? 'edit&item_id=' . $itemId : 'create');
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<div class="view-header">
    <div>
        <h1 class="view-title"><?php echo $editing ? '編輯商家優惠方案' : '選擇商家優惠模板'; ?></h1>
        <p class="view-subtitle">選擇一種優惠方式，再填入貴店家的條件。每個方案可獨立設定。</p>
    </div>
    <a class="btn btn-secondary btn-sm" href="?page=rewards">返回心意幣兌換</a>
</div>

<?php if ($message): ?>
    <div class="reward-verification-notice reward-verification-notice-<?php echo $escape($message['type']); ?>" role="alert">
        <p><?php echo $escape($message['text']); ?></p>
    </div>
<?php endif; ?>

<div class="card mt-32">
    <div class="card-header">
        <h2>步驟一：選擇優惠方式</h2>
        <p>範例僅供參考，實際金額與點數由店家自行填寫。切換模板會重新載入頁面，請先儲存已填資料。</p>
    </div>
    <div class="card-body reward-template-grid">
        <?php foreach ($templates as $key => $details): ?>
            <a class="reward-template-card<?php echo $template === $key ? ' is-selected' : ''; ?>"
               href="<?php echo $escape($baseUrl . '&template=' . $key); ?>"
               <?php echo $template === $key ? 'aria-current="true"' : ''; ?>>
                <strong><?php echo $escape($details[0]); ?></strong>
                <span><?php echo $escape($details[1]); ?></span>
                <small><?php echo $escape($details[2]); ?></small>
                <span class="reward-template-action"><?php echo $template === $key ? '已選擇' : '選擇此模板'; ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($template !== ''): ?>
<div class="card mt-32">
    <div class="card-header">
        <h2>步驟二：填寫<?php echo $escape($templates[$template][0]); ?>方案</h2>
        <p><?php echo $escape($templates[$template][2]); ?>。實際條件請自行輸入。</p>
    </div>
    <div class="card-body">
        <form method="post" action="?page=rewards">
            <?php echo csrfField(); ?>
            <input type="hidden" name="editor_flow" value="1">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_donor_reward' : 'create_donor_reward'; ?>">
            <input type="hidden" name="rule_type" value="<?php echo $escape($template); ?>">
            <?php if ($editing): ?><input type="hidden" name="item_id" value="<?php echo $itemId; ?>"><?php endif; ?>
            <div class="form-group">
                <label for="reward-title">方案名稱 *</label>
                <input id="reward-title" name="title" maxlength="150" value="<?php echo $escape($values['title'] ?? ''); ?>" required>
            </div>
            <?php if ($template === 'direct_exchange'): ?>
                <div class="form-group">
                    <label for="reward-points">兌換所需點數 *</label>
                    <input id="reward-points" type="number" name="cost_points" min="1" max="2147483647" step="1" value="<?php echo $escape($values['cost_points'] ?? ''); ?>" placeholder="例如 80" required>
                    <small>1 點相當於 1 元商品價值；商品直換不需現金。</small>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label for="reward-min-spend">最低消費（元）*</label>
                    <input id="reward-min-spend" type="number" name="min_spend" min="1" max="99999999" step="1" value="<?php echo $escape($values['min_spend'] ?? ''); ?>" placeholder="<?php echo $template === 'min_spend_discount' ? '例如 100' : '例如 150'; ?>" required>
                </div>
                <?php if ($template === 'min_spend_discount'): ?>
                    <div class="form-group">
                        <label for="reward-discount-amount">折抵金額（元／點）*</label>
                        <input id="reward-discount-amount" type="number" name="discount_amount" min="1" max="99999999" step="1" value="<?php echo $escape($values['discount_amount'] ?? ''); ?>" placeholder="例如 20" required>
                        <small>所需點數等於折抵金額，且折抵金額不得超過最低消費。</small>
                    </div>
                <?php else: ?>
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="reward-points">解鎖所需點數 *</label>
                            <input id="reward-points" type="number" name="cost_points" min="1" max="2147483647" step="1" value="<?php echo $escape($values['cost_points'] ?? ''); ?>" placeholder="例如 15" required>
                        </div>
                        <div class="form-group">
                            <label for="reward-rate">折扣比例 *</label>
                            <select id="reward-rate" name="discount_rate" required>
                                <?php for ($i = 99; $i >= 1; $i--): $rate = number_format($i / 100, 2, '.', ''); ?>
                                    <option value="<?php echo $rate; ?>" <?php echo $rate === ($values['discount_rate'] ?? '0.90') ? 'selected' : ''; ?>><?php echo rtrim(rtrim(number_format($i / 10, 1), '0'), '.'); ?> 折</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <p class="toolbar-meta">折扣後實收金額四捨五入至整元。</p>
                <?php endif; ?>
            <?php endif; ?>
            <div class="grid-2">
                <div class="form-group">
                    <label for="reward-category">分類 *</label>
                    <select id="reward-category" name="category" required>
                        <?php foreach (['discount' => '折扣優惠', 'product' => '實體商品', 'experience' => '體驗活動', 'other' => '其他'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo ($values['category'] ?? ($template === 'direct_exchange' ? 'product' : 'discount')) === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reward-stock">庫存（留空為不限量）</label>
                    <input id="reward-stock" type="number" name="stock" min="0" max="2147483647" step="1" value="<?php echo $escape($values['stock'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="reward-description">使用說明</label>
                <textarea id="reward-description" name="description" placeholder="例如：適用品項與使用限制"><?php echo $escape($values['description'] ?? ''); ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit"><?php echo $editing ? '儲存修改' : '新增方案'; ?></button>
            <a class="btn btn-secondary" href="?page=rewards">取消</a>
        </form>
    </div>
</div>
<?php endif; ?>
