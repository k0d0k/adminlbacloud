<?php

namespace Modules\Patients\Support;

/**
 * Body mass index (Phase 3A).
 *
 * Derived only when BOTH weight and height are present and physically sensible,
 * so an encounter that recorded just a weight never shows a fabricated BMI.
 */
final class BodyMassIndex
{
    public static function calculate(?float $weightKg, ?float $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null) {
            return null;
        }

        if ($weightKg <= 0 || $heightCm <= 0) {
            return null;
        }

        $heightM = $heightCm / 100;

        return round($weightKg / ($heightM * $heightM), 2);
    }
}
