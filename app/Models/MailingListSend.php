<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email sent to a mailing list from ⚡mailing-lists — sent by App\Jobs\SendMailingListEmail. */
#[Fillable(['mailing_list_id', 'user_id', 'subject', 'content_type', 'body_html', 'recipient_count', 'status', 'error_message', 'sent_at'])]
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

    public function mailingList(): BelongsTo
    {
        return $this->belongsTo(MailingList::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
