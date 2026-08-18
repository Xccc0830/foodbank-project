<?php
require __DIR__ . '/../src/models/DeliveryModel.php';
$class = new class extends DeliveryModel {
    public function __construct() {}
};
echo $class->calculatePoints("motorcycle", 4.5, 12, "urgent") . PHP_EOL;

