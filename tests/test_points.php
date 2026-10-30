<?php
require __DIR__ . '/../src/models/DeliveryModel.php';

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

check('motorcycle 4.5km 12kg urgent', DeliveryModel::calculatePoints('motorcycle', 4.5, 12, 'urgent'), 24);
check('motorcycle short light normal', DeliveryModel::calculatePoints('motorcycle', 1, 3, 'normal'), 8);
check('car short light normal', DeliveryModel::calculatePoints('car', 1, 3, 'normal'), 20);
check('car 10km 10kg priority', DeliveryModel::calculatePoints('car', 10, 10, 'priority'), 38);
check('car far heavy urgent', DeliveryModel::calculatePoints('car', 12, 25, 'urgent'), 52);
check('distance boundary 2km stays in first tier', DeliveryModel::calculatePoints('motorcycle', 2, 5, 'normal'), 8);
check('distance just over 2km moves to next tier', DeliveryModel::calculatePoints('motorcycle', 2.1, 5, 'normal'), 10);
check('unknown urgency adds nothing', DeliveryModel::calculatePoints('motorcycle', 1, 3, 'whatever'), 8);

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}
echo "All points tests passed.\n";
