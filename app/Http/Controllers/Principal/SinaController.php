<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Platform\Access\PermissionKey;
use App\Platform\Settings\PlatformSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Consultations\Models\AiProviderConfig;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Support\SinaEndpointGuard;

/**
 * Workstream 2 — Principal "Integrasi SINA AI" TailAdmin surface.
 *
 * One governance screen with three concerns:
 *
 *  - the SINA provider endpoint per partner (Ollama or an OpenAI-compatible
 *    base URL — for example an Open WebUI instance running on a Mac Mini M4 on
 *    the clinic LAN), whose credential is written through the model's
 *    `encrypted` cast and is NEVER echoed back;
 *  - the "price of one chat": the integer credits reserved before / captured
 *    for a completed turn ({@see PlatformSettings::updateSinaBilling()}), so a
 *    default of 1 credit per turn means one chat costs one token;
 *  - the read-only usage register ({@see SinaUsageRecord}) — provider, model,
 *    token counters and charged credits per captured turn.
 *
 * Viewing requires `platform.billing.manage` OR `platform.analytics.view`;
 * every write requires `platform.billing.manage`. Authorization is re-derived
 * server-side on each action — route middleware and the controller both check,
 * so UI visibility is never the boundary.
 */
final class SinaController extends Controller
{
    use AuthorizesPrincipalResources;

    public function index(PlatformSettings $settings): View
    {
        $this->authorizeView();

        $providers = AiProviderConfig::query()
            ->with('tenant')
            ->orderBy('tenant_id')
            ->orderBy('provider')
            ->get();

        $usage = SinaUsageRecord::query()
            ->with('tenant')
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get();

        return view('principal.sina.index', [
            'billing' => $settings->sinaBilling(),
            'providers' => $providers,
            'usage' => $usage,
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'providerOptions' => [
                AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE => __('lba.principal.ai_providers.provider_openai'),
                AiProviderConfig::PROVIDER_OLLAMA => __('lba.principal.ai_providers.provider_ollama'),
            ],
            'usageTotals' => [
                'turns' => $usage->count(),
                'credits' => (int) $usage->sum('credit_units'),
                'tokens' => (int) $usage->sum(
                    static fn (SinaUsageRecord $record): int => (int) $record->prompt_tokens + (int) $record->completion_tokens,
                ),
            ],
            'canManage' => self::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE),
        ]);
    }

    public function updateBilling(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'credits_per_turn' => ['required', 'integer', 'min:0', 'max:1000000'],
            'credits_per_1k_tokens' => ['required', 'integer', 'min:0', 'max:1000000'],
            'currency' => ['required', 'string', 'max:32'],
        ]);

        $settings->updateSinaBilling([
            'enabled' => $request->boolean('enabled'),
            'credits_per_turn' => $data['credits_per_turn'],
            'credits_per_1k_tokens' => $data['credits_per_1k_tokens'],
            'currency' => $data['currency'],
        ], $request->user());

        return to_route('principal.tailadmin.sina.index')
            ->with('success', __('lba.principal.sina.billing_saved'));
    }

    public function saveProvider(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'tenant_id' => ['required', 'string'],
            'provider' => ['required', Rule::in(AiProviderConfig::PROVIDERS)],
            'base_url' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:190'],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $tenant = Tenant::query()->find($data['tenant_id']);

        if ($tenant === null) {
            return back()->withErrors(['tenant_id' => __('lba.principal.sina.tenant_missing')])->withInput();
        }

        if (! SinaEndpointGuard::isAcceptable($data['base_url'])) {
            return back()->withErrors(['base_url' => __('lba.principal.ai_providers.error_endpoint')])->withInput();
        }

        $config = AiProviderConfig::query()->firstOrNew([
            'tenant_id' => (string) $tenant->getKey(),
            'provider' => $data['provider'],
        ]);

        $config->fill([
            'base_url' => rtrim($data['base_url'], '/'),
            'model' => $data['model'],
            'enabled' => $request->boolean('enabled'),
            'is_default' => true,
        ]);

        // The credential is only replaced when a new one is supplied; a blank
        // submission keeps whatever is configured.
        if (filled($data['api_key'] ?? null)) {
            $config->api_key = $data['api_key'];
        }

        $config->save();

        return to_route('principal.tailadmin.sina.index')
            ->with('success', __('lba.principal.ai_providers.saved'));
    }

    private function authorizeView(): void
    {
        abort_unless(
            self::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE)
                || self::principalCan(PermissionKey::PLATFORM_ANALYTICS_VIEW),
            403,
        );
    }

    private function authorizeManage(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE), 403);
    }
}
