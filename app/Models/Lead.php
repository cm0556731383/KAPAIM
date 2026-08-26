<?php

namespace App\Models;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

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

    /**
     * FR-1.15/FR-2.3: a lead converts to at most one customer — see
     * convertToCustomer() below.
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
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
     * Build-plan 10 (FR-1.17/FR-5.21): a brand-new lead joins the primary
     * mailing list immediately — before any Customer exists at all, hence
     * MailingMembership::addLead() rather than addCustomer() (see that
     * table's migration docblock for why lead_id exists). Once this lead
     * converts, convertToCustomer() below backfills this same row with
     * customer_id so it becomes a real "customer joins mailing list" row.
     *
     * TODO(stage 12 — Smove): this membership should also be pushed to
     * Smove — for now it is 100% real and local only, same stub boundary as
     * ExternalIntegrationSetting elsewhere in this codebase.
     */
    public function joinPrimaryMailingList(): void
    {
        MailingMembership::addLead(MailingList::primaryList(), $this);
    }

    /**
     * Build-plan 05: the *only* place a Customer row is ever created
     * (FR-2.3, FR-8.2 — there is no "create customer" UI). A lead converts
     * at most once (FR-1.15/FR-2.3/FR-8.2): both enforced here and backed by
     * the unique `lead_id` column on `customers`. Conversion never deletes
     * or duplicates the lead — its full activity history (interactions,
     * follow-ups, activity_logs) stays reachable via Customer::lead()
     * (FR-1.14/FR-2.18).
     *
     * FR-2.2: a branch of an education network still gets its own separate
     * Customer here — this method only ever operates on $this lead's own
     * school, so each branch (its own School/Lead row) converts to its own
     * Customer regardless of centralized network billing.
     *
     * @throws RuntimeException on a business-rule violation (already
     *                          converted, or no school attached yet) — the
     *                          caller shows the message as a friendly error.
     */
    public function convertToCustomer(ActivityLogger $activityLogger): Customer
    {
        if ($this->customer()->exists()) {
            throw new RuntimeException('ליד זה כבר הומר ללקוחה — לא ניתן להמיר אותו ללקוחה פעם נוספת.');
        }

        if (! $this->school_id) {
            throw new RuntimeException('יש להשלים פרטי בית ספר עבור הליד לפני המרתו ללקוחה.');
        }

        $activeStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'customer', 'name' => Customer::DEFAULT_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $customer = Customer::create([
            'school_id' => $this->school_id,
            'lead_id' => $this->id,
            'status_id' => $activeStatus->id,
            'converted_at' => now(),
        ]);

        // FR-2.5..FR-2.13: contacts move conceptually to the customer —
        // backfill customer_id on the school's existing (non-deleted)
        // contacts without touching school_id.
        $contacts = Contact::where('school_id', $this->school_id)->get();
        $contacts->each(fn (Contact $contact) => $contact->update(['customer_id' => $customer->id]));

        // FR-2.8: a customer must always have at least one primary contact —
        // stage 4 never enforced that for a lead's school, so if none of the
        // backfilled contacts happens to be primary yet, promote the first.
        if ($contacts->isNotEmpty() && $contacts->where('is_primary', true)->isEmpty()) {
            $contacts->first()->update(['is_primary' => true]);
        }

        $this->update(['converted_at' => $customer->converted_at]);

        // Build-plan 10 (FR-5.21): the lead's own primary-list membership (if
        // any — see joinPrimaryMailingList() above) becomes the customer's
        // membership on conversion, same backfill spirit as the contacts
        // backfill above. A lead that never actually joined one (e.g.
        // created directly, bypassing ⚡leads.blade.php's createLead()) still
        // leaves the resulting customer on the primary list — every customer
        // belongs on it regardless of how its lead got there.
        $existingMembership = MailingMembership::where('mailing_list_id', MailingList::primaryList()->id)
            ->where('lead_id', $this->id)
            ->first();

        if ($existingMembership) {
            $existingMembership->update(['customer_id' => $customer->id]);
        } else {
            MailingMembership::addCustomer(MailingList::primaryList(), $customer);
        }

        $activityLogger->log('customer.created', "לקוחה נוצרה מהמרת ליד #{$this->id}: {$this->school->name}", [
            'lead_id' => $this->id, 'customer_id' => $customer->id, 'school_id' => $this->school_id,
        ]);
        $activityLogger->log('lead.converted', "ליד #{$this->id} הומר ללקוחה #{$customer->id}", [
            'lead_id' => $this->id, 'customer_id' => $customer->id,
        ]);

        return $customer;
    }
}
