<?php

namespace Tests\Feature\Modules;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\LbaAssessment\Models\LbaDosageReferenceBrandLink;
use App\Platform\LbaAssessment\Models\LbaDosageReferenceEntry;
use App\Platform\LbaAssessment\Models\LbaDosageReferencePhaseValue;
use App\Platform\LbaAssessment\Models\LbaDosageReferenceVersion;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\LBA\Dosage\AppendixADosageParser;
use Modules\LBA\Dosage\DosageIndicatorMap;
use Modules\LBA\Dosage\DosageReferenceCatalog;
use Modules\LBA\Exceptions\AssessmentException;
use Modules\LBA\Filament\Pages\AssessmentForm;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentDosageSelection;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\DosageIndicatorMapping;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\DosageMappingProvisioner;
use Modules\LBA\Services\DosageReferenceSelector;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Referensi Rumusan Dosis — the per-phase dosage-reference selection (Phase 7A).
 *
 * Covers ONLY the required behaviours: a dry-runnable, idempotent, versioned
 * import of the Lampiran A master (226 entries / 904 phase values / 226
 * `Tambahan`); the reviewed indicator -> Lampiran A mapping with its unresolved
 * labels; the selection rules (red outranks yellow, several reds pick the
 * highest daily total without summing, a tie asks the examiner, yellow fallback,
 * several yellows, an unmapped/draft red stops at "Perlu Review", an ambiguous
 * label is "Perlu Pemetaan", all-green is "Belum Ada Referensi"); the examiner
 * decision; and the hard rule that the internal priority reference and any draft
 * master never reach the patient portal or another tenant.
 *
 * Every identity, patient and value here is synthetic (DECISIONS.md PRV-01).
 */
class LbaDosageReferenceTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'lba';

    private const SOURCE = 'Formulir_LBA_dan_Lampiran_A_Babul_Syiffa.md';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function activate(Tenant $tenant): void
    {
        if (! RegisteredModule::query()->where('module_key', self::MODULE)->exists()) {
            RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();
        }

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    private function catalog(): DosageReferenceCatalog
    {
        return app(DosageReferenceCatalog::class);
    }

    private function selector(): DosageReferenceSelector
    {
        return app(DosageReferenceSelector::class);
    }

    private function recorder(): AssessmentRecorder
    {
        return app(AssessmentRecorder::class);
    }

    private function central(): string
    {
        return (string) config('tenancy.central_connection');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeExamination(Tenant $tenant, User $owner, array $overrides = []): Examination
    {
        $this->bindTenant($tenant);

        if (! isset($overrides['branch_id'])) {
            $overrides['branch_id'] = $this->createBranch($tenant)->getKey();
        }

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Dosage Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    private function assessmentFor(Tenant $tenant, User $owner): Assessment
    {
        $examination = $this->makeExamination($tenant, $owner);

        return $this->recorder()->openFor($examination, $owner);
    }

    /**
     * Build one parsed entry from per-phase notations.
     *
     * @param  array<string, string>  $phases  e.g. ['Cleansing' => '4 X 2', ...]
     * @return array<string, mixed>
     */
    private function entry(string $number, string $name, array $phases, ?string $tambahan = null): array
    {
        $parser = app(AppendixADosageParser::class);

        $built = [];

        foreach ($phases as $phase => $notation) {
            $built[$phase] = ['notation' => $notation] + $parser->readNotation($notation);
        }

        return [
            'number' => $number,
            'name' => $name,
            'tambahan' => $tambahan,
            'phases' => $built,
        ];
    }

    /**
     * Import a small, controlled master (default key) so the selection rules can
     * be exercised deterministically.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function seedMaster(array $entries, bool $activate = true, string $key = DosageReferenceCatalog::DEFAULT_KEY): array
    {
        return $this->catalog()->import([
            'checksum' => hash('sha256', $key.'|'.serialize($entries).'|'.($activate ? 'a' : 'd')),
            'entries' => $entries,
        ], [
            'key' => $key,
            'label' => 'Synthetic master',
            'activate' => $activate,
        ]);
    }

    private function map(
        string $indicatorKey,
        string $entryNumber,
        string $status = DosageIndicatorMapping::REVIEWED,
        ?string $referenceKey = null,
    ): DosageIndicatorMapping {
        return DosageIndicatorMapping::query()->create([
            'indicator_key' => $indicatorKey,
            'condition_label' => '',
            'reference_entry_number' => $entryNumber,
            'reference_key' => $referenceKey,
            'review_status' => $status,
        ]);
    }

    // ======================================================== 1. Import master

    public function test_a_dry_run_reports_the_expected_counts_and_writes_nothing(): void
    {
        $result = $this->catalog()->importFromFile(base_path(self::SOURCE), [
            'key' => DosageReferenceCatalog::DEFAULT_KEY,
            'dry_run' => true,
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertTrue($result['created'] === false);
        $this->assertSame(226, $result['entries']);
        $this->assertSame(904, $result['phase_values']);
        $this->assertSame(226, $result['tambahan']);

        // Nothing was persisted by a dry run.
        $this->assertSame(0, LbaDosageReferenceVersion::query()->count());
        $this->assertSame(0, LbaDosageReferenceEntry::query()->count());
        $this->assertSame(0, LbaDosageReferencePhaseValue::query()->count());
    }

    public function test_the_import_persists_a_draft_version_and_re_running_it_never_duplicates(): void
    {
        $first = $this->catalog()->importFromFile(base_path(self::SOURCE), [
            'key' => DosageReferenceCatalog::DEFAULT_KEY,
            'source_file' => self::SOURCE,
        ]);

        $this->assertTrue($first['created']);
        $this->assertSame(226, $first['entries']);
        $this->assertSame(904, $first['phase_values']);
        $this->assertSame(226, $first['tambahan']);

        // The source is a DRAF INTERNAL master, so it imports as `draft`.
        $version = LbaDosageReferenceVersion::query()->where('key', DosageReferenceCatalog::DEFAULT_KEY)->firstOrFail();
        $this->assertTrue($version->isDraft());
        $this->assertSame(226, $version->entry_count);
        $this->assertSame(904, $version->phase_value_count);
        $this->assertSame(226, $version->tambahan_count);
        $this->assertSame(64, strlen((string) $version->source_checksum));

        $this->assertSame(1, LbaDosageReferenceVersion::query()->count());
        $this->assertSame(226, LbaDosageReferenceEntry::query()->count());
        $this->assertSame(904, LbaDosageReferencePhaseValue::query()->count());

        // An UNCHANGED file is a NO-OP: no new version, no duplicate row.
        $second = $this->catalog()->importFromFile(base_path(self::SOURCE), [
            'key' => DosageReferenceCatalog::DEFAULT_KEY,
            'source_file' => self::SOURCE,
        ]);

        $this->assertTrue($second['unchanged']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, LbaDosageReferenceVersion::query()->count());
        $this->assertSame(226, LbaDosageReferenceEntry::query()->count());
        $this->assertSame(904, LbaDosageReferencePhaseValue::query()->count());
    }

    public function test_a_changed_source_appends_a_new_version_and_keeps_the_old_one(): void
    {
        $this->catalog()->importFromFile(base_path(self::SOURCE), [
            'key' => DosageReferenceCatalog::DEFAULT_KEY,
        ]);

        // A changed file (different bytes -> different checksum) MUST append a
        // new version, never overwrite the earlier one.
        $changed = $this->catalog()->import([
            'checksum' => hash('sha256', 'a-changed-source'),
            'entries' => [$this->entry('001', 'Changed condition', ['Cleansing' => '1 X 1'])],
        ], [
            'key' => DosageReferenceCatalog::DEFAULT_KEY,
        ]);

        $this->assertTrue($changed['created']);
        $this->assertSame(2, $changed['version']);

        $versions = LbaDosageReferenceVersion::query()
            ->where('key', DosageReferenceCatalog::DEFAULT_KEY)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame(1, $versions[0]->version);
        $this->assertSame(226, $versions[0]->entry_count);
        $this->assertSame(2, $versions[1]->version);
        $this->assertSame(1, $versions[1]->entry_count);
    }

    public function test_the_original_notation_and_the_structured_reading_are_both_preserved(): void
    {
        $this->seedMaster([
            $this->entry('001', 'Abses (Penimbunan N@nah)', [
                'Cleansing' => '4 X 2',
                'Balancing' => '3 X 2',
                'Protecting' => '2 X 2',
                'Maintenance' => '2 X 1',
            ], 'ACCUPUNTUR'),
        ]);

        $entry = LbaDosageReferenceEntry::query()->where('entry_number', '001')->firstOrFail();
        $this->assertSame('ACCUPUNTUR', $entry->tambahan_text);

        $cleansing = $entry->phaseValue(LbaDosageReferencePhaseValue::PHASE_CLEANSING);
        $this->assertSame('4 X 2', $cleansing->dosage_notation);
        $this->assertSame(4, $cleansing->sachets_per_intake);
        $this->assertSame(2, $cleansing->intakes_per_day);
        $this->assertSame(8, $cleansing->sachets_per_day);

        $maintenance = $entry->phaseValue(LbaDosageReferencePhaseValue::PHASE_MAINTENANCE);
        $this->assertSame('2 X 1', $maintenance->dosage_notation);
        $this->assertSame(2, $maintenance->sachets_per_day);
    }

    public function test_the_parser_reads_a_times_b_into_sachets_per_intake_and_per_day(): void
    {
        $parser = app(AppendixADosageParser::class);

        $this->assertSame(
            ['sachets_per_intake' => 4, 'intakes_per_day' => 2, 'sachets_per_day' => 8],
            $parser->readNotation('4 X 2'),
        );

        $this->assertSame(
            ['sachets_per_intake' => null, 'intakes_per_day' => null, 'sachets_per_day' => null],
            $parser->readNotation('as directed'),
        );
    }

    // ============================================ 2. Indicator mapping table

    public function test_the_reviewed_mapping_table_flags_the_unresolved_labels(): void
    {
        $unresolved = DosageIndicatorMap::unresolvedIndicatorKeys();

        $this->assertCount(7, $unresolved);

        foreach ([
            'ambeien_wasir',
            'agregasi_degeneratif',
            'kolesterol_ldl',
            'trigliserida',
            'radikal_bebas_poikilotosite',
            'plak_penyumbatan_stroke',
            'pelengketan_darah_kental',
        ] as $key) {
            $this->assertContains($key, $unresolved);
        }
    }

    public function test_provisioning_the_indicator_mappings_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $provisioner = app(DosageMappingProvisioner::class);

        $first = $provisioner->provision();
        $this->assertSame($first['total'], $first['created']);
        $this->assertSame(count(DosageIndicatorMap::mappings()), $first['total']);
        $this->assertSame($first['total'], DosageIndicatorMapping::query()->count());

        // Re-running never duplicates and never overwrites.
        $second = $provisioner->provision();
        $this->assertSame(0, $second['created']);
        $this->assertSame($first['total'], DosageIndicatorMapping::query()->count());
    }

    // ================================================== 3. Selection rules

    public function test_red_outranks_yellow_for_a_phase(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('asthma_paru_tbc', '010');
        $this->map('usus_liver', '070');

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $operator);
        $this->recorder()->setCategory($assessment, 'usus_liver', AssessmentItem::CATEGORY_YELLOW, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_SELECTED, $selection->status);
        $this->assertSame('red_highest', $selection->reason);
        $this->assertSame('asthma_paru_tbc', $selection->indicator_key);
        $this->assertSame(AssessmentItem::CATEGORY_RED, $selection->category);
        $this->assertSame('010', $selection->reference_entry_number);
        $this->assertSame(8, $selection->total_sachets_per_day);
    }

    public function test_several_reds_pick_the_highest_daily_total_without_summing(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),   // 8/day
            $this->entry('058', 'Heart', ['Cleansing' => '3 X 2']),    // 6/day
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('asthma_paru_tbc', '010');
        $this->map('jantung_pembengkakan', '058');

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $operator);
        $this->recorder()->setCategory($assessment, 'jantung_pembengkakan', AssessmentItem::CATEGORY_RED, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_SELECTED, $selection->status);
        $this->assertSame('010', $selection->reference_entry_number);
        // 8 — the highest single indicator — NEVER 8 + 6 = 14.
        $this->assertSame(8, $selection->total_sachets_per_day);
        $this->assertCount(2, $selection->considered);
    }

    public function test_several_reds_with_equal_totals_ask_the_examiner_to_choose(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
            $this->entry('058', 'Heart', ['Cleansing' => '4 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('asthma_paru_tbc', '010');
        $this->map('jantung_pembengkakan', '058');

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $operator);
        $this->recorder()->setCategory($assessment, 'jantung_pembengkakan', AssessmentItem::CATEGORY_RED, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_MULTIPLE_CHOICES, $selection->status);
        $this->assertSame('red_tie', $selection->reason);
        $this->assertCount(2, $selection->considered);
        $this->assertNull($selection->reference_entry_number);

        // The examiner chooses one of the tied candidates.
        $decided = $this->selector()->decide($selection, $operator, AssessmentDosageSelection::DECISION_ACCEPTED, '058');

        $this->assertSame(AssessmentDosageSelection::DECISION_ACCEPTED, $decided->decision);
        $this->assertSame('058', $decided->reference_entry_number);
        $this->assertSame('4 X 2', $decided->source_notation);
        $this->assertSame(8, $decided->total_sachets_per_day);
        $this->assertSame((string) $operator->getKey(), (string) $decided->decided_by);
        $this->assertNotNull($decided->decided_at);
    }

    public function test_without_red_a_single_yellow_reference_is_selected(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('usus_liver', '070');
        $this->recorder()->setCategory($assessment, 'usus_liver', AssessmentItem::CATEGORY_YELLOW, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_SELECTED, $selection->status);
        $this->assertSame('yellow_single', $selection->reason);
        $this->assertSame('070', $selection->reference_entry_number);
        $this->assertSame(4, $selection->total_sachets_per_day);
    }

    public function test_several_yellows_ask_the_examiner_to_choose(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('asthma_paru_tbc', '010');
        $this->map('usus_liver', '070');

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW, $operator);
        $this->recorder()->setCategory($assessment, 'usus_liver', AssessmentItem::CATEGORY_YELLOW, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_MULTIPLE_CHOICES, $selection->status);
        $this->assertSame('yellow_multiple', $selection->reason);
        $this->assertCount(2, $selection->considered);
    }

    public function test_an_unmapped_red_needs_review_and_never_falls_back_to_yellow(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // Only the yellow reference is mapped; the red one is NOT.
        $this->seedMaster([
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('usus_liver', '070');

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $operator);
        $this->recorder()->setCategory($assessment, 'usus_liver', AssessmentItem::CATEGORY_YELLOW, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_NEEDS_REVIEW, $selection->status);
        $this->assertSame('red_unmapped', $selection->reason);
        $this->assertSame('asthma_paru_tbc', $selection->indicator_key);
        // No silent fallback to the mapped yellow.
        $this->assertNull($selection->reference_entry_number);
    }

    public function test_a_red_whose_reference_is_still_draft_needs_review(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // A DRAFT master is not usable for a selection.
        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
        ], activate: false);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('asthma_paru_tbc', '010');
        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_NEEDS_REVIEW, $selection->status);
        $this->assertSame('red_unmapped', $selection->reason);
    }

    public function test_an_ambiguous_label_is_flagged_as_needs_mapping(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('ambeien_wasir', '', DosageIndicatorMapping::NEEDS_MAPPING);
        $this->recorder()->setCategory($assessment, 'ambeien_wasir', AssessmentItem::CATEGORY_RED, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_NEEDS_MAPPING, $selection->status);
        $this->assertSame('needs_mapping', $selection->reason);
    }

    public function test_an_all_green_or_unassessed_assessment_yields_no_reference(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('asthma_paru_tbc', '010');
        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_GREEN, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::STATUS_NO_REFERENCE, $selection->status);
        $this->assertSame('no_reference', $selection->reason);
        $this->assertNull($selection->indicator_key);
        $this->assertNull($selection->reference_entry_number);
    }

    public function test_the_same_assessment_resolves_each_phase_separately(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('010', 'Asthma', [
                'Cleansing' => '4 X 2',   // 8/day
                'Balancing' => '3 X 2',   // 6/day
                'Protecting' => '2 X 2',
                'Maintenance' => '2 X 1',
            ]),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('asthma_paru_tbc', '010');
        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $operator);

        $cleansing = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);
        $balancing = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_BALANCING);

        $this->assertSame('4 X 2', $cleansing->source_notation);
        $this->assertSame(8, $cleansing->total_sachets_per_day);

        $this->assertSame('3 X 2', $balancing->source_notation);
        $this->assertSame(6, $balancing->total_sachets_per_day);

        // Protecting and Maintenance remain in the master and are NOT selected.
        $this->assertSame(
            0,
            AssessmentDosageSelection::query()
                ->whereIn('program_phase', [Assessment::PROGRAM_PROTECTING, Assessment::PROGRAM_MAINTENANCE])
                ->count(),
        );

        $this->assertSame(
            [Assessment::PROGRAM_BALANCING, Assessment::PROGRAM_CLEANSING],
            AssessmentDosageSelection::query()->orderBy('program_phase')->pluck('program_phase')->all(),
        );
    }

    public function test_re_evaluating_keeps_a_recorded_decision_while_the_reference_is_unchanged(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('usus_liver', '070');
        $this->recorder()->setCategory($assessment, 'usus_liver', AssessmentItem::CATEGORY_YELLOW, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);
        $this->selector()->decide($selection, $operator, AssessmentDosageSelection::DECISION_ACCEPTED, '070');

        $reEvaluated = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->assertSame(AssessmentDosageSelection::DECISION_ACCEPTED, $reEvaluated->decision);
        $this->assertSame('070', $reEvaluated->reference_entry_number);

        // Only ONE row per (assessment, phase) — re-evaluating never duplicates.
        $this->assertSame(
            1,
            AssessmentDosageSelection::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('program_phase', Assessment::PROGRAM_CLEANSING)
                ->count(),
        );
    }

    public function test_an_invalid_decision_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $this->map('usus_liver', '070');
        $this->recorder()->setCategory($assessment, 'usus_liver', AssessmentItem::CATEGORY_YELLOW, $operator);

        $selection = $this->selector()->evaluate($assessment->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->expectException(AssessmentException::class);

        $this->selector()->decide($selection, $operator, 'not-a-decision');
    }

    // ================================================ 4. Tenant isolation

    public function test_dosage_selections_are_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $operatorA = $this->member($tenantA, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $this->seedMaster([
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessmentA = $this->assessmentFor($tenantA, $operatorA);
        $this->map('usus_liver', '070');
        $this->recorder()->setCategory($assessmentA, 'usus_liver', AssessmentItem::CATEGORY_YELLOW, $operatorA);
        $this->selector()->evaluate($assessmentA->fresh(), Assessment::PROGRAM_CLEANSING);

        $this->bindTenant($tenantA);
        $this->assertSame(1, AssessmentDosageSelection::query()->count());

        // Tenant B sees none of tenant A's clinical reference data.
        $tenantB = Tenant::factory()->create();
        $this->member($tenantB, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);
        $this->bindTenant($tenantB);

        $this->assertSame(0, AssessmentDosageSelection::query()->count());
        $this->assertSame(0, DosageIndicatorMapping::query()->count());
    }

    // ============================ 5. Examiner surface + portal isolation

    public function test_only_an_authorized_examiner_may_view_the_priority_reference(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->seedMaster([
            $this->entry('070', 'Liver', ['Cleansing' => '2 X 2']),
        ]);

        $assessment = $this->assessmentFor($tenant, $operator);
        $examinationId = (string) $assessment->examination_id;

        // A LBA operator lacks exam.review: the internal priority reference is
        // hidden and a direct mutation writes nothing.
        $this->actingAs($operator);
        $this->bindTenant($tenant);

        $page = new AssessmentForm;
        $page->examinationId = $examinationId;
        $page->assessmentId = (string) $assessment->getKey();

        $this->assertFalse($page->canViewDosageReference());
        $this->assertEmpty($page->dosageSelections());

        $page->evaluateDosageReference(Assessment::PROGRAM_CLEANSING);

        $this->bindTenant($tenant);
        $this->assertSame(0, AssessmentDosageSelection::query()->count());

        // The authorized reviewer (exam.review) sees the section.
        $this->actingAs($reviewer);
        $this->bindTenant($tenant);

        $reviewerPage = new AssessmentForm;
        $reviewerPage->examinationId = $examinationId;
        $reviewerPage->assessmentId = (string) $assessment->getKey();

        $this->assertTrue($reviewerPage->canViewDosageReference());
    }

    public function test_the_internal_priority_reference_is_never_rendered_on_the_patient_portal(): void
    {
        $html = view('lba::reports.assessment', [
            'locale' => 'en',
            'version' => 1,
            'brand' => ['name' => 'Synthetic Brand', 'display_name' => 'Synthetic Brand'],
            'branch' => [],
            'reviewer' => [],
            'patient' => ['name' => 'Synthetic Patient', 'number' => '—'],
            'examination' => ['id' => '—', 'mode' => 'manual', 'captured_at' => null],
            'summary' => [
                'findings' => [],
                'neutral' => [],
                'has_reviewed_terminology' => false,
                'pending' => 0,
            ],
            'program_phase' => Assessment::PROGRAM_NOT_SPECIFIED,
            'evidence' => [],
            'item_count' => 0,
            'generated_at' => '2026-01-01T00:00:00+00:00',
        ])->render();

        // The patient-facing report carries NO internal dosage reference section
        // and no Lampiran A priority entry.
        $this->assertStringNotContainsString(__('lba.dosage.title'), $html);
        $this->assertStringNotContainsString('Lampiran A', $html);
    }

    // ==================================== 6. Brand/SKU sharing one version

    public function test_the_same_reference_version_can_back_several_brands(): void
    {
        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
        ]);

        $catalog = $this->catalog();

        foreach (['babul-syiffa', 'nubiom', 'biodam'] as $brandKey) {
            $result = $catalog->linkBrand(DosageReferenceCatalog::DEFAULT_KEY, $brandKey);
            $this->assertTrue($result['linked']);
            $this->assertTrue($result['created']);
            $this->assertSame(1, $result['version']);
        }

        // NuBiom and Biodam reuse the SAME version as Babul Syiffa.
        $links = LbaDosageReferenceBrandLink::query()->get();
        $this->assertCount(3, $links);
        $this->assertCount(1, $links->pluck('reference_version_id')->unique());

        $version = $catalog->activeVersionForBrandKey('nubiom');
        $this->assertNotNull($version);
        $this->assertSame(1, (int) $version->version);

        // Linking again never duplicates.
        $again = $catalog->linkBrand(DosageReferenceCatalog::DEFAULT_KEY, 'nubiom');
        $this->assertTrue($again['linked']);
        $this->assertFalse($again['created']);
        $this->assertSame(3, LbaDosageReferenceBrandLink::query()->count());
    }

    public function test_linking_a_brand_without_a_reference_version_reports_nothing_to_link(): void
    {
        $result = $this->catalog()->linkBrand(DosageReferenceCatalog::DEFAULT_KEY, 'nubiom');

        $this->assertFalse($result['linked']);
        $this->assertNull($result['version']);
        $this->assertSame(0, LbaDosageReferenceBrandLink::query()->count());
        $this->assertNull($this->catalog()->activeVersionForBrandKey('nubiom'));
    }

    public function test_the_link_brand_command_links_the_default_brands_idempotently(): void
    {
        $this->seedMaster([
            $this->entry('010', 'Asthma', ['Cleansing' => '4 X 2']),
        ]);

        Brand::factory()->create(['slug' => 'nubiom', 'name' => 'NuBiom']);

        $this->artisan('lba:dosage:link-brand')->assertSuccessful();
        $this->assertSame(3, LbaDosageReferenceBrandLink::query()->count());

        // A central Brand row matched by slug is recorded as brand_id.
        $nubiomLink = LbaDosageReferenceBrandLink::query()->where('brand_key', 'nubiom')->firstOrFail();
        $this->assertNotNull($nubiomLink->brand_id);

        $this->artisan('lba:dosage:link-brand')->assertSuccessful();
        $this->assertSame(3, LbaDosageReferenceBrandLink::query()->count());
    }
}
