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

check('motorcycle normal carbon formula rounds down', DeliveryModel::calculatePoints('motorcycle', 5, 10, 'normal'), 16);
check('motorcycle urgent carbon formula', DeliveryModel::calculatePoints('motorcycle', 10, 20, 'urgent'), 42);
check('car normal carbon formula', DeliveryModel::calculatePoints('car', 5, 10, 'normal'), 18);
check('car urgent formula is capped at 60', DeliveryModel::calculatePoints('car', 15, 50, 'urgent'), 60);
check('priority multiplier', DeliveryModel::calculatePoints('motorcycle', 0, 0, 'priority'), 6);
check('motorcycle weight limit', DeliveryModel::maximumWeightForVehicle('motorcycle'), 20.0);
check('car weight limit', DeliveryModel::maximumWeightForVehicle('car'), 50.0);

try {
    DeliveryModel::calculatePoints('motorcycle', 1, 20.01, 'normal');
    check('motorcycle over-limit weight rejected', false, true);
} catch (InvalidArgumentException $exception) {
    check('motorcycle over-limit weight rejected', true, true);
}

try {
    DeliveryModel::calculatePoints('car', 1, 50.01, 'normal');
    check('car over-limit weight rejected', false, true);
} catch (InvalidArgumentException $exception) {
    check('car over-limit weight rejected', true, true);
}

try {
    DeliveryModel::calculatePoints('motorcycle', 1, 1, 'unknown');
    check('unknown urgency rejected', false, true);
} catch (InvalidArgumentException $exception) {
    check('unknown urgency rejected', true, true);
}

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}
echo "All points tests passed.\n";
