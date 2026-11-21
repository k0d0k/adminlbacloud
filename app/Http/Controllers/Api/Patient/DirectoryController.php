<?php

namespace App\Http\Controllers\Api\Patient;

use App\Platform\Api\PatientApiAuthorizer;
use App\Platform\Api\Support\ApiResponse;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Appointments\Services\BranchAvailability;
use Modules\LBA\Services\BrandDirectory;

/**
 * Phase 8B — brand, clinic and branch directory.
 *
 * Brands come from the tenant's ACTIVE, legitimately-linked brand set (reusing
 * {@see BrandDirectory}, so the same rule the clinic applies governs the API).
 * Branches are the ACTIVE branches of the bound clinic database — the tenant
 * scope comes from the token, never the request.
 */
final class DirectoryController extends PatientApiController
{
    public function __construct(
        PatientApiAuthorizer $auth,
        private readonly BrandDirectory $brands,
        private readonly BranchAvailability $availability,
    ) {
        parent::__construct($auth);
    }

    public function brands(Request $request): JsonResponse
    {
        $brands = $this->brands->activeBrandsForTenant($this->auth->tenantId())
            ->map(fn ($brand) => [
                'id' => (string) $brand->getKey(),
                'name' => (string) $brand->name,
                'slug' => (string) $brand->slug,
            ])
            ->values()
            ->all();

        return ApiResponse::ok($brands);
    }

    public function branches(Request $request): JsonResponse
    {
        // Accept the documented directory filters (`city`, `postcode`) as well
        // as the generic `search` alias, so the GoodBarber-facing contract
        // (`/api/v1/...?city=&postcode=&lat=&lng=`) is honoured.
        $search = $this->firstQueryString($request, ['search', 'city', 'postcode']);

        $query = TenantBranch::query()->active()->orderBy('name');

        if ($search !== '') {
            $term = '%'.$search.'%';

            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('region', 'like', $term)
                    ->orWhere('postal_code', 'like', $term)
                    ->orWhere('address', 'like', $term);
            });
        }

        $branches = $query->get();

        // Optional nearest-first ordering when the caller shares its position.
        $latitude = $this->firstQueryNumber($request, ['latitude', 'lat']);
        $longitude = $this->firstQueryNumber($request, ['longitude', 'lng']);

        if ($latitude !== null && $longitude !== null) {
            $branches = $branches
                ->sortBy(fn (TenantBranch $branch): float => $branch->latitude === null || $branch->longitude === null
                    ? PHP_FLOAT_MAX
                    : $this->distanceKm($latitude, $longitude, (float) $branch->latitude, (float) $branch->longitude))
                ->values();
        }

        return ApiResponse::ok(
            $branches->map(fn (TenantBranch $branch) => $this->branchArray($branch))->values()->all(),
        );
    }

    /**
     * The first non-empty string among the given query keys, trimmed.
     *
     * @param  list<string>  $keys
     */
    private function firstQueryString(Request $request, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * The first numeric query value among the given keys, or null.
     *
     * @param  list<string>  $keys
     */
    private function firstQueryNumber(Request $request, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $request->query($key);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function distanceKm(float $latitude, float $longitude, float $targetLatitude, float $targetLongitude): float
    {
        $earthRadius = 6371.0;

        $latFrom = deg2rad($latitude);
        $latTo = deg2rad($targetLatitude);
        $latDelta = deg2rad($targetLatitude - $latitude);
        $lngDelta = deg2rad($targetLongitude - $longitude);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }

    public function branch(Request $request, string $branch): JsonResponse
    {
        $model = TenantBranch::query()->whereKey($branch)->first();

        abort_if($model === null, 404);

        return ApiResponse::ok($this->branchArray($model));
    }

    /**
     * The bookable slots of one branch on one day, computed from the branch's
     * own scheduling rules. Read-only: booking still runs through the guards.
     */
    public function availability(Request $request, string $branch): JsonResponse
    {
        $model = TenantBranch::query()->whereKey($branch)->first();

        abort_if($model === null, 404);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = Carbon::createFromFormat(
            'Y-m-d',
            $validated['date'],
            $model->timezone ?: config('app.timezone'),
        );

        $slots = $this->availability->slotsForDate($model, $date);

        return ApiResponse::ok($slots, [
            'branch' => [
                'id' => (string) $model->getKey(),
                'name' => (string) $model->name,
            ],
            'date' => $validated['date'],
            'slot_minutes' => $model->effectiveSlotMinutes(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function branchArray(TenantBranch $branch): array
    {
        return [
            'id' => (string) $branch->getKey(),
            'name' => (string) $branch->name,
            'code' => $branch->code,
            'location_type' => $branch->location_type ?? TenantBranch::LOCATION_PERMANENT,
            'address' => $branch->address,
            'city' => $branch->city,
            'region' => $branch->region,
            'postal_code' => $branch->postal_code,
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'country' => $branch->country,
            'phone' => $branch->phone,
            'email' => $branch->email,
            'status' => $branch->status,
            'timezone' => $branch->timezone,
            'operates_from' => $branch->operates_from?->toIso8601String(),
            'operates_until' => $branch->operates_until?->toIso8601String(),
            'opens_at' => $branch->opens_at,
            'closes_at' => $branch->closes_at,
            'slot_minutes' => $branch->effectiveSlotMinutes(),
            'capacity' => $branch->effectiveCapacity(),
        ];
    }
}
