<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Build-plan 10/12 — MATERIAL_DELIVERY_ATTACHMENT. file_reference is the
 * file's real path on the 'local' disk (storage/app/private) — Smove's real
 * API (build-plan 12) has no attachment-upload endpoint, so instead of being
 * forwarded and discarded, the file is now kept permanently and a signed,
 * expiring download link is embedded in the Smove email body instead (see
 * downloadUrl() below and MaterialDelivery::sendFor()).
 */
#[Fillable(['material_delivery_id', 'file_reference', 'file_name'])]
class MaterialDeliveryAttachment extends Model
{
    use HasFactory;

    public function materialDelivery(): BelongsTo
    {
        return $this->belongsTo(MaterialDelivery::class);
    }

    /** A 14-day signed link a recipient (not logged into this app) can download the file from — see routes/web.php. */
    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute('materials.attachment.download', now()->addDays(14), ['attachment' => $this->id]);
    }
}
