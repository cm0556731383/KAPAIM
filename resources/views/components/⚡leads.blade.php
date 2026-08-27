<?php

use App\Concerns\Notifies;
use App\Models\Lead;
use App\Models\LeadSource;
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

    // ===== סינון =====
    public string $filterSchoolId = '';
    public string $filterCity = '';
    public string $filterSourceId = '';
    public string $filterStatusId = '';

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
        // leads.manage holders (owner/secretary) are unaffected, unfiltered.
        $user = auth()->user();
        abort_unless($user->can('leads.manage') || $user->can('leads.view'), 403);
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
            'newPhone' => ['required', 'string', 'max:50'],
            'newSchoolName' => ['nullable', 'string', 'max:255'],
            'newSchoolPhone' => ['nullable', 'string', 'max:50'],
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
                    $this->leadError = "קיים כבר ליד עבור \"{$exactSchool->name}\" (ליד #{$existingLead->id}) — הפנייה נרשמה על הליד הקיים ולא נוצר ליד כפול (FR-1.9).";

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

        // FR-1.17 — stub, see Lead::joinPrimaryMailingList() (stage 10).
        $lead->joinPrimaryMailingList();

        $activityLogger->log('lead.created', 'נוצר ליד חדש: '.($school?->name ?? $lead->email), [
            'lead_id' => $lead->id,
            'school_id' => $school?->id,
        ]);

        $this->reset(['newSchoolName', 'newSchoolPhone', 'newEmail', 'newPhone', 'newSourceId', 'newNotes']);
        unset($this->leads);

        $this->notifySuccess('ליד חדש נוצר בהצלחה.');
    }

    #[Computed]
    public function leads()
    {
        $user = auth()->user();

        return Lead::query()
            ->with(['school', 'status', 'source', 'assignedUser', 'interestedPrograms', 'followUps'])
            // Build-plan 13 (FR-7.2): a leads.manage holder sees every lead,
            // unfiltered — a leads.view-only holder (future "עובדת מכירות")
            // only sees leads assigned to them that have not yet converted
            // (FR-7.4), scoped directly in the query, not filtered in PHP.
            ->when(! $user->can('leads.manage'), fn ($q) => $q->where('assigned_user_id', $user->id)->whereNull('converted_at'))
            ->when($this->filterSchoolId !== '', fn ($q) => $q->where('school_id', $this->filterSchoolId))
            ->when($this->filterCity !== '', fn ($q) => $q->whereHas('school', fn ($sq) => $sq->where('city', $this->filterCity)))
            ->when($this->filterSourceId !== '', fn ($q) => $q->where('lead_source_id', $this->filterSourceId))
            ->when($this->filterStatusId !== '', fn ($q) => $q->where('status_id', $this->filterStatusId))
            ->orderByDesc('updated_at')
            ->get();
    }

    #[Computed]
    public function schools()
    {
        $user = auth()->user();

        return School::query()
            // Build-plan 13: the filter dropdowns must not leak other reps'
            // school names to a leads.view-only user — same scope as leads().
            ->when(! $user->can('leads.manage'), fn ($q) => $q->whereHas(
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
            ->when(! $user->can('leads.manage'), fn ($q) => $q->whereHas(
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
    public function statuses()
    {
        return StatusDefinition::where('scope', 'lead')->where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function statusCounts()
    {
        $user = auth()->user();

        return Lead::query()
            ->when(! $user->can('leads.manage'), fn ($q) => $q->where('assigned_user_id', $user->id)->whereNull('converted_at'))
            ->selectRaw('status_id, count(*) as aggregate')
            ->groupBy('status_id')
            ->pluck('aggregate', 'status_id');
    }

    /**
     * Same leads.manage/leads.view scope as leads()/statusCounts() above —
     * backs the "הכל (N)" chip.
     */
    #[Computed]
    public function totalLeadsCount()
    {
        $user = auth()->user();

        return Lead::query()
            ->when(! $user->can('leads.manage'), fn ($q) => $q->where('assigned_user_id', $user->id)->whereNull('converted_at'))
            ->count();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">לידים</h1>
            <p class="text-text-secondary m-0">SCHOOL, LEAD — קליטת פניות וניהול תהליך המכירה מול בתי ספר</p>
        </div>
    </div>

    <x-business-error-banner :message="$leadError" />
    <x-business-error-banner :message="$duplicateWarning" type="warning" />

    {{-- ===== סינון ===== --}}
    <div class="filter-row">
        <div>
            <label for="filterSchoolId">בית ספר</label>
            <select id="filterSchoolId" wire:model.live="filterSchoolId">
                <option value="">כל בתי הספר</option>
                @foreach ($this->schools as $school)
                    <option value="{{ $school->id }}">{{ $school->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filterCity">עיר</label>
            <select id="filterCity" wire:model.live="filterCity">
                <option value="">כל הערים</option>
                @foreach ($this->cities as $city)
                    <option value="{{ $city }}">{{ $city }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filterSourceId">מקור פנייה</label>
            <select id="filterSourceId" wire:model.live="filterSourceId">
                <option value="">כל המקורות</option>
                @foreach ($this->sources as $source)
                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filterStatusId">סטטוס</label>
            <select id="filterStatusId" wire:model.live="filterStatusId">
                <option value="">כל הסטטוסים</option>
                @foreach ($this->statuses as $status)
                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="filters">
        <span class="chip {{ $filterStatusId === '' ? 'active' : '' }}" wire:click="$set('filterStatusId', '')">הכל ({{ $this->totalLeadsCount }})</span>
        @foreach ($this->statuses as $status)
            <span class="chip {{ (string) $filterStatusId === (string) $status->id ? 'active' : '' }}" wire:click="$set('filterStatusId', '{{ $status->id }}')">{{ $status->name }} ({{ $this->statusCounts[$status->id] ?? 0 }})</span>
        @endforeach
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
                    <th>Up Follow הבא</th>
                    <th>עדכון אחרון</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->leads as $lead)
                    @php
                        $primaryContact = $lead->school?->contacts()->where('is_primary', true)->first();
                        $nextFollowUp = $lead->followUps->whereNotNull('next_at')->sortBy('next_at')->first();
                    @endphp
                    <tr class="row-link" onclick="window.location='{{ route('lead-detail', $lead) }}'">
                        <td>{{ $lead->school?->name ?? '— (ללא בית ספר) —' }}</td>
                        <td>{{ $primaryContact ? $primaryContact->name.($primaryContact->role ? ', '.$primaryContact->role : '') : '—' }}</td>
                        <td>{{ $lead->source?->name ?? '—' }}</td>
                        <td>
                            <div class="chip-list">
                                @forelse ($lead->interestedPrograms as $program)
                                    <span class="chip">{{ $program->name }}</span>
                                @empty
                                    —
                                @endforelse
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

    {{-- ===== ליד חדש ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>ליד חדש</h2>
            <p class="hint">מייל וטלפון הם השדות היחידים שחובה למלא (FR-1.2) — שאר הפרטים ניתנים להשלמה בכרטיס הליד</p>
        </div>
        <div class="card" style="max-width:640px">
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
        </div>
    </section>
</div>
