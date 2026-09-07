<?php

use App\Models\Document;
use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use Livewire\Attributes\Layout;
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
 * A field the customer's card (or a prior submission) already has a value
 * for is shown read-only, not reopened for editing — only genuinely empty
 * fields are editable. $readonlyFields carries that per-field, computed
 * once in mount(): a linked field is read-only when DocumentLinkedFields
 * already resolves a value from the live business record; any field
 * (linked or free-text) is read-only when field_values already has a
 * non-empty value from an earlier submission (e.g. staff filled it
 * manually in ⚡document-view.blade.php before sending).
 */
new
#[Layout('layouts.public', ['title' => 'אישור מסמך — כפיים'])]
class extends Component
{
    public Document $document;

    /** document_template_field_id => string value — includes read-only fields' values too, so confirm() still records them. */
    public array $fieldValues = [];

    /** document_template_field_id => bool. */
    public array $readonlyFields = [];

    public ?string $error = null;

    public function mount(Document $document): void
    {
        abort_if(in_array($document->document_type, ['invoice', 'credit_note'], true), 404);

        $this->document = $document->load(['deal.customer.school', 'documentTemplate.fields', 'businessEntity', 'lines']);
        $this->document->markViewed();

        $stored = collect($this->document->field_values ?? []);

        foreach ($this->document->documentTemplate->fields as $field) {
            $storedValue = trim((string) ($stored[$field->id]['value'] ?? ''));

            $linkedValue = $field->field_type === 'linked' && $field->linked_field
                ? trim((string) (DocumentLinkedFields::resolve($field->linked_field, $this->document->deal) ?? ''))
                : '';

            $existingValue = $storedValue !== '' ? $storedValue : $linkedValue;

            $this->fieldValues[$field->id] = $existingValue;
            $this->readonlyFields[$field->id] = $existingValue !== '';
        }
    }

    public function confirm(ActivityLogger $activityLogger): void
    {
        $this->error = null;

        try {
            $this->document->confirm($this->fieldValues, $activityLogger);
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

    <div class="content">{!! $document->rendered_content !!}</div>

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

        @if ($document->documentTemplate->fields->isNotEmpty())
            @foreach ($document->documentTemplate->fields as $field)
                <div class="field">
                    <label>{{ $field->name }} @if ($field->is_required)<span class="required">*</span>@endif</label>
                    @if ($readonlyFields[$field->id] ?? false)
                        <div class="field-readonly">{{ $fieldValues[$field->id] }}</div>
                    @else
                        <input type="text" wire:model="fieldValues.{{ $field->id }}">
                    @endif
                </div>
            @endforeach
        @endif

        <button type="button" wire:click="confirm" class="btn-confirm">אישור וחתימה</button>
    @endif
</div>
