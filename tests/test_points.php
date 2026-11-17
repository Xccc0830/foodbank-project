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

check('motorcycle 4.5km 12kg urgent', DeliveryModel::calculatePoints('motorcycle', 4.5, 12, 'urgent'), 18);
check('motorcycle short light normal', DeliveryModel::calculatePoints('motorcycle', 1, 3, 'normal'), 6);
check('car short light normal', DeliveryModel::calculatePoints('car', 1, 3, 'normal'), 8);
check('car 10km 10kg priority', DeliveryModel::calculatePoints('car', 10, 10, 'priority'), 17);
check('car far heavy urgent', DeliveryModel::calculatePoints('car', 12, 25, 'urgent'), 30);
check('distance boundary 2km stays in first tier', DeliveryModel::calculatePoints('motorcycle', 2, 5, 'normal'), 6);
check('distance just over 2km moves to next tier', DeliveryModel::calculatePoints('motorcycle', 2.1, 5, 'normal'), 8);
check('unknown urgency adds nothing', DeliveryModel::calculatePoints('motorcycle', 1, 3, 'whatever'), 6);
check('cold storage adds 2', DeliveryModel::calculatePoints('motorcycle', 1, 3, 'normal', true), 8);
check('weight bonus capped at 15', DeliveryModel::calculatePoints('car', 1, 200, 'normal'), 20);

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}
echo "All points tests passed.\n";
