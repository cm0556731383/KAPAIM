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
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">תבניות מסמכים</h1>
            <p class="text-text-secondary m-0">הצעת מחיר · טופס הזמנה · חוזה · חשבונית</p>
        </div>
    </div>

    <div class="mb-8" style="display:flex; align-items:center; gap:10px; background: var(--color-primary-lighter); color: var(--color-primary-hover); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
        <strong>שינוי תבנית משפיע רק על מסמכים חדשים.</strong> מסמכים שכבר הופקו אינם משתנים בעקבות עריכת התבנית (FR-4.14) — לכן אין כאן אפשרות מחיקה, רק יצירה, עריכה והשבתה.
    </div>

    <section class="settings-section">
        <div class="section-head">
            <h2>כל התבניות</h2>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom:var(--sp-lg)">
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
                            <td style="display:flex; gap:6px">
                                <button type="button" wire:click="editTemplate({{ $template->id }})" class="btn btn-ghost btn-sm">עריכה</button>
                                <button type="button" wire:click="togglePreview({{ $template->id }})" class="btn btn-ghost btn-sm">תצוגה מקדימה</button>
                                <button type="button" wire:click="toggleTemplate({{ $template->id }})" class="btn btn-ghost btn-sm">{{ $template->is_active ? 'השבתה' : 'הפעלה' }}</button>
                            </td>
                        </tr>
                        @if ($previewTemplateId === $template->id)
                            <tr>
                                <td colspan="4" style="background:var(--color-background)">
                                    <div style="white-space:pre-wrap; font-size:var(--fs-small); padding:var(--sp-sm) 0">{{ $template->renderContent() }}</div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
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
                        <textarea id="templateContent" wire:model="templateContent" rows="8" placeholder="שלבו שדות מקושרים/טקסט חופשי בטבלה למטה — התג יופיע כאן כ- @{{שם_השדה}}"></textarea>
                        @error('templateContent') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
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
                        <label for="fieldIsRequired" style="margin:0">שדה חובה (FR-4.11)</label>
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
        @endforeach
    </section>
</div>
