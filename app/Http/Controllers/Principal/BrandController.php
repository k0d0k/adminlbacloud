<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Principal\SaveBrandReportIdentityRequest;
use App\Http\Requests\Principal\SaveBrandRequest;
use App\Models\Brand;
use App\Platform\Crud\Generated\BrandCrud;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class BrandController extends Controller
{
    public function index(Request $request): View
    {
        $filterEnabled = in_array('status', BrandCrud::OPTIONS['filters'], true);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => [$filterEnabled ? 'nullable' : 'exclude', Rule::in([Brand::STATUS_ACTIVE, Brand::STATUS_SUSPENDED])],
        ]);

        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        $brands = Brand::query()
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('slug', "%{$search}%"),
            ))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->orderBy('name')->orderBy('id')->paginate(15)->appends($filters);

        return view('principal.brands.index', compact('brands', 'search', 'status', 'filterEnabled'));
    }

    public function create(): View
    {
        return view('principal.brands.form', ['brand' => new Brand(['status' => Brand::STATUS_ACTIVE])]);
    }

    public function store(SaveBrandRequest $request): RedirectResponse
    {
        Brand::query()->create($request->validated());

        return $this->saved();
    }

    public function edit(Brand $brand): View
    {
        return view('principal.brands.form', compact('brand'));
    }

    public function update(SaveBrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->safe()->only(['name', 'slug']));

        return $this->saved();
    }

    public function status(Request $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validate([
            'status' => ['required', Rule::in([Brand::STATUS_ACTIVE, Brand::STATUS_SUSPENDED])],
        ]));

        return $this->saved();
    }

    /**
     * Remove a brand. Gated to the Principal Super Admin by
     * {@see BrandPolicy::delete()} through the route.
     *
     * A brand is only a commercial identity (ARCH-05): it is never a tenant, a
     * branch, and holds no clinical data. Removing it therefore never touches a
     * partner's account, subscription or clinical database.
     *
     * Previously this action was blocked while any partner was still linked to
     * the brand, which cascaded into an operator dead-end: to delete the brand
     * they had to detach every partner, and to delete a partner they had to
     * cancel its live subscription first. Because the brand-link carries no
     * clinical or financial obligation of its own, removal now AUTO-DETACHES
     * the brand from every linked partner inside the same transaction. The
     * partners, their subscriptions and their databases are left intact.
     */
    public function destroy(Brand $brand): RedirectResponse
    {
        $name = $brand->name;

        $detached = DB::transaction(static function () use ($brand): int {
            $count = $brand->tenants()->count();
            $brand->tenants()->detach();
            $brand->delete();

            return $count;
        });

        return to_route('principal.tailadmin.brands.index')
            ->with('success', __('principal.delete.brand_deleted', [
                'name' => $name,
                'count' => $detached,
            ]));
    }

    public function reportIdentity(Brand $brand): View
    {
        return view('principal.brands.report-identity', [
            'brand' => $brand,
            'languages' => SaveBrandReportIdentityRequest::languages(),
            'textFields' => SaveBrandReportIdentityRequest::TEXT_FIELDS,
            'longTextFields' => SaveBrandReportIdentityRequest::LONG_TEXT_FIELDS,
        ]);
    }

    public function updateReportIdentity(SaveBrandReportIdentityRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validated());

        return to_route('principal.tailadmin.brands.report-identity', $brand)
            ->with('success', __('lba.brand_report.saved'));
    }

    private function saved(): RedirectResponse
    {
        return to_route('principal.tailadmin.brands.index')->with('success', __('principal.brands.saved'));
    }
}
