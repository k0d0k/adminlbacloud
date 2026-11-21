<?php

namespace App\Filament\Clinic\Widgets;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\AiBilling\AiWalletService;
use Filament\Widgets\Widget;

/**
 * Real AI credit wallet balance (replaces the synthetic `clinic.token` demo).
 *
 * Reads the tenant's central AI wallet (available + reserved credits) and
 * lifetime consumption. No fabricated numbers — if no wallet exists yet, an
 * honest "0 token" is shown rather than a demo balance.
 */
class ClinicTokenWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 6;

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
        $balance = $this->balance();

        return [
            'variant' => 'metric',
            'heading' => __('lba.widget.token_heading'),
            'description' => __('lba.widget.token_description'),
            'items' => [],
            'primary' => [
                'label' => __('lba.widget.token_available'),
                'value' => number_format($balance['available']),
                'unit' => 'token',
            ],
            'progress' => $balance['progress'],
            'progressLabel' => $balance['progress'] !== null
                ? $balance['progress'].'% '.__('lba.widget.token_of_quota')
                : null,
            'secondary' => [
                'label' => __('lba.widget.token_consumed'),
                'value' => number_format($balance['consumed']),
                'unit' => 'token',
            ],
            'note' => null,
        ];
    }

    /**
     * @return array{available: int, consumed: int, progress: int|null}
     */
    private function balance(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return ['available' => 0, 'consumed' => 0, 'progress' => null];
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return ['available' => 0, 'consumed' => 0, 'progress' => null];
        }

        $wallet = app(AiWalletService::class)->walletFor($tenantId);

        if ($wallet === null) {
            return ['available' => 0, 'consumed' => 0, 'progress' => null];
        }

        $available = (int) $wallet->available_credits + (int) $wallet->reserved_credits;
        $consumed = (int) $wallet->lifetime_consumed_credits;

        // If the wallet has any credits, show progress relative to a soft
        // monthly quota (500k credits). This is an indicative meter, not a
        // hard limit — the wallet itself never enforces a monthly cap.
        $quota = 500000;
        $progress = $available > 0 ? (int) min(100, round(($available / $quota) * 100)) : null;

        return [
            'available' => $available,
            'consumed' => $consumed,
            'progress' => $progress,
        ];
    }
}
