<?php

use App\Concerns\Notifies;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateField;
use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Build-plan 07 (US-015) — תבניות מסמכים, shown as a tab of the settings
 * page (⚡settings.blade.php embeds it; /document-templates redirects there)
 * but still gated by its own document-templates permission. Mirrors ⚡settings.blade.php's
 * email-template-field manager pattern. FR-4.14/US-015's closing line: a
 * template is never deleted, only deactivated (is_active) — same convention
 * as programs/bundles/email templates — because DocumentTemplate itself
 * stays a pure renderer and every already-generated Document snapshots its
 * own rendered_content/field_values at generation time (see
 * Document::generateFor()), so editing a template here can never reach back
 * into a document that already exists.
 */
new
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

    public function mount(): void
    {
        abort_unless(auth()->user()->can('document-templates.view'), 403);
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

        // Only an existing template is saved here — new templates aren't created from the screen.
        $template = DocumentTemplate::findOrFail($this->editingTemplateId);
        $template->update([
            'name' => $data['templateName'],
            'document_type' => $data['templateDocumentType'],
            'content' => $data['templateContent'],
        ]);
        $activityLogger->log('document_template.updated', "עודכנה תבנית מסמך: {$template->name}");

        $this->dispatch('close-modals');
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
    <section class="settings-section">
        <div class="section-head">
            <h2>כל התבניות</h2>
            <div style="display:flex; gap:var(--sp-sm)">
                {{-- Existing templates are edited here (editTemplate() opens it); new ones aren't created from the screen. --}}
                <x-modal name="template" title="עריכת תבנית">
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
                            <button type="submit" class="btn btn-primary">שמירת שינויים</button>
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
</div>
