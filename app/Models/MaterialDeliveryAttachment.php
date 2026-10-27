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
#[Fillable(['material_delivery_id', 'file_reference', 'file_name', 'url'])]
class MaterialDeliveryAttachment extends Model
{
    use HasFactory;

    /** Upload limits for an attached file (materials and mailing-list emails) — 95MB: Cloudflare (in front of the site) refuses an upload over 100MB; bigger videos go as a link instead. */
    public const FILE_RULES = ['file', 'max:97280', 'extensions:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,gif,mp4,mov,mp3'];

    public function materialDelivery(): BelongsTo
    {
        return $this->belongsTo(MaterialDelivery::class);
    }

    /** A link sent as-is (e.g. a video on YouTube/Drive) rather than a file stored here. */
    public function isLink(): bool
    {
        return filled($this->url);
    }

    /**
     * What the email links to: the link itself, or a 14-day signed link a
     * recipient (not logged into this app) can download the file from — see
     * routes/web.php.
     */
    /** How long an emailed download link works. */
    public const LINK_DAYS = 14;

    public function downloadUrl(): string
    {
        return $this->url ?: URL::temporarySignedRoute('materials.attachment.download', now()->addDays(self::LINK_DAYS), ['attachment' => $this->id]);
    }
}
