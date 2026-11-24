<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * One file in a deal's folder (2026-11-24) — a PDF or an image uploaded on
 * the deal page, viewed in the browser or downloaded through the
 * 'deals.files.show' route. Added only by attachTo(), removed by remove().
 */
#[Fillable(['deal_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'])]
class DealFile extends Model
{
    /** Where deal files live on the private 'local' disk. */
    public const DIRECTORY = 'deal-files';

    /** What may be uploaded: PDFs and images. */
    public const MIMES = 'pdf,jpg,jpeg,png,webp,gif,heic,heif';

    /** Max size per file, in KB (10MB). */
    public const MAX_KB = 10240;

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Stores $file in $deal's folder. */
    public static function attachTo(Deal $deal, UploadedFile $file, ?User $user = null): self
    {
        return $deal->files()->create([
            'path' => $file->store(self::DIRECTORY."/{$deal->id}", 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $user?->id,
        ]);
    }

    /** Deletes the row and the stored file. */
    public function remove(): void
    {
        $path = $this->path;
        $this->delete();
        Storage::disk('local')->delete($path);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /** "1.2MB" / "340KB". */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? round($this->size / 1048576, 1).'MB'
            : max(1, (int) round($this->size / 1024)).'KB';
    }
}
