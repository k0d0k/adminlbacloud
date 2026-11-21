<?php

namespace Modules\LBA\Dosage;

use Modules\LBA\Models\DosageIndicatorMapping;

/**
 * Referensi Rumusan Dosis — the reviewed indicator -> Lampiran A mapping table.
 *
 * A transcribed, human-reviewable mapping from each digital indicator key to the
 * Lampiran A entry number(s) whose condition name is equivalent. Entries are
 * flagged:
 *  - `reviewed` — a name equivalence that was reviewed and may be used;
 *  - `needs_mapping` — a combined or ambiguous label (several conditions folded
 *    into one source label, or a concept with no faithful Lampiran A entry) that
 *    still requires mapping. It is surfaced to the examiner as "Perlu Pemetaan"
 *    and never guessed.
 *
 * These are provisional INTERNAL equivalences: they are reference data for a
 * draft master and must be confirmed by an authorized clinical reviewer before
 * any patient-facing use.
 */
final class DosageIndicatorMap
{
    /**
     * @return list<array{indicator_key: string, condition_label: string, reference_entry_number: string, review_status: string}>
     */
    public static function mappings(): array
    {
        /** @var array<string, list<string>> $reviewed */
        $reviewed = [
            'asthma_paru_tbc' => ['010', '207'],
            'asam_urat_gout' => ['068'],
            'alergi_bateri_basofil' => ['190'],
            'darah_rendah_tinggi' => ['200', '201'],
            'diabetes_bibit_gula' => ['036'],
            'agregasi_trombosit' => ['042'],
            'racun_darah_hemolisis' => ['128', '005'],
            'infeksi_radang_sinusitis' => ['083'],
            'jantung_pembengkakan' => ['058'],
            'keputihan_kewanitaan_prostat' => ['160', '181'],
            'leukimia_lupus_leukosit' => ['142', '157'],
            'regenerasi_sel' => ['206'],
            'parasit_herpes_exim' => ['081', '033'],
            'pencernaan_limpa_lambung' => ['180'],
            'usus_liver' => ['070', '179'],
            'stress_ginjal_dehidrasi' => ['056'],
            'rematik_kram_kesemutan' => ['182', '139'],
            'cancer_tumor_lipoma' => ['152', '037'],
            'keracunan_darah_kotor' => ['128'],
            'migrain_vertigo' => ['193', '226'],
            'kurang_zat_besi_mikrosite' => ['005'],
            'red_crystal_tenggorokan' => ['202'],
            'getah_benih_kelenjar_yeast' => ['095'],
        ];

        /** @var list<string> $unresolved */
        $unresolved = [
            'ambeien_wasir',
            'agregasi_degeneratif',
            'kolesterol_ldl',
            'trigliserida',
            'radikal_bebas_poikilotosite',
            'plak_penyumbatan_stroke',
            'pelengketan_darah_kental',
        ];

        $mappings = [];

        foreach ($reviewed as $indicatorKey => $entryNumbers) {
            foreach ($entryNumbers as $entryNumber) {
                $mappings[] = [
                    'indicator_key' => $indicatorKey,
                    'condition_label' => '',
                    'reference_entry_number' => $entryNumber,
                    'review_status' => DosageIndicatorMapping::REVIEWED,
                ];
            }
        }

        foreach ($unresolved as $indicatorKey) {
            $mappings[] = [
                'indicator_key' => $indicatorKey,
                'condition_label' => '',
                'reference_entry_number' => '',
                'review_status' => DosageIndicatorMapping::NEEDS_MAPPING,
            ];
        }

        return $mappings;
    }

    /**
     * The indicator keys whose label still requires mapping (no faithful Lampiran
     * A equivalence was reviewed).
     *
     * @return list<string>
     */
    public static function unresolvedIndicatorKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $mapping): string => $mapping['indicator_key'],
            array_filter(
                self::mappings(),
                static fn (array $mapping): bool => $mapping['review_status'] === DosageIndicatorMapping::NEEDS_MAPPING,
            ),
        )));
    }
}
