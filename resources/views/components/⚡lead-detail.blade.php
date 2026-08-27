<?php

use App\Models\Contact;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadInteraction;
use App\Models\LeadSource;
use App\Models\Program;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.app', ['title' => 'כרטיס ליד — כפיים'])]
class extends Component
{
    public Lead $lead;

    // ===== פרטי בית ספר (עריכה) =====
    public bool $editingSchool = false;
    public string $schoolName = '';
    public string $schoolCity = '';
    public string $schoolAddress = '';
    public string $schoolPhone = '';
    public string $schoolEmail = '';

    // ===== פרטי ליד =====
    public string $leadSourceId = '';
    public string $leadNotes = '';

    // ===== שיוך ליד (FR-7.5 — הקצאה ידנית, leads.manage בלבד) =====
    public string $assignedUserId = '';

    /** @see Lead::findFuzzyDuplicateSchool() */
    public ?string $duplicateWarning = null;

    /**
     * Business-rule error (FR-7.25) from Lead::convertToCustomer() — e.g.
     * blocking a re-conversion (FR-1.15) or a conversion with no school yet.
     */
    public ?string $conversionError = null;

    // ===== סטטוס =====
    public string $selectedStatusId = '';
    public string $selectedSubStatus = '';

    // ===== תוכניות מבוקשות =====
    public string $programToAttach = '';

    // ===== אנשי קשר =====
    public string $contactName = '';
    public string $contactRole = '';
    public string $contactPhone = '';
    public string $contactPhoneSecondary = '';
    public string $contactEmail = '';
    public string $contactEmailSecondary = '';
    public bool $contactIsPrimary = false;
    public bool $contactIsAccountingContact = false;
    public ?int $editingContactId = null;

    // ===== אינטראקציות (LEAD_INTERACTION) =====
    public string $interactionType = '';
    public string $interactionSummary = '';
    public string $interactionResult = '';

    // ===== Up Follow (FOLLOW_UP) =====
    public string $followUpSummary = '';
    public string $followUpResult = '';
    public string $followUpNextAt = '';

    // ===== תזכורות אישיות (TASK) =====
    public string $taskTitle = '';
    public string $taskDescription = '';
    public string $taskDueAt = '';

    public function mount(Lead $lead): void
    {
        // Build-plan 13: resource-level gate first (leads.manage OR
        // leads.view — a future "עובדת מכירות" holds only the latter), then
        // the record-level LeadPolicy::view() check (a non-dotted ability,
        // so it falls through Gate::before to the actual Policy) — this is
        // what enforces "own assigned, not-yet-converted leads only" for a
        // leads.view-only user, and revokes access the instant FR-7.4 fires.
        $user = auth()->user();
        abort_unless($user->can('leads.manage') || $user->can('leads.view'), 403);
        abort_unless($user->can('view', $lead), 403);

        $this->lead = $lead->load('school');
        $this->syncSchoolFields();
        $this->leadSourceId = (string) ($lead->lead_source_id ?? '');
        $this->leadNotes = (string) $lead->notes;
        $this->selectedStatusId = (string) $lead->status_id;
        $this->selectedSubStatus = (string) $lead->sub_status;
        $this->assignedUserId = (string) ($lead->assigned_user_id ?? '');
    }

    /**
     * FR-1.15/FR-2.3/FR-8.2: the only place a lead becomes a customer — see
     * Lead::convertToCustomer() for the actual business rules/logging.
     */
    public function convertToCustomer(ActivityLogger $activityLogger): void
    {
        $this->conversionError = null;

        try {
            $customer = $this->lead->convertToCustomer($activityLogger);
        } catch (\RuntimeException $e) {
            $this->conversionError = $e->getMessage();

            return;
        }

        $this->redirect(route('customer-detail', $customer), navigate: false);
    }

    private function syncSchoolFields(): void
    {
        $school = $this->lead->school;
        $this->schoolName = $school?->name ?? '';
        $this->schoolCity = $school?->city ?? '';
        $this->schoolAddress = $school?->address ?? '';
        $this->schoolPhone = $school?->phone ?? '';
        $this->schoolEmail = $school?->email ?? '';
    }

    // ----- פרטי בית ספר -----

    public function saveSchool(ActivityLogger $activityLogger): void
    {
        $this->duplicateWarning = null;

        $data = $this->validate([
            'schoolName' => ['required', 'string', 'max:255'],
            'schoolCity' => ['nullable', 'string', 'max:255'],
            'schoolAddress' => ['nullable', 'string', 'max:255'],
            'schoolPhone' => ['nullable', 'string', 'max:50'],
            'schoolEmail' => ['nullable', 'email', 'max:255'],
        ], [], ['schoolName' => 'שם המוסד']);

        $school = $this->lead->school;

        if (! $school) {
            // FR-1.9 "search before create" also applies when a school is
            // attached to an existing lead for the first time.
            $exact = Lead::findExactDuplicateSchool($data['schoolName'], $data['schoolPhone'] ?: null);

            if ($exact) {
                $school = $exact;
            } else {
                $fuzzy = Lead::findFuzzyDuplicateSchool($data['schoolName'], $data['schoolPhone'] ?: null);

                if ($fuzzy) {
                    $this->duplicateWarning = "כפילות אפשרית: קיים כבר בית ספר בשם דומה — \"{$fuzzy->name}\". נא לוודא שאין כפילות.";
                }

                $school = School::create(['name' => $data['schoolName']]);
            }

            $this->lead->update(['school_id' => $school->id]);
        }

        $school->update([
            'name' => $data['schoolName'],
            'city' => $data['schoolCity'] ?: null,
            'address' => $data['schoolAddress'] ?: null,
            'phone' => $data['schoolPhone'] ?: null,
            'email' => $data['schoolEmail'] ?: null,
        ]);

        $activityLogger->log('lead.school_updated', "עודכנו פרטי בית ספר עבור ליד #{$this->lead->id}: {$school->name}", [
            'lead_id' => $this->lead->id,
            'school_id' => $school->id,
        ]);

        $this->lead->refresh()->load('school');
        $this->syncSchoolFields();
        $this->editingSchool = false;
    }

    // ----- פרטי ליד (מקור, הערות) -----

    public function saveLeadDetails(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'leadSourceId' => ['nullable', 'exists:lead_sources,id'],
            'leadNotes' => ['nullable', 'string'],
        ]);

        $this->lead->update([
            'lead_source_id' => $data['leadSourceId'] ?: null,
            'notes' => $data['leadNotes'] ?: null,
        ]);

        $activityLogger->log('lead.updated', "עודכנו פרטי ליד #{$this->lead->id}", ['lead_id' => $this->lead->id]);

        $this->lead->refresh();
    }

    // ----- שיוך ליד (FR-7.5) -----

    /**
     * Manual half of FR-7.5 (the landing-page-automatic half is stage 12's
     * territory). Gated on leads.manage only — a "עובדת מכירות" holding just
     * leads.view has no route to this method at all (no control rendered
     * for her, and this check re-verifies it server-side regardless).
     */
    public function assignUser(ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('leads.manage'), 403);

        $data = $this->validate(['assignedUserId' => ['nullable', 'exists:users,id']]);

        $oldUser = $this->lead->assignedUser;
        $newUserId = $data['assignedUserId'] ?: null;

        $this->lead->update(['assigned_user_id' => $newUserId]);
        $newUser = $newUserId ? User::find($newUserId) : null;

        $activityLogger->log('lead.assigned', "ליד #{$this->lead->id} שויך מ\"{$oldUser?->name}\" ל\"{$newUser?->name}\"", [
            'lead_id' => $this->lead->id,
            'metadata' => ['old_user_id' => $oldUser?->id, 'new_user_id' => $newUserId],
        ]);

        $this->lead->refresh();
    }

    #[Computed]
    public function activeUsers()
    {
        return User::where('is_active', true)->orderBy('name')->get();
    }

    // ----- סטטוס (FR-1.4, FR-1.5, FR-1.6, FR-1.8) -----

    public function updateStatus(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'selectedStatusId' => ['required', 'exists:status_definitions,id'],
            'selectedSubStatus' => ['nullable', 'string', 'max:255'],
        ]);

        $oldStatusName = $this->lead->status?->name;
        $oldSubStatus = $this->lead->sub_status;

        $newStatus = StatusDefinition::findOrFail($data['selectedStatusId']);
        $isYellow = Lead::trafficLightColorForStatusName($newStatus->name) === 'yellow';
        $newSubStatus = $isYellow ? ($data['selectedSubStatus'] ?: null) : null;

        $this->lead->update(['status_id' => $newStatus->id, 'sub_status' => $newSubStatus]);

        $description = "סטטוס ליד #{$this->lead->id} שונה מ\"{$oldStatusName}\" ל\"{$newStatus->name}\"";
        if ($newSubStatus) {
            $description .= " (תת-סטטוס: {$newSubStatus})";
        }

        $activityLogger->log('lead.status_changed', $description, [
            'lead_id' => $this->lead->id,
            'metadata' => [
                'old_status' => $oldStatusName, 'new_status' => $newStatus->name,
                'old_sub_status' => $oldSubStatus, 'new_sub_status' => $newSubStatus,
            ],
        ]);

        $this->lead->refresh();
        $this->selectedSubStatus = (string) $this->lead->sub_status;
    }

    // ----- תוכניות מבוקשות (LEAD }o--o{ PROGRAM) -----

    public function attachProgram(ActivityLogger $activityLogger): void
    {
        $data = $this->validate(['programToAttach' => ['required', 'exists:programs,id']], [], ['programToAttach' => 'תוכנית']);

        if ($this->lead->interestedPrograms()->where('programs.id', $data['programToAttach'])->exists()) {
            $this->reset('programToAttach');

            return;
        }

        $this->lead->interestedPrograms()->attach($data['programToAttach']);
        $program = Program::find($data['programToAttach']);

        $activityLogger->log('lead.program_interest_added', "התעניינות בתוכנית \"{$program?->name}\" נוספה לליד #{$this->lead->id}", ['lead_id' => $this->lead->id]);

        $this->reset('programToAttach');
        $this->lead->refresh();
    }

    public function removeProgram(int $programId, ActivityLogger $activityLogger): void
    {
        $program = Program::find($programId);
        $this->lead->interestedPrograms()->detach($programId);

        $activityLogger->log('lead.program_interest_removed', "התעניינות בתוכנית \"{$program?->name}\" הוסרה מליד #{$this->lead->id}", ['lead_id' => $this->lead->id]);

        $this->lead->refresh();
    }

    // ----- אנשי קשר (CONTACT) -----

    public function addContact(ActivityLogger $activityLogger): void
    {
        if (! $this->lead->school) {
            $this->addError('contactName', 'יש להוסיף פרטי בית ספר לפני הוספת אנשי קשר.');

            return;
        }

        $data = $this->validateContact();

        $contact = Contact::create(array_merge($data, ['school_id' => $this->lead->school->id]));

        $activityLogger->log('contact.created', "נוסף איש קשר \"{$contact->name}\" לבית ספר \"{$this->lead->school->name}\"", [
            'lead_id' => $this->lead->id, 'school_id' => $this->lead->school->id,
        ]);

        $this->resetContactForm();
        unset($this->contacts);
    }

    public function editContact(int $id): void
    {
        $contact = Contact::findOrFail($id);
        $this->editingContactId = $id;
        $this->contactName = $contact->name;
        $this->contactRole = (string) $contact->role;
        $this->contactPhone = (string) $contact->phone;
        $this->contactPhoneSecondary = (string) $contact->phone_secondary;
        $this->contactEmail = (string) $contact->email;
        $this->contactEmailSecondary = (string) $contact->email_secondary;
        $this->contactIsPrimary = $contact->is_primary;
        $this->contactIsAccountingContact = $contact->is_accounting_contact;
    }

    public function updateContact(ActivityLogger $activityLogger): void
    {
        $contact = Contact::findOrFail($this->editingContactId);
        $data = $this->validateContact();

        $contact->update($data);

        $activityLogger->log('contact.updated', "עודכן איש קשר \"{$contact->name}\"", [
            'lead_id' => $this->lead->id, 'school_id' => $contact->school_id,
        ]);

        $this->resetContactForm();
        unset($this->contacts);
    }

    public function cancelContactEdit(): void
    {
        $this->resetContactForm();
    }

    /**
     * Removal is a logical delete (deleted_at), never a real one, and is
     * logged to ACTIVITY_LOG (build-plan 04, 1.3.14).
     */
    public function removeContact(int $id, ActivityLogger $activityLogger): void
    {
        $contact = Contact::findOrFail($id);
        $name = $contact->name;
        $schoolId = $contact->school_id;
        $contact->delete();

        $activityLogger->log('contact.removed', "הוסר איש קשר \"{$name}\" (מחיקה לוגית)", [
            'lead_id' => $this->lead->id, 'school_id' => $schoolId,
        ]);

        if ($this->editingContactId === $id) {
            $this->resetContactForm();
        }

        unset($this->contacts);
    }

    private function validateContact(): array
    {
        $data = $this->validate([
            'contactName' => ['required', 'string', 'max:255'],
            'contactRole' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'contactPhoneSecondary' => ['nullable', 'string', 'max:50'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactEmailSecondary' => ['nullable', 'email', 'max:255'],
            'contactIsPrimary' => ['boolean'],
            'contactIsAccountingContact' => ['boolean'],
        ], [], ['contactName' => 'שם מלא']);

        return [
            'name' => $data['contactName'],
            'role' => $data['contactRole'] ?: null,
            'phone' => $data['contactPhone'] ?: null,
            'phone_secondary' => $data['contactPhoneSecondary'] ?: null,
            'email' => $data['contactEmail'] ?: null,
            'email_secondary' => $data['contactEmailSecondary'] ?: null,
            'is_primary' => $data['contactIsPrimary'],
            'is_accounting_contact' => $data['contactIsAccountingContact'],
        ];
    }

    private function resetContactForm(): void
    {
        $this->reset([
            'editingContactId', 'contactName', 'contactRole', 'contactPhone',
            'contactPhoneSecondary', 'contactEmail', 'contactEmailSecondary',
            'contactIsPrimary', 'contactIsAccountingContact',
        ]);
    }

    // ----- אינטראקציות (LEAD_INTERACTION) -----

    public function addInteraction(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'interactionType' => ['required', 'string', 'max:255'],
            'interactionSummary' => ['nullable', 'string'],
            'interactionResult' => ['nullable', 'string'],
        ], [], ['interactionType' => 'סוג אינטראקציה']);

        LeadInteraction::create([
            'lead_id' => $this->lead->id,
            'user_id' => auth()->id(),
            'interaction_type' => $data['interactionType'],
            'summary' => $data['interactionSummary'] ?: null,
            'result' => $data['interactionResult'] ?: null,
            'occurred_at' => now(),
        ]);

        $activityLogger->log('lead.interaction_logged', "תועדה אינטראקציה עבור ליד #{$this->lead->id}: {$data['interactionType']}", ['lead_id' => $this->lead->id]);

        $this->reset(['interactionType', 'interactionSummary', 'interactionResult']);
        unset($this->interactions, $this->timeline);
    }

    // ----- Up Follow (FOLLOW_UP) -----

    public function addFollowUp(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'followUpSummary' => ['nullable', 'string'],
            'followUpResult' => ['nullable', 'string'],
            'followUpNextAt' => ['nullable', 'date'],
        ]);

        FollowUp::create([
            'lead_id' => $this->lead->id,
            'user_id' => auth()->id(),
            'occurred_at' => now(),
            'summary' => $data['followUpSummary'] ?: null,
            'result' => $data['followUpResult'] ?: null,
            'next_at' => $data['followUpNextAt'] ?: null,
        ]);

        $activityLogger->log('lead.follow_up_logged', "נרשם Up Follow עבור ליד #{$this->lead->id}", ['lead_id' => $this->lead->id]);

        $this->reset(['followUpSummary', 'followUpResult', 'followUpNextAt']);
        unset($this->followUps, $this->timeline);
    }

    // ----- תזכורות אישיות (TASK) -----

    public function addTask(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDescription' => ['nullable', 'string'],
            'taskDueAt' => ['nullable', 'date'],
        ], [], ['taskTitle' => 'כותרת']);

        $task = Task::create([
            'user_id' => auth()->id(),
            'lead_id' => $this->lead->id,
            'task_type' => 'reminder',
            'status' => 'open',
            'title' => $data['taskTitle'],
            'description' => $data['taskDescription'] ?: null,
            'due_at' => $data['taskDueAt'] ?: null,
        ]);

        $activityLogger->log('task.created', "נוצרה תזכורת \"{$task->title}\" עבור ליד #{$this->lead->id}", ['lead_id' => $this->lead->id, 'task_id' => $task->id]);

        $this->reset(['taskTitle', 'taskDescription', 'taskDueAt']);
        unset($this->tasks);
    }

    public function completeTask(int $id, ActivityLogger $activityLogger): void
    {
        $task = Task::findOrFail($id);
        $task->update(['status' => 'done', 'completed_at' => now()]);

        $activityLogger->log('task.completed', "הושלמה תזכורת \"{$task->title}\"", ['lead_id' => $this->lead->id, 'task_id' => $task->id]);

        unset($this->tasks);
    }

    /**
     * Cancelling a task is a logical delete (deleted_at) — same convention
     * as Contact::delete() above — never a real one, and is logged.
     */
    public function cancelTask(int $id, ActivityLogger $activityLogger): void
    {
        $task = Task::findOrFail($id);
        $title = $task->title;
        $task->delete();

        $activityLogger->log('task.cancelled', "בוטלה תזכורת \"{$title}\" (מחיקה לוגית)", ['lead_id' => $this->lead->id, 'task_id' => $id]);

        unset($this->tasks);
    }

    #[Computed]
    public function contacts()
    {
        return $this->lead->school?->contacts()->orderByDesc('is_primary')->orderBy('name')->get() ?? collect();
    }

    #[Computed]
    public function interactions()
    {
        return $this->lead->interactions()->orderByDesc('occurred_at')->get();
    }

    #[Computed]
    public function followUps()
    {
        return $this->lead->followUps()->orderByDesc('occurred_at')->get();
    }

    #[Computed]
    public function tasks()
    {
        return $this->lead->tasks()->orderByDesc('created_at')->get();
    }

    #[Computed]
    public function availablePrograms()
    {
        $attachedIds = $this->lead->interestedPrograms()->pluck('programs.id');

        return Program::where('is_active', true)->whereNotIn('id', $attachedIds)->orderBy('name')->get();
    }

    #[Computed]
    public function leadStatuses()
    {
        return StatusDefinition::where('scope', 'lead')->where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function leadSources()
    {
        return LeadSource::where('is_active', true)->orderBy('name')->get();
    }

    /**
     * Merges LEAD_INTERACTION + FOLLOW_UP + the lead's own creation into one
     * chronological feed for the "היסטוריית פעילות ו-Up Follow" card
     * (docs/storyboard/lead-detail.html).
     */
    #[Computed]
    public function timeline()
    {
        $items = collect();

        $items->push([
            'date' => $this->lead->created_at,
            'title' => 'ליד נוצר',
            'body' => $this->lead->source?->name ? "התקבל דרך {$this->lead->source->name}." : 'נוצר ידנית.',
        ]);

        foreach ($this->interactions as $interaction) {
            $items->push([
                'date' => $interaction->occurred_at,
                'title' => $interaction->interaction_type,
                'body' => trim(($interaction->summary ?? '').($interaction->result ? ' — '.$interaction->result : '')),
            ]);
        }

        foreach ($this->followUps as $followUp) {
            $items->push([
                'date' => $followUp->occurred_at,
                'title' => 'Up Follow'.($followUp->next_at ? ' · הבא: '.$followUp->next_at->format('d/m/Y') : ''),
                'body' => trim(($followUp->summary ?? '').($followUp->result ? ' — '.$followUp->result : '')),
            ]);
        }

        return $items->sortByDesc('date')->values();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <span class="badge {{ \App\Models\Lead::badgeClassForStatusName($lead->status?->name) }}" style="margin-bottom:8px; display:inline-flex">{{ $lead->status?->name }}@if ($lead->sub_status) · {{ $lead->sub_status }} @endif</span>
            <h1>{{ $lead->school?->name ?? 'ליד #'.$lead->id.' (ללא בית ספר)' }}</h1>
            <p style="color:var(--color-text-secondary); margin:0">ליד #{{ $lead->id }} · נפתח <span class="ltr-num">{{ $lead->created_at->format('d/m/Y') }}</span> · מטפלת: {{ $lead->assignedUser?->name ?? '—' }}</p>
        </div>
        <div style="display:flex; gap:var(--sp-sm)">
            <a href="{{ route('leads') }}" class="btn btn-ghost">חזרה לרשימה</a>
            @if ($lead->customer)
                <a href="{{ route('customer-detail', $lead->customer) }}" class="btn btn-primary">מעבר לכרטיס הלקוחה</a>
            @else
                <button type="button" class="btn btn-primary" wire:click="convertToCustomer" wire:confirm="להמיר ליד זה ללקוחה? הליד יכול להיות מומר פעם אחת בלבד.">המרה ללקוחה</button>
            @endif
        </div>
    </div>

    @if ($lead->customer)
        <div class="mb-8" style="background: var(--color-info-bg); color: var(--color-info); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
            ליד זה הומר ללקוחה — <a href="{{ route('customer-detail', $lead->customer) }}" style="color:inherit; text-decoration:underline">מעבר לכרטיס הלקוחה</a>.
        </div>
    @endif

    @if ($conversionError)
        <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
            {{ $conversionError }}
        </div>
    @endif

    @if ($duplicateWarning)
        <div class="mb-8" style="background: var(--color-warning-bg); color: var(--color-warning); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
            {{ $duplicateWarning }}
        </div>
    @endif

    <div class="cols2">
        <div>
            {{-- ===== פרטי בית ספר ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <div class="contact-edit-head">
                    <h3 style="margin:0">פרטי בית ספר</h3>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$toggle('editingSchool')">{{ $editingSchool ? 'ביטול' : 'עריכה' }}</button>
                </div>

                @if ($editingSchool)
                    <form wire:submit="saveSchool" class="form-grid">
                        <div class="full">
                            <label for="schoolName">שם המוסד</label>
                            <input type="text" id="schoolName" wire:model="schoolName">
                            @error('schoolName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="schoolCity">עיר</label>
                            <input type="text" id="schoolCity" wire:model="schoolCity">
                        </div>
                        <div>
                            <label for="schoolPhone">טלפון</label>
                            <input type="text" id="schoolPhone" wire:model="schoolPhone" class="ltr-num" dir="ltr">
                        </div>
                        <div>
                            <label for="schoolEmail">דוא"ל</label>
                            <input type="text" id="schoolEmail" wire:model="schoolEmail" class="ltr-num" dir="ltr">
                            @error('schoolEmail') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="schoolAddress">כתובת</label>
                            <input type="text" id="schoolAddress" wire:model="schoolAddress">
                        </div>
                        <div class="full"><button type="submit" class="btn btn-primary">שמירת פרטי בית ספר</button></div>
                    </form>
                @else
                    <div class="field"><div class="k">שם המוסד</div><div class="v">{{ $lead->school?->name ?? '— טרם הוזן —' }}</div></div>
                    <div class="field"><div class="k">עיר</div><div class="v">{{ $lead->school?->city ?? '—' }}</div></div>
                    <div class="field"><div class="k">טלפון</div><div class="v ltr-num">{{ $lead->school?->phone ?? '—' }}</div></div>
                    <div class="field"><div class="k">דוא"ל</div><div class="v ltr-num">{{ $lead->school?->email ?? '—' }}</div></div>
                @endif
            </div>

            {{-- ===== פרטי ליד: מקור, תוכניות, הערות ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>פרטי הפנייה</h3>
                <form wire:submit="saveLeadDetails" class="form-grid">
                    <div class="full">
                        <label for="leadSourceId">מקור פנייה</label>
                        <select id="leadSourceId" wire:model="leadSourceId">
                            <option value="">— ללא —</option>
                            @foreach ($this->leadSources as $source)
                                <option value="{{ $source->id }}">{{ $source->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="full">
                        <label for="leadNotes">הערות</label>
                        <textarea id="leadNotes" wire:model="leadNotes" rows="3"></textarea>
                    </div>
                    <div class="full"><button type="submit" class="btn btn-secondary">שמירת פרטי הפנייה</button></div>
                </form>

                @can('leads.manage')
                    {{-- FR-7.5 — הקצאה ידנית של ליד; רק בעלת גישה מלאה יכולה
                    לשייך/לשנות שיוך, לא עובדת מכירות עצמה. --}}
                    <form wire:submit="assignUser" class="form-grid" style="margin-top:var(--sp-lg)">
                        <div class="full">
                            <label for="assignedUserId">מטפלת בליד</label>
                            <select id="assignedUserId" wire:model="assignedUserId">
                                <option value="">— ללא —</option>
                                @foreach ($this->activeUsers as $activeUser)
                                    <option value="{{ $activeUser->id }}">{{ $activeUser->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="full"><button type="submit" class="btn btn-secondary">עדכון שיוך</button></div>
                    </form>
                @endcan

                <h3 style="margin-top:var(--sp-lg)">תוכניות מבוקשות</h3>
                <div class="chip-list" style="margin-bottom:var(--sp-md)">
                    @forelse ($lead->interestedPrograms as $program)
                        <span class="chip">{{ $program->name }} <button type="button" wire:click="removeProgram({{ $program->id }})" style="background:none;border:0;cursor:pointer;color:inherit;font-weight:700;padding:0 0 0 4px" title="הסרה">×</button></span>
                    @empty
                        <span class="text-text-secondary" style="font-size:var(--fs-caption)">— אין תוכניות מבוקשות —</span>
                    @endforelse
                </div>
                <form wire:submit="attachProgram" style="display:flex; gap:var(--sp-sm)">
                    <select wire:model="programToAttach" style="flex:1">
                        <option value="">בחרו תוכנית</option>
                        @foreach ($this->availablePrograms as $program)
                            <option value="{{ $program->id }}">{{ $program->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-secondary">הוספה</button>
                </form>
            </div>

            {{-- ===== סטטוס ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>סטטוס ליד (רמזור)</h3>
                <form wire:submit="updateStatus" class="form-grid">
                    <div class="full">
                        <label for="selectedStatusId">סטטוס</label>
                        <div style="display:flex; align-items:center; gap:10px">
                            <select id="selectedStatusId" wire:model="selectedStatusId" style="flex:1">
                                @foreach ($this->leadStatuses as $status)
                                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                                @endforeach
                            </select>
                            @php $previewColor = \App\Models\Lead::trafficLightColorForStatusName(optional($this->leadStatuses->firstWhere('id', (int) $selectedStatusId))->name); @endphp
                            <span class="dot {{ $previewColor }}" title="רמזור: {{ $previewColor }}"></span>
                        </div>
                        @error('selectedStatusId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    @if ($previewColor === 'yellow')
                        <div class="full sub-status" style="margin-top:0">
                            <label for="selectedSubStatus">תת-סטטוס (צהוב בלבד — FR-1.6)</label>
                            <input type="text" id="selectedSubStatus" wire:model="selectedSubStatus" placeholder="למשל: ממתינה לשיחה חוזרת">
                        </div>
                    @endif
                    <div class="full"><button type="submit" class="btn btn-primary">עדכון סטטוס</button></div>
                </form>
            </div>

            {{-- ===== אנשי קשר ===== --}}
            <div class="card">
                <h3>אנשי קשר</h3>

                @forelse ($this->contacts as $contact)
                    @if ($editingContactId === $contact->id)
                        <div class="contact-item" style="border-color:var(--color-primary); box-shadow:0 0 0 3px var(--color-primary-lighter)">
                            <div class="contact-edit-head">
                                <span class="tag">עריכת איש קשר</span>
                                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-error)" wire:click="removeContact({{ $contact->id }})">הסרה</button>
                            </div>
                            <form wire:submit="updateContact" class="form-grid">
                                <div><label>שם מלא</label><input type="text" wire:model="contactName"></div>
                                <div><label>תפקיד</label><input type="text" wire:model="contactRole"></div>
                                <div><label>טלפון ראשי</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactPhone"></div>
                                <div><label>טלפון נוסף</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactPhoneSecondary"></div>
                                <div><label>דוא"ל ראשי</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactEmail"></div>
                                <div><label>דוא"ל נוסף</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactEmailSecondary"></div>
                                <div class="full checkbox-row" style="gap:var(--sp-lg)">
                                    <span class="checkbox-row"><input type="checkbox" id="contactIsPrimary" wire:model="contactIsPrimary"><label for="contactIsPrimary" style="margin:0">איש קשר ראשי</label></span>
                                    <span class="checkbox-row"><input type="checkbox" id="contactIsAccountingContact" wire:model="contactIsAccountingContact"><label for="contactIsAccountingContact" style="margin:0">גורם חשבונאי</label></span>
                                </div>
                                <div class="full" style="display:flex; gap:var(--sp-sm)">
                                    <button type="submit" class="btn btn-primary btn-sm">שמירת איש קשר</button>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelContactEdit">ביטול</button>
                                </div>
                            </form>
                        </div>
                    @else
                        <div class="contact-item">
                            <div class="contact-view">
                                <div>
                                    <div class="who">{{ $contact->name }}</div>
                                    <div class="role">{{ $contact->role }}@if ($contact->phone) · <span class="ltr-num">{{ $contact->phone }}</span> @endif</div>
                                </div>
                                <div class="actions">
                                    @if ($contact->is_primary)<span class="badge badge-primary">ראשי</span>@endif
                                    @if ($contact->is_accounting_contact)<span class="badge badge-neutral">חשבונאי</span>@endif
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="editContact({{ $contact->id }})">עריכה</button>
                                </div>
                            </div>
                        </div>
                    @endif
                @empty
                    <p class="text-text-secondary" style="font-size:var(--fs-small)">אין עדיין אנשי קשר לבית ספר זה.</p>
                @endforelse

                @if (! $editingContactId)
                    <div class="contact-item">
                        <div class="contact-edit-head"><span class="tag">איש קשר חדש</span></div>
                        <form wire:submit="addContact" class="form-grid">
                            <div><label>שם מלא</label><input type="text" wire:model="contactName">@error('contactName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                            <div><label>תפקיד</label><input type="text" wire:model="contactRole"></div>
                            <div><label>טלפון ראשי</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactPhone"></div>
                            <div><label>טלפון נוסף</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactPhoneSecondary"></div>
                            <div><label>דוא"ל ראשי</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactEmail"></div>
                            <div><label>דוא"ל נוסף</label><input type="text" class="ltr-num" dir="ltr" wire:model="contactEmailSecondary"></div>
                            <div class="full checkbox-row" style="gap:var(--sp-lg)">
                                <span class="checkbox-row"><input type="checkbox" id="newContactIsPrimary" wire:model="contactIsPrimary"><label for="newContactIsPrimary" style="margin:0">איש קשר ראשי</label></span>
                                <span class="checkbox-row"><input type="checkbox" id="newContactIsAccountingContact" wire:model="contactIsAccountingContact"><label for="newContactIsAccountingContact" style="margin:0">גורם חשבונאי</label></span>
                            </div>
                            <div class="full"><button type="submit" class="btn btn-secondary">+ הוספת איש קשר</button></div>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        <div>
            {{-- ===== היסטוריית פעילות ו-Up Follow ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>היסטוריית פעילות ו-Up Follow</h3>
                <div class="timeline">
                    @foreach ($this->timeline as $item)
                        <div class="t-item">
                            <div class="t-date ltr-num" style="direction:ltr">{{ $item['date']?->format('d/m/Y H:i') }}</div>
                            <div class="t-title">{{ $item['title'] }}</div>
                            @if ($item['body'])<div class="t-body">{{ $item['body'] }}</div>@endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ===== אינטראקציה חדשה ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>תיעוד אינטראקציה</h3>
                <form wire:submit="addInteraction" class="form-grid">
                    <div class="full">
                        <label for="interactionType">סוג</label>
                        <input type="text" id="interactionType" wire:model="interactionType" placeholder="למשל: שיחת טלפון, פגישה, מייל">
                        @error('interactionType') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><label for="interactionSummary">סיכום</label><textarea id="interactionSummary" wire:model="interactionSummary" rows="2"></textarea></div>
                    <div class="full"><label for="interactionResult">תוצאה</label><input type="text" id="interactionResult" wire:model="interactionResult"></div>
                    <div class="full"><button type="submit" class="btn btn-secondary">שמירת אינטראקציה</button></div>
                </form>
            </div>

            {{-- ===== Up Follow חדש ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>Up Follow חדש</h3>
                <form wire:submit="addFollowUp" class="form-grid">
                    <div class="full"><label for="followUpSummary">סיכום</label><textarea id="followUpSummary" wire:model="followUpSummary" rows="2"></textarea></div>
                    <div class="full"><label for="followUpResult">תוצאה</label><input type="text" id="followUpResult" wire:model="followUpResult"></div>
                    <div class="full">
                        <label for="followUpNextAt">מועד Up Follow הבא (אופציונלי)</label>
                        <input type="date" id="followUpNextAt" wire:model="followUpNextAt">
                        @error('followUpNextAt') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-secondary">שמירת Up Follow</button></div>
                </form>
            </div>

            {{-- ===== תזכורות אישיות ===== --}}
            <div class="card">
                <h3>תזכורות אישיות</h3>
                @forelse ($this->tasks as $task)
                    <div class="list-item">
                        <div>
                            <div style="font-weight:600; {{ $task->status === 'done' ? 'text-decoration:line-through; color:var(--color-text-secondary)' : '' }}">{{ $task->title }}</div>
                            <div class="who">{{ $task->due_at?->format('d/m/Y') ?? 'ללא מועד יעד' }}</div>
                        </div>
                        @if ($task->status !== 'done')
                            <div style="display:flex; gap:6px">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="completeTask({{ $task->id }})">הושלם</button>
                                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-error)" wire:click="cancelTask({{ $task->id }})">ביטול</button>
                            </div>
                        @else
                            <span class="badge badge-success">הושלם</span>
                        @endif
                    </div>
                @empty
                    <p class="text-text-secondary" style="font-size:var(--fs-small)">אין תזכורות פתוחות.</p>
                @endforelse

                <form wire:submit="addTask" class="form-grid" style="margin-top:var(--sp-md)">
                    <div class="full"><label for="taskTitle">כותרת</label><input type="text" id="taskTitle" wire:model="taskTitle">@error('taskTitle') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                    <div class="full"><label for="taskDescription">תיאור</label><textarea id="taskDescription" wire:model="taskDescription" rows="2"></textarea></div>
                    <div><label for="taskDueAt">מועד יעד</label><input type="date" id="taskDueAt" wire:model="taskDueAt"></div>
                    <div class="full"><button type="submit" class="btn btn-secondary">+ הוספת תזכורת</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
