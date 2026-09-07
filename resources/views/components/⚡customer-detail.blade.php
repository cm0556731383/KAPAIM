<?php

use App\Concerns\Notifies;
use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\ExternalOperation;
use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Models\MaterialDelivery;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Subscription;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

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
 *
 * Build-plan 10 fills in the "חומרים" tab for real: sending materials
 * (MaterialDelivery::sendFor(), FR-5.1-FR-5.9/FR-8.13/FR-8.14) with a real
 * Livewire file upload (WithFileUploads — the file is discarded right after
 * "sending", FR-5.6/FR-5.20) and per-delivery open/ack status (FR-5.14).
 * Every materials action below is additionally gated on materials.manage —
 * same pattern as subscriptions.manage above.
 */
new
#[Layout('layouts.app', ['title' => 'כרטיס לקוחה — כפיים'])]
class extends Component
{
    use WithFileUploads;
    use Notifies;

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

    // ===== חומרים (MATERIAL_DELIVERY) =====
    public string $materialsProgramId = '';

    /** [{contact_id: ?int, name: string, email: string}, ...] — this send's recipients (FR-5.4/FR-5.5). */
    public array $materialsRecipients = [];

    public string $materialsRecipientName = '';

    public string $materialsRecipientEmail = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile[] */
    public array $materialsFiles = [];

    /** Business-rule error from MaterialDelivery::sendFor() (FR-5.2/FR-5.3/FR-8.13/FR-8.14). */
    public ?string $materialsError = null;

    /**
     * Stage 19 hardening: real Livewire validation on the material-send
     * attachment upload. Per docs/storyboard/materials-send.html ("PDF,
     * PPTX או קובץ מדיה — עד 25MB") — a 25MB per-file cap and a whitelist
     * covering documents/presentations and common media/image types a
     * school could receive. Applied both on selection (immediate feedback,
     * see updatedMaterialsFiles()) and again in sendMaterials() as a
     * server-side safety net before anything is forwarded.
     *
     * Deliberately `extensions` (checked against the original client
     * filename) rather than `mimes` (content-sniffed): the attachment is
     * never persisted to real disk (FR-5.6/FR-5.20 — discarded right after
     * the stubbed Smove send), so there's no stored file for a later
     * content-based scan to matter, and `extensions` is what Livewire's own
     * temporary-upload test doubles reliably report.
     */
    private const MATERIALS_FILE_RULES = ['file', 'max:25600', 'extensions:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,gif,mp4,mp3'];

    public function mount(Customer $customer): void
    {
        abort_unless(auth()->user()->can('customers.manage'), 403);

        $this->customer = $customer->load(['school', 'lead']);
        $this->syncSchoolFields();

        // FR-5.4: defaults the send form to the customer's primary contacts
        // with a real email — the user may freely add/remove for this one
        // send afterwards without ever touching contacts.is_primary (FR-5.5).
        $this->materialsRecipients = MaterialDelivery::defaultRecipients($this->customer)
            ->map(fn (Contact $contact) => ['contact_id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email])
            ->values()
            ->all();
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

        // School name feeds the Smove contact's display name — re-push so
        // an edit here doesn't leave Smove showing a stale name.
        MailingMembership::addCustomer(MailingList::primaryList(), $this->customer);

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

        // A customer with no contacts yet had no email to sync to Smove at
        // conversion time (see MailingMembership::pushToSmove()'s no-email
        // skip) — the first (forced-primary) contact is often the first
        // real chance to actually push them.
        if ($contact->is_primary) {
            MailingMembership::addCustomer(MailingList::primaryList(), $this->customer);
        }

        $this->contactError = null;
        $this->resetContactForm();
        unset($this->contacts);

        $this->notifySuccess("איש קשר \"{$contact->name}\" נוסף בהצלחה.");
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
            $this->contactError = 'לא ניתן להסיר את הסימון מאיש הקשר הראשי האחרון — לכל לקוחה חייב להיות לפחות איש קשר ראשי אחד.';

            return;
        }

        $contact->update($data);

        $activityLogger->log('contact.updated', "עודכן איש קשר \"{$contact->name}\"", [
            'customer_id' => $this->customer->id, 'lead_id' => $this->customer->lead_id, 'school_id' => $contact->school_id,
        ]);

        // The synced Smove contact uses the PRIMARY contact's email/name
        // (see MailingMembership::addCustomer()) — only that one's edits
        // are relevant to re-push.
        if ($data['is_primary']) {
            MailingMembership::addCustomer(MailingList::primaryList(), $this->customer);
        }

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
            $this->contactError = 'לא ניתן להסיר את איש הקשר הראשי האחרון — לכל לקוחה חייב להיות לפחות איש קשר ראשי אחד.';

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
        unset($this->recentlyRemovedContacts);

        $this->notifySuccess("איש קשר \"{$name}\" הוסר.");
    }

    /**
     * FR-8.22: restores a contact removed within the last 30 days (see
     * recentlyRemovedContacts() below) via Contact::restore().
     */
    public function restoreContact(int $id, ActivityLogger $activityLogger): void
    {
        $contact = Contact::onlyTrashed()->findOrFail($id);
        $contact->restore($activityLogger);

        unset($this->contacts);
        unset($this->recentlyRemovedContacts);

        $this->notifySuccess("איש קשר \"{$contact->name}\" שוחזר.");
    }

    /**
     * FR-8.22: restores a personal/collection task tied to this customer
     * and cancelled within the last 30 days (see recentlyRemovedTasks()
     * below) via Task::restore().
     */
    public function restoreTask(int $id, ActivityLogger $activityLogger): void
    {
        $task = Task::onlyTrashed()->findOrFail($id);
        $task->restore($activityLogger);

        unset($this->recentlyRemovedTasks);

        $this->notifySuccess("המשימה \"{$task->title}\" שוחזרה.");
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
            $this->subscriptionError = 'יש לבחור תוכנית עבור שורת האספקה לפני הסימון.';

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

        $this->notifySuccess("תוכנית מס' {$delivery->sequence_number} סומנה כסופקה.");

        if ($subscription->fresh()->status?->name === Subscription::ENDED_STATUS_NAME) {
            $activityLogger->log('subscription.ended', "מנוי #{$subscription->id} הסתיים אוטומטית לאחר סימון התוכנית העשירית", [
                'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            ]);

            // FR-7.23 — a genuinely informational outcome distinct from the
            // plain "marked as supplied" success above.
            $this->notifyInfo('המנוי הסתיים אוטומטית לאחר סימון התוכנית העשירית.');
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

        $this->notifySuccess('המנוי בוטל בהצלחה.');
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

    // ----- חומרים (MATERIAL_DELIVERY) -----

    #[Computed]
    public function materialDeliveries()
    {
        return MaterialDelivery::where('customer_id', $this->customer->id)
            ->with(['program', 'status'])
            ->orderByDesc('sent_at')
            ->get();
    }

    /** FR-5.19: any active catalog program's materials may be sent, premium included. */
    #[Computed]
    public function materialsPrograms()
    {
        return Program::where('is_active', true)->orderBy('name')->get();
    }

    /** FR-5.5: adds an ad-hoc recipient for this send only — never touches contacts.is_primary. */
    public function addMaterialsRecipient(): void
    {
        $email = trim($this->materialsRecipientEmail);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->materialsError = 'יש להזין כתובת דוא"ל תקינה עבור הנמען הנוסף.';

            return;
        }

        $this->materialsError = null;
        $this->materialsRecipients[] = [
            'contact_id' => null,
            'name' => $this->materialsRecipientName !== '' ? $this->materialsRecipientName : $email,
            'email' => $email,
        ];
        $this->materialsRecipientName = '';
        $this->materialsRecipientEmail = '';
    }

    /** FR-5.5: removes a recipient for this send only — never touches contacts.is_primary. */
    public function removeMaterialsRecipient(int $index): void
    {
        unset($this->materialsRecipients[$index]);
        $this->materialsRecipients = array_values($this->materialsRecipients);
    }

    /**
     * Stage 19 hardening: validates each newly-selected attachment the
     * moment Livewire finishes uploading it to temp storage, so an
     * oversized/wrong-type file is rejected immediately rather than only
     * at submit time.
     */
    public function updatedMaterialsFiles(): void
    {
        $this->validate(['materialsFiles.*' => self::MATERIALS_FILE_RULES]);
    }

    /**
     * FR-5.1/FR-5.7: the only place this screen sends materials —
     * MaterialDelivery::sendFor() enforces the "at least one recipient with
     * a valid email" / "at least one attachment" gates server-side
     * (FR-5.2/FR-5.3/FR-8.13/FR-8.14). FR-5.6/FR-5.20: Smove's real API
     * (build-plan 12) has no attachment upload, so each file is moved from
     * Livewire's temporary storage into the 'local' disk permanently here —
     * MaterialDelivery::sendFor() emails a signed link to it instead of an
     * attachment (see MaterialDeliveryAttachment::downloadUrl()).
     */
    public function sendMaterials(ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        abort_unless(auth()->user()->can('materials.manage'), 403);

        $this->materialsError = null;

        if (! $this->materialsProgramId) {
            $this->materialsError = 'יש לבחור תוכנית לפני שליחת חומרי הלימוד.';

            return;
        }

        $this->validate(['materialsFiles.*' => self::MATERIALS_FILE_RULES]);

        $program = Program::find($this->materialsProgramId);
        $attachments = array_map(
            fn ($file) => [
                'file_reference' => $file->storeAs('material-attachments', $file->getFilename(), 'local'),
                'file_name' => $file->getClientOriginalName(),
            ],
            $this->materialsFiles,
        );

        try {
            $delivery = MaterialDelivery::sendFor($this->customer, $program, $this->materialsRecipients, $attachments, $activityLogger, $runner, $smove);
        } catch (\RuntimeException $e) {
            $this->materialsError = $e->getMessage();

            return;
        }

        if (ExternalOperation::where('material_delivery_id', $delivery->id)->where('status', ExternalOperation::STATUS_FAILED)->exists()) {
            $this->notifyWarning('חומרי הלימוד נרשמו במערכת, אך שליחתם בפועל דרך Smove נכשלה — ראו יומן פעילות.');
        }

        // FR-5.6/FR-5.20: discard the temp upload now that the Smove send
        // attempt is done — it is never persisted anywhere in this app.
        foreach ($this->materialsFiles as $file) {
            $file->delete();
        }

        $this->materialsFiles = [];
        $this->materialsProgramId = '';
        unset($this->materialDeliveries);

        $this->notifySuccess("חומרי הלימוד עבור \"{$program?->name}\" נשלחו בהצלחה.");
    }

    /** FR-5.17's manual half — dismisses a stale "needs attention" item with no dashboard yet to do it from. */
    public function markMaterialHandled(int $materialDeliveryId): void
    {
        abort_unless(auth()->user()->can('materials.manage'), 403);

        MaterialDelivery::findOrFail($materialDeliveryId)->markHandled();
        unset($this->materialDeliveries);
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

    /** FR-8.22 recovery panel — this customer's contacts removed in roughly the last 30 days. */
    #[Computed]
    public function recentlyRemovedContacts()
    {
        return Contact::onlyTrashed()
            ->where('customer_id', $this->customer->id)
            ->where('deleted_at', '>=', now()->subDays(30))
            ->orderByDesc('deleted_at')
            ->get();
    }

    /** FR-8.22 recovery panel — personal/collection tasks tied to this customer, cancelled in roughly the last 30 days. */
    #[Computed]
    public function recentlyRemovedTasks()
    {
        return Task::onlyTrashed()
            ->where('customer_id', $this->customer->id)
            ->where('deleted_at', '>=', now()->subDays(30))
            ->orderByDesc('deleted_at')
            ->get();
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
            התראת חריגת מחיר: סך רכישות התוכניות הבודדות (מחוץ למנוי) של לקוחה זו עולה על מחיר מנוי שנתי מלא.
        </div>
    @endif

    <x-business-error-banner :message="$contactError" />

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
                                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-error)" wire:click="removeContact({{ $contact->id }})" wire:confirm="הסרת איש קשר זה היא מחיקה לוגית — האם להמשיך?">הסרה</button>
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
                    <p style="font-size:var(--fs-caption); color:var(--color-text-secondary); margin-top:var(--sp-sm)">לכל לקוחה חייב להיות תמיד לפחות איש קשר ראשי אחד — לא ניתן להסיר את הסימון או למחוק את הראשי האחרון.</p>
                </div>

                @if ($this->recentlyRemovedContacts->isNotEmpty() || $this->recentlyRemovedTasks->isNotEmpty())
                    {{-- ===== פריטים שהוסרו לאחרונה (FR-8.22) ===== --}}
                    <div class="card" style="margin-top:var(--sp-lg)">
                        <h3>פריטים שהוסרו לאחרונה</h3>
                        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-4px">פריטים שהוסרו/בוטלו בשלושים הימים האחרונים — ניתן לשחזר.</p>

                        @foreach ($this->recentlyRemovedContacts as $contact)
                            <div class="list-item">
                                <div>
                                    <div style="font-weight:600">{{ $contact->name }}</div>
                                    <div class="who">איש קשר · הוסר ב-{{ $contact->deleted_at->format('d/m/Y') }}</div>
                                </div>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="restoreContact({{ $contact->id }})">שחזור</button>
                            </div>
                        @endforeach

                        @foreach ($this->recentlyRemovedTasks as $task)
                            <div class="list-item">
                                <div>
                                    <div style="font-weight:600">{{ $task->title }}</div>
                                    <div class="who">משימה · בוטלה ב-{{ $task->deleted_at->format('d/m/Y') }}</div>
                                </div>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="restoreTask({{ $task->id }})">שחזור</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @elseif ($activeTab === 'subscription')
        <x-business-error-banner :message="$subscriptionError" />

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
                <div class="table-scroll">
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
                </div>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-md)">
                    סימון "סופקה" הוא פעולה ידנית בלבד ואינה נגזרת משליחת חומרי לימוד. לאחר סימון התוכנית העשירית המנוי מסתיים אוטומטית ואינו מתחדש — חידוש מתבצע ביצירת עסקה חדשה.
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
                            wire:confirm="הפקת חשבונית זיכוי היא פעולה חשבונאית בלתי הפיכה — האם להמשיך?"
                            @disabled(! \App\Models\Document::canGenerate($subscription->deal, 'credit_note'))
                        >הפקת חשבונית זיכוי</button>
                    </div>
                    @unless (\App\Models\Document::canGenerate($subscription->deal, 'credit_note'))
                        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-sm)">לא ניתן להפיק חשבונית זיכוי — לעסקה זו טרם הופקה חשבונית.</p>
                    @endunless
                @endif
            </div>
        @empty
            <div class="card empty-state">אין ללקוחה זו מנוי — מנוי נפתח אוטומטית עם יצירת עסקה עבור תוכנית המנוי השנתי.</div>
        @endforelse
    @elseif ($activeTab === 'deals')
        <x-business-error-banner :message="$dealError" />
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
                    <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-6px">תוכנית אחת או מארז אחד בלבד לעסקה — רכישת כמה תוכניות יוצרת כמה עסקאות נפרדות.</p>
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
                            <label for="dealSpecialRequest">בקשת התאמה מיוחדת</label>
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
                    ללקוחה זו יתרת חוב פתוחה בסך <span class="ltr-num">₪{{ number_format($this->outstandingBalance, 0) }}</span>.
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

            <div class="table-scroll">
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
            </div>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-md)">מסמכים ותשלומים מפורטים לכל עסקה נמצאים בכרטיס העסקה עצמה.</p>
        </div>
    @elseif ($activeTab === 'materials')
        <x-business-error-banner :message="$materialsError" />
        <div class="cols2">
            <div class="card">
                <h3>שליחת חומרים</h3>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-6px">השליחה מתבצעת באמצעות Smove — הקבצים אינם נשמרים במערכת לאחר השליחה.</p>

                <form wire:submit="sendMaterials" class="form-grid">
                    <div class="full">
                        <label for="materialsProgramId">תוכנית</label>
                        <select id="materialsProgramId" wire:model="materialsProgramId">
                            <option value="">בחרו תוכנית</option>
                            @foreach ($this->materialsPrograms as $program)
                                <option value="{{ $program->id }}">{{ $program->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="full">
                        <label for="materialsFiles">קובץ מצורף</label>
                        <input type="file" id="materialsFiles" wire:model="materialsFiles" multiple>
                        <div class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">PDF, Word, PowerPoint או קובץ מדיה — עד 25MB לקובץ</div>
                        @error('materialsFiles.*') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        @if (! empty($materialsFiles))
                            <div class="chip-row" style="display:flex; flex-wrap:wrap; gap:var(--sp-sm); margin-top:var(--sp-sm)">
                                @foreach ($materialsFiles as $file)
                                    <span class="badge badge-neutral">{{ $file->getClientOriginalName() }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="full">
                        <label>נמענים</label>
                        <div class="chip-row" style="display:flex; flex-wrap:wrap; gap:var(--sp-sm); margin-bottom:var(--sp-sm)">
                            @forelse ($materialsRecipients as $index => $recipient)
                                <span class="badge badge-primary" style="display:inline-flex; align-items:center; gap:6px">
                                    {{ $recipient['name'] }}
                                    <button type="button" wire:click="removeMaterialsRecipient({{ $index }})" title="הסרה" aria-label="הסרת נמען" style="background:none; border:none; cursor:pointer; color:inherit; font-weight:700;">×</button>
                                </span>
                            @empty
                                <span class="text-text-secondary" style="font-size:var(--fs-caption)">אין עדיין נמענים — הוסיפו לפחות אחד לפני השליחה.</span>
                            @endforelse
                        </div>
                        <div class="field-row" style="display:flex; gap:var(--sp-sm)">
                            <input type="text" wire:model="materialsRecipientName" placeholder="שם הנמען (אופציונלי)">
                            <input type="email" wire:model="materialsRecipientEmail" placeholder="הוספת נמען לפי כתובת דוא&quot;ל" class="ltr-num" dir="ltr">
                            <button type="button" class="btn btn-secondary" wire:click="addMaterialsRecipient">הוספה</button>
                        </div>
                    </div>

                    <p class="text-text-secondary" style="font-size:var(--fs-caption)">יש לצרף קובץ אחד לפחות ולבחור נמען אחד לפחות לפני השליחה.</p>

                    <div class="full"><button type="submit" class="btn btn-primary">שליחה באמצעות Smove</button></div>
                </form>
            </div>

            <div class="card">
                <h3>היסטוריית משלוחים</h3>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-8px">כל שליחה חוזרת מתועדת כמשלוח חדש.</p>
                @if ($this->materialDeliveries->isEmpty())
                    <div class="empty-state">אין עדיין משלוחי חומרי לימוד ללקוחה זו.</div>
                @else
                    <div class="table-scroll">
                    <table>
                        <thead><tr><th>תאריך</th><th>תוכנית</th><th>סטטוס</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($this->materialDeliveries as $delivery)
                                <tr>
                                    <td class="ltr-num">{{ $delivery->sent_at->format('d/m/Y') }}</td>
                                    <td>{{ $delivery->program?->name }}</td>
                                    <td>
                                        @if ($delivery->acknowledged_at)
                                            <span class="badge badge-success">התקבל, תודה</span>
                                            <div style="font-size:var(--fs-caption); color:var(--color-text-secondary); margin-top:4px" class="ltr-num">{{ $delivery->acknowledged_at->format('d/m/Y H:i') }}</div>
                                        @else
                                            <span class="badge {{ \App\Models\MaterialDelivery::badgeClassForStatusName($delivery->status?->name) }}">ממתין לאישור</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if (! $delivery->acknowledged_at && ! $delivery->handled_at)
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="markMaterialHandled({{ $delivery->id }})">סימון כטופל</button>
                                        @elseif ($delivery->handled_at)
                                            <span class="text-text-secondary" style="font-size:var(--fs-caption)">טופל ידנית</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </div>
        </div>
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
