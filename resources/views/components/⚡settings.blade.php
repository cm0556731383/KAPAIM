<?php

use App\Console\Commands\ProcessMaterialReminders;
use App\Models\BusinessEntity;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateField;
use App\Models\ExternalIntegrationSetting;
use App\Models\LeadSource;
use App\Models\PaymentMethod;
use App\Models\StatusDefinition;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.app', ['title' => 'הגדרות מערכת — כפיים'])]
class extends Component
{
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

    public string $summitBaseUrl = '';
    public string $summitApiKey = '';
    public string $summitWebhookSecret = '';

    public string $landingPageWebhookSecret = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $smove = ExternalIntegrationSetting::where('system', 'smove')->first()?->settings ?? [];
        $this->smoveApiKey = (string) ($smove['api_key'] ?? '');
        $this->smoveWebhookSecret = (string) ($smove['webhook_secret'] ?? '');
        $this->smoveMaterialReminderHours = (string) ($smove['material_reminder_hours'] ?? ProcessMaterialReminders::DEFAULT_REMINDER_HOURS);

        $summit = ExternalIntegrationSetting::where('system', 'summit')->first()?->settings ?? [];
        $this->summitBaseUrl = (string) ($summit['base_url'] ?? '');
        $this->summitApiKey = (string) ($summit['api_key'] ?? '');
        $this->summitWebhookSecret = (string) ($summit['webhook_secret'] ?? '');

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
    public function saveSmoveSettings(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'smoveApiKey' => ['nullable', 'string', 'max:255'],
            'smoveWebhookSecret' => ['nullable', 'string', 'max:255'],
            'smoveMaterialReminderHours' => ['required', 'integer', 'min:1'],
        ]);

        ExternalIntegrationSetting::firstOrCreate(['system' => 'smove'], ['is_active' => false, 'settings' => []])->update(['settings' => [
            'api_key' => $data['smoveApiKey'] ?: null,
            'webhook_secret' => $data['smoveWebhookSecret'] ?: null,
            'material_reminder_hours' => (int) $data['smoveMaterialReminderHours'],
        ]]);

        $activityLogger->log('external_integration_setting.updated', 'עודכנו הגדרות חיבור Smove');
        unset($this->integrations);
    }

    public function saveSummitSettings(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'summitBaseUrl' => ['nullable', 'url', 'max:255'],
            'summitApiKey' => ['nullable', 'string', 'max:255'],
            'summitWebhookSecret' => ['nullable', 'string', 'max:255'],
        ], [], ['summitBaseUrl' => 'כתובת שרת']);

        ExternalIntegrationSetting::firstOrCreate(['system' => 'summit'], ['is_active' => false, 'settings' => []])->update(['settings' => [
            'base_url' => $data['summitBaseUrl'] ?: null,
            'api_key' => $data['summitApiKey'] ?: null,
            'webhook_secret' => $data['summitWebhookSecret'] ?: null,
        ]]);

        $activityLogger->log('external_integration_setting.updated', 'עודכנו הגדרות חיבור Summit');
        unset($this->integrations);
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
            'paymentMethodType' => ['required', 'string', 'max:255'],
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
            'emailTemplateType' => ['required', 'string', 'max:255'],
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
            'fieldLinkedField' => ['required_if:fieldType,linked', 'nullable', 'string', 'max:255'],
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

        $label = $record->name ?? $record->system ?? "#{$record->id}";
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
                                <td><button type="button" wire:click="toggleStatus({{ $status->id }})" class="btn btn-ghost">{{ $status->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
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
                        <tr>
                            <td>{{ $source->name }}</td>
                            <td>
                                @if ($source->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="toggleLeadSource({{ $source->id }})" class="btn btn-ghost">{{ $source->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
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
                        <tr>
                            <td>{{ $method->name }}</td>
                            <td>{{ $method->type }}</td>
                            <td>
                                @if ($method->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="togglePaymentMethod({{ $method->id }})" class="btn btn-ghost">{{ $method->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
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
                    <input type="text" id="paymentMethodType" wire:model="paymentMethodType" placeholder="למשל: recurring">
                    @error('paymentMethodType') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">הוספת אמצעי תשלום</button></div>
            </form>
        </div>
    </section>

    {{-- ===== עוסקים ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>עוסקים</h2>
            <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">נבחר בכל הפקת חשבונית</p>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>שם</th><th>סיווג</th><th>ח.פ / ע.מ</th><th>אימייל</th><th>סטטוס</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->businessEntities as $entity)
                        <tr>
                            <td>{{ $entity->name }}</td>
                            <td><span class="badge badge-info">{{ $entity->classification }}</span></td>
                            <td class="ltr-num">{{ $entity->company_number }}</td>
                            <td class="ltr-num" style="direction:ltr; text-align:right">{{ $entity->email }}</td>
                            <td>
                                @if ($entity->is_active)
                                    <span class="badge badge-success">פעיל</span>
                                @else
                                    <span class="badge badge-neutral">מושבת</span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="toggleBusinessEntity({{ $entity->id }})" class="btn btn-ghost">{{ $entity->is_active ? 'השבתה' : 'הפעלה' }}</button></td>
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

    {{-- ===== תבניות דואר ===== --}}
    <section class="settings-section">
        <div class="section-head">
            <h2>תבניות דואר</h2>
            <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">מיילים תפעוליים שהמערכת שולחת ישירות (SMTP) — לא מסמכים עסקיים ולא דיוור רשימות תפוצה (תמיד דרך Smove)</p>
        </div>

        @foreach ($this->emailTemplates as $template)
            <div class="card" style="margin-bottom: var(--sp-md)">
                <div style="display:flex; justify-content:space-between; align-items:flex-start">
                    <div>
                        <h3 style="margin-bottom:2px">{{ $template->name }}</h3>
                        <p class="text-text-secondary" style="margin:0; font-size:var(--fs-caption)">{{ $template->template_type }} · {{ $template->subject }}</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px">
                        @if ($template->is_active)
                            <span class="badge badge-success">פעיל</span>
                        @else
                            <span class="badge badge-neutral">מושבת</span>
                        @endif
                        <button type="button" wire:click="toggleEmailTemplate({{ $template->id }})" class="btn btn-ghost">{{ $template->is_active ? 'השבתה' : 'הפעלה' }}</button>
                    </div>
                </div>

                <div class="table-scroll">
                <table style="margin-top: var(--sp-md)">
                    <thead><tr><th>שדה מיזוג</th><th>סוג</th><th>שדה מקושר</th><th>חובה</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($template->fields as $field)
                            <tr>
                                <td>{{ $field->name }}</td>
                                <td><span class="scope-pill">{{ $field->field_type === 'linked' ? 'מקושר' : 'טקסט חופשי' }}</span></td>
                                <td>{{ $field->linked_field ?? '—' }}</td>
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
                        <label for="emailTemplateType">סוג (מזהה טכני)</label>
                        <input type="text" id="emailTemplateType" wire:model="emailTemplateType" class="ltr-num" dir="ltr" placeholder="lead_confirmation">
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
                            <input type="text" id="fieldLinkedField" wire:model="fieldLinkedField" class="ltr-num" dir="ltr" placeholder="school.name">
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
                            <td class="ltr-num" style="direction:ltr; text-align:right">{{ $integration->system }}</td>
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
                        <label for="smoveMaterialReminderHours">כמה שעות לחכות לפני תזכורת על חומרי לימוד שלא נפתחו</label>
                        <input type="text" id="smoveMaterialReminderHours" wire:model="smoveMaterialReminderHours" class="ltr-num" dir="ltr">
                        @error('smoveMaterialReminderHours') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">שמירת הגדרות Smove</button></div>
                </form>
            </div>

            <div class="card">
                <h3>Summit — כתובת שרת ומפתח</h3>
                <form wire:submit="saveSummitSettings" class="form-grid">
                    <div class="full">
                        <label for="summitBaseUrl">כתובת שרת (Base URL)</label>
                        <input type="text" id="summitBaseUrl" wire:model="summitBaseUrl" class="ltr-num" dir="ltr" placeholder="https://api.summit.co.il">
                        @error('summitBaseUrl') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <label for="summitApiKey">מפתח API</label>
                        <input type="password" id="summitApiKey" wire:model="summitApiKey" class="ltr-num" dir="ltr">
                    </div>
                    <div class="full">
                        <label for="summitWebhookSecret">סוד Webhook (לגבייה אוטומטית בהוראת קבע)</label>
                        <input type="password" id="summitWebhookSecret" wire:model="summitWebhookSecret" class="ltr-num" dir="ltr">
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">שמירת הגדרות Summit</button></div>
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
</div>
