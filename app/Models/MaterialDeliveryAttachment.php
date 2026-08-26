<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build-plan 10 — MATERIAL_DELIVERY_ATTACHMENT. Metadata only (FR-5.6/
 * FR-5.20) — file_reference is the original Livewire temporary-upload
 * token/identifier for audit purposes ONLY, never a working path. The actual
 * file bytes are forwarded to Smove at send time and then discarded — see
 * MaterialDelivery::sendFor() and this table's migration docblock.
 */
#[Fillable(['material_delivery_id', 'file_reference', 'file_name'])]
class MaterialDeliveryAttachment extends Model
{
    use HasFactory;

    public function materialDelivery(): BelongsTo
    {
        return $this->belongsTo(MaterialDelivery::class);
    }
}
