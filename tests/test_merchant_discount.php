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

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}
echo "All tests passed.\n";
