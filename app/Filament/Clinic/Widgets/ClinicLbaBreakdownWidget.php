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
 * Real LBA breakdown widget (replaces the synthetic `clinic.status` and
 * `clinic.workflow` demo cards).
 *
 * Two variants driven by the `type` property:
 *  - `status` : examination/assessment status distribution (Selesai, Menunggu
 *               tinjauan, Dalam proses, Perlu tindak lanjut)
 *  - `mode`   : analysis mode distribution (AI, Hybrid, Manual)
 *
 * All counts come from the verified tenant database, scoped to the acting
 * identity's accessible branch.
 */
class ClinicLbaBreakdownWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    public int|string|array $span = 1;

    /** Breakdown type: status | mode. */
    public string $type = 'status';

    /**
     * @var view-string
     */
    protected string $view = 'filament.lba.widgets.section';

    public function getColumnSpan(): int|string|array
    {
        return $this->span;
    }

    protected function getViewData(): array
    {
        return [
            'variant' => 'breakdown',
            'heading' => $this->type === 'mode'
                ? __('lba.widget.lba_mode_heading')
                : __('lba.widget.lba_status_heading'),
            'description' => $this->type === 'mode'
                ? __('lba.widget.lba_mode_description')
                : __('lba.widget.lba_status_description'),
            'items' => $this->items(),
            'note' => null,
        ];
    }

    /**
     * @return array<int, array{label: string, value: int, max: int, tone: string, caption: string}>
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

        return $this->type === 'mode'
            ? $this->modeBreakdown($branchId === null ? null : (string) $branchId)
            : $this->statusBreakdown($branchId === null ? null : (string) $branchId);
    }

    /**
     * @return array<int, array{label: string, value: int, max: int, tone: string, caption: string}>
     */
    private function statusBreakdown(?string $branchId): array
    {
        $examsQuery = Examination::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', Examination::STATUS_CANCELLED);

        $assessQuery = Assessment::query()
            ->when($branchId !== null, fn ($q) => $q->whereHas('examination', fn ($e) => $e->where('branch_id', $branchId)));

        $completed = (clone $assessQuery)->where('status', Assessment::STATUS_REVIEWED)->count();
        $awaitingReview = (clone $assessQuery)->where('status', Assessment::STATUS_SUBMITTED_FOR_REVIEW)->count();
        $inProgress = (clone $examsQuery)->whereIn('status', [
            Examination::STATUS_UPLOADED,
            Examination::STATUS_MANUAL_ANNOTATION,
        ])->count();
        $needsFollowUp = (clone $assessQuery)->where('status', Assessment::STATUS_RETURNED_FOR_CHANGES)->count();

        $total = max(1, $completed + $awaitingReview + $inProgress + $needsFollowUp);

        return [
            ['label' => __('lba.widget.lba_status_completed'), 'value' => $completed, 'max' => $total, 'tone' => 'teal', 'caption' => $this->pct($completed, $total)],
            ['label' => __('lba.widget.lba_status_review'), 'value' => $awaitingReview, 'max' => $total, 'tone' => 'amber', 'caption' => $this->pct($awaitingReview, $total)],
            ['label' => __('lba.widget.lba_status_process'), 'value' => $inProgress, 'max' => $total, 'tone' => 'navy', 'caption' => $this->pct($inProgress, $total)],
            ['label' => __('lba.widget.lba_status_followup'), 'value' => $needsFollowUp, 'max' => $total, 'tone' => 'rose', 'caption' => $this->pct($needsFollowUp, $total)],
        ];
    }

    /**
     * @return array<int, array{label: string, value: int, max: int, tone: string, caption: string}>
     */
    private function modeBreakdown(?string $branchId): array
    {
        $query = Examination::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', Examination::STATUS_CANCELLED);

        $ai = (clone $query)->where('mode', Examination::MODE_AI)->count();
        $hybrid = (clone $query)->where('mode', Examination::MODE_HYBRID)->count();
        $manual = (clone $query)->where('mode', Examination::MODE_MANUAL)->count();

        $total = max(1, $ai + $hybrid + $manual);

        return [
            ['label' => __('lba.examination.mode_ai'), 'value' => $ai, 'max' => $total, 'tone' => 'teal', 'caption' => $this->pct($ai, $total)],
            ['label' => __('lba.examination.mode_hybrid'), 'value' => $hybrid, 'max' => $total, 'tone' => 'navy', 'caption' => $this->pct($hybrid, $total)],
            ['label' => __('lba.examination.mode_manual'), 'value' => $manual, 'max' => $total, 'tone' => 'slate', 'caption' => $this->pct($manual, $total)],
        ];
    }

    private function pct(int $value, int $total): string
    {
        return $total > 0 ? (int) round(($value / $total) * 100).'%' : '0%';
    }
}
