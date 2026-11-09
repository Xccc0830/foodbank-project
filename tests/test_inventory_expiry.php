<?php
// 即期品提醒：依「先到期先出」推算剩下的批次，只回傳在提醒天數內（含已過期）的批次。純計算，不碰資料庫。
require_once __DIR__ . '/../src/models/InventoryReceiptModel.php';

function check($label, $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$lot = function ($name, $category, $type, $qty, $expiry) {
    return ['item_name' => $name, 'category' => $category, 'unit' => '瓶', 'movement_type' => $type, 'quantity' => $qty, 'expiry_date' => $expiry];
};
$today = '2026-11-10';
$alerts = ['乳製品' => 3, '罐頭食品' => 30];

// 鮮奶：兩批（11/11 到期 5 瓶、11/20 到期 5 瓶），已取出 5 瓶 → 早到期那批用完，剩下 11/20 那批（10 天後，超過 3 天不提醒）
$rows = [
    $lot('鮮奶', '乳製品', 'in', 5, '2026-11-11'),
    $lot('鮮奶', '乳製品', 'in', 5, '2026-11-20'),
    $lot('鮮奶', '乳製品', 'out', 5, null),
];
check('早到期的批次已出庫，不再提醒', InventoryReceiptModel::computeExpiringLots($rows, $alerts, 7, $today) === []);

// 只取出 3 瓶 → 11/11 那批還剩 2 瓶，明天到期要提醒
$rows[2]['quantity'] = 3;
$result = InventoryReceiptModel::computeExpiringLots($rows, $alerts, 7, $today);
check('剩下的早到期批次會提醒', count($result) === 1 && $result[0]['quantity'] == 2 && $result[0]['days_left'] === 1);

// 罐頭 20 天後到期，提醒天數 30 → 提醒；已過期的也要列出，且排在最前面
$rows = [
    $lot('鮪魚罐頭', '罐頭食品', 'in', 4, '2026-11-30'),
    $lot('優格', '乳製品', 'in', 2, '2026-11-08'),
];
$result = InventoryReceiptModel::computeExpiringLots($rows, $alerts, 7, $today);
check('依分類天數提醒，已過期排最前', count($result) === 2 && $result[0]['item_name'] === '優格' && $result[0]['days_left'] === -2 && $result[1]['alert_days'] === 30);

// 沒填有效期限、或分類沒設定時用預設天數
$rows = [
    $lot('衛生紙', '其他', 'in', 3, null),
    $lot('餅乾', '未知分類', 'in', 3, '2026-11-15'),
];
$result = InventoryReceiptModel::computeExpiringLots($rows, $alerts, 7, $today);
check('沒有期限不提醒、未設定分類用預設 7 天', count($result) === 1 && $result[0]['item_name'] === '餅乾' && $result[0]['alert_days'] === 7);

if (!empty($failed)) {
    exit(1);
}
echo PHP_EOL . 'All inventory expiry tests passed.' . PHP_EOL;
