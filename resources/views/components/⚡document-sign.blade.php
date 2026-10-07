<?php

use App\Models\Document;
use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Build-plan 12 follow-up — the online form embedded in a "digital"
 * document send (Document::signUrl(), routes.php's documents.sign). No
 * auth at all: the recipient opening this link isn't logged into the app
 * (protected instead by Laravel's `signed` middleware on the route, same
 * pattern as materials.acknowledge). Read-only content for every document
 * type; an input per DOCUMENT_TEMPLATE_FIELD when the template has any
 * (FR-4.10/FR-4.11); "אישור וחתימה" is a click-to-confirm, not a drawn
 * signature — see Document::confirm() for what it actually records.
 *
 * Only fields whose token appears in the template text are shown
 * (DocumentTemplate::fieldsInContent()), each as an inline input right
 * where its token sits in the text (formContent()) — there is no separate
 * list of fields under the document. Each is pre-filled from what the
 * document captured, or the customer card's live value
 * (Document::currentFieldValues()), and stays editable either way — a
 * corrected value is written back to the customer card on confirm
 * (DocumentLinkedFields::applyBack()). The exception is any linked value
 * that isn't one of the customer card's own editable details
 * (DocumentLinkedFields::isWritable() — the deal's terms, balances,
 * statuses, dates): those are shown as plain text, never editable by the
 * recipient, and never written back.
 */
new
#[Layout('layouts.public', ['title' => 'אישור מסמך — כפיים'])]
class extends Component
{
    public Document $document;

    /** document_template_field_id => string value — includes read-only fields' values too, so confirm() still records them. */
    public array $fieldValues = [];

    /** document_template_field_id => bool — true for filled, non-writable linked values (see class docblock). */
    #[Locked]
    public array $readonlyFields = [];

    public ?string $error = null;

    public function mount(Document $document): void
    {
        abort_if(in_array($document->document_type, ['invoice', 'credit_note'], true), 404);

        $this->document = $document->load(['deal.customer.school', 'documentTemplate.fields', 'businessEntity', 'lines']);
        $this->document->markViewed();

        $this->fieldValues = $this->document->currentFieldValues();

        foreach ($this->document->documentTemplate->fieldsInContent() as $field) {
            $this->readonlyFields[$field->id] = $field->field_type === 'linked'
                && $field->linked_field
                && ! DocumentLinkedFields::isWritable($field->linked_field)
                && $this->fieldValues[$field->id] !== '';
        }
    }

    /**
     * The template body with every field's token swapped for an inline
     * input bound to fieldValues.{id} (or, for the deal's read-only terms,
     * just the value as text). Built from the template's own content, the
     * same source submitFieldValues() re-renders from on confirm.
     */
    public function formContent(): string
    {
        return $this->document->documentTemplate->renderFormContent(function ($field) {
            $value = (string) ($this->fieldValues[$field->id] ?? '');

            if ($this->readonlyFields[$field->id] ?? false) {
                return '<strong>'.e($value).'</strong>';
            }

            // A blank line only — no visible field name. The single-space
            // placeholder is invisible but keeps :placeholder-shown working
            // for the required-field styling; the name stays available to
            // screen readers / on hover via aria-label and title.
            $label = $field->name.($field->is_required ? ' *' : '');
            $minSize = 14;

            return sprintf(
                '<input type="text" class="inline-field%s" wire:model="fieldValues.%d" placeholder=" " aria-label="%s" title="%s" size="%d" oninput="this.size = Math.max(this.value.length + 2, %d)">',
                $field->is_required ? ' is-required' : '',
                $field->id,
                e($label),
                e($label),
                max(mb_strlen($value) + 2, $minSize),
                $minSize,
            );
        });
    }

    public function confirm(ActivityLogger $activityLogger): void
    {
        $this->error = null;

        // The read-only values are only rendered as text client-side — re-pin
        // them here so a crafted request can't alter the deal's terms.
        $current = $this->document->currentFieldValues();
        $values = $this->fieldValues;

        foreach (array_keys(array_filter($this->readonlyFields)) as $fieldId) {
            $values[$fieldId] = $current[$fieldId] ?? '';
        }

        try {
            $this->document->confirm($values, $activityLogger);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->document->refresh();
    }
};
?>

<div>
    <h1>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }}</h1>
    <p class="meta">
        {{ $document->deal->customer->school?->name }}
        @if ($document->businessEntity) · עוסק: {{ $document->businessEntity->name }} ({{ $document->businessEntity->classification }}) @endif
    </p>

    {{-- Before confirmation the fields are filled in inline, inside the text itself; afterwards the stored, filled-in content is shown. --}}
    <div class="content">{!! $document->confirmed_at ? $document->rendered_content : $this->formContent() !!}</div>

    @if ($document->document_type === 'invoice')
        <table>
            <thead><tr><th>תיאור</th><th>כמות</th><th>מחיר יחידה</th><th>סכום</th></tr></thead>
            <tbody>
                @foreach ($document->lines as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="ltr-num">{{ $line->quantity }}</td>
                        <td class="ltr-num">₪{{ number_format((float) $line->unit_price, 0) }}</td>
                        <td class="ltr-num">₪{{ number_format((float) $line->amount, 0) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3">סה"כ לתשלום</td>
                    <td class="ltr-num">₪{{ number_format($document->totalAmount(), 0) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if ($document->confirmed_at)
        <div class="confirmed-banner">
            המסמך אושר בתאריך {{ $document->confirmed_at->format('d/m/Y H:i') }}. תודה!
        </div>
    @else
        @if ($error)
            <div class="error-banner">{{ $error }}</div>
        @endif

        <button type="button" wire:click="confirm" class="btn-confirm">אישור וחתימה</button>
    @endif
</div>
