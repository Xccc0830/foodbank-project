<?php
require __DIR__ . '/../src/models/MerchantDiscountModel.php';

$failures = 0;
function check($name, $actual, $expected) {
    global $failures;
    if ($actual === $expected) {
        echo "PASS {$name}\n";
    } else {
        echo "FAIL {$name} (expected {$expected}, got {$actual})\n";
        $failures++;
    }
}

check('example: min 100, 20%, bill 150, balance 30', MerchantDiscountModel::calculateDiscount(150, 30, 100, 20), 30);
check('capped by percent', MerchantDiscountModel::calculateDiscount(150, 500, 100, 20), 30);
check('capped by balance', MerchantDiscountModel::calculateDiscount(500, 12, 100, 20), 12);
check('below minimum spend gives 0', MerchantDiscountModel::calculateDiscount(99, 500, 100, 20), 0);
check('exactly minimum spend qualifies', MerchantDiscountModel::calculateDiscount(100, 500, 100, 20), 20);
check('percent rounds down', MerchantDiscountModel::calculateDiscount(109, 500, 0, 10), 10);
check('zero balance gives 0', MerchantDiscountModel::calculateDiscount(200, 0, 0, 50), 0);
check('negative balance treated as 0', MerchantDiscountModel::calculateDiscount(200, -5, 0, 50), 0);
check('100 percent never exceeds bill', MerchantDiscountModel::calculateDiscount(80, 999, 0, 100), 80);
check('invalid bill gives 0', MerchantDiscountModel::calculateDiscount(0, 50, 0, 20), 0);
check('cap remaining limits discount', MerchantDiscountModel::calculateDiscount(500, 500, 0, 50, 40), 40);
check('cap remaining 0 gives 0', MerchantDiscountModel::calculateDiscount(500, 500, 0, 50, 0), 0);

$day = 86400;
$now = strtotime('2026-11-04 12:00:00');
$old = strtotime('-13 months', $now);
$mid = strtotime('-11 months', $now);
$new = strtotime('-1 day', $now);
$lots = [['ts' => $new, 'points' => 50], ['ts' => $old, 'points' => 30], ['ts' => $mid, 'points' => 20]];
check('expired lot is not usable', MerchantDiscountModel::computeUsableBalance($lots, 0, $now), 70);
check('FIFO spend consumes expired lot first', MerchantDiscountModel::computeUsableBalance($lots, 30, $now), 70);
check('FIFO spend then consumes next oldest', MerchantDiscountModel::computeUsableBalance($lots, 40, $now), 60);
check('spend everything', MerchantDiscountModel::computeUsableBalance($lots, 100, $now), 0);
check('lot expires after 12 months', MerchantDiscountModel::computeUsableBalance([['ts' => $mid, 'points' => 20]], 0, strtotime('+2 months', $now)), 0);

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}
echo "All tests passed.\n";
