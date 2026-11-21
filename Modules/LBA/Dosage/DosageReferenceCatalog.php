<?php

namespace Modules\LBA\Dosage;

use App\Platform\LbaAssessment\Models\LbaDosageReferenceBrandLink;
use App\Platform\LbaAssessment\Models\LbaDosageReferenceEntry;
use App\Platform\LbaAssessment\Models\LbaDosageReferencePhaseValue;
use App\Platform\LbaAssessment\Models\LbaDosageReferenceVersion;
use Illuminate\Support\Facades\DB;

/**
 * Referensi Rumusan Dosis — the CENTRAL dosage-reference catalog (Modules/LBA).
 *
 * Reads the Lampiran A master into the central `lba_dosage_reference_*` tables
 * and serves it back to the examiner surface. Import is IDEMPOTENT and
 * dry-runnable:
 *
 *  - the source bytes are hashed; an unchanged file (same `key` + checksum) is a
 *    NO-OP, so re-running never duplicates an entry or a phase value;
 *  - a CHANGED file appends a NEW version (`version` = max + 1) instead of
 *    overwriting, so a historical decision that snapshots a version key stays
 *    traceable;
 *  - the imported version starts as `draft` (the source is a DRAF INTERNAL
 *    master); it only becomes `active` when explicitly confirmed.
 *
 * This service only touches the central connection: it never writes tenant
 * clinical data.
 */
final class DosageReferenceCatalog
{
    /** The default master key (Babul Syiffa). NuBiom/Biodam reuse the same
     *  version once their product/SKU mapping is confirmed. */
    public const DEFAULT_KEY = 'lba-dosage-babul-syiffa';

    public function __construct(
        private readonly AppendixADosageParser $parser,
    ) {}

    /**
     * Import (or dry-run) a source file.
     *
     * @param  array{key: string, label?: ?string, brand_id?: ?string, brand_key?: ?string, source_file?: ?string, actor_id?: ?string, dry_run?: bool, activate?: bool}  $options
     * @return array{dry_run: bool, unchanged: bool, created: bool, key: string, version: ?int, version_id: ?string, entries: int, phase_values: int, tambahan: int, checksum: string}
     */
    public function importFromFile(string $path, array $options): array
    {
        $parsed = $this->parser->parseFile($path);

        return $this->import($parsed, $options);
    }

    /**
     * @param  array{checksum: string, entries: list<array{number: string, name: string, tambahan: ?string, phases: array<string, array<string, mixed>>}>}  $parsed
     * @param  array{key: string, label?: ?string, brand_id?: ?string, brand_key?: ?string, source_file?: ?string, actor_id?: ?string, dry_run?: bool, activate?: bool}  $options
     * @return array{dry_run: bool, unchanged: bool, created: bool, key: string, version: ?int, version_id: ?string, entries: int, phase_values: int, tambahan: int, checksum: string}
     */
    public function import(array $parsed, array $options): array
    {
        $key = (string) $options['key'];
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $checksum = (string) $parsed['checksum'];

        $entries = $parsed['entries'];
        $phaseValues = 0;
        $tambahan = 0;

        foreach ($entries as $entry) {
            $phaseValues += count($entry['phases']);
            if ($entry['tambahan'] !== null) {
                $tambahan++;
            }
        }

        $counts = [
            'entries' => count($entries),
            'phase_values' => $phaseValues,
            'tambahan' => $tambahan,
        ];

        $existing = LbaDosageReferenceVersion::query()
            ->where('key', $key)
            ->where('source_checksum', $checksum)
            ->first();

        if ($existing !== null) {
            return [
                'dry_run' => $dryRun,
                'unchanged' => true,
                'created' => false,
                'key' => $key,
                'version' => (int) $existing->version,
                'version_id' => (string) $existing->getKey(),
                'checksum' => $checksum,
            ] + $counts;
        }

        if ($dryRun) {
            $next = ((int) LbaDosageReferenceVersion::query()->where('key', $key)->max('version')) + 1;

            return [
                'dry_run' => true,
                'unchanged' => false,
                'created' => false,
                'key' => $key,
                'version' => $next,
                'version_id' => null,
                'checksum' => $checksum,
            ] + $counts;
        }

        $version = DB::connection((string) config('tenancy.central_connection'))->transaction(
            function () use ($parsed, $options, $key, $checksum, $counts): LbaDosageReferenceVersion {
                $next = ((int) LbaDosageReferenceVersion::query()->where('key', $key)->max('version')) + 1;

                $record = LbaDosageReferenceVersion::query()->create([
                    'key' => $key,
                    'version' => $next,
                    'label' => (string) ($options['label'] ?? $key),
                    'status' => ($options['activate'] ?? false)
                        ? LbaDosageReferenceVersion::STATUS_ACTIVE
                        : LbaDosageReferenceVersion::STATUS_DRAFT,
                    'brand_id' => $options['brand_id'] ?? null,
                    'brand_key' => $options['brand_key'] ?? null,
                    'source_file' => $options['source_file'] ?? null,
                    'source_checksum' => $checksum,
                    'entry_count' => $counts['entries'],
                    'phase_value_count' => $counts['phase_values'],
                    'tambahan_count' => $counts['tambahan'],
                    'notes' => 'Compiled from the transcribed Lampiran A master (DRAF INTERNAL).',
                    'imported_at' => now(),
                    'imported_by' => $options['actor_id'] ?? null,
                ]);

                foreach ($parsed['entries'] as $index => $entry) {
                    $entryRecord = LbaDosageReferenceEntry::query()->create([
                        'reference_version_id' => $record->getKey(),
                        'entry_number' => $entry['number'],
                        'condition_name' => $entry['name'],
                        'tambahan_text' => $entry['tambahan'],
                        'position' => $index + 1,
                    ]);

                    foreach ($entry['phases'] as $phase => $value) {
                        LbaDosageReferencePhaseValue::query()->create([
                            'reference_entry_id' => $entryRecord->getKey(),
                            'phase' => strtolower($phase),
                            'dosage_notation' => (string) $value['notation'],
                            'sachets_per_intake' => $value['sachets_per_intake'],
                            'intakes_per_day' => $value['intakes_per_day'],
                            'sachets_per_day' => $value['sachets_per_day'],
                        ]);
                    }
                }

                return $record;
            }
        );

        return [
            'dry_run' => false,
            'unchanged' => false,
            'created' => true,
            'key' => $key,
            'version' => (int) $version->version,
            'version_id' => (string) $version->getKey(),
            'checksum' => $checksum,
        ] + $counts;
    }

    /**
     * The active version of a master key, falling back to its latest version so
     * an unmapped/draft-only master is still surfaced (and reported as
     * needing review rather than silently hidden).
     */
    public function activeVersionForKey(string $key): ?LbaDosageReferenceVersion
    {
        return LbaDosageReferenceVersion::query()
            ->where('key', $key)
            ->where('status', LbaDosageReferenceVersion::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first()
            ?? LbaDosageReferenceVersion::query()
                ->where('key', $key)
                ->orderByDesc('version')
                ->first();
    }

    public function versionById(string $versionId): ?LbaDosageReferenceVersion
    {
        return LbaDosageReferenceVersion::query()->whereKey($versionId)->first();
    }

    /**
     * Link a Brand/SKU to the current version of a master key, idempotently.
     *
     * NuBiom and Biodam reuse the SAME version as Babul Syiffa once their
     * product/SKU mapping is confirmed, so linking is keyed on
     * `(reference_version_id, brand_key)` — re-running never duplicates a link.
     * `brand_id` is optional: a central Brand row may not exist yet, in which
     * case the stable logical `brand_key` still records the link.
     *
     * @return array{linked: bool, created: bool, dry_run: bool, key: string, version: ?int, version_id: ?string, brand_key: string, brand_id: ?string}
     */
    public function linkBrand(string $referenceKey, string $brandKey, ?string $brandId = null, bool $dryRun = false): array
    {
        $version = $this->activeVersionForKey($referenceKey);

        $base = [
            'dry_run' => $dryRun,
            'key' => $referenceKey,
            'brand_key' => $brandKey,
            'brand_id' => $brandId,
        ];

        if ($version === null) {
            return ['linked' => false, 'created' => false, 'version' => null, 'version_id' => null] + $base;
        }

        $existing = LbaDosageReferenceBrandLink::query()
            ->where('reference_version_id', $version->getKey())
            ->where('brand_key', $brandKey)
            ->first();

        if ($dryRun) {
            return [
                'linked' => true,
                'created' => $existing === null,
                'version' => (int) $version->version,
                'version_id' => (string) $version->getKey(),
            ] + $base;
        }

        if ($existing === null) {
            LbaDosageReferenceBrandLink::query()->create([
                'reference_version_id' => $version->getKey(),
                'brand_key' => $brandKey,
                'brand_id' => $brandId,
                'linked_at' => now(),
            ]);

            return [
                'linked' => true,
                'created' => true,
                'version' => (int) $version->version,
                'version_id' => (string) $version->getKey(),
            ] + $base;
        }

        if ($brandId !== null && (string) $existing->brand_id !== $brandId) {
            $existing->forceFill(['brand_id' => $brandId])->save();
        }

        return [
            'linked' => true,
            'created' => false,
            'version' => (int) $version->version,
            'version_id' => (string) $version->getKey(),
        ] + $base;
    }

    /**
     * The version backing a Brand/SKU, preferring its ACTIVE version and
     * otherwise its latest linked version.
     */
    public function activeVersionForBrandKey(string $brandKey): ?LbaDosageReferenceVersion
    {
        $versionIds = LbaDosageReferenceBrandLink::query()
            ->where('brand_key', $brandKey)
            ->pluck('reference_version_id');

        if ($versionIds->isEmpty()) {
            return null;
        }

        return LbaDosageReferenceVersion::query()
            ->whereIn('id', $versionIds)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [LbaDosageReferenceVersion::STATUS_ACTIVE])
            ->orderByDesc('version')
            ->first();
    }
}
