<?php

namespace App\Filament\Clinic\Widgets;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\ModuleGate;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Widgets\Widget;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\Examination;

/**
 * Real LBA analysis queue widget (replaces the synthetic `clinic.examinations`
 * and `clinic.queue` demo cards).
 *
 * Renders one of three live patient lists from the verified tenant database:
 *  - `pending`   : examinations still needing operator work (draft / uploaded /
 *                  manual_annotation / returned_for_changes)
 *  - `review`    : assessments submitted for human review (submitted_for_review)
 *  - `completed` : assessments reviewed with an approved report (reviewed)
 *
 * Every row names the PATIENT so the doctor knows who is waiting, scoped to the
 * acting identity's accessible branch. A row that may not be shown renders the
 * honest empty state rather than a fabricated entry.
 */
class LbaAnalysisQueueWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 1;

    public int|string|array $span = 1;

    /**
     * @var view-string
     */
    protected string $view = 'filament.lba.widgets.section';

    /** Queue type: pending | review | completed. */
    public string $type = 'pending';

    /** Maximum rows to surface on the dashboard. */
    public int $limit = 6;

    /**
     * @return int | string | array<string, int | null>
     */
    public function getColumnSpan(): int|string|array
    {
        return $this->span;
    }

    protected function getViewData(): array
    {
        $config = $this->config();

        return [
            'variant' => 'list',
            'heading' => $config['heading'],
            'description' => $config['description'],
            'items' => $this->items(),
            'note' => null,
        ];
    }

    /**
     * @return array{heading: string, description: string}
     */
    private function config(): array
    {
        return match ($this->type) {
            'review' => [
                'heading' => __('lba.widget.analysis_review_heading'),
                'description' => __('lba.widget.analysis_review_description'),
            ],
            'completed' => [
                'heading' => __('lba.widget.analysis_completed_heading'),
                'description' => __('lba.widget.analysis_completed_description'),
            ],
            default => [
                'heading' => __('lba.widget.analysis_pending_heading'),
                'description' => __('lba.widget.analysis_pending_description'),
            ],
        };
    }

    /**
     * Build the live list rows from the verified tenant database.
     *
     * @return array<int, array{title: string, subtitle: string, meta: string, badge: array{label: string, tone: string}}>
     */
    private function items(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return [];
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null || app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        $branches = app(ManagesBranchContext::class);
        $context = AccessContext::forTenant($tenantId, $branches->currentBranchId($user));

        if (! app(ModuleGate::class)->allows('lba', PanelAccessPolicy::PANEL_CLINIC)
            || ! app(ChecksPermissions::class)->allows($user, PermissionKey::EXAM_READ, $context)) {
            return [];
        }

        $branchId = $branches->currentBranchId($user);

        return match ($this->type) {
            'review' => $this->reviewRows($branchId === null ? null : (string) $branchId),
            'completed' => $this->completedRows($branchId === null ? null : (string) $branchId),
            default => $this->pendingRows($branchId === null ? null : (string) $branchId),
        };
    }

    /**
     * Examinations still needing operator work.
     *
     * @return array<int, array{title: string, subtitle: string, meta: string, badge: array{label: string, tone: string}}>
     */
    private function pendingRows(?string $branchId): array
    {
        $examinations = Examination::query()
            ->with('patient')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', Examination::EDITABLE_STATUSES)
            ->where('status', '!=', Examination::STATUS_CANCELLED)
            ->latest('updated_at')
            ->limit($this->limit)
            ->get();

        $rows = [];

        foreach ($examinations as $examination) {
            $rows[] = [
                'title' => $examination->patient?->full_name ?? __('lba.widget.analysis_unknown_patient'),
                'subtitle' => __('lba.examination.mode_'.$examination->mode).' · '.__('lba.exams.status_'.$examination->status),
                'meta' => $examination->updated_at?->diffForHumans() ?? '',
                'badge' => [
                    'label' => $this->examinationStatusLabel($examination->status),
                    'tone' => $this->examinationStatusTone($examination->status),
                ],
            ];
        }

        return $rows;
    }

    /**
     * Assessments submitted for human review.
     *
     * @return array<int, array{title: string, subtitle: string, meta: string, badge: array{label: string, tone: string}}>
     */
    private function reviewRows(?string $branchId): array
    {
        $assessments = Assessment::query()
            ->with(['examination.patient'])
            ->when($branchId !== null, fn ($q) => $q->whereHas(
                'examination',
                fn ($e) => $e->where('branch_id', $branchId),
            ))
            ->where('status', Assessment::STATUS_SUBMITTED_FOR_REVIEW)
            ->latest('submitted_at')
            ->limit($this->limit)
            ->get();

        $rows = [];

        foreach ($assessments as $assessment) {
            $examination = $assessment->examination;
            $rows[] = [
                'title' => $examination?->patient?->full_name ?? __('lba.widget.analysis_unknown_patient'),
                'subtitle' => __('lba.examination.mode_'.$assessment->mode).' · '.__('lba.assessment.status_submitted_for_review'),
                'meta' => $assessment->submitted_at?->diffForHumans() ?? '',
                'badge' => [
                    'label' => __('lba.assessment.status_submitted_for_review'),
                    'tone' => 'amber',
                ],
            ];
        }

        return $rows;
    }

    /**
     * Assessments reviewed with an approved report.
     *
     * @return array<int, array{title: string, subtitle: string, meta: string, badge: array{label: string, tone: string}}>
     */
    private function completedRows(?string $branchId): array
    {
        $assessments = Assessment::query()
            ->with(['examination.patient'])
            ->when($branchId !== null, fn ($q) => $q->whereHas(
                'examination',
                fn ($e) => $e->where('branch_id', $branchId),
            ))
            ->where('status', Assessment::STATUS_REVIEWED)
            ->has('reportVersions')
            ->latest('reviewed_at')
            ->limit($this->limit)
            ->get();

        $rows = [];

        foreach ($assessments as $assessment) {
            $examination = $assessment->examination;
            $rows[] = [
                'title' => $examination?->patient?->full_name ?? __('lba.widget.analysis_unknown_patient'),
                'subtitle' => __('lba.examination.mode_'.$assessment->mode).' · '.__('lba.assessment.status_reviewed'),
                'meta' => $assessment->reviewed_at?->diffForHumans() ?? '',
                'badge' => [
                    'label' => __('lba.assessment.status_reviewed'),
                    'tone' => 'teal',
                ],
            ];
        }

        return $rows;
    }

    private function examinationStatusLabel(string $status): string
    {
        return __('lba.exams.status_'.$status);
    }

    private function examinationStatusTone(string $status): string
    {
        return match ($status) {
            Examination::STATUS_DRAFT => 'slate',
            Examination::STATUS_UPLOADED => 'navy',
            Examination::STATUS_MANUAL_ANNOTATION => 'navy',
            Examination::STATUS_RETURNED_FOR_CHANGES => 'rose',
            default => 'slate',
        };
    }
}
