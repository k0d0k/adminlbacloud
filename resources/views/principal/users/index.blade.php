@extends('principal.layouts.app', ['title' => __('principal.users.title')])

@section('content')
    <section class="principal-card">
        <div class="grid gap-3 border-b border-slate-200 p-4 sm:p-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-navy-900">{{ __('principal.users.title') }}</h1>
                    <p class="text-sm text-slate-600">{{ __('principal.users.subtitle') }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('principal.users.membership_hint') }}</p>
                </div>
                <form method="get" class="flex min-w-0 flex-wrap items-end gap-3">
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.users.search') }}
                        <input class="principal-input" type="search" name="search" value="{{ $search }}" placeholder="{{ __('principal.users.search_placeholder') }}">
                    </label>
                    <button class="principal-button principal-button-primary" type="submit">{{ __('principal.brands.apply') }}</button>
                </form>
            </div>
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ __('principal.users.temporary_hint') }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-max text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        @foreach (['member', 'email', 'roles', 'memberships', 'status', 'actions'] as $column)
                            <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('principal.users.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        @php($roleKeys = collect([$user->role])
                            ->filter()
                            ->merge($user->tenantMemberships->flatMap(fn ($membership) => $membership->roles->pluck('key')))
                            ->unique())
                        <tr>
                            <td class="px-4 py-3 font-semibold text-navy-900 sm:px-5">{{ $user->name }}</td>
                            <td class="px-4 py-3 break-all sm:px-5">{{ $user->email }}</td>
                            <td class="px-4 py-3 sm:px-5">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($roleKeys as $roleKey)
                                        <span class="inline-block rounded bg-slate-100 px-2 py-0.5 text-xs text-navy-900">{{ __('lba.roles.'.$roleKey) }}</span>
                                    @empty
                                        <span class="text-slate-500">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3 sm:px-5">
                                @if ($user->tenantMemberships->isEmpty())
                                    <span class="text-slate-500">—</span>
                                @else
                                    <div class="grid gap-2">
                                        @foreach ($user->tenantMemberships as $membership)
                                            <div class="grid justify-items-start gap-1">
                                                <span>{{ $membership->tenant?->name ?? __('principal.users.tenant_unavailable') }}</span>
                                                <div class="flex flex-wrap gap-1">
                                                    <span class="inline-block rounded px-2 py-0.5 text-xs {{ $membership->status === App\Models\TenantMembership::STATUS_ACTIVE ? 'bg-brand-50 text-brand-700' : 'bg-amber-50 text-amber-800' }}">
                                                        {{ __('lba.tenants.member_status_'.$membership->status) }}
                                                    </span>
                                                    @if ($membership->tenant === null)
                                                        <span class="inline-block rounded bg-red-50 px-2 py-0.5 text-xs text-red-700">{{ __('principal.users.tenant_unavailable') }}</span>
                                                    @else
                                                        <span class="inline-block rounded px-2 py-0.5 text-xs {{ $membership->tenant->status === App\Models\Tenant::STATUS_ACTIVE ? 'bg-brand-50 text-brand-700' : 'bg-red-50 text-red-700' }}">
                                                            {{ __($membership->tenant->status === App\Models\Tenant::STATUS_ACTIVE ? 'principal.users.tenant_active' : 'principal.users.tenant_suspended') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 sm:px-5">
                                <span class="inline-block rounded px-2 py-0.5 text-xs {{ $user->is_active ? 'bg-brand-50 text-brand-700' : 'bg-red-50 text-red-700' }}">
                                    {{ __($user->is_active ? 'principal.users.status_active' : 'principal.users.status_inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 sm:px-5">
                                <div class="grid gap-2">
                                    <form method="post" action="{{ route('principal.tailadmin.users.password-reset', $user) }}">
                                        @csrf
                                        <button class="principal-button" type="submit">{{ __('lba.password_reset.super_admin_action') }}</button>
                                    </form>
                                    <details>
                                        <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('principal.users.set_action') }}</summary>
                                        <form method="post" action="{{ route('principal.tailadmin.users.password', $user) }}" class="mt-2 grid gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <label class="grid gap-1 text-xs text-slate-600">
                                                {{ __('principal.users.new_password') }}
                                                <input class="principal-input" type="password" name="password" autocomplete="new-password" required minlength="12">
                                            </label>
                                            <label class="grid gap-1 text-xs text-slate-600">
                                                {{ __('principal.users.password_confirmation') }}
                                                <input class="principal-input" type="password" name="password_confirmation" autocomplete="new-password" required minlength="12">
                                            </label>
                                            <button class="principal-button principal-button-primary" type="submit">{{ __('principal.users.set_submit') }}</button>
                                        </form>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-500">{{ __('principal.users.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $users->links() }}</div>
        @endif
    </section>
@endsection
