<?php

namespace Modules\Consultations\Services;

use App\Platform\Settings\PlatformSettings;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Support\ConsultationTariff;

/**
 * Workstream 2 — consultation tariff and cancellation/refund disclosure.
 *
 * The patient must "see the tariff before starting" and receive
 * cancellation/refund details (Phase 13 / acceptance row 18). The concrete fee
 * is a BUSINESS decision (see DECISIONS.md) — it is configured, never guessed —
 * so this resolver reads config and returns `null` for a channel the owner has
 * not priced. A `null` tariff discloses nothing; it is never a fabricated zero.
 *
 * SINA chat is metered per turn in the patient's own credit wallet, so its
 * "transparent configured fee unit" is the `lba.sina.billing` block; the
 * helpers below simply expose it without re-deriving any amount.
 */
final class ConsultationTariffService
{
    /**
     * The configured tariff for a channel, or null when none is set.
     */
    public function forChannel(?string $channel): ?ConsultationTariff
    {
        if (! is_string($channel) || $channel === '') {
            return null;
        }

        $amount = config("lba.consultations.tariff.{$channel}");

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        return new ConsultationTariff($channel, (float) $amount, $this->currency());
    }

    /**
     * The tariff for a consultation's own channel, or null.
     */
    public function forConsultation(Consultation $consultation): ?ConsultationTariff
    {
        return $this->forChannel($consultation->channel);
    }

    public function currency(): string
    {
        $currency = (string) config('lba.consultations.currency', 'IDR');

        return $currency === '' ? 'IDR' : $currency;
    }

    /**
     * May a not-yet-started consultation be cancelled by the patient?
     */
    public function cancellableBeforeStart(): bool
    {
        return (bool) config('lba.consultations.cancellable_before_start', true);
    }

    /**
     * Does the clinic's policy refund a cancelled/at-fault consultation?
     */
    public function refundable(): bool
    {
        return (bool) config('lba.consultations.refundable', false);
    }

    /**
     * Whether SINA turns are metered against the patient's credit wallet.
     */
    public function sinaBillingEnabled(): bool
    {
        return $this->sinaBilling()['enabled'];
    }

    /**
     * The credits reserved (and released/captured) around one SINA turn — the
     * "price of one chat".
     */
    public function sinaCreditsPerTurn(): int
    {
        return $this->sinaBilling()['credits_per_turn'];
    }

    /**
     * The credits charged per 1,000 tokens of a captured SINA turn.
     */
    public function sinaCreditsPerThousandTokens(): int
    {
        return $this->sinaBilling()['credits_per_1k_tokens'];
    }

    public function sinaCreditCurrency(): string
    {
        return $this->sinaBilling()['currency'];
    }

    /**
     * The EFFECTIVE SINA billing configuration: a Principal override persisted
     * through {@see PlatformSettings} (so a price change applies without a
     * deploy), falling back to the `lba.sina.billing` `.env` values.
     *
     * @return array{enabled: bool, credits_per_turn: int, credits_per_1k_tokens: int, currency: string}
     */
    private function sinaBilling(): array
    {
        return app(PlatformSettings::class)->sinaBilling();
    }
}
