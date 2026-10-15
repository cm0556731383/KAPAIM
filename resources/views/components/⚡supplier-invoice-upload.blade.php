<?php

use App\Models\Expense;
use App\Services\ActivityLogger;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The page a supplier reaches from a missing-invoice request email
 * (Expense::requestInvoice() → invoiceUploadUrl()). No login — protected by
 * the route's `signed` middleware with an expiring link, same as
 * ⚡document-sign.blade.php. Shows only what the supplier needs to identify
 * the payment (never the internal notes), takes one PDF/image, and attaches
 * it via Expense::attachInvoice(). Once an invoice is on file the page just
 * says so — a second upload is never accepted from here.
 */
new
#[Layout('layouts.public', ['title' => 'העלאת חשבונית — כפיים'])]
class extends Component
{
    use WithFileUploads;

    public Expense $expense;

    public $invoice = null;

    public function mount(Expense $expense): void
    {
        $this->expense = $expense->load(['supplier', 'document']);
    }

    public function save(ActivityLogger $activityLogger): void
    {
        $this->validate(
            ['invoice' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:10240']],
            [
                'invoice.required' => 'יש לבחור את קובץ החשבונית.',
                'invoice.mimes' => 'ניתן להעלות קובץ PDF או תמונה (JPG, PNG, WEBP, HEIC) בלבד.',
                'invoice.max' => 'הקובץ גדול מדי — עד 10MB.',
            ],
        );

        $this->expense->refresh();

        if ($this->expense->hasInvoiceFile()) {
            $this->reset('invoice');

            return;
        }

        $document = $this->expense->attachInvoice($this->invoice);

        $activityLogger->log('expense.invoice_uploaded_by_supplier', "הספק \"{$this->expense->supplier?->name}\" העלה חשבונית להוצאה #{$this->expense->id}", [
            'expense_id' => $this->expense->id,
            'document_id' => $document->id,
            'user' => null,
        ]);

        $this->reset('invoice');
        $this->expense->load('document');
    }
};
?>

<div>
    <style>
        .upload-box { display:block; border:2px dashed var(--color-border); border-radius:10px; padding:28px 16px; text-align:center; cursor:pointer; margin-bottom:16px; background:#FAFAF6; }
        .upload-box:hover { border-color: var(--color-primary); }
        .upload-box input { display:none; }
        .upload-box .hint { color: var(--color-text-secondary); font-size:14px; }
        .file-chosen { font-weight:600; color: var(--color-primary); word-break:break-all; }
        .btn-confirm:disabled { opacity:.5; cursor:not-allowed; }
        .field-error { color: var(--color-error); font-size:14px; margin:-8px 0 16px; }
    </style>

    <h1>העלאת חשבונית</h1>
    <p class="meta">{{ $expense->supplier?->name }}</p>

    <table>
        <tbody>
            <tr><th>תאריך התשלום</th><td><span class="ltr-num">{{ $expense->expense_date->format('d/m/Y') }}</span></td></tr>
            <tr><th>סכום</th><td><span class="ltr-num">₪{{ $expense->invoiceRequestData()['amount'] }}</span></td></tr>
            <tr><th>מספר הוצאה</th><td><span class="ltr-num">{{ $expense->id }}</span></td></tr>
        </tbody>
    </table>

    @if ($expense->hasInvoiceFile())
        <div class="confirmed-banner">
            החשבונית התקבלה{{ $expense->document?->received_at ? ' בתאריך '.$expense->document->received_at->format('d/m/Y') : '' }}. תודה!
        </div>
    @else
        <form wire:submit="save">
            <label class="upload-box">
                <input type="file" wire:model="invoice" accept="application/pdf,image/*">
                <div wire:loading.remove wire:target="invoice">
                    @if ($invoice)
                        <div class="file-chosen">{{ $invoice->getClientOriginalName() }}</div>
                        <div class="hint">לחצו כדי לבחור קובץ אחר</div>
                    @else
                        <div style="font-weight:600; margin-bottom:4px">בחירת קובץ החשבונית</div>
                        <div class="hint">PDF או תמונה, עד 10MB</div>
                    @endif
                </div>
                <div wire:loading wire:target="invoice" class="hint">טוען את הקובץ...</div>
            </label>
            @error('invoice') <div class="field-error">{{ $message }}</div> @enderror

            <button type="submit" class="btn-confirm" wire:loading.attr="disabled" wire:target="invoice,save" @disabled(! $invoice)>
                <span wire:loading.remove wire:target="save">שליחת החשבונית</span>
                <span wire:loading wire:target="save">שולח...</span>
            </button>
        </form>
    @endif
</div>
