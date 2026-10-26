<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One email sent to one or more mailing lists from ⚡mailing-lists — sent by App\Jobs\SendMailingListEmail. */
#[Fillable(['user_id', 'subject', 'content_type', 'body_html', 'recipient_count', 'status', 'error_message', 'sent_at'])]
class MailingListSend extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'בשליחה',
        self::STATUS_SENT => 'נשלח',
        self::STATUS_FAILED => 'נכשל',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function mailingLists(): BelongsToMany
    {
        return $this->belongsToMany(MailingList::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailingListSendAttachment::class);
    }

    /** Every address on any of the chosen lists, once. */
    public function recipients(): array
    {
        return MailingList::recipientsOf($this->mailingLists);
    }

    public function listNames(): string
    {
        return $this->mailingLists->map->displayName()->implode(', ');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
