<?php

use App\Concerns\Notifies;
use App\Models\Contact;
use App\Models\Document;
use App\Models\ExternalOperation;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 07 — מסך מסמך בודד. A Document only ever exists via
 * Document::generateFor() (called from ⚡deal-detail.blade.php's "מסמכים"
 * section) — this component never creates one, only displays/progresses it:
 * filling the digital-form field values (FR-4.10-FR-4.13), editing invoice
 * lines before sending (FR-4.17-FR-4.19), marking an order form
 * received/a contract signed (the FR-4.3/FR-4.4 gate's prerequisites), and
 * sending (FR-4.2/FR-4.7/FR-4.8/FR-4.9).
 *
 * There is no external customer portal yet (that's stage 12's landing-page/
 * integration work) — the "digital form" here is filled in by staff on the
 * customer's behalf, recording what the order form/contract actually said.
 */
new
#[Layout('layouts.app', ['title' => 'מסמך — כפיים'])]
class extends Component
{
    use Notifies;

    public Document $document;

    /** document_template_field_id => string value, editable while the document hasn't been sent yet. */
    public array $fieldValues = [];

    public ?string $fieldsError = null;

    // ===== שורות חשבונית (DOCUMENT_LINE) =====
    public string $lineDescription = '';
    public string $lineQuantity = '1';
    public string $lineUnitPrice = '';
    public string $lineAmount = '';
    public ?string $lineError = null;

    // ===== נמענים (DOCUMENT_RECIPIENT) — FR-2.11-FR-2.13/FR-4.8/FR-4.9 =====
    /** @var array<int, array{contact_id: ?int, name: string, email: ?string}> */
    public array $workingRecipients = [];
    public string $newRecipientName = '';
    public string $newRecipientEmail = '';
    public ?string $sendError = null;

    public function mount(Document $document): void
    {
        abort_unless(auth()->user()->can('documents.manage'), 403);

        $this->document = $document->load(['deal.customer.school', 'documentTemplate.fields', 'businessEntity', 'status', 'lines', 'recipients']);

        foreach ($this->document->field_values ?? [] as $fieldId => $entry) {
            $this->fieldValues[$fieldId] = $entry['value'] ?? '';
        }

        if (! $this->document->sent_at) {
            $this->workingRecipients = $this->document->defaultRecipients()
                ->map(fn (Contact $c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])
                ->all();
        }
    }

    // ----- שדות טופס דיגיטלי -----

    public function saveFieldValues(): void
    {
        $this->fieldsError = null;

        try {
            $this->document->submitFieldValues($this->fieldValues);
        } catch (\RuntimeException $e) {
            $this->fieldsError = $e->getMessage();

            return;
        }

        $this->document->refresh();
    }

    /** FR-4.3/FR-8.7's prerequisite for a contract. */
    public function markReceived(ActivityLogger $activityLogger): void
    {
        $this->document->markReceived();

        $activityLogger->log('document.received', "טופס הזמנה עבור עסקה #{$this->document->deal_id} סומן כהתקבל", [
            'document_id' => $this->document->id, 'deal_id' => $this->document->deal_id,
        ]);
    }

    /** FR-4.4/FR-8.8's prerequisite for an invoice. */
    public function markSigned(ActivityLogger $activityLogger): void
    {
        $this->document->markSigned();

        $activityLogger->log('document.signed', "חוזה עבור עסקה #{$this->document->deal_id} סומן כנחתם", [
            'document_id' => $this->document->id, 'deal_id' => $this->document->deal_id,
        ]);
    }

    // ----- שורות חשבונית -----

    public function addLine(): void
    {
        $this->lineError = null;

        $data = $this->validate([
            'lineDescription' => ['required', 'string', 'max:255'],
            'lineQuantity' => ['required', 'numeric', 'gt:0'],
            'lineAmount' => ['required', 'numeric', 'gt:0'],
            'lineUnitPrice' => ['nullable', 'numeric'],
        ], [], ['lineDescription' => 'תיאור', 'lineAmount' => 'סכום']);

        try {
            $this->document->addLine(
                $data['lineDescription'],
                (float) $data['lineAmount'],
                (float) $data['lineQuantity'],
                $data['lineUnitPrice'] !== null && $data['lineUnitPrice'] !== '' ? (float) $data['lineUnitPrice'] : null,
            );
        } catch (\RuntimeException $e) {
            $this->lineError = $e->getMessage();

            return;
        }

        $this->reset(['lineDescription', 'lineUnitPrice', 'lineAmount']);
        $this->lineQuantity = '1';
        $this->document->refresh();
    }

    public function removeLine(int $lineId): void
    {
        $this->lineError = null;

        try {
            $this->document->removeLine($lineId);
        } catch (\RuntimeException $e) {
            $this->lineError = $e->getMessage();

            return;
        }

        $this->document->refresh();
    }

    // ----- נמענים -----

    public function addRecipient(): void
    {
        $data = $this->validate([
            'newRecipientName' => ['required', 'string', 'max:255'],
            'newRecipientEmail' => ['nullable', 'email', 'max:255'],
        ], [], ['newRecipientName' => 'שם הנמען']);

        $this->workingRecipients[] = ['contact_id' => null, 'name' => $data['newRecipientName'], 'email' => $data['newRecipientEmail'] ?: null];
        $this->reset(['newRecipientName', 'newRecipientEmail']);
    }

    public function removeRecipient(int $index): void
    {
        unset($this->workingRecipients[$index]);
        $this->workingRecipients = array_values($this->workingRecipients);
    }

    /**
     * FR-4.2: format (digital/pdf) is chosen at send time. FR-4.7: every
     * send is logged (inside Document::sendTo()). FR-2.12/FR-2.13: the
     * recipient rows created here are a permanent snapshot — never derived
     * live from contacts.is_primary again.
     */
    public function send(string $format, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->sendError = null;

        try {
            $operation = $this->document->sendTo($this->workingRecipients, $format, $activityLogger, $runner, $summit);
        } catch (\RuntimeException $e) {
            $this->sendError = $e->getMessage();

            return;
        }

        $this->document->refresh()->load('recipients');

        if ($operation?->status === ExternalOperation::STATUS_FAILED) {
            $this->notifyWarning('המסמך נשלח, אך ההפקה מול Summit נכשלה — ראו יומן פעילות (FR-8.16).');

            return;
        }

        $this->notifySuccess('המסמך נשלח בהצלחה.');
    }

    #[Computed]
    public function chain()
    {
        return $this->document->deal->documents()->with('status')->get();
    }
};
?>

<div>
    <a href="{{ route('deal-detail', $document->deal_id) }}" class="back-link" style="display:inline-block; color:var(--color-text-secondary); text-decoration:none; font-size:var(--fs-small); margin-bottom:var(--sp-md)">← חזרה לעסקה #{{ $document->deal_id }}</a>

    <div class="topbar">
        <div>
            <h1 style="margin-bottom:2px">{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }} — עסקה #{{ $document->deal_id }}</h1>
            <p style="color:var(--color-text-secondary); margin:0">{{ $document->deal->customer->school?->name }} · נוצר <span class="ltr-num">{{ $document->created_at->format('d/m/Y') }}</span></p>
        </div>
        <div style="display:flex; gap:var(--sp-sm); align-items:center">
            <span class="badge badge-neutral">{{ $document->status?->name }}</span>
            <a href="{{ route('document-print', $document) }}" target="_blank" class="btn btn-secondary">תצוגת הדפסה / PDF</a>
        </div>
    </div>

    <div class="doc-stepper">
        @foreach ($this->chain as $doc)
            <span class="doc-step {{ $doc->id === $document->id ? 'current' : 'done' }}">{{ \App\Models\Document::TYPE_LABELS[$doc->document_type] ?? $doc->document_type }}</span>
            @if (! $loop->last)<span class="doc-step-arrow">←</span>@endif
        @endforeach
    </div>

    <div class="cols2">
        <div>
            {{-- ===== תוכן המסמך (Snapshot) ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>תוכן המסמך</h3>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-6px">
                    תוכן קפוא בעת ההפקה — שינוי בתבנית אינו משפיע רטרואקטיבית על מסמך זה (FR-4.14).
                </p>
                <div style="white-space:pre-wrap; font-size:var(--fs-small); line-height:1.8">{{ $document->rendered_content }}</div>

                @if ($document->document_type === 'invoice' && $document->businessEntity)
                    <div class="field" style="margin-top:var(--sp-md)"><div class="k">עוסק פטור</div><div class="v">{{ $document->businessEntity->name }} — {{ $document->businessEntity->classification }}</div></div>
                @endif
            </div>

            {{-- ===== שדות הטופס הדיגיטלי ===== --}}
            @if ($document->documentTemplate->fields->isNotEmpty())
                <div class="card" style="margin-bottom:var(--sp-lg)">
                    <h3>שדות הטופס</h3>
                    <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-6px">
                        אין עדיין פורטל לקוחות חיצוני — השדות מתועדים כאן על ידי הצוות בהתאם למה שנמסר בפועל (FR-4.10-FR-4.12).
                    </p>
                    <x-business-error-banner :message="$fieldsError" />
                    <div class="form-grid">
                        @foreach ($document->documentTemplate->fields as $field)
                            <div class="full">
                                <label>{{ $field->name }} @if ($field->is_required)<span style="color:var(--color-error)">*</span>@endif @if ($field->field_type === 'linked')<span class="scope-pill" style="margin-inline-start:6px">מקושר</span>@endif</label>
                                <input type="text" wire:model="fieldValues.{{ $field->id }}">
                            </div>
                        @endforeach
                        <div class="full"><button type="button" wire:click="saveFieldValues" class="btn btn-primary">שמירת שדות</button></div>
                    </div>
                </div>
            @endif

            {{-- ===== שורות חשבונית ===== --}}
            @if ($document->document_type === 'invoice')
                <div class="card" style="margin-bottom:var(--sp-lg); padding:0; overflow:hidden">
                    <div style="padding: var(--sp-lg) var(--sp-lg) 0"><h3>פירוט</h3></div>
                    <x-business-error-banner :message="$lineError" style="margin:0 var(--sp-lg)" />
                    <table>
                        <thead><tr><th>תיאור</th><th>כמות</th><th>מחיר יחידה</th><th>סכום</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($document->lines as $line)
                                <tr>
                                    <td>{{ $line->description }}</td>
                                    <td class="ltr-num">{{ $line->quantity }}</td>
                                    <td class="ltr-num">₪{{ number_format((float) $line->unit_price, 0) }}</td>
                                    <td class="ltr-num">₪{{ number_format((float) $line->amount, 0) }}</td>
                                    <td>
                                        @unless ($document->sent_at)
                                            <button type="button" wire:click="removeLine({{ $line->id }})" class="btn btn-ghost btn-sm">הסרה</button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="total-row">
                                <td colspan="3">סה"כ לתשלום (FR-4.19)</td>
                                <td class="ltr-num">₪{{ number_format($document->totalAmount(), 0) }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                    @unless ($document->sent_at)
                        <div style="padding: var(--sp-md) var(--sp-lg)">
                            <form wire:submit="addLine" class="form-grid">
                                <div><label>תיאור</label><input type="text" wire:model="lineDescription"></div>
                                <div><label>כמות</label><input type="text" class="ltr-num" dir="ltr" wire:model="lineQuantity"></div>
                                <div><label>מחיר יחידה (אופציונלי)</label><input type="text" class="ltr-num" dir="ltr" wire:model="lineUnitPrice"></div>
                                <div><label>סכום שורה</label><input type="text" class="ltr-num" dir="ltr" wire:model="lineAmount"></div>
                                <div class="full"><button type="submit" class="btn btn-ghost">+ הוספת שורה</button></div>
                            </form>
                        </div>
                    @endunless
                </div>
            @endif

            {{-- ===== נמענים ושליחה ===== --}}
            <div class="card">
                <h3>נמענים</h3>
                <x-business-error-banner :message="$sendError" />

                @if ($document->sent_at)
                    <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-4px">נשלח בתאריך <span class="ltr-num">{{ $document->sent_at->format('d/m/Y H:i') }}</span> כ{{ $document->format === 'pdf' ? 'קובץ PDF' : 'טופס דיגיטלי' }}.</p>
                    <div class="recipients-row">
                        @foreach ($document->recipients as $recipient)
                            <span class="chip-recipient">{{ $recipient->recipient_name }}</span>
                        @endforeach
                    </div>
                @else
                    <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-4px">ברירת מחדל: אנשי קשר ראשיים · שינוי כאן משפיע רק על שליחה זו (FR-4.8, FR-4.9)</p>
                    <div class="recipients-row">
                        @forelse ($workingRecipients as $index => $recipient)
                            <span class="chip-recipient">{{ $recipient['name'] }}<button type="button" wire:click="removeRecipient({{ $index }})" aria-label="הסרה">×</button></span>
                        @empty
                            <span class="text-text-secondary" style="font-size:var(--fs-small)">אין נמענים — יש להוסיף לפחות נמען אחד.</span>
                        @endforelse
                    </div>
                    <div class="form-grid">
                        <div><input type="text" wire:model="newRecipientName" placeholder="שם הנמען"></div>
                        <div><input type="text" wire:model="newRecipientEmail" class="ltr-num" dir="ltr" placeholder="דוא״ל (אופציונלי)"></div>
                        <div class="full"><button type="button" wire:click="addRecipient" class="btn btn-ghost">+ הוספת נמען לשליחה זו</button></div>
                    </div>
                    <div style="display:flex; gap:var(--sp-sm); margin-top:var(--sp-lg)">
                        <button type="button" wire:click="send('digital')" class="btn btn-primary">שליחה כטופס דיגיטלי</button>
                        <button type="button" wire:click="send('pdf')" class="btn btn-secondary">שליחה כ-PDF</button>
                    </div>
                @endif
            </div>
        </div>

        <div>
            {{-- ===== סטטוס תהליך ===== --}}
            <div class="card">
                <h3>סטטוס תהליך</h3>
                <div class="field"><div class="k">נשלח</div><div class="v ltr-num">{{ $document->sent_at?->format('d/m/Y H:i') ?? '—' }}</div></div>
                <div class="field"><div class="k">התקבל</div><div class="v ltr-num">{{ $document->received_at?->format('d/m/Y H:i') ?? '—' }}</div></div>
                <div class="field"><div class="k">נחתם</div><div class="v ltr-num">{{ $document->signed_at?->format('d/m/Y H:i') ?? '—' }}</div></div>

                @if ($document->document_type === 'order_form' && ! $document->received_at)
                    <button type="button" wire:click="markReceived" class="btn btn-primary" style="margin-top:var(--sp-md)">סימון כטופס שהתקבל (FR-4.3)</button>
                @endif
                @if ($document->document_type === 'contract' && ! $document->signed_at)
                    <button type="button" wire:click="markSigned" class="btn btn-primary" style="margin-top:var(--sp-md)">סימון כחוזה שנחתם (FR-4.4)</button>
                @endif
            </div>
        </div>
    </div>
</div>
