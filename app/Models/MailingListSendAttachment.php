<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * A file or link attached to a mailing-list email (⚡mailing-lists) — the
 * same "kept here, linked from the email" approach as
 * MaterialDeliveryAttachment. file_reference is the file's path on the
 * 'local' disk.
 */
#[Fillable(['mailing_list_send_id', 'file_reference', 'file_name', 'url'])]
class MailingListSendAttachment extends Model
{
    public function mailingListSend(): BelongsTo
    {
        return $this->belongsTo(MailingListSend::class);
    }

    public function isLink(): bool
    {
        return filled($this->url);
    }

    /** The link itself, or a 30-day signed download link — a newsletter is often opened later than a materials email. */
    public function downloadUrl(): string
    {
        return $this->url ?: URL::temporarySignedRoute('mailing-lists.attachment.download', now()->addDays(30), ['attachment' => $this->id]);
    }
}
