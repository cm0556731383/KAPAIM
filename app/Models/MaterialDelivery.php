<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Build-plan 10 — MATERIAL_DELIVERY. sendFor() below is the only place a row
 * is ever created — every send, including a resend of the very same program
 * to the very same customer, is a brand-new row (FR-5.9), never an
 * update-in-place, the same "sole creation point" convention as
 * Document::generateFor()/Deal::createForCustomer().
 *
 * Status lifecycle (STATUS_DEFINITION scope 'material_delivery', seeded by
 * ReferenceDataSeeder): "טרם נפתח" -> "נפתח" -> "אושר שהתקבל" (FR-5.10-FR-5.15).
 * markOpened() exists for a future real Smove open-tracking webhook
 * (build-plan 12) to call — nothing in THIS stage calls it automatically,
 * since there is no real outbound email/tracking pixel yet (see this stage's
 * report). acknowledge() IS fully real today: it backs the public signed
 * /materials/{materialDelivery}/acknowledge route (FR-5.12/FR-5.13), is
 * idempotent, and logs to ACTIVITY_LOG (FR-5.15).
 *
 * A delivery is never deleted (matches every other business-record
 * convention here) — no soft-deletes column, no delete route.
 */
#[Fillable(['customer_id', 'program_id', 'status_id', 'sent_at', 'opened_at', 'acknowledged_at', 'handled_at'])]
class MaterialDelivery extends Model
{
    use HasFactory;

    public const STATUS_NOT_OPENED = 'טרם נפתח';

    public const STATUS_OPENED = 'נפתח';

    public const STATUS_ACKNOWLEDGED = 'אושר שהתקבל';

    private const BADGE_CLASSES = [
        'טרם נפתח' => 'badge-info',
        'נפתח' => 'badge-warning',
        'אושר שהתקבל' => 'badge-success',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MaterialDeliveryRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MaterialDeliveryAttachment::class);
    }

    public static function badgeClassForStatusName(?string $statusName): string
    {
        return self::BADGE_CLASSES[$statusName] ?? 'badge-neutral';
    }

    /**
     * FR-5.4: the customer's primary contacts with a non-empty email — the
     * caller (⚡customer-detail.blade.php) loads this into the send form as
     * the starting point, which the user may then freely add/remove for this
     * one send without ever touching contacts.is_primary (FR-5.5).
     */
    public static function defaultRecipients(Customer $customer): Collection
    {
        return Contact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();
    }

    /**
     * FR-5.17: deliveries nobody has acted on yet — neither opened, nor
     * acknowledged, nor manually marked handled. Stage 15 (dashboard,
     * doesn't exist yet) consumes this scope directly for its "דורש טיפול"
     * widget; today it's exercised by markHandled() and the reminder job's
     * eligibility query below.
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereNull('opened_at')->whereNull('acknowledged_at')->whereNull('handled_at');
    }

    /**
     * FR-5.16: eligible for an automatic reminder — not opened/acknowledged,
     * and $hours have passed since it was sent. Used by
     * App\Console\Commands\ProcessMaterialReminders — deliberately excludes
     * handled_at from this one (a stale item dismissed by a user is done for
     * dashboard purposes, but the underlying "did the customer ever see it"
     * question that the reminder answers is independent of that dismissal).
     */
    public function scopeOverdueForReminder(Builder $query, int $hours): Builder
    {
        return $query->whereNull('opened_at')
            ->whereNull('acknowledged_at')
            ->where('sent_at', '<=', now()->subHours($hours));
    }

    /**
     * The only place a MaterialDelivery row is ever created (FR-5.1).
     * $recipients is an array of ['contact_id' => ?int, 'name' => string,
     * 'email' => ?string] rows (same shape as Document::sendTo(), see
     * MaterialDeliveryRecipient) — the caller is responsible for defaulting
     * it via defaultRecipients() above when the user hasn't overridden it.
     * $attachments is an array of ['file_reference' => string, 'file_name' =>
     * string] rows (FR-5.6/FR-5.20) — file_reference is the file's real path
     * on the 'local' disk; the caller is responsible for having already
     * stored it there (Smove's real API has no attachment upload, so the
     * file stays here permanently and a signed link is emailed instead —
     * see MaterialDeliveryAttachment::downloadUrl()).
     *
     * FR-5.8: best-effort deal association — looked up by customer+program
     * and logged onto the ACTIVITY_LOG entry, never blocking the send when no
     * matching deal exists (e.g. a subscription-program delivery has no deal
     * of its own — the deal is the subscription's, for a different program
     * each month).
     *
     * @param  array<int, array{contact_id: ?int, name: string, email: ?string}>  $recipients
     * @param  array<int, array{file_reference: string, file_name: string}>  $attachments
     *
     * Build-plan 12: the actual outbound Smove send now happens here too,
     * via $runner/$smove — non-blocking (see ExternalOperationRunner's
     * docblock): the delivery row above it is created and returned
     * regardless of whether Smove succeeds, since staff can always resend.
     *
     * @throws RuntimeException when no recipient has a valid email
     *                          (FR-5.2/FR-8.13), or no attachment is given
     *                          (FR-5.3/FR-8.14).
     */
    public static function sendFor(
        Customer $customer,
        Program $program,
        array $recipients,
        array $attachments,
        ActivityLogger $activityLogger,
        ExternalOperationRunner $runner,
        SmoveClient $smove,
    ): self {
        $validRecipients = array_values(array_filter(
            $recipients,
            fn (array $r) => ! empty($r['email']) && filter_var($r['email'], FILTER_VALIDATE_EMAIL),
        ));

        if (empty($validRecipients)) {
            throw new RuntimeException('יש לבחור לפחות איש קשר אחד עם כתובת דוא"ל תקינה לפני שליחת חומרי הלימוד.');
        }

        if (empty($attachments)) {
            throw new RuntimeException('יש לצרף לפחות קובץ אחד לפני שליחת חומרי הלימוד.');
        }

        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'material_delivery', 'name' => self::STATUS_NOT_OPENED],
            ['is_active' => true, 'sort_order' => 1],
        );

        $delivery = self::create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'status_id' => $status->id,
            'sent_at' => now(),
        ]);

        foreach ($validRecipients as $recipient) {
            $delivery->recipients()->create([
                'contact_id' => $recipient['contact_id'] ?? null,
                'recipient_name' => $recipient['name'],
                'recipient_email' => $recipient['email'],
            ]);
        }

        $attachmentModels = array_map(
            fn (array $attachment) => $delivery->attachments()->create([
                'file_reference' => $attachment['file_reference'],
                'file_name' => $attachment['file_name'],
            ]),
            $attachments,
        );

        // FR-5.8: best-effort — attach the relevant deal's id if one exists
        // for this exact customer+program, but never require it.
        $deal = Deal::where('customer_id', $customer->id)->where('program_id', $program->id)->latest('id')->first();

        $activityLogger->log(
            'material_delivery.sent',
            "נשלחו חומרי לימוד \"{$program->name}\" ללקוחה \"{$customer->school?->name}\" ({$delivery->recipients()->count()} נמענים)",
            [
                'customer_id' => $customer->id,
                'deal_id' => $deal?->id,
                'material_delivery_id' => $delivery->id,
                'metadata' => [
                    'program_id' => $program->id,
                    'recipient_count' => count($validRecipients),
                    'attachment_count' => count($attachments),
                ],
            ],
        );

        $runner->run(
            'smove',
            'materials_send',
            'app_action',
            fn () => $smove->sendMaterialsEmail(
                array_map(fn (array $r) => ['email' => $r['email'], 'name' => $r['name']], $validRecipients),
                array_map(fn (MaterialDeliveryAttachment $a) => ['name' => $a->file_name, 'url' => $a->downloadUrl()], $attachmentModels),
                "חומרי לימוד: {$program->name}",
            ),
            [
                'material_delivery_id' => $delivery->id,
                'customer_id' => $customer->id,
                'description' => "שליחת חומרי לימוד \"{$program->name}\" ללקוחה \"{$customer->school?->name}\"",
            ],
        );

        return $delivery->refresh();
    }

    /**
     * FR-5.10/FR-5.11: a real email open requires an actual outbound Smove
     * send (now real, see sendFor() above) plus Smove's own tracking
     * pixel/webhook telling us about it — see the
     * /webhooks/smove/material-opened route (routes/web.php), the only
     * caller. Idempotent — opening twice, or opening an already-acknowledged
     * delivery, is a no-op.
     */
    public function markOpened(): void
    {
        if ($this->opened_at !== null || $this->acknowledged_at !== null) {
            return;
        }

        $openedStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'material_delivery', 'name' => self::STATUS_OPENED],
            ['is_active' => true, 'sort_order' => 2],
        );

        $this->update(['opened_at' => now(), 'status_id' => $openedStatus->id]);
    }

    /**
     * FR-5.12/FR-5.13/FR-5.15: backs the public signed
     * /materials/{materialDelivery}/acknowledge route — our own app's link,
     * not Smove's, so this is fully real today (unlike markOpened() above).
     * Idempotent: a second click (or a slow double page-load) never
     * re-stamps acknowledged_at or double-logs.
     */
    public function acknowledge(ActivityLogger $activityLogger): void
    {
        if ($this->acknowledged_at !== null) {
            return;
        }

        $ackStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'material_delivery', 'name' => self::STATUS_ACKNOWLEDGED],
            ['is_active' => true, 'sort_order' => 3],
        );

        $this->update([
            'acknowledged_at' => now(),
            'opened_at' => $this->opened_at ?? now(),
            'status_id' => $ackStatus->id,
        ]);

        $activityLogger->log(
            'material_delivery.acknowledged',
            "הלקוחה אישרה קבלת חומרי לימוד — משלוח #{$this->id} (\"{$this->program?->name}\")",
            [
                'customer_id' => $this->customer_id,
                'material_delivery_id' => $this->id,
                'user' => null,
            ],
        );
    }

    /**
     * FR-5.17's manual half: dismisses a stale "needs attention" item even
     * with no dashboard yet (stage 15) to do it from — see
     * scopeNeedingAttention() above.
     */
    public function markHandled(): void
    {
        $this->update(['handled_at' => now()]);
    }
}
