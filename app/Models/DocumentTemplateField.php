<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mirrors EmailTemplateField exactly. field_type is 'free_text' or 'linked'
 * (FR-4.10); linked_field is one of App\Services\DocumentLinkedFields::OPTIONS.
 */
#[Fillable(['document_template_id', 'name', 'field_type', 'linked_field', 'is_required', 'sort_order'])]
class DocumentTemplateField extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    /**
     * The token DocumentTemplate::renderContent() substitutes in the
     * template body, e.g. field "שם בית הספר" -> "{{שם_בית_הספר}}" (matches
     * docs/storyboard/document-templates.html's merge-tag convention).
     */
    public function placeholderToken(): string
    {
        return '{{'.str_replace(' ', '_', $this->name).'}}';
    }
}
