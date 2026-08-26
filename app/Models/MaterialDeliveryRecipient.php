<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build-plan 10 — MATERIAL_DELIVERY_RECIPIENT. A per-send snapshot only ever
 * created through MaterialDelivery::sendFor() — never re-derived live from
 * contacts.is_primary (FR-2.12/FR-2.13/FR-5.5, the exact same convention as
 * DocumentRecipient). contact_id is nullable: an ad-hoc recipient added for
 * one send has no contact row at all.
 */
#[Fillable(['material_delivery_id', 'contact_id', 'recipient_name', 'recipient_email'])]
class MaterialDeliveryRecipient extends Model
{
    use HasFactory;

    public function materialDelivery(): BelongsTo
    {
        return $this->belongsTo(MaterialDelivery::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
