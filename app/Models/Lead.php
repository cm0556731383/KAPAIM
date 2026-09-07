<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
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
     * Build-plan 12: MailingMembership::addLead() below now also pushes this
     * membership to Smove for real — nothing to change here.
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
     * @param  ?\DateTimeInterface  $convertedAt  Build-plan 18 (FR-8.21): lets the
     *                                            legacy-data importer backdate an
     *                                            already-converted historical
     *                                            customer's conversion moment
     *                                            (also backdates the two activity-log
     *                                            entries below via occurred_at) —
     *                                            every other caller omits this and
     *                                            gets "now", exactly as before.
     *
     * @throws RuntimeException on a business-rule violation (already
     *                          converted, or no school attached yet) — the
     *                          caller shows the message as a friendly error.
     */
    public function convertToCustomer(ActivityLogger $activityLogger, ?\DateTimeInterface $convertedAt = null): Customer
    {
        if ($this->customer()->exists()) {
            throw new RuntimeException('ליד זה כבר הומר ללקוחה — לא ניתן להמיר אותו ללקוחה פעם נוספת.');
        }

        if (! $this->school_id) {
            throw new RuntimeException('יש להשלים פרטי בית ספר עבור הליד לפני המרתו ללקוחה.');
        }

        $convertedAt ??= now();

        $activeStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'customer', 'name' => Customer::DEFAULT_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $customer = Customer::create([
            'school_id' => $this->school_id,
            'lead_id' => $this->id,
            'status_id' => $activeStatus->id,
            'converted_at' => $convertedAt,
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
            'lead_id' => $this->id, 'customer_id' => $customer->id, 'school_id' => $this->school_id, 'occurred_at' => $convertedAt,
        ]);
        $activityLogger->log('lead.converted', "ליד #{$this->id} הומר ללקוחה #{$customer->id}", [
            'lead_id' => $this->id, 'customer_id' => $customer->id, 'occurred_at' => $convertedAt,
        ]);

        return $customer;
    }

    /**
     * Build-plan 12 — FR-1.18: the sole entry point for
     * /webhooks/landing-page/lead (routes/web.php), the only caller. Mirrors
     * ⚡leads.blade.php's addLead() dedup shape (FR-1.9) exactly, except: an
     * exact-duplicate school never blocks here (there's no staff member to
     * show an error to) — it logs the repeat inquiry against the existing
     * lead and returns that lead, still sending the confirmation email
     * below; a fuzzy-duplicate warning is skipped entirely (nobody to show
     * it to either). FR-7.5: auto-assigns a sales rep when one exists
     * (User::pickForAutoAssignment()) — leaving the lead unassigned when
     * none does is this build-plan's own documented valid state.
     *
     * @param  array{contact_name: string, school_name: ?string, email: string, phone: string, school_phone: ?string}  $payload
     */
    public static function createFromLandingPage(array $payload, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): self
    {
        $schoolName = trim((string) ($payload['school_name'] ?? ''));
        $schoolPhone = $payload['school_phone'] ?? null;
        $school = null;

        if ($schoolName !== '') {
            $exactSchool = self::findExactDuplicateSchool($schoolName, $schoolPhone ?? $payload['phone']);

            if ($exactSchool) {
                $existingLead = self::where('school_id', $exactSchool->id)->latest()->first();

                if ($existingLead) {
                    $activityLogger->log('lead.repeat_inquiry', "פנייה חוזרת מדף הנחיתה עבור \"{$exactSchool->name}\" נרשמה על ליד קיים #{$existingLead->id}", [
                        'lead_id' => $existingLead->id,
                        'school_id' => $exactSchool->id,
                        'user' => null,
                    ]);

                    self::sendLandingPageConfirmation($payload, $existingLead, $activityLogger, $runner, $smove);

                    return $existingLead;
                }

                $school = $exactSchool;
            } else {
                $school = School::create(['name' => $schoolName, 'phone' => $schoolPhone]);
            }
        }

        $source = LeadSource::firstOrCreate(['name' => 'דף נחיתה'], ['is_active' => true]);
        $newStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => self::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $lead = self::create([
            'school_id' => $school?->id,
            'lead_source_id' => $source->id,
            'status_id' => $newStatus->id,
            'email' => $payload['email'],
            'phone' => $payload['phone'],
        ]);

        $lead->joinPrimaryMailingList();

        $salesRep = User::pickForAutoAssignment();

        if ($salesRep) {
            $lead->update(['assigned_user_id' => $salesRep->id]);
        }

        $activityLogger->log('lead.created', 'נוצר ליד חדש מדף הנחיתה: '.($school?->name ?? $lead->email), [
            'lead_id' => $lead->id,
            'school_id' => $school?->id,
            'user' => null,
            'metadata' => ['auto_assigned_user_id' => $salesRep?->id],
        ]);

        self::sendLandingPageConfirmation($payload, $lead, $activityLogger, $runner, $smove);

        return $lead;
    }

    /**
     * FR-1.18's "מייל אישור אוטומטי" half — every business email goes
     * through Smove, never Laravel Mail (the business owner's explicit
     * instruction, 2026-09-03), via the same ExternalOperationRunner pattern
     * as every other Smove call in this codebase (never blocks lead
     * creation; success/failure is recorded to EXTERNAL_OPERATION +
     * ACTIVITY_LOG automatically).
     */
    private static function sendLandingPageConfirmation(array $payload, self $lead, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        $template = EmailTemplate::where('template_type', 'lead_confirmation')->where('is_active', true)->first();

        if (! $template) {
            $activityLogger->log('lead.confirmation_email_skipped', "לא נשלח מייל אישור לליד #{$lead->id} — לא נמצאה תבנית 'lead_confirmation' פעילה", [
                'lead_id' => $lead->id, 'user' => null,
            ]);

            return;
        }

        $rendered = $template->render([
            'contact_name' => $payload['contact_name'] ?? $payload['email'],
            'school_name' => $payload['school_name'] ?? '',
        ]);

        $runner->run(
            'smove',
            'lead_confirmation',
            'app_action',
            fn () => $smove->sendTransactionalEmail(
                $payload['email'],
                $payload['contact_name'] ?? $payload['email'],
                $rendered['subject'],
                $rendered['content'],
            ),
            [
                'lead_id' => $lead->id,
                'user' => null,
                'description' => "מייל אישור אוטומטי לליד #{$lead->id}",
            ],
        );
    }
}
