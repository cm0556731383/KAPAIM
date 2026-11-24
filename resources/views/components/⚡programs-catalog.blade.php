<?php

use App\Concerns\Notifies;
use App\Models\Bundle;
use App\Models\DocumentTemplate;
use App\Models\Program;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.app', ['title' => 'קטלוג תוכניות ומארזים — כפיים'])]
class extends Component
{
    use Notifies;

    // ===== תוכניות (PROGRAM) =====
    public string $programName = '';
    public string $programDescription = '';
    public string $programPrice = '';
    public string $programPrice34 = '';
    public string $programPrice56 = '';
    public bool $programIsPremium = false;
    public string $programContractTemplateId = '';

    // ===== מארזים (BUNDLE) =====
    public string $bundleName = '';
    public string $bundleDescription = '';
    public string $bundlePrice = '';
    public string $bundlePrice34 = '';
    public string $bundlePrice56 = '';
    public bool $bundleIsSubscriptionType = false;

    /** A subscription bundle's school year (Hebrew year, 5787 = תשפ״ז) — required for one (2026-11-24). */
    public string $bundleSchoolYear = '';
    public string $bundleContractTemplateId = '';

    // ===== עריכה =====
    /** "program:{id}" / "bundle:{id}" being edited inline. */
    public ?string $editingItem = null;
    public string $editName = '';
    public string $editDescription = '';
    public string $editPrice = '';
    public string $editPrice34 = '';
    public string $editPrice56 = '';
    public bool $editFlag = false;
    public string $editSchoolYear = '';
    public string $editContractTemplateId = '';

    // ===== שיוך תוכניות למארז =====
    public ?int $membershipBundleId = null;
    public ?int $membershipProgramId = null;

    /** What the window adds: "program:ID", or "bundle:ID" (only into a subscription bundle). */
    public string $membershipItem = '';

    /**
     * Business-rule error (FR-7.25 "הודעת שגיאה עסקית") — blocks an action and
     * explains why, distinct from a plain validation error on a form field.
     */
    public ?string $catalogError = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('catalog.view'), 403);

        $this->resetContractDefaults();
    }

    /** A new item's default contract starts as "חוזה סטנדרטי" — changeable (2026-10-27). */
    private function resetContractDefaults(): void
    {
        $this->programContractTemplateId = $this->bundleContractTemplateId = (string) (DocumentTemplate::standardContract()?->id ?? '');
    }

    /** The default-contract picker's options — the active contract templates. */
    #[Computed]
    public function contractTemplates()
    {
        return DocumentTemplate::where('is_active', true)->where('document_type', 'contract')->orderBy('name')->orderBy('id')->get();
    }

    private function contractTemplateRule(): array
    {
        return ['nullable', \Illuminate\Validation\Rule::exists('document_templates', 'id')->where('document_type', 'contract')];
    }

    // ----- תוכניות -----

    public function addProgram(ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;

        $data = $this->validate([
            'programName' => ['required', 'string', 'max:255', 'unique:programs,name'],
            'programDescription' => ['nullable', 'string'],
            'programPrice' => ['required', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'programPrice34' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'programPrice56' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'programIsPremium' => ['boolean'],
            'programContractTemplateId' => $this->contractTemplateRule(),
        ], [], [
            'programName' => 'שם התוכנית',
            'programPrice' => 'מחיר 1–2 כיתות',
            'programPrice34' => 'מחיר 3–4 כיתות',
            'programPrice56' => 'מחיר 5–6 כיתות',
        ]);

        $program = Program::create([
            'name' => $data['programName'],
            'description' => $data['programDescription'] ?: null,
            'price' => $data['programPrice'],
            'price_3_4' => $data['programPrice34'] ?: null,
            'price_5_6' => $data['programPrice56'] ?: null,
            'is_premium' => $data['programIsPremium'],
            'contract_template_id' => $data['programContractTemplateId'] ?: null,
            'is_active' => true,
        ]);

        $activityLogger->log('program.created', "נוצרה תוכנית חדשה: {$program->name}");

        $this->reset(['programName', 'programDescription', 'programPrice', 'programPrice34', 'programPrice56', 'programIsPremium']);
        $this->resetContractDefaults();
        unset($this->programs);

        $this->dispatch('close-modals');
    }

    public function toggleProgram(int $id, ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;

        /** @var Program $program */
        $program = Program::findOrFail($id);

        $program->is_active = ! $program->is_active;
        $program->save();

        $verb = $program->is_active ? 'הופעלה' : 'הושבתה';
        $activityLogger->log('program.toggled', "תוכנית \"{$program->name}\" {$verb}", [
            'metadata' => ['id' => $program->id, 'is_active' => $program->is_active],
        ]);

        unset($this->programs);
    }

    /**
     * Editing the catalog never touches past sales — every deal keeps its
     * own program/bundle name and price snapshot (FR-8.25).
     */
    public function editItem(string $type, int $id): void
    {
        $item = $type === 'program' ? Program::findOrFail($id) : Bundle::findOrFail($id);

        $this->catalogError = null;
        $this->resetErrorBag();
        $this->editingItem = "{$type}:{$id}";
        $this->editName = $item->name;
        $this->editDescription = (string) $item->description;
        $this->editPrice = (string) (float) $item->price;
        $this->editPrice34 = $item->price_3_4 !== null ? (string) (float) $item->price_3_4 : '';
        $this->editPrice56 = $item->price_5_6 !== null ? (string) (float) $item->price_5_6 : '';
        $this->editFlag = (bool) ($type === 'program' ? $item->is_premium : $item->is_subscription_type);
        $this->editContractTemplateId = (string) ($item->contract_template_id ?? '');
        $this->editSchoolYear = (string) ($type === 'bundle' ? ($item->school_year ?? '') : '');
    }

    /** The school-year rule: required for a subscription bundle. */
    private function schoolYearRule(string $flagField): array
    {
        return ["required_if_accepted:{$flagField}", 'nullable', 'integer', 'between:5700,5999'];
    }

    /** School-year picker options (keeps an edited bundle's own year even when outside the range). */
    public function schoolYearOptions(?int $include = null): array
    {
        return \App\Services\HebrewCalendar::schoolYearOptions($include);
    }

    public function cancelItemEdit(): void
    {
        $this->reset(['editingItem', 'editName', 'editDescription', 'editPrice', 'editPrice34', 'editPrice56', 'editFlag', 'editSchoolYear', 'editContractTemplateId']);
    }

    public function saveItem(ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;
        [$type, $id] = explode(':', (string) $this->editingItem) + [null, null];
        $table = $type === 'program' ? 'programs' : 'bundles';

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:255', "unique:{$table},name,".(int) $id],
            'editDescription' => ['nullable', 'string'],
            'editPrice' => ['required', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'editPrice34' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'editPrice56' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'editFlag' => ['boolean'],
            'editSchoolYear' => $type === 'bundle' ? $this->schoolYearRule('editFlag') : ['nullable'],
            'editContractTemplateId' => $this->contractTemplateRule(),
        ], ['editSchoolYear.required_if_accepted' => 'למארז מנוי יש לבחור את שנת הלימודים שלו.'], ['editName' => 'שם', 'editSchoolYear' => 'שנת לימודים', 'editPrice' => 'מחיר 1–2 כיתות', 'editPrice34' => 'מחיר 3–4 כיתות', 'editPrice56' => 'מחיר 5–6 כיתות', 'editContractTemplateId' => 'חוזה ברירת מחדל']);

        if ($type === 'program') {
            $item = Program::findOrFail((int) $id);
            $oldName = $item->name;
            $item->update([
                'name' => $data['editName'],
                'description' => $data['editDescription'] ?: null,
                'price' => $data['editPrice'],
                'price_3_4' => $data['editPrice34'] ?: null,
                'price_5_6' => $data['editPrice56'] ?: null,
                'is_premium' => $data['editFlag'],
                'contract_template_id' => $data['editContractTemplateId'] ?: null,
            ]);

            // The program's own mailing list is named after it.
            \App\Models\MailingList::where('program_id', $item->id)->where('name', $oldName)->update(['name' => $item->name]);
        } else {
            $item = Bundle::findOrFail((int) $id);

            if (! $data['editFlag'] && $item->is_subscription_type && $item->includedBundles()->exists()) {
                $this->catalogError = 'במארז זה כלולים מארזים אחרים — רק מארז שמקנה מנוי שנתי יכול לכלול מארזים. יש להסיר אותם קודם.';

                return;
            }

            if ($data['editFlag'] && ! $item->is_subscription_type && $item->includedIn()->exists()) {
                $this->catalogError = 'מארז זה כלול בתוך מארז מנוי, ולכן אינו יכול להיות בעצמו מארז מנוי. יש להסיר אותו משם קודם.';

                return;
            }

            $item->update([
                'name' => $data['editName'],
                'description' => $data['editDescription'] ?: null,
                'price' => $data['editPrice'],
                'price_3_4' => $data['editPrice34'] ?: null,
                'price_5_6' => $data['editPrice56'] ?: null,
                'is_subscription_type' => $data['editFlag'],
                // Subscriptions already sold keep their months; the year applies to new sales.
                'school_year' => $data['editFlag'] && $data['editSchoolYear'] !== '' ? (int) $data['editSchoolYear'] : null,
                'contract_template_id' => $data['editContractTemplateId'] ?: null,
            ]);
        }

        $activityLogger->log("{$type}.updated", ($type === 'program' ? 'עודכנה תוכנית: ' : 'עודכן מארז: ').$item->name, [
            'metadata' => ['id' => $item->id],
        ]);

        $this->cancelItemEdit();
        unset($this->programs, $this->bundles);
    }

    #[Computed]
    public function programs()
    {
        return Program::with('contractTemplate')->orderBy('name')->get();
    }

    // ----- מארזים -----

    public function addBundle(ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;

        $data = $this->validate([
            'bundleName' => ['required', 'string', 'max:255', 'unique:bundles,name'],
            'bundleDescription' => ['nullable', 'string'],
            'bundlePrice' => ['required', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'bundlePrice34' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'bundlePrice56' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'bundleIsSubscriptionType' => ['boolean'],
            'bundleSchoolYear' => $this->schoolYearRule('bundleIsSubscriptionType'),
            'bundleContractTemplateId' => $this->contractTemplateRule(),
        ], ['bundleSchoolYear.required_if_accepted' => 'למארז מנוי יש לבחור את שנת הלימודים שלו.'], [
            'bundleSchoolYear' => 'שנת לימודים',
            'bundleName' => 'שם המארז',
            'bundlePrice' => 'מחיר 1–2 כיתות',
            'bundlePrice34' => 'מחיר 3–4 כיתות',
            'bundlePrice56' => 'מחיר 5–6 כיתות',
        ]);

        $bundle = Bundle::create([
            'name' => $data['bundleName'],
            'description' => $data['bundleDescription'] ?: null,
            'price' => $data['bundlePrice'],
            'price_3_4' => $data['bundlePrice34'] ?: null,
            'price_5_6' => $data['bundlePrice56'] ?: null,
            'is_subscription_type' => $data['bundleIsSubscriptionType'],
            'school_year' => $data['bundleIsSubscriptionType'] && $data['bundleSchoolYear'] !== '' ? (int) $data['bundleSchoolYear'] : null,
            'contract_template_id' => $data['bundleContractTemplateId'] ?: null,
            'is_active' => true,
        ]);

        $activityLogger->log('bundle.created', "נוצר מארז חדש: {$bundle->name}");

        $this->reset(['bundleName', 'bundleDescription', 'bundlePrice', 'bundlePrice34', 'bundlePrice56', 'bundleIsSubscriptionType', 'bundleSchoolYear']);
        $this->resetContractDefaults();
        unset($this->bundles);

        $this->dispatch('close-modals');
    }

    public function toggleBundle(int $id, ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;

        /** @var Bundle $bundle */
        $bundle = Bundle::findOrFail($id);

        $bundle->is_active = ! $bundle->is_active;
        $bundle->save();

        $verb = $bundle->is_active ? 'הופעל' : 'הושבת';
        $activityLogger->log('bundle.toggled', "מארז \"{$bundle->name}\" {$verb}", [
            'metadata' => ['id' => $bundle->id, 'is_active' => $bundle->is_active],
        ]);

        unset($this->bundles);
    }

    /**
     * Bundle-program membership is a plain many-to-many that can be freely
     * edited (attach/detach) — unlike is_active, there's no historical
     * snapshot to protect yet since DEAL (stage 6) doesn't exist.
     */
    public function addBundleProgram(ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;

        [$itemType, $itemId] = explode(':', $this->membershipItem) + [null, null];

        if ($itemType === 'bundle') {
            $this->addIncludedBundle((int) $itemId, $activityLogger);

            return;
        }

        if ($itemType === 'program') {
            $this->membershipProgramId = (int) $itemId;
        }

        $data = $this->validate([
            'membershipBundleId' => ['required', 'exists:bundles,id'],
            'membershipProgramId' => ['required', 'exists:programs,id'],
        ], [], ['membershipBundleId' => 'מארז', 'membershipProgramId' => 'תוכנית']);

        $bundle = Bundle::findOrFail($data['membershipBundleId']);

        if ($bundle->programs()->where('programs.id', $data['membershipProgramId'])->exists()) {
            $this->catalogError = 'התוכנית כבר כלולה במארז זה.';

            return;
        }

        $bundle->programs()->attach($data['membershipProgramId']);
        $program = Program::find($data['membershipProgramId']);
        $bundle->syncSubscriberProgramList($program, joined: true);

        $activityLogger->log('bundle.program_added', "תוכנית \"{$program?->name}\" נוספה למארז \"{$bundle->name}\"", [
            'metadata' => ['bundle_id' => $bundle->id, 'program_id' => $program?->id],
        ]);

        unset($this->bundles);
        $this->reset(['membershipItem', 'membershipProgramId']);

        $this->dispatch('close-modals');
    }

    /** A bundle included in a subscription bundle: its programs become part of the subscription (Bundle::allPrograms()). */
    private function addIncludedBundle(int $childId, ActivityLogger $activityLogger): void
    {
        $this->validate(['membershipBundleId' => ['required', 'exists:bundles,id']], [], ['membershipBundleId' => 'מארז']);

        $parent = Bundle::findOrFail($this->membershipBundleId);
        $child = Bundle::find($childId);

        $error = match (true) {
            ! $child => 'יש לבחור תוכנית או מארז.',
            ! $parent->is_subscription_type => 'רק מארז שמקנה מנוי שנתי יכול לכלול מארזים אחרים.',
            $child->id === $parent->id => 'מארז אינו יכול לכלול את עצמו.',
            $child->is_subscription_type => 'אי אפשר לכלול מארז מנוי בתוך מארז מנוי.',
            $parent->includedBundles()->whereKey($child->id)->exists() => 'המארז כבר כלול במארז זה.',
            default => null,
        };

        if ($error) {
            $this->catalogError = $error;

            return;
        }

        $parent->includedBundles()->attach($child->id);
        $parent->refresh();
        $child->programs->each(fn (Program $program) => $parent->syncSubscriberProgramList($program, joined: true));

        $activityLogger->log('bundle.bundle_added', "מארז \"{$child->name}\" נכלל במארז \"{$parent->name}\"", [
            'metadata' => ['bundle_id' => $parent->id, 'included_bundle_id' => $child->id],
        ]);

        unset($this->bundles);
        $this->reset(['membershipItem', 'membershipProgramId']);
        $this->dispatch('close-modals');
    }

    public function removeIncludedBundle(int $bundleId, int $childId, ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;
        $parent = Bundle::findOrFail($bundleId);
        $child = Bundle::findOrFail($childId);

        $parent->includedBundles()->detach($child->id);
        $parent->refresh();
        // Leaves only programs no longer in the subscription another way (Bundle::syncSubscriberProgramList()).
        $child->programs->each(fn (Program $program) => $parent->syncSubscriberProgramList($program, joined: false));

        $activityLogger->log('bundle.bundle_removed', "מארז \"{$child->name}\" הוסר ממארז \"{$parent->name}\"", [
            'metadata' => ['bundle_id' => $parent->id, 'included_bundle_id' => $child->id],
        ]);

        unset($this->bundles);
    }

    public function removeBundleProgram(int $bundleId, int $programId, ActivityLogger $activityLogger): void
    {
        $bundle = Bundle::findOrFail($bundleId);
        $program = Program::find($programId);

        $bundle->programs()->detach($programId);

        if ($program) {
            $bundle->syncSubscriberProgramList($program, joined: false);
        }

        $activityLogger->log('bundle.program_removed', "תוכנית \"{$program?->name}\" הוסרה ממארז \"{$bundle->name}\"", [
            'metadata' => ['bundle_id' => $bundle->id, 'program_id' => $programId],
        ]);

        unset($this->bundles);
    }

    #[Computed]
    public function bundles()
    {
        return Bundle::with(['programs', 'includedBundles', 'contractTemplate'])->orderBy('name')->get();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">קטלוג תוכניות ומארזים</h1>
        </div>
    </div>


    {{-- ===== תוכניות ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <div>
                <h2>תוכניות</h2>
                <p class="hint">חודשיות ופרימיום. המחיר בטבלה הוא ל-1–2 כיתות; כל המחירים בעריכה.</p>
            </div>
            <x-modal trigger-label="+ תוכנית חדשה" title="תוכנית חדשה">
                <form wire:submit="addProgram" class="form-grid">
                    <div class="full">
                        <label for="programName">שם</label>
                        <input type="text" id="programName" wire:model="programName" placeholder="למשל: פרחי אביב — תוכנית חודשית">
                        @error('programName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <label for="programDescription">תיאור</label>
                        <textarea id="programDescription" wire:model="programDescription" rows="2" placeholder="תיאור קצר של התוכנית..."></textarea>
                    </div>
                    <div class="full tier-prices">
                        <div><label for="programPrice">מחיר 1–2 כיתות</label><input type="text" id="programPrice" wire:model="programPrice" class="ltr-num" dir="ltr" placeholder="₪ 0"> @error('programPrice') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                        <div><label for="programPrice34">מחיר 3–4 כיתות</label><input type="text" id="programPrice34" wire:model="programPrice34" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('programPrice34') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                        <div><label for="programPrice56">מחיר 5–6 כיתות</label><input type="text" id="programPrice56" wire:model="programPrice56" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('programPrice56') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                    </div>
                    <div class="full">
                        <span class="checkbox-row"><input type="checkbox" id="programIsPremium" wire:model="programIsPremium"><label for="programIsPremium" style="margin:0">תוכנית פרימיום</label></span>
                    </div>
                    <div class="full">
                        <label for="programContractTemplateId">חוזה ברירת מחדל</label>
                            <select id="programContractTemplateId" wire:model="programContractTemplateId">
                                <option value="">— החוזה הסטנדרטי —</option>
                                @foreach ($this->contractTemplates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                            <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">החוזה שנשלח יחד עם טופס ההזמנה בעסקה על פריט זה.</div>
                            @error('programContractTemplateId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">יצירת תוכנית</button></div>
                </form>
            </x-modal>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <div class="table-scroll">
            <table class="catalog-table">
                <thead><tr><th>שם</th><th>מחיר</th><th>פרימיום</th><th>חוזה</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->programs as $program)
                        @if ($editingItem === "program:{$program->id}")
                        <tr>
                            <td colspan="6" class="edit-cell">
                                <form wire:submit="saveItem" class="form-grid catalog-edit">
                                    <div><label>שם</label><input type="text" wire:model="editName"> @error('editName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div><label>מחיר 1–2 כיתות</label><input type="text" wire:model="editPrice" class="ltr-num" dir="ltr"> @error('editPrice') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div><label>מחיר 3–4 כיתות</label><input type="text" wire:model="editPrice34" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('editPrice34') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div><label>מחיר 5–6 כיתות</label><input type="text" wire:model="editPrice56" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('editPrice56') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div class="full"><label>תיאור</label><textarea wire:model="editDescription" rows="1"></textarea></div>
                                    <div class="half"><label for="editContractTemplateId">חוזה ברירת מחדל</label>
                                        <select id="editContractTemplateId" wire:model="editContractTemplateId">
                                            <option value="">— החוזה הסטנדרטי —</option>
                                            @foreach ($this->contractTemplates as $template)
                                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('editContractTemplateId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div class="half" style="align-self:end; padding-bottom:10px"><span class="checkbox-row"><input type="checkbox" id="editFlag" wire:model="editFlag"><label for="editFlag" style="margin:0">תוכנית פרימיום</label></span></div>
                                    <div class="full catalog-edit-actions">
                                        <button type="submit" class="btn btn-primary btn-sm">שמירה</button>
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelItemEdit">ביטול</button>
                                        <span class="text-text-secondary">מחיר ריק = כמו 1–2 כיתות. שינוי שם או מחיר חל רק על מכירות חדשות.</span>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        @continue
                        @endif
                        <tr>
                            <td>{{ $program->name }}</td>
                            <td class="ltr-num" title="{{ collect(\App\Models\Program::CLASS_TIERS)->map(fn ($l, $t) => $l.': ₪'.number_format($program->priceForClassTier($t), 0))->implode(' · ') }}">₪{{ number_format((float) $program->price, 0) }}</td>
                            <td>@if ($program->is_premium)<span class="badge badge-primary">פרימיום</span>@else —@endif</td>
                            <td>{{ $program->contractTemplate?->is_active ? $program->contractTemplate->name : 'החוזה הסטנדרטי' }}</td>
                            <td>
                                @if ($program->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td class="actions"><button type="button" wire:click="editItem('program', {{ $program->id }})" class="btn btn-ghost btn-sm">עריכה</button><button type="button" wire:click="toggleProgram({{ $program->id }})" class="btn btn-ghost btn-sm">{{ $program->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </section>

    {{-- ===== מארזים ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <div>
                <h2>מארזים</h2>
            </div>
            <div style="display:flex; gap:var(--sp-sm)">
                <x-modal trigger-label="+ מארז חדש" title="מארז חדש">
                    <form wire:submit="addBundle" class="form-grid">
                        <div class="full">
                            <label for="bundleName">שם</label>
                            <input type="text" id="bundleName" wire:model="bundleName">
                            @error('bundleName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div class="full">
                            <label for="bundleDescription">תיאור</label>
                            <textarea id="bundleDescription" wire:model="bundleDescription" rows="2"></textarea>
                        </div>
                        <div class="full tier-prices">
                            <div><label for="bundlePrice">מחיר 1–2 כיתות</label><input type="text" id="bundlePrice" wire:model="bundlePrice" class="ltr-num" dir="ltr" placeholder="₪ 0"> @error('bundlePrice') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                            <div><label for="bundlePrice34">מחיר 3–4 כיתות</label><input type="text" id="bundlePrice34" wire:model="bundlePrice34" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('bundlePrice34') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                            <div><label for="bundlePrice56">מחיר 5–6 כיתות</label><input type="text" id="bundlePrice56" wire:model="bundlePrice56" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('bundlePrice56') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                        </div>
                        <div class="full">
                            <span class="checkbox-row"><input type="checkbox" id="bundleIsSubscriptionType" wire:model.live="bundleIsSubscriptionType"><label for="bundleIsSubscriptionType" style="margin:0">מקנה מנוי שנתי</label></span>
                        </div>
                        @if ($bundleIsSubscriptionType)
                            <div class="full">
                                <label for="bundleSchoolYear">שנת לימודים</label>
                                <select id="bundleSchoolYear" wire:model="bundleSchoolYear">
                                    <option value="">— בחרו שנה —</option>
                                    @foreach ($this->schoolYearOptions() as $year => $label)
                                        <option value="{{ $year }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">המנוי של כל לקוחה שתקנה את המארז ירוץ בחודשי שנת הלימודים הזו ({{ \App\Services\HebrewCalendar::MONTHS[\App\Services\HebrewCalendar::startMonth()] }} עד 10 חודשים אחריו), גם אם נמכר מראש.</div>
                                @error('bundleSchoolYear') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                        @endif
                        <div class="full">
                            <label for="bundleContractTemplateId">חוזה ברירת מחדל</label>
                            <select id="bundleContractTemplateId" wire:model="bundleContractTemplateId">
                                <option value="">— החוזה הסטנדרטי —</option>
                                @foreach ($this->contractTemplates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                            <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">החוזה שנשלח יחד עם טופס ההזמנה בעסקה על פריט זה.</div>
                            @error('bundleContractTemplateId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div class="full"><button type="submit" class="btn btn-primary">יצירת מארז</button></div>
                    </form>
                </x-modal>
                <x-modal trigger-label="+ שיוך למארז" trigger-class="btn btn-secondary" title="שיוך תוכנית או מארז למארז">
                    <form wire:submit="addBundleProgram" class="form-grid">
                        <div class="full">
                            <label for="membershipBundleId">מארז</label>
                            <select id="membershipBundleId" wire:model.live="membershipBundleId">
                                <option value="">בחרו מארז</option>
                                @foreach ($this->bundles as $bundle)
                                    <option value="{{ $bundle->id }}">{{ $bundle->name }}</option>
                                @endforeach
                            </select>
                            @error('membershipBundleId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        @php $membershipParent = $this->bundles->firstWhere('id', (int) $membershipBundleId); @endphp
                        <div class="full">
                            <label for="membershipItem">{{ $membershipParent?->is_subscription_type ? 'תוכנית או מארז' : 'תוכנית' }}</label>
                            <select id="membershipItem" wire:model="membershipItem">
                                <option value="">{{ $membershipParent?->is_subscription_type ? 'בחרו תוכנית או מארז' : 'בחרו תוכנית' }}</option>
                                <optgroup label="תוכניות">
                                    @foreach ($this->programs as $program)
                                        <option value="program:{{ $program->id }}">{{ $program->name }}</option>
                                    @endforeach
                                </optgroup>
                                @if ($membershipParent?->is_subscription_type)
                                    <optgroup label="מארזים">
                                        @foreach ($this->bundles->where('is_subscription_type', false) as $childBundle)
                                            <option value="bundle:{{ $childBundle->id }}">{{ $childBundle->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                            @if ($membershipParent?->is_subscription_type)
                                <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">מארז מנוי יכול לכלול גם מארזים — כל התוכניות שבהם נחשבות חלק מהמנוי.</div>
                            @endif
                            @error('membershipProgramId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div class="full"><button type="submit" class="btn btn-primary">הוספה למארז</button></div>
                    </form>
                </x-modal>
            </div>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <div class="table-scroll">
            <table class="catalog-table">
                <thead><tr><th>שם</th><th>מחיר</th><th>סוג</th><th>תוכניות ומארזים כלולים</th><th>חוזה</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->bundles as $bundle)
                        @if ($editingItem === "bundle:{$bundle->id}")
                        <tr>
                            <td colspan="7" class="edit-cell">
                                <form wire:submit="saveItem" class="form-grid catalog-edit">
                                    <div><label>שם</label><input type="text" wire:model="editName"> @error('editName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div><label>מחיר 1–2 כיתות</label><input type="text" wire:model="editPrice" class="ltr-num" dir="ltr"> @error('editPrice') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div><label>מחיר 3–4 כיתות</label><input type="text" wire:model="editPrice34" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('editPrice34') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div><label>מחיר 5–6 כיתות</label><input type="text" wire:model="editPrice56" class="ltr-num" dir="ltr" placeholder="כמו 1–2"> @error('editPrice56') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div class="full"><label>תיאור</label><textarea wire:model="editDescription" rows="1"></textarea></div>
                                    <div class="half"><label for="editContractTemplateId">חוזה ברירת מחדל</label>
                                        <select id="editContractTemplateId" wire:model="editContractTemplateId">
                                            <option value="">— החוזה הסטנדרטי —</option>
                                            @foreach ($this->contractTemplates as $template)
                                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('editContractTemplateId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div class="half" style="align-self:end; padding-bottom:10px"><span class="checkbox-row"><input type="checkbox" id="editFlag" wire:model.live="editFlag"><label for="editFlag" style="margin:0">מקנה מנוי שנתי</label></span></div>
                                    @if ($editFlag)
                                        <div class="half"><label for="editSchoolYear">שנת לימודים</label>
                                            <select id="editSchoolYear" wire:model="editSchoolYear">
                                                <option value="">— בחרו שנה —</option>
                                                @foreach ($this->schoolYearOptions($bundle->school_year) as $year => $label)
                                                    <option value="{{ $year }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('editSchoolYear') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                            <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">שינוי השנה חל על מכירות חדשות בלבד — מנויים שכבר נמכרו שומרים על החודשים שלהם.</div></div>
                                    @endif
                                    <div class="full catalog-edit-actions">
                                        <button type="submit" class="btn btn-primary btn-sm">שמירה</button>
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelItemEdit">ביטול</button>
                                        <span class="text-text-secondary">מחיר ריק = כמו 1–2 כיתות. שינוי שם או מחיר חל רק על מכירות חדשות.</span>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        @continue
                        @endif
                        <tr>
                            <td>{{ $bundle->name }}</td>
                            <td class="ltr-num" title="{{ collect(\App\Models\Program::CLASS_TIERS)->map(fn ($l, $t) => $l.': ₪'.number_format($bundle->priceForClassTier($t), 0))->implode(' · ') }}">₪{{ number_format((float) $bundle->price, 0) }}</td>
                            <td>@if ($bundle->is_subscription_type)<span class="badge" style="background:#92600D22; color:#92600D">מנוי שנתי</span>@if ($bundle->school_year)<div style="font-size:var(--fs-caption); margin-top:2px">{{ $bundle->schoolYearLabel() }}@if ($bundle->schoolYearHasEnded()) <span class="badge badge-neutral">השנה הסתיימה</span>@endif</div>@else<div style="font-size:var(--fs-caption); margin-top:2px; color:var(--color-error)">חסרה שנת לימודים</div>@endif @else —@endif</td>
                            <td>
                                <div class="chip-list">
                                    @foreach ($bundle->includedBundles as $childBundle)
                                        <span class="chip chip-bundle" title="מארז כלול — התוכניות שבו: {{ $childBundle->programs->pluck('name')->implode(', ') ?: 'אין עדיין' }}">מארז: {{ $childBundle->name }} <button type="button" wire:click="removeIncludedBundle({{ $bundle->id }}, {{ $childBundle->id }})" wire:confirm="להסיר את המארז &quot;{{ $childBundle->name }}&quot; מהמארז &quot;{{ $bundle->name }}&quot;?" style="background:none;border:0;cursor:pointer;color:inherit;font-weight:700;padding:0 0 0 4px" title="הסרה">×</button></span>
                                    @endforeach
                                    @foreach ($bundle->programs as $program)
                                        <span class="chip">{{ $program->name }} <button type="button" wire:click="removeBundleProgram({{ $bundle->id }}, {{ $program->id }})" style="background:none;border:0;cursor:pointer;color:inherit;font-weight:700;padding:0 0 0 4px" title="הסרה">×</button></span>
                                    @endforeach
                                    @if ($bundle->includedBundles->isEmpty() && $bundle->programs->isEmpty())
                                        <span class="text-text-secondary" style="font-size:var(--fs-caption)">—</span>
                                    @endif
                                </div>
                            </td>
                            <td>{{ $bundle->contractTemplate?->is_active ? $bundle->contractTemplate->name : 'החוזה הסטנדרטי' }}</td>
                            <td>
                                @if ($bundle->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td class="actions"><button type="button" wire:click="editItem('bundle', {{ $bundle->id }})" class="btn btn-ghost btn-sm">עריכה</button><button type="button" wire:click="toggleBundle({{ $bundle->id }})" class="btn btn-ghost btn-sm">{{ $bundle->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

    </section>
</div>
