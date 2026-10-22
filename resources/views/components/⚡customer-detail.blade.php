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
use App\Services\Integrations\SummitClient;
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

    // ===== פרטים נוספים (עריכה) =====
    public bool $editingSchoolDetails = false;
    public string $schoolInvoiceName = '';
    public string $schoolBusinessNumber = '';
    public string $schoolSyllable = '';
    public string $schoolClassesPerGrade = '';

    public string $schoolOwnershipName = '';

    public bool $schoolGefenBudget = false;

    public string $schoolContentFormat = '';
    public string $schoolNotes = '';
    public $newLogo = null;

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

    /** Pops up (sticky) while the primary contact opened at lead conversion still has no name. */
    public ?string $contactNameWarning = null;

    // ===== עסקה חדשה (DEAL) =====
    /** "program:{id}" or "bundle:{id}" — a single select so exactly one type is ever chosen. */
    public string $dealItem = '';

    public string $dealAgreedAmount = '';

    /** Shown under the amount when it was filled in with the subscriber discount. */
    public ?string $dealDiscountNote = null;

    public string $dealSpecialRequest = '';

    public string $dealStudentsCount = '';

    public string $dealPaymentMethodId = '';

    /** Business-rule error (FR-7.25) from Deal::createForCustomer() (FR-3.3/FR-8.4/FR-8.5). */
    public ?string $dealError = null;

    // ===== מנוי (SUBSCRIPTION / SUBSCRIPTION_DELIVERY) =====
    /** delivery_id => chosen program_id, bound per delivery row. */
    public array $deliveryProgramSelections = [];

    public ?int $editingMonthlyPaymentForSubscriptionId = null;

    public string $monthlyPaymentOverrideInput = '';

    /** subscription_id => chosen cancellation month (1-10), bound per subscription card. */
    public array $cancelFromMonths = [];

    /** subscription_id => credit amount in the cancellation window (filled from the month, editable). */
    public array $cancelCredits = [];

    /** subscription_id => why the subscription is cancelled. */
    public array $cancelReasons = [];

    /** Choosing the month fills in its computed credit — still editable after. */
    /** Fills the agreed amount with the chosen item's price (FR-3.11 discount included) — still editable. */
    public function updatedDealItem(): void
    {
        [$type, $id] = array_pad(explode(':', $this->dealItem, 2), 2, null);
        $program = $type === 'program' ? Program::find($id) : null;
        $bundle = $type === 'bundle' ? Bundle::find($id) : null;

        $this->dealDiscountNote = null;

        if (! $program && ! $bundle) {
            $this->dealAgreedAmount = '';

            return;
        }

        $amount = Deal::defaultAgreedAmount($this->customer, $program, $bundle);
        $this->dealAgreedAmount = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        if (Deal::hasSubscriberDiscount($this->customer, $program)) {
            $this->dealDiscountNote = 'הלקוחה מנויה, ולכן חלה הנחת מנוי של 10% על תוכנית הפרימיום (מחיר המחירון: ₪'.number_format((float) $program->price, 0).').';
        }
    }

    public function updatedCancelFromMonths($value, $subscriptionId): void
    {
        $subscription = Subscription::where('customer_id', $this->customer->id)->find($subscriptionId);

        $this->cancelCredits[$subscriptionId] = $subscription && (int) $value > 0
            ? (string) $subscription->creditFor((int) $value)
            : '';
    }

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

    /** [{name, url}, ...] — links sent with the files, e.g. a video too big to upload. */
    public array $materialsLinks = [];

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
    /** 95MB — Cloudflare (in front of the site) refuses an upload over 100MB; bigger videos go as a link instead. */
    private const MATERIALS_FILE_RULES = ['file', 'max:97280', 'extensions:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,gif,mp4,mov,mp3'];

    public function mount(Customer $customer): void
    {
        abort_unless(auth()->user()->can('customers.view'), 403);

        $this->customer = $customer->load(['school', 'lead']);
        $this->syncSchoolFields();

        // ?tab=materials etc. — a dashboard item opens the card on the tab it's about.
        if (in_array(request()->query('tab'), ['info', 'subscription', 'deals', 'billing', 'materials', 'activity'], true)) {
            $this->activeTab = request()->query('tab');
        }

        $namelessPrimary = $this->customer->contacts()->where('is_primary', true)->where('name', '')->first();

        if ($namelessPrimary) {
            $this->contactNameWarning = 'יש להשלים את שם איש הקשר הראשי — הטלפון והמייל הועתקו מהליד.';
            $this->editContact($namelessPrimary->id);
        }

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
        $this->schoolInvoiceName = $school?->invoice_name ?? '';
        $this->schoolBusinessNumber = $school?->business_number ?? '';
        $this->schoolSyllable = $school?->syllable ?? '';
        $this->schoolClassesPerGrade = (string) ($school?->classes_per_grade ?? '');
        $this->schoolOwnershipName = $school?->ownership_name ?? '';
        $this->schoolGefenBudget = (bool) $school?->gefen_budget;
        $this->schoolContentFormat = $school?->content_format ?? '';
        $this->schoolNotes = $school?->notes ?? '';
    }

    // ----- פרטי בית ספר -----

    public function saveSchool(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'schoolName' => ['required', 'string', 'max:255'],
            'schoolCity' => ['nullable', 'string', 'max:255'],
            'schoolAddress' => ['nullable', 'string', 'max:255'],
            'schoolPhone' => ['nullable', 'string', 'max:50', new \App\Rules\Phone],
            'schoolEmail' => ['nullable', 'email', 'max:255'],
            'schoolInvoiceName' => ['nullable', 'string', 'max:255'],
            'schoolBusinessNumber' => ['nullable', 'string', 'max:20', 'regex:/^[0-9][0-9\s-]*$/'],
            'schoolNotes' => ['nullable', 'string'],
        ], [
            'schoolBusinessNumber.regex' => 'ח.פ. יכול להכיל ספרות בלבד (ומקפים).',
        ], ['schoolName' => 'שם המוסד', 'schoolInvoiceName' => 'שם לחשבונית', 'schoolBusinessNumber' => 'ח.פ.']);

        $this->customer->school->update([
            'name' => $data['schoolName'],
            'city' => $data['schoolCity'] ?: null,
            'address' => $data['schoolAddress'] ?: null,
            'phone' => $data['schoolPhone'] ?: null,
            'email' => $data['schoolEmail'] ?: null,
            'invoice_name' => trim((string) $data['schoolInvoiceName']) ?: null,
            'business_number' => trim((string) $data['schoolBusinessNumber']) ?: null,
            'notes' => $data['schoolNotes'] ?: null,
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

    public function saveSchoolDetails(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'schoolSyllable' => ['nullable', 'string', 'max:255'],
            'schoolClassesPerGrade' => ['nullable', 'integer', 'min:0'],
            'schoolOwnershipName' => ['nullable', 'string', 'max:255'],
            'schoolGefenBudget' => ['boolean'],
            'schoolContentFormat' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Models\School::CONTENT_FORMATS))],
            'newLogo' => ['nullable', 'image', 'max:5120'],
        ], [], ['schoolClassesPerGrade' => 'מספר כיתות בשנתון']);

        $school = $this->customer->school;

        $attributes = [
            'syllable' => $data['schoolSyllable'] ?: null,
            'classes_per_grade' => $data['schoolClassesPerGrade'] !== null && $data['schoolClassesPerGrade'] !== ''
                ? (int) $data['schoolClassesPerGrade']
                : null,
            'ownership_name' => trim($data['schoolOwnershipName']) ?: null,
            'gefen_budget' => $data['schoolGefenBudget'],
            'content_format' => $data['schoolContentFormat'] ?: null,
        ];

        if ($this->newLogo) {
            $attributes['logo_path'] = $this->newLogo->store('school-logos', 'public');
        }

        $school->update($attributes);

        $activityLogger->log('customer.school_details_updated', "עודכנו פרטים נוספים עבור בית ספר \"{$school->name}\"", [
            'customer_id' => $this->customer->id,
            'lead_id' => $this->customer->lead_id,
            'school_id' => $this->customer->school_id,
        ]);

        $this->newLogo = null;
        $this->customer->refresh()->load('school');
        $this->syncSchoolFields();
        $this->editingSchoolDetails = false;

        $this->notifySuccess('הפרטים הנוספים נשמרו בהצלחה.');
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

        // 2026-09-08 request: an *additional* contact (this customer already
        // has at least one, so addCustomer() above no-ops for it — see its
        // own docblock) still needs to reach Smove on its own, so a mailing
        // sent to this customer reaches every contact, not just the first.
        MailingMembership::syncContact($this->customer, $contact);

        $this->contactError = null;
        $this->resetContactForm();
        unset($this->contacts);

        $this->dispatch('close-modals');
        $this->notifySuccess("איש קשר \"{$contact->name}\" נוסף בהצלחה.");
    }

    public function editContact(int $id): void
    {
        $contact = Contact::where('school_id', $this->customer->school_id)->findOrFail($id);
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
        $contact = Contact::where('school_id', $this->customer->school_id)->findOrFail($this->editingContactId);
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
        $this->contactNameWarning = null;
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
        $contact = Contact::where('school_id', $this->customer->school_id)->findOrFail($id);

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
        $contact = Contact::onlyTrashed()->where('school_id', $this->customer->school_id)->findOrFail($id);
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
        $task = Task::onlyTrashed()->where('customer_id', $this->customer->id)->findOrFail($id);
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
            'dealAgreedAmount' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
            'dealSpecialRequest' => ['nullable', 'string'],
            'dealPaymentMethodId' => ['nullable', 'exists:payment_methods,id'],
            'dealStudentsCount' => ['nullable', 'integer', 'min:0'],
        ], [], ['dealItem' => 'תוכנית / מארז', 'dealStudentsCount' => 'מספר תלמידות']);

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

        if ($data['dealStudentsCount'] !== null && $data['dealStudentsCount'] !== '') {
            $deal->update(['students_count' => (int) $data['dealStudentsCount']]);
        }

        $itemName = $deal->program_name_snapshot ?? $deal->bundle_name_snapshot;
        $discount = Deal::hasSubscriberDiscount($this->customer, $program)
            && abs((float) $deal->agreed_amount - Deal::defaultAgreedAmount($this->customer, $program, $bundle)) < 0.005
            ? ' (כולל הנחת מנוי של 10%)' : '';
        $activityLogger->log('deal.created', "נוצרה עסקה חדשה #{$deal->id} עבור לקוחה \"{$this->customer->school?->name}\": {$itemName}{$discount}", [
            'deal_id' => $deal->id, 'customer_id' => $this->customer->id,
        ]);

        $this->reset(['dealItem', 'dealAgreedAmount', 'dealDiscountNote', 'dealSpecialRequest', 'dealPaymentMethodId', 'dealStudentsCount']);
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
        return Program::where('is_active', true)->orderBy('name')->get();
    }

    /**
     * FR-3.14/FR-8.19: the only place this screen marks a delivery supplied —
     * Subscription::markDeliverySupplied() enforces the version-guarded
     * optimistic lock and the "active, non-subscription program" rule
     * server-side. FR-3.15: purely manual, no materials-sending hook exists
     * anywhere in this method or the model it calls.
     */
    public function markDeliverySupplied(int $subscriptionId, int $deliveryId, ActivityLogger $activityLogger, bool $confirmed = false): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $this->subscriptionError = null;

        $programId = $this->deliveryProgramSelections[$deliveryId] ?? null;

        if (! $programId) {
            $this->subscriptionError = 'יש לבחור תוכנית עבור שורת האספקה לפני הסימון.';

            return;
        }

        $subscription = Subscription::where('customer_id', $this->customer->id)->findOrFail($subscriptionId);

        if (! $confirmed && ($warning = $subscription->deliverySupplyWarning($deliveryId, (int) $programId))) {
            $this->js('confirmDialog('.json_encode($warning, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).").then((ok) => ok && \$wire.markDeliverySupplied({$subscriptionId}, {$deliveryId}, true))");

            return;
        }

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

    public function unmarkDeliverySupplied(int $subscriptionId, int $deliveryId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $this->subscriptionError = null;
        $subscription = Subscription::where('customer_id', $this->customer->id)->findOrFail($subscriptionId);

        try {
            $delivery = $subscription->unmarkDeliverySupplied($subscription->version, $deliveryId);
        } catch (\RuntimeException $e) {
            $this->subscriptionError = $e->getMessage();

            return;
        }

        $activityLogger->log('subscription.delivery_unsupplied', "בוטל סימון האספקה של תוכנית מס' {$delivery->sequence_number} במנוי #{$subscription->id}", [
            'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
        ]);

        unset($this->subscriptions, $this->hasActiveSubscription);
        $this->notifySuccess('סימון האספקה בוטל.');
    }

    /**
     * US-010/FR-8.24: the only place this screen cancels a subscription —
     * from the month chosen on its card. Subscription::cancel() computes the
     * credit for the months from it onward and is a status-only change
     * (never deletes the deal/subscription/delivery history/activity log).
     */
    public function cancelSubscription(int $subscriptionId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $this->subscriptionError = null;

        $subscription = Subscription::where('customer_id', $this->customer->id)->findOrFail($subscriptionId);

        $fromMonth = (int) ($this->cancelFromMonths[$subscriptionId] ?? 0);

        if ($fromMonth < 1) {
            $this->subscriptionError = 'יש לבחור החל מאיזה חודש המנוי מבוטל.';

            return;
        }

        $reason = trim((string) ($this->cancelReasons[$subscriptionId] ?? ''));
        $credit = trim((string) ($this->cancelCredits[$subscriptionId] ?? ''));

        if ($reason === '') {
            $this->subscriptionError = 'יש לרשום את סיבת הביטול.';

            return;
        }

        if (! is_numeric($credit)) {
            $this->subscriptionError = 'יש להזין סכום זיכוי (מספר; 0 אם אין זיכוי).';

            return;
        }

        try {
            $subscription->cancel($fromMonth, (float) $credit, $reason);
        } catch (\RuntimeException $e) {
            $this->subscriptionError = $e->getMessage();

            return;
        }

        $activityLogger->log('subscription.cancelled', "מנוי #{$subscription->id} בוטל החל מחודש {$fromMonth} ({$subscription->monthLabel($fromMonth)}) — קיזוז מחושב: ₪".number_format((float) $subscription->cancellation_credit, 0), [
            'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            'metadata' => ['cancellation_credit' => (float) $subscription->cancellation_credit, 'cancelled_from_month' => $fromMonth, 'reason' => $reason],
        ]);

        unset($this->subscriptions, $this->hasActiveSubscription);
        $this->dispatch('close-modals');

        $this->notifySuccess('המנוי בוטל בהצלחה.');

        if ($subscription->deal->standingOrderItemIds() !== []) {
            $this->notifyWarning('לעסקה זו הוראת קבע ב-SUMIT — יש לעצור אותה גם ב-SUMIT, אחרת החיובים ימשיכו.', sticky: true);
        }
    }

    /**
     * FR-4.5/FR-8.12: a deliberately separate explicit action from
     * cancelSubscription() above — Subscription::generateCreditNote()
     * re-checks the "deal already has an invoice" gate server-side via
     * Document::generateCreditNoteFor().
     */
    public function generateSubscriptionCreditNote(int $subscriptionId, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit, SmoveClient $smove): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $this->subscriptionError = null;

        $subscription = Subscription::where('customer_id', $this->customer->id)->findOrFail($subscriptionId);

        try {
            ['document' => $document, 'operations' => $operations] = $subscription->issueCreditNote($activityLogger, $runner, $summit, $smove);
        } catch (\RuntimeException $e) {
            $this->subscriptionError = $e->getMessage();

            return;
        }

        $activityLogger->log('document.credit_note_generated', "הופקה חשבונית זיכוי עבור מנוי #{$subscription->id}", [
            'subscription_id' => $subscription->id, 'customer_id' => $this->customer->id, 'deal_id' => $subscription->deal_id,
            'metadata' => ['document_id' => $document->id],
        ]);

        unset($this->subscriptions);

        if (($operations['summit'] ?? null)?->status === \App\Models\ExternalOperation::STATUS_FAILED) {
            $this->subscriptionError = 'ההפקה ב-SUMIT נכשלה, ולכן טיוטת הזיכוי נמחקה ולא נשלח דבר ללקוחה. אפשר לנסות שוב. פירוט השגיאה: '.$operations['summit']->error_message;

            return;
        }

        $this->notifySuccess('הזיכוי הופק ב-SUMIT ונשלח ללקוחה במייל.');
    }

    /** FR-3.20: loads the current override (if any) into the edit form. */
    public function editMonthlyPayment(int $subscriptionId): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $subscription = Subscription::where('customer_id', $this->customer->id)->findOrFail($subscriptionId);
        $this->editingMonthlyPaymentForSubscriptionId = $subscriptionId;
        $this->monthlyPaymentOverrideInput = $subscription->monthly_payment_override !== null
            ? (string) $subscription->monthly_payment_override
            : '';
    }

    /** FR-3.20: an empty value clears the override, falling back to the computed default again. */
    public function saveMonthlyPayment(int $subscriptionId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('subscriptions.manage'), 403);

        $data = $this->validate(['monthlyPaymentOverrideInput' => ['nullable', 'numeric', 'gt:0', \App\Support\Money::maxRule()]]);

        $subscription = Subscription::where('customer_id', $this->customer->id)->findOrFail($subscriptionId);
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

    /**
     * Only programs this customer bought — directly, or inside a bundle — on
     * a deal that wasn't cancelled (business owner's call, 2026-10-13;
     * replaces FR-5.19's "any active catalog program"). A cancelled
     * subscription still counts for its months before the cancellation,
     * supplied or not — Subscription::programIdsBeforeCancellation()
     * (business owner's call, 2026-10-17).
     */
    #[Computed]
    public function materialsPrograms()
    {
        $deals = $this->customer->deals()
            ->whereDoesntHave('status', fn ($q) => $q->where('name', Deal::CANCELLED_STATUS_NAME))
            ->get(['id', 'program_id', 'bundle_id']);

        $beforeCancellation = $this->customer->subscriptions()
            ->whereNotNull('cancelled_from_month')
            ->with(['deliveries', 'deal.bundle.programs'])
            ->get()
            ->flatMap->programIdsBeforeCancellation();

        return Program::where(fn ($q) => $q
            ->whereIn('id', $deals->pluck('program_id')->filter())
            ->orWhereIn('id', $beforeCancellation)
            ->orWhereHas('bundles', fn ($b) => $b->whereIn('bundles.id', $deals->pluck('bundle_id')->filter())))
            ->orderBy('name')
            ->get();
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

    public function addMaterialsLink(): void
    {
        $this->materialsLinks[] = ['name' => '', 'url' => ''];
    }

    public function removeMaterialsLink(int $index): void
    {
        unset($this->materialsLinks[$index]);
        $this->materialsLinks = array_values($this->materialsLinks);
        $this->resetErrorBag();
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

        if (empty($this->materialsFiles) && empty($this->materialsLinks)) {
            $this->materialsError = 'לא צורפו קבצים או קישורים — יש לצרף לפחות קובץ אחד או קישור אחד לפני השליחה.';

            return;
        }

        if (empty($this->materialsRecipients)) {
            $this->materialsError = 'אין עדיין נמענים — יש להוסיף לפחות נמען אחד לפני השליחה.';

            return;
        }

        $this->validate([
            'materialsFiles.*' => self::MATERIALS_FILE_RULES,
            'materialsLinks.*.url' => ['required', 'url:http,https', 'max:2048'],
            'materialsLinks.*.name' => ['nullable', 'string', 'max:255'],
        ], [], ['materialsLinks.*.url' => 'קישור', 'materialsLinks.*.name' => 'שם הקישור']);

        // A link without a name is shown by its address.
        $links = array_map(fn (array $link) => [
            'url' => trim($link['url']),
            'name' => trim((string) ($link['name'] ?? '')) ?: (parse_url(trim($link['url']), PHP_URL_HOST) ?: trim($link['url'])),
        ], $this->materialsLinks);

        $program = $this->materialsPrograms->firstWhere('id', (int) $this->materialsProgramId);

        if (! $program) {
            $this->materialsError = 'ניתן לשלוח חומרים רק עבור תוכנית שהלקוחה רכשה.';

            return;
        }

        $attachments = array_map(
            fn ($file) => [
                'file_reference' => $file->storeAs('material-attachments', $file->getFilename(), 'local'),
                'file_name' => $file->getClientOriginalName(),
            ],
            $this->materialsFiles,
        );

        try {
            $delivery = MaterialDelivery::sendFor($this->customer, $program, $this->materialsRecipients, $attachments, $activityLogger, $runner, $smove, $links);
        } catch (\RuntimeException $e) {
            // Nothing was recorded, so the stored copies would be orphans.
            \Illuminate\Support\Facades\Storage::disk('local')->delete(array_column($attachments, 'file_reference'));
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
        $this->materialsLinks = [];
        $this->materialsProgramId = '';
        unset($this->materialDeliveries);

        $this->notifySuccess("חומרי הלימוד עבור \"{$program?->name}\" נשלחו בהצלחה.");
    }

    /** FR-5.17's manual half — dismisses a stale "needs attention" item with no dashboard yet to do it from. */
    public function markMaterialHandled(int $materialDeliveryId): void
    {
        abort_unless(auth()->user()->can('materials.manage'), 403);

        MaterialDelivery::where('customer_id', $this->customer->id)->findOrFail($materialDeliveryId)->markHandled();
        unset($this->materialDeliveries);
    }

    /**
     * FR-2.15: the customer card's debt indicator — sum of agreed_amount
     * minus paid across every non-cancelled deal.
     */
    #[Computed]
    public function outstandingBalance(): float
    {
        return $this->customer->outstandingBalance();
    }

    /** The real debt — what checks already received will cover isn't one (Deal::uncoveredBalance()). */
    #[Computed]
    public function uncoveredBalance(): float
    {
        return $this->customer->uncoveredBalance();
    }

    #[Computed]
    public function pendingChecksTotal(): float
    {
        return $this->customer->pendingChecksTotal();
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
            'contactPhone' => ['nullable', 'string', 'max:50', new \App\Rules\Phone],
            'contactPhoneSecondary' => ['nullable', 'string', 'max:50', new \App\Rules\Phone],
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
                'title' => 'Follow Up'.($followUp->next_at ? ' · הבא: '.$followUp->next_at->format('d/m/Y') : ''),
                'body' => trim(($followUp->summary ?? '').($followUp->result ? ' — '.$followUp->result : '')),
            ]);
        }

        foreach ($this->activityLogs as $log) {
            $items->push([
                'date' => $log->occurred_at,
                'title' => \App\Models\ActivityLog::typeLabel($log->activity_type),
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
            @if ($this->uncoveredBalance > 0)
                <span class="badge badge-error" style="margin-bottom:8px; margin-inline-start:6px; display:inline-flex">חוב פתוח: ₪{{ number_format($this->uncoveredBalance, 0) }}</span>
            @endif
            <h1>{{ $customer->school?->name ?? '—' }}</h1>
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
                            <div>
                                <label for="schoolInvoiceName">שם לחשבונית</label>
                                <input type="text" id="schoolInvoiceName" wire:model="schoolInvoiceName">
                                @error('schoolInvoiceName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="schoolBusinessNumber">ח.פ.</label>
                                <input type="text" id="schoolBusinessNumber" wire:model="schoolBusinessNumber" class="ltr-num" dir="ltr" inputmode="numeric">
                                @error('schoolBusinessNumber') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            <div class="full">
                                <label for="schoolNotes">הערות</label>
                                <textarea id="schoolNotes" wire:model="schoolNotes" rows="3"></textarea>
                            </div>
                            <div class="full"><button type="submit" class="btn btn-primary">שמירת פרטי בית ספר</button></div>
                        </form>
                    @else
                        <div class="form-grid">
                            <div class="full">
                                <label>שם המוסד</label>
                                <div class="field-box">{{ $customer->school?->name ?? '—' }}</div>
                            </div>
                            <div>
                                <label>עיר</label>
                                <div class="field-box">{{ $customer->school?->city ?? '—' }}</div>
                            </div>
                            <div>
                                <label>טלפון</label>
                                <div class="field-box ltr-num">{{ $customer->school?->phone ?? '—' }}</div>
                            </div>
                            <div>
                                <label>דוא"ל</label>
                                <div class="field-box ltr-num">{{ $customer->school?->email ?? '—' }}</div>
                            </div>
                            <div>
                                <label>כתובת</label>
                                <div class="field-box">{{ $customer->school?->address ?? '—' }}</div>
                            </div>
                            <div>
                                <label>שם לחשבונית</label>
                                <div class="field-box">{{ $customer->school?->invoice_name ?? '—' }}</div>
                            </div>
                            <div>
                                <label>ח.פ.</label>
                                <div class="field-box ltr-num">{{ $customer->school?->business_number ?? '—' }}</div>
                            </div>
                            <div class="full">
                                <label>הערות</label>
                                <div class="field-box" style="white-space:pre-wrap">{{ $customer->school?->notes ?? '—' }}</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div>
                {{-- ===== אנשי קשר ===== --}}
                <div class="card">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:var(--sp-sm)">
                        <h3 style="margin:0">אנשי קשר</h3>
                        @if (! $editingContactId)
                            <x-modal trigger-label="+ איש קשר חדש" trigger-class="btn btn-secondary btn-sm" title="איש קשר חדש">
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
                                    <div class="full"><button type="submit" class="btn btn-primary">+ הוספת איש קשר</button></div>
                                </form>
                            </x-modal>
                        @endif
                    </div>

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
                                        <div class="who">{{ $contact->name !== '' ? $contact->name : '(יש להשלים שם)' }}</div>
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
                </div>

                {{-- ===== פרטים נוספים ===== --}}
                <div class="card" style="margin-top:var(--sp-lg)">
                    <div class="contact-edit-head">
                        <h3 style="margin:0">פרטים נוספים</h3>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="$toggle('editingSchoolDetails')">{{ $editingSchoolDetails ? 'ביטול' : 'עריכה' }}</button>
                    </div>

                    @if ($editingSchoolDetails)
                        <form wire:submit="saveSchoolDetails" class="form-grid">
                            <div>
                                <label for="schoolSyllable">הברה</label>
                                <input type="text" id="schoolSyllable" wire:model="schoolSyllable">
                            </div>
                            <div>
                                <label for="schoolClassesPerGrade">מספר כיתות בשנתון</label>
                                <input type="text" id="schoolClassesPerGrade" wire:model="schoolClassesPerGrade" class="ltr-num" dir="ltr">
                                @error('schoolClassesPerGrade') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="schoolOwnershipName">שם הבעלות</label>
                                <input type="text" id="schoolOwnershipName" wire:model="schoolOwnershipName">
                                @error('schoolOwnershipName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label>תקציב גפן</label>
                                <span class="checkbox-row"><input type="checkbox" id="schoolGefenBudget" wire:model="schoolGefenBudget"><label for="schoolGefenBudget" style="margin:0">יש תקציב גפן</label></span>
                            </div>
                            <div>
                                <label>וידאו או שקופיות</label>
                                <div style="display:flex; gap:var(--sp-md); flex-wrap:wrap">
                                    @foreach (\App\Models\School::CONTENT_FORMATS as $value => $label)
                                        <span class="checkbox-row"><input type="radio" id="schoolContentFormat-{{ $value }}" name="schoolContentFormat" value="{{ $value }}" wire:model="schoolContentFormat"><label for="schoolContentFormat-{{ $value }}" style="margin:0">{{ $label }}</label></span>
                                    @endforeach
                                    <span class="checkbox-row"><input type="radio" id="schoolContentFormat-none" name="schoolContentFormat" value="" wire:model="schoolContentFormat"><label for="schoolContentFormat-none" style="margin:0">לא נקבע</label></span>
                                </div>
                                @error('schoolContentFormat') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            <div class="full">
                                <label for="newLogo">לוגו</label>
                                <input type="file" id="newLogo" wire:model="newLogo" accept="image/*">
                                @error('newLogo') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                @if ($newLogo)
                                    <img src="{{ $newLogo->temporaryUrl() }}" alt="תצוגה מקדימה" style="height:56px; width:56px; object-fit:contain; border:1px solid var(--color-border); border-radius:var(--radius-control); background:var(--color-surface); margin-top:8px">
                                @elseif ($customer->school?->logo_path)
                                    <div style="display:flex; align-items:center; gap:var(--sp-md); margin-top:8px">
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($customer->school->logo_path) }}" alt="לוגו" style="height:56px; width:56px; object-fit:contain; border:1px solid var(--color-border); border-radius:var(--radius-control); background:var(--color-surface)">
                                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($customer->school->logo_path) }}" download class="btn btn-secondary btn-sm">הורדה</a>
                                    </div>
                                @endif
                            </div>
                            <div class="full"><button type="submit" class="btn btn-primary">שמירת פרטים נוספים</button></div>
                        </form>
                    @else
                        <div class="form-grid">
                            <div>
                                <label>הברה</label>
                                <div class="field-box">{{ $customer->school?->syllable ?? '—' }}</div>
                            </div>
                            <div>
                                <label>מספר כיתות בשנתון</label>
                                <div class="field-box ltr-num">{{ $customer->school?->classes_per_grade ?? '—' }}</div>
                            </div>
                            <div>
                                <label>שם הבעלות</label>
                                <div class="field-box">{{ $customer->school?->ownership_name ?? '—' }}</div>
                            </div>
                            <div>
                                <label>תקציב גפן</label>
                                <div class="field-box">{{ $customer->school?->gefen_budget ? '✓ כן' : '✗ לא' }}</div>
                            </div>
                            <div>
                                <label>וידאו או שקופיות</label>
                                <div class="field-box">{{ \App\Models\School::CONTENT_FORMATS[$customer->school?->content_format] ?? '—' }}</div>
                            </div>
                            <div class="full">
                                <label>לוגו</label>
                                @if ($customer->school?->logo_path)
                                    <div style="display:flex; align-items:center; gap:var(--sp-md)">
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($customer->school->logo_path) }}" alt="לוגו" style="height:56px; width:56px; object-fit:contain; border:1px solid var(--color-border); border-radius:var(--radius-control); background:var(--color-surface)">
                                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($customer->school->logo_path) }}" download class="btn btn-secondary btn-sm">הורדה</a>
                                    </div>
                                @else
                                    <div class="field-box">—</div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($activeTab === 'subscription')

        @forelse ($this->subscriptions as $subscription)
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <div class="contact-edit-head">
                    <h3 style="margin:0">{{ $subscription->deal->program_name_snapshot ?? $subscription->deal->bundle_name_snapshot }}</h3>
                    <span class="badge {{ \App\Models\Subscription::badgeClassForStatusName($subscription->status?->name) }}">{{ $subscription->status?->name }}</span>
                </div>

                <div style="display:flex; gap:var(--sp-xl); flex-wrap:wrap; margin-bottom:var(--sp-md); font-size:var(--fs-small)">
                    <div><span style="color:var(--color-text-secondary)">תאריך תחילה: </span><span class="ltr-num" style="font-weight:600">{{ $subscription->start_date->format('d/m/Y') }}</span></div>
                    <div><span style="color:var(--color-text-secondary)">מחיר שסוכם: </span><span class="ltr-num" style="font-weight:600">₪{{ number_format((float) $subscription->agreed_price, 0) }}</span></div>
                    <div><span style="color:var(--color-text-secondary)">התקדמות: </span><span style="font-weight:600">{{ $subscription->suppliedCount() }} מתוך {{ \App\Models\Subscription::TOTAL_DELIVERIES }} תוכניות סופקו</span></div>
                    @if ($subscription->cancelled_from_month)
                        <div><span style="color:var(--color-text-secondary)">מבוטל החל מ: </span><span style="font-weight:600">חודש {{ $subscription->cancelled_from_month }} ({{ $subscription->monthLabel($subscription->cancelled_from_month) }})</span></div>
                    @endif
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
                    <thead><tr><th>#</th><th>חודש</th><th>תוכנית</th><th>תאריך אספקה</th><th>סומן ע"י</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($subscription->deliveries as $delivery)
                            @php
                                $deliveryCancelled = $subscription->isDeliveryCancelled($delivery);
                                // Months before a cancellation month are still owed and can be marked.
                                $deliverySuppliable = ! $delivery->is_supplied && ($subscription->isActive() || ($subscription->cancelled_from_month && ! $deliveryCancelled && $subscription->status?->name === \App\Models\Subscription::CANCELLED_STATUS_NAME));
                            @endphp
                            <tr @if ($deliveryCancelled) style="color:var(--color-text-secondary)" @endif>
                                <td>{{ $delivery->sequence_number }}</td>
                                <td>{{ $subscription->monthLabel($delivery->sequence_number) }}</td>
                                <td>
                                    @if ($delivery->is_supplied)
                                        {{ $delivery->program?->name }}
                                    @elseif ($deliverySuppliable)
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
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="unmarkDeliverySupplied({{ $subscription->id }}, {{ $delivery->id }})" wire:confirm="לבטל את סימון האספקה של שורה זו?">ביטול סימון</button>
                                    @elseif ($deliveryCancelled)
                                        <span class="badge badge-error">בוטלה</span>
                                    @elseif ($deliverySuppliable)
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

                {{-- ===== ביטול מנוי וחישוב קיזוז (US-010) ===== --}}
                @if ($subscription->isActive())
                    @php $firstCancellableMonth = $subscription->firstCancellableMonth(); @endphp
                    @if ($firstCancellableMonth <= \App\Models\Subscription::TOTAL_DELIVERIES)
                        <div style="margin-top:var(--sp-lg); display:flex; justify-content:flex-end">
                            <x-modal trigger-label="ביטול מנוי" trigger-class="btn btn-destructive" title="ביטול מנוי">
                                <form wire:submit="cancelSubscription({{ $subscription->id }})" class="form-grid">
                                    <div class="full">
                                        <label for="cancelFromMonth{{ $subscription->id }}">ביטול החל מחודש</label>
                                        <select id="cancelFromMonth{{ $subscription->id }}" wire:model.live="cancelFromMonths.{{ $subscription->id }}">
                                            <option value="">בחרו חודש</option>
                                            @for ($month = $firstCancellableMonth; $month <= \App\Models\Subscription::TOTAL_DELIVERIES; $month++)
                                                <option value="{{ $month }}">חודש {{ $month }} — {{ $subscription->monthLabel($month) }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                    @php $chosenCancelMonth = (int) ($cancelFromMonths[$subscription->id] ?? 0); @endphp
                                    <div class="full">
                                        <label for="cancelCredit{{ $subscription->id }}">סכום הזיכוי (₪)</label>
                                        <input type="text" id="cancelCredit{{ $subscription->id }}" class="ltr-num" dir="ltr" wire:model="cancelCredits.{{ $subscription->id }}" @disabled(! $chosenCancelMonth)>
                                        @if ($chosenCancelMonth)
                                            <div style="font-size:var(--fs-caption); color:var(--color-text-secondary); margin-top:4px">מחושב: ₪{{ number_format($subscription->creditFor($chosenCancelMonth), 0) }} ({{ \App\Models\Subscription::TOTAL_DELIVERIES - $chosenCancelMonth + 1 }} חודשים). ניתן לשנות.</div>
                                        @endif
                                    </div>
                                    <div class="full">
                                        <label for="cancelReason{{ $subscription->id }}">סיבת הביטול</label>
                                        <textarea id="cancelReason{{ $subscription->id }}" rows="3" wire:model="cancelReasons.{{ $subscription->id }}"></textarea>
                                    </div>
                                    @if ($subscriptionError)
                                        <div class="full" style="color:var(--color-error); font-size:var(--fs-small)">{{ $subscriptionError }}</div>
                                    @endif
                                    <div class="full" style="font-size:var(--fs-small); color:var(--color-text-secondary)">גם העסקה תסומן כמבוטלת. פעולה זו אינה הפיכה.</div>
                                    <div class="full"><button type="submit" class="btn btn-destructive" @disabled(! $chosenCancelMonth)>אישור ביטול המנוי</button></div>
                                </form>
                            </x-modal>
                        </div>
                    @endif
                @elseif ($subscription->status?->name === \App\Models\Subscription::CANCELLED_STATUS_NAME)
                    <div class="card confirm-row" style="margin-top:var(--sp-lg); background:var(--color-background)">
                        <div style="display:flex; gap:var(--sp-xl); flex-wrap:wrap; font-size:var(--fs-small)">
                            <div><span style="color:var(--color-text-secondary)">תאריך ביטול: </span><span class="ltr-num" style="font-weight:600">{{ $subscription->cancelled_at?->format('d/m/Y') }}</span></div>
                            <div><span style="color:var(--color-text-secondary)">סכום הזיכוי: </span><span class="ltr-num" style="font-weight:600">₪{{ number_format((float) $subscription->cancellation_credit, 0) }}</span></div>
                        </div>
                        @if ($subscription->cancellation_reason)
                            <div style="font-size:var(--fs-small); margin-top:var(--sp-xs)"><span style="color:var(--color-text-secondary)">סיבת הביטול: </span><span style="white-space:pre-wrap">{{ $subscription->cancellation_reason }}</span></div>
                        @endif
                        <div style="margin-top:var(--sp-md)">
                        @php $creditNote = $subscription->deal->documents()->where('document_type', 'credit_note')->first(); @endphp
                        @if ($creditNote)
                            <a href="{{ route('document-view', $creditNote) }}" class="btn btn-secondary">{{ $creditNote->sent_at ? 'צפייה בחשבונית הזיכוי' : 'חשבונית הזיכוי (טרם הופקה מול SUMIT)' }}</a>
                        @else
                            <button
                                type="button"
                                class="btn btn-secondary"
                                wire:click="generateSubscriptionCreditNote({{ $subscription->id }})"
                                @if (\App\Models\Document::canGenerate($subscription->deal, 'credit_note'))
                                    wire:confirm="הזיכוי יופק ב-SUMIT ויישלח ללקוחה במייל. זו פעולה חשבונאית בלתי הפיכה — להמשיך?"
                                @endif
                            >הפקת חשבונית זיכוי</button>
                        @endif
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="card empty-state">אין ללקוחה זו מנוי.</div>
        @endforelse
    @elseif ($activeTab === 'deals')
        <div class="card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:var(--sp-sm)">
                <h3 style="margin:0">עסקאות הלקוחה</h3>
                <x-modal trigger-label="+ עסקה חדשה" title="עסקה חדשה">
                    <form wire:submit="createDeal" class="form-grid">
                        <div class="full">
                            <label for="dealItem">תוכנית / מארז</label>
                            <select id="dealItem" wire:model.live="dealItem">
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
                            <label for="dealAgreedAmount">סכום מוסכם (ניתן לשינוי)</label>
                            <input type="text" id="dealAgreedAmount" wire:model="dealAgreedAmount" class="ltr-num" dir="ltr" placeholder="₪">
                            @if ($dealDiscountNote) <div style="color: var(--color-text-secondary); font-size: var(--fs-caption); margin-top: 4px;">{{ $dealDiscountNote }}</div> @endif
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
                        <div>
                            <label for="dealStudentsCount">מספר תלמידות</label>
                            <input type="text" id="dealStudentsCount" wire:model="dealStudentsCount" class="ltr-num" dir="ltr" inputmode="numeric">
                            @error('dealStudentsCount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div class="full">
                            <label for="dealSpecialRequest">בקשת התאמה מיוחדת</label>
                            <textarea id="dealSpecialRequest" wire:model="dealSpecialRequest" rows="2"></textarea>
                        </div>
                        <div class="full"><button type="submit" class="btn btn-primary">יצירת עסקה</button></div>
                    </form>
                </x-modal>
            </div>
            @forelse ($this->deals as $deal)
                <div class="deal-row">
                    <div>
                        <div style="font-weight:600">{{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</div>
                        <div style="font-size:var(--fs-caption); color:var(--color-text-secondary)">נפתחה <span class="ltr-num">{{ $deal->purchased_at->format('d/m/Y') }}</span>@if ($deal->students_count !== null) · {{ $deal->students_count }} תלמידות @endif</div>
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
    @elseif ($activeTab === 'billing')
        <div class="card">
            <h3>מצב תשלומים ויתרת חוב</h3>

            @if ($this->uncoveredBalance > 0)
                <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:600;">
                    ללקוחה זו יתרת חוב פתוחה בסך <span class="ltr-num">₪{{ number_format($this->uncoveredBalance, 0) }}</span>.
                </div>
            @elseif ($this->pendingChecksTotal > 0)
                <div class="mb-8" style="background: var(--color-background); color: var(--color-text-secondary); border:1px solid var(--color-border); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:600;">
                    אין חוב פתוח — היתרה מכוסה בצ'קים שהתקבלו וטרם נפרעו (<span class="ltr-num">₪{{ number_format($this->pendingChecksTotal, 0) }}</span>).
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
                            <td>{{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</td>
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
        </div>
    @elseif ($activeTab === 'materials')
        <div class="cols2">
            <div class="card">
                <h3>שליחת חומרים</h3>

                <form wire:submit="sendMaterials" class="form-grid">
                    <div class="full">
                        <label for="materialsProgramId">תוכנית</label>
                        <select id="materialsProgramId" wire:model="materialsProgramId">
                            <option value="">{{ $this->materialsPrograms->isEmpty() ? 'הלקוחה עדיין לא רכשה תוכניות' : 'בחרו תוכנית' }}</option>
                            @foreach ($this->materialsPrograms as $program)
                                <option value="{{ $program->id }}">{{ $program->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Livewire uploads the files as soon as they're chosen: show it, and say so if it fails
                         (a failed upload used to surface only on send, as "no file attached"). --}}
                    <div class="full" x-data="{ uploading: false, progress: 0, failed: false }"
                        x-on:livewire-upload-start="uploading = true; failed = false; progress = 0"
                        x-on:livewire-upload-progress="progress = $event.detail.progress"
                        x-on:livewire-upload-finish="uploading = false"
                        x-on:livewire-upload-error="uploading = false; failed = true"
                        x-on:livewire-upload-cancel="uploading = false">
                        <label for="materialsFiles">קובץ מצורף</label>
                        <input type="file" id="materialsFiles" wire:model="materialsFiles" multiple>
                        <div class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">PDF, Word, PowerPoint, תמונה, וידאו או שמע — עד 95MB לקובץ. סרטון גדול יותר — כקישור (למטה).</div>
                        <div x-show="uploading" x-cloak class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">מעלה את הקבצים… <span class="ltr-num" x-text="progress + '%'"></span></div>
                        <div x-show="failed" x-cloak style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">העלאת הקובץ נכשלה — ייתכן שהוא גדול מ-95MB (או שכמה קבצים יחד עוברים 100MB), או שהחיבור נקטע. נסו לבחור אותו שוב, או שלחו סרטון גדול כקישור.</div>
                        @error('materialsFiles.*') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        @if (! empty($materialsFiles))
                            <div class="chip-row" style="display:flex; flex-wrap:wrap; gap:var(--sp-sm); margin-top:var(--sp-sm)">
                                @foreach ($materialsFiles as $file)
                                    <span class="badge badge-neutral">{{ $file->getClientOriginalName() }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- A video too big to upload goes as a link (YouTube unlisted, Vimeo, Google Drive…). --}}
                    <div class="full">
                        <label>קישורים</label>
                        @foreach ($materialsLinks as $i => $link)
                            <div class="material-link-row" wire:key="material-link-{{ $i }}">
                                <div>
                                    <input type="text" wire:model="materialsLinks.{{ $i }}.name" placeholder="שם (למשל: סרטון הדרכה)" aria-label="שם הקישור">
                                    @error("materialsLinks.$i.name") <div class="field-error">{{ $message }}</div> @enderror
                                </div>
                                <div>
                                    <input type="url" wire:model="materialsLinks.{{ $i }}.url" class="ltr-num" dir="ltr" placeholder="https://" aria-label="כתובת הקישור">
                                    @error("materialsLinks.$i.url") <div class="field-error">{{ $message }}</div> @enderror
                                </div>
                                <button type="button" wire:click="removeMaterialsLink({{ $i }})" class="btn btn-ghost btn-sm" aria-label="הסרת הקישור">×</button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addMaterialsLink" class="btn btn-ghost btn-sm">+ הוספת קישור</button>
                    </div>

                    <div class="full">
                        <label>נמענים</label>
                        <div class="chip-row" style="display:flex; flex-wrap:wrap; gap:var(--sp-sm); margin-bottom:var(--sp-sm)">
                            @foreach ($materialsRecipients as $index => $recipient)
                                <span class="badge badge-primary" style="display:inline-flex; align-items:center; gap:6px">
                                    {{ $recipient['name'] }}
                                    <button type="button" wire:click="removeMaterialsRecipient({{ $index }})" title="הסרה" aria-label="הסרת נמען" style="background:none; border:none; cursor:pointer; color:inherit; font-weight:700;">×</button>
                                </span>
                            @endforeach
                        </div>
                        <div class="field-row" style="display:flex; gap:var(--sp-sm)">
                            <input type="text" wire:model="materialsRecipientName" placeholder="שם הנמען (אופציונלי)">
                            <input type="email" wire:model="materialsRecipientEmail" placeholder="הוספת נמען לפי כתובת דוא&quot;ל" class="ltr-num" dir="ltr">
                            <button type="button" class="btn btn-secondary" wire:click="addMaterialsRecipient">הוספה</button>
                        </div>
                    </div>

                    <div class="full"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="materialsFiles, sendMaterials">שליחה באמצעות Smove</button></div>
                </form>
            </div>

            <div class="card">
                <h3>היסטוריית משלוחים</h3>
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
