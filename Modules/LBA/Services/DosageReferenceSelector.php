<?php

namespace Modules\LBA\Services;

use App\Models\User;
use App\Platform\LbaAssessment\Models\LbaDosageReferencePhaseValue;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Illuminate\Support\Collection;
use Modules\LBA\Dosage\DosageReferenceCatalog;
use Modules\LBA\Exceptions\AssessmentException;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentDosageSelection;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\DosageIndicatorMapping;

/**
 * Referensi Rumusan Dosis — the per-phase reference SELECTION rules
 * (Modules/LBA).
 *
 * Applies the agreed rules to the phases the examiner works in Cleansing and
 * Balancing (Protecting and Maintenance stay in the master and are never
 * selected here):
 *
 *  - if a RED indicator is relevant, the RED group is examined first and YELLOW
 *    is set aside;
 *  - if there are several reds, the reference with the HIGHEST total main-product
 *    sachets per day is picked as the review priority — for the same phase and
 *    SKU only; doses are NEVER summed across indicators; a tie asks the examiner
 *    to choose;
 *  - if a red indicator's reference is unmapped or still draft, the phase is
 *    flagged "Perlu Review" and NEVER falls back to yellow;
 *  - with no red, the yellow reference is used; several yellows are presented to
 *    the examiner, and a tie (or non-comparable SKU) also asks the examiner to
 *    choose;
 *  - an all-green / unassessed assessment yields "Belum Ada Referensi".
 *
 * Human control is preserved: the service only RESOLVES and records a
 * recommendation; the examiner records the final decision, and nothing here is
 * ever published to the patient portal.
 */
final class DosageReferenceSelector
{
    use RunsVerifiedTenantTransactions;

    /** The phases the selection rules apply to. */
    public const SELECTABLE_PHASES = ['cleansing', 'balancing'];

    public function __construct(
        private readonly DosageReferenceCatalog $catalog,
    ) {}

    /**
     * @return Collection<int, AssessmentDosageSelection>
     */
    public function selections(Assessment $assessment): Collection
    {
        return AssessmentDosageSelection::query()
            ->where('assessment_id', $assessment->getKey())
            ->orderBy('program_phase')
            ->get();
    }

    public function selectionFor(Assessment $assessment, string $phase): ?AssessmentDosageSelection
    {
        return AssessmentDosageSelection::query()
            ->where('assessment_id', $assessment->getKey())
            ->where('program_phase', strtolower($phase))
            ->first();
    }

    /**
     * Evaluate and persist the selection for one phase. Idempotent: re-evaluating
     * refreshes the resolution but keeps an already-recorded examiner decision
     * whenever the resolved reference is unchanged.
     */
    public function evaluate(Assessment $assessment, string $phase): AssessmentDosageSelection
    {
        $phase = strtolower($phase);

        $items = $assessment->items()->get();
        $mappingsByKey = DosageIndicatorMapping::query()->get()->groupBy('indicator_key');

        $redItems = $items->where('category', AssessmentItem::CATEGORY_RED)->values();
        $yellowItems = $items->where('category', AssessmentItem::CATEGORY_YELLOW)->values();

        $resolved = $this->resolve($redItems, $yellowItems, $mappingsByKey, $phase);

        return $this->persist($assessment, $phase, $resolved);
    }

    /**
     * Record the examiner's final decision on a resolved selection. The chosen
     * entry (for a multiple-choice / tie case) is copied from the considered
     * candidates so the stored notation and sachet totals stay consistent.
     */
    public function decide(
        AssessmentDosageSelection $selection,
        ?User $actor,
        string $decision = AssessmentDosageSelection::DECISION_ACCEPTED,
        ?string $entryNumber = null,
        ?string $notes = null,
    ): AssessmentDosageSelection {
        if (! in_array($decision, [
            AssessmentDosageSelection::DECISION_ACCEPTED,
            AssessmentDosageSelection::DECISION_OVERRIDDEN,
            AssessmentDosageSelection::DECISION_PENDING,
        ], true)) {
            throw AssessmentException::invalidDosageDecision($decision);
        }

        return $this->tenantTransaction(function () use ($selection, $actor, $decision, $entryNumber, $notes): AssessmentDosageSelection {
            $payload = [
                'decision' => $decision,
                'decided_by' => $actor?->getKey(),
                'decided_at' => $decision === AssessmentDosageSelection::DECISION_PENDING ? null : now(),
                'examiner_notes' => $notes,
            ];

            if ($entryNumber !== null && $entryNumber !== '') {
                $candidate = $this->findCandidate($selection, $entryNumber);

                $payload['reference_entry_number'] = $entryNumber;
                $payload['source_notation'] = $candidate['notation'] ?? null;
                $payload['total_sachets_per_day'] = $candidate['sachets_per_day'] ?? null;
                $payload['reference_key'] = $candidate['reference_key'] ?? $selection->reference_key;
                $payload['reference_version'] = $candidate['version'] ?? $selection->reference_version;
                $payload['indicator_key'] = $candidate['indicator_key'] ?? $selection->indicator_key;
                $payload['category'] = $candidate['category'] ?? $selection->category;
            }

            $selection->forceFill($payload)->save();

            return $selection;
        });
    }

    /**
     * @param  Collection<int, AssessmentItem>  $redItems
     * @param  Collection<int, AssessmentItem>  $yellowItems
     * @param  Collection<string, Collection<int, DosageIndicatorMapping>>  $mappingsByKey
     * @return array{status: string, reason: string, indicator_key: ?string, category: ?string, reference_key: ?string, reference_version: ?int, reference_entry_number: ?string, source_notation: ?string, total_sachets_per_day: ?int, considered: list<array<string, mixed>>, snapshot: array<string, mixed>}
     */
    private function resolve(Collection $redItems, Collection $yellowItems, Collection $mappingsByKey, string $phase): array
    {
        if ($redItems->isEmpty() && $yellowItems->isEmpty()) {
            return $this->unresolved(AssessmentDosageSelection::STATUS_NO_REFERENCE, 'no_reference', [], $phase);
        }

        if ($redItems->isNotEmpty()) {
            $red = $this->gather($redItems, $mappingsByKey, $phase);

            if ($red['needs_mapping']) {
                return $this->unresolved(AssessmentDosageSelection::STATUS_NEEDS_MAPPING, 'needs_mapping', $red['candidates'], $phase, $redItems->first());
            }

            if ($red['candidates'] === []) {
                return $this->unresolved(AssessmentDosageSelection::STATUS_NEEDS_REVIEW, 'red_unmapped', [], $phase, $redItems->first());
            }

            $max = max(array_map(static fn (array $candidate): int => (int) $candidate['sachets_per_day'], $red['candidates']));
            $top = array_values(array_filter(
                $red['candidates'],
                static fn (array $candidate): bool => (int) $candidate['sachets_per_day'] === $max,
            ));

            if (count($top) === 1) {
                return $this->selected($top[0], 'red_highest', $red['candidates'], $phase);
            }

            return $this->unresolved(AssessmentDosageSelection::STATUS_MULTIPLE_CHOICES, 'red_tie', $top, $phase);
        }

        $yellow = $this->gather($yellowItems, $mappingsByKey, $phase);

        if ($yellow['needs_mapping']) {
            return $this->unresolved(AssessmentDosageSelection::STATUS_NEEDS_MAPPING, 'needs_mapping', $yellow['candidates'], $phase, $yellowItems->first());
        }

        if ($yellow['candidates'] === []) {
            return $this->unresolved(AssessmentDosageSelection::STATUS_NEEDS_REVIEW, 'yellow_unmapped', [], $phase, $yellowItems->first());
        }

        if (count($yellow['candidates']) === 1) {
            return $this->selected($yellow['candidates'][0], 'yellow_single', $yellow['candidates'], $phase);
        }

        return $this->unresolved(AssessmentDosageSelection::STATUS_MULTIPLE_CHOICES, 'yellow_multiple', $yellow['candidates'], $phase);
    }

    /**
     * Build the reference candidates for a group of items.
     *
     * @param  Collection<int, AssessmentItem>  $items
     * @param  Collection<string, Collection<int, DosageIndicatorMapping>>  $mappingsByKey
     * @return array{candidates: list<array<string, mixed>>, needs_mapping: bool}
     */
    private function gather(Collection $items, Collection $mappingsByKey, string $phase): array
    {
        $candidates = [];
        $needsMapping = false;

        foreach ($items as $item) {
            $indicatorKey = (string) $item->indicator_key;
            $mappings = $mappingsByKey->get($indicatorKey);

            if ($mappings === null || $mappings->isEmpty()) {
                continue;
            }

            foreach ($mappings as $mapping) {
                if ($mapping->review_status === DosageIndicatorMapping::NEEDS_MAPPING) {
                    $needsMapping = true;

                    continue;
                }

                $candidate = $this->candidate($item, $mapping, $phase);

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return ['candidates' => $candidates, 'needs_mapping' => $needsMapping];
    }

    /**
     * Resolve ONE mapping into a candidate, or null when the reference is missing
     * or still draft (the caller treats a null as "needs review").
     *
     * @return array<string, mixed>|null
     */
    private function candidate(AssessmentItem $item, DosageIndicatorMapping $mapping, string $phase): ?array
    {
        $referenceKey = (string) ($mapping->reference_key ?: DosageReferenceCatalog::DEFAULT_KEY);

        $version = $this->catalog->activeVersionForKey($referenceKey);

        if ($version === null || $version->isDraft()) {
            return null;
        }

        $entry = $version->entries()
            ->where('entry_number', (string) $mapping->reference_entry_number)
            ->first();

        if ($entry === null) {
            return null;
        }

        $phaseValue = $entry->phaseValue($phase);

        if ($phaseValue === null) {
            return null;
        }

        return [
            'indicator_key' => (string) $item->indicator_key,
            'category' => (string) $item->category,
            'entry_number' => (string) $entry->entry_number,
            'condition_name' => (string) $entry->condition_name,
            'notation' => (string) $phaseValue->dosage_notation,
            'sachets_per_intake' => $phaseValue->sachets_per_intake,
            'intakes_per_day' => $phaseValue->intakes_per_day,
            'sachets_per_day' => $phaseValue->sachets_per_day,
            'reference_key' => $referenceKey,
            'version' => (int) $version->version,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array<string, mixed>>  $considered
     * @return array<string, mixed>
     */
    private function selected(array $candidate, string $reason, array $considered, string $phase): array
    {
        return [
            'status' => AssessmentDosageSelection::STATUS_SELECTED,
            'reason' => $reason,
            'indicator_key' => $candidate['indicator_key'],
            'category' => $candidate['category'],
            'reference_key' => $candidate['reference_key'],
            'reference_version' => $candidate['version'],
            'reference_entry_number' => $candidate['entry_number'],
            'source_notation' => $candidate['notation'],
            'total_sachets_per_day' => $candidate['sachets_per_day'],
            'considered' => $considered,
            'snapshot' => $this->snapshot($candidate['reference_key'], $candidate['version'], $phase, $considered),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $considered
     * @return array<string, mixed>
     */
    private function unresolved(string $status, string $reason, array $considered, string $phase, ?AssessmentItem $item = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'indicator_key' => $item !== null ? (string) $item->indicator_key : null,
            'category' => $item !== null ? (string) $item->category : null,
            'reference_key' => null,
            'reference_version' => null,
            'reference_entry_number' => null,
            'source_notation' => null,
            'total_sachets_per_day' => null,
            'considered' => $considered,
            'snapshot' => $this->snapshot(null, null, $phase, $considered),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $considered
     * @return array<string, mixed>
     */
    private function snapshot(?string $referenceKey, ?int $version, string $phase, array $considered): array
    {
        return [
            'phase' => $phase,
            'reference_key' => $referenceKey,
            'version' => $version,
            'considered_count' => count($considered),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function persist(Assessment $assessment, string $phase, array $resolved): AssessmentDosageSelection
    {
        return $this->tenantTransaction(function () use ($assessment, $phase, $resolved): AssessmentDosageSelection {
            $existing = $this->selectionFor($assessment, $phase);

            $payload = [
                'status' => $resolved['status'],
                'indicator_key' => $resolved['indicator_key'],
                'category' => $resolved['category'],
                'reference_key' => $resolved['reference_key'],
                'reference_version' => $resolved['reference_version'],
                'reference_entry_number' => $resolved['reference_entry_number'],
                'source_notation' => $resolved['source_notation'],
                'total_sachets_per_day' => $resolved['total_sachets_per_day'],
                'reason' => $resolved['reason'],
                'considered' => $resolved['considered'],
                'snapshot' => $resolved['snapshot'],
            ];

            if ($existing === null) {
                return AssessmentDosageSelection::query()->create($payload + [
                    'assessment_id' => $assessment->getKey(),
                    'program_phase' => $phase,
                    'decision' => AssessmentDosageSelection::DECISION_PENDING,
                ]);
            }

            // Preserve a recorded decision only while the resolved reference is
            // unchanged; a changed resolution resets the decision to pending.
            $unchanged = $existing->status === $resolved['status']
                && (string) $existing->reference_entry_number === (string) $resolved['reference_entry_number'];

            if (! $unchanged || $existing->decision === AssessmentDosageSelection::DECISION_PENDING) {
                $payload['decision'] = AssessmentDosageSelection::DECISION_PENDING;
                $payload['decided_by'] = null;
                $payload['decided_at'] = null;
            }

            $existing->forceFill($payload)->save();

            return $existing;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCandidate(AssessmentDosageSelection $selection, string $entryNumber): ?array
    {
        foreach ((array) $selection->considered as $candidate) {
            if (is_array($candidate) && (string) ($candidate['entry_number'] ?? '') === $entryNumber) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The reference phase values of an entry, for a snapshot/report preview.
     *
     * @return Collection<int, LbaDosageReferencePhaseValue>
     */
    public function phaseValues(string $referenceKey, string $entryNumber): Collection
    {
        $version = $this->catalog->activeVersionForKey($referenceKey);

        if ($version === null) {
            return collect();
        }

        $entry = $version->entries()->where('entry_number', $entryNumber)->first();

        return $entry?->phaseValues()->get() ?? collect();
    }
}
