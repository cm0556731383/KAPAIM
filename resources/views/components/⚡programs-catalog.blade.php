<?php

use App\Models\Bundle;
use App\Models\Program;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.app', ['title' => 'קטלוג תוכניות ומארזים — כפיים'])]
class extends Component
{
    // ===== תוכניות (PROGRAM) =====
    public string $programName = '';
    public string $programDescription = '';
    public string $programPrice = '';
    public bool $programIsPremium = false;
    public bool $programIsSubscriptionType = false;

    // ===== מארזים (BUNDLE) =====
    public string $bundleName = '';
    public string $bundleDescription = '';
    public string $bundlePrice = '';

    // ===== שיוך תוכניות למארז =====
    public ?int $membershipBundleId = null;
    public ?int $membershipProgramId = null;

    /**
     * Business-rule error (FR-7.25 "הודעת שגיאה עסקית") — blocks an action and
     * explains why, distinct from a plain validation error on a form field.
     */
    public ?string $catalogError = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('catalog.manage'), 403);
    }

    // ----- תוכניות -----

    public function addProgram(ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;

        $data = $this->validate([
            'programName' => ['required', 'string', 'max:255', 'unique:programs,name'],
            'programDescription' => ['nullable', 'string'],
            'programPrice' => ['required', 'numeric', 'gt:0'],
            'programIsPremium' => ['boolean'],
            'programIsSubscriptionType' => ['boolean'],
        ], [], [
            'programName' => 'שם התוכנית',
            'programPrice' => 'מחיר',
        ]);

        if ($data['programIsSubscriptionType'] && $this->anotherActiveSubscriptionProgramExists()) {
            $this->catalogError = 'קיימת כבר תוכנית מנוי אחת פעילה בקטלוג. יש להשבית אותה לפני יצירת תוכנית מנוי חדשה.';

            return;
        }

        $program = Program::create([
            'name' => $data['programName'],
            'description' => $data['programDescription'] ?: null,
            'price' => $data['programPrice'],
            'is_premium' => $data['programIsPremium'],
            'is_subscription_type' => $data['programIsSubscriptionType'],
            'is_active' => true,
        ]);

        $activityLogger->log('program.created', "נוצרה תוכנית חדשה: {$program->name}");

        $this->reset(['programName', 'programDescription', 'programPrice', 'programIsPremium', 'programIsSubscriptionType']);
        unset($this->programs);
    }

    public function toggleProgram(int $id, ActivityLogger $activityLogger): void
    {
        $this->catalogError = null;

        /** @var Program $program */
        $program = Program::findOrFail($id);

        if (! $program->is_active && $program->is_subscription_type && $this->anotherActiveSubscriptionProgramExists($program->id)) {
            $this->catalogError = 'קיימת כבר תוכנית מנוי אחת פעילה בקטלוג. יש להשבית אותה לפני הפעלת תוכנית מנוי זו.';

            return;
        }

        $program->is_active = ! $program->is_active;
        $program->save();

        $verb = $program->is_active ? 'הופעלה' : 'הושבתה';
        $activityLogger->log('program.toggled', "תוכנית \"{$program->name}\" {$verb}", [
            'metadata' => ['id' => $program->id, 'is_active' => $program->is_active],
        ]);

        unset($this->programs);
    }

    /**
     * At most one active is_subscription_type program at a time (build-plan 03) —
     * this is the discriminator stage 6 (deal creation) will use to route into
     * the subscription flow (stage 9), so it must be unambiguous.
     */
    private function anotherActiveSubscriptionProgramExists(?int $excludingId = null): bool
    {
        return Program::where('is_subscription_type', true)
            ->where('is_active', true)
            ->when($excludingId, fn ($query) => $query->where('id', '!=', $excludingId))
            ->exists();
    }

    #[Computed]
    public function programs()
    {
        return Program::orderBy('name')->get();
    }

    // ----- מארזים -----

    public function addBundle(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'bundleName' => ['required', 'string', 'max:255', 'unique:bundles,name'],
            'bundleDescription' => ['nullable', 'string'],
            'bundlePrice' => ['required', 'numeric', 'gt:0'],
        ], [], [
            'bundleName' => 'שם המארז',
            'bundlePrice' => 'מחיר',
        ]);

        $bundle = Bundle::create([
            'name' => $data['bundleName'],
            'description' => $data['bundleDescription'] ?: null,
            'price' => $data['bundlePrice'],
            'is_active' => true,
        ]);

        $activityLogger->log('bundle.created', "נוצר מארז חדש: {$bundle->name}");

        $this->reset(['bundleName', 'bundleDescription', 'bundlePrice']);
        unset($this->bundles);
    }

    public function toggleBundle(int $id, ActivityLogger $activityLogger): void
    {
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

        $activityLogger->log('bundle.program_added', "תוכנית \"{$program?->name}\" נוספה למארז \"{$bundle->name}\"", [
            'metadata' => ['bundle_id' => $bundle->id, 'program_id' => $program?->id],
        ]);

        unset($this->bundles);
    }

    public function removeBundleProgram(int $bundleId, int $programId, ActivityLogger $activityLogger): void
    {
        $bundle = Bundle::findOrFail($bundleId);
        $program = Program::find($programId);

        $bundle->programs()->detach($programId);

        $activityLogger->log('bundle.program_removed', "תוכנית \"{$program?->name}\" הוסרה ממארז \"{$bundle->name}\"", [
            'metadata' => ['bundle_id' => $bundle->id, 'program_id' => $programId],
        ]);

        unset($this->bundles);
    }

    #[Computed]
    public function bundles()
    {
        return Bundle::with('programs')->orderBy('name')->get();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">קטלוג תוכניות ומארזים</h1>
            <p class="text-text-secondary m-0">PROGRAM, BUNDLE — התוכניות והמארזים שניתן למכור ללקוחות</p>
        </div>
    </div>

    <div class="mb-8" style="display:flex; align-items:center; gap:10px; background: var(--color-primary-lighter); color: var(--color-primary-hover); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
        השבתת תוכנית חוסמת <strong>רכישה חדשה</strong> שלה בלבד — היא ממשיכה להופיע בעסקאות עבר כפי שהייתה (FR-3.6, FR-8.25).
    </div>

    <x-business-error-banner :message="$catalogError" />

    {{-- ===== תוכניות ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>תוכניות</h2>
            <p class="hint">חודשיות, פרימיום, ותוכנית המנוי השנתי</p>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <table>
                <thead><tr><th>שם</th><th>מחיר</th><th>פרימיום</th><th>סוג</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->programs as $program)
                        <tr>
                            <td>{{ $program->name }}</td>
                            <td class="ltr-num">₪{{ number_format((float) $program->price, 0) }}</td>
                            <td>@if ($program->is_premium)<span class="badge badge-primary">פרימיום</span>@else —@endif</td>
                            <td>@if ($program->is_subscription_type)<span class="badge" style="background:#92600D22; color:#92600D">מנוי שנתי</span>@else —@endif</td>
                            <td>
                                @if ($program->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="toggleProgram({{ $program->id }})" class="btn btn-ghost">{{ $program->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-bottom: var(--sp-md)">
            תוכניות פרימיום נמכרות <strong>לפי פנייה בלבד</strong> ואינן חלק משיווק חוזר אוטומטי (FR-3.10). ללקוחה עם מנוי פעיל — הנחה אוטומטית של 10% על פרימיום.
        </p>

        <div class="card" style="max-width:640px">
            <h3>תוכנית חדשה</h3>
            <p class="hint text-text-secondary" style="font-size:var(--fs-caption); margin-top:-6px">מחיר חייב להיות גדול מ-0 (FR-8.6)</p>
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
                <div>
                    <label for="programPrice">מחיר</label>
                    <input type="text" id="programPrice" wire:model="programPrice" class="ltr-num" dir="ltr" placeholder="₪ 0">
                    @error('programPrice') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>&nbsp;</label>
                    <div style="display:flex; flex-direction:column; gap:8px; padding-top:6px">
                        <span class="check-row"><input type="checkbox" id="programIsPremium" wire:model="programIsPremium"><label for="programIsPremium" style="margin:0">תוכנית פרימיום (is_premium)</label></span>
                        <span class="check-row"><input type="checkbox" id="programIsSubscriptionType" wire:model="programIsSubscriptionType"><label for="programIsSubscriptionType" style="margin:0">מקנה מנוי שנתי (is_subscription_type)</label></span>
                    </div>
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">יצירת תוכנית</button></div>
            </form>
        </div>
    </section>

    {{-- ===== מארזים ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>מארזים</h2>
            <p class="hint">רכישה חד-פעמית עצמאית, לא נכללת בחישוב קיזוז מנוי (FR-3.8)</p>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <table>
                <thead><tr><th>שם</th><th>מחיר</th><th>תוכניות כלולות</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->bundles as $bundle)
                        <tr>
                            <td>{{ $bundle->name }}</td>
                            <td class="ltr-num">₪{{ number_format((float) $bundle->price, 0) }}</td>
                            <td>
                                <div class="chip-list">
                                    @forelse ($bundle->programs as $program)
                                        <span class="chip">{{ $program->name }} <button type="button" wire:click="removeBundleProgram({{ $bundle->id }}, {{ $program->id }})" style="background:none;border:0;cursor:pointer;color:inherit;font-weight:700;padding:0 0 0 4px" title="הסרה">×</button></span>
                                    @empty
                                        <span class="text-text-secondary" style="font-size:var(--fs-caption)">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                @if ($bundle->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="toggleBundle({{ $bundle->id }})" class="btn btn-ghost">{{ $bundle->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="cols2">
            <div class="card">
                <h3>מארז חדש</h3>
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
                    <div class="full">
                        <label for="bundlePrice">מחיר</label>
                        <input type="text" id="bundlePrice" wire:model="bundlePrice" class="ltr-num" dir="ltr" placeholder="₪ 0">
                        @error('bundlePrice') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">יצירת מארז</button></div>
                </form>
            </div>

            <div class="card">
                <h3>שיוך תוכנית למארז</h3>
                <form wire:submit="addBundleProgram" class="form-grid">
                    <div class="full">
                        <label for="membershipBundleId">מארז</label>
                        <select id="membershipBundleId" wire:model="membershipBundleId">
                            <option value="">בחרו מארז</option>
                            @foreach ($this->bundles as $bundle)
                                <option value="{{ $bundle->id }}">{{ $bundle->name }}</option>
                            @endforeach
                        </select>
                        @error('membershipBundleId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <label for="membershipProgramId">תוכנית</label>
                        <select id="membershipProgramId" wire:model="membershipProgramId">
                            <option value="">בחרו תוכנית</option>
                            @foreach ($this->programs as $program)
                                <option value="{{ $program->id }}">{{ $program->name }}</option>
                            @endforeach
                        </select>
                        @error('membershipProgramId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-secondary">הוספת תוכנית למארז</button></div>
                </form>
            </div>
        </div>
    </section>
</div>
