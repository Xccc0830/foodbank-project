<?php
define('BASE_PATH', dirname(__DIR__));

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="test">';
}

class RewardEditorTestModel {
    public function getAvailableRewards() {
        return [
            ['source_type' => 'donor', 'source_id' => 1, 'source_owner_id' => 11,
             'title' => '本店方案', 'description' => '', 'category' => 'discount', 'stock' => null,
             'cost_points' => 20, 'rule_type' => 'min_spend_discount', 'min_spend' => 100,
             'discount_amount' => 20, 'discount_rate' => null],
            ['source_type' => 'donor', 'source_id' => 2, 'source_owner_id' => 13,
             'title' => '其他店家', 'description' => '', 'category' => 'discount', 'stock' => null,
             'cost_points' => 15, 'rule_type' => 'min_spend_percentage', 'min_spend' => 150,
             'retail_price' => null, 'discount_amount' => null, 'discount_rate' => '0.90'],
        ];
    }
}

function renderEditor($editor, $query, $input = [], $admin = false) {
    global $rewardModel, $currentUser;
    $_GET = $query;
    $_SESSION['reward_editor_input'] = $input;
    $canCreateCatalogReward = $admin;
    $message = null;
    ob_start();
    include BASE_PATH . '/src/views/reward_editor.php';
    return ob_get_clean();
}

$rewardModel = new RewardEditorTestModel();
$currentUser = ['user_id' => 11];
$checks = [];
$selection = renderEditor('create', []);
$checks['新增時先顯示三種模板，未選擇前不顯示表單'] =
    substr_count($selection, 'class="reward-template-card') === 3 && strpos($selection, '<form method="post"') === false;

foreach (['direct_exchange', 'min_spend_discount', 'min_spend_percentage'] as $template) {
    $html = renderEditor('create', ['template' => $template]);
    $expected = [
        'direct_exchange' => ['cost_points', 'retail_price'],
        'min_spend_discount' => ['min_spend', 'discount_amount'],
        'min_spend_percentage' => ['min_spend', 'cost_points', 'discount_rate'],
    ][$template];
    $ok = strpos($html, 'name="csrf_token"') !== false
        && strpos($html, 'name="rule_type" value="' . $template . '"') !== false;
    foreach (['cost_points', 'min_spend', 'retail_price', 'discount_amount', 'discount_rate'] as $field) {
        $ok = $ok && ((strpos($html, 'name="' . $field . '"') !== false) === in_array($field, $expected, true));
    }
    $checks[$template . ' 僅顯示對應欄位並包含 CSRF'] = $ok;
}

$edit = renderEditor('edit', ['item_id' => 1]);
$checks['編輯自動選取原模板並填入設定'] = strpos($edit, 'name="rule_type" value="min_spend_discount"') !== false
    && strpos($edit, 'value="本店方案"') !== false && strpos($edit, 'value="100"') !== false;
$other = renderEditor('edit', ['item_id' => 2]);
$checks['商家不能開啟其他商家的編輯頁'] = strpos($other, '無權編輯') !== false && strpos($other, '<form method="post"') === false;
$admin = renderEditor('edit', ['item_id' => 2], [], true);
$checks['食物銀行仍可管理商家方案'] = strpos($admin, 'value="其他店家"') !== false;
$retry = renderEditor('create', ['template' => 'min_spend_discount'], ['title' => '重填 <方案>', 'min_spend' => '10', 'discount_amount' => '20']);
$checks['失敗時保留輸入並安全跳脫'] = strpos($retry, '重填 &lt;方案&gt;') !== false
    && strpos($retry, 'value="10"') !== false && strpos($retry, 'value="20"') !== false;

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    $failed = $failed || !$ok;
}
exit($failed ? 1 : 0);
