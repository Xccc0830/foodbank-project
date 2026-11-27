<?php
require __DIR__ . '/../src/helpers/CarbonPointHelper.php';

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

check('one point baseline plus avoided carbon for 10 kg', CarbonPointHelper::calculate('motorcycle', 10), 26);
check('vehicle does not change carbon points', CarbonPointHelper::calculate('car', 10), 26);
check('baseline applies when there is no avoided carbon', CarbonPointHelper::calculate('motorcycle', 0), 1);
check('fractional carbon is rounded down after baseline', CarbonPointHelper::calculate('motorcycle', 0.2), 1);
check('one kg CO2e maps to one point in addition to baseline', CarbonPointHelper::calculate('motorcycle', 0.4), 2);
check('motorcycle weight limit', CarbonPointHelper::maximumWeightForVehicle('motorcycle'), 20.0);
check('car weight limit', CarbonPointHelper::maximumWeightForVehicle('car'), 50.0);

try {
    CarbonPointHelper::calculate('motorcycle', 20.01);
    check('motorcycle over-limit weight rejected', false, true);
} catch (InvalidArgumentException $exception) {
    check('motorcycle over-limit weight rejected', true, true);
}

try {
    CarbonPointHelper::calculate('car', 50.01);
    check('car over-limit weight rejected', false, true);
} catch (InvalidArgumentException $exception) {
    check('car over-limit weight rejected', true, true);
}

try {
    CarbonPointHelper::calculate('bicycle', 1);
    check('unknown vehicle rejected', false, true);
} catch (InvalidArgumentException $exception) {
    check('unknown vehicle rejected', true, true);
}

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}
echo "All points tests passed.\n";
