<?php

namespace App\Filament\Clinic\Widgets;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Filament\Widgets\Widget;

/**
 * Real clinic dashboard hero band (replaces the synthetic `clinic.hero` demo).
 *
 * Shows the verified partner (tenant) name, active branch, and today's date.
 * No synthetic disclaimer — every value is resolved from the acting identity's
 * verified tenant/branch context.
 */
class ClinicHeroWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var view-string
     */
    protected string $view = 'filament.lba.widgets.hero';

    protected function getViewData(): array
    {
        $user = auth()->user();
        $userName = $user?->name;
        $firstName = filled($userName) ? str($userName)->before(' ')->toString() : null;

        $tenantName = null;
        $branchName = null;

        if ($user instanceof User) {
            $tenant = app(ManagesTenantContext::class)->currentTenant($user);
            $tenantName = $tenant?->name;
            $branch = app(ManagesBranchContext::class)->currentBranch($user);
            $branchName = $branch?->name;
        }

        $chips = [];

        if ($tenantName !== null) {
            $chips[] = ['label' => __('lba.widget.hero_clinic').': '.$tenantName, 'tone' => 'navy'];
        }

        $chips[] = ['label' => __('lba.widget.hero_today').' · '.now()->translatedFormat('j M Y'), 'tone' => 'slate'];

        return [
            'eyebrow' => __('lba.widget.hero_eyebrow_clinic'),
            'title' => __('lba.widget.hero_title_clinic'),
            'subtitle' => __('lba.widget.hero_subtitle_clinic'),
            'chips' => $chips,
            'userName' => $firstName,
            'note' => null,
        ];
    }
}
