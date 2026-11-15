<?php
require __DIR__ . '/../src/models/DeliveryModel.php';
$class = new class extends DeliveryModel {
    public function __construct() {}
};

$tests = [
    'motorcycle-free-distance-and-weight' => ['motorcycle', 1, 3, 'normal', 0, 0, 5],
    'motorcycle-progressive-distance' => ['motorcycle', 10, 3, 'normal', 0, 0, 37],
    'motorcycle-distance-over-10km' => ['motorcycle', 11, 3, 'normal', 0, 0, 42],
    'motorcycle-weight-limit' => ['motorcycle', 0, 25, 'normal', 0, 0, 32],
    'car-progressive-distance-and-weight' => ['car', 10, 50, 'normal', 0, 0, 66],
    'car-weight-over-50kg' => ['car', 0, 60, 'normal', 0, 0, 60],
    'priority-multiplier' => ['car', 0, 0, 'priority', 0, 0, 9],
    'urgent-multiplier' => ['car', 0, 0, 'urgent', 0, 0, 12],
    'early-10-percent' => ['car', 10, 50, 'normal', 100, 90, 73],
    'early-20-percent' => ['car', 10, 50, 'normal', 100, 80, 76],
    'early-30-percent' => ['car', 10, 50, 'normal', 100, 70, 79],
    'early-60-percent-is-suspicious' => ['car', 10, 50, 'normal', 100, 40, 66],
    'late-delivery-has-no-bonus' => ['car', 10, 50, 'normal', 100, 110, 66],
    'missing-time-has-no-bonus' => ['car', 10, 50, 'normal', 100, 0, 66],
];

$failures = 0;
foreach ($tests as $name => $test) {
    [$vehicle, $distance, $weight, $urgency, $estimated, $actual, $expected] = $test;
    $actualPoints = DeliveryModel::calculatePoints($vehicle, $distance, $weight, $urgency, $estimated, $actual);
    if ($actualPoints !== $expected) {
        fwrite(STDERR, "FAIL {$name}: expected {$expected}, got {$actualPoints}\n");
        $failures++;
    }
}

if ($failures > 0) {
    exit(1);
}

echo 'PASS ' . count($tests) . ' points calculation cases' . PHP_EOL;

