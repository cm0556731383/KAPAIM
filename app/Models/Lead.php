<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lead has exactly one active status at a time (FR-1.4), modeled as a
 * single status_id FK (STATUS_DEFINITION scoped 'lead') rather than a
 * history table — status *changes* are still logged to activity_logs
 * (FR-1.8). `sub_status` is only meaningful under the yellow traffic-light
 * color (FR-1.6).
 *
 * STATUS_DEFINITION (docs/erd.md) has no color column, and the build-plan
 * explicitly leaves the traffic-light mapping (FR-1.5) unresolved for this
 * stage to define (see the note in ⚡settings.blade.php). TRAFFIC_LIGHT_COLORS
 * below is that mapping: the *seeded* lead statuses (ReferenceDataSeeder)
 * map to green/yellow/red; any custom status added later via the settings
 * screen falls back to 'yellow' (safe default — sub_status stays optional).
 * BADGE_CLASSES separately mirrors the exact per-status badge colors shown
 * in docs/storyboard/leads.html (which are more specific than the 3-way
 * traffic light, e.g. a distinct "new" badge), independent of the traffic
 * light — the light and the badge answer different questions (business
 * color vs. list-screen visual grouping).
 */
#[Fillable([
    'school_id', 'assigned_user_id', 'lead_source_id', 'status_id',
    'email', 'phone', 'sub_status', 'notes', 'converted_at',
])]
class Lead extends Model
{
    use HasFactory;

    public const NEW_STATUS_NAME = 'חדש';

    private const TRAFFIC_LIGHT_COLORS = [
        'חדש' => 'yellow',
        'בתהליך מכירה' => 'yellow',
        'מוכנה לסגור / רוצה לרכוש' => 'green',
        'נסגר ללא מכירה' => 'red',
    ];

    private const BADGE_CLASSES = [
        'חדש' => 'badge-info',
        'בתהליך מכירה' => 'badge-warning',
        'מוכנה לסגור / רוצה לרכוש' => 'badge-success',
        'נסגר ללא מכירה' => 'badge-error',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(LeadInteraction::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function interestedPrograms(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'lead_program');
    }

    public static function trafficLightColorForStatusName(?string $statusName): string
    {
        return self::TRAFFIC_LIGHT_COLORS[$statusName] ?? 'yellow';
    }

    public static function badgeClassForStatusName(?string $statusName): string
    {
        return self::BADGE_CLASSES[$statusName] ?? 'badge-neutral';
    }

    public function trafficLightColor(): string
    {
        return self::trafficLightColorForStatusName($this->status?->name);
    }

    /**
     * FR-1.9 "search before create", exact half: same (normalized) school
     * name or same (normalized) phone blocks duplicate creation — the
     * caller reuses the returned school's existing lead instead.
     */
    public static function findExactDuplicateSchool(string $name, ?string $phone = null): ?School
    {
        $normalizedName = self::normalizeName($name);
        $normalizedPhone = self::normalizePhone($phone);

        return School::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->when($normalizedPhone, fn ($q) => $q->orWhere('phone', $normalizedPhone))
            ->first();
    }

    /**
     * FR-1.9 "possible duplicate" half: a school whose name is *close* to
     * (but not identical to) the given name — plain levenshtein distance on
     * normalized names is enough here (build-plan 04 explicitly calls for
     * "pragmatic, not a fancy library"). Non-blocking — the caller still
     * shows a warning banner but proceeds with creation.
     */
    public static function findFuzzyDuplicateSchool(string $name, ?string $phone = null, int $threshold = 3): ?School
    {
        $normalizedName = self::normalizeName($name);
        $normalizedPhone = self::normalizePhone($phone);

        return School::query()->get()->first(function (School $school) use ($normalizedName, $normalizedPhone, $threshold) {
            $schoolNormalizedName = self::normalizeName($school->name);

            if ($schoolNormalizedName === $normalizedName) {
                return false; // exact match — handled separately, not "fuzzy"
            }

            if ($normalizedPhone && self::normalizePhone($school->phone) === $normalizedPhone) {
                return true;
            }

            return levenshtein($normalizedName, $schoolNormalizedName) <= $threshold;
        });
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : null;
    }

    /**
     * FR-1.17 stub: a new lead should auto-join the "primary mailing list",
     * but that list (MAILING_MEMBERSHIP / mailing lists module) is
     * build-plan stage 10, which doesn't exist yet. Deliberately a no-op —
     * not a fake mailing-list schema — so stage 10 has an obvious place to
     * wire the real behavior in.
     */
    public function joinPrimaryMailingList(): void
    {
        // TODO(stage 10 — mailing lists): attach $this to the primary
        // mailing list here once MAILING_MEMBERSHIP exists (FR-1.17).
    }
}
