<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Build-plan 07 — DOCUMENT_TEMPLATE (US-015). Like EmailTemplate, a template
 * is never deleted — only deactivated via is_active. FR-4.14: editing or
 * deactivating a template must affect future generations only; it never
 * reaches back into documents already generated from it, because
 * Document::generateFor() snapshots renderContent()'s output (and the field
 * values used) onto the DOCUMENT row at generation time. This model itself
 * stays a pure, stateless renderer so calling it again later for a preview
 * is always safe.
 */
#[Fillable(['document_type', 'name', 'content', 'is_active'])]
class DocumentTemplate extends Model
{
    use HasFactory;

    public const TYPES = [
        'quote' => 'הצעת מחיר',
        'order_form' => 'טופס הזמנה',
        'contract' => 'חוזה',
        'invoice' => 'חשבונית',
        'credit_note' => 'חשבונית זיכוי',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentTemplateField::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Substitutes each field's {{placeholder}} token in the template body.
     * $valuesByFieldId is keyed by document_template_field_id => string
     * value; a field with no supplied value falls back to a bracketed
     * "[field name]" placeholder, which is exactly what the template-manager
     * preview screen wants to show before any real document exists.
     */
    public function renderContent(array $valuesByFieldId = []): string
    {
        $content = (string) $this->content;

        foreach ($this->fields as $field) {
            $value = $valuesByFieldId[$field->id] ?? null;
            $content = str_replace(
                $field->placeholderToken(),
                $value !== null && $value !== '' ? (string) $value : "[{$field->name}]",
                $content,
            );
        }

        return $content;
    }
}
