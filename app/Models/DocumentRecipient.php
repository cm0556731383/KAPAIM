<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build-plan 07 — DOCUMENT_RECIPIENT. A per-send snapshot only ever created
 * through Document::sendTo() — never re-derived live from contacts.is_primary
 * (FR-2.12/FR-2.13/FR-4.9). contact_id is nullable: an ad-hoc recipient added
 * for one send has no contact row at all.
 */
#[Fillable(['document_id', 'contact_id', 'recipient_name', 'recipient_email', 'sent_at'])]
class DocumentRecipient extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
