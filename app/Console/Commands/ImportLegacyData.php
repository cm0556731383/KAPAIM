<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Program;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Build-plan 18 (FR-8.21): one-time (or repeated-until-clean, per the
 * build-plan's "ריצה חד-פעמית (או מספר ריצות עד לניקוי מלא של המקור)")
 * importer for historical leads/customers/deals coming from the business's
 * pre-CRM Excel records. There is no real legacy Excel export available to
 * build against, so this is a generic, documented CSV importer rather than a
 * one-off script — see docs/legacy-import-format.md for the full column
 * reference, an example row, and the de-duplication/idempotency rules below
 * in plain language. storage/app/legacy-import-sample.csv is both a worked
 * example of the format and this stage's own test fixture.
 *
 * Only CSV is read (PHP's native fgetcsv — no new Composer dependency for
 * Excel/.xlsx parsing, out of scope for this stage). Export the real Excel
 * file to CSV (UTF-8) before running this command.
 *
 * Expected columns (one row = one historical lead-or-customer, optionally
 * with one associated historical deal):
 *   school_name, school_phone, school_city, contact_name, contact_phone,
 *   contact_email, lead_source, is_customer, converted_date, program_name,
 *   deal_amount, deal_date, notes
 *
 * Every entity this command touches is created through the exact same
 * business-rule-enforcing path a real user would go through — it never
 * inserts into `customers` directly (Lead::convertToCustomer() is still the
 * only place a Customer row is created, FR-2.3/FR-8.2), never bypasses
 * Deal::createForCustomer(), and reuses Lead::findExactDuplicateSchool() /
 * findFuzzyDuplicateSchool() (build-plan 04) for school matching rather than
 * reinventing it. The only additions those methods needed were purely
 * additive optional "backdate this" parameters (Deal::createForCustomer()'s
 * $purchasedAt, Lead::convertToCustomer()'s $convertedAt) — every existing
 * caller is unaffected.
 *
 * Idempotency (safe to run multiple times against a source file being
 * incrementally cleaned up, per the build-plan): a school already matched
 * exactly by name/phone is reused, never duplicated; a school that already
 * has a lead reuses that lead; a lead already converted reuses its existing
 * customer instead of erroring; a contact with the same name+phone already
 * on the school is left alone; a deal already recorded for that
 * customer+program+purchase-date is reused rather than duplicated. Running
 * this command twice against the identical file is a no-op the second time.
 */
class ImportLegacyData extends Command
{
    protected $signature = 'legacy:import
        {path : נתיב לקובץ ה-CSV של הנתונים ההיסטוריים (UTF-8)}
        {--dry-run : ניתוח וולידציה של הקובץ בלבד, ללא כל כתיבה למסד הנתונים}';

    protected $description = 'ייבוא חד-פעמי (או חוזר, עד לניקוי מלא של המקור) של לידים/לקוחות/עסקאות היסטוריים מקובץ CSV (FR-8.21) — ראו docs/legacy-import-format.md.';

    private const EXPECTED_HEADERS = [
        'school_name', 'school_phone', 'school_city', 'contact_name', 'contact_phone',
        'contact_email', 'lead_source', 'is_customer', 'converted_date', 'program_name',
        'deal_amount', 'deal_date', 'notes',
    ];

    /** Fallback for a blank/unmatched lead_source (build-plan 18 judgment call). */
    private const DEFAULT_LEAD_SOURCE_NAME = 'הגירת נתונים';

    /** FR-8.21 "preserve context" marker prefixed onto every activity log this command writes directly. */
    private const IMPORT_MARKER = 'יובא ממערכת קודמת';

    /** @var array<string, int> */
    private array $summary = [
        'schools_created' => 0, 'schools_reused' => 0,
        'leads_created' => 0, 'leads_reused' => 0,
        'contacts_created' => 0, 'contacts_reused' => 0,
        'customers_created' => 0, 'customers_reused' => 0,
        'deals_created' => 0, 'deals_reused' => 0, 'deals_skipped' => 0,
        'rows_ok' => 0, 'rows_error' => 0,
    ];

    /** @var list<string> */
    private array $rowReports = [];

    public function handle(ActivityLogger $activityLogger): int
    {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("קובץ לא נמצא או לא ניתן לקריאה: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error("שגיאה בפתיחת הקובץ: {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('הקובץ ריק.');
            fclose($handle);

            return self::FAILURE;
        }

        $header = array_map(fn ($cell) => preg_replace('/^\x{FEFF}/u', '', trim((string) $cell)), $header);

        $missingHeaders = array_diff(self::EXPECTED_HEADERS, $header);

        if ($missingHeaders !== []) {
            $this->error('כותרות עמודות חסרות בקובץ: '.implode(', ', $missingHeaders));
            $this->line('כותרות נדרשות (ראו docs/legacy-import-format.md): '.implode(', ', self::EXPECTED_HEADERS));
            fclose($handle);

            return self::FAILURE;
        }

        $lineNumber = 1;

        DB::beginTransaction();

        try {
            try {
                while (($row = fgetcsv($handle)) !== false) {
                    $lineNumber++;

                    $data = [];
                    foreach ($header as $index => $key) {
                        $data[$key] = trim((string) ($row[$index] ?? ''));
                    }

                    if (array_filter($data, fn ($value) => $value !== '') === []) {
                        continue; // blank line — skip silently, not an error
                    }

                    try {
                        // A per-row savepoint (Laravel nests DB::transaction()
                        // automatically) — a row-level failure only unwinds
                        // that row, never the rows already committed to the
                        // outer transaction before it.
                        DB::transaction(function () use ($data, $activityLogger, $lineNumber): void {
                            $this->importRow($data, $lineNumber, $activityLogger);
                        });
                    } catch (RuntimeException $e) {
                        $this->summary['rows_error']++;
                        $this->rowReports[] = "שורה {$lineNumber}: שגיאה — {$e->getMessage()}";
                    }
                }
            } finally {
                fclose($handle);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('שגיאה בלתי צפויה בייבוא — בוטלו כל השינויים: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printAndWriteReport($dryRun, $path);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $row
     *
     * @throws RuntimeException on a hard row failure (missing required
     *                          field) — caught by handle() per-row, so one
     *                          bad row never aborts the whole import.
     */
    private function importRow(array $row, int $lineNumber, ActivityLogger $activityLogger): void
    {
        $notes = [];

        $schoolName = $row['school_name'];

        if ($schoolName === '') {
            throw new RuntimeException('school_name חסר — שדה חובה.');
        }

        $contactEmail = $row['contact_email'];

        if ($contactEmail === '') {
            throw new RuntimeException('contact_email חסר — שדה חובה ליצירת ליד (leads.email אינו יכול להיות ריק).');
        }

        $schoolPhone = $row['school_phone'] !== '' ? $row['school_phone'] : null;
        $contactPhone = $row['contact_phone'] !== '' ? $row['contact_phone'] : null;
        $phone = $contactPhone ?? $schoolPhone;

        if (! $phone) {
            throw new RuntimeException('לא נמצא טלפון (contact_phone/school_phone) — שדה חובה ליצירת ליד.');
        }

        // ----- School (find-or-create, exact match reused — never duplicated) -----
        $school = Lead::findExactDuplicateSchool($schoolName, $schoolPhone ?? $phone);

        if ($school) {
            $this->summary['schools_reused']++;
        } else {
            $fuzzySchool = Lead::findFuzzyDuplicateSchool($schoolName, $schoolPhone);

            if ($fuzzySchool) {
                $notes[] = "אפשרות לכפילות בית ספר: קיים כבר בית ספר בשם דומה \"{$fuzzySchool->name}\" (#{$fuzzySchool->id}) — מומלץ בדיקה ידנית לפני מיזוג.";
            }

            $school = School::create([
                'name' => $schoolName,
                'phone' => $schoolPhone,
                'city' => $row['school_city'] !== '' ? $row['school_city'] : null,
            ]);
            $this->summary['schools_created']++;
        }

        // Best-available historical date for this row's activity-log entries.
        $convertedDate = $this->parseDate($row['converted_date']);
        $dealDate = $this->parseDate($row['deal_date']);
        $rowDate = $convertedDate ?? $dealDate ?? now();

        // ----- Lead (find-or-create — a school that already has a lead reuses it) -----
        $lead = Lead::where('school_id', $school->id)->latest('id')->first();

        if ($lead) {
            $this->summary['leads_reused']++;
        } else {
            $source = $this->resolveLeadSource($row['lead_source']);

            $newStatus = StatusDefinition::firstOrCreate(
                ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
                ['is_active' => true, 'sort_order' => 1],
            );

            $lead = Lead::create([
                'school_id' => $school->id,
                'lead_source_id' => $source->id,
                'status_id' => $newStatus->id,
                'email' => $contactEmail,
                'phone' => $phone,
                'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);

            $lead->joinPrimaryMailingList();

            $activityLogger->log('lead.created', self::IMPORT_MARKER.": ליד היסטורי עבור \"{$school->name}\"", [
                'lead_id' => $lead->id, 'school_id' => $school->id, 'occurred_at' => $rowDate,
            ]);

            $this->summary['leads_created']++;
        }

        // ----- Contact (skip if the same name+phone already exists on the school) -----
        $contactName = $row['contact_name'];

        if ($contactName !== '') {
            $existingContact = Contact::where('school_id', $school->id)
                ->where('name', $contactName)
                ->where('phone', $contactPhone)
                ->first();

            if ($existingContact) {
                $this->summary['contacts_reused']++;
            } else {
                $contact = Contact::create([
                    'school_id' => $school->id,
                    'name' => $contactName,
                    'phone' => $contactPhone,
                    'email' => $contactEmail !== '' ? $contactEmail : null,
                    'is_primary' => ! Contact::where('school_id', $school->id)->where('is_primary', true)->exists(),
                ]);

                $activityLogger->log('contact.created', self::IMPORT_MARKER.": נוסף איש קשר \"{$contact->name}\" לבית ספר \"{$school->name}\"", [
                    'school_id' => $school->id, 'occurred_at' => $rowDate,
                ]);

                $this->summary['contacts_created']++;
            }
        } elseif ($contactPhone) {
            $notes[] = 'contact_name חסר — לא נוצר איש קשר עבור פרטי הקשר שסופקו בשורה.';
        }

        // ----- Customer conversion (idempotent — a lead already converted reuses its customer) -----
        $isCustomer = $this->parseBool($row['is_customer']);
        $customer = null;

        if ($isCustomer) {
            $customer = $lead->customer;

            if ($customer) {
                $this->summary['customers_reused']++;
            } else {
                if (! $convertedDate) {
                    $notes[] = 'converted_date חסר — נעשה שימוש בתאריך העסקה (אם קיים) או בתאריך הנוכחי כברירת מחדל.';
                }

                $customer = $lead->convertToCustomer($activityLogger, $convertedDate ?? $dealDate ?? now());
                $this->summary['customers_created']++;
            }
        }

        // ----- Deal (only when a program name is given and resolves to a real catalog program) -----
        $programName = $row['program_name'];

        if ($programName !== '') {
            if (! $customer) {
                $notes[] = "לא נוצרה עסקה: שם תוכנית (\"{$programName}\") סופק אך is_customer אינו מסומן — עסקה דורשת לקוחה (FR-3.2).";
                $this->summary['deals_skipped']++;
            } else {
                $program = Program::query()
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($programName)])
                    ->first();

                if (! $program) {
                    $notes[] = "לא נמצאה תוכנית תואמת בקטלוג עבור \"{$programName}\" — עסקה לא נוצרה.";
                    $this->summary['deals_skipped']++;
                } else {
                    $resolvedDealDate = $dealDate ?? $convertedDate ?? now();

                    $existingDeal = Deal::where('customer_id', $customer->id)
                        ->where('program_id', $program->id)
                        ->whereDate('purchased_at', $resolvedDealDate->toDateString())
                        ->first();

                    if ($existingDeal) {
                        $this->summary['deals_reused']++;
                    } else {
                        $amount = $row['deal_amount'] !== '' ? (float) $row['deal_amount'] : null;

                        $deal = Deal::createForCustomer($customer, $program, null, $amount, null, null, $resolvedDealDate);

                        $activityLogger->log('deal.created', self::IMPORT_MARKER.": נוצרה עסקה היסטורית עבור לקוחה \"{$school->name}\": {$program->name}", [
                            'deal_id' => $deal->id, 'customer_id' => $customer->id, 'occurred_at' => $resolvedDealDate,
                        ]);

                        $this->summary['deals_created']++;
                    }
                }
            }
        }

        $this->summary['rows_ok']++;
        $status = $notes === [] ? 'תקין' : 'תקין עם הערות';
        $this->rowReports[] = "שורה {$lineNumber} ({$schoolName}): {$status}".($notes !== [] ? ' — '.implode(' ', $notes) : '');
    }

    /**
     * FR-8.21 judgment call: a blank or unmatched lead_source falls back to a
     * single shared "הגירת נתונים" source rather than auto-creating one new
     * LeadSource row per distinct unmatched string in the file.
     */
    private function resolveLeadSource(string $name): LeadSource
    {
        $trimmed = trim($name);

        if ($trimmed !== '') {
            $match = LeadSource::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmed)])
                ->first();

            if ($match) {
                return $match;
            }
        }

        return LeadSource::firstOrCreate(
            ['name' => self::DEFAULT_LEAD_SOURCE_NAME],
            ['is_active' => true],
        );
    }

    private function parseBool(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['כן', 'yes', 'y', '1', 'true'], true);
    }

    private function parseDate(string $value): ?Carbon
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::parse($trimmed)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function printAndWriteReport(bool $dryRun, string $path): void
    {
        $this->newLine();
        $this->info($dryRun
            ? 'ריצת בדיקה (dry-run) הושלמה — לא בוצע אף שינוי במסד הנתונים.'
            : 'הייבוא הושלם.');

        $this->table(['מדד', 'ערך'], [
            ['בתי ספר — נוצרו', $this->summary['schools_created']],
            ['בתי ספר — קיימים (שימוש חוזר)', $this->summary['schools_reused']],
            ['לידים — נוצרו', $this->summary['leads_created']],
            ['לידים — קיימים (שימוש חוזר)', $this->summary['leads_reused']],
            ['אנשי קשר — נוצרו', $this->summary['contacts_created']],
            ['אנשי קשר — קיימים (שימוש חוזר)', $this->summary['contacts_reused']],
            ['לקוחות — נוצרו (המרה מליד)', $this->summary['customers_created']],
            ['לקוחות — קיימים (שימוש חוזר)', $this->summary['customers_reused']],
            ['עסקאות — נוצרו', $this->summary['deals_created']],
            ['עסקאות — קיימות (שימוש חוזר)', $this->summary['deals_reused']],
            ['עסקאות — דולגו (שם תוכנית לא תואם / חסרה לקוחה)', $this->summary['deals_skipped']],
            ['שורות שהושלמו בהצלחה', $this->summary['rows_ok']],
            ['שורות עם שגיאה (דולגו לגמרי)', $this->summary['rows_error']],
        ]);

        $reportDirectory = storage_path('app/legacy-import-reports');
        File::ensureDirectoryExists($reportDirectory);

        $reportPath = $reportDirectory.'/'.now()->format('Y-m-d_His').($dryRun ? '_dry-run' : '').'.txt';

        $lines = [
            'דוח ייבוא נתונים היסטוריים (legacy:import) — FR-8.21',
            'קובץ מקור: '.$path,
            'מועד ריצה: '.now()->toDateTimeString(),
            'מצב: '.($dryRun ? 'בדיקה (dry-run) — לא בוצעו שינויים במסד הנתונים.' : 'ריצה בפועל.'),
            '',
            '--- סיכום ---',
        ];

        foreach ($this->summary as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }

        $lines[] = '';
        $lines[] = '--- פירוט שורות ---';
        array_push($lines, ...$this->rowReports);

        File::put($reportPath, implode(PHP_EOL, $lines).PHP_EOL);

        $this->line("דוח מפורט נשמר ב: {$reportPath}");
    }
}
