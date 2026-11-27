<?php

class CarbonPointHelper {
    public const DEFAULT_CARBON_FACTOR = 2.5;

    public static function maximumWeightForVehicle($vehicle) {
        return ['motorcycle' => 20.0, 'car' => 50.0][$vehicle] ?? null;
    }

    public static function calculate($vehicle, $weight) {
        $maximumWeight = self::maximumWeightForVehicle($vehicle);
        if ($maximumWeight === null
            || !is_numeric($weight) || !is_finite((float) $weight)
            || (float) $weight < 0 || (float) $weight > $maximumWeight) {
            throw new InvalidArgumentException('配送車種或重量不符合心意幣計算規範。');
        }

        $avoidedEmissions = (float) $weight * self::DEFAULT_CARBON_FACTOR;
        return 1 + (int) floor($avoidedEmissions + 1e-9);
    }
}
