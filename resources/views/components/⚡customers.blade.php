<?php

use App\Concerns\Notifies;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Program;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use App\Services\ActivityLogger;
use App\Services\CustomerImport;
use App\Services\SmoveContactMatch;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 05: the list of customers. "+ לקוחה חדשה" (2026-11-18, business
 * owner's request) opens one by hand — through CustomerImport::createCustomer(),
 * so it is still a lead converted at once (Lead::convertToCustomer()), exactly
 * like an imported row. A contact whose email already exists in Smove is shown
 * by its Smove name and linked only after the user approves the match.
 */
new
#[Layout('layouts.app', ['title' => 'לקוחות — כפיים'])]
class extends Component
{
    use Notifies;

    // ===== סינון ===== (city/program/status take several values — nothing ticked means all)
    public string $filterName = '';
    public array $filterCities = [];
    public array $filterProgramIds = [];
    public string $filterSubscription = '';
    public array $filterStatusIds = [];

    // ===== לקוחה חדשה =====
    public string $newSchoolName = '';
    public string $newCity = '';
    public string $newAddress = '';
    public string $newSchoolPhone = '';
    public string $newSchoolEmail = '';
    public string $newBusinessNumber = '';
    public string $newSourceId = '';
    public string $newNotes = '';

    /** The contacts being entered — see blankContact(). */
    public array $newContacts = [];

    /**
     * Contacts found in Smove, awaiting the user's approval to match them:
     * row index => ['email', 'contact_name', 'smove_name', 'id', 'can_receive', 'on_list'].
     */
    public array $smoveMatches = [];

    public ?string $customerError = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('customers.view'), 403);
        $this->newContacts = [self::blankContact(primary: true)];
    }

    private static function blankContact(bool $primary = false): array
    {
        return ['name' => '', 'role' => '', 'phone' => '', 'email' => '', 'is_primary' => $primary, 'is_accounting_contact' => false];
    }

    public function addNewContactRow(): void
    {
        $this->newContacts[] = self::blankContact();
    }

    public function removeNewContactRow(int $index): void
    {
        if (count($this->newContacts) > 1) {
            unset($this->newContacts[$index]);
            $this->newContacts = array_values($this->newContacts);
        }
    }

    /**
     * Step 1: checks the form, then looks every contact email up in Smove. A
     * match stops here and asks for approval (confirmSmoveMatch()); with none,
     * the customer is created right away.
     */
    public function createCustomer(ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('customers.manage'), 403);

        $this->customerError = null;
        $this->smoveMatches = [];
        $data = $this->validatedCustomer();

        if ($data === null) {
            return;
        }

        $matches = $this->findSmoveMatches($data['contacts']);

        if ($matches === null) {
            return;
        }

        if ($matches !== []) {
            $this->smoveMatches = $matches;

            return;
        }

        $this->saveCustomer($data, $activityLogger);
    }

    /** Step 2: the user approved — create the customer with those contacts linked to their Smove contacts. */
    public function confirmSmoveMatch(ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('customers.manage'), 403);

        if ($this->smoveMatches === []) {
            return;
        }

        $data = $this->validatedCustomer();

        if ($data === null) {
            $this->smoveMatches = [];

            return;
        }

        $this->saveCustomer($data, $activityLogger, $this->smoveMatches);
    }

    public function cancelSmoveMatch(): void
    {
        $this->smoveMatches = [];
    }

    /** The form, validated and shaped like an import row (CustomerImport::normalize()) — or null with the errors shown. */
    private function validatedCustomer(): ?array
    {
        $rules = [
            'newSchoolName' => ['required', 'string', 'max:255'],
            'newCity' => ['nullable', 'string', 'max:255'],
            'newAddress' => ['nullable', 'string', 'max:255'],
            'newSchoolPhone' => ['nullable', 'string', 'max:50', new \App\Rules\Phone],
            'newSchoolEmail' => ['nullable', 'email', 'max:255'],
            'newBusinessNumber' => ['nullable', 'string', 'max:20', 'regex:/^[0-9][0-9\s-]*$/'],
            'newSourceId' => ['nullable', Rule::exists('lead_sources', 'id')],
            'newNotes' => ['nullable', 'string'],
        ];
        $attributes = ['newSchoolName' => 'שם המוסד', 'newSchoolPhone' => 'טלפון המוסד', 'newSchoolEmail' => 'דוא"ל המוסד', 'newBusinessNumber' => 'ח.פ.'];

        // No contact marked primary → the first one is.
        if (! collect($this->newContacts)->contains(fn ($c) => ! empty($c['is_primary']))) {
            $this->newContacts[0]['is_primary'] = true;
        }

        foreach ($this->newContacts as $i => $contact) {
            $primary = ! empty($contact['is_primary']);
            $rules += [
                "newContacts.{$i}.name" => ['required', 'string', 'max:255'],
                "newContacts.{$i}.role" => ['nullable', 'string', 'max:255'],
                "newContacts.{$i}.phone" => [Rule::requiredIf($primary), 'nullable', 'string', 'max:50', new \App\Rules\Phone],
                "newContacts.{$i}.email" => [Rule::requiredIf($primary), 'nullable', 'email', 'max:255'],
                "newContacts.{$i}.is_primary" => ['boolean'],
                "newContacts.{$i}.is_accounting_contact" => ['boolean'],
            ];
            $attributes += ["newContacts.{$i}.name" => 'שם', "newContacts.{$i}.phone" => 'טלפון', "newContacts.{$i}.email" => 'דוא"ל'];
        }

        $this->validate($rules, [
            'newContacts.*.phone.required' => 'חובה לאיש קשר ראשי.',
            'newContacts.*.email.required' => 'חובה לאיש קשר ראשי.',
            'newBusinessNumber.regex' => 'ח.פ. יכול להכיל ספרות בלבד (ומקפים).',
        ], $attributes);

        $name = trim($this->newSchoolName);

        if (Lead::findExactDuplicateSchool($name)) {
            $this->addError('newSchoolName', 'בית ספר בשם זה כבר קיים במערכת (כליד או כלקוחה).');

            return null;
        }

        $digits = preg_replace('/\D/', '', $this->newBusinessNumber);

        if ($digits !== '' && School::whereNotNull('business_number')->pluck('business_number')
            ->contains(fn ($number) => preg_replace('/\D/', '', $number) === $digits)) {
            $this->addError('newBusinessNumber', 'ח.פ. זה כבר קיים במערכת.');

            return null;
        }

        $blank = fn (?string $value) => trim((string) $value) === '' ? null : trim($value);

        return [
            'school' => [
                'name' => $name,
                'city' => $blank($this->newCity),
                'address' => $blank($this->newAddress),
                'phone' => $blank($this->newSchoolPhone),
                'email' => $blank($this->newSchoolEmail),
                'business_number' => $blank($this->newBusinessNumber),
                'notes' => $blank($this->newNotes),
            ],
            'status' => null,
            'lead_source' => $this->newSourceId ? \App\Models\LeadSource::whereKey($this->newSourceId)->value('name') : null,
            'converted_at' => null,
            'contacts' => array_map(fn (array $c) => [
                'name' => trim($c['name']),
                'role' => $blank($c['role'] ?? null),
                'phone' => $blank($c['phone'] ?? null),
                'email' => $blank($c['email'] ?? null),
                'is_primary' => (bool) ($c['is_primary'] ?? false),
                'is_accounting_contact' => (bool) ($c['is_accounting_contact'] ?? false),
            ], $this->newContacts),
        ];
    }

    /** row index => Smove match (SmoveContactMatch::find()), or null with the error shown so the user may retry. */
    private function findSmoveMatches(array $contacts): ?array
    {
        $result = app(SmoveContactMatch::class)->find($contacts);

        if ($result['error']) {
            $this->customerError = $result['error'];

            return null;
        }

        return $result['matches'];
    }

    /**
     * Matched contacts are linked to their Smove contact. One already on the
     * system's Smove list isn't pushed to Smove at all (a join would reset its
     * consent — same rule as the import); one not on it yet joins it as usual.
     */
    private function saveCustomer(array $data, ActivityLogger $activityLogger, array $matches = []): void
    {
        [$links, $noPush] = SmoveContactMatch::linksAndNoPush($matches);

        try {
            $customer = app(CustomerImport::class)->createCustomer($data, auth()->user(), $activityLogger, $links, $noPush);
        } catch (\RuntimeException $e) {
            $this->smoveMatches = [];
            $this->customerError = $e->getMessage();

            return;
        }

        $activityLogger->log('customer.created_manually', "לקוחה נפתחה ידנית: {$customer->school->name}".($links ? ' ('.count($links).' אנשי קשר הותאמו ל-Smove)' : ''), [
            'customer_id' => $customer->id, 'school_id' => $customer->school_id,
        ]);

        $this->reset(['newSchoolName', 'newCity', 'newAddress', 'newSchoolPhone', 'newSchoolEmail', 'newBusinessNumber', 'newSourceId', 'newNotes', 'smoveMatches', 'customerError']);
        $this->newContacts = [self::blankContact(primary: true)];

        $this->redirect(route('customer-detail', $customer), navigate: false);
    }

    #[Computed]
    public function leadSources()
    {
        return \App\Models\LeadSource::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->with(['school', 'status', 'contacts', 'subscriptions.status'])
            ->when($this->filterName !== '', fn ($q) => $q->whereHas('school', fn ($sq) => $sq->where('name', 'like', '%'.$this->filterName.'%')))
            ->when($this->filterCities, fn ($q) => $q->whereHas('school', fn ($sq) => $sq->whereIn('city', $this->filterCities)))
            ->when($this->filterProgramIds, function ($q) {
                $programIds = array_map('intval', $this->filterProgramIds);

                // Bought any of the ticked programs. A program filter also
                // catches a customer who bought it inside a bundle — an annual
                // subscription or any other bundle — on a deal that wasn't
                // cancelled, not only as its own standalone deal.
                $q->where(function ($outer) use ($programIds) {
                    $outer->whereHas('deals', fn ($dq) => $dq->withAnyProgram($programIds))
                        ->orWhereHas('deals', fn ($dq) => $dq
                            ->where(fn ($bq) => $bq->whereHas('bundle.programs', fn ($pq) => $pq->whereIn('programs.id', $programIds))
                                ->orWhereHas('bundle.includedBundles.programs', fn ($pq) => $pq->whereIn('programs.id', $programIds)))
                            ->whereDoesntHave('status', fn ($stq) => $stq->where('name', Deal::CANCELLED_STATUS_NAME)));
                });
            })
            ->when($this->filterSubscription === 'yes', fn ($q) => $q->whereHas(
                'subscriptions', fn ($sq) => $sq->whereHas('status', fn ($stq) => $stq->where('name', Subscription::ACTIVE_STATUS_NAME))
            ))
            ->when($this->filterSubscription === 'no', fn ($q) => $q->whereDoesntHave(
                'subscriptions', fn ($sq) => $sq->whereHas('status', fn ($stq) => $stq->where('name', Subscription::ACTIVE_STATUS_NAME))
            ))
            // Former subscriber: no active subscription, but one that ended or was cancelled.
            ->when($this->filterSubscription === 'former', fn ($q) => $q->formerSubscribers())
            ->when($this->filterStatusIds, fn ($q) => $q->whereIn('status_id', array_map('intval', $this->filterStatusIds)))
            ->orderByDesc('converted_at')
            ->get();
    }

    #[Computed]
    public function cities()
    {
        return School::whereNotNull('city')->where('city', '!=', '')
            ->whereHas('customer')
            ->distinct()->orderBy('city')->pluck('city');
    }

    #[Computed]
    public function programs()
    {
        return Program::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function statuses()
    {
        return StatusDefinition::where('scope', 'customer')->where('is_active', true)->orderBy('sort_order')->get();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">לקוחות</h1>
        </div>
        @can('customers.manage')
            <x-modal trigger-label="+ לקוחה חדשה" title="לקוחה חדשה" width="760px">
                @if ($smoveMatches)
                    <x-smove-match-notice :matches="$smoveMatches" />
                    <div style="display:flex; gap:8px">
                        <button type="button" class="btn btn-primary" wire:click="confirmSmoveMatch" wire:loading.attr="disabled">אישור התאמה ופתיחת הלקוחה</button>
                        <button type="button" class="btn btn-secondary" wire:click="cancelSmoveMatch">חזרה לעריכה</button>
                    </div>
                @else
                    <form wire:submit="createCustomer" class="form-grid">
                        <div class="full"><h3 style="margin:0">פרטי המוסד</h3></div>
                        <div>
                            <label for="newSchoolName">שם המוסד *</label>
                            <input type="text" id="newSchoolName" wire:model="newSchoolName" placeholder="למשל: בית ספר יובלים">
                            @error('newSchoolName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="newCity">עיר</label>
                            <input type="text" id="newCity" wire:model="newCity">
                        </div>
                        <div>
                            <label for="newAddress">כתובת</label>
                            <input type="text" id="newAddress" wire:model="newAddress">
                        </div>
                        <div>
                            <label for="newBusinessNumber">ח.פ.</label>
                            <input type="text" id="newBusinessNumber" wire:model="newBusinessNumber" class="ltr-num" dir="ltr">
                            @error('newBusinessNumber') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="newSchoolPhone">טלפון המוסד</label>
                            <input type="text" id="newSchoolPhone" wire:model="newSchoolPhone" class="ltr-num" dir="ltr">
                            @error('newSchoolPhone') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="newSchoolEmail">דוא"ל המוסד</label>
                            <input type="text" id="newSchoolEmail" wire:model="newSchoolEmail" class="ltr-num" dir="ltr">
                            @error('newSchoolEmail') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="newSourceId">מקור הגעה</label>
                            <select id="newSourceId" wire:model="newSourceId">
                                <option value="">— ללא —</option>
                                @foreach ($this->leadSources as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="full">
                            <label for="newNotes">הערות</label>
                            <textarea id="newNotes" wire:model="newNotes" rows="2"></textarea>
                        </div>

                        <div class="full" style="display:flex; align-items:center; justify-content:space-between; margin-top:8px">
                            <h3 style="margin:0">אנשי קשר</h3>
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="addNewContactRow">+ איש קשר נוסף</button>
                        </div>
                        @foreach ($newContacts as $i => $contact)
                            <div class="full contact-item" wire:key="new-contact-{{ $i }}">
                                <div class="form-grid">
                                    <div>
                                        <label for="newContact{{ $i }}Name">שם מלא *</label>
                                        <input type="text" id="newContact{{ $i }}Name" wire:model="newContacts.{{ $i }}.name">
                                        @error('newContacts.{{ $i }}.name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="newContact{{ $i }}Role">תפקיד</label>
                                        <input type="text" id="newContact{{ $i }}Role" wire:model="newContacts.{{ $i }}.role">
                                    </div>
                                    <div>
                                        <label for="newContact{{ $i }}Phone">טלפון</label>
                                        <input type="text" id="newContact{{ $i }}Phone" wire:model="newContacts.{{ $i }}.phone" class="ltr-num" dir="ltr">
                                        @error('newContacts.{{ $i }}.phone') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="newContact{{ $i }}Email">דוא"ל</label>
                                        <input type="text" id="newContact{{ $i }}Email" wire:model="newContacts.{{ $i }}.email" class="ltr-num" dir="ltr">
                                        @error('newContacts.{{ $i }}.email') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="full checkbox-row" style="gap:var(--sp-lg); justify-content:space-between">
                                        <span class="checkbox-row" style="gap:var(--sp-lg)">
                                            <span class="checkbox-row"><input type="checkbox" id="newContact{{ $i }}Primary" wire:model="newContacts.{{ $i }}.is_primary"><label for="newContact{{ $i }}Primary" style="margin:0">איש קשר ראשי</label></span>
                                            <span class="checkbox-row"><input type="checkbox" id="newContact{{ $i }}Accounting" wire:model="newContacts.{{ $i }}.is_accounting_contact"><label for="newContact{{ $i }}Accounting" style="margin:0">גורם חשבונאי</label></span>
                                        </span>
                                        @if (count($newContacts) > 1)
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="removeNewContactRow({{ $i }})">הסרה</button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                        <div class="full" style="font-size:var(--fs-caption); color:var(--color-text-secondary)">לאיש קשר ראשי חובה טלפון ודוא"ל. אם לא סומן אף איש קשר ראשי — הראשון יהיה הראשי.</div>

                        <div class="full"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="createCustomer">פתיחת הלקוחה</button></div>
                    </form>
                @endif
            </x-modal>
        @endcan
    </div>

    {{-- ===== סינון ===== --}}
    <div class="filter-row">
        <div>
            <label for="filterName">שם בית ספר</label>
            <input type="text" id="filterName" wire:model.live.debounce.400ms="filterName" placeholder="חיפוש לפי שם...">
        </div>
        <x-multi-filter model="filterCities" label="עיר" all-label="כל הערים" :selected="$filterCities"
            :options="$this->cities->combine($this->cities)->all()" />
        <x-multi-filter model="filterProgramIds" label="תכנית שנרכשה" all-label="כל התוכניות" :selected="$filterProgramIds"
            :options="$this->programs->pluck('name', 'id')->all()" />
        <div>
            <label for="filterSubscription">מנוי</label>
            <select id="filterSubscription" wire:model.live="filterSubscription">
                <option value="">הכל</option>
                <option value="yes">יש מנוי פעיל</option>
                <option value="no">אין מנוי פעיל</option>
                <option value="former">מנויה לשעבר</option>
            </select>
        </div>
        <x-multi-filter model="filterStatusIds" label="סטטוס" all-label="כל הסטטוסים" :selected="$filterStatusIds"
            :options="$this->statuses->pluck('name', 'id')->all()" />
    </div>

    <div class="card" style="padding:0; overflow:hidden">
        <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>בית ספר</th>
                    <th>איש קשר ראשי</th>
                    <th>סטטוס</th>
                    <th>מנוי</th>
                    <th>לקוחה מאז</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->customers as $customer)
                    @php
                        $primaryContact = $customer->contacts->firstWhere('is_primary', true);
                        $hasActiveSubscription = $customer->subscriptions->first(fn ($s) => $s->status?->name === \App\Models\Subscription::ACTIVE_STATUS_NAME) !== null;
                        $isFormerSubscriber = ! $hasActiveSubscription && $customer->subscriptions->first(fn ($s) => in_array($s->status?->name, [\App\Models\Subscription::ENDED_STATUS_NAME, \App\Models\Subscription::CANCELLED_STATUS_NAME], true)) !== null;
                    @endphp
                    <tr class="row-link {{ $hasActiveSubscription ? 'is-subscription' : ($isFormerSubscriber ? 'is-former-subscription' : '') }}" @if ($hasActiveSubscription) title="לקוחה מנויה" @elseif ($isFormerSubscriber) title="מנויה לשעבר" @endif onclick="window.location='{{ route('customer-detail', $customer) }}'">
                        <td>{{ $customer->school?->name ?? '—' }}</td>
                        <td>{{ $primaryContact ? $primaryContact->name.($primaryContact->role ? ', '.$primaryContact->role : '') : '—' }}</td>
                        <td><span class="badge {{ \App\Models\Customer::badgeClassForStatusName($customer->status?->name) }}">{{ $customer->status?->name }}</span></td>
                        <td>@if ($hasActiveSubscription)<span class="badge badge-primary">מנויה פעילה</span>@elseif ($isFormerSubscriber)<span class="badge badge-neutral">מנויה לשעבר</span>@else—@endif</td>
                        <td class="ltr-num">{{ $customer->converted_at->format('d/m/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-text-secondary">אין עדיין לקוחות — לקוחה נוצרת מהמרת ליד בכרטיס הליד.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
