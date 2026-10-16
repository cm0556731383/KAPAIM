<?php

namespace App\Models;

use App\Support\PagePermissions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_active'])]
class Role extends Model
{
    use HasFactory;

    /**
     * Build-plan 13's future-facing role, seeded (not yet assigned to any
     * real user for MVP) by ReferenceDataSeeder — build-plan 12's landing-
     * page lead intake (Lead::createFromLandingPage()) auto-assigns among
     * active users holding exactly this role, per FR-7.5.
     */
    public const SALES_REP_ROLE_NAME = 'עובדת מכירות';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Data-driven resource+action check (3.12) — see App\Models\User::hasPermission(),
     * which delegates here. A '*' resource or action row acts as a wildcard; an
     * exact row (allowed or not) overrides it, which is how the per-page
     * matrix (App\Support\PagePermissions) turns one page off for a full-access
     * role. 'manage' is the older name for 'edit', and 'edit' implies 'view'.
     */
    public function hasPermission(string $resource, string $action): bool
    {
        $resource = PagePermissions::ALIASES[$resource] ?? $resource;
        $action = $action === 'manage' ? 'edit' : $action;

        return $this->allows($resource, $action)
            || ($action === 'view' && $this->allows($resource, 'edit'));
    }

    private function allows(string $resource, string $action): bool
    {
        $exact = $this->permissions->first(fn (RolePermission $p) => $p->resource === $resource && $p->action === $action);

        if ($exact) {
            return $exact->is_allowed;
        }

        return $this->permissions->contains(fn (RolePermission $p) => $p->is_allowed
            && in_array($p->resource, [$resource, '*'], true)
            && in_array($p->action, [$action, '*'], true));
    }

    /** Sets one page permission as an exact row (overriding any wildcard). */
    public function setPermission(string $resource, string $action, bool $allowed): void
    {
        $this->permissions()->updateOrCreate(['resource' => $resource, 'action' => $action], ['is_allowed' => $allowed]);
        $this->unsetRelation('permissions');
    }
}
