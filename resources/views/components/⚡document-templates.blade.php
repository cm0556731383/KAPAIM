<?php

use App\Models\DocumentTemplate;
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
    public ?int $editingTemplateId = null;

    public string $templateName = '';
    public string $templateDocumentType = 'quote';
    public string $templateContent = '';

    /** The "quick insert" search box below the content textarea — filters App\Services\DocumentLinkedFields::customerCardOptions() only, never the deal-scoped options. */
    public string $linkedFieldSearch = '';

    public ?int $previewTemplateId = null;

    public ?int $fieldTemplateId = null;
    public string $fieldName = '';
    public string $fieldType = 'free_text';
    public string $fieldLinkedField = '';
    public bool $fieldIsRequired = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('document-templates.manage'), 403);
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
        $this->previewTemplateId = null;

        // Previously the "הוספת שדה" panel required re-picking the template
        // from its own dropdown even while already editing it here — this
        // keeps the two in sync so managing a template's fields is part of
        // editing it, not a separate disconnected step.
        $this->fieldTemplateId = $template->id;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingTemplateId', 'templateName', 'templateContent']);
        $this->templateDocumentType = 'quote';
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
     * Adds a field row (FR-4.10/FR-4.11). Fields can be freely added/removed
     * while a template evolves — same "not yet snapshotted anywhere" logic
     * ⚡settings.blade.php's addEmailTemplateField() documents — because a
     * generated document snapshots the field *values* it captured
     * (Document::field_values), not a live reference to the field row.
     */
    public function addField(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'fieldTemplateId' => ['required', 'exists:document_templates,id'],
            'fieldName' => ['required', 'string', 'max:255'],
            'fieldType' => ['required', 'in:free_text,linked'],
            'fieldLinkedField' => ['required_if:fieldType,linked', 'nullable', 'in:'.implode(',', array_keys(DocumentLinkedFields::OPTIONS))],
            'fieldIsRequired' => ['boolean'],
        ], [], ['fieldName' => 'שם השדה', 'fieldLinkedField' => 'שדה מקושר']);

        $nextSort = (int) DocumentTemplateField::where('document_template_id', $data['fieldTemplateId'])->max('sort_order') + 1;

        $field = DocumentTemplateField::create([
            'document_template_id' => $data['fieldTemplateId'],
            'name' => $data['fieldName'],
            'field_type' => $data['fieldType'],
            'linked_field' => $data['fieldType'] === 'linked' ? $data['fieldLinkedField'] : null,
            'is_required' => (bool) $data['fieldIsRequired'],
            'sort_order' => $nextSort,
        ]);

        $activityLogger->log('document_template_field.created', "נוסף שדה \"{$field->name}\" לתבנית מסמך", [
            'metadata' => ['document_template_id' => $field->document_template_id],
        ]);

        $this->reset(['fieldName', 'fieldLinkedField', 'fieldIsRequired']);
        $this->fieldType = 'free_text';
        unset($this->templates);
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
        if (! $this->editingTemplateId || ! array_key_exists($key, DocumentLinkedFields::customerCardOptions())) {
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

    public function removeField(int $id, ActivityLogger $activityLogger): void
    {
        $field = DocumentTemplateField::findOrFail($id);
        $templateId = $field->document_template_id;
        $name = $field->name;
        $field->delete();

        $activityLogger->log('document_template_field.removed', "הוסר שדה \"{$name}\" מתבנית מסמך", [
            'metadata' => ['document_template_id' => $templateId],
        ]);

        unset($this->templates);
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

    public function linkedFieldOptions(): array
    {
        return DocumentLinkedFields::OPTIONS;
    }

    /** The quick-insert search box's results — customer-card fields only, filtered by $linkedFieldSearch. */
    public function customerCardLinkedFieldOptions(): array
    {
        $search = trim($this->linkedFieldSearch);

        $options = DocumentLinkedFields::customerCardOptions();

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
            <p class="text-text-secondary m-0">הצעת מחיר · טופס הזמנה · חוזה · חשבונית</p>
        </div>
    </div>

    <div class="mb-8" style="display:flex; align-items:center; gap:10px; background: var(--color-primary-lighter); color: var(--color-primary-hover); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
        <strong>שינוי תבנית משפיע רק על מסמכים חדשים.</strong> מסמכים שכבר הופקו אינם משתנים בעקבות עריכת התבנית — לכן אין כאן אפשרות מחיקה, רק יצירה, עריכה והשבתה.
    </div>

    <section class="settings-section">
        <div class="section-head">
            <h2>כל התבניות</h2>
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

        <div class="cols2">
            <div class="card template-editor">
                <h3>{{ $editingTemplateId ? 'עריכת תבנית' : 'תבנית חדשה' }}</h3>
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
                                    $wire.set('templateContent', $refs.templateContentInput.innerHTML);
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
                            contenteditable="true"
                            class="rte-content"
                            style="min-height:180px; border:1px solid var(--color-border); border-radius:var(--radius-control); padding:var(--sp-sm); font-size:var(--fs-body); line-height:1.9; white-space:pre-wrap"
                            x-on:input.debounce.500ms="$wire.set('templateContent', $el.innerHTML)"
                        >{!! $templateContent !!}</div>
                        @error('templateContent') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>

                    @if ($editingTemplateId)
                        <div class="full">
                            <label for="linkedFieldSearch">הוספת שדה מכרטיס הלקוחה לתוך המלל</label>
                            <input type="text" id="linkedFieldSearch" wire:model.live="linkedFieldSearch" placeholder="חיפוש: שם, כתובת, עיר, טלפון, דוא&quot;ל...">
                            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px">
                                @forelse ($this->customerCardLinkedFieldOptions() as $key => $label)
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
                                                $wire.set('templateContent', $refs.templateContentInput.innerHTML);
                                            })
                                        "
                                    >+ {{ $label }}</button>
                                @empty
                                    <span class="text-text-secondary" style="font-size:var(--fs-small)">אין שדה תואם בכרטיס הלקוחה.</span>
                                @endforelse
                            </div>
                        </div>
                    @endif

                    <div class="full" style="display:flex; gap:var(--sp-sm)">
                        <button type="submit" class="btn btn-primary">{{ $editingTemplateId ? 'שמירת שינויים' : 'יצירת תבנית' }}</button>
                        @if ($editingTemplateId)
                            <button type="button" wire:click="cancelEdit" class="btn btn-ghost">ביטול עריכה</button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="card">
                <h3>הוספת שדה לתבנית</h3>
                <form wire:submit="addField" class="form-grid">
                    <div class="full">
                        <label for="fieldTemplateId">תבנית</label>
                        <select id="fieldTemplateId" wire:model="fieldTemplateId">
                            <option value="">בחרו תבנית</option>
                            @foreach ($this->templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                        @error('fieldTemplateId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="fieldName">שם השדה</label>
                        <input type="text" id="fieldName" wire:model="fieldName">
                        @error('fieldName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="fieldType">סוג שדה</label>
                        <select id="fieldType" wire:model.live="fieldType">
                            <option value="free_text">טקסט חופשי</option>
                            <option value="linked">מקושר למידע קיים</option>
                        </select>
                    </div>
                    @if ($fieldType === 'linked')
                        <div class="full">
                            <label for="fieldLinkedField">שדה מקושר</label>
                            <select id="fieldLinkedField" wire:model="fieldLinkedField">
                                <option value="">בחרו שדה מקושר</option>
                                @foreach ($this->linkedFieldOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('fieldLinkedField') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                    @endif
                    <div class="full checkbox-row">
                        <input type="checkbox" id="fieldIsRequired" wire:model="fieldIsRequired">
                        <label for="fieldIsRequired" style="margin:0">שדה חובה</label>
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">הוספת שדה</button></div>
                </form>
            </div>
        </div>
    </section>

    <section class="settings-section">
        <div class="section-head"><h2>שדות התבניות</h2></div>
        @foreach ($this->templates as $template)
            <div class="card" style="margin-bottom:var(--sp-md)">
                <h3 style="margin-bottom:4px">{{ $template->name }}</h3>
                <p class="text-text-secondary" style="margin:0 0 var(--sp-sm); font-size:var(--fs-caption)">{{ DocumentTemplate::TYPES[$template->document_type] ?? $template->document_type }}</p>
                <div class="table-scroll">
                <table>
                    <thead><tr><th>שם שדה</th><th>סוג</th><th>שדה מקושר</th><th>חובה</th><th>סדר</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($template->fields as $field)
                            <tr>
                                <td>{{ $field->name }}</td>
                                <td><span class="scope-pill">{{ $field->field_type === 'linked' ? 'מקושר' : 'טקסט חופשי' }}</span></td>
                                <td class="mono">{{ $field->linked_field ?? '—' }}</td>
                                <td>{{ $field->is_required ? 'כן' : 'לא' }}</td>
                                <td class="ltr-num">{{ $field->sort_order }}</td>
                                <td><button type="button" wire:click="removeField({{ $field->id }})" class="btn btn-ghost btn-sm">הסרה</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-text-secondary">אין עדיין שדות בתבנית זו.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        @endforeach
    </section>
</div>
