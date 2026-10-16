<?php

use App\Concerns\Notifies;
use App\Models\DocumentTemplate;
use App\Models\EmailTemplate;
use App\Models\DocumentTemplateField;
use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 07 (US-015) — תבניות מסמכים. Mirrors ⚡settings.blade.php's
 * email-template-field manager pattern. FR-4.14/US-015's closing line: a
 * template is never deleted, only deactivated (is_active) — same convention
 * as programs/bundles/email templates — because DocumentTemplate itself
 * stays a pure renderer and every already-generated Document snapshots its
 * own rendered_content/field_values at generation time (see
 * Document::generateFor()), so editing a template here can never reach back
 * into a document that already exists.
 */
new
#[Layout('layouts.app', ['title' => 'תבניות מסמכים — כפיים'])]
class extends Component
{
    use Notifies;

    public ?int $editingTemplateId = null;

    public string $templateName = '';
    public string $templateDocumentType = 'quote';
    public string $templateContent = '';

    /**
     * The content editor is wire:ignore'd so a re-render never overwrites
     * it while the user is typing (that reset the cursor and made the text
     * jump). Bumped whenever a different template is loaded/cleared, which
     * changes the editor's wire:key so Livewire does replace it then.
     */
    public int $editorVersion = 0;

    /** The "quick insert" search box below the content editor — filters App\Services\DocumentLinkedFields::pickerOptions(). */
    public string $linkedFieldSearch = '';

    public ?int $previewTemplateId = null;

    /** The supplier missing-invoice email (EmailTemplate::SUPPLIER_INVOICE_REQUEST) — see saveInvoiceRequestTemplate(). */
    public string $invoiceRequestSubject = '';
    public string $invoiceRequestContent = '';

    /** The several-invoices supplier email (EmailTemplate::SUPPLIER_INVOICES_REQUEST) — see saveBulkRequestTemplate(). */
    public string $bulkRequestSubject = '';
    public string $bulkRequestContent = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('document-templates.view'), 403);

        $template = EmailTemplate::supplierInvoiceRequest();
        $this->invoiceRequestSubject = (string) $template?->subject;
        $this->invoiceRequestContent = (string) $template?->content;

        $bulk = EmailTemplate::supplierInvoicesRequest();
        $this->bulkRequestSubject = (string) $bulk?->subject;
        $this->bulkRequestContent = (string) $bulk?->content;
    }

    /**
     * The wording of the email the expenses page's "בקשה מהספק במייל" sends
     * (Expense::requestInvoice()) — {{...}} fields are filled from that
     * expense row; EmailTemplate::UPLOAD_BUTTON_TOKEN marks where the upload
     * button goes.
     */
    public function saveInvoiceRequestTemplate(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'invoiceRequestSubject' => ['required', 'string', 'max:255'],
            'invoiceRequestContent' => ['required', 'string'],
        ], [], ['invoiceRequestSubject' => 'נושא המייל', 'invoiceRequestContent' => 'גוף המייל']);

        $template = EmailTemplate::updateOrCreate(
            ['template_type' => EmailTemplate::SUPPLIER_INVOICE_REQUEST],
            ['name' => 'בקשת חשבונית מספק', 'subject' => $data['invoiceRequestSubject'], 'content' => $data['invoiceRequestContent'], 'is_active' => true],
        );

        $activityLogger->log('email_template.updated', "עודכן נוסח המייל \"{$template->name}\"");
        $this->notifySuccess('נוסח המייל נשמר.');
    }

    /**
     * The wording of the one email a supplier missing several invoices gets
     * from the expenses page's "בקשת חשבוניות מכל הספקים"
     * (Expense::requestAllMissingInvoices()) — EmailTemplate::EXPENSE_LIST_TOKEN
     * marks where the list of expenses, each with its upload button, goes.
     */
    public function saveBulkRequestTemplate(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'bulkRequestSubject' => ['required', 'string', 'max:255'],
            'bulkRequestContent' => ['required', 'string'],
        ], [], ['bulkRequestSubject' => 'נושא המייל', 'bulkRequestContent' => 'גוף המייל']);

        $template = EmailTemplate::updateOrCreate(
            ['template_type' => EmailTemplate::SUPPLIER_INVOICES_REQUEST],
            ['name' => 'בקשת כמה חשבוניות מספק', 'subject' => $data['bulkRequestSubject'], 'content' => $data['bulkRequestContent'], 'is_active' => true],
        );

        $activityLogger->log('email_template.updated', "עודכן נוסח המייל \"{$template->name}\"");
        $this->notifySuccess('נוסח המייל נשמר.');
    }

    /** The several-invoices email filled with example values. */
    public function bulkRequestPreview(): array
    {
        return (new EmailTemplate(['subject' => $this->bulkRequestSubject, 'content' => $this->bulkRequestContent]))->render([
            'supplier_name' => 'דפוס הראל בע"מ',
            'expense_count' => '2',
            'total_amount' => '1,730',
        ]);
    }

    /** The email as a supplier would see it, filled with example values. */
    public function invoiceRequestPreview(): array
    {
        $template = new EmailTemplate(['subject' => $this->invoiceRequestSubject, 'content' => $this->invoiceRequestContent]);

        return $template->render([
            'supplier_name' => 'דפוס הראל בע"מ',
            'expense_date' => now()->subDays(12)->format('d/m/Y'),
            'amount' => '1,250',
            'program_name' => 'תוכנית לדוגמה',
            'notes' => 'הדפסת 400 חוברות',
            'expense_id' => '42',
        ]);
    }

    public function saveTemplate(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'templateName' => ['required', 'string', 'max:255'],
            'templateDocumentType' => ['required', 'in:'.implode(',', array_keys(DocumentTemplate::TYPES))],
            'templateContent' => ['required', 'string'],
        ], [], [
            'templateName' => 'שם התבנית',
            'templateDocumentType' => 'סוג מסמך',
            'templateContent' => 'מלל התבנית',
        ]);

        if ($this->editingTemplateId) {
            $template = DocumentTemplate::findOrFail($this->editingTemplateId);
            $template->update([
                'name' => $data['templateName'],
                'document_type' => $data['templateDocumentType'],
                'content' => $data['templateContent'],
            ]);
            $activityLogger->log('document_template.updated', "עודכנה תבנית מסמך: {$template->name}");
        } else {
            $template = DocumentTemplate::create([
                'name' => $data['templateName'],
                'document_type' => $data['templateDocumentType'],
                'content' => $data['templateContent'],
                'is_active' => true,
            ]);
            $activityLogger->log('document_template.created', "נוצרה תבנית מסמך חדשה: {$template->name}");
        }

        $this->cancelEdit();
        unset($this->templates);
    }

    public function editTemplate(int $id): void
    {
        $template = DocumentTemplate::findOrFail($id);
        $this->editingTemplateId = $template->id;
        $this->templateName = $template->name;
        $this->templateDocumentType = $template->document_type;
        $this->templateContent = $template->content;
        $this->editorVersion++;
        $this->previewTemplateId = null;

        $this->dispatch('open-modal', name: 'template');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingTemplateId', 'templateName', 'templateContent']);
        $this->editorVersion++;
        $this->templateDocumentType = 'quote';
        $this->dispatch('close-modals');
    }

    /**
     * FR-4.14/US-015: a template is disabled, never deleted — existing
     * documents already snapshot their own content and are unaffected.
     */
    public function toggleTemplate(int $id, ActivityLogger $activityLogger): void
    {
        $template = DocumentTemplate::findOrFail($id);
        $template->is_active = ! $template->is_active;
        $template->save();

        $verb = $template->is_active ? 'הופעלה' : 'הושבתה';
        $activityLogger->log('document_template.toggled', "תבנית \"{$template->name}\" {$verb}");

        unset($this->templates);
    }

    public function togglePreview(int $id): void
    {
        $this->previewTemplateId = $this->previewTemplateId === $id ? null : $id;
    }

    /**
     * The "הוספה מהירה" row under the rich-text content editor while
     * editing. The content editor is now a contenteditable div (rich
     * formatting — bold/italic/underline/font-size), not a plain
     * <textarea>, so there's no simple integer "cursor position" to splice
     * a string at from PHP anymore: this method only ensures the
     * DOCUMENT_TEMPLATE_FIELD row exists (same logic as before) and hands
     * back its token — the caller (Alpine, in the Blade below) inserts it
     * into the editor itself via document.execCommand('insertHTML', ...)
     * at the actual browser selection, then syncs templateContent back.
     * Inserting the same linked field twice reuses its existing field row
     * (and just returns its token again) rather than creating a duplicate —
     * a template can legitimately reference the same field more than once.
     */
    public function ensureLinkedField(string $key, ActivityLogger $activityLogger): ?string
    {
        if (! $this->editingTemplateId || ! array_key_exists($key, DocumentLinkedFields::pickerOptions())) {
            return null;
        }

        $field = DocumentTemplateField::where('document_template_id', $this->editingTemplateId)
            ->where('linked_field', $key)
            ->first();

        if (! $field) {
            $nextSort = (int) DocumentTemplateField::where('document_template_id', $this->editingTemplateId)->max('sort_order') + 1;

            $field = DocumentTemplateField::create([
                'document_template_id' => $this->editingTemplateId,
                'name' => preg_replace('/\s*\([^)]*\)$/', '', DocumentLinkedFields::OPTIONS[$key]),
                'field_type' => 'linked',
                'linked_field' => $key,
                'is_required' => false,
                'sort_order' => $nextSort,
            ]);

            $activityLogger->log('document_template_field.created', "נוסף שדה \"{$field->name}\" לתבנית מסמך", [
                'metadata' => ['document_template_id' => $field->document_template_id],
            ]);

            unset($this->templates);
        }

        return $field->placeholderToken();
    }

    #[Computed]
    public function templates()
    {
        return DocumentTemplate::with('fields')->orderBy('document_type')->orderBy('name')->get();
    }

    public function documentTypes(): array
    {
        return DocumentTemplate::TYPES;
    }

    /** The quick-insert search box's results — customer-card fields only, filtered by $linkedFieldSearch. */
    public function linkedFieldPickerOptions(): array
    {
        $search = trim($this->linkedFieldSearch);

        $options = DocumentLinkedFields::pickerOptions();

        if ($search === '') {
            return $options;
        }

        return array_filter($options, fn ($label) => str_contains(mb_strtolower($label), mb_strtolower($search)));
    }
};
?>

<div x-data>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">תבניות מסמכים</h1>
        </div>
    </div>

    <section class="settings-section">
        <div class="section-head">
            <h2>כל התבניות</h2>
            <div style="display:flex; gap:var(--sp-sm)">
                <x-modal name="template" trigger-label="+ תבנית חדשה" :title="$editingTemplateId ? 'עריכת תבנית' : 'תבנית חדשה'">
                    <form wire:submit="saveTemplate" class="form-grid">
                        <div>
                            <label for="templateName">שם התבנית</label>
                            <input type="text" id="templateName" wire:model="templateName">
                            @error('templateName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="templateDocumentType">סוג מסמך</label>
                            <select id="templateDocumentType" wire:model="templateDocumentType">
                                @foreach ($this->documentTypes() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="full">
                            <label for="templateContent">מלל התבנית</label>
                            <div class="rte-toolbar" style="display:flex; align-items:center; gap:4px; margin-bottom:6px">
                                <button type="button" class="btn btn-ghost btn-sm" style="font-weight:700" onmousedown="event.preventDefault()" onclick="document.execCommand('bold')">B</button>
                                <button type="button" class="btn btn-ghost btn-sm" style="font-style:italic" onmousedown="event.preventDefault()" onclick="document.execCommand('italic')">I</button>
                                <button type="button" class="btn btn-ghost btn-sm" style="text-decoration:underline" onmousedown="event.preventDefault()" onclick="document.execCommand('underline')">U</button>
                                <select
                                    style="width:auto"
                                    onmousedown="event.preventDefault(); this._sel = window.getSelection().getRangeAt(0)"
                                    onchange="
                                        if (this._sel) { window.getSelection().removeAllRanges(); window.getSelection().addRange(this._sel); }
                                        document.execCommand('styleWithCSS', false, true);
                                        document.execCommand('fontSize', false, this.value);
                                        $wire.templateContent = $refs.templateContentInput.innerHTML;
                                        this.selectedIndex = 0;
                                    "
                                >
                                    <option value="" disabled selected>גודל גופן</option>
                                    <option value="2">קטן</option>
                                    <option value="3">רגיל</option>
                                    <option value="5">גדול</option>
                                    <option value="7">גדול מאוד</option>
                                </select>
                            </div>
                            <div
                                id="templateContent"
                                x-ref="templateContentInput"
                                wire:ignore
                                wire:key="template-editor-{{ $editorVersion }}"
                                contenteditable="true"
                                class="rte-content"
                                style="min-height:180px; border:1px solid var(--color-border); border-radius:var(--radius-control); padding:var(--sp-sm); font-size:var(--fs-body); line-height:1.9; white-space:pre-wrap"
                                x-on:input="$wire.templateContent = $el.innerHTML"
                            >{!! $templateContent !!}</div>
                            @error('templateContent') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>

                        @if ($editingTemplateId)
                            <div class="full">
                                <label for="linkedFieldSearch">הוספת שדה מכרטיס הלקוחה או מהעסקה לתוך המלל</label>
                                <input type="text" id="linkedFieldSearch" wire:model.live="linkedFieldSearch" placeholder="חיפוש: שם, כתובת, עיר, טלפון, דוא&quot;ל...">
                                @forelse (collect($this->linkedFieldPickerOptions())->groupBy(fn ($label, $key) => strtok($key, '.'), preserveKeys: true) as $group => $options)
                                <div style="font-size:var(--fs-small); font-weight:600; margin-top:10px">{{ \App\Services\DocumentLinkedFields::GROUPS[$group] ?? '' }}</div>
                                <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px">
                                    @foreach ($options as $key => $label)
                                        <button
                                            type="button"
                                            class="btn btn-ghost btn-sm"
                                            onmousedown="event.preventDefault(); window._templateInsertRange = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null"
                                            x-on:click="
                                                $wire.ensureLinkedField('{{ $key }}').then(token => {
                                                    if (!token) return;
                                                    $refs.templateContentInput.focus();
                                                    let sel = window.getSelection();
                                                    sel.removeAllRanges();
                                                    if (window._templateInsertRange) { sel.addRange(window._templateInsertRange); }
                                                    document.execCommand('insertHTML', false, token);
                                                    $wire.templateContent = $refs.templateContentInput.innerHTML;
                                                })
                                            "
                                        >+ {{ $label }}</button>
                                    @endforeach
                                </div>
                                @empty
                                    <div class="text-text-secondary" style="font-size:var(--fs-small); margin-top:8px">אין שדה תואם.</div>
                                @endforelse
                            </div>
                        @endif

                        <div class="full" style="display:flex; gap:var(--sp-sm)">
                            <button type="submit" class="btn btn-primary">{{ $editingTemplateId ? 'שמירת שינויים' : 'יצירת תבנית' }}</button>
                            @if ($editingTemplateId)
                                <button type="button" wire:click="cancelEdit" class="btn btn-ghost">ביטול עריכה</button>
                            @endif
                        </div>
                    </form>
                </x-modal>
            </div>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom:var(--sp-lg)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>שם התבנית</th><th>סוג מסמך</th><th>סטטוס</th><th>פעולות</th></tr></thead>
                <tbody>
                    @foreach ($this->templates as $template)
                        <tr>
                            <td>{{ $template->name }}</td>
                            <td><span class="badge badge-primary">{{ DocumentTemplate::TYPES[$template->document_type] ?? $template->document_type }}</span></td>
                            <td>
                                @if ($template->is_active)
                                    <span class="badge badge-success">פעילה</span>
                                @else
                                    <span class="badge badge-neutral">לא פעילה</span>
                                @endif
                            </td>
                            <td>
                                <div style="display:flex; gap:6px">
                                    <button type="button" wire:click="editTemplate({{ $template->id }})" class="btn btn-ghost btn-sm">עריכה</button>
                                    <button type="button" wire:click="togglePreview({{ $template->id }})" class="btn btn-ghost btn-sm">תצוגה מקדימה</button>
                                    <button type="button" wire:click="toggleTemplate({{ $template->id }})" class="btn btn-ghost btn-sm">{{ $template->is_active ? 'השבתה' : 'הפעלה' }}</button>
                                </div>
                            </td>
                        </tr>
                        @if ($previewTemplateId === $template->id)
                            <tr>
                                <td colspan="4" style="background:var(--color-background)">
                                    <div style="white-space:pre-wrap; font-size:var(--fs-small); padding:var(--sp-sm) 0">{!! $template->renderContent() !!}</div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

    </section>

    @include('partials.supplier-email-editor', [
        'title' => 'מייל בקשת חשבונית מספק',
        'intro' => 'המייל שנשלח לספק עבור הוצאה אחת שאין לה חשבונית: בלחיצה על "בקשה מהספק במייל" בדף ההוצאות, או בשליחה לכל הספקים כשחסרה לספק חשבונית אחת. השדות בסוגריים המסולסלים יתמלאו בפרטי ההוצאה, והספק יקבל כפתור להעלאת החשבונית ישירות למערכת.',
        'subjectModel' => 'invoiceRequestSubject',
        'contentModel' => 'invoiceRequestContent',
        'fields' => ['שם_הספק' => 'שם הספק', 'תאריך_התשלום' => 'תאריך התשלום', 'סכום' => 'סכום', 'תוכנית' => 'תוכנית', 'הערות' => 'הערות'],
        'blockToken' => EmailTemplate::UPLOAD_BUTTON_TOKEN,
        'blockLabel' => 'כפתור העלאת החשבונית',
        'blockHint' => 'אם הכפתור לא מופיע בטקסט, הוא יתווסף בסוף המייל.',
        'blockPreviewHtml' => '<span style="display:inline-block; background:#286E9A; color:#fff; border-radius:32px; padding:10px 28px; font-weight:700">להעלאת החשבונית</span>',
        'preview' => $this->invoiceRequestPreview(),
        'saveAction' => 'saveInvoiceRequestTemplate',
    ])

    @include('partials.supplier-email-editor', [
        'title' => 'מייל בקשת כמה חשבוניות מספק',
        'intro' => 'המייל שנשלח בלחיצה על "בקשת חשבוניות מכל הספקים" בדף ההוצאות לספק שחסרות לו כמה חשבוניות — מייל אחד עם רשימת ההוצאות, ולכל הוצאה כפתור משלה להעלאת החשבונית.',
        'subjectModel' => 'bulkRequestSubject',
        'contentModel' => 'bulkRequestContent',
        'fields' => ['שם_הספק' => 'שם הספק', 'מספר_החשבוניות_החסרות' => 'מספר החשבוניות החסרות', 'סכום_כולל' => 'סכום כולל'],
        'blockToken' => EmailTemplate::EXPENSE_LIST_TOKEN,
        'blockLabel' => 'רשימת ההוצאות עם כפתורי ההעלאה',
        'blockHint' => 'אם הרשימה לא מופיעה בטקסט, היא תתווסף בסוף המייל.',
        'blockPreviewHtml' => collect(['תשלום מתאריך 03/09/2026 · ₪1,250', 'תשלום מתאריך 21/09/2026 · ₪480'])->map(fn ($row) => '<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; padding:8px 0; border-bottom:1px solid var(--color-border)"><span>'.$row.'</span><span style="display:inline-block; background:#286E9A; color:#fff; border-radius:24px; padding:6px 16px; font-weight:700; font-size:var(--fs-caption)">להעלאת החשבונית</span></div>')->implode(''),
        'preview' => $this->bulkRequestPreview(),
        'saveAction' => 'saveBulkRequestTemplate',
    ])
</div>
