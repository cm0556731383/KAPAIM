<?php

use App\Concerns\Notifies;
use App\Console\Commands\ProcessMaterialReminders;
use App\Models\AppSetting;
use App\Models\BusinessEntity;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateField;
use App\Models\ExternalIntegrationSetting;
use App\Models\ExternalOperation;
use App\Models\LeadSource;
use App\Models\PaymentMethod;
use App\Models\StatusDefinition;
use App\Services\ActivityLogger;
use App\Services\HebrewCalendar;
use App\Services\Integrations\SummitClient;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts.app', ['title' => 'הגדרות מערכת — כפיים'])]
class extends Component
{
    use Notifies;

    // ===== סטטוסים (STATUS_DEFINITION) =====
    public string $statusScope = 'lead';
    public string $statusName = '';

    /**
     * Plain scope names seeded per build-plan 02. NOTE: the PRD also defines a
     * separate lead "traffic light" (ירוק/צהוב/אדום, FR-1.5) that each lead status
     * maps to, and a lead sub-status "ממתינה לשיחה חוזרת" nested under צהוב
     * (FR-1.6). STATUS_DEFINITION per the ERD has no color/parent-substatus
     * columns, so that mapping is intentionally NOT modeled here — it needs to be
     * addressed when the Leads module (build-plan stage 4) is actually built.
     */
    public array $statusScopes = [
        'lead' => 'ליד',
        'customer' => 'לקוחה',
        'deal' => 'עסקה',
        'document' => 'מסמך',
        'payment' => 'תשלום',
        'subscription' => 'מנוי',
        'expense' => 'הוצאה',
        'material_delivery' => 'אספקת חומרים',
    ];

    // ===== מקורות ליד (LEAD_SOURCE) =====
    public string $leadSourceName = '';

    // ===== אמצעי תשלום (PAYMENT_METHOD) =====
    public string $paymentMethodName = '';
    public string $paymentMethodType = '';

    // ===== עוסקים (BUSINESS_ENTITY) =====
    public string $businessEntityName = '';
    public string $businessEntityClassification = 'עוסק פטור';
    public string $businessEntityCompanyNumber = '';
    public string $businessEntityEmail = '';
    public string $businessEntityPhone = '';

    public array $businessEntityClassifications = ['עוסק פטור', 'עוסק מורשה', 'חברה בעמ'];

    // ===== תבניות דואר (EMAIL_TEMPLATE) =====
    public string $emailTemplateName = '';
    public string $emailTemplateType = '';
    public string $emailTemplateSubject = '';
    public string $emailTemplateContent = '';

    public ?int $fieldTemplateId = null;
    public string $fieldName = '';
    public string $fieldType = 'free_text';
    public string $fieldLinkedField = '';
    public bool $fieldIsRequired = false;

    // ===== אינטגרציות חיצוניות (EXTERNAL_INTEGRATION_SETTING, build-plan 12) =====
    public string $smoveApiKey = '';
    public string $smoveWebhookSecret = '';
    public string $smoveMaterialReminderHours = '48';
    public string $smoveListId = '';

    public bool $summitTestMode = true;

    /**
     * SUMIT credentials per business entity (each עוסק is its own SUMIT
     * company): business_entity_id => ['name', 'connection_name', 'company_id',
     * 'api_key'].
     * api_key is never loaded back into the page — left empty it keeps the
     * stored key; typed in, it replaces it.
     */
    public array $sumitCompanies = [];

    public string $landingPageWebhookSecret = '';

    /** Reference-data row being edited inline: "status:{id}", "lead_source:{id}", … */
    public ?string $editingRecord = null;

    /** The edited row's fields, keyed like its columns. */
    public array $editForm = [];

    /** Which settings tab is shown — kept in the URL (?tab=) so a reload stays on it. */
    #[Url(as: 'tab')]
    public string $activeTab = 'statuses';

    // ===== מנויים =====
    public string $subscriptionStartMonth = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $this->subscriptionStartMonth = (string) HebrewCalendar::startMonth();

        $smove = ExternalIntegrationSetting::where('system', 'smove')->first()?->settings ?? [];
        $this->smoveApiKey = (string) ($smove['api_key'] ?? '');
        $this->smoveWebhookSecret = (string) ($smove['webhook_secret'] ?? '');
        $this->smoveMaterialReminderHours = (string) ($smove['material_reminder_hours'] ?? ProcessMaterialReminders::DEFAULT_REMINDER_HOURS);
        $this->smoveListId = (string) ($smove['list_id'] ?? '');

        $summit = ExternalIntegrationSetting::where('system', 'summit')->first()?->settings ?? [];
        // Test mode defaults ON until the business owner explicitly turns it off.
        $this->summitTestMode = (bool) ($summit['test_mode'] ?? true);
        $this->loadSumitCompanies();

        $landingPage = ExternalIntegrationSetting::where('system', 'landing_page')->first()?->settings ?? [];
        $this->landingPageWebhookSecret = (string) ($landingPage['webhook_secret'] ?? '');
    }

    /**
     * Build-plan 12: these three save methods are where the business owner
     * actually fills in Smove/Summit/landing-page's real api_key/
     * webhook_secret — the code paths that use them (App\Services\Integrations\*,
     * the three webhook routes) are already fully real; only these values were
     * ever left open, per this stage's own scope decision. Smove's base_url
     * is no longer one of them — it's a fixed vendor address, hardcoded in
     * SmoveClient — the business owner never had a "server address" to know.
     */
    /** HebrewCalendar: the Hebrew month that is month 1 of a newly opened subscription. */
    public function saveSubscriptionSettings(ActivityLogger $activityLogger): void
    {
        $this->validate(['subscriptionStartMonth' => ['required', 'in:'.implode(',', array_keys(HebrewCalendar::MONTHS))]]);

        AppSetting::put(HebrewCalendar::START_MONTH_SETTING, $this->subscriptionStartMonth);

        $activityLogger->log('app_setting.updated', 'חודש תחילת מנוי עודכן ל'.HebrewCalendar::MONTHS[(int) $this->subscriptionStartMonth]);
        $this->notifySuccess('הגדרות המנוי נשמרו.');
    }

    public function saveSmoveSettings(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'smoveApiKey' => ['nullable', 'string', 'max:255'],
            'smoveWebhookSecret' => ['nullable', 'string', 'max:255'],
            'smoveMaterialReminderHours' => ['required', 'integer', 'min:1'],
            'smoveListId' => ['nullable', 'integer', 'min:1'],
        ], [], ['smoveListId' => 'מזהה רשימה ב-Smove']);

        ExternalIntegrationSetting::firstOrCreate(['system' => 'smove'], ['is_active' => false, 'settings' => []])->update(['settings' => [
            'api_key' => $data['smoveApiKey'] ?: null,
            'webhook_secret' => $data['smoveWebhookSecret'] ?: null,
            'material_reminder_hours' => (int) $data['smoveMaterialReminderHours'],
            'list_id' => $data['smoveListId'] ? (int) $data['smoveListId'] : null,
        ]]);

        $activityLogger->log('external_integration_setting.updated', 'עודכנו הגדרות חיבור Smove');
        unset($this->integrations);
    }

    public function saveSummitSettings(ActivityLogger $activityLogger): void
    {
        $data = $this->validate(['summitTestMode' => ['boolean']]);

        $setting = ExternalIntegrationSetting::firstOrCreate(['system' => 'summit'], ['is_active' => false, 'settings' => []]);
        $setting->update(['settings' => ['test_mode' => (bool) $data['summitTestMode']]]);

        $activityLogger->log('external_integration_setting.updated', 'עודכן מצב הבדיקה של SUMIT: '.($data['summitTestMode'] ? 'פעיל' : 'כבוי'));
        unset($this->integrations);
        $this->notifySuccess('הגדרות SUMIT נשמרו.');
    }

    private function loadSumitCompanies(): void
    {
        $this->sumitCompanies = BusinessEntity::orderBy('name')->get()
            ->mapWithKeys(fn (BusinessEntity $entity) => [$entity->id => [
                'name' => $entity->name,
                'connection_name' => (string) $entity->sumit_connection_name,
                'company_id' => (string) $entity->sumit_company_id,
                'api_key' => '',
                'has_key' => filled($entity->sumit_api_key),
            ]])
            ->all();
    }

    public function saveSumitCompany(int $entityId, ActivityLogger $activityLogger): void
    {
        $this->validate([
            "sumitCompanies.{$entityId}.name" => ['required', 'string', 'max:255'],
            "sumitCompanies.{$entityId}.connection_name" => ['required', 'string', 'max:255'],
            "sumitCompanies.{$entityId}.company_id" => ['nullable', 'digits_between:1,18'],
            "sumitCompanies.{$entityId}.api_key" => ['nullable', 'string', 'max:255'],
        ], [], [
            "sumitCompanies.{$entityId}.name" => 'שם העוסק',
            "sumitCompanies.{$entityId}.connection_name" => 'שם החיבור',
            "sumitCompanies.{$entityId}.company_id" => 'מספר חברה',
        ]);

        $entity = BusinessEntity::findOrFail($entityId);
        $row = $this->sumitCompanies[$entityId];

        $entity->update(array_merge(
            ['name' => trim($row['name']), 'sumit_connection_name' => trim($row['connection_name']), 'sumit_company_id' => $row['company_id'] ?: null],
            filled($row['api_key']) ? ['sumit_api_key' => trim($row['api_key'])] : [],
        ));

        $activityLogger->log('business_entity.sumit_updated', "עודכן חיבור SUMIT \"{$entity->sumitConnectionLabel()}\" של העוסק \"{$entity->name}\"");
        $this->loadSumitCompanies();
        unset($this->businessEntities);
        $this->notifySuccess("חיבור SUMIT \"{$entity->sumitConnectionLabel()}\" נשמר.");
    }

    /** Read-only credentials check (SummitClient::verifyCredentials()) — saves the row first so it tests what's on screen. */
    public function testSumitCompany(int $entityId, ActivityLogger $activityLogger, SummitClient $summit): void
    {
        $this->saveSumitCompany($entityId, $activityLogger);

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $summit->verifyCredentials($entity = BusinessEntity::findOrFail($entityId));
        } catch (\Throwable $e) {
            $this->addError("sumitConnection.{$entityId}", 'החיבור נכשל: '.$e->getMessage());

            return;
        }

        $this->notifySuccess("החיבור \"{$entity->sumitConnectionLabel()}\" ל-SUMIT תקין — מספר החברה ומפתח ה-API אושרו.");
    }

    public function saveLandingPageSettings(ActivityLogger $activityLogger): void
    {
        $data = $this->validate(['landingPageWebhookSecret' => ['nullable', 'string', 'max:255']]);

        ExternalIntegrationSetting::firstOrCreate(['system' => 'landing_page'], ['is_active' => false, 'settings' => []])->update(['settings' => [
            'webhook_secret' => $data['landingPageWebhookSecret'] ?: null,
        ]]);

        $activityLogger->log('external_integration_setting.updated', 'עודכן סוד ה-Webhook של דף הנחיתה');
        unset($this->integrations);
    }

    // ----- עריכת נתוני ייחוס (FR-7.11: never deleted — only renamed/edited or deactivated) -----

    private const EDITABLE_RECORDS = [
        'status' => [StatusDefinition::class, ['name', 'sort_order']],
        'lead_source' => [LeadSource::class, ['name']],
        'payment_method' => [PaymentMethod::class, ['name', 'type']],
        'business_entity' => [BusinessEntity::class, ['name', 'classification', 'company_number', 'email', 'phone']],
        'email_template' => [EmailTemplate::class, ['name', 'template_type', 'subject', 'content']],
    ];

    public function editRecord(string $type, int $id): void
    {
        [$modelClass, $fields] = self::EDITABLE_RECORDS[$type] ?? abort(404);
        $record = $modelClass::findOrFail($id);

        $this->resetErrorBag();
        $this->editingRecord = "{$type}:{$id}";
        $this->editForm = collect($fields)->mapWithKeys(fn ($f) => [$f => (string) $record->{$f}])->all();
    }

    public function cancelRecordEdit(): void
    {
        $this->reset(['editingRecord', 'editForm']);
    }

    public function saveRecord(ActivityLogger $activityLogger): void
    {
        [$type, $id] = explode(':', (string) $this->editingRecord) + [null, null];
        [$modelClass] = self::EDITABLE_RECORDS[$type] ?? abort(404);
        $record = $modelClass::findOrFail((int) $id);

        $rules = match ($type) {
            'status' => [
                'editForm.name' => ['required', 'string', 'max:255'],
                'editForm.sort_order' => ['required', 'integer', 'min:0'],
            ],
            'lead_source' => ['editForm.name' => ['required', 'string', 'max:255', "unique:lead_sources,name,{$record->id}"]],
            'payment_method' => [
                'editForm.name' => ['required', 'string', 'max:255', "unique:payment_methods,name,{$record->id}"],
                // An older free-text type stays valid as-is; the screen only offers TYPE_LABELS' keys.
                'editForm.type' => ['required', 'string', 'max:255', 'in:'.implode(',', array_unique([...array_keys(PaymentMethod::TYPE_LABELS), $record->type]))],
            ],
            'business_entity' => [
                'editForm.name' => ['required', 'string', 'max:255'],
                'editForm.classification' => ['required', 'in:'.implode(',', $this->businessEntityClassifications)],
                'editForm.company_number' => ['required', 'string', 'max:255'],
                'editForm.email' => ['required', 'email'],
                'editForm.phone' => ['required', 'string', 'max:255'],
            ],
            'email_template' => [
                'editForm.name' => ['required', 'string', 'max:255'],
                'editForm.template_type' => ['required', 'string', 'max:255', 'in:'.implode(',', array_unique([...array_keys(EmailTemplate::TYPE_LABELS), $record->template_type]))],
                'editForm.subject' => ['required', 'string'],
                'editForm.content' => ['required', 'string'],
            ],
        };

        $data = $this->validate($rules, [], [
            'editForm.name' => 'שם', 'editForm.sort_order' => 'סדר', 'editForm.type' => 'סוג',
            'editForm.classification' => 'סיווג', 'editForm.company_number' => 'ח.פ / ע.מ', 'editForm.email' => 'אימייל',
            'editForm.phone' => 'טלפון', 'editForm.template_type' => 'סוג', 'editForm.subject' => 'נושא', 'editForm.content' => 'תוכן',
        ])['editForm'];

        // A status the code looks up by name keeps its name (StatusDefinition::SYSTEM_NAMES).
        if ($type === 'status' && $record->isSystem()) {
            $data['name'] = $record->name;
        }

        // An entity that already issued documents keeps its classification —
        // SUMIT document types (invoice vs. חשבון עסקה, credit invoice vs.
        // קבלה זיכוי) depend on it.
        if ($type === 'business_entity' && $record->classification !== $data['classification']
            && \App\Models\Document::where('business_entity_id', $record->id)->exists()) {
            $this->addError('editForm.classification', 'לא ניתן לשנות סיווג של עוסק שכבר הופקו עבורו מסמכים.');

            return;
        }

        $record->update($data);

        $activityLogger->log("{$type}.updated", 'עודכן: '.($record->name ?? "#{$record->id}"), ['metadata' => ['id' => $record->id]]);

        $this->cancelRecordEdit();
        unset($this->statuses, $this->leadSources, $this->paymentMethods, $this->businessEntities, $this->emailTemplates);
        if ($type === 'business_entity') {
            $this->loadSumitCompanies();
        }
        $this->notifySuccess('השינויים נשמרו.');
    }

    // ----- סטטוסים -----

    public function addStatus(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'statusScope' => ['required', 'string'],
            'statusName' => ['required', 'string', 'max:255'],
        ], [], ['statusName' => 'שם סטטוס']);

        $nextSort = (int) StatusDefinition::where('scope', $data['statusScope'])->max('sort_order') + 1;

        $status = StatusDefinition::create([
            'scope' => $data['statusScope'],
            'name' => $data['statusName'],
            'is_active' => true,
            'sort_order' => $nextSort,
        ]);

        $activityLogger->log('status_definition.created', "נוצר סטטוס חדש: {$status->name} ({$this->statusScopes[$status->scope]})");

        $this->reset(['statusName']);
        unset($this->statuses);
    }

    public function toggleStatus(int $id, ActivityLogger $activityLogger): void
    {
        $this->toggleActive(StatusDefinition::class, $id, $activityLogger, 'status_definition');
        unset($this->statuses);
    }

    #[Computed]
    public function statuses()
    {
        return StatusDefinition::orderBy('scope')->orderBy('sort_order')->get()->groupBy('scope');
    }

    // ----- מקורות ליד -----

    public function addLeadSource(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'leadSourceName' => ['required', 'string', 'max:255', 'unique:lead_sources,name'],
        ], [], ['leadSourceName' => 'שם מקור']);

        $source = LeadSource::create(['name' => $data['leadSourceName'], 'is_active' => true]);

        $activityLogger->log('lead_source.created', "נוצר מקור ליד חדש: {$source->name}");

        $this->reset(['leadSourceName']);
        unset($this->leadSources);
    }

    public function toggleLeadSource(int $id, ActivityLogger $activityLogger): void
    {
        $this->toggleActive(LeadSource::class, $id, $activityLogger, 'lead_source');
        unset($this->leadSources);
    }

    #[Computed]
    public function leadSources()
    {
        return LeadSource::orderBy('name')->get();
    }

    // ----- אמצעי תשלום -----

    public function addPaymentMethod(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'paymentMethodName' => ['required', 'string', 'max:255', 'unique:payment_methods,name'],
            'paymentMethodType' => ['required', 'in:'.implode(',', array_keys(PaymentMethod::TYPE_LABELS))],
        ], [], ['paymentMethodName' => 'שם אמצעי תשלום', 'paymentMethodType' => 'סוג']);

        $method = PaymentMethod::create([
            'name' => $data['paymentMethodName'],
            'type' => $data['paymentMethodType'],
            'is_active' => true,
        ]);

        $activityLogger->log('payment_method.created', "נוצר אמצעי תשלום חדש: {$method->name}");

        $this->reset(['paymentMethodName', 'paymentMethodType']);
        unset($this->paymentMethods);
    }

    public function togglePaymentMethod(int $id, ActivityLogger $activityLogger): void
    {
        $this->toggleActive(PaymentMethod::class, $id, $activityLogger, 'payment_method');
        unset($this->paymentMethods);
    }

    #[Computed]
    public function paymentMethods()
    {
        return PaymentMethod::orderBy('name')->get();
    }

    // ----- עוסקים -----

    public function addBusinessEntity(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'businessEntityName' => ['required', 'string', 'max:255'],
            'businessEntityClassification' => ['required', 'in:עוסק פטור,עוסק מורשה,חברה בעמ'],
            'businessEntityCompanyNumber' => ['required', 'string', 'max:255'],
            'businessEntityEmail' => ['required', 'email'],
            'businessEntityPhone' => ['required', 'string', 'max:255'],
        ], [], [
            'businessEntityName' => 'שם העוסק',
            'businessEntityCompanyNumber' => 'ח.פ / ע.מ',
            'businessEntityEmail' => 'אימייל',
            'businessEntityPhone' => 'טלפון',
        ]);

        $entity = BusinessEntity::create([
            'name' => $data['businessEntityName'],
            'classification' => $data['businessEntityClassification'],
            'company_number' => $data['businessEntityCompanyNumber'],
            'email' => $data['businessEntityEmail'],
            'phone' => $data['businessEntityPhone'],
            'is_active' => true,
        ]);

        $activityLogger->log('business_entity.created', "נוצר עוסק חדש: {$entity->name}");

        $this->reset(['businessEntityName', 'businessEntityCompanyNumber', 'businessEntityEmail', 'businessEntityPhone']);
        $this->businessEntityClassification = 'עוסק פטור';
        unset($this->businessEntities);
        $this->loadSumitCompanies();
    }

    public function toggleBusinessEntity(int $id, ActivityLogger $activityLogger): void
    {
        $this->toggleActive(BusinessEntity::class, $id, $activityLogger, 'business_entity');
        unset($this->businessEntities);
    }

    #[Computed]
    public function businessEntities()
    {
        return BusinessEntity::orderBy('name')->get();
    }

    // ----- תבניות דואר -----

    public function addEmailTemplate(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'emailTemplateName' => ['required', 'string', 'max:255'],
            'emailTemplateType' => ['required', 'in:'.implode(',', array_keys(EmailTemplate::TYPE_LABELS))],
            'emailTemplateSubject' => ['required', 'string'],
            'emailTemplateContent' => ['required', 'string'],
        ], [], [
            'emailTemplateName' => 'שם התבנית',
            'emailTemplateType' => 'סוג',
            'emailTemplateSubject' => 'נושא',
            'emailTemplateContent' => 'תוכן',
        ]);

        $template = EmailTemplate::create([
            'name' => $data['emailTemplateName'],
            'template_type' => $data['emailTemplateType'],
            'subject' => $data['emailTemplateSubject'],
            'content' => $data['emailTemplateContent'],
            'is_active' => true,
        ]);

        $activityLogger->log('email_template.created', "נוצרה תבנית דואר חדשה: {$template->name}");

        $this->reset(['emailTemplateName', 'emailTemplateType', 'emailTemplateSubject', 'emailTemplateContent']);
        unset($this->emailTemplates);
    }

    public function toggleEmailTemplate(int $id, ActivityLogger $activityLogger): void
    {
        $this->toggleActive(EmailTemplate::class, $id, $activityLogger, 'email_template');
        unset($this->emailTemplates);
    }

    /**
     * Adds a merge-field row to a template's field editor (FR-7.12). Templates
     * aren't sent anywhere yet in this stage (real SMTP sending / usage tracking
     * is a future stage), so unlike the other 6 reference entities there is no
     * "already used elsewhere" snapshot to protect yet — field rows can be freely
     * added/removed while the template itself is still just being drafted. This
     * should be revisited (switch to disable-only) once templates are actually
     * used to send real emails, per FR-7.11's snapshot principle.
     */
    public function addEmailTemplateField(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'fieldTemplateId' => ['required', 'exists:email_templates,id'],
            'fieldName' => ['required', 'string', 'max:255'],
            'fieldType' => ['required', 'in:free_text,linked'],
            'fieldLinkedField' => ['required_if:fieldType,linked', 'nullable', 'in:'.implode(',', array_keys(EmailTemplateField::LINKED_FIELDS))],
            'fieldIsRequired' => ['boolean'],
        ], [], ['fieldName' => 'שם השדה', 'fieldLinkedField' => 'שדה מקושר']);

        $nextSort = (int) EmailTemplateField::where('email_template_id', $data['fieldTemplateId'])->max('sort_order') + 1;

        $field = EmailTemplateField::create([
            'email_template_id' => $data['fieldTemplateId'],
            'name' => $data['fieldName'],
            'field_type' => $data['fieldType'],
            'linked_field' => $data['fieldType'] === 'linked' ? $data['fieldLinkedField'] : null,
            'is_required' => (bool) $data['fieldIsRequired'],
            'sort_order' => $nextSort,
        ]);

        $activityLogger->log('email_template_field.created', "נוסף שדה מיזוג \"{$field->name}\" לתבנית", [
            'metadata' => ['email_template_id' => $field->email_template_id],
        ]);

        $this->reset(['fieldName', 'fieldLinkedField', 'fieldIsRequired']);
        $this->fieldType = 'free_text';
        unset($this->emailTemplates);
    }

    public function removeEmailTemplateField(int $id, ActivityLogger $activityLogger): void
    {
        $field = EmailTemplateField::findOrFail($id);
        $templateId = $field->email_template_id;
        $name = $field->name;
        $field->delete();

        $activityLogger->log('email_template_field.removed', "הוסר שדה מיזוג \"{$name}\" מתבנית", [
            'metadata' => ['email_template_id' => $templateId],
        ]);

        unset($this->emailTemplates);
    }

    #[Computed]
    public function emailTemplates()
    {
        return EmailTemplate::with('fields')->orderBy('name')->get();
    }

    // ----- אינטגרציות חיצוניות -----

    public function toggleIntegration(int $id, ActivityLogger $activityLogger): void
    {
        $this->toggleActive(ExternalIntegrationSetting::class, $id, $activityLogger, 'external_integration_setting');
        unset($this->integrations);
    }

    #[Computed]
    public function integrations()
    {
        return ExternalIntegrationSetting::orderBy('system')->get();
    }

    /**
     * Shared by every "השבתה/הפעלה" button below: a pure boolean flip, never a
     * delete, so records already using the old value keep working unaffected
     * (FR-7.8 — settings changes affect future actions only).
     */
    private function toggleActive(string $modelClass, int $id, ActivityLogger $activityLogger, string $activityPrefix): void
    {
        /** @var Model&\Illuminate\Database\Eloquent\Model $record */
        $record = $modelClass::findOrFail($id);
        $record->is_active = ! $record->is_active;
        $record->save();

        $label = $record->name ?? (ExternalOperation::SYSTEM_LABELS[$record->system] ?? null) ?? "#{$record->id}";
        $verb = $record->is_active ? 'הופעל' : 'הושבת';

        $activityLogger->log("{$activityPrefix}.toggled", "{$label} {$verb}", [
            'metadata' => ['id' => $record->id, 'is_active' => $record->is_active],
        ]);
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">הגדרות מערכת</h1>
        </div>
    </div>

    <div class="tabs">
        <span class="{{ $activeTab === 'statuses' ? 'active' : '' }}" wire:click="$set('activeTab', 'statuses')">סטטוסים</span>
        <span class="{{ $activeTab === 'subscriptions' ? 'active' : '' }}" wire:click="$set('activeTab', 'subscriptions')">מנויים</span>
        <span class="{{ $activeTab === 'leads' ? 'active' : '' }}" wire:click="$set('activeTab', 'leads')">לידים</span>
        <span class="{{ $activeTab === 'payment-methods' ? 'active' : '' }}" wire:click="$set('activeTab', 'payment-methods')">אמצעי תשלום</span>
        <span class="{{ $activeTab === 'business-entities' ? 'active' : '' }}" wire:click="$set('activeTab', 'business-entities')">עוסקים</span>
        <span class="{{ $activeTab === 'email-templates' ? 'active' : '' }}" wire:click="$set('activeTab', 'email-templates')">תבניות דואר</span>
        <span class="{{ $activeTab === 'integrations' ? 'active' : '' }}" wire:click="$set('activeTab', 'integrations')">אינטגרציות</span>
    </div>

    @if ($activeTab === 'statuses')
    {{-- ===== סטטוסים ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>סטטוסים</h2>
        </div>
        @foreach ($this->statuses as $scope => $items)
            <h3 style="margin-top: var(--sp-lg)">{{ $this->statusScopes[$scope] ?? $scope }}</h3>
            <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
                <div class="table-scroll">
                <table>
                    <thead><tr><th>שם</th><th>סדר</th><th>סטטוס</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $status)
                            @if ($editingRecord === "status:{$status->id}")
                                <tr>
                                    <td>
                                        @if ($status->isSystem())
                                            {{ $status->name }} <span class="text-text-secondary" style="font-size:var(--fs-caption)">(סטטוס מערכת — השם קבוע)</span>
                                        @else
                                            <input type="text" wire:model="editForm.name"> @error('editForm.name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                        @endif
                                    </td>
                                    <td><input type="number" min="0" wire:model="editForm.sort_order" style="max-width:90px"> @error('editForm.sort_order') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                    <td colspan="2"><button type="button" wire:click="saveRecord" class="btn btn-primary btn-sm">שמירה</button> <button type="button" wire:click="cancelRecordEdit" class="btn btn-ghost btn-sm">ביטול</button></td>
                                </tr>
                                @continue
                            @endif
                            <tr>
                                <td>{{ $status->name }}</td>
                                <td class="ltr-num">{{ $status->sort_order }}</td>
                                <td>
                                    @if ($status->is_active)
                                        <span class="badge badge-success">פעיל</span>
                                    @else
                                        <span class="badge badge-neutral">מושבת</span>
                                    @endif
                                </td>
                                <td><button type="button" wire:click="editRecord('status', {{ $status->id }})" class="btn btn-ghost">עריכה</button><button type="button" wire:click="toggleStatus({{ $status->id }})" class="btn btn-ghost">{{ $status->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endforeach

        <div class="card" style="max-width:640px">
            <h3>סטטוס חדש</h3>
            <form wire:submit="addStatus" class="form-grid">
                <div>
                    <label for="statusScope">סקופ</label>
                    <select id="statusScope" wire:model="statusScope">
                        @foreach ($statusScopes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statusName">שם הסטטוס</label>
                    <input type="text" id="statusName" wire:model="statusName">
                    @error('statusName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">הוספת סטטוס</button></div>
            </form>
        </div>
    </section>
    @endif

    @if ($activeTab === 'subscriptions')
    {{-- ===== מנויים ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>מנויים</h2>
        </div>
        <div class="card" style="max-width:640px">
            <form wire:submit="saveSubscriptionSettings" class="form-grid">
                <div>
                    <label for="subscriptionStartMonth">חודש תחילת מנוי (חודש עברי)</label>
                    <select id="subscriptionStartMonth" wire:model="subscriptionStartMonth">
                        @foreach (\App\Services\HebrewCalendar::MONTHS as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('subscriptionStartMonth') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <p class="full text-text-secondary" style="font-size:var(--fs-caption); margin:0">חודש 1 של כל מנוי הוא החודש שנבחר כאן, וממנו נספרים 10 חודשים עבריים (בשנה מעוברת אדר א׳ ואדר ב׳ הם שני חודשים). מנוי שנפתח אחרי תום 10 החודשים מתחיל במחזור הבא. שינוי ההגדרה חל על מנויים חדשים בלבד.</p>
                <div class="full"><button type="submit" class="btn btn-primary">שמירה</button></div>
            </form>
        </div>
    </section>
    @endif

    @if ($activeTab === 'leads')
    {{-- ===== מקורות ליד ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>מקורות ליד</h2>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>שם</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->leadSources as $source)
                        @if ($editingRecord === "lead_source:{$source->id}")
                            <tr>
                                <td><input type="text" wire:model="editForm.name"> @error('editForm.name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                <td colspan="2"><button type="button" wire:click="saveRecord" class="btn btn-primary btn-sm">שמירה</button> <button type="button" wire:click="cancelRecordEdit" class="btn btn-ghost btn-sm">ביטול</button></td>
                            </tr>
                            @continue
                        @endif
                        <tr>
                            <td>{{ $source->name }}</td>
                            <td>
                                @if ($source->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="editRecord('lead_source', {{ $source->id }})" class="btn btn-ghost">עריכה</button><button type="button" wire:click="toggleLeadSource({{ $source->id }})" class="btn btn-ghost">{{ $source->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <div class="card" style="max-width:640px">
            <form wire:submit="addLeadSource" class="form-grid">
                <div class="full">
                    <label for="leadSourceName">מקור ליד חדש</label>
                    <input type="text" id="leadSourceName" wire:model="leadSourceName">
                    @error('leadSourceName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">הוספת מקור</button></div>
            </form>
        </div>
    </section>
    @endif

    @if ($activeTab === 'payment-methods')
    {{-- ===== אמצעי תשלום ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>אמצעי תשלום</h2>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>שם</th><th>סוג</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->paymentMethods as $method)
                        @if ($editingRecord === "payment_method:{$method->id}")
                            <tr>
                                <td><input type="text" wire:model="editForm.name"> @error('editForm.name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                <td><select wire:model="editForm.type">@foreach (PaymentMethod::TYPE_LABELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach @if (! array_key_exists($editForm['type'] ?? '', PaymentMethod::TYPE_LABELS))<option value="{{ $editForm['type'] }}">אחר</option>@endif</select> @error('editForm.type') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                <td colspan="2"><button type="button" wire:click="saveRecord" class="btn btn-primary btn-sm">שמירה</button> <button type="button" wire:click="cancelRecordEdit" class="btn btn-ghost btn-sm">ביטול</button></td>
                            </tr>
                            @continue
                        @endif
                        <tr>
                            <td>{{ $method->name }}</td>
                            <td>{{ $method->typeLabel() }}</td>
                            <td>
                                @if ($method->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="editRecord('payment_method', {{ $method->id }})" class="btn btn-ghost">עריכה</button><button type="button" wire:click="togglePaymentMethod({{ $method->id }})" class="btn btn-ghost">{{ $method->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <div class="card" style="max-width:640px">
            <form wire:submit="addPaymentMethod" class="form-grid">
                <div>
                    <label for="paymentMethodName">שם</label>
                    <input type="text" id="paymentMethodName" wire:model="paymentMethodName">
                    @error('paymentMethodName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="paymentMethodType">סוג</label>
                    <select id="paymentMethodType" wire:model="paymentMethodType">
                        <option value="">בחרי סוג...</option>
                        @foreach (PaymentMethod::TYPE_LABELS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('paymentMethodType') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">הוספת אמצעי תשלום</button></div>
            </form>
        </div>
    </section>
    @endif

    @if ($activeTab === 'business-entities')
    {{-- ===== עוסקים ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>עוסקים</h2>
            <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">נבחר בכל הפקת חשבונית</p>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>שם</th><th>סיווג</th><th>ח.פ / ע.מ</th><th>אימייל</th><th>חיבור SUMIT</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->businessEntities as $entity)
                        @if ($editingRecord === "business_entity:{$entity->id}")
                            <tr>
                                <td><input type="text" wire:model="editForm.name"> @error('editForm.name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                <td>
                                    <select wire:model="editForm.classification">
                                        @foreach ($businessEntityClassifications as $classification)
                                            <option value="{{ $classification }}">{{ $classification }}</option>
                                        @endforeach
                                    </select> @error('editForm.classification') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                </td>
                                <td><input type="text" wire:model="editForm.company_number" class="ltr-num" dir="ltr"> @error('editForm.company_number') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                <td><input type="text" wire:model="editForm.email" class="ltr-num" dir="ltr"> @error('editForm.email') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                <td><input type="text" wire:model="editForm.phone" class="ltr-num" dir="ltr" placeholder="טלפון"> @error('editForm.phone') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</td>
                                <td colspan="2"><button type="button" wire:click="saveRecord" class="btn btn-primary btn-sm">שמירה</button> <button type="button" wire:click="cancelRecordEdit" class="btn btn-ghost btn-sm">ביטול</button></td>
                            </tr>
                            @continue
                        @endif
                        <tr>
                            <td>{{ $entity->name }}</td>
                            <td><span class="badge badge-info">{{ $entity->classification }}</span></td>
                            <td class="ltr-num">{{ $entity->company_number }}</td>
                            <td class="ltr-num" style="direction:ltr; text-align:right">{{ $entity->email }}</td>
                            <td>{{ $entity->sumit_connection_name ?: '—' }}</td>
                            <td>
                                @if ($entity->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="editRecord('business_entity', {{ $entity->id }})" class="btn btn-ghost">עריכה</button><button type="button" wire:click="toggleBusinessEntity({{ $entity->id }})" class="btn btn-ghost">{{ $entity->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <div class="card" style="max-width:640px">
            <form wire:submit="addBusinessEntity" class="form-grid">
                <div>
                    <label for="businessEntityName">שם העוסק</label>
                    <input type="text" id="businessEntityName" wire:model="businessEntityName">
                    @error('businessEntityName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="businessEntityClassification">סיווג</label>
                    <select id="businessEntityClassification" wire:model="businessEntityClassification">
                        @foreach ($businessEntityClassifications as $classification)
                            <option value="{{ $classification }}">{{ $classification }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="businessEntityCompanyNumber">ח.פ / ע.מ</label>
                    <input type="text" id="businessEntityCompanyNumber" wire:model="businessEntityCompanyNumber" class="ltr-num" dir="ltr">
                    @error('businessEntityCompanyNumber') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="businessEntityPhone">טלפון</label>
                    <input type="text" id="businessEntityPhone" wire:model="businessEntityPhone" class="ltr-num" dir="ltr">
                    @error('businessEntityPhone') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full">
                    <label for="businessEntityEmail">אימייל</label>
                    <input type="text" id="businessEntityEmail" wire:model="businessEntityEmail" class="ltr-num" dir="ltr">
                    @error('businessEntityEmail') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">הוספת עוסק</button></div>
            </form>
        </div>
    </section>
    @endif

    @if ($activeTab === 'email-templates')
    {{-- ===== תבניות דואר ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>תבניות דואר</h2>
            <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">מיילים אוטומטיים שהמערכת שולחת בעצמה, כמו אישור קליטת ליד — לא מסמכים עסקיים ולא דיוור לרשימות תפוצה</p>
        </div>

        @foreach ($this->emailTemplates as $template)
            <div class="card" style="margin-bottom: var(--sp-md)">
                <div style="display:flex; justify-content:space-between; align-items:flex-start">
                    <div>
                        <h3 style="margin-bottom:2px">{{ $template->name }}</h3>
                        <p class="text-text-secondary" style="margin:0; font-size:var(--fs-caption)">{{ EmailTemplate::TYPE_LABELS[$template->template_type] ?? 'כללי' }} · {{ $template->subject }}</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px">
                        @if ($template->is_active)
                            <span class="badge badge-success">פעיל</span>
                        @else
                            <span class="badge badge-neutral">מושבת</span>
                        @endif
                        <button type="button" wire:click="editRecord('email_template', {{ $template->id }})" class="btn btn-ghost">עריכה</button>
                        <button type="button" wire:click="toggleEmailTemplate({{ $template->id }})" class="btn btn-ghost">{{ $template->is_active ? 'השבתה' : 'הפעלה' }}</button>
                    </div>
                </div>

                @if ($editingRecord === "email_template:{$template->id}")
                    <div class="form-grid" style="margin-top:var(--sp-md)">
                        <div><label>שם התבנית</label><input type="text" wire:model="editForm.name"> @error('editForm.name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                        <div><label>שימוש</label><select wire:model="editForm.template_type">@foreach (EmailTemplate::TYPE_LABELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach @if (! array_key_exists($editForm['template_type'] ?? '', EmailTemplate::TYPE_LABELS))<option value="{{ $editForm['template_type'] }}">כללי</option>@endif</select> @error('editForm.template_type') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                        <div class="full"><label>נושא</label><input type="text" wire:model="editForm.subject"> @error('editForm.subject') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                        <div class="full"><label>תוכן</label><textarea wire:model="editForm.content" rows="6"></textarea> <div class="hint text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">אפשר לשלב בטקסט: @foreach (array_keys(EmailTemplate::PLACEHOLDERS) as $token)<span dir="rtl" style="unicode-bidi:isolate">{{ str_repeat('{', 2).$token.str_repeat('}', 2) }}</span>@if (! $loop->last), @endif @endforeach</div> @error('editForm.content') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                        <div class="full"><button type="button" wire:click="saveRecord" class="btn btn-primary btn-sm">שמירה</button> <button type="button" wire:click="cancelRecordEdit" class="btn btn-ghost btn-sm">ביטול</button></div>
                    </div>
                @endif

                <div class="table-scroll">
                <table style="margin-top: var(--sp-md)">
                    <thead><tr><th>שדה מיזוג</th><th>סוג</th><th>שדה מקושר</th><th>חובה</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($template->fields as $field)
                            <tr>
                                <td>{{ $field->name }}</td>
                                <td><span class="scope-pill">{{ $field->field_type === 'linked' ? 'מקושר' : 'טקסט חופשי' }}</span></td>
                                <td>{{ $field->linked_field ? (EmailTemplateField::LINKED_FIELDS[$field->linked_field] ?? '—') : '—' }}</td>
                                <td>{{ $field->is_required ? 'כן' : 'לא' }}</td>
                                <td><button type="button" wire:click="removeEmailTemplateField({{ $field->id }})" class="btn btn-ghost">הסרה</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-text-secondary">אין עדיין שדות מיזוג בתבנית זו.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        @endforeach

        <div class="cols2">
            <div class="card">
                <h3>תבנית חדשה</h3>
                <form wire:submit="addEmailTemplate" class="form-grid">
                    <div class="full">
                        <label for="emailTemplateName">שם התבנית</label>
                        <input type="text" id="emailTemplateName" wire:model="emailTemplateName">
                        @error('emailTemplateName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="emailTemplateType">שימוש</label>
                        <select id="emailTemplateType" wire:model="emailTemplateType">
                            <option value="">בחרי...</option>
                            @foreach (EmailTemplate::TYPE_LABELS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('emailTemplateType') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="emailTemplateSubject">נושא</label>
                        <input type="text" id="emailTemplateSubject" wire:model="emailTemplateSubject">
                        @error('emailTemplateSubject') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <label for="emailTemplateContent">תוכן</label>
                        <textarea id="emailTemplateContent" wire:model="emailTemplateContent" rows="3"></textarea>
                        <div class="hint text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">אפשר לשלב בטקסט: @foreach (array_keys(EmailTemplate::PLACEHOLDERS) as $token)<span dir="rtl" style="unicode-bidi:isolate">{{ str_repeat('{', 2).$token.str_repeat('}', 2) }}</span>@if (! $loop->last), @endif @endforeach</div>
                        @error('emailTemplateContent') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">הוספת תבנית</button></div>
                </form>
            </div>

            <div class="card">
                <h3>הוספת שדה מיזוג</h3>
                <form wire:submit="addEmailTemplateField" class="form-grid">
                    <div class="full">
                        <label for="fieldTemplateId">תבנית</label>
                        <select id="fieldTemplateId" wire:model="fieldTemplateId">
                            <option value="">בחרו תבנית</option>
                            @foreach ($this->emailTemplates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                        @error('fieldTemplateId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="fieldName">שם השדה</label>
                        <input type="text" id="fieldName" wire:model="fieldName">
                        @error('fieldName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="fieldType">סוג שדה</label>
                        <select id="fieldType" wire:model.live="fieldType">
                            <option value="free_text">טקסט חופשי</option>
                            <option value="linked">מקושר למידע עסקי</option>
                        </select>
                    </div>
                    @if ($fieldType === 'linked')
                        <div class="full">
                            <label for="fieldLinkedField">שדה מקושר</label>
                            <select id="fieldLinkedField" wire:model="fieldLinkedField">
                                <option value="">בחרי שדה...</option>
                                @foreach (EmailTemplateField::LINKED_FIELDS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('fieldLinkedField') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                    @endif
                    <div class="full checkbox-row">
                        <input type="checkbox" id="fieldIsRequired" wire:model="fieldIsRequired">
                        <label for="fieldIsRequired" style="margin:0">שדה חובה</label>
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">הוספת שדה</button></div>
                </form>
            </div>
        </div>
    </section>
    @endif

    @if ($activeTab === 'integrations')
    {{-- ===== אינטגרציות חיצוניות ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>אינטגרציות חיצוניות</h2>
            <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">חיבור אמיתי ל-Smove ו-Summit, וה-Webhook הנכנס מדף הנחיתה — כל עוד "מושבת" למטה, שום קריאה החוצה לא מתבצעת בפועל</p>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>מערכת</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->integrations as $integration)
                        <tr>
                            <td>{{ ExternalOperation::SYSTEM_LABELS[$integration->system] ?? 'חיבור חיצוני' }}</td>
                            <td>
                                @if ($integration->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="toggleIntegration({{ $integration->id }})" class="btn btn-ghost">{{ $integration->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div class="cols2">
            <div class="card">
                <h3>Smove — חיבור החשבון שלך</h3>
                <form wire:submit="saveSmoveSettings" class="form-grid">
                    <div class="full">
                        <label for="smoveApiKey">מפתח API (חובה)</label>
                        <input type="password" id="smoveApiKey" wire:model="smoveApiKey" class="ltr-num" dir="ltr">
                        <p class="hint text-text-secondary" style="font-size:var(--fs-caption); margin:6px 0 0">
                            איפה מוצאים את זה: נכנסים לחשבון שלכם ב-<span class="ltr-num" dir="ltr">smoove.io</span> ←
                            עוברים עם העכבר על שם החשבון (למעלה) ← בוחרים <b><span class="ltr-num" dir="ltr">API Keys &amp; pixels</span></b> ←
                            <b><span class="ltr-num" dir="ltr">Add API Key</span></b> ← בוחרים הרשאה <b>Full Permission</b> ←
                            שומרים, ומעתיקים את המפתח שנוצר לכאן.
                        </p>
                    </div>
                    <div class="full">
                        <label for="smoveWebhookSecret">סוד Webhook (רשות)</label>
                        <input type="password" id="smoveWebhookSecret" wire:model="smoveWebhookSecret" class="ltr-num" dir="ltr">
                        <p class="hint text-text-secondary" style="font-size:var(--fs-caption); margin:6px 0 0">
                            זה לא מגיע מ-Smove — זו מילת סוד שאתם ממציאים בעצמכם (כמו סיסמה), ומזינים גם כאן וגם בממשק Smove
                            אם מגדירים שם התראה על "מייל נפתח". אין לכם דבר כזה מוגדר? אפשר להשאיר ריק.
                        </p>
                    </div>
                    <div>
                        <label for="smoveListId">מזהה רשימה ב-Smove</label>
                        <input type="text" id="smoveListId" wire:model="smoveListId" class="ltr-num" dir="ltr" placeholder="למשל 1174405">
                        @error('smoveListId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="smoveMaterialReminderHours">כמה שעות לחכות לפני תזכורת על חומרי לימוד שלא נפתחו</label>
                        <input type="text" id="smoveMaterialReminderHours" wire:model="smoveMaterialReminderHours" class="ltr-num" dir="ltr">
                        @error('smoveMaterialReminderHours') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">שמירת הגדרות Smove</button></div>
                </form>
            </div>

            <div class="card">
                <h3>SUMIT (סאמיט) — חיבורים</h3>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-8px">לכל עוסק יש חיבור משלו לחשבון SUMIT נפרד, וחשבונית נשלחת לחשבון של העוסק שממנו הופקה. תנו לכל חיבור שם שיזהה אותו. מספר החברה והמפתח הפרטי נמצאים ב-SUMIT בתפריט API ← מפתחות API. להוספת חיבור — הוסיפו עוסק בטבלת העוסקים.</p>
                @foreach ($sumitCompanies as $entityId => $company)
                    <form wire:submit="saveSumitCompany({{ $entityId }})" class="form-grid" style="border-top:1px solid var(--color-border); padding-top:var(--sp-md); margin-top:var(--sp-md)">
                        <div class="full" style="display:flex; align-items:center; gap:var(--sp-sm); flex-wrap:wrap">
                            <strong>{{ $company['connection_name'] ?: 'חיבור ללא שם' }}</strong>
                            @if ($company['company_id'] !== '' && $company['has_key'])
                                <span class="badge badge-success">מוגדר</span>
                            @else
                                <span class="badge badge-neutral">חסרים פרטים</span>
                            @endif
                        </div>
                        <div>
                            <label for="sumitConnectionName{{ $entityId }}">שם החיבור</label>
                            <input type="text" id="sumitConnectionName{{ $entityId }}" wire:model="sumitCompanies.{{ $entityId }}.connection_name" placeholder="לדוגמה: סאמיט — חשבון ראשי">
                            @error("sumitCompanies.{$entityId}.connection_name") <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="sumitName{{ $entityId }}">עוסק</label>
                            <input type="text" id="sumitName{{ $entityId }}" wire:model="sumitCompanies.{{ $entityId }}.name">
                            @error("sumitCompanies.{$entityId}.name") <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="sumitCompanyId{{ $entityId }}">מספר חברה ב-SUMIT</label>
                            <input type="text" id="sumitCompanyId{{ $entityId }}" wire:model="sumitCompanies.{{ $entityId }}.company_id" class="ltr-num" dir="ltr" inputmode="numeric">
                            @error("sumitCompanies.{$entityId}.company_id") <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="sumitApiKey{{ $entityId }}">מפתח API (פרטי)</label>
                            <input type="password" id="sumitApiKey{{ $entityId }}" wire:model="sumitCompanies.{{ $entityId }}.api_key" class="ltr-num" dir="ltr" placeholder="{{ $company['has_key'] ? '•••••• מפתח שמור — להחלפה הקלידו חדש' : '' }}" autocomplete="new-password">
                        </div>
                        <div class="full" style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                            <button type="submit" class="btn btn-primary">שמירה</button>
                            <button type="button" wire:click="testSumitCompany({{ $entityId }})" class="btn btn-secondary">בדיקת חיבור</button>
                        </div>
                        @error("sumitConnection.{$entityId}") <div class="full" style="color: var(--color-error); font-size: var(--fs-caption);">{{ $message }}</div> @enderror
                    </form>
                @endforeach
                <form wire:submit="saveSummitSettings" class="form-grid" style="border-top:1px solid var(--color-border); padding-top:var(--sp-md); margin-top:var(--sp-md)">
                    <div class="full">
                        <span class="checkbox-row"><input type="checkbox" id="summitTestMode" wire:model="summitTestMode"><label for="summitTestMode" style="margin:0">מצב בדיקה (לכל החברות) — מסמכים יופקו ב-SUMIT כטיוטה, וחיובי אשראי יבוצעו כבדיקת אישור בלבד, ללא חיוב בפועל</label></span>
                    </div>
                    <div class="full"><button type="submit" class="btn btn-secondary">שמירת מצב בדיקה</button></div>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top:var(--sp-md); max-width:640px">
            <h3>דף הנחיתה — סוד Webhook</h3>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-8px">יש להזין סוד זה גם בהגדרות דף הנחיתה עצמו, בכותרת X-Webhook-Secret.</p>
            <form wire:submit="saveLandingPageSettings" class="form-grid">
                <div class="full">
                    <label for="landingPageWebhookSecret">סוד Webhook</label>
                    <input type="password" id="landingPageWebhookSecret" wire:model="landingPageWebhookSecret" class="ltr-num" dir="ltr">
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">שמירה</button></div>
            </form>
        </div>
    </section>
    @endif
</div>
