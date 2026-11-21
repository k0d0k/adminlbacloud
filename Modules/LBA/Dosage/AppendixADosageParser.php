<?php

namespace Modules\LBA\Dosage;

/**
 * Referensi Rumusan Dosis — the Lampiran A source parser (Modules/LBA).
 *
 * Reads the transcribed Lampiran A markdown (`Formulir_LBA_dan_Lampiran_A_...md`)
 * into plain, auditable structures WITHOUT touching the database, so the import
 * mapping can be dry-run and unit-verified in isolation. Nothing is rewritten:
 * the original entry number, condition name, phase notation and `Tambahan` text
 * are preserved verbatim.
 *
 * Per the "Cara membaca" rule, a notation `A x B` means A sachet per intake and
 * B intakes per day; the parser stores that structured reading alongside the
 * original notation. Duration and phase-transition rules are not in the file, so
 * none are derived.
 */
final class AppendixADosageParser
{
    /** @var list<string> */
    public const PHASES = ['Cleansing', 'Balancing', 'Protecting', 'Maintenance'];

    /**
     * Parse a source file into its entries plus the SHA-256 checksum of the
     * exact bytes read (used to make the import idempotent).
     *
     * @return array{checksum: string, entries: list<array{number: string, name: string, tambahan: ?string, phases: array<string, array{notation: string, sachets_per_intake: ?int, intakes_per_day: ?int, sachets_per_day: ?int}>}>}
     */
    public function parseFile(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Unable to read dosage reference source file [{$path}].");
        }

        return $this->parse($contents);
    }

    /**
     * @return array{checksum: string, entries: list<array{number: string, name: string, tambahan: ?string, phases: array<string, array{notation: string, sachets_per_intake: ?int, intakes_per_day: ?int, sachets_per_day: ?int}>}>}
     */
    public function parse(string $contents): array
    {
        $checksum = hash('sha256', $contents);
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (preg_match('/^####\s+(\d{3})\s+(.*)$/', $line, $match) === 1) {
                if ($current !== null) {
                    $entries[] = $this->finalize($current);
                }

                $current = [
                    'number' => $match[1],
                    'name' => trim($match[2]),
                    'tambahan' => null,
                    'phases' => [],
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^-\s+\*\*(Cleansing|Balancing|Protecting|Maintenance):\*\*\s*(.*)$/', $line, $match) === 1) {
                $notation = trim($match[2]);
                $current['phases'][$match[1]] = ['notation' => $notation] + $this->readNotation($notation);

                continue;
            }

            if (preg_match('/^-\s+\*Tambahan:\s*(.*?)\*?\s*$/', $line, $match) === 1) {
                $text = trim($match[1]);
                $current['tambahan'] = $text === '' ? null : $text;
            }
        }

        if ($current !== null) {
            $entries[] = $this->finalize($current);
        }

        return ['checksum' => $checksum, 'entries' => $entries];
    }

    /**
     * The structured reading of a notation `A x B`: A sachet per intake, B
     * intakes per day, and their product as sachets per day.
     *
     * @return array{sachets_per_intake: ?int, intakes_per_day: ?int, sachets_per_day: ?int}
     */
    public function readNotation(string $notation): array
    {
        if (preg_match('/^\s*(\d+)\s*[xX×]\s*(\d+)\s*$/u', $notation, $match) !== 1) {
            return ['sachets_per_intake' => null, 'intakes_per_day' => null, 'sachets_per_day' => null];
        }

        $sachets = (int) $match[1];
        $intakes = (int) $match[2];

        return [
            'sachets_per_intake' => $sachets,
            'intakes_per_day' => $intakes,
            'sachets_per_day' => $sachets * $intakes,
        ];
    }

    /**
     * @param  array{number: string, name: string, tambahan: ?string, phases: array<string, mixed>}  $entry
     * @return array{number: string, name: string, tambahan: ?string, phases: array<string, array{notation: string, sachets_per_intake: ?int, intakes_per_day: ?int, sachets_per_day: ?int}>}
     */
    private function finalize(array $entry): array
    {
        return [
            'number' => $entry['number'],
            'name' => $entry['name'],
            'tambahan' => $entry['tambahan'],
            'phases' => $entry['phases'],
        ];
    }
}
