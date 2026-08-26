<?php

use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Subscription;
use App\Models\Task;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 05 — כרטיס לקוחה. A Customer only ever exists via
 * Lead::convertToCustomer() (FR-8.2); this component never creates one.
 * Build-plan 06 fills in the "עסקאות" tab (a real deal list + "עסקה חדשה"
 * creation form — see createDeal() below, which is the only place this
 * screen ever creates a Deal, always scoped to $this->customer). מנוי and
 * חומרים remain deliberately empty-state stubs — their real data model
 * arrives in build-plan stages 9/10.
 *
 * Build-plan 08 fills in the "מסמכים ותשלומים" tab with a real debt
 * indicator (FR-2.15) and a direct link to an open collection task
 * (FR-2.16) — per-deal document/payment detail still lives on each deal's
 * own card (⚡deal-detail.blade.php).
 *
 * Build-plan 09 fills in the "מנוי" tab for real: the delivery log, the
 * mark-supplied action (Subscription::markDeliverySupplied(), FR-3.14/
 * FR-8.19), the monthly-payment field (FR-3.19-FR-3.21), and cancellation +
 * credit-note generation (Subscription::cancel()/generateCreditNote(),
 * US-010/FR-4.5/FR-8.12/FR-8.24). Also adds the active-subscription badge
 * (FR-2.17) and the FR-8.23 price-exceeded banner to the card header. Every
 * subscription action below is additionally gated on subscriptions.manage —
 * this whole page still requires customers.manage at mount, unchanged.
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

    // ===== עסקה חדשה (DEAL) =====
    /** "program:{id}" or "bundle:{id}" — a single select so exactly one type is ever chosen. */
    public string $dealItem = '';

    public string $dealAgreedAmount = '';

    public string $dealSpecialRequest = '';

    public string $dealPaymentMethodId = '';

    /** Business-rule error (FR-7.25) from Deal::createForCustomer() (FR-3.3/FR-8.4/FR-8.5). */
    public ?string $dealError = null;

    // ===== מנוי (SUBSCRIPTION / SUBSCRIPTION_DELIVERY) =====
    /** delivery_id => chosen program_id, bound per delivery row. */
    public array $deliveryProgramSelections = [];

    public ?int $editingMonthlyPaymentForSubscriptionId = null;

    public string $monthlyPaymentOverrideInput = '';

    /** Business-rule error from Subscription::markDeliverySupplied()/cancel()/generateCreditNote(). */
    public ?string $subscriptionError = null;

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

    // ----- עסקאות (DEAL) -----

    /**
     * FR-3.2/FR-3.3/FR-8.4: the only place this screen creates a Deal — the
     * customer is always $this->customer (never freely user-supplied), and
     * dealItem's "program:{id}"/"bundle:{id}" encoding makes choosing both
     * or neither structurally impossible on the UI side; Deal::createForCustomer()
     * still re-checks everything server-side (including FR-8.5, disabled items).
     */
    public function createDeal(ActivityLogger $activityLogger): void
    {
        $this->dealError = null;

        $data = $this->validate([
            'dealItem' => ['required', 'string'],
            'dealAgreedAmount' => ['nullable', 'numeric', 'gt:0'],
            'dealSpecialRequest' => ['nullable', 'string'],
            'dealPaymentMethodId' => ['nullable', 'exists:payment_methods,id'],
        ], [], ['dealItem' => 'תוכנית / מארז']);

        [$type, $id] = array_pad(explode(':', $data['dealItem'], 2), 2, null);

        $program = $type === 'program' ? Program::find($id) : null;
        $bundle = $type === 'bundle' ? Bundle::find($id) : null;

        try {
            $deal = Deal::createForCustomer(
                $this->customer,
                $program,
                $bundle,
                $data['dealAgreedAmount'] !== null && $data['dealAgreedAmount'] !== '' ? (float) $data['dealAgreedAmount'] : null,
                $data['dealSpecialRequest'] ?: null,
                $data['dealPaymentMethodId'] ?: null,
            );
        } catch (\RuntimeException $e) {
            $this->dealError = $e->getMessage();

            return;
        }

        $itemName = $deal->program_name_snapshot ?? $deal->bundle_name_snapshot;
        $activityLogger->log('deal.created', "נוצרה עסקה חדשה #{$deal->id} עבור לקוחה \"{$this->customer->school?->name}\": {$itemName}", [
            'deal_id' => $deal->id, 'customer_id' => $this->customer->id,
        ]);

        $this->reset(['dealItem', 'dealAgreedAmount', 'dealSpecialRequest', 'dealPaymentMethodId']);
        unset($this->deals);

        $this->redirect(route('deal-detail', $deal), navigate: false);
    }

    #[Computed]
    public function deals()
    {
        return $this->customer->deals()->with(['program', 'bundle', 'status'])->orderByDesc('purchased_at')->get();
    }

    #[Computed]
    public function availablePrograms()
    {
        return Program::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function availableBundles()
    {
        return Bundle::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function paymentMethods()
    {
        return PaymentMethod::where('is_active', true)->orderBy('name')->get();
    }

    // ----- מנוי (SUBSCRIPTION / SUBSCRIPTION_DELIVERY) -----

    #[Computed]
    public function subscriptions()
    {
        return $this->customer->subscriptions()
            ->with(['status', 'deal', 'deliveries.program', 'deliveries.suppliedBy'])
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function hasActiveSubscription(): bool
    {
        return $this->customer->hasActiveSubscription();
    }

    /** FR-8.23: purely informational banner on the customer card. */
    #[Computed]
    public function priceExceededAlert(): bool
    {
        return $this->customer->exceedsSubscriptionPriceAlert();
    }

    /** FR-3.14: the secretary chooses which active, non-subscription catalog program filled a slot. */
    #[Computed]
    public function availableDeliveryPrograms()
    {
        return Program::where('is_active', true)->where('is_subscription_type', false)->orderBy('name')->get();
    }

    /**
     * FR-3.14/FR-8.19: the only place this screen marks a delivery supplied —
     * Subscription::markDeliverySupplied() enforces the version-guarded
     * optimistic lock and the "active, non-subscription program" rule
     * server-side. FR-3.15: purely manual, no materials-sending hook exists
     * anywhere in this method or the model it calls.
     */
    public function markDeliverySupplied(int $subscriptionId, int $deliveryId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $this->subscriptionError = null;

        $programId = $this->deliveryProgramSelections[$deliveryId] ?? null;

        if (! $programId) {
            $this->subscriptionError = 'יש לבחור תוכנית עבור שורת האספקה לפני הסימון (FR-3.14).';

            return;
        }

        $subscription = Subscription::findOrFail($subscriptionId);

        try {
            $delivery = $subscription->markDeliverySupplied($subscription->version, $deliveryId, (int) $programId, auth()->user());
        } catch (\RuntimeException $e) {
            $this->subscriptionError = $e->getMessage();

            return;
        }

        $activityLogger->log('subscription.delivery_supplied', "סומנה תוכנית מס' {$delivery->sequence_number} כסופקה במנוי #{$subscription->id}: {$delivery->program?->name}", [
            'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            'metadata' => ['delivery_id' => $delivery->id, 'program_id' => $delivery->program_id],
        ]);

        if ($subscription->fresh()->status?->name === Subscription::ENDED_STATUS_NAME) {
            $activityLogger->log('subscription.ended', "מנוי #{$subscription->id} הסתיים אוטומטית לאחר סימון התוכנית העשירית (FR-3.16/FR-3.17)", [
                'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            ]);
        }

        unset($this->deliveryProgramSelections[$deliveryId]);
        unset($this->subscriptions, $this->hasActiveSubscription);
    }

    /**
     * US-010/FR-8.24: the only place this screen cancels a subscription —
     * Subscription::cancel() computes the dynamic credit and is a
     * status-only change (never deletes the deal/subscription/delivery
     * history/activity log).
     */
    public function cancelSubscription(int $subscriptionId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $this->subscriptionError = null;

        $subscription = Subscription::findOrFail($subscriptionId);

        try {
            $subscription->cancel();
        } catch (\RuntimeException $e) {
            $this->subscriptionError = $e->getMessage();

            return;
        }

        $activityLogger->log('subscription.cancelled', "מנוי #{$subscription->id} בוטל — קיזוז מחושב: ₪".number_format((float) $subscription->cancellation_credit, 0), [
            'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            'metadata' => ['cancellation_credit' => (float) $subscription->cancellation_credit],
        ]);

        unset($this->subscriptions, $this->hasActiveSubscription);
    }

    /**
     * FR-4.5/FR-8.12: a deliberately separate explicit action from
     * cancelSubscription() above — Subscription::generateCreditNote()
     * re-checks the "deal already has an invoice" gate server-side via
     * Document::generateCreditNoteFor().
     */
    public function generateSubscriptionCreditNote(int $subscriptionId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $this->subscriptionError = null;

        $subscription = Subscription::findOrFail($subscriptionId);

        try {
            $document = $subscription->generateCreditNote();
        } catch (\RuntimeException $e) {
            $this->subscriptionError = $e->getMessage();

            return;
        }

        $activityLogger->log('document.credit_note_generated', "הופקה חשבונית זיכוי עבור מנוי #{$subscription->id}", [
            'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            'metadata' => ['document_id' => $document->id],
        ]);

        $this->redirect(route('document-view', $document), navigate: false);
    }

    /** FR-3.20: loads the current override (if any) into the edit form. */
    public function editMonthlyPayment(int $subscriptionId): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $subscription = Subscription::findOrFail($subscriptionId);
        $this->editingMonthlyPaymentForSubscriptionId = $subscriptionId;
        $this->monthlyPaymentOverrideInput = $subscription->monthly_payment_override !== null
            ? (string) $subscription->monthly_payment_override
            : '';
    }

    /** FR-3.20: an empty value clears the override, falling back to the computed default again. */
    public function saveMonthlyPayment(int $subscriptionId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $data = $this->validate(['monthlyPaymentOverrideInput' => ['nullable', 'numeric', 'gt:0']]);

        $subscription = Subscription::findOrFail($subscriptionId);
        $amount = $data['monthlyPaymentOverrideInput'] !== null && $data['monthlyPaymentOverrideInput'] !== ''
            ? (float) $data['monthlyPaymentOverrideInput']
            : null;

        $subscription->setMonthlyPaymentOverride($amount);

        $activityLogger->log('subscription.monthly_payment_overridden', "עודכן תשלום חודשי ידני עבור מנוי #{$subscription->id}", [
            'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            'metadata' => ['monthly_payment_override' => $amount],
        ]);

        $this->editingMonthlyPaymentForSubscriptionId = null;
        $this->monthlyPaymentOverrideInput = '';
        unset($this->subscriptions);
    }

    public function cancelMonthlyPaymentEdit(): void
    {
        $this->editingMonthlyPaymentForSubscriptionId = null;
        $this->monthlyPaymentOverrideInput = '';
    }

    /**
     * FR-2.15: the customer card's debt indicator — sum of agreed_amount
     * minus paid across every non-cancelled deal.
     */
    #[Computed]
    public function outstandingBalance(): float
    {
        return (float) $this->customer->deals()->with('status')->get()
            ->reject(fn (Deal $deal) => $deal->status?->name === Deal::CANCELLED_STATUS_NAME)
            ->sum(fn (Deal $deal) => $deal->outstandingBalance());
    }

    /**
     * FR-2.16: a direct link from the customer card into a still-open
     * collection task (App\Console\Commands\ProcessCollectionTasks), if any.
     */
    #[Computed]
    public function openCollectionTask(): ?Task
    {
        return Task::where('task_type', 'collection')
            ->where('status', 'open')
            ->whereIn('deal_id', $this->customer->deals()->pluck('id'))
            ->with('deal')
            ->latest('id')
            ->first();
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
            @if ($this->hasActiveSubscription)
                <span class="badge badge-primary" style="margin-bottom:8px; margin-inline-start:6px; display:inline-flex">מנויה פעילה</span>
            @endif
            @if ($this->outstandingBalance > 0)
                <span class="badge badge-error" style="margin-bottom:8px; margin-inline-start:6px; display:inline-flex">חוב פתוח: ₪{{ number_format($this->outstandingBalance, 0) }}</span>
            @endif
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

    @if ($this->priceExceededAlert)
        <div class="mb-8" style="background: var(--color-warning-bg); color: var(--color-warning); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:600;">
            התראת חריגת מחיר: סך רכישות התוכניות הבודדות (מחוץ למנוי) של לקוחה זו עולה על מחיר מנוי שנתי מלא (FR-8.23).
        </div>
    @endif

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
        @if ($subscriptionError)
            <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
                {{ $subscriptionError }}
            </div>
        @endif

        @forelse ($this->subscriptions as $subscription)
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <div class="contact-edit-head">
                    <h3 style="margin:0">מנוי #{{ $subscription->id }} — עסקה #{{ $subscription->deal_id }}</h3>
                    <span class="badge {{ \App\Models\Subscription::badgeClassForStatusName($subscription->status?->name) }}">{{ $subscription->status?->name }}</span>
                </div>

                <div style="display:flex; gap:var(--sp-xl); flex-wrap:wrap; margin-bottom:var(--sp-md); font-size:var(--fs-small)">
                    <div><span style="color:var(--color-text-secondary)">תאריך תחילה: </span><span class="ltr-num" style="font-weight:600">{{ $subscription->start_date->format('d/m/Y') }}</span></div>
                    <div><span style="color:var(--color-text-secondary)">מחיר שסוכם: </span><span class="ltr-num" style="font-weight:600">₪{{ number_format((float) $subscription->agreed_price, 0) }}</span></div>
                    <div><span style="color:var(--color-text-secondary)">התקדמות: </span><span style="font-weight:600">{{ $subscription->suppliedCount() }} מתוך {{ \App\Models\Subscription::TOTAL_DELIVERIES }} תוכניות סופקו</span></div>
                    @if ($subscription->end_date)
                        <div><span style="color:var(--color-text-secondary)">תאריך סיום: </span><span class="ltr-num" style="font-weight:600">{{ $subscription->end_date->format('d/m/Y') }}</span></div>
                    @endif
                </div>

                {{-- ===== תשלום חודשי (FR-3.19-FR-3.21) ===== --}}
                <div class="field" style="margin-bottom:var(--sp-lg)">
                    <div class="k">תשלום חודשי</div>
                    @if ($editingMonthlyPaymentForSubscriptionId === $subscription->id)
                        <form wire:submit="saveMonthlyPayment({{ $subscription->id }})" style="display:flex; gap:var(--sp-sm); align-items:center; margin-top:4px">
                            <input type="text" wire:model="monthlyPaymentOverrideInput" class="ltr-num" dir="ltr" placeholder="₪" style="max-width:140px">
                            <button type="submit" class="btn btn-primary btn-sm">שמירה</button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelMonthlyPaymentEdit">ביטול</button>
                        </form>
                        @error('monthlyPaymentOverrideInput') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    @else
                        <div class="v ltr-num">
                            ₪{{ number_format($subscription->monthlyPayment(), 0) }}
                            @if ($subscription->monthly_payment_override !== null)
                                <span class="badge badge-neutral" style="margin-inline-start:6px">נקבע ידנית</span>
                            @endif
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="editMonthlyPayment({{ $subscription->id }})">עריכה</button>
                        </div>
                    @endif
                </div>

                {{-- ===== יומן אספקה (FR-3.13-FR-3.15) ===== --}}
                <h3 style="font-size:var(--fs-h3)">יומן אספקה</h3>
                <table>
                    <thead><tr><th>#</th><th>תוכנית</th><th>תאריך אספקה</th><th>סומן ע"י</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($subscription->deliveries as $delivery)
                            <tr>
                                <td>{{ $delivery->sequence_number }}</td>
                                <td>
                                    @if ($delivery->is_supplied)
                                        {{ $delivery->program?->name }}
                                    @elseif ($subscription->isActive())
                                        <select wire:model="deliveryProgramSelections.{{ $delivery->id }}">
                                            <option value="">בחרו תוכנית שסופקה</option>
                                            @foreach ($this->availableDeliveryPrograms as $program)
                                                <option value="{{ $program->id }}">{{ $program->name }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="ltr-num">{{ $delivery->supplied_at?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $delivery->suppliedBy?->name ?? '—' }}</td>
                                <td>
                                    @if ($delivery->is_supplied)
                                        <span class="badge badge-success">סופקה</span>
                                    @elseif ($subscription->isActive())
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="markDeliverySupplied({{ $subscription->id }}, {{ $delivery->id }})">סימון כסופקה</button>
                                    @else
                                        <span class="badge badge-neutral">טרם סופקה</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-md)">
                    סימון "סופקה" הוא פעולה ידנית בלבד ואינה נגזרת משליחת חומרי לימוד (FR-3.15). לאחר סימון התוכנית העשירית המנוי מסתיים אוטומטית ואינו מתחדש (FR-3.16/FR-3.17) — חידוש מתבצע ביצירת עסקה חדשה (FR-3.18).
                </p>

                {{-- ===== ביטול מנוי וחישוב קיזוז (US-010) ===== --}}
                @if ($subscription->isActive())
                    <div style="margin-top:var(--sp-lg); display:flex; justify-content:flex-end">
                        <button
                            type="button"
                            class="btn btn-destructive"
                            wire:click="cancelSubscription({{ $subscription->id }})"
                            wire:confirm="ביטול המנוי יסמן אותו כמבוטל לצמיתות ויחשב זיכוי לפי התוכניות שטרם סופקו. פעולה זו אינה הפיכה — להמשיך?"
                        >ביטול מנוי</button>
                    </div>
                @elseif ($subscription->status?->name === \App\Models\Subscription::CANCELLED_STATUS_NAME)
                    <div class="card confirm-row" style="margin-top:var(--sp-lg); background:var(--color-background)">
                        <div style="display:flex; gap:var(--sp-xl); flex-wrap:wrap; font-size:var(--fs-small)">
                            <div><span style="color:var(--color-text-secondary)">תאריך ביטול: </span><span class="ltr-num" style="font-weight:600">{{ $subscription->cancelled_at?->format('d/m/Y') }}</span></div>
                            <div><span style="color:var(--color-text-secondary)">קיזוז מחושב: </span><span class="ltr-num" style="font-weight:600">₪{{ number_format((float) $subscription->cancellation_credit, 0) }}</span></div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            wire:click="generateSubscriptionCreditNote({{ $subscription->id }})"
                            @disabled(! \App\Models\Document::canGenerate($subscription->deal, 'credit_note'))
                        >הפקת חשבונית זיכוי</button>
                    </div>
                    @unless (\App\Models\Document::canGenerate($subscription->deal, 'credit_note'))
                        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-sm)">לא ניתן להפיק חשבונית זיכוי — לעסקה זו טרם הופקה חשבונית (FR-4.5/FR-8.12).</p>
                    @endunless
                @endif
            </div>
        @empty
            <div class="card empty-state">אין ללקוחה זו מנוי — מנוי נפתח אוטומטית עם יצירת עסקה עבור תוכנית המנוי השנתי (FR-3.12).</div>
        @endforelse
    @elseif ($activeTab === 'deals')
        @if ($dealError)
            <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
                {{ $dealError }}
            </div>
        @endif
        <div class="cols2">
            <div>
                <div class="card">
                    <h3>עסקאות הלקוחה</h3>
                    @forelse ($this->deals as $deal)
                        <div class="deal-row">
                            <div>
                                <div style="font-weight:600">עסקה #{{ $deal->id }} — {{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</div>
                                <div style="font-size:var(--fs-caption); color:var(--color-text-secondary)">נפתחה <span class="ltr-num">{{ $deal->purchased_at->format('d/m/Y') }}</span></div>
                            </div>
                            <div style="display:flex; align-items:center; gap:var(--sp-md)">
                                <span class="amount ltr-num">₪{{ number_format((float) $deal->agreed_amount, 0) }}</span>
                                <span class="badge {{ \App\Models\Deal::badgeClassForStatusName($deal->status?->name) }}">{{ $deal->status?->name }}</span>
                                <a href="{{ route('deal-detail', $deal) }}" class="btn btn-ghost btn-sm">פתיחת עסקה</a>
                            </div>
                        </div>
                    @empty
                        <p class="text-text-secondary" style="font-size:var(--fs-small)">אין עדיין עסקאות ללקוחה זו.</p>
                    @endforelse
                </div>
            </div>

            <div>
                <div class="card">
                    <h3>עסקה חדשה</h3>
                    <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-6px">תוכנית אחת או מארז אחד בלבד לעסקה — רכישת כמה תוכניות יוצרת כמה עסקאות נפרדות (FR-3.3/FR-3.4).</p>
                    <form wire:submit="createDeal" class="form-grid">
                        <div class="full">
                            <label for="dealItem">תוכנית / מארז</label>
                            <select id="dealItem" wire:model="dealItem">
                                <option value="">בחרו תוכנית או מארז</option>
                                @if ($this->availablePrograms->isNotEmpty())
                                    <optgroup label="תוכניות">
                                        @foreach ($this->availablePrograms as $program)
                                            <option value="program:{{ $program->id }}">{{ $program->name }} (₪{{ number_format((float) $program->price, 0) }})</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if ($this->availableBundles->isNotEmpty())
                                    <optgroup label="מארזים">
                                        @foreach ($this->availableBundles as $bundle)
                                            <option value="bundle:{{ $bundle->id }}">{{ $bundle->name }} (₪{{ number_format((float) $bundle->price, 0) }})</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                            @error('dealItem') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="dealAgreedAmount">סכום מוסכם (אופציונלי — ברירת מחדל: מחיר המחירון)</label>
                            <input type="text" id="dealAgreedAmount" wire:model="dealAgreedAmount" class="ltr-num" dir="ltr" placeholder="₪">
                            @error('dealAgreedAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="dealPaymentMethodId">אמצעי תשלום</label>
                            <select id="dealPaymentMethodId" wire:model="dealPaymentMethodId">
                                <option value="">— ללא —</option>
                                @foreach ($this->paymentMethods as $method)
                                    <option value="{{ $method->id }}">{{ $method->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="full">
                            <label for="dealSpecialRequest">בקשת התאמה מיוחדת (FR-3.7)</label>
                            <textarea id="dealSpecialRequest" wire:model="dealSpecialRequest" rows="2"></textarea>
                        </div>
                        <div class="full"><button type="submit" class="btn btn-primary">יצירת עסקה</button></div>
                    </form>
                </div>
            </div>
        </div>
    @elseif ($activeTab === 'billing')
        <div class="card">
            <h3>מצב תשלומים ויתרת חוב</h3>

            @if ($this->outstandingBalance > 0)
                <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:600;">
                    ללקוחה זו יתרת חוב פתוחה בסך <span class="ltr-num">₪{{ number_format($this->outstandingBalance, 0) }}</span> (FR-2.15).
                </div>
            @else
                <div class="mb-8" style="background: var(--color-success-bg); color: var(--color-success); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:600;">
                    אין יתרת חוב פתוחה ללקוחה זו.
                </div>
            @endif

            @if ($this->openCollectionTask)
                <div class="field">
                    <div class="k">משימת גבייה פתוחה</div>
                    <div class="v"><a href="{{ route('deal-detail', $this->openCollectionTask->deal) }}">{{ $this->openCollectionTask->title }} ←</a></div>
                </div>
            @endif

            <table>
                <thead><tr><th>עסקה</th><th>סכום עסקה</th><th>שולם</th><th>יתרה</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->deals as $deal)
                        <tr>
                            <td>#{{ $deal->id }} — {{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</td>
                            <td class="ltr-num">₪{{ number_format((float) $deal->agreed_amount, 0) }}</td>
                            <td class="ltr-num">₪{{ number_format($deal->totalPaid(), 0) }}</td>
                            <td class="ltr-num" style="{{ $deal->outstandingBalance() > 0 ? 'color:var(--color-error); font-weight:700' : '' }}">₪{{ number_format($deal->outstandingBalance(), 0) }}</td>
                            <td><a href="{{ route('deal-detail', $deal) }}" class="btn btn-ghost btn-sm">פתיחת עסקה</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">אין עדיין עסקאות ללקוחה זו.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-md)">מסמכים ותשלומים מפורטים לכל עסקה נמצאים בכרטיס העסקה עצמה.</p>
        </div>
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
