<?php

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 05 — כרטיס לקוחה. A Customer only ever exists via
 * Lead::convertToCustomer() (FR-8.2); this component never creates one.
 * Most tabs here (מנוי, עסקאות, מסמכים ותשלומים, חומרים) are deliberately
 * empty-state stubs — the real data model for each arrives in its own
 * build-plan stage (9/6/7+8/10) — per this stage's Definition of Done.
 */
new
#[Layout('layouts.app', ['title' => 'כרטיס לקוחה — כפיים'])]
class extends Component
{
    public Customer $customer;

    public string $activeTab = 'info';

    // ===== פרטי בית ספר (עריכה) =====
    public bool $editingSchool = false;
    public string $schoolName = '';
    public string $schoolCity = '';
    public string $schoolAddress = '';
    public string $schoolPhone = '';
    public string $schoolEmail = '';

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

    /** Business-rule error (FR-7.25) — FR-2.9 "לא ניתן להסיר את הראשי האחרון". */
    public ?string $contactError = null;

    public function mount(Customer $customer): void
    {
        abort_unless(auth()->user()->can('customers.manage'), 403);

        $this->customer = $customer->load(['school', 'lead']);
        $this->syncSchoolFields();
    }

    private function syncSchoolFields(): void
    {
        $school = $this->customer->school;
        $this->schoolName = $school?->name ?? '';
        $this->schoolCity = $school?->city ?? '';
        $this->schoolAddress = $school?->address ?? '';
        $this->schoolPhone = $school?->phone ?? '';
        $this->schoolEmail = $school?->email ?? '';
    }

    // ----- פרטי בית ספר -----

    public function saveSchool(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'schoolName' => ['required', 'string', 'max:255'],
            'schoolCity' => ['nullable', 'string', 'max:255'],
            'schoolAddress' => ['nullable', 'string', 'max:255'],
            'schoolPhone' => ['nullable', 'string', 'max:50'],
            'schoolEmail' => ['nullable', 'email', 'max:255'],
        ], [], ['schoolName' => 'שם המוסד']);

        $this->customer->school->update([
            'name' => $data['schoolName'],
            'city' => $data['schoolCity'] ?: null,
            'address' => $data['schoolAddress'] ?: null,
            'phone' => $data['schoolPhone'] ?: null,
            'email' => $data['schoolEmail'] ?: null,
        ]);

        $activityLogger->log('customer.school_updated', "עודכנו פרטי בית ספר עבור לקוחה #{$this->customer->id}: {$this->customer->school->name}", [
            'customer_id' => $this->customer->id,
            'lead_id' => $this->customer->lead_id,
            'school_id' => $this->customer->school_id,
        ]);

        $this->customer->refresh()->load('school');
        $this->syncSchoolFields();
        $this->editingSchool = false;
    }

    // ----- אנשי קשר (CONTACT) -----

    /**
     * FR-2.8: a customer must always have at least one primary contact — the
     * very first contact added to a customer is forced primary regardless of
     * the checkbox, since there is no other primary yet to rely on.
     */
    public function addContact(ActivityLogger $activityLogger): void
    {
        $data = $this->validateContact();

        if ($this->customer->contacts()->count() === 0) {
            $data['is_primary'] = true;
        }

        $contact = Contact::create(array_merge($data, [
            'school_id' => $this->customer->school_id,
            'customer_id' => $this->customer->id,
        ]));

        $activityLogger->log('contact.created', "נוסף איש קשר \"{$contact->name}\" ללקוחה \"{$this->customer->school->name}\"", [
            'customer_id' => $this->customer->id, 'lead_id' => $this->customer->lead_id, 'school_id' => $this->customer->school_id,
        ]);

        $this->contactError = null;
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

    /**
     * FR-2.9: cannot remove the "ראשי" mark from the last remaining primary
     * contact — a customer must always have at least one (FR-2.8).
     */
    public function updateContact(ActivityLogger $activityLogger): void
    {
        $contact = Contact::findOrFail($this->editingContactId);
        $data = $this->validateContact();

        if ($contact->is_primary && ! $data['is_primary'] && ! $this->hasOtherPrimaryContact($contact->id)) {
            $this->contactError = 'לא ניתן להסיר את הסימון מאיש הקשר הראשי האחרון — לכל לקוחה חייב להיות לפחות איש קשר ראשי אחד (FR-2.8/FR-2.9).';

            return;
        }

        $contact->update($data);

        $activityLogger->log('contact.updated', "עודכן איש קשר \"{$contact->name}\"", [
            'customer_id' => $this->customer->id, 'lead_id' => $this->customer->lead_id, 'school_id' => $contact->school_id,
        ]);

        $this->contactError = null;
        $this->resetContactForm();
        unset($this->contacts);
    }

    public function cancelContactEdit(): void
    {
        $this->contactError = null;
        $this->resetContactForm();
    }

    /**
     * Removal is a logical delete (deleted_at), never a real one — same
     * convention as stage 4 — and is blocked by FR-2.9 for the last primary.
     */
    public function removeContact(int $id, ActivityLogger $activityLogger): void
    {
        $contact = Contact::findOrFail($id);

        if ($contact->is_primary && ! $this->hasOtherPrimaryContact($contact->id)) {
            $this->contactError = 'לא ניתן להסיר את איש הקשר הראשי האחרון — לכל לקוחה חייב להיות לפחות איש קשר ראשי אחד (FR-2.8/FR-2.9).';

            return;
        }

        $name = $contact->name;
        $schoolId = $contact->school_id;
        $contact->delete();

        $activityLogger->log('contact.removed', "הוסר איש קשר \"{$name}\" (מחיקה לוגית)", [
            'customer_id' => $this->customer->id, 'lead_id' => $this->customer->lead_id, 'school_id' => $schoolId,
        ]);

        $this->contactError = null;

        if ($this->editingContactId === $id) {
            $this->resetContactForm();
        }

        unset($this->contacts);
    }

    private function hasOtherPrimaryContact(int $excludingContactId): bool
    {
        return Contact::where('customer_id', $this->customer->id)
            ->where('id', '!=', $excludingContactId)
            ->where('is_primary', true)
            ->exists();
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

    #[Computed]
    public function contacts()
    {
        return $this->customer->contacts()->orderByDesc('is_primary')->orderBy('name')->get();
    }

    /**
     * FR-1.14/FR-2.18: the full activity history accumulated while this was
     * a lead stays reachable from the customer card — pulled live from
     * LeadInteraction/FollowUp (via lead_id) and ActivityLog (via customer_id
     * or lead_id), not copied anywhere.
     */
    #[Computed]
    public function interactions()
    {
        return $this->customer->lead?->interactions()->orderByDesc('occurred_at')->get() ?? collect();
    }

    #[Computed]
    public function followUps()
    {
        return $this->customer->lead?->followUps()->orderByDesc('occurred_at')->get() ?? collect();
    }

    #[Computed]
    public function activityLogs()
    {
        return ActivityLog::query()
            ->where(fn ($q) => $q->where('customer_id', $this->customer->id)->orWhere('lead_id', $this->customer->lead_id))
            ->whereNotIn('activity_type', ['lead.interaction_logged', 'lead.follow_up_logged'])
            ->orderByDesc('occurred_at')
            ->get();
    }

    #[Computed]
    public function timeline()
    {
        $items = collect();

        if ($lead = $this->customer->lead) {
            $items->push([
                'date' => $lead->created_at,
                'title' => 'ליד נוצר',
                'body' => $lead->source?->name ? "התקבל דרך {$lead->source->name}." : 'נוצר ידנית.',
            ]);
        }

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

        foreach ($this->activityLogs as $log) {
            $items->push([
                'date' => $log->occurred_at,
                'title' => $log->activity_type,
                'body' => $log->description,
            ]);
        }

        return $items->sortByDesc('date')->values();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <span class="badge {{ \App\Models\Customer::badgeClassForStatusName($customer->status?->name) }}" style="margin-bottom:8px; display:inline-flex">{{ $customer->status?->name }}</span>
            <h1>{{ $customer->school?->name ?? 'לקוחה #'.$customer->id }}</h1>
            <p style="color:var(--color-text-secondary); margin:0">
                לקוחה מאז <span class="ltr-num">{{ $customer->converted_at->format('d/m/Y') }}</span>
                @if ($this->contacts->firstWhere('is_primary', true))
                    · איש קשר ראשי: {{ $this->contacts->firstWhere('is_primary', true)->name }}
                @endif
            </p>
        </div>
        <div style="display:flex; gap:var(--sp-sm)">
            <a href="{{ route('customers') }}" class="btn btn-ghost">חזרה לרשימה</a>
            @if ($customer->lead)
                <a href="{{ route('lead-detail', $customer->lead) }}" class="btn btn-ghost">כרטיס הליד המקורי</a>
            @endif
        </div>
    </div>

    @if ($contactError)
        <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
            {{ $contactError }}
        </div>
    @endif

    <div class="tabs">
        <span class="{{ $activeTab === 'info' ? 'active' : '' }}" wire:click="$set('activeTab', 'info')">בית ספר ואנשי קשר</span>
        <span class="{{ $activeTab === 'subscription' ? 'active' : '' }}" wire:click="$set('activeTab', 'subscription')">מנוי</span>
        <span class="{{ $activeTab === 'deals' ? 'active' : '' }}" wire:click="$set('activeTab', 'deals')">עסקאות</span>
        <span class="{{ $activeTab === 'billing' ? 'active' : '' }}" wire:click="$set('activeTab', 'billing')">מסמכים ותשלומים</span>
        <span class="{{ $activeTab === 'materials' ? 'active' : '' }}" wire:click="$set('activeTab', 'materials')">חומרים</span>
        <span class="{{ $activeTab === 'activity' ? 'active' : '' }}" wire:click="$set('activeTab', 'activity')">היסטוריית פעילות</span>
    </div>

    @if ($activeTab === 'info')
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
                        <div class="field"><div class="k">שם המוסד</div><div class="v">{{ $customer->school?->name ?? '—' }}</div></div>
                        <div class="field"><div class="k">עיר</div><div class="v">{{ $customer->school?->city ?? '—' }}</div></div>
                        <div class="field"><div class="k">טלפון</div><div class="v ltr-num">{{ $customer->school?->phone ?? '—' }}</div></div>
                        <div class="field"><div class="k">דוא"ל</div><div class="v ltr-num">{{ $customer->school?->email ?? '—' }}</div></div>
                    @endif
                </div>
            </div>

            <div>
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
                        <p class="text-text-secondary" style="font-size:var(--fs-small)">אין עדיין אנשי קשר ללקוחה זו.</p>
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
                    <p style="font-size:var(--fs-caption); color:var(--color-text-secondary); margin-top:var(--sp-sm)">לכל לקוחה חייב להיות תמיד לפחות איש קשר ראשי אחד — לא ניתן להסיר את הסימון או למחוק את הראשי האחרון (FR-2.8, FR-2.9).</p>
                </div>
            </div>
        </div>
    @elseif ($activeTab === 'subscription')
        <div class="card empty-state">מעקב מנוי שנתי, זכאות להטבות ויומן אספקה יוצגו כאן — יוצג בשלב 9 (מנויים).</div>
    @elseif ($activeTab === 'deals')
        <div class="card empty-state">עסקאות הלקוחה (רכישות, סכומים, סטטוס) יוצגו כאן — יוצג בשלב 6 (עסקאות).</div>
    @elseif ($activeTab === 'billing')
        <div class="card empty-state">מסמכים, תשלומים ויתרת חוב יוצגו כאן — יוצג בשלבים 7–8 (מסמכים, גבייה ותשלומים).</div>
    @elseif ($activeTab === 'materials')
        <div class="card empty-state">חומרי הלימוד שנשלחו ורשימות תפוצה יוצגו כאן — יוצג בשלב 10 (חומרים ותפוצה).</div>
    @elseif ($activeTab === 'activity')
        <div class="card">
            <h3>היסטוריית פעילות</h3>
            <div class="timeline">
                @forelse ($this->timeline as $item)
                    <div class="t-item">
                        <div class="t-date ltr-num" style="direction:ltr">{{ $item['date']?->format('d/m/Y H:i') }}</div>
                        <div class="t-title">{{ $item['title'] }}</div>
                        @if ($item['body'])<div class="t-body">{{ $item['body'] }}</div>@endif
                    </div>
                @empty
                    <p class="text-text-secondary" style="font-size:var(--fs-small)">אין עדיין היסטוריית פעילות.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
