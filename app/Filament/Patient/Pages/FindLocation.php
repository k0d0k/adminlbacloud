<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Branches\Models\TenantBranch;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Phase 8A — "Cari Lokasi LBA".
 *
 * A read-only listing of every ACTIVE branch (clinic location) of the verified
 * tenant. It exposes only non-clinical, public-facing location details — never
 * patient, examination or staff data. A deactivated branch is never listed.
 */
class FindLocation extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'lokasi';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected string $view = 'filament.patient.pages.find-location';

    /** A free-text city / postal-code / name query typed by the patient. */
    public string $search = '';

    /** The patient's GPS position, set only when they grant permission. */
    public ?float $latitude = null;

    public ?float $longitude = null;

    public static function canAccess(): bool
    {
        return static::hasPatientContext();
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.find_location');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.locations.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.locations.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.locations.subtitle');
    }

    /**
     * The active branches of the verified tenant that match the patient's
     * typed city / postal code (and, once granted, are ordered nearest-first).
     *
     * Only PUBLISHED locations are ever listed: `active()` excludes a
     * deactivated branch and a tentative (unconfirmed) event/temporary site.
     * The query runs inside the verified tenant database, so a branch of
     * another partner can never appear. Fails closed to an empty list.
     *
     * @return Collection<int, TenantBranch>
     */
    public function branches(): Collection
    {
        try {
            $branches = TenantBranch::query()
                ->active()
                ->when($this->searchTerm() !== '', function ($query): void {
                    $term = '%'.$this->searchTerm().'%';

                    $query->where(function ($inner) use ($term): void {
                        $inner->where('name', 'like', $term)
                            ->orWhere('city', 'like', $term)
                            ->orWhere('region', 'like', $term)
                            ->orWhere('postal_code', 'like', $term)
                            ->orWhere('address', 'like', $term);
                    });
                })
                ->orderBy('name')
                ->get();
        } catch (Throwable) {
            return collect();
        }

        return $this->sortByProximity($branches);
    }

    /**
     * Adopt the browser's GPS position and re-order nearest-first. Called from
     * the portal only after the patient grants the geolocation permission.
     */
    public function useNearest(float $latitude, float $longitude): void
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    /**
     * Forget the GPS position (e.g. the patient revoked permission) and fall
     * back to the manual, alphabetical listing.
     */
    public function clearNearest(): void
    {
        $this->latitude = null;
        $this->longitude = null;
    }

    public function isUsingNearest(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    private function searchTerm(): string
    {
        return trim($this->search);
    }

    /**
     * When a GPS fix is present, order the branches by great-circle distance
     * (haversine). A branch without coordinates keeps its alphabetical slot
     * after the located ones, so manual search always still works.
     *
     * @param  Collection<int, TenantBranch>  $branches
     * @return Collection<int, TenantBranch>
     */
    private function sortByProximity(Collection $branches): Collection
    {
        if (! $this->isUsingNearest()) {
            return $branches;
        }

        return $branches
            ->sortBy(function (TenantBranch $branch): float {
                if ($branch->latitude === null || $branch->longitude === null) {
                    return PHP_FLOAT_MAX;
                }

                return $this->distanceKm((float) $branch->latitude, (float) $branch->longitude);
            })
            ->values();
    }

    private function distanceKm(float $latitude, float $longitude): float
    {
        $earthRadius = 6371.0;

        $latFrom = deg2rad((float) $this->latitude);
        $latTo = deg2rad($latitude);
        $latDelta = deg2rad($latitude - (float) $this->latitude);
        $lngDelta = deg2rad($longitude - (float) $this->longitude);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }

    public function addressLine(TenantBranch $branch): string
    {
        $parts = array_filter([
            $branch->address,
            $branch->city,
            $branch->region,
            $branch->postal_code,
            $branch->country,
        ], static fn (?string $part): bool => filled($part));

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * The great-circle distance to a located branch, formatted for display, or
     * null when the patient has not shared a GPS position or the branch has no
     * coordinates.
     */
    public function distanceLabel(TenantBranch $branch): ?string
    {
        if (! $this->isUsingNearest() || $branch->latitude === null || $branch->longitude === null) {
            return null;
        }

        $distance = $this->distanceKm((float) $branch->latitude, (float) $branch->longitude);

        return $distance < 1
            ? __('lba.patient.locations.distance_under_1km')
            : __('lba.patient.locations.distance_km', ['distance' => number_format($distance, 1)]);
    }

    /**
     * The booking URL pre-selected for this branch, so "Cari Lokasi" and
     * "Booking Pemeriksaan" are one continuous flow. The target branch is only
     * a HINT: the booking page re-validates it against its own active-branch
     * list before adopting it.
     */
    public function bookingUrl(TenantBranch $branch): string
    {
        return Booking::getUrl(['branch' => $branch->getKey()], panel: 'patient');
    }
}
