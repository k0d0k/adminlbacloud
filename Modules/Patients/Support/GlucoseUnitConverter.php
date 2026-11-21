<?php

namespace Modules\Patients\Support;

use InvalidArgumentException;

/**
 * Glucose unit conversion (Phase 3B).
 *
 * Blood glucose is reported in mg/dL in Indonesia/most of SE Asia and mmol/L in
 * much of the world, so both must be accepted. The ORIGINAL value and unit are
 * always kept verbatim on the reading; this converter only produces the derived
 * canonical value stored alongside it, so a chart or comparison can use one
 * unit without ever rewriting what the operator typed.
 *
 * Conversion uses the glucose molar mass (180.156 g/mol), i.e.
 * 1 mmol/L = 18.0182 mg/dL. HbA1c is a percentage and is NOT converted here.
 */
final class GlucoseUnitConverter
{
    /** mg/dL per 1 mmol/L, from the molar mass of glucose. */
    public const MGDL_PER_MMOL = 18.0182;

    public const UNIT_MGDL = 'mg/dL';

    public const UNIT_MMOL = 'mmol/L';

    /** @var list<string> */
    public const SUPPORTED_UNITS = [
        self::UNIT_MGDL,
        self::UNIT_MMOL,
    ];

    /**
     * Normalise a loosely-typed unit string to its canonical spelling, or
     * `null` when it is not a recognised glucose unit.
     */
    public static function normalizeUnit(string $unit): ?string
    {
        $key = strtolower(str_replace([' ', '·'], '', $unit));

        return match ($key) {
            'mg/dl', 'mgdl' => self::UNIT_MGDL,
            'mmol/l', 'mmoll', 'mmol' => self::UNIT_MMOL,
            default => null,
        };
    }

    public static function supports(string $unit): bool
    {
        return self::normalizeUnit($unit) !== null;
    }

    /**
     * Convert a glucose value to the canonical unit (mg/dL).
     *
     * @return array{value: float, unit: string}
     */
    public static function toCanonical(float $value, string $unit): array
    {
        $normalized = self::normalizeUnit($unit);

        if ($normalized === null) {
            throw new InvalidArgumentException("Unsupported glucose unit: {$unit}");
        }

        return match ($normalized) {
            self::UNIT_MMOL => ['value' => round($value * self::MGDL_PER_MMOL, 3), 'unit' => self::UNIT_MGDL],
            default => ['value' => round($value, 3), 'unit' => self::UNIT_MGDL],
        };
    }
}
