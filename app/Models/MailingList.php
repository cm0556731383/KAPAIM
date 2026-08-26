<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Build-plan 10 — MAILING_LIST. Every list this app ever needs is obtained
 * through the factory methods below (all firstOrCreate()-backed) — nothing
 * anywhere else in the codebase should call MailingList::create() directly,
 * the same "sole creation point(s)" convention as Deal::createForCustomer()
 * etc. See MailingListsSeeder for pre-seeding one row per currently-catalog
 * program plus the three singleton lists.
 */
#[Fillable(['name', 'list_type', 'program_id', 'is_active'])]
class MailingList extends Model
{
    use HasFactory;

    public const TYPE_PRIMARY = 'primary';

    public const TYPE_PROGRAM = 'program';

    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_SUPPLIER = 'supplier';

    public const PRIMARY_LIST_NAME = 'רשימה ראשית';

    public const SUBSCRIBERS_LIST_NAME = 'מנויים';

    public const SUPPLIERS_LIST_NAME = 'ספקים';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(MailingMembership::class);
    }

    /** FR-1.17/FR-5.21: the singleton every lead/customer ends up on. */
    public static function primaryList(): self
    {
        return self::firstOrCreate(
            ['list_type' => self::TYPE_PRIMARY],
            ['name' => self::PRIMARY_LIST_NAME, 'is_active' => true],
        );
    }

    /** FR-5.23/FR-5.24: the singleton "subscribers" list. */
    public static function subscribersList(): self
    {
        return self::firstOrCreate(
            ['list_type' => self::TYPE_SUBSCRIPTION],
            ['name' => self::SUBSCRIBERS_LIST_NAME, 'is_active' => true],
        );
    }

    /**
     * FR-5.26: the singleton "ספקים" shell — seeded empty now
     * (MailingListsSeeder). Actually populating it happens in build-plan 11
     * once SUPPLIER exists; not this stage's job.
     */
    public static function suppliersList(): self
    {
        return self::firstOrCreate(
            ['list_type' => self::TYPE_SUPPLIER],
            ['name' => self::SUPPLIERS_LIST_NAME, 'is_active' => true],
        );
    }

    /** FR-5.22: one list per catalog PROGRAM, created lazily on first use. */
    public static function forProgram(Program $program): self
    {
        return self::firstOrCreate(
            ['list_type' => self::TYPE_PROGRAM, 'program_id' => $program->id],
            ['name' => $program->name, 'is_active' => true],
        );
    }

    /** Read-only ⚡mailing-lists.blade.php's member-count column. */
    public function activeMemberCount(): int
    {
        return $this->memberships()->where('membership_status', MailingMembership::STATUS_ACTIVE)->count();
    }
}
