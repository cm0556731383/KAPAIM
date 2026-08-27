<?php

namespace App\Models;

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
     * which delegates here. A '*' resource or action row acts as a wildcard.
     */
    public function hasPermission(string $resource, string $action): bool
    {
        return $this->permissions()
            ->where(fn ($q) => $q->where('resource', $resource)->orWhere('resource', '*'))
            ->where(fn ($q) => $q->where('action', $action)->orWhere('action', '*'))
            ->where('is_allowed', true)
            ->exists();
    }
}
