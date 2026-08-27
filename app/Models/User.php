<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'personal_email', 'password', 'role_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_user_id');
    }

    /**
     * Build-plan 12 — FR-7.5's automatic half: among active users holding
     * Role::SALES_REP_ROLE_NAME, picks the one with the fewest currently
     * open (not-yet-converted) leads, for basic round-robin fairness. Returns
     * null when no such user exists yet (build-plan 13's own seeded note:
     * the role exists but isn't assigned to a real user for MVP) — a valid,
     * expected state per build-plan 12's own report, not an error.
     */
    public static function pickForAutoAssignment(): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('name', Role::SALES_REP_ROLE_NAME))
            ->withCount(['leads' => fn ($q) => $q->whereNull('converted_at')])
            ->orderBy('leads_count')
            ->orderBy('id')
            ->first();
    }

    /**
     * Generic resource+action permission check (3.12), data-driven off role_permissions
     * so a future limited role (e.g. "עובדת מכירות") only needs new rows — no migration.
     * A '*' resource or action row acts as a wildcard; the most specific match wins by
     * simply requiring an allowing row to exist (no matching row => denied by default).
     */
    public function hasPermission(string $resource, string $action): bool
    {
        return $this->role?->hasPermission($resource, $action) ?? false;
    }
}
