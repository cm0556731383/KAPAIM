<?php

use App\Concerns\Notifies;
use App\Concerns\PreviewsEmails;
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
use Livewire\WithFileUploads;

new
#[Layout('layouts.app', ['title' => 'הגדרות מערכת — כפיים'])]
class extends Component
{
    use Notifies;
    use PreviewsEmails;
    use WithFileUploads;

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
    /** The template open in the editor — null when none is chosen, 0 for a new one not saved yet. */
    public ?int $selectedEmailTemplateId = null;
    public string $emailTemplateName = '';
    public string $emailTemplateType = 'general';
    public string $emailTemplateSubject = '';
    public string $emailTemplateContent = '';
    /** EmailTemplate::FORMAT_TEXT / FORMAT_HTML — HTML only for EmailTemplate::HTML_TYPES. */
    public string $emailTemplateFormat = 'text';
    /** An uploaded HTML file — read into $emailTemplateContent as soon as it arrives. */
    public $emailTemplateHtmlFile = null;

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

    /**
     * What's already saved for each secret, as its last 4 characters only —
     * the secrets themselves never reach the page (anyone who may view the
     * settings would otherwise read them in the page source). A blank field
     * on save keeps the saved value, same as the SUMIT keys.
     */
    #[\Livewire\Attributes\Locked]
    public array $savedSecretHints = [];

    /** Reference-data row being edited inline: "status:{id}", "lead_source:{id}", … */
    public ?string $editingRecord = null;

    /** The edited row's fields, keyed like its columns. */
    public array $editForm = [];

    /** Which settings tab is shown — kept in the URL (?tab=) so a reload stays on it. */
    #[Url(as: 'tab')]
    public string $activeTab = 'subscriptions';

    // ===== מנויים =====
    public string $subscriptionStartMonth = '';

    // ===== גיבוי שבועי (App\Services\WeeklyBackup) =====
    public string $backupPassword = '';

    public string $backupPassword_confirmation = '';

    public string $backupUserId = '';

    // ===== יבוא נתונים (App\Services\CustomerImport) =====
    /** The filled customers file, just uploaded. */
    public $importFile = null;

    /** Where the checked file waits on disk for the import (storage/app/imports). */
    #[\Livewire\Attributes\Locked]
    public ?string $importPath = null;

    /** The check's outcome: fileError, total, valid, errors (row, school, column, message). */
    #[\Livewire\Attributes\Locked]
    public array $importCheck = [];

    /** The import running in the background (App\Jobs\ImportCustomers), once started. */
    #[\Livewire\Attributes\Locked]
    public ?string $importId = null;

    /** The user approved opening the contacts the check didn't find on the Smove list. */
    public bool $importApproveUnlisted = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.view'), 403);

        $this->subscriptionStartMonth = (string) HebrewCalendar::startMonth();
        $this->backupUserId = (string) (app(\App\Services\WeeklyBackup::class)->recipient()?->id ?? '');

        $smove = ExternalIntegrationSetting::where('system', 'smove')->first()?->settings ?? [];
        $this->savedSecretHints['smove_api_key'] = self::secretHint($smove['api_key'] ?? null);
        $this->savedSecretHints['smove_webhook_secret'] = self::secretHint($smove['webhook_secret'] ?? null);
        $this->smoveMaterialReminderHours = (string) ($smove['material_reminder_hours'] ?? ProcessMaterialReminders::DEFAULT_REMINDER_HOURS);
        $this->smoveListId = (string) ($smove['list_id'] ?? '');

        $summit = ExternalIntegrationSetting::where('system', 'summit')->first()?->settings ?? [];
        // Test mode defaults ON until the business owner explicitly turns it off.
        $this->summitTestMode = (bool) ($summit['test_mode'] ?? true);
        $this->loadSumitCompanies();

        $landingPage = ExternalIntegrationSetting::where('system', 'landing_page')->first()?->settings ?? [];
        $this->savedSecretHints['landing_page_webhook_secret'] = self::secretHint($landingPage['webhook_secret'] ?? null);
    }

    private static function secretHint(?string $secret): string
    {
        return filled($secret) ? '••••••••'.mb_substr(trim($secret), -4) : '';
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
    // ----- יבוא נתונים -----

    /** Reads the uploaded file and checks every row — nothing is saved yet. */
    public function checkImport(\App\Services\CustomerImport $import): void
    {
        $this->validate(['importFile' => ['required', 'file', 'max:10240', 'mimes:xlsx']], [
            'importFile.required' => 'יש לבחור קובץ.',
            'importFile.mimes' => 'יש להעלות קובץ אקסל (xlsx).',
            'importFile.max' => 'הקובץ גדול מדי (עד 10MB).',
        ]);

        $this->discardImportFile();
        $this->importId = null;

        $path = $this->importFile->store('imports', 'local');
        $this->importFile = null;

        $this->analyzeImport($import, $path);
    }

    /**
     * Fixed values typed into the "פרטים שגויים" table, by sheet row and
     * column key — prefilled with what the file had (analyzeImport()).
     */
    public array $importFixes = [];

    /** Writes the fixes typed on the check screen into the stored file and checks it again. */
    public function fixImport(\App\Services\CustomerImport $import): void
    {
        if (! $this->importPath || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->importPath)) {
            $this->notifyError('הקובץ כבר אינו זמין — יש להעלות אותו שוב.');
            $this->resetImport();

            return;
        }

        // Only the rows and fields the check flagged can be changed here.
        $allowed = collect($this->importCheck['groups']['invalid'] ?? [])
            ->mapWithKeys(fn ($row) => [$row['row'] => array_keys($row['fields'])]);
        $fixes = [];

        foreach ($this->importFixes as $row => $values) {
            foreach ((array) $values as $key => $value) {
                if (in_array($key, $allowed[$row] ?? [], true) && is_scalar($value ?? '')) {
                    $fixes[$row][$key] = (string) $value;
                }
            }
        }

        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($this->importPath);

        try {
            $import->applyFixes($path, $fixes);
        } catch (\Throwable $e) {
            report($e);
            $this->notifyError('לא ניתן לשמור את התיקונים בקובץ. יש לתקן בקובץ עצמו ולהעלות אותו שוב.');

            return;
        }

        $this->analyzeImport($import, $this->importPath);

        $left = count($this->importCheck['groups']['invalid'] ?? []);
        $this->notifySuccess($left ? "התיקונים נשמרו. נשארו {$left} שורות עם שגיאות." : 'התיקונים נשמרו — כל השורות תקינות.');
    }

    /** Checks the stored file and lays out the results for the screen (the three groups). */
    private function analyzeImport(\App\Services\CustomerImport $import, string $path): void
    {
        $result = $import->analyze(\Illuminate\Support\Facades\Storage::disk('local')->path($path));
        $columns = \App\Services\CustomerImport::columns();
        $keyOf = collect($columns)->mapWithKeys(fn ($column, $key) => [$column[0] => $key])->all();

        $errors = [];
        $warnings = [];
        $unlisted = [];
        $groups = ['linked' => [], 'unlisted' => [], 'invalid' => []];
        $fixes = [];

        foreach ($result['rows'] as $row) {
            foreach ($row['errors'] as $column => $message) {
                $errors[] = ['row' => $row['row'], 'school' => $row['school'], 'column' => $column, 'message' => $message];
            }

            // Contacts whose email another customer (or another row) already uses — imported, but shown.
            foreach ($row['warnings'] ?? [] as $column => $message) {
                $warnings[] = ['row' => $row['row'], 'school' => $row['school'], 'column' => $column, 'message' => $message];
            }

            // Contacts not on the Smove list — imported only once approved (importApproveUnlisted).
            foreach ($row['unlisted'] ?? [] as $column => $contact) {
                $unlisted[] = ['row' => $row['row'], 'school' => $row['school'], 'column' => $column] + $contact;
            }

            if ($row['data'] === null) {
                $fields = [];

                foreach ($row['errors'] as $column => $message) {
                    $key = $keyOf[$column] ?? null;

                    if ($key) {
                        $fields[$key] = ['label' => $column, 'message' => $message, 'value' => $row['values'][$key] ?? ''];
                        $fixes[$row['row']][$key] = $row['values'][$key] ?? '';
                    }
                }

                $groups['invalid'][] = ['row' => $row['row'], 'school' => $row['school'], 'fields' => $fields];

                continue;
            }

            $unlistedEmails = collect($row['unlisted'] ?? [])->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
            $contacts = array_map(fn ($c) => [
                'name' => $c['name'],
                'email' => $c['email'],
                'primary' => $c['is_primary'],
                'accounting' => $c['is_accounting_contact'],
                'unlisted' => $c['email'] !== null && in_array(mb_strtolower(trim($c['email'])), $unlistedEmails, true),
            ], $row['data']['contacts']);

            $groups[$unlistedEmails ? 'unlisted' : 'linked'][] = ['row' => $row['row'], 'school' => $row['school'], 'contacts' => $contacts];
        }

        $valid = count(array_filter($result['rows'], fn ($row) => $row['data'] !== null));

        $this->importApproveUnlisted = false;
        $this->importFixes = $fixes;

        $this->importCheck = [
            'unlisted' => $unlisted,
            'fileError' => $result['fileError'],
            'total' => count($result['rows']),
            'valid' => $valid,
            'invalidRows' => count($result['rows']) - $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'groups' => $groups,
            // How many contacts will be linked to the Smove list, and how many aren't on it.
            'smove' => $valid > 0 ? $import->smovePreview($result['rows'], $result['smove']) : null,
        ];

        // The file is kept while there's anything to import or to fix on screen.
        if ($result['fileError']) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            $this->importPath = null;
        } else {
            $this->importPath = $path;
        }
    }

    /** Imports the rows that passed the check, in the background. */
    public function runImport(): void
    {
        if (! auth()->user()->hasPermission('settings', 'edit')) {
            $this->notifyError('אין לך הרשאת עריכה בהגדרות — לא ניתן לייבא.');

            return;
        }

        if (! $this->importPath || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->importPath)) {
            $this->notifyError('הקובץ כבר אינו זמין — יש להעלות אותו שוב.');
            $this->resetImport();

            return;
        }

        if (($this->importCheck['unlisted'] ?? []) && ! $this->importApproveUnlisted) {
            $this->notifyError('יש לאשר את פתיחת אנשי הקשר שלא נמצאו ברשימת התפוצה ב־Smove לפני היבוא.');

            return;
        }

        $this->importId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\ImportCustomers::cacheKey($this->importId), ['state' => 'queued', 'total' => $this->importCheck['valid'] ?? 0, 'done' => 0], now()->addDay());

        \App\Jobs\ImportCustomers::dispatch($this->importId, $this->importPath, auth()->id(), $this->importApproveUnlisted);

        // The job owns the file now and deletes it when done.
        $this->importPath = null;
    }

    public function resetImport(): void
    {
        $this->discardImportFile();
        $this->reset(['importFile', 'importCheck', 'importId', 'importApproveUnlisted', 'importFixes']);
    }

    private function discardImportFile(): void
    {
        if ($this->importPath) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($this->importPath);
            $this->importPath = null;
        }
    }

    #[Computed]
    public function importStatus(): ?array
    {
        return $this->importId ? \App\Jobs\ImportCustomers::status($this->importId) : null;
    }

    #[Computed]
    public function importColumns(): array
    {
        return \App\Services\CustomerImport::columns();
    }

    // ----- יבוא תוכניות ומארזים (App\Services\CatalogImport) -----

    /** What the import tab imports: customers / catalog (programs + bundles). Kept in the URL. */
    #[Url(as: 'import')]
    public string $importKind = 'customers';

    public $catalogFile = null;

    #[\Livewire\Attributes\Locked]
    public ?string $catalogPath = null;

    /** The check's outcome: fileError, total, valid, invalidRows, valid rows, invalid rows (with their fields). */
    #[\Livewire\Attributes\Locked]
    public array $catalogCheck = [];

    /** Values typed into the "שגויות" table: [sheet => [row => [column => value]]]. */
    public array $catalogFixes = [];

    /** The finished import: programs / bundles created. */
    #[\Livewire\Attributes\Locked]
    public array $catalogResult = [];

    public function checkCatalogImport(\App\Services\CatalogImport $import): void
    {
        $this->validate(['catalogFile' => ['required', 'file', 'max:10240', 'mimes:xlsx']], [
            'catalogFile.required' => 'יש לבחור קובץ.',
            'catalogFile.mimes' => 'יש להעלות קובץ אקסל (xlsx).',
            'catalogFile.max' => 'הקובץ גדול מדי (עד 10MB).',
        ]);

        $this->discardCatalogFile();
        $this->catalogResult = [];

        $path = $this->catalogFile->store('imports', 'local');
        $this->catalogFile = null;

        $this->analyzeCatalog($import, $path);
    }

    public function fixCatalogImport(\App\Services\CatalogImport $import): void
    {
        if (! $this->catalogPath || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->catalogPath)) {
            $this->notifyError('הקובץ כבר אינו זמין — יש להעלות אותו שוב.');
            $this->resetCatalogImport();

            return;
        }

        // Only the rows and fields the check flagged can be changed here.
        $allowed = [];

        foreach ($this->catalogCheck['invalid'] ?? [] as $row) {
            $allowed[$row['sheet']][$row['row']] = array_keys($row['fields']);
        }

        $fixes = [];

        foreach ($this->catalogFixes as $sheet => $rows) {
            foreach ((array) $rows as $row => $values) {
                foreach ((array) $values as $key => $value) {
                    if (in_array($key, $allowed[$sheet][$row] ?? [], true) && is_scalar($value ?? '')) {
                        $fixes[$sheet][$row][$key] = (string) $value;
                    }
                }
            }
        }

        try {
            $import->applyFixes(\Illuminate\Support\Facades\Storage::disk('local')->path($this->catalogPath), $fixes);
        } catch (\Throwable $e) {
            report($e);
            $this->notifyError('לא ניתן לשמור את התיקונים בקובץ. יש לתקן בקובץ עצמו ולהעלות אותו שוב.');

            return;
        }

        $this->analyzeCatalog($import, $this->catalogPath);

        $left = count($this->catalogCheck['invalid'] ?? []);
        $this->notifySuccess($left ? "התיקונים נשמרו. נשארו {$left} שורות עם שגיאות." : 'התיקונים נשמרו — כל השורות תקינות.');
    }

    private function analyzeCatalog(\App\Services\CatalogImport $import, string $path): void
    {
        $result = $import->analyze(\Illuminate\Support\Facades\Storage::disk('local')->path($path));
        $valid = [];
        $invalid = [];
        $fixes = [];

        foreach ($result['rows'] as $row) {
            if ($row['data'] !== null) {
                $valid[] = ['sheet' => $row['sheet'], 'row' => $row['row'], 'name' => $row['name'], 'data' => $row['data']];

                continue;
            }

            $columns = \App\Services\CatalogImport::columns($row['sheet']);
            $fields = [];

            foreach ($row['errors'] as $key => $message) {
                $fields[$key] = ['label' => $columns[$key][0], 'message' => $message];
                $fixes[$row['sheet']][$row['row']][$key] = $row['values'][$key] ?? '';
            }

            $invalid[] = ['sheet' => $row['sheet'], 'row' => $row['row'], 'name' => $row['name'], 'fields' => $fields];
        }

        $this->catalogFixes = $fixes;
        $this->catalogCheck = [
            'fileError' => $result['fileError'],
            'total' => count($result['rows']),
            'valid' => $valid,
            'invalid' => $invalid,
        ];

        if ($result['fileError']) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            $this->catalogPath = null;
        } else {
            $this->catalogPath = $path;
        }
    }

    /** Imports the rows that passed the check — checked again from the file first. */
    public function runCatalogImport(\App\Services\CatalogImport $import, ActivityLogger $activityLogger): void
    {
        if (! auth()->user()->hasPermission('settings', 'edit') || ! auth()->user()->hasPermission('catalog', 'edit')) {
            $this->notifyError('ליבוא נדרשת הרשאת עריכה בהגדרות ובקטלוג התוכניות.');

            return;
        }

        if (! $this->catalogPath || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->catalogPath)) {
            $this->notifyError('הקובץ כבר אינו זמין — יש להעלות אותו שוב.');
            $this->resetCatalogImport();

            return;
        }

        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($this->catalogPath);
        $analysis = $import->analyze($path);

        if ($analysis['fileError']) {
            $this->notifyError('היבוא לא בוצע: '.$analysis['fileError']);

            return;
        }

        try {
            $result = $import->import($analysis['rows'], auth()->user(), $activityLogger);
        } catch (\Throwable $e) {
            report($e);
            $this->notifyError($e instanceof \RuntimeException ? $e->getMessage() : 'היבוא נכשל בגלל שגיאה לא צפויה — שום דבר לא נשמר.');

            return;
        }

        $skipped = count(array_filter($analysis['rows'], fn ($row) => $row['data'] === null));

        $this->discardCatalogFile();
        $this->reset(['catalogFile', 'catalogCheck', 'catalogFixes']);
        $this->catalogResult = $result + ['skipped' => $skipped];
    }

    public function resetCatalogImport(): void
    {
        $this->discardCatalogFile();
        $this->reset(['catalogFile', 'catalogCheck', 'catalogFixes', 'catalogResult']);
    }

    // ----- יבוא עסקאות (App\Services\DealImport) -----

    public $dealFile = null;

    #[\Livewire\Attributes\Locked]
    public ?string $dealPath = null;

    /** The check's outcome: fileError, total, valid rows, invalid rows (with their fields). */
    #[\Livewire\Attributes\Locked]
    public array $dealCheck = [];

    /** Values typed into the "שגויות" table: [row => [column => value]]. */
    public array $dealFixes = [];

    /** The import running in the background (App\Jobs\ImportDeals). */
    #[\Livewire\Attributes\Locked]
    public ?string $dealImportId = null;

    private function canImportDeals(): bool
    {
        return auth()->user()->hasPermission('settings', 'edit') && auth()->user()->hasPermission('customers', 'edit');
    }

    public function checkDealImport(\App\Services\DealImport $import): void
    {
        $this->validate(['dealFile' => ['required', 'file', 'max:10240', 'mimes:xlsx']], [
            'dealFile.required' => 'יש לבחור קובץ.',
            'dealFile.mimes' => 'יש להעלות קובץ אקסל (xlsx).',
            'dealFile.max' => 'הקובץ גדול מדי (עד 10MB).',
        ]);

        $this->discardDealFile();
        $this->dealImportId = null;

        $path = $this->dealFile->store('imports', 'local');
        $this->dealFile = null;

        $this->analyzeDeals($import, $path);
    }

    public function fixDealImport(\App\Services\DealImport $import): void
    {
        if (! $this->dealPath || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->dealPath)) {
            $this->notifyError('הקובץ כבר אינו זמין — יש להעלות אותו שוב.');
            $this->resetDealImport();

            return;
        }

        // Only the rows and fields the check flagged can be changed here.
        $allowed = collect($this->dealCheck['invalid'] ?? [])->mapWithKeys(fn ($row) => [$row['row'] => array_keys($row['fields'])]);
        $fixes = [];

        foreach ($this->dealFixes as $row => $values) {
            foreach ((array) $values as $key => $value) {
                if (in_array($key, $allowed[$row] ?? [], true) && is_scalar($value ?? '')) {
                    $fixes[$row][$key] = (string) $value;
                }
            }
        }

        try {
            $import->applyFixes(\Illuminate\Support\Facades\Storage::disk('local')->path($this->dealPath), $fixes);
        } catch (\Throwable $e) {
            report($e);
            $this->notifyError('לא ניתן לשמור את התיקונים בקובץ. יש לתקן בקובץ עצמו ולהעלות אותו שוב.');

            return;
        }

        $this->analyzeDeals($import, $this->dealPath);

        $left = count($this->dealCheck['invalid'] ?? []);
        $this->notifySuccess($left ? "התיקונים נשמרו. נשארו {$left} שורות עם שגיאות." : 'התיקונים נשמרו — כל השורות תקינות.');
    }

    private function analyzeDeals(\App\Services\DealImport $import, string $path): void
    {
        $result = $import->analyze(\Illuminate\Support\Facades\Storage::disk('local')->path($path));
        $columns = \App\Services\DealImport::columns();
        $valid = [];
        $invalid = [];
        $fixes = [];

        foreach ($result['rows'] as $row) {
            if ($row['data'] !== null) {
                $valid[] = ['row' => $row['row'], 'data' => $row['data']];

                continue;
            }

            $fields = [];

            // An item's error shows its amount too, and an amount's error its item — they're fixed together.
            $keys = array_keys($row['errors']);

            foreach ($row['errors'] as $key => $message) {
                if (preg_match('/^(item|amount)(\d+)$/', $key, $m)) {
                    $keys[] = "item{$m[2]}";
                    $keys[] = "amount{$m[2]}";
                }

                // A document's state and date (and the invoice's number) are fixed together too.
                if (preg_match('/^(quote|order_form|contract|invoice)_(state|date|number)$/', $key, $m)) {
                    $keys[] = "{$m[1]}_state";
                    $keys[] = "{$m[1]}_date";

                    if ($m[1] === 'invoice') {
                        $keys[] = 'invoice_number';
                    }
                }

                // A payment's four fields too — and the invoice's, when the payment lacks one.
                if (preg_match('/^pay(\d+)_/', $key, $m)) {
                    array_push($keys, "pay{$m[1]}_method", "pay{$m[1]}_amount", "pay{$m[1]}_date", "pay{$m[1]}_receipt");

                    if (($row['values']['invoice_state'] ?? '') === '') {
                        array_push($keys, 'invoice_state', 'invoice_date', 'invoice_number');
                    }
                }
            }

            foreach (array_keys($columns) as $key) {
                if (in_array($key, $keys, true)) {
                    $fields[$key] = ['label' => $columns[$key][0], 'message' => $row['errors'][$key] ?? null];
                    $fixes[$row['row']][$key] = $row['values'][$key] ?? '';
                }
            }

            $invalid[] = ['row' => $row['row'], 'customer' => $row['customer'], 'fields' => $fields];
        }

        $this->dealFixes = $fixes;
        $this->dealCheck = [
            'fileError' => $result['fileError'],
            'total' => count($result['rows']),
            'valid' => $valid,
            'invalid' => $invalid,
        ];

        if ($result['fileError']) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            $this->dealPath = null;
        } else {
            $this->dealPath = $path;
        }
    }

    public function runDealImport(): void
    {
        if (! $this->canImportDeals()) {
            $this->notifyError('ליבוא עסקאות נדרשת הרשאת עריכה בהגדרות ובלקוחות.');

            return;
        }

        if (! $this->dealPath || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->dealPath)) {
            $this->notifyError('הקובץ כבר אינו זמין — יש להעלות אותו שוב.');
            $this->resetDealImport();

            return;
        }

        $this->dealImportId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\ImportDeals::cacheKey($this->dealImportId), ['state' => 'queued', 'total' => count($this->dealCheck['valid'] ?? []), 'done' => 0], now()->addDay());

        \App\Jobs\ImportDeals::dispatch($this->dealImportId, $this->dealPath, auth()->id());

        // The job owns the file now and deletes it when done.
        $this->dealPath = null;
    }

    public function resetDealImport(): void
    {
        $this->discardDealFile();
        $this->reset(['dealFile', 'dealCheck', 'dealFixes', 'dealImportId']);
    }

    private function discardDealFile(): void
    {
        if ($this->dealPath) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($this->dealPath);
            $this->dealPath = null;
        }
    }

    #[Computed]
    public function dealImportStatus(): ?array
    {
        return $this->dealImportId ? \App\Jobs\ImportDeals::status($this->dealImportId) : null;
    }

    private function discardCatalogFile(): void
    {
        if ($this->catalogPath) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($this->catalogPath);
            $this->catalogPath = null;
        }
    }

    /** HebrewCalendar: the Hebrew month that is month 1 of a newly opened subscription. */
    public function saveSubscriptionSettings(ActivityLogger $activityLogger): void
    {
        $this->validate(['subscriptionStartMonth' => ['required', 'in:'.implode(',', array_keys(HebrewCalendar::MONTHS))]]);

        AppSetting::put(HebrewCalendar::START_MONTH_SETTING, $this->subscriptionStartMonth);

        $activityLogger->log('app_setting.updated', 'חודש תחילת מנוי עודכן ל'.HebrewCalendar::MONTHS[(int) $this->subscriptionStartMonth]);
        $this->notifySuccess('הגדרות המנוי נשמרו.');
    }

    /** Settings → גיבוי: the weekly backup's password and who it downloads to. */
    public function saveWeeklyBackup(ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);
        $backup = app(\App\Services\WeeklyBackup::class);

        $this->validate([
            'backupPassword' => [$backup->hasPassword() ? 'nullable' : 'required', 'string', 'min:'.\App\Services\WeeklyBackup::MIN_PASSWORD_LENGTH, 'max:100', 'confirmed'],
            'backupUserId' => ['required', \Illuminate\Validation\Rule::exists('users', 'id')->where('is_active', true)],
        ], [
            'backupPassword.required' => 'יש לבחור סיסמה לקובץ הגיבוי.',
            'backupPassword.min' => 'הסיסמה צריכה להיות באורך '.\App\Services\WeeklyBackup::MIN_PASSWORD_LENGTH.' תווים לפחות.',
            'backupPassword.confirmed' => 'הסיסמאות אינן זהות.',
            'backupUserId.required' => 'יש לבחור למי יורד הגיבוי.',
        ]);

        if ($this->backupPassword !== '') {
            $backup->setPassword($this->backupPassword);
            $activityLogger->log('app_setting.updated', 'עודכנה סיסמת הגיבוי השבועי');
        }
        $backup->setRecipient((int) $this->backupUserId);
        $activityLogger->log('app_setting.updated', 'הגיבוי השבועי יורד אל '.$backup->recipient()?->name);

        $this->reset('backupPassword', 'backupPassword_confirmation');
        $this->notifySuccess('הגדרות הגיבוי נשמרו.');
    }

    public function saveSmoveSettings(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'smoveApiKey' => ['nullable', 'string', 'max:255'],
            'smoveWebhookSecret' => ['nullable', 'string', 'max:255'],
            'smoveMaterialReminderHours' => ['required', 'integer', 'min:1'],
            'smoveListId' => ['nullable', 'integer', 'min:1'],
        ], [], ['smoveListId' => 'מזהה רשימה ב-Smove']);

        $setting = ExternalIntegrationSetting::firstOrCreate(['system' => 'smove'], ['is_active' => false, 'settings' => []]);
        $saved = $setting->settings ?? [];
        $setting->update(['settings' => [
            'api_key' => filled($data['smoveApiKey']) ? trim($data['smoveApiKey']) : ($saved['api_key'] ?? null),
            'webhook_secret' => filled($data['smoveWebhookSecret']) ? trim($data['smoveWebhookSecret']) : ($saved['webhook_secret'] ?? null),
            'material_reminder_hours' => (int) $data['smoveMaterialReminderHours'],
            'list_id' => $data['smoveListId'] ? (int) $data['smoveListId'] : null,
        ]]);

        $this->savedSecretHints['smove_api_key'] = self::secretHint($setting->settings['api_key']);
        $this->savedSecretHints['smove_webhook_secret'] = self::secretHint($setting->settings['webhook_secret']);
        $this->reset(['smoveApiKey', 'smoveWebhookSecret']);

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

        $setting = ExternalIntegrationSetting::firstOrCreate(['system' => 'landing_page'], ['is_active' => false, 'settings' => []]);

        if (filled($data['landingPageWebhookSecret'])) {
            $setting->update(['settings' => ['webhook_secret' => trim($data['landingPageWebhookSecret'])]]);
        }

        $this->savedSecretHints['landing_page_webhook_secret'] = self::secretHint($setting->settings['webhook_secret'] ?? null);
        $this->reset('landingPageWebhookSecret');

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

    /** Opens a template in the editor below the list. */
    public function selectEmailTemplate(int $id): void
    {
        $this->resetErrorBag();
        $template = EmailTemplate::findOrFail($id);

        $this->selectedEmailTemplateId = $id;
        $this->emailTemplateName = (string) $template->name;
        $this->emailTemplateType = $template->template_type;
        $this->emailTemplateSubject = (string) $template->subject;
        $this->emailTemplateContent = (string) $template->content;
        $this->emailTemplateFormat = $template->isHtml() ? EmailTemplate::FORMAT_HTML : EmailTemplate::FORMAT_TEXT;
        $this->emailTemplateHtmlFile = null;
    }

    /** "+ תבנית חדשה" — an empty general template in the editor, created on "שמירה". */
    public function newEmailTemplate(): void
    {
        $this->closeEmailTemplate();
        $this->selectedEmailTemplateId = 0;
    }

    public function closeEmailTemplate(): void
    {
        $this->resetErrorBag();
        $this->reset(['selectedEmailTemplateId', 'emailTemplateName', 'emailTemplateType', 'emailTemplateSubject', 'emailTemplateContent', 'emailTemplateFormat', 'emailTemplateHtmlFile']);
    }

    /** The uploaded HTML file becomes the template's content (still editable as code before saving). */
    public function updatedEmailTemplateHtmlFile(): void
    {
        $this->validate(['emailTemplateHtmlFile' => ['required', 'file', 'max:2048', 'mimes:html,htm']], [], ['emailTemplateHtmlFile' => 'קובץ HTML']);

        $this->emailTemplateContent = (string) file_get_contents($this->emailTemplateHtmlFile->getRealPath());
        $this->emailTemplateFormat = EmailTemplate::FORMAT_HTML;
        $this->emailTemplateHtmlFile = null;
    }

    /**
     * Saves the open template. An email the code looks up by type (the
     * supplier-invoice requests, the materials reminder —
     * EmailTemplate::FIXED_TYPES) keeps it; the others may switch between
     * TYPE_LABELS' uses.
     */
    public function saveEmailTemplate(ActivityLogger $activityLogger): void
    {
        // A new template is always a general one — the automatic emails already exist.
        $template = $this->selectedEmailTemplateId === 0
            ? new EmailTemplate(['template_type' => EmailTemplate::GENERAL, 'is_active' => true])
            : EmailTemplate::findOrFail($this->selectedEmailTemplateId);
        $isFixed = in_array($template->template_type, EmailTemplate::FIXED_TYPES, true);
        $type = $template->exists && ! $isFixed ? $this->emailTemplateType : $template->template_type;

        $data = $this->validate([
            'emailTemplateName' => ['required', 'string', 'max:255'],
            'emailTemplateType' => $template->exists && ! $isFixed ? ['required', 'in:'.implode(',', array_unique([...array_keys(EmailTemplate::TYPE_LABELS), (string) $template->template_type]))] : ['nullable'],
            'emailTemplateSubject' => ['required', 'string', 'max:255'],
            'emailTemplateContent' => ['required', 'string'],
            'emailTemplateFormat' => ['required', in_array($type, EmailTemplate::HTML_TYPES, true) ? 'in:text,html' : 'in:text'],
        ], ['emailTemplateFormat.in' => 'תבנית HTML יכולה לשמש רק כתבנית כללית או כמייל שליחת החומרים.'], [
            'emailTemplateName' => 'שם התבנית',
            'emailTemplateType' => 'שימוש',
            'emailTemplateSubject' => 'נושא המייל',
            'emailTemplateContent' => 'גוף המייל',
            'emailTemplateFormat' => 'סוג התוכן',
        ]);

        if ($data['emailTemplateFormat'] === EmailTemplate::FORMAT_HTML && trim(strip_tags($data['emailTemplateContent'])) === '' && ! str_contains($data['emailTemplateContent'], '<img')) {
            $this->addError('emailTemplateContent', 'קוד ה-HTML ריק.');

            return;
        }

        $isNew = ! $template->exists;
        $template->fill([
            'name' => $data['emailTemplateName'],
            'subject' => $data['emailTemplateSubject'],
            'content' => $data['emailTemplateContent'],
            'content_format' => $data['emailTemplateFormat'],
            'template_type' => $type,
        ])->save();
        $activityLogger->log($isNew ? 'email_template.created' : 'email_template.updated', ($isNew ? 'נוצרה תבנית המייל' : 'עודכן נוסח המייל')." \"{$template->name}\"");

        $this->selectedEmailTemplateId = $template->id;
        unset($this->emailTemplates);
        $this->notifySuccess($isNew ? 'התבנית נוצרה.' : 'נוסח המייל נשמר.');
    }

    /** Only a general template can be deleted — the others are the emails the system sends. Asks first. */
    public function deleteEmailTemplate(int $id, ActivityLogger $activityLogger, bool $confirmed = false): void
    {
        $template = EmailTemplate::findOrFail($id);
        abort_unless($template->template_type === EmailTemplate::GENERAL, 403);

        if (! $confirmed) {
            $question = "למחוק את התבנית \"{$template->name}\"? מיילים שכבר נשלחו בה לא יושפעו.";
            $this->js('confirmDialog('.json_encode($question, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).").then((ok) => ok && \$wire.deleteEmailTemplate({$id}, true))");

            return;
        }

        $template->delete();
        $activityLogger->log('email_template.deleted', "נמחקה תבנית המייל \"{$template->name}\"");

        $this->closeEmailTemplate();
        unset($this->emailTemplates);
        $this->notifySuccess('התבנית נמחקה.');
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
                'intro' => 'המייל שנשלח בלחיצה על "שליחת חומרים" בכרטיס הלקוחה, לכל הנמענים שנבחרו. מתחת לנוסח יופיעו תמיד שני כפתורים: "להורדת החומרים" (כל הקבצים שצורפו בבת אחת) ו"קיבלתי, תודה" (מסמן את המשלוח כהתקבל ומבטל את התזכורת) — בצבע הבולט של קוד ה-HTML, או בכחול של כפיים.',
                'fields' => ['שם_בית_הספר' => 'שם בית הספר', 'תוכנית' => 'תוכנית'],
                'blocks' => [],
                'example' => ['school_name' => 'בית ספר אורות', 'program_name' => 'אלול תשפ"ו'],
            ],
            EmailTemplate::MATERIAL_REMINDER => [
                'usage' => 'תזכורת על חומרים שלא אושרו',
                'intro' => 'נשלחת פעם אחת לכל נמעני משלוח חומרים שלא אושר עד שעבר מספר השעות שמוגדר באינטגרציות ← Smove. לא נשלחת אם הלקוחה כבר לחצה "קיבלתי את החומרים", או אם המשלוח סומן כטופל. כשהתבנית מושבתת, לא נשלחות תזכורות.',
                'fields' => ['שם_בית_הספר' => 'שם בית הספר', 'תוכנית' => 'תוכנית'],
                'blocks' => [EmailTemplate::ACKNOWLEDGE_BUTTON_TOKEN => ['label' => 'כפתור "קיבלתי את החומרים"', 'preview' => $receivedButton]],
                'example' => ['school_name' => 'בית ספר אורות', 'program_name' => 'אלול תשפ"ו'],
            ],
            EmailTemplate::DOCUMENT_SEND => [
                'usage' => EmailTemplate::FIXED_LABELS[EmailTemplate::DOCUMENT_SEND],
                'intro' => 'המייל שנשלח בלחיצה על "שליחה" בכרטיס מסמך — הצעת מחיר, טופס הזמנה או חוזה — לכל נמען בנפרד. המסמך מצורף תמיד כקובץ PDF, והכפתור מוביל לטופס המקוון לצפייה, מילוי פרטים ואישור. חשבוניות וקבלות נשלחות מ-SUMIT ולא מכאן.',
                'fields' => ['שם_הנמען' => 'שם הנמען', 'סוג_המסמך' => 'סוג המסמך', 'שם_בית_הספר' => 'שם בית הספר'],
                'blocks' => [EmailTemplate::DOCUMENT_BUTTON_TOKEN => ['label' => 'כפתור המסמך', 'preview' => '<span style="display:inline-block; background:#286E9A; color:#fff; border-radius:32px; padding:10px 28px; font-weight:700">לצפייה במסמך, מילוי פרטים ואישור מקוון</span>']],
                'example' => ['recipient_name' => 'רחל כהן', 'document_type' => 'הצעת מחיר', 'school_name' => 'בית ספר אורות'],
            ],
            EmailTemplate::DOCUMENTS_SEND => [
                'usage' => EmailTemplate::FIXED_LABELS[EmailTemplate::DOCUMENTS_SEND],
                'intro' => 'המייל שנשלח כשטופס הזמנה יוצא יחד עם החוזה שלו (בשליחת הטופס, או אוטומטית כשהצעת מחיר אושרה). לכל מסמך כפתור משלו לטופס המקוון, ושני קובצי ה-PDF מצורפים.',
                'fields' => ['שם_הנמען' => 'שם הנמען', 'המסמכים' => 'שמות המסמכים', 'שם_בית_הספר' => 'שם בית הספר'],
                'blocks' => [EmailTemplate::DOCUMENT_BUTTON_TOKEN => ['label' => 'כפתורי המסמכים', 'preview' => '<div style="display:flex; flex-direction:column; gap:8px; align-items:flex-start">'.'<span style="display:inline-block; background:#286E9A; color:#fff; border-radius:32px; padding:10px 28px; font-weight:700">טופס הזמנה — לצפייה, מילוי פרטים ואישור מקוון</span>'.'<span style="display:inline-block; background:#286E9A; color:#fff; border-radius:32px; padding:10px 28px; font-weight:700">חוזה — לצפייה, מילוי פרטים ואישור מקוון</span>'.'</div>']],
                'example' => ['recipient_name' => 'רחל כהן', 'documents' => 'טופס הזמנה וחוזה', 'school_name' => 'בית ספר אורות'],
            ],
            EmailTemplate::PAYMENT_LINK, EmailTemplate::STANDING_ORDER_LINK => [
                'usage' => EmailTemplate::FIXED_LABELS[$this->emailTemplateType],
                'intro' => $this->emailTemplateType === EmailTemplate::PAYMENT_LINK
                    ? 'המייל שנשלח בלחיצה על "שליחת קישור לתשלום" בכרטיס העסקה, לתשלום חד-פעמי בכרטיס אשראי בדף המאובטח של SUMIT.'
                    : 'המייל שנשלח בלחיצה על "שליחת קישור לתשלום" בעסקה בהוראת קבע — התשלום הראשון עכשיו, והבאים מאותו כרטיס בכל חודש.',
                'fields' => ['שם_הנמען' => 'שם הנמען', 'פריט' => 'תוכנית / חבילה', 'סכום' => $this->emailTemplateType === EmailTemplate::PAYMENT_LINK ? 'סכום' : 'סכום חודשי', 'תוקף_הקישור' => 'תוקף הקישור', 'שם_בית_הספר' => 'שם בית הספר']
                    + ($this->emailTemplateType === EmailTemplate::STANDING_ORDER_LINK ? ['מספר_חודשים' => 'מספר חודשים'] : []),
                'blocks' => [EmailTemplate::PAYMENT_BUTTON_TOKEN => ['label' => 'כפתור התשלום', 'preview' => '<span style="display:inline-block; background:#286E9A; color:#fff; border-radius:32px; padding:10px 28px; font-weight:700">לתשלום מאובטח בכרטיס אשראי</span>']],
                'example' => ['recipient_name' => 'רחל כהן', 'item' => 'אלול תשפ"ו', 'amount' => $this->emailTemplateType === EmailTemplate::PAYMENT_LINK ? '3,600.00' : '300.00', 'months' => '12', 'expires_at' => now()->addDays(3)->format('d/m/Y'), 'school_name' => 'בית ספר אורות'],
            ],
            EmailTemplate::TASK_REMINDERS => [
                'usage' => EmailTemplate::FIXED_LABELS[EmailTemplate::TASK_REMINDERS],
                'intro' => 'מייל פנימי שנשלח כל בוקר ב-08:00 לכל משתמשת שיש לה תזכורות להיום: תזכורות בלידים שמועדן היום, ומשימות גבייה שנפתחו. כל פריט ברשימה מקשר ישירות לליד או לעסקה. כשהתבנית מושבתת, לא נשלחים מיילי בוקר.',
                'fields' => ['שם_המשתמשת' => 'שם המשתמשת', 'מספר_התזכורות' => 'מספר התזכורות'],
                'blocks' => [EmailTemplate::TASK_LIST_TOKEN => ['label' => 'רשימת התזכורות', 'preview' => '<ul style="margin:0; padding-inline-start:20px"><li><a style="color:#286E9A; font-weight:700">להתקשר למנהלת</a><div style="font-size:var(--fs-caption)">תזכורת בליד · בית ספר אורות</div></li><li><a style="color:#286E9A; font-weight:700">גבייה: עסקה #12</a><div style="font-size:var(--fs-caption)">גבייה · בית ספר אורות</div></li></ul>']],
                'example' => ['user_name' => 'מיכל', 'task_count' => '2'],
            ],
            EmailTemplate::GENERAL => [
                'usage' => 'כללי',
                'intro' => 'תבנית שהמערכת לא שולחת אוטומטית — בוחרים אותה בשליחת מייל לרשימות תפוצה או בשליחת חומרים מכרטיס הלקוחה, ואפשר לשנות את הנוסח לפני כל שליחה. שם בית הספר והתוכנית מתמלאים רק בשליחת חומרים (בשליחה לרשימות אין אותם). בשליחת חומרים יופיעו מתחת לנוסח שני הכפתורים "להורדת החומרים" ו"קיבלתי, תודה"; רשימת החומרים משמשת רק בשליחה לרשימות.',
                'fields' => ['שם_בית_הספר' => 'שם בית הספר', 'תוכנית' => 'תוכנית'],
                'blocks' => [EmailTemplate::MATERIALS_LIST_TOKEN => ['label' => 'רשימת החומרים', 'preview' => '<ul style="margin:0; padding-inline-start:20px"><li><a style="color:#286E9A; font-weight:600">חוברת לתלמידה.pdf</a></li><li><a style="color:#286E9A; font-weight:600">סרטון הדרכה</a></li></ul>']],
                'example' => ['school_name' => 'בית ספר אורות', 'program_name' => 'אלול תשפ"ו'],
            ],
            default => [
                'usage' => EmailTemplate::TYPE_LABELS[$this->emailTemplateType] ?? 'כללי',
                'intro' => 'נשלח אוטומטית לאיש הקשר כשנכנס ליד חדש מדף הנחיתה. השדות בסוגריים המסולסלים יתמלאו בפרטי הליד.',
                'fields' => ['שם_איש_הקשר' => 'שם איש הקשר', 'שם_בית_הספר' => 'שם בית הספר'],
                'blocks' => [],
                'example' => ['contact_name' => 'רחל כהן', 'school_name' => 'בית ספר אורות'],
            ],
        };
    }

    /**
     * The open template's email exactly as it goes out (the same
     * EmailMessage builder as the real send), with the example values and
     * buttons that lead nowhere — in the preview window, with "שליחת בדיקה אליי".
     */
    public function previewEmailTemplateEmail(): void
    {
        $this->showEmailPreview($this->emailTemplateMessage(), null, 'הנוסח כפי שהוא כרגע בעורך (גם לפני שמירה), עם פרטים לדוגמה. בתצוגה ובמייל הבדיקה הכפתורים והקישורים אינם פעילים.');
    }

    /** The open template as the email that goes out (the real send's EmailMessage builder), with the example values. */
    public function emailTemplateMessage(): \App\Support\EmailMessage
    {
        $rendered = $this->emailTemplatePreview();
        $example = fn (string $name) => ['name' => $name, 'url' => '#'];
        $isHtml = $this->emailTemplateFormat === EmailTemplate::FORMAT_HTML && in_array($this->emailTemplateType, EmailTemplate::HTML_TYPES, true);

        return match ($this->emailTemplateType) {
            EmailTemplate::SUPPLIER_INVOICE_REQUEST => \App\Support\EmailMessage::invoiceRequest($rendered['subject'], $rendered['content'], '#'),
            EmailTemplate::SUPPLIER_INVOICES_REQUEST => \App\Support\EmailMessage::invoicesRequest($rendered['subject'], $rendered['content'], [
                ['label' => 'תשלום מתאריך 03/09/2026 · ₪1,250', 'url' => '#'],
                ['label' => 'תשלום מתאריך 21/09/2026 · ₪480', 'url' => '#'],
            ]),
            EmailTemplate::MATERIAL_DELIVERY => \App\Support\EmailMessage::materials($rendered['subject'], $rendered['content'], '#', [$example('סרטון הדרכה')], '#', $isHtml),
            EmailTemplate::MATERIAL_REMINDER => \App\Support\EmailMessage::materialReminder($rendered['subject'], $rendered['content'], '#'),
            EmailTemplate::DOCUMENT_SEND => \App\Support\EmailMessage::document($rendered['subject'], $rendered['content'], '#'),
            EmailTemplate::DOCUMENTS_SEND => \App\Support\EmailMessage::documents($rendered['subject'], $rendered['content'], [['label' => 'טופס הזמנה', 'url' => '#'], ['label' => 'חוזה', 'url' => '#']]),
            EmailTemplate::PAYMENT_LINK, EmailTemplate::STANDING_ORDER_LINK => \App\Support\EmailMessage::paymentLink($rendered['subject'], $rendered['content'], '#'),
            EmailTemplate::TASK_REMINDERS => \App\Support\EmailMessage::taskReminders($rendered['subject'], $rendered['content'], [
                ['title' => 'להתקשר למנהלת', 'subtitle' => 'תזכורת בליד · בית ספר אורות', 'description' => null, 'url' => '#'],
                ['title' => 'גבייה: עסקה #12', 'subtitle' => 'גבייה · בית ספר אורות', 'description' => null, 'url' => '#'],
            ]),
            EmailTemplate::GENERAL => \App\Support\EmailMessage::composed($rendered['subject'], $rendered['content'], $isHtml, [$example('חוברת לתלמידה.pdf'), $example('סרטון הדרכה')]),
            default => \App\Support\EmailMessage::transactional($rendered['subject'], $rendered['content']),
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
        <span class="{{ $activeTab === 'import' ? 'active' : '' }}" wire:click="$set('activeTab', 'import')">יבוא נתונים</span>
        <span class="{{ $activeTab === 'backup' ? 'active' : '' }}" wire:click="$set('activeTab', 'backup')">גיבוי</span>
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
                <p class="hint text-text-secondary" style="font-size:var(--fs-caption); margin:0">מיילים שהמערכת שולחת בעצמה, ותבניות כלליות שבוחרים בשליחה לרשימות תפוצה ובשליחת חומרים. בוחרים תבנית כדי לערוך אותה.</p>
            </div>
            <button type="button" wire:click="newEmailTemplate" class="btn btn-primary btn-sm">+ תבנית חדשה</button>
        </div>

        <div class="card" style="padding:0; overflow:hidden; margin-bottom:var(--sp-md)">
            <div class="table-scroll">
            <table>
                <thead><tr><th>שם</th><th>שימוש</th><th>נושא</th><th>סטטוס</th></tr></thead>
                <tbody>
                    @foreach ($this->emailTemplates as $template)
                        <tr wire:key="email-template-{{ $template->id }}" wire:click="selectEmailTemplate({{ $template->id }})" style="cursor:pointer; {{ $selectedEmailTemplateId === $template->id ? 'background:var(--color-background)' : '' }}">
                            <td style="font-weight:{{ $selectedEmailTemplateId === $template->id ? '700' : '400' }}">{{ $template->name }}</td>
                            <td>{{ $template->usageLabel() }}@if ($template->isHtml()) <span class="badge badge-info">HTML</span>@endif</td>
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
            @php($isFixed = in_array($emailTemplateType, EmailTemplate::FIXED_TYPES, true) || $selectedEmailTemplateId === 0)
            @php($canBeHtml = in_array($emailTemplateType, EmailTemplate::HTML_TYPES, true))
            @php($isHtml = $canBeHtml && $emailTemplateFormat === EmailTemplate::FORMAT_HTML)
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
                        <h3 style="margin:0 0 2px">{{ $openTemplate?->name ?? 'תבנית חדשה' }}</h3>
                        <p class="text-text-secondary" style="margin:0; font-size:var(--fs-small)">{{ $spec['intro'] }}</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px">
                        @if ($openTemplate && ! in_array($openTemplate->template_type, EmailTemplate::ALWAYS_ACTIVE_TYPES, true))
                            <button type="button" wire:click="toggleEmailTemplate({{ $openTemplate->id }})" class="btn btn-ghost btn-sm">{{ $openTemplate->is_active ? 'השבתה' : 'הפעלה' }}</button>
                        @endif
                        @if ($openTemplate?->template_type === EmailTemplate::GENERAL)
                            <button type="button" wire:click="deleteEmailTemplate({{ $openTemplate->id }})" class="btn btn-ghost btn-sm" style="color:var(--color-error)">מחיקה</button>
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
                            @unless ($canBeHtml) @error('emailTemplateFormat') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror @endunless
                        </div>
                    @endunless
                    <div class="full">
                        <label for="emailTemplateSubject">נושא המייל</label>
                        <input type="text" id="emailTemplateSubject" wire:model.live.debounce.400ms="emailTemplateSubject" x-on:focus="target = $el">
                        @error('emailTemplateSubject') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    @if ($canBeHtml)
                        <div class="full" role="radiogroup" aria-label="סוג התוכן" style="display:flex; gap:var(--sp-xl); flex-wrap:wrap">
                            <label style="display:inline-flex; align-items:center; gap:8px; margin:0; font-weight:500; cursor:pointer"><input type="radio" value="text" wire:model.live="emailTemplateFormat" style="width:auto; margin:0">טקסט בתבנית המיילים של המערכת</label>
                            <label style="display:inline-flex; align-items:center; gap:8px; margin:0; font-weight:500; cursor:pointer"><input type="radio" value="html" wire:model.live="emailTemplateFormat" style="width:auto; margin:0">HTML משלי</label>
                        </div>
                        @error('emailTemplateFormat') <div class="full" style="color: var(--color-error); font-size: var(--fs-caption);">{{ $message }}</div> @enderror
                    @endif
                    @if ($isHtml)
                        <div class="full">
                            <label for="emailTemplateHtmlFile">העלאת קובץ HTML (מחליף את הקוד שלמטה)</label>
                            <input type="file" id="emailTemplateHtmlFile" wire:model="emailTemplateHtmlFile" accept=".html,.htm,text/html">
                            @error('emailTemplateHtmlFile') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </div>
                    @endif
                    <div class="full">
                        <label for="emailTemplateContent">{{ $isHtml ? 'קוד ה-HTML' : 'גוף המייל' }}</label>
                        <textarea id="emailTemplateContent" x-ref="body" wire:model.live.debounce.400ms="emailTemplateContent" x-on:focus="target = $el" rows="{{ $isHtml ? 14 : 10 }}" @if ($isHtml) dir="ltr" style="font-family:ui-monospace, Menlo, Consolas, monospace; font-size:var(--fs-caption)" @endif></textarea>
                        @error('emailTemplateContent') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        @if ($isHtml)
                            <div class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">ה-HTML נשלח כמו שהוא, בלי מסגרת המיילים של המערכת.</div>
                        @endif
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
                        @if ($isHtml || $emailTemplateType === EmailTemplate::MATERIAL_DELIVERY)
                            <div style="font-weight:600; margin-bottom:var(--sp-sm)">{{ $preview['subject'] }}</div>
                            {{-- sandbox: the template's scripts never run inside the app. --}}
                            <iframe sandbox srcdoc="{{ $this->emailTemplateMessage()->html }}" title="תצוגה מקדימה של התבנית" style="width:100%; height:420px; border:1px solid var(--color-border); border-radius:var(--radius-control); background:#FFFFFF"></iframe>
                        @else
                            <div style="border:1px solid var(--color-border); border-radius:var(--radius-control); padding:var(--sp-md); background:var(--color-background)">
                                <div style="font-weight:600; margin-bottom:var(--sp-sm)">{{ $preview['subject'] }}</div>
                                {!! $this->emailTemplatePreviewHtml() !!}
                            </div>
                        @endif
                    </div>
                    <div class="full" style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary">{{ $selectedEmailTemplateId === 0 ? 'יצירת התבנית' : 'שמירת נוסח המייל' }}</button>
                        <button type="button" class="btn btn-secondary" wire:click="previewEmailTemplateEmail">תצוגה מקדימה מדויקת ושליחת בדיקה</button>
                    </div>
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
                        <input type="password" id="smoveApiKey" wire:model="smoveApiKey" class="ltr-num" dir="ltr" placeholder="{{ $savedSecretHints['smove_api_key'] ?? '' }}" autocomplete="new-password">
                        @if ($savedSecretHints['smove_api_key'] ?? '')
                            <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">✓ שמור: <span dir="ltr">{{ $savedSecretHints['smove_api_key'] }}</span> — להחלפה הקלידו ערך חדש</div>
                        @endif
                        <p class="hint text-text-secondary" style="font-size:var(--fs-caption); margin:6px 0 0">
                            איפה מוצאים את זה: נכנסים לחשבון שלכם ב-<span class="ltr-num" dir="ltr">smoove.io</span> ←
                            עוברים עם העכבר על שם החשבון (למעלה) ← בוחרים <b><span class="ltr-num" dir="ltr">API Keys &amp; pixels</span></b> ←
                            <b><span class="ltr-num" dir="ltr">Add API Key</span></b> ← בוחרים הרשאה <b>Full Permission</b> ←
                            שומרים, ומעתיקים את המפתח שנוצר לכאן.
                        </p>
                    </div>
                    <div class="full">
                        <label for="smoveWebhookSecret">סוד Webhook (רשות)</label>
                        <input type="password" id="smoveWebhookSecret" wire:model="smoveWebhookSecret" class="ltr-num" dir="ltr" placeholder="{{ $savedSecretHints['smove_webhook_secret'] ?? '' }}" autocomplete="new-password">
                        @if ($savedSecretHints['smove_webhook_secret'] ?? '')
                            <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">✓ שמור: <span dir="ltr">{{ $savedSecretHints['smove_webhook_secret'] }}</span> — להחלפה הקלידו ערך חדש</div>
                        @endif
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
                        <input type="password" id="landingPageWebhookSecret" wire:model="landingPageWebhookSecret" class="ltr-num" dir="ltr" placeholder="{{ $savedSecretHints['landing_page_webhook_secret'] ?? '' }}" autocomplete="new-password">
                        @if ($savedSecretHints['landing_page_webhook_secret'] ?? '')
                            <div class="text-text-secondary" style="font-size: var(--fs-caption); margin-top: 4px;">✓ שמור: <span dir="ltr">{{ $savedSecretHints['landing_page_webhook_secret'] }}</span> — להחלפה הקלידו ערך חדש</div>
                        @endif
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
    @if ($activeTab === 'import')
    {{-- ===== יבוא נתונים — customers from an Excel file (App\Services\CustomerImport) ===== --}}
    @php($errStyle = 'color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;')
    <div class="tabs" style="margin-bottom: var(--sp-md)">
        <span class="{{ $importKind === 'customers' ? 'active' : '' }}" wire:click="$set('importKind', 'customers')">לקוחות</span>
        <span class="{{ $importKind === 'catalog' ? 'active' : '' }}" wire:click="$set('importKind', 'catalog')">תוכניות ומארזים</span>
        <span class="{{ $importKind === 'deals' ? 'active' : '' }}" wire:click="$set('importKind', 'deals')">עסקאות</span>
    </div>
    @if ($importKind === 'catalog')
        @include('partials.settings-catalog-import')
    @elseif ($importKind === 'deals')
        @include('partials.settings-deal-import', ['dealImportStatus' => $this->dealImportStatus])
    @else
    <section class="settings-section">
        <div class="section-head">
            <h2>יבוא לקוחות מאקסל</h2>
        </div>

        <div class="cols2">
            <div class="card">
                <h3 style="margin:0 0 4px">1. הורדת הקובץ למילוי</h3>
                <a href="{{ route('settings.import-template') }}" class="btn btn-secondary">הורדת קובץ האקסל</a>
            </div>

            <div class="card">
                <h3 style="margin:0 0 4px">2. העלאת הקובץ המלא לבדיקה</h3>
                <form wire:submit="checkImport" style="display:flex; flex-direction:column; gap:var(--sp-sm)">
                    <input type="file" id="importFile" wire:model="importFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    @error('importFile') <div style="{{ $errStyle }}">{{ $message }}</div> @enderror
                    <div>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="importFile,checkImport">
                            <span wire:loading.remove wire:target="checkImport">בדיקת הקובץ</span>
                            <span wire:loading wire:target="checkImport">בודקת…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        @if ($this->importStatus)
            @php($status = $this->importStatus)
            <div class="card" style="margin-top: var(--sp-md)" @if (in_array($status['state'], ['queued', 'running'], true)) wire:poll.2s @endif>
                @if (in_array($status['state'], ['queued', 'running'], true))
                    <h3 style="margin:0 0 var(--sp-sm)">מייבאת… {{ $status['done'] ?? 0 }} מתוך {{ $status['total'] ?? 0 }}</h3>
                    <div style="height:8px; background:var(--color-border); border-radius:4px; overflow:hidden">
                        <div style="height:100%; width:{{ ($status['total'] ?? 0) ? round(100 * ($status['done'] ?? 0) / $status['total']) : 0 }}%; background:var(--color-primary); transition:width .3s"></div>
                    </div>
                @elseif ($status['state'] === 'done')
                    <h3 style="margin:0 0 var(--sp-sm)">✓ היבוא הסתיים: נוספו {{ $status['created'] }} לקוחות</h3>
                    <p style="font-size:var(--fs-caption); margin:0 0 var(--sp-sm)">{{ $status['smove']['linked'] ?? 0 }} אנשי קשר קושרו לאנשי הקשר ברשימת התפוצה ב־Smove.@if (! empty($status['smove']['joined'])) {{ $status['smove']['joined'] }} אנשי קשר שלא היו ברשימה נפתחו וצורפו אליה.@endif</p>
                    @if (! empty($status['skipped']))
                        <p style="{{ $errStyle }}">{{ $status['skipped'] }} שורות לא יובאו כי לא עברו את הבדיקה החוזרת בזמן היבוא (למשל בית ספר שנוסף למערכת בינתיים). אפשר להעלות את הקובץ שוב כדי לראות את השגיאות.</p>
                    @endif
                    @if (! empty($status['failed']))
                        <p style="{{ $errStyle }}">{{ count($status['failed']) }} שורות לא יובאו:</p>
                        <ul style="font-size:var(--fs-caption); margin:0 0 var(--sp-sm)">
                            @foreach ($status['failed'] as $failure)
                                <li>שורה {{ $failure['row'] }} ({{ $failure['school'] }}): {{ $failure['error'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                        @can('customers.view')<a href="{{ route('customers') }}" class="btn btn-primary">למסך הלקוחות</a>@endcan
                        <button type="button" wire:click="resetImport" class="btn btn-ghost">יבוא קובץ נוסף</button>
                    </div>
                @else
                    <p style="{{ $errStyle }}">{{ $status['message'] ?? 'היבוא נכשל.' }}</p>
                    <button type="button" wire:click="resetImport" class="btn btn-ghost">התחלה מחדש</button>
                @endif
            </div>
        @elseif ($importCheck)
            <div class="card" style="margin-top: var(--sp-md)">
                @if ($importCheck['fileError'])
                    <p style="color: var(--color-error); margin:0 0 var(--sp-sm)">{{ $importCheck['fileError'] }}</p>
                    <button type="button" wire:click="resetImport" class="btn btn-ghost">העלאת קובץ אחר</button>
                @else
                    <h3 style="margin:0 0 var(--sp-sm)">תוצאות הבדיקה</h3>
                    <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap; margin-bottom:var(--sp-md)">
                        <span class="badge badge-neutral">{{ $importCheck['total'] }} שורות בקובץ</span>
                        <span class="badge badge-success">{{ $importCheck['valid'] }} תקינות</span>
                        @if ($importCheck['invalidRows'])
                            <span class="badge badge-error">{{ $importCheck['invalidRows'] }} עם שגיאות</span>
                        @endif
                    </div>

                    @php($groups = $importCheck['groups'] ?? ['linked' => [], 'unlisted' => [], 'invalid' => []])
                    @php($smove = $importCheck['smove'] ?? null)

                    {{-- 1. Customers whose contacts are all on the Smove list --}}
                    <div class="import-group" x-data="{ open: false }">
                        <button type="button" class="import-group-head" @click="open = !open" :aria-expanded="open">
                            <span class="import-group-arrow" :class="open && 'open'">◀</span>
                            <span class="badge badge-success">{{ count($groups['linked']) }}</span>
                            <strong>לקוחות שאנשי הקשר שלהן נמצאו ברשימת Smove</strong>
                        </button>
                        <div x-show="open" x-cloak class="import-group-body">
                            @if ($smove && $smove['not_receiving'])
                                <p style="color:var(--color-warning); font-size:var(--fs-caption); margin:0 0 var(--sp-sm)">{{ $smove['not_receiving'] }} אנשי קשר לא מאשרים כרגע קבלת מיילים ב־Smove (הוסרו או לא אישרו). הם יקושרו, אבל מיילים לא יגיעו אליהם עד שיאשרו.</p>
                            @endif
                            @if ($groups['linked'])
                                <div class="table-scroll" style="max-height:360px; overflow-y:auto">
                                <table>
                                    <thead><tr><th>שורה</th><th>מוסד</th><th>אנשי קשר</th></tr></thead>
                                    <tbody>
                                        @foreach (array_slice($groups['linked'], 0, 500) as $row)
                                            <tr>
                                                <td class="ltr-num">{{ $row['row'] }}</td>
                                                <td>{{ $row['school'] }}</td>
                                                <td style="font-size:var(--fs-caption)">
                                                    @foreach ($row['contacts'] as $contact)
                                                        <div>{{ $contact['name'] }}@if ($contact['email']) · <span dir="ltr">{{ $contact['email'] }}</span>@endif @if ($contact['primary'])<span class="badge badge-neutral">ראשי</span>@endif @if ($contact['accounting'])<span class="badge badge-neutral">חשבונאי</span>@endif</div>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                            @else
                                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">אין שורות בקבוצה הזו.</p>
                            @endif
                        </div>
                    </div>

                    {{-- 2. Customers with contacts not on the Smove list — opened there once approved --}}
                    <div class="import-group" x-data="{ open: false }">
                        <button type="button" class="import-group-head" @click="open = !open" :aria-expanded="open">
                            <span class="import-group-arrow" :class="open && 'open'">◀</span>
                            <span class="badge badge-warning">{{ count($groups['unlisted']) }}</span>
                            <strong>לקוחות עם אנשי קשר שלא נמצאו ב־Smove</strong>
                        </button>
                        <div x-show="open" x-cloak class="import-group-body">
                            @if ($groups['unlisted'])
                                <div class="table-scroll" style="max-height:360px; overflow-y:auto">
                                <table>
                                    <thead><tr><th>שורה</th><th>מוסד</th><th>אנשי קשר</th></tr></thead>
                                    <tbody>
                                        @foreach (array_slice($groups['unlisted'], 0, 500) as $row)
                                            <tr>
                                                <td class="ltr-num">{{ $row['row'] }}</td>
                                                <td>{{ $row['school'] }}</td>
                                                <td style="font-size:var(--fs-caption)">
                                                    @foreach ($row['contacts'] as $contact)
                                                        <div>
                                                            {{ $contact['name'] }}@if ($contact['email']) · <span dir="ltr">{{ $contact['email'] }}</span>@endif
                                                            @if ($contact['unlisted'])<span class="badge badge-warning">ייפתח ב־Smove</span>@elseif ($contact['email'])<span class="badge badge-success">נמצא ב־Smove</span>@endif
                                                        </div>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                                <div style="padding-top: var(--sp-sm)">
                                    <span class="checkbox-row"><input type="checkbox" id="importApproveUnlisted" wire:model.live="importApproveUnlisted"><label for="importApproveUnlisted" style="margin:0"><strong>אני מאשרת לפתוח את אנשי הקשר האלה ולצרף אותם לרשימת התפוצה ב־Smove</strong></label></span>
                                </div>
                            @else
                                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">אין שורות בקבוצה הזו.</p>
                            @endif
                        </div>
                        @if ($groups['unlisted'] && ! $importApproveUnlisted)
                            <p style="color:var(--color-warning); font-size:var(--fs-caption); margin:4px var(--sp-md) 0">כדי לייבא, יש לפתוח את השורה ולאשר את פתיחת אנשי הקשר ב־Smove.</p>
                        @endif
                    </div>

                    {{-- 3. Rows with errors — fixed here, written back into the file and checked again --}}
                    <div class="import-group" x-data="{ open: {{ $groups['invalid'] ? 'true' : 'false' }} }" style="{{ $groups['invalid'] ? 'border-color:var(--color-error)' : '' }}">
                        <button type="button" class="import-group-head" @click="open = !open" :aria-expanded="open">
                            <span class="import-group-arrow" :class="open && 'open'">◀</span>
                            <span class="badge badge-error">{{ count($groups['invalid']) }}</span>
                            <strong>לקוחות עם פרטים שגויים</strong>
                        </button>
                        <div x-show="open" x-cloak class="import-group-body">
                            @if ($groups['invalid'])
                                <div class="table-scroll" style="max-height:520px; overflow-y:auto">
                                <table>
                                    <thead><tr><th>שורה</th><th>מוסד</th><th>שדה</th><th>ערך (לתיקון)</th><th>מה שגוי</th></tr></thead>
                                    <tbody>
                                        @foreach (array_slice($groups['invalid'], 0, 500) as $row)
                                            @foreach ($row['fields'] as $key => $field)
                                                <tr wire:key="fix-{{ $row['row'] }}-{{ $key }}">
                                                    @if ($loop->first)
                                                        <td class="ltr-num" rowspan="{{ count($row['fields']) }}">{{ $row['row'] }}</td>
                                                        <td rowspan="{{ count($row['fields']) }}">{{ $row['school'] ?: '—' }}</td>
                                                    @endif
                                                    <td>{{ $field['label'] }}</td>
                                                    <td>
                                                        <input type="text" class="import-fix-input" wire:model="importFixes.{{ $row['row'] }}.{{ $key }}"
                                                            @if (str_contains($key, 'email') || str_contains($key, 'phone') || $key === 'business_number') dir="ltr" @endif
                                                            @if (! auth()->user()->hasPermission('settings', 'edit')) disabled @endif>
                                                    </td>
                                                    <td style="color: var(--color-error); font-size:var(--fs-caption)">{{ $field['message'] }}</td>
                                                </tr>
                                            @endforeach
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                                @if (count($groups['invalid']) > 500)
                                    <p class="text-text-secondary" style="font-size:var(--fs-caption)">מוצגות 500 השורות הראשונות מתוך {{ count($groups['invalid']) }}.</p>
                                @endif
                                @can('settings.edit')
                                    <div style="padding-top: var(--sp-sm)">
                                        <button type="button" wire:click="fixImport" wire:loading.attr="disabled" wire:target="fixImport" class="btn btn-secondary">
                                            <span wire:loading.remove wire:target="fixImport">שמירת התיקונים ובדיקה מחדש</span>
                                            <span wire:loading wire:target="fixImport">בודקת…</span>
                                        </button>
                                    </div>
                                @endcan
                            @else
                                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">אין שורות עם שגיאות.</p>
                            @endif
                        </div>
                    </div>

                    @if ($importCheck['warnings'] ?? [])
                        <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-md)">
                            <div style="padding: var(--sp-sm) var(--sp-md)">
                                <h4 style="margin:0 0 4px; color:var(--color-warning)">שימי לב: {{ count($importCheck['warnings']) }} אנשי קשר שהכתובת שלהם כבר שייכת ללקוחה אחרת</h4>
                            </div>
                            <div class="table-scroll" style="max-height:320px; overflow-y:auto">
                            <table>
                                <thead><tr><th>שורה</th><th>מוסד</th><th>עמודה</th><th>אזהרה</th></tr></thead>
                                <tbody>
                                    @foreach (array_slice($importCheck['warnings'], 0, 500) as $warning)
                                        <tr>
                                            <td class="ltr-num">{{ $warning['row'] }}</td>
                                            <td>{{ $warning['school'] ?: '—' }}</td>
                                            <td>{{ $warning['column'] }}</td>
                                            <td style="color: var(--color-warning)">{{ $warning['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        </div>
                    @endif

                    @if ($importCheck['valid'] > 0)
                        <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                            @can('settings.edit')
                                <button type="button" wire:click="runImport" wire:loading.attr="disabled" class="btn btn-primary" @disabled(($importCheck['unlisted'] ?? []) && ! $importApproveUnlisted)
                                    wire:confirm="לייבא {{ $importCheck['valid'] }} לקוחות חדשות? לא ניתן למחוק לקוחות אחרי היבוא.">
                                    ייבוא {{ $importCheck['valid'] }} {{ $importCheck['invalidRows'] ? 'השורות התקינות' : 'לקוחות' }}
                                </button>
                            @else
                                <span class="text-text-secondary" style="font-size:var(--fs-caption)">ליבוא עצמו נדרשת הרשאת עריכה בהגדרות.</span>
                            @endcan
                            <button type="button" wire:click="resetImport" class="btn btn-ghost">ביטול</button>
                        </div>
                    @else
                        <p class="text-text-secondary" style="font-size:var(--fs-caption)">אין שורות תקינות ליבוא.</p>
                        <button type="button" wire:click="resetImport" class="btn btn-ghost">העלאת קובץ אחר</button>
                    @endif
                @endif
            </div>
        @endif
    </section>
    @endif
    @endif
    @if ($activeTab === 'backup')
    {{-- ===== גיבוי שבועי — App\Services\WeeklyBackup ===== --}}
    @php($backup = app(\App\Services\WeeklyBackup::class))
    @php($latestBackup = $backup->latest())
    @php($lastDownload = $backup->lastDownloaded())
    <section class="settings-section">
        <div class="section-head">
            <h2>גיבוי שבועי למחשב</h2>
        </div>
        <div class="card" style="max-width:720px">

            <div style="margin-bottom:var(--sp-md)">
                @if ($backup->isActive())
                    <span class="badge badge-success">פעיל</span>
                @else
                    <span class="badge badge-warning">לא פעיל — יש לבחור סיסמה ומשתמשת</span>
                @endif
            </div>

            <form wire:submit="saveWeeklyBackup" class="form-grid">
                <div class="full">
                    <label for="backupUserId">למי יורד הגיבוי</label>
                    <select id="backupUserId" wire:model="backupUserId">
                        <option value="">— בחירה —</option>
                        @foreach (\App\Models\User::where('is_active', true)->orderBy('name')->get() as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                    @error('backupUserId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="backupPassword">{{ $backup->hasPassword() ? 'סיסמה חדשה לקובץ (ריק = ללא שינוי)' : 'סיסמה לקובץ הגיבוי' }}</label>
                    <input id="backupPassword" type="password" wire:model="backupPassword" autocomplete="new-password">
                    @error('backupPassword') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="backupPassword_confirmation">הסיסמה שוב</label>
                    <input id="backupPassword_confirmation" type="password" wire:model="backupPassword_confirmation" autocomplete="new-password">
                </div>
                <p class="full" style="margin:0; font-size:var(--fs-caption); color:var(--color-warning)"><strong>חשוב:</strong> רשמי את הסיסמה במקום בטוח — בלעדיה אי אפשר לפתוח את הגיבוי.</p>
                @can('settings.edit')
                    <div class="full"><button type="submit" class="btn btn-primary">שמירה</button></div>
                @endcan
            </form>
        </div>

        <div class="card" style="max-width:720px; margin-top:var(--sp-lg)">
            <h3 style="margin-top:0">הגיבוי האחרון</h3>
            @if ($latestBackup)
                <p style="margin:0 0 var(--sp-sm)">
                    נוצר ב-<span class="ltr-num">{{ $latestBackup['created_at']->format('d/m/Y H:i') }}</span>
                    · <span class="ltr-num">{{ round($latestBackup['size'] / 1048576, 1) }}MB</span>
                </p>
                <p style="margin:0 0 var(--sp-md); font-size:var(--fs-caption); color:var(--color-text-secondary)">
                    @if ($lastDownload['name'] === $latestBackup['name'])
                        ירד למחשב של {{ $lastDownload['user'] }} ב-<span class="ltr-num">{{ $lastDownload['at']?->format('d/m/Y H:i') }}</span>
                    @else
                        עוד לא ירד
                    @endif
                </p>
                <a href="{{ route('backups.weekly.download') }}" class="btn btn-secondary btn-sm">הורדה עכשיו</a>
            @else
                <p class="text-text-secondary" style="margin:0">עוד לא נוצר גיבוי שבועי.</p>
            @endif
        </div>
    </section>
    @endif
    <x-email-preview />
</div>
