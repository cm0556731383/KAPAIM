<?php

use App\Concerns\Notifies;
use App\Models\Bundle;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Program;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.app', ['title' => 'לידים — כפיים'])]
class extends Component
{
    use Notifies;

    // ===== סינון ===== (each filter takes several values — nothing ticked means all)
    public array $filterSchoolIds = [];
    public array $filterCities = [];
    public array $filterSourceIds = [];
    public array $filterStatusIds = [];
    /** "program:{id}" / "bundle:{id}" — same encoding as ⚡lead-detail's itemToAttach. */
    public array $filterItems = [];

    // ===== ליד חדש (יצירה מהירה — FR-1.2: מייל+טלפון בלבד חובה) =====
    public string $newSchoolName = '';
    public string $newSchoolPhone = '';
    public string $newEmail = '';
    public string $newPhone = '';
    public string $newSourceId = '';
    public string $newNotes = '';

    /**
     * Business-rule error (FR-7.25 "הודעת שגיאה עסקית") — e.g. blocking a
     * duplicate creation because the school already has a lead (FR-1.9).
     */
    public ?string $leadError = null;

    /**
     * Non-blocking "possible duplicate" warning (FR-1.9, fuzzy match) —
     * shown but does not stop creation.
     */
    public ?string $duplicateWarning = null;

    public function mount(): void
    {
        // Build-plan 13: a "leads.view"-only role (future "עובדת מכירות") may
        // also open this page — the leads() query below scopes what such a
        // user actually sees to their own not-yet-converted assignments;
        // leads-all.view holders (owner/secretary) are unaffected, unfiltered.
        $user = auth()->user();
        abort_unless($user->can('leads.view'), 403);
    }

    /**
     * FR-1.2/FR-1.3/FR-1.9: creates a lead requiring only email+phone; when a
     * school name is given, "search before create" blocks an exact-duplicate
     * school (the repeat inquiry is logged on the existing lead instead) and
     * warns — without blocking — on a fuzzy-duplicate school name/phone.
     */
    public function addLead(ActivityLogger $activityLogger): void
    {
        $this->leadError = null;
        $this->duplicateWarning = null;

        $data = $this->validate([
            'newEmail' => ['required', 'email', 'max:255'],
            'newPhone' => ['required', 'string', 'max:50', new \App\Rules\Phone],
            'newSchoolName' => ['nullable', 'string', 'max:255'],
            'newSchoolPhone' => ['nullable', 'string', 'max:50', new \App\Rules\Phone],
            'newSourceId' => ['nullable', 'exists:lead_sources,id'],
            'newNotes' => ['nullable', 'string'],
        ], [], [
            'newEmail' => 'דוא"ל',
            'newPhone' => 'טלפון',
            'newSchoolName' => 'שם בית הספר',
        ]);

        $school = null;
        $schoolName = trim($data['newSchoolName'] ?? '');
        $schoolPhone = $data['newSchoolPhone'] ?: null;

        if ($schoolName !== '') {
            $exactSchool = Lead::findExactDuplicateSchool($schoolName, $schoolPhone ?? $data['newPhone']);

            if ($exactSchool) {
                $existingLead = Lead::where('school_id', $exactSchool->id)->latest()->first();

                if ($existingLead) {
                    $this->leadError = "קיים כבר ליד עבור \"{$exactSchool->name}\" — הפנייה נרשמה על הליד הקיים ולא נוצר ליד כפול.";

                    $activityLogger->log('lead.repeat_inquiry', "פנייה חוזרת מ\"{$exactSchool->name}\" נרשמה על ליד קיים #{$existingLead->id}", [
                        'lead_id' => $existingLead->id,
                        'school_id' => $exactSchool->id,
                    ]);

                    unset($this->leads);

                    return;
                }

                $school = $exactSchool;
            } else {
                $fuzzySchool = Lead::findFuzzyDuplicateSchool($schoolName, $schoolPhone);

                if ($fuzzySchool) {
                    $this->duplicateWarning = "כפילות אפשרית: קיים כבר בית ספר בשם דומה — \"{$fuzzySchool->name}\". נא לוודא שאין כפילות לפני יצירת ליד חדש.";
                    // FR-7.23 — non-blocking, doesn't stop lead creation below.
                    $this->notifyInfo($this->duplicateWarning);
                }

                $school = School::create(['name' => $schoolName, 'phone' => $schoolPhone]);
            }
        }

        $newStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $lead = Lead::create([
            'school_id' => $school?->id,
            'assigned_user_id' => auth()->id(),
            'lead_source_id' => $data['newSourceId'] ?: null,
            'status_id' => $newStatus->id,
            'email' => $data['newEmail'],
            'phone' => $data['newPhone'],
            'notes' => $data['newNotes'] ?: null,
        ]);

        $school?->fillContactDetailsIfBlank($lead->email, $lead->phone);

        // FR-1.17 — stub, see Lead::joinPrimaryMailingList() (stage 10).
        $lead->joinPrimaryMailingList();

        $activityLogger->log('lead.created', 'נוצר ליד חדש: '.($school?->name ?? $lead->email), [
            'lead_id' => $lead->id,
            'school_id' => $school?->id,
        ]);

        $this->reset(['newSchoolName', 'newSchoolPhone', 'newEmail', 'newPhone', 'newSourceId', 'newNotes']);
        unset($this->leads);

        $this->dispatch('close-modals');
        $this->notifySuccess('ליד חדש נוצר בהצלחה.');
    }

    #[Computed]
    public function leads()
    {
        $user = auth()->user();

        return Lead::query()
            ->with(['school', 'status', 'source', 'assignedUser', 'interestedPrograms', 'interestedBundles', 'followUps'])
            ->whereNull('converted_at')
            ->when(! $user->can('leads-all.view'), fn ($q) => $q->where('assigned_user_id', $user->id))
            ->when($this->filterSchoolIds, fn ($q) => $q->whereIn('school_id', array_map('intval', $this->filterSchoolIds)))
            ->when($this->filterCities, fn ($q) => $q->whereHas('school', fn ($sq) => $sq->whereIn('city', $this->filterCities)))
            ->when($this->filterSourceIds, fn ($q) => $q->whereIn('lead_source_id', array_map('intval', $this->filterSourceIds)))
            // Any of the ticked items. A program also matches a lead interested
            // in a bundle (e.g. the annual subscription) that includes it.
            ->when($this->filterItems, function ($q) {
                $programIds = $this->filterItemIds('program:');
                $bundleIds = $this->filterItemIds('bundle:');

                $q->where(fn ($outer) => $outer
                    ->when($programIds, fn ($o) => $o
                        ->orWhereHas('interestedPrograms', fn ($pq) => $pq->whereIn('programs.id', $programIds))
                        ->orWhereHas('interestedBundles.programs', fn ($pq) => $pq->whereIn('programs.id', $programIds)))
                    ->when($bundleIds, fn ($o) => $o->orWhereHas('interestedBundles', fn ($bq) => $bq->whereIn('bundles.id', $bundleIds)))
                    ->when(! $programIds && ! $bundleIds, fn ($o) => $o->whereRaw('1 = 0')));
            })
            ->when(
                $this->filterStatusIds,
                fn ($q) => $q->whereIn('status_id', array_map('intval', $this->filterStatusIds)),
                // No status explicitly chosen: default to hiding closed-lost
                // leads rather than showing every status unfiltered.
                fn ($q) => $q->whereHas('status', fn ($sq) => $sq->where('name', '!=', Lead::CLOSED_NO_SALE_STATUS_NAME)),
            )
            ->orderByDesc('updated_at')
            ->get();
    }

    /** The ids of the ticked filterItems with this prefix ("program:" / "bundle:"). */
    private function filterItemIds(string $prefix): array
    {
        return collect($this->filterItems)
            ->filter(fn ($item) => str_starts_with((string) $item, $prefix))
            ->map(fn ($item) => (int) substr($item, strlen($prefix)))
            ->values()->all();
    }

    #[Computed]
    public function schools()
    {
        $user = auth()->user();

        return School::query()
            // Build-plan 13: the filter dropdowns must not leak other reps'
            // school names to a leads.view-only user — same scope as leads().
            ->when(! $user->can('leads-all.view'), fn ($q) => $q->whereHas(
                'leads', fn ($lq) => $lq->where('assigned_user_id', $user->id)->whereNull('converted_at')
            ))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function cities()
    {
        $user = auth()->user();

        return School::whereNotNull('city')->where('city', '!=', '')
            ->when(! $user->can('leads-all.view'), fn ($q) => $q->whereHas(
                'leads', fn ($lq) => $lq->where('assigned_user_id', $user->id)->whereNull('converted_at')
            ))
            ->distinct()->orderBy('city')->pluck('city');
    }

    #[Computed]
    public function sources()
    {
        return LeadSource::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function programs()
    {
        return Program::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function bundles()
    {
        return Bundle::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function statuses()
    {
        return StatusDefinition::where('scope', 'lead')->where('is_active', true)->orderBy('sort_order')->get();
    }

};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">לידים</h1>
        </div>
        <x-modal trigger-label="+ ליד חדש" title="ליד חדש">
            <form wire:submit="addLead" class="form-grid">
                <div>
                    <label for="newEmail">דוא"ל</label>
                    <input type="text" id="newEmail" wire:model="newEmail" class="ltr-num" dir="ltr" placeholder="contact@example.com">
                    @error('newEmail') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="newPhone">טלפון</label>
                    <input type="text" id="newPhone" wire:model="newPhone" class="ltr-num" dir="ltr" placeholder="050-0000000">
                    @error('newPhone') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="newSchoolName">שם בית הספר (אופציונלי)</label>
                    <input type="text" id="newSchoolName" wire:model="newSchoolName" placeholder="למשל: בית ספר יובלים">
                    @error('newSchoolName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="newSchoolPhone">טלפון בית הספר (אופציונלי)</label>
                    <input type="text" id="newSchoolPhone" wire:model="newSchoolPhone" class="ltr-num" dir="ltr">
                </div>
                <div>
                    <label for="newSourceId">מקור פנייה</label>
                    <select id="newSourceId" wire:model="newSourceId">
                        <option value="">— ללא —</option>
                        @foreach ($this->sources as $source)
                            <option value="{{ $source->id }}">{{ $source->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="full">
                    <label for="newNotes">הערות</label>
                    <textarea id="newNotes" wire:model="newNotes" rows="2"></textarea>
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">+ ליד חדש</button></div>
            </form>
        </x-modal>
    </div>


    {{-- ===== סינון ===== --}}
    <div class="filter-row">
        <x-multi-filter model="filterSchoolIds" label="בית ספר" all-label="כל בתי הספר" :selected="$filterSchoolIds"
            :options="$this->schools->pluck('name', 'id')->all()" />
        <x-multi-filter model="filterCities" label="עיר" all-label="כל הערים" :selected="$filterCities"
            :options="$this->cities->combine($this->cities)->all()" />
        <x-multi-filter model="filterSourceIds" label="מקור פנייה" all-label="כל המקורות" :selected="$filterSourceIds"
            :options="$this->sources->pluck('name', 'id')->all()" />
        <x-multi-filter model="filterStatusIds" label="סטטוס" all-label="כל הסטטוסים" :selected="$filterStatusIds"
            :options="$this->statuses->pluck('name', 'id')->all()" />
        <x-multi-filter model="filterItems" label="תוכנית / מארז מבוקש" all-label="כל התוכניות והמארזים" :selected="$filterItems"
            :groups="[
                'תוכניות' => $this->programs->mapWithKeys(fn ($p) => ['program:'.$p->id => $p->name])->all(),
                'מארזים' => $this->bundles->mapWithKeys(fn ($b) => ['bundle:'.$b->id => $b->name])->all(),
            ]" />
    </div>

    <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-lg)">
        <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>בית ספר</th>
                    <th>איש קשר ראשי</th>
                    <th>מקור פנייה</th>
                    <th>תוכניות מבוקשות</th>
                    <th>סטטוס</th>
                    <th>מטפלת</th>
                    <th>Follow Up הבא</th>
                    <th>עדכון אחרון</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->leads as $lead)
                    @php
                        $primaryContact = $lead->school?->contacts()->where('is_primary', true)->first();
                        $nextFollowUp = $lead->followUps->whereNotNull('next_at')->sortBy('next_at')->first();
                    @endphp
                    <tr class="row-link {{ $lead->isInterestedInSubscription() ? 'is-subscription' : '' }}" @if ($lead->isInterestedInSubscription()) title="מתעניינים במנוי" @endif onclick="window.location='{{ route('lead-detail', $lead) }}'">
                        <td>{{ $lead->school?->name ?? '— (ללא בית ספר) —' }}</td>
                        <td>{{ $primaryContact ? $primaryContact->name.($primaryContact->role ? ', '.$primaryContact->role : '') : '—' }}</td>
                        <td>{{ $lead->source?->name ?? '—' }}</td>
                        <td>
                            <div class="chip-list">
                                @foreach ($lead->interestedPrograms as $program)
                                    <span class="chip">{{ $program->name }}</span>
                                @endforeach
                                @foreach ($lead->interestedBundles as $bundle)
                                    <span class="chip">מארז: {{ $bundle->name }}</span>
                                @endforeach
                                @if ($lead->interestedPrograms->isEmpty() && $lead->interestedBundles->isEmpty())
                                    —
                                @endif
                            </div>
                        </td>
                        <td><span class="badge {{ Lead::badgeClassForStatusName($lead->status?->name) }}">{{ $lead->status?->name }}@if ($lead->sub_status) · {{ $lead->sub_status }} @endif</span></td>
                        <td>{{ $lead->assignedUser?->name ?? '—' }}</td>
                        <td class="ltr-num">{{ $nextFollowUp?->next_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="ltr-num">{{ $lead->updated_at->format('d/m/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-text-secondary">אין לידים התואמים לסינון.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

</div>
