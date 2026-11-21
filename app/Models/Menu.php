<?php

namespace App\Models;

use App\Platform\Access\PermissionKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Menu Dynamic entry (v3.4).
 *
 * One navigable node the Principal composes per panel and per group. Nodes nest
 * through {@see self::$parent_id} and remember their dragged position through
 * {@see self::$sort_order}. Visibility is re-derived on every render (see
 * {@see self::visibleTo()}) — never trusted from the client.
 */
#[Fillable([
    'panel', 'group_key', 'group_label', 'label', 'icon', 'route_name',
    'url', 'permission_key', 'role_keys', 'parent_id', 'sort_order',
    'is_active', 'source',
])]
class Menu extends Model
{
    use HasUlids;

    public const PANEL_PRINCIPAL = 'principal';

    public const PANEL_CLINIC = 'clinic';

    /** @var list<string> */
    public const PANELS = [self::PANEL_PRINCIPAL, self::PANEL_CLINIC];

    public const SOURCE_CUSTOM = 'custom';

    public const SOURCE_GENERATED = 'generated';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_keys' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Menu, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * @param  Builder<Menu>  $query
     */
    public function scopeForPanel(Builder $query, string $panel): void
    {
        $query->where('panel', $panel);
    }

    /**
     * @param  Builder<Menu>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * The href this node points at: an explicit URL wins, otherwise the named
     * route is resolved. A node that resolves to neither renders as a plain
     * (non-clickable) grouping label.
     */
    public function href(): ?string
    {
        if (is_string($this->url) && $this->url !== '') {
            return $this->url;
        }

        if (is_string($this->route_name) && $this->route_name !== '' && Route::has($this->route_name)) {
            return route($this->route_name);
        }

        return null;
    }

    /**
     * Whether this node may be shown to the given actor.
     *
     * Fail-closed: inactive nodes never render; a node restricted by a
     * permission the actor lacks never renders; a node restricted by roles the
     * actor does not hold never renders.
     */
    public function visibleTo(?User $user): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($user === null) {
            return false;
        }

        $permission = $this->permission_key;

        if (is_string($permission) && $permission !== '' && ! $user->can($permission)) {
            return false;
        }

        $roles = $this->role_keys;

        if (is_array($roles) && $roles !== []) {
            $held = app(\App\Platform\Access\RoleResolver::class)->allActiveRoleKeys($user);

            if (array_intersect($roles, $held) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the full nested tree for one panel that the actor may see, grouped
     * by `group_key` in insertion order.
     *
     * @return Collection<int, array{group_key: string, group_label: string, items: list<array<string, mixed>>}>
     */
    public static function treeFor(?User $user, string $panel): Collection
    {
        $nodes = static::query()
            ->forPanel($panel)
            ->orderBy('group_key')
            ->orderBy('sort_order')
            ->get();

        $byParent = [];
        $groupLabels = [];

        foreach ($nodes as $node) {
            if (! $node->visibleTo($user)) {
                continue;
            }

            // Roots are keyed by their own group so `build($groupKey)` can start
            // from the group; children are keyed by their parent id.
            $byParent[$node->parent_id ?? $node->group_key][] = $node;
            $groupLabels[$node->group_key] = $node->group_label;
        }

        $build = function (string $parentKey) use (&$build, $byParent): array {
            $items = [];

            foreach ($byParent[$parentKey] ?? [] as $node) {
                $items[] = [
                    'id' => $node->id,
                    'label' => $node->label,
                    'icon' => $node->icon,
                    'href' => $node->href(),
                    'children' => $build($node->id),
                ];
            }

            return $items;
        };

        $groups = [];

        foreach ($nodes as $node) {
            $group = $node->group_key;

            if (array_key_exists($group, $groups)) {
                continue;
            }

            $groups[$group] = [
                'group_key' => $group,
                'group_label' => $groupLabels[$group] ?? $group,
                'items' => $build($group),
            ];
        }

        return collect(array_values($groups));
    }
}
