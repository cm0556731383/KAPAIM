<?php

use App\Concerns\Notifies;
use App\Console\Commands\ProcessMaterialReminders;
use App\Models\AppSetting;
use App\Models\BusinessEntity;
use App\Models\EmailTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Models\ExternalOperation;
use App\Models\LeadSource;
use App\Models\PaymentMethod;
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
    /** The template open in the editor — null when none is chosen. */
    public ?int $selectedEmailTemplateId = null;
    public string $emailTemplateName = '';
    public string $emailTemplateType = 'general';
    public string $emailTemplateSubject = '';
    public string $emailTemplateContent = '';

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
    public string $activeTab = 'subscriptions';

    // ===== מנויים =====
    public string $subscriptionStartMonth = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.view'), 403);

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
                // Only the last 4 characters ever reach the page, so the user can see which key is saved.
                'key_hint' => filled($entity->sumit_api_key) ? '••••••••'.mb_substr(trim($entity->sumit_api_key), -4) : '',
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
        'lead_source' => [LeadSource::class, ['name']],
        'payment_method' => [PaymentMethod::class, ['name', 'type']],
        'business_entity' => [BusinessEntity::class, ['name', 'classification', 'company_number', 'email', 'phone']],
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
                'editForm.phone' => ['required', 'string', 'max:255', new \App\Rules\Phone],
            ],
        };

        $data = $this->validate($rules, [], [
            'editForm.name' => 'שם', 'editForm.type' => 'סוג',
            'editForm.classification' => 'סיווג', 'editForm.company_number' => 'ח.פ / ע.מ', 'editForm.email' => 'אימייל',
            'editForm.phone' => 'טלפון', 'editForm.template_type' => 'סוג', 'editForm.subject' => 'נושא', 'editForm.content' => 'תוכן',
        ])['editForm'];

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
        unset($this->leadSources, $this->paymentMethods, $this->businessEntities);
        if ($type === 'business_entity') {
            $this->loadSumitCompanies();
        }
        $this->notifySuccess('השינויים נשמרו.');
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
        $this->dispatch('close-modals');
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
        $this->dispatch('close-modals');
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
            'businessEntityPhone' => ['required', 'string', 'max:255', new \App\Rules\Phone],
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
        $this->dispatch('close-modals');
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

    /** Opens a template in the editor below the list — new templates aren't created from the screen. */
    public function selectEmailTemplate(int $id): void
    {
        $this->resetErrorBag();
        $template = EmailTemplate::findOrFail($id);

        $this->selectedEmailTemplateId = $id;
        $this->emailTemplateName = (string) $template->name;
        $this->emailTemplateType = $template->template_type;
        $this->emailTemplateSubject = (string) $template->subject;
        $this->emailTemplateContent = (string) $template->content;
    }

    public function closeEmailTemplate(): void
    {
        $this->resetErrorBag();
        $this->reset(['selectedEmailTemplateId', 'emailTemplateName', 'emailTemplateType', 'emailTemplateSubject', 'emailTemplateContent']);
    }

    /**
     * Saves the open template. An email the code looks up by type (the
     * supplier-invoice requests, the materials reminder —
     * EmailTemplate::FIXED_TYPES) keeps it; the others may switch between
     * TYPE_LABELS' uses.
     */
    public function saveEmailTemplate(ActivityLogger $activityLogger): void
    {
        $template = EmailTemplate::findOrFail($this->selectedEmailTemplateId);
        $isFixed = in_array($template->template_type, EmailTemplate::FIXED_TYPES, true);

        $data = $this->validate([
            'emailTemplateName' => ['required', 'string', 'max:255'],
            'emailTemplateType' => $isFixed ? ['nullable'] : ['required', 'in:'.implode(',', array_unique([...array_keys(EmailTemplate::TYPE_LABELS), (string) $template->template_type]))],
            'emailTemplateSubject' => ['required', 'string', 'max:255'],
            'emailTemplateContent' => ['required', 'string'],
        ], [], [
            'emailTemplateName' => 'שם התבנית',
            'emailTemplateType' => 'שימוש',
            'emailTemplateSubject' => 'נושא המייל',
            'emailTemplateContent' => 'גוף המייל',
        ]);

        $values = [
            'name' => $data['emailTemplateName'],
            'subject' => $data['emailTemplateSubject'],
            'content' => $data['emailTemplateContent'],
        ];

        if (! $isFixed) {
            $values['template_type'] = $data['emailTemplateType'];
        }

        $template->update($values);
        $activityLogger->log('email_template.updated', "עודכן נוסח המייל \"{$template->name}\"");

        unset($this->emailTemplates);
        $this->notifySuccess('נוסח המייל נשמר.');
    }

    public function toggleEmailTemplate(int $id, ActivityLogger $activityLogger): void
    {
        // The materials email is always sent — disabling it would stop sending materials altogether.
        abort_if(in_array(EmailTemplate::findOrFail($id)->template_type, EmailTemplate::ALWAYS_ACTIVE_TYPES, true), 403);

        $this->toggleActive(EmailTemplate::class, $id, $activityLogger, 'email_template');
        unset($this->emailTemplates);
    }

    /** Every email template, the lead confirmation and supplier emails included. */
    #[Computed]
    public function emailTemplates()
    {
        return EmailTemplate::orderBy('name')->get();
    }

    /** What the open template is for — the shared editor's intro, insertable fields, optional block and example values. */
    public function emailTemplateSpec(): array
    {
        $uploadButton = '<span style="display:inline-block; background:#286E9A; color:#fff; border-radius:32px; padding:10px 28px; font-weight:700">להעלאת החשבונית</span>';
        $receivedButton = '<span style="display:inline-block; background:#286E9A; color:#fff; border-radius:32px; padding:10px 28px; font-weight:700">קיבלתי את החומרים</span>';

        return match ($this->emailTemplateType) {
            EmailTemplate::SUPPLIER_INVOICE_REQUEST => [
                'usage' => 'בקשת חשבונית מספק',
                'intro' => 'המייל שנשלח לספק עבור הוצאה אחת שאין לה חשבונית: בלחיצה על "בקשה מהספק במייל" בדף ההוצאות, או בשליחה לכל הספקים כשחסרה לספק חשבונית אחת. השדות בסוגריים המסולסלים יתמלאו בפרטי ההוצאה, והספק יקבל כפתור להעלאת החשבונית ישירות למערכת.',
                'fields' => ['שם_הספק' => 'שם הספק', 'תאריך_התשלום' => 'תאריך התשלום', 'סכום' => 'סכום', 'תוכנית' => 'תוכנית', 'הערות' => 'הערות'],
                'blocks' => [EmailTemplate::UPLOAD_BUTTON_TOKEN => ['label' => 'כפתור העלאת החשבונית', 'preview' => $uploadButton]],
                'example' => [
                    'supplier_name' => 'דפוס הראל בע"מ',
                    'expense_date' => now()->subDays(12)->format('d/m/Y'),
                    'amount' => '1,250',
                    'program_name' => 'תוכנית לדוגמה',
                    'notes' => 'הדפסת 400 חוברות',
                    'expense_id' => '42',
                ],
            ],
            EmailTemplate::SUPPLIER_INVOICES_REQUEST => [
                'usage' => 'בקשת כמה חשבוניות מספק',
                'intro' => 'המייל שנשלח בלחיצה על "בקשת חשבוניות מכל הספקים" בדף ההוצאות לספק שחסרות לו כמה חשבוניות — מייל אחד עם רשימת ההוצאות, ולכל הוצאה כפתור משלה להעלאת החשבונית.',
                'fields' => ['שם_הספק' => 'שם הספק', 'מספר_החשבוניות_החסרות' => 'מספר החשבוניות החסרות', 'סכום_כולל' => 'סכום כולל'],
                'blocks' => [EmailTemplate::EXPENSE_LIST_TOKEN => ['label' => 'רשימת ההוצאות עם כפתורי ההעלאה', 'preview' => collect(['תשלום מתאריך 03/09/2026 · ₪1,250', 'תשלום מתאריך 21/09/2026 · ₪480'])->map(fn ($row) => '<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; padding:8px 0; border-bottom:1px solid var(--color-border)"><span>'.$row.'</span><span style="display:inline-block; background:#286E9A; color:#fff; border-radius:24px; padding:6px 16px; font-weight:700; font-size:var(--fs-caption)">להעלאת החשבונית</span></div>')->implode('')]],
                'example' => ['supplier_name' => 'דפוס הראל בע"מ', 'expense_count' => '2', 'total_amount' => '1,730'],
            ],
            EmailTemplate::MATERIAL_DELIVERY => [
                'usage' => 'שליחת חומרי לימוד',
                'intro' => 'המייל שנשלח בלחיצה על "שליחת חומרים" בכרטיס הלקוחה, לכל הנמענים שנבחרו. רשימת החומרים היא הקבצים והקישורים שצורפו לשליחה. לחיצה על "קיבלתי את החומרים" מסמנת את המשלוח כהתקבל ומבטלת את התזכורת.',
                'fields' => ['שם_בית_הספר' => 'שם בית הספר', 'תוכנית' => 'תוכנית'],
                'blocks' => [
                    EmailTemplate::MATERIALS_LIST_TOKEN => ['label' => 'רשימת החומרים', 'preview' => '<ul style="margin:0; padding-inline-start:20px"><li><a style="color:#286E9A; font-weight:600">חוברת לתלמידה.pdf</a></li><li><a style="color:#286E9A; font-weight:600">סרטון הדרכה</a></li></ul>'],
                    EmailTemplate::ACKNOWLEDGE_BUTTON_TOKEN => ['label' => 'כפתור "קיבלתי את החומרים"', 'preview' => $receivedButton],
                ],
                'example' => ['school_name' => 'בית ספר אורות', 'program_name' => 'אלול תשפ"ו'],
            ],
            EmailTemplate::MATERIAL_REMINDER => [
                'usage' => 'תזכורת על חומרים שלא אושרו',
                'intro' => 'נשלחת פעם אחת לכל נמעני משלוח חומרים שלא אושר עד שעבר מספר השעות שמוגדר באינטגרציות ← Smove. לא נשלחת אם הלקוחה כבר לחצה "קיבלתי את החומרים", או אם המשלוח סומן כטופל. כשהתבנית מושבתת, לא נשלחות תזכורות.',
                'fields' => ['שם_בית_הספר' => 'שם בית הספר', 'תוכנית' => 'תוכנית'],
                'blocks' => [EmailTemplate::ACKNOWLEDGE_BUTTON_TOKEN => ['label' => 'כפתור "קיבלתי את החומרים"', 'preview' => $receivedButton]],
                'example' => ['school_name' => 'בית ספר אורות', 'program_name' => 'אלול תשפ"ו'],
            ],
            default => [
                'usage' => EmailTemplate::TYPE_LABELS[$this->emailTemplateType] ?? 'כללי',
                'intro' => $this->emailTemplateType === 'lead_confirmation'
                    ? 'נשלח אוטומטית לאיש הקשר כשנכנס ליד חדש מדף הנחיתה. השדות בסוגריים המסולסלים יתמלאו בפרטי הליד.'
                    : 'תבנית כללית — המערכת לא שולחת אותה אוטומטית.',
                'fields' => ['שם_איש_הקשר' => 'שם איש הקשר', 'שם_בית_הספר' => 'שם בית הספר'],
                'blocks' => [],
                'example' => ['contact_name' => 'רחל כהן', 'school_name' => 'בית ספר אורות'],
            ],
        };
    }

    /** The open template filled with the example values. */
    public function emailTemplatePreview(): array
    {
        return (new EmailTemplate(['subject' => $this->emailTemplateSubject, 'content' => $this->emailTemplateContent]))
            ->render($this->emailTemplateSpec()['example']);
    }

    /**
     * The preview's body as HTML: the text escaped, each block's sample where
     * its token sits, and a block the text doesn't place at the end — the
     * same rule the real send follows (SmoveClient::textWithBlocks()).
     */
    public function emailTemplatePreviewHtml(): string
    {
        $blocks = collect($this->emailTemplateSpec()['blocks'])->map(fn (array $block) => '<div style="margin:var(--sp-sm) 0">'.$block['preview'].'</div>');
        $content = $this->emailTemplatePreview()['content'];
        $text = fn (string $part) => trim($part, "\n") === '' ? '' : '<div style="white-space:pre-wrap">'.e(trim($part, "\n")).'</div>';

        if ($blocks->isEmpty()) {
            return $text($content);
        }

        $pattern = '/('.$blocks->keys()->map(fn ($token) => preg_quote($token, '/'))->implode('|').')/u';

        return collect(preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE))
            ->map(fn (string $part) => $blocks[$part] ?? $text($part))
            ->implode('')
            .$blocks->reject(fn ($html, $token) => str_contains($content, $token))->implode('');
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
        <span class="{{ $activeTab === 'subscriptions' ? 'active' : '' }}" wire:click="$set('activeTab', 'subscriptions')">מנויים</span>
        <span class="{{ $activeTab === 'leads' ? 'active' : '' }}" wire:click="$set('activeTab', 'leads')">לידים</span>
        <span class="{{ $activeTab === 'payment-methods' ? 'active' : '' }}" wire:click="$set('activeTab', 'payment-methods')">אמצעי תשלום</span>
        <span class="{{ $activeTab === 'business-entities' ? 'active' : '' }}" wire:click="$set('activeTab', 'business-entities')">עוסקים</span>
        <span class="{{ $activeTab === 'email-templates' ? 'active' : '' }}" wire:click="$set('activeTab', 'email-templates')">תבניות דואר</span>
        @can('document-templates.view')
        <span class="{{ $activeTab === 'document-templates' ? 'active' : '' }}" wire:click="$set('activeTab', 'document-templates')">תבניות מסמכים</span>
        @endcan
        <span class="{{ $activeTab === 'integrations' ? 'active' : '' }}" wire:click="$set('activeTab', 'integrations')">אינטגרציות</span>
    </div>

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
        <div class="section-head" style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap">
            <h2>מקורות ליד</h2>
            <x-modal trigger-label="+ הוספת מקור ליד" title="מקור ליד חדש">
                <form wire:submit="addLeadSource" class="form-grid">
                    <div class="full">
                        <label for="leadSourceName">שם המקור</label>
                        <input type="text" id="leadSourceName" wire:model="leadSourceName">
                        @error('leadSourceName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">הוספת מקור</button></div>
                </form>
            </x-modal>
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
    </section>
    @endif

    @if ($activeTab === 'payment-methods')
    {{-- ===== אמצעי תשלום ===== --}}
    <section class="settings-section">
        <div class="section-head" style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap">
            <h2>אמצעי תשלום</h2>
            <x-modal trigger-label="+ הוספת אמצעי תשלום" title="אמצעי תשלום חדש">
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
            </x-modal>
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
    </section>
    @endif

    @if ($activeTab === 'business-entities')
    {{-- ===== עוסקים ===== --}}
    <section class="settings-section">
        <div class="section-head" style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap">
            <div>
                <h2>עוסקים</h2>
                <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">נבחר בכל הפקת חשבונית</p>
            </div>
            <x-modal trigger-label="+ הוספת עוסק" title="עוסק חדש">
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
            </x-modal>
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
    </section>
    @endif

    @if ($activeTab === 'document-templates')
    @can('document-templates.view')
    {{-- ===== תבניות מסמכים — its own component, with its own permissions ===== --}}
    <livewire:document-templates />
    @endcan
    @endif

    @if ($activeTab === 'email-templates')
    {{-- ===== תבניות דואר — a list; the chosen template opens in full below it ===== --}}
    <section class="settings-section">
        <div class="section-head" style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap">
            <div>
                <h2>תבניות דואר</h2>
                <p class="hint text-text-secondary" style="font-size:var(--fs-caption); margin:0">מיילים שהמערכת שולחת בעצמה — לא מסמכים עסקיים ולא דיוור לרשימות תפוצה. בוחרים תבנית כדי לערוך אותה.</p>
            </div>
        </div>

        <div class="card" style="padding:0; overflow:hidden; margin-bottom:var(--sp-md)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>שם</th><th>שימוש</th><th>נושא</th><th>סטטוס</th></tr></thead>
                <tbody>
                    @foreach ($this->emailTemplates as $template)
                        <tr wire:key="email-template-{{ $template->id }}" wire:click="selectEmailTemplate({{ $template->id }})" style="cursor:pointer; {{ $selectedEmailTemplateId === $template->id ? 'background:var(--color-background)' : '' }}">
                            <td style="font-weight:{{ $selectedEmailTemplateId === $template->id ? '700' : '400' }}">{{ $template->name }}</td>
                            <td>{{ match ($template->template_type) { EmailTemplate::SUPPLIER_INVOICE_REQUEST => 'בקשת חשבונית מספק', EmailTemplate::SUPPLIER_INVOICES_REQUEST => 'בקשת כמה חשבוניות מספק', EmailTemplate::MATERIAL_DELIVERY => 'שליחת חומרי לימוד', EmailTemplate::MATERIAL_REMINDER => 'תזכורת על חומרים שלא אושרו', default => EmailTemplate::TYPE_LABELS[$template->template_type] ?? 'כללי' } }}</td>
                            <td class="text-text-secondary">{{ \Illuminate\Support\Str::limit($template->subject, 60) }}</td>
                            <td>
                                @if ($template->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        @if ($selectedEmailTemplateId !== null)
            @php($spec = $this->emailTemplateSpec())
            @php($preview = $this->emailTemplatePreview())
            @php($openTemplate = $this->emailTemplates->firstWhere('id', $selectedEmailTemplateId))
            @php($isFixed = in_array($emailTemplateType, EmailTemplate::FIXED_TYPES, true))
            <div class="card" wire:key="email-template-editor-{{ $selectedEmailTemplateId }}" x-data="{ target: null, insert(token) {
                    const el = this.target ?? $refs.body;
                    const start = el.selectionStart ?? el.value.length, end = el.selectionEnd ?? start;
                    el.value = el.value.slice(0, start) + token + el.value.slice(end);
                    el.focus();
                    el.setSelectionRange(start + token.length, start + token.length);
                    el.dispatchEvent(new Event('input'));
                } }">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap; margin-bottom:var(--sp-sm)">
                    <div>
                        <h3 style="margin:0 0 2px">{{ $openTemplate?->name }}</h3>
                        <p class="text-text-secondary" style="margin:0; font-size:var(--fs-small)">{{ $spec['intro'] }}</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px">
                        @if ($openTemplate && ! in_array($openTemplate->template_type, EmailTemplate::ALWAYS_ACTIVE_TYPES, true))
                            <button type="button" wire:click="toggleEmailTemplate({{ $openTemplate->id }})" class="btn btn-ghost btn-sm">{{ $openTemplate->is_active ? 'השבתה' : 'הפעלה' }}</button>
                        @endif
                        <button type="button" wire:click="closeEmailTemplate" class="btn btn-ghost btn-sm">סגירה</button>
                    </div>
                </div>

                <form wire:submit="saveEmailTemplate" class="form-grid">
                    <div @class(['full' => $isFixed])>
                        <label for="emailTemplateName">שם התבנית</label>
                        <input type="text" id="emailTemplateName" wire:model="emailTemplateName">
                        @error('emailTemplateName') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    @unless ($isFixed)
                        <div>
                            <label for="emailTemplateType">שימוש</label>
                            <select id="emailTemplateType" wire:model.live="emailTemplateType">
                                @foreach (EmailTemplate::TYPE_LABELS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                                @if (! array_key_exists($emailTemplateType, EmailTemplate::TYPE_LABELS))
                                    <option value="{{ $emailTemplateType }}">כללי</option>
                                @endif
                            </select>
                            @error('emailTemplateType') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                    @endunless
                    <div class="full">
                        <label for="emailTemplateSubject">נושא המייל</label>
                        <input type="text" id="emailTemplateSubject" wire:model.live.debounce.400ms="emailTemplateSubject" x-on:focus="target = $el">
                        @error('emailTemplateSubject') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <label for="emailTemplateContent">גוף המייל</label>
                        <textarea id="emailTemplateContent" x-ref="body" wire:model.live.debounce.400ms="emailTemplateContent" x-on:focus="target = $el" rows="10"></textarea>
                        @error('emailTemplateContent') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <label>הוספת שדה (נכנס במקום הסמן)</label>
                        <div style="display:flex; flex-wrap:wrap; gap:6px">
                            @foreach ($spec['fields'] as $token => $label)
                                <button type="button" class="btn btn-ghost btn-sm" onmousedown="event.preventDefault()" x-on:click="insert('{{ str_repeat('{', 2).$token.str_repeat('}', 2) }}')">+ {{ $label }}</button>
                            @endforeach
                            @foreach ($spec['blocks'] as $token => $block)
                                <button type="button" class="btn btn-secondary btn-sm" onmousedown="event.preventDefault()" x-on:click="insert('{{ $token }}')">+ {{ $block['label'] }}</button>
                            @endforeach
                        </div>
                        @if ($spec['blocks'])
                            <div class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:6px">רכיב שלא מופיע בטקסט יתווסף בסוף המייל.</div>
                        @endif
                    </div>
                    <div class="full">
                        <label>תצוגה מקדימה (עם פרטים לדוגמה)</label>
                        <div style="border:1px solid var(--color-border); border-radius:var(--radius-control); padding:var(--sp-md); background:var(--color-background)">
                            <div style="font-weight:600; margin-bottom:var(--sp-sm)">{{ $preview['subject'] }}</div>
                            {!! $this->emailTemplatePreviewHtml() !!}
                        </div>
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">שמירת נוסח המייל</button></div>
                </form>
            </div>
        @endif
    </section>
    @endif

    @if ($activeTab === 'integrations')
    {{-- ===== אינטגרציות חיצוניות — a card per system, its on/off switch in its header ===== --}}
    @php($integrationsBySystem = $this->integrations->keyBy('system'))
    <section class="settings-section">
        <div class="section-head">
            <h2>אינטגרציות חיצוניות</h2>
            <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">כל חיבור מופעל או מושבת בכרטיס שלו. כשחיבור מושבת, המערכת לא פונה אליו בכלל.</p>
        </div>

        <div class="cols2">
            {{-- Right column: Smove, and the landing page right under it --}}
            <div style="display:flex; flex-direction:column; gap:var(--sp-md); min-width:0">
                <div class="card">
                @php($integration = $integrationsBySystem['smove'] ?? null)
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap; padding-bottom:var(--sp-md); margin-bottom:var(--sp-md); border-bottom:1px solid var(--color-border)">
                    <div style="min-width:0; flex:1 1 220px">
                        <h3 style="margin:0 0 4px">Smove — דיוור ומיילים</h3>
                        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">שליחת מיילים ללקוחות: חומרי לימוד, תזכורות, מסמכים ודיוור לרשימות תפוצה.</p>
                    </div>
                    @if ($integration)
                        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0">
                            <span class="badge {{ $integration->is_active ? 'badge-success' : 'badge-neutral' }}">{{ $integration->is_active ? 'פעיל' : 'מושבת' }}</span>
                            <button type="button" wire:click="toggleIntegration({{ $integration->id }})" class="btn btn-ghost btn-sm">{{ $integration->is_active ? 'השבתה' : 'הפעלה' }}</button>
                        </div>
                    @endif
                </div>
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
                        <label for="smoveMaterialReminderHours">כמה שעות לחכות לפני תזכורת על חומרי לימוד שלא אושרו</label>
                        <input type="text" id="smoveMaterialReminderHours" wire:model="smoveMaterialReminderHours" class="ltr-num" dir="ltr">
                        @error('smoveMaterialReminderHours') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">שמירת הגדרות Smove</button></div>
                </form>
                </div>

                <div class="card">
                @php($integration = $integrationsBySystem['landing_page'] ?? null)
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap; padding-bottom:var(--sp-md); margin-bottom:var(--sp-md); border-bottom:1px solid var(--color-border)">
                    <div style="min-width:0; flex:1 1 220px">
                        <h3 style="margin:0 0 4px">דף הנחיתה</h3>
                        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">פניות מטופס דף הנחיתה נכנסות כלידים חדשים. את הסוד מזינים גם בהגדרות דף הנחיתה עצמו, בכותרת X-Webhook-Secret.</p>
                    </div>
                    @if ($integration)
                        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0">
                            <span class="badge {{ $integration->is_active ? 'badge-success' : 'badge-neutral' }}">{{ $integration->is_active ? 'פעיל' : 'מושבת' }}</span>
                            <button type="button" wire:click="toggleIntegration({{ $integration->id }})" class="btn btn-ghost btn-sm">{{ $integration->is_active ? 'השבתה' : 'הפעלה' }}</button>
                        </div>
                    @endif
                </div>
                <form wire:submit="saveLandingPageSettings" class="form-grid">
                    <div class="full">
                        <label for="landingPageWebhookSecret">סוד Webhook</label>
                        <input type="password" id="landingPageWebhookSecret" wire:model="landingPageWebhookSecret" class="ltr-num" dir="ltr">
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">שמירה</button></div>
                </form>
                </div>
            </div>

            {{-- Left column: SUMIT and all its connections --}}
            <div class="card" style="min-width:0">
                @php($integration = $integrationsBySystem['summit'] ?? null)
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-md); flex-wrap:wrap; padding-bottom:var(--sp-md); margin-bottom:var(--sp-md); border-bottom:1px solid var(--color-border)">
                    <div style="min-width:0; flex:1 1 220px">
                        <h3 style="margin:0 0 4px">SUMIT (סאמיט) — חשבוניות וסליקה</h3>
                        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">לכל עוסק חיבור משלו לחשבון SUMIT נפרד, וחשבונית נשלחת לחשבון של העוסק שממנו הופקה. מספר החברה והמפתח הפרטי נמצאים ב-SUMIT בתפריט API ← מפתחות API. להוספת חיבור מוסיפים עוסק בלשונית עוסקים.</p>
                    </div>
                    @if ($integration)
                        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0">
                            <span class="badge {{ $integration->is_active ? 'badge-success' : 'badge-neutral' }}">{{ $integration->is_active ? 'פעיל' : 'מושבת' }}</span>
                            <button type="button" wire:click="toggleIntegration({{ $integration->id }})" class="btn btn-ghost btn-sm">{{ $integration->is_active ? 'השבתה' : 'הפעלה' }}</button>
                        </div>
                    @endif
                </div>
                @foreach ($sumitCompanies as $entityId => $company)
                    <form wire:submit="saveSumitCompany({{ $entityId }})" class="form-grid" style="background:var(--color-background); border:1px solid var(--color-border); border-radius:var(--radius-control); padding:var(--sp-md); margin-bottom:var(--sp-md)">
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
                            <input type="password" id="sumitApiKey{{ $entityId }}" wire:model="sumitCompanies.{{ $entityId }}.api_key" class="ltr-num" dir="ltr" placeholder="{{ $company['has_key'] ? $company['key_hint'] : '' }}" autocomplete="new-password">
                            @if ($company['has_key'])
                                <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">✓ מפתח שמור: <span dir="ltr">{{ $company['key_hint'] }}</span> — להחלפה הקלידו מפתח חדש</div>
                            @else
                                <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">לא נשמר מפתח</div>
                            @endif
                        </div>
                        <div class="full" style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                            <button type="submit" class="btn btn-primary">שמירה</button>
                            <button type="button" wire:click="testSumitCompany({{ $entityId }})" class="btn btn-secondary">בדיקת חיבור</button>
                        </div>
                        @error("sumitConnection.{$entityId}") <div class="full" style="color: var(--color-error); font-size: var(--fs-caption);">{{ $message }}</div> @enderror
                    </form>
                @endforeach
                <form wire:submit="saveSummitSettings" class="form-grid">
                    <div class="full">
                        <span class="checkbox-row"><input type="checkbox" id="summitTestMode" wire:model="summitTestMode"><label for="summitTestMode" style="margin:0">מצב בדיקה (לכל החברות) — מסמכים יופקו ב-SUMIT כטיוטה, וחיובי אשראי יבוצעו כבדיקת אישור בלבד, ללא חיוב בפועל</label></span>
                    </div>
                    <div class="full"><button type="submit" class="btn btn-secondary">שמירת מצב בדיקה</button></div>
                </form>
            </div>
        </div>
    </section>
    @endif
</div>
