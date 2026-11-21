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
use Modules\Appointments\Models\Appointment;

/**
 * Real today's appointment schedule (replaces the synthetic `clinic.schedule` demo).
 *
 * Lists the acting identity's accessible-branch appointments for today, each
 * showing the patient name, time slot and status. Renders an honest empty state
 * when there are none or when the context/permissions do not allow showing them.
 */
class ClinicScheduleWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    public int|string|array $span = 1;

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
            'variant' => 'list',
            'heading' => __('lba.widget.schedule_heading'),
            'description' => __('lba.widget.schedule_description'),
            'items' => $this->items(),
            'note' => null,
        ];
    }

    /**
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

        if (! app(ModuleGate::class)->allows('appointments', PanelAccessPolicy::PANEL_CLINIC)
            || ! app(ChecksPermissions::class)->allows($user, PermissionKey::BOOKING_READ, $context)) {
            return [];
        }

        $branchIds = array_map(
            static fn ($option): string => (string) $option->id,
            $branches->eligibleOptions($user),
        );

        if ($branchIds === []) {
            return [];
        }

        $appointments = Appointment::query()
            ->with('patient')
            ->whereIn('branch_id', $branchIds)
            ->whereDate('starts_at', now()->toDateString())
            ->orderBy('starts_at')
            ->limit(8)
            ->get();

        $rows = [];

        foreach ($appointments as $appointment) {
            $rows[] = [
                'title' => $appointment->patient?->full_name ?? __('lba.widget.analysis_unknown_patient'),
                'subtitle' => $appointment->starts_at?->format('H:i').' — '.$appointment->ends_at?->format('H:i'),
                'meta' => '',
                'badge' => [
                    'label' => __('lba.appointment.status_'.$appointment->status),
                    'tone' => $this->statusTone($appointment->status),
                ],
            ];
        }

        return $rows;
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            Appointment::STATUS_CHECKED_IN => 'teal',
            Appointment::STATUS_CANCELLED => 'rose',
            Appointment::STATUS_COMPLETED => 'navy',
            default => 'slate',
        };
    }
}
