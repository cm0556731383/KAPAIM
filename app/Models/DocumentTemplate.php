<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        // Build-plan 11 — Expense::attachInvoice()'s standalone document type.
        'expense_invoice' => 'חשבונית הוצאה',
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
     * The fields whose {{token}} actually appears in the template text. A
     * field row can outlive its token (the text was edited and the token
     * deleted, but the row stayed) — such a field has nowhere to show up in
     * the document, so the form must neither ask for it nor require it.
     */
    public function fieldsInContent(): Collection
    {
        return $this->fields->filter(fn (DocumentTemplateField $field) => str_contains((string) $this->content, $field->placeholderToken()))->values();
    }

    /**
     * Substitutes each field's {{placeholder}} token in the template body.
     * $valuesByFieldId is keyed by document_template_field_id => string
     * value; a field with no supplied value falls back to a bracketed
     * "[field name]" placeholder, which is exactly what the template-manager
     * preview screen wants to show before any real document exists.
     *
     * $printFriendly (Document::printFriendlyContent(), the PDF send) swaps
     * that fallback for a bare "________________" line instead — the token
     * already sits inside the template's own sentence ("בכתובת ____"), so
     * repeating the field name there would read twice. A bracket
     * makes sense on screen, but a printed/downloaded document has no
     * interactive field for the recipient to fill, so it gets a blank line
     * to write on by hand instead, right where the field actually sits in
     * the text (matching the online form's own field, not a generic notice).
     *
     * `content` (and therefore this method's output) is real HTML now —
     * authored by staff via ⚡document-templates.blade.php's rich-text
     * editor and rendered raw ({!! !!}) everywhere so bold/italic/underline/
     * font-size actually show up. $value, in contrast, is never trusted:
     * for a "digital form" document it can be text a customer typed into
     * the public sign form (⚡document-sign.blade.php, no auth at all) —
     * escaping it here, the one place every value gets substituted in, is
     * what stops a submitted "<script>..."/"<img onerror=...>" from
     * becoming stored XSS shown back on both the customer's own page and
     * staff's ⚡document-view.blade.php.
     */
    /**
     * The online sign form's version of the body: each field's token is
     * replaced by whatever HTML $renderField returns for it (an inline
     * input, in ⚡document-sign.blade.php), so the field is filled in right
     * where it sits in the sentence instead of in a separate list. The
     * callback owns escaping of anything it puts in that HTML.
     *
     * @param  callable(DocumentTemplateField): string  $renderField
     */
    public function renderFormContent(callable $renderField): string
    {
        $content = (string) $this->content;

        foreach ($this->fieldsInContent() as $field) {
            $content = str_replace($field->placeholderToken(), $renderField($field), $content);
        }

        return $content;
    }

    public function renderContent(array $valuesByFieldId = [], bool $printFriendly = false): string
    {
        $content = (string) $this->content;

        foreach ($this->fields as $field) {
            $value = $valuesByFieldId[$field->id] ?? null;
            $blank = $printFriendly ? str_repeat('_', 24) : '['.e($field->name).']';
            $content = str_replace(
                $field->placeholderToken(),
                $value !== null && $value !== '' ? e((string) $value) : $blank,
                $content,
            );
        }

        return $content;
    }
}
