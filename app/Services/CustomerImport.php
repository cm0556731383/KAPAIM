<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\MailingMembership;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Rules\Phone;
use App\Services\Integrations\SmoveClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Throwable;

/**
 * Customer import from Excel (settings → יבוא נתונים): the blank template to
 * fill, the check of a filled file against the same rules the customer card
 * applies, and the import itself.
 *
 * One row = one customer: its school card, its primary contact and,
 * optionally, its accounting contact. A customer only ever exists as a
 * converted lead (Lead::convertToCustomer()), so each row opens a lead from
 * the primary contact's email and phone — which, like a lead, are required —
 * and converts it at once.
 *
 * A school already in the system (by name or ח.פ.), as a lead or a customer,
 * is reported and never imported: the import only adds, it never changes an
 * existing card.
 *
 * Smove: the system's Smove list is read first (smoveList()). A contact whose
 * email is already on it is LINKED (contacts.smove_contact_id) and never
 * pushed — a join would reset a confirmed contact's consent or resubscribe
 * one who unsubscribed. A contact not on it joins the list as any new
 * customer does. If the list can't be read, nothing is pushed at all.
 */
class CustomerImport
{
    public const MAX_ROWS = 2000;

    public const SHEET_TITLE = 'לקוחות';

    /** Boolean cells: what counts as yes / no. */
    private const YES = ['כן', 'yes', 'y', 'true', '1', 'v', '✓'];

    private const NO = ['לא', 'no', 'n', 'false', '0', ''];

    /**
     * The template's columns, in order: key => [header, required, help].
     * The header is how a column is found in an uploaded file, so the
     * columns may be reordered (or optional ones removed) freely.
     */
    public const COLUMNS = [
        'school_name' => ['שם המוסד', true, 'שם בית הספר / המוסד, כפי שיופיע בכרטיס הלקוח.'],
        'city' => ['עיר', false, ''],
        'address' => ['כתובת', false, ''],
        'school_phone' => ['טלפון המוסד', false, 'לפחות 7 ספרות. מותר + - ( ) ורווחים.'],
        'school_email' => ['דוא"ל המוסד', false, 'כתובת דוא"ל תקינה.'],
        'invoice_name' => ['שם לחשבונית', false, 'אם שונה משם המוסד.'],
        'business_number' => ['ח.פ.', false, 'ספרות בלבד (מותרים מקפים ורווחים), עד 20 תווים.'],
        'status' => ['סטטוס לקוחה', false, 'בחירה מהרשימה. ריק = פעילה.'],
        'lead_source' => ['מקור הגעה', false, 'בחירה מהרשימה (מקורות הליד שבהגדרות).'],
        'converted_at' => ['תאריך הצטרפות', false, 'תאריך בפורמט 31/12/2025. ריק = היום.'],
        'syllable' => ['הברה', false, ''],
        'classes_per_grade' => ['מספר כיתות בשנתון', false, 'מספר שלם, 0 ומעלה.'],
        'ownership_name' => ['שם הבעלות', false, ''],
        'gefen_budget' => ['תקציב גפן', false, 'כן / לא. ריק = לא.'],
        'content_format' => ['פורמט תוכן', false, 'וידאו / שקופיות.'],
        'notes' => ['הערות', false, ''],
        'contact_name' => ['איש קשר ראשי — שם', true, 'שם מלא.'],
        'contact_role' => ['איש קשר ראשי — תפקיד', false, ''],
        'contact_phone' => ['איש קשר ראשי — טלפון', true, 'לפחות 7 ספרות.'],
        'contact_phone_secondary' => ['איש קשר ראשי — טלפון נוסף', false, ''],
        'contact_email' => ['איש קשר ראשי — דוא"ל', true, 'כתובת דוא"ל תקינה.'],
        'contact_email_secondary' => ['איש קשר ראשי — דוא"ל נוסף', false, ''],
        'accounting_name' => ['הנהלת חשבונות — שם', false, 'חובה אם מולא פרט כלשהו של איש הקשר להנהלת חשבונות.'],
        'accounting_role' => ['הנהלת חשבונות — תפקיד', false, ''],
        'accounting_phone' => ['הנהלת חשבונות — טלפון', false, ''],
        'accounting_email' => ['הנהלת חשבונות — דוא"ל', false, ''],
    ];

    private const PHONE_COLUMNS = ['school_phone', 'contact_phone', 'contact_phone_secondary', 'accounting_phone'];

    private const TEXT_COLUMNS = ['school_phone', 'contact_phone', 'contact_phone_secondary', 'accounting_phone', 'business_number'];

    private const ACCOUNTING_COLUMNS = ['accounting_name', 'accounting_role', 'accounting_phone', 'accounting_email'];

    // ===== The blank template =====

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('יבוא לקוחות — כפיים');

        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::SHEET_TITLE);
        $sheet->setRightToLeft(true);

        $lastRow = self::MAX_ROWS + 1;
        $index = 0;

        foreach (self::COLUMNS as $key => [$header, $required, $help]) {
            $letter = Coordinate::stringFromColumnIndex(++$index);
            $sheet->setCellValue("{$letter}1", $required ? "{$header} *" : $header);
            $sheet->getColumnDimension($letter)->setWidth(max(14, mb_strlen($header) + 6));

            $style = $sheet->getStyle("{$letter}1");
            $style->getFont()->setBold(true);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($required ? 'F6D5C8' : 'E8EEF4');
            $style->getAlignment()->setWrapText(true)->setVertical('center');

            if ($help !== '') {
                $sheet->getComment("{$letter}1")->getText()->createTextRun($help);
            }

            if (in_array($key, self::TEXT_COLUMNS, true)) {
                $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            if ($key === 'converted_at') {
                $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->freezePane('A2');

        $this->addPickLists($book, $lastRow);
        $this->addInstructions($book);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** Drop-down lists, read from a hidden sheet so a long list of lead sources still fits. */
    private function addPickLists(Spreadsheet $book, int $lastRow): void
    {
        $lists = [
            'status' => self::customerStatuses()->all(),
            'lead_source' => self::leadSources()->all(),
            'gefen_budget' => ['כן', 'לא'],
            'content_format' => array_values(School::CONTENT_FORMATS),
        ];

        $listSheet = $book->createSheet();
        $listSheet->setTitle('רשימות');
        $listSheet->setSheetState('hidden');

        $sheet = $book->getSheet(0);
        $listColumn = 0;

        foreach ($lists as $key => $values) {
            if ($values === []) {
                continue;
            }

            $listLetter = Coordinate::stringFromColumnIndex(++$listColumn);

            foreach (array_values($values) as $i => $value) {
                $listSheet->setCellValueExplicit($listLetter.($i + 1), $value, 's');
            }

            $letter = self::columnLetter($key);
            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('ערך לא מהרשימה')
                ->setError('יש לבחור ערך מהרשימה.')
                ->setFormula1("'רשימות'!\${$listLetter}\$1:\${$listLetter}\$".count($values));

            $sheet->setDataValidation("{$letter}2:{$letter}{$lastRow}", $validation);
        }
    }

    private function addInstructions(Spreadsheet $book): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('הוראות');
        $sheet->setRightToLeft(true);

        $lines = [
            ['יבוא לקוחות — הוראות מילוי'],
            [''],
            ['• כל שורה בגיליון "לקוחות" היא לקוחה אחת. אין לשנות את שורת הכותרות.'],
            ['• עמודות המסומנות ב־* (ובצבע כתום) הן חובה.'],
            ['• בית ספר שכבר קיים במערכת (כליד או כלקוחה, לפי שם או ח.פ.) לא ייובא, וכך גם שורה כפולה בקובץ.'],
            ['• לפני היבוא המערכת בודקת את כל הקובץ ומציגה את השגיאות לפי מספר שורה. אפשר לתקן ולהעלות שוב.'],
            ['• אנשי קשר נוספים, עסקאות ומסמכים מוסיפים אחר כך בכרטיס הלקוח.'],
            [''],
            ['עמודה', 'חובה', 'הסבר'],
        ];

        foreach (self::COLUMNS as [$header, $required, $help]) {
            $lines[] = [$header, $required ? 'כן' : '', $help];
        }

        $sheet->fromArray($lines, null, 'A1', true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A9:C9')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(8);
        $sheet->getColumnDimension('C')->setWidth(70);
    }

    // ===== Checking an uploaded file =====

    /**
     * Reads and checks a filled file. Returns:
     *   'fileError' => a reason the file can't be read at all, else null
     *   'rows'      => [['row' => sheet row number, 'school' => name, 'data' => normalized values, 'errors' => [column header => message]]]
     */
    public function analyze(string $path): array
    {
        $book = null;

        try {
            return $this->analyzeFile($path, $book);
        } finally {
            // PhpSpreadsheet's objects reference each other — free them explicitly.
            $book?->disconnectWorksheets();
        }
    }

    private function analyzeFile(string $path, ?Spreadsheet &$book): array
    {
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheet(0);
        } catch (Throwable) {
            return ['fileError' => 'לא ניתן לקרוא את הקובץ. יש להעלות קובץ אקסל (xlsx) שהורד מכאן ומולא.', 'rows' => []];
        }

        // Find each column by its header (a trailing "*" and spaces ignored).
        $columnOf = [];
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($i = 1; $i <= $highestColumn; $i++) {
            $header = self::normalizeHeader((string) $sheet->getCell([$i, 1])->getValue());

            foreach (self::COLUMNS as $key => [$label]) {
                if ($header === self::normalizeHeader($label)) {
                    $columnOf[$key] = $i;
                }
            }
        }

        $missing = collect(self::COLUMNS)
            ->filter(fn ($column, $key) => $column[1] && ! isset($columnOf[$key]))
            ->map(fn ($column) => $column[0]);

        if ($missing->isNotEmpty()) {
            return ['fileError' => 'בקובץ חסרות עמודות חובה: '.$missing->implode(', ').'. יש להשתמש בקובץ שהורד מכאן.', 'rows' => []];
        }

        $rows = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($r = 2; $r <= $highestRow; $r++) {
            $values = [];

            foreach (array_keys(self::COLUMNS) as $key) {
                $values[$key] = isset($columnOf[$key]) ? $this->cellValue($sheet->getCell([$columnOf[$key], $r]), $key) : '';
            }

            if (collect($values)->every(fn ($v) => $v === '')) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                return ['fileError' => 'בקובץ יותר מ־'.self::MAX_ROWS.' שורות. יש לפצל אותו לכמה קבצים.', 'rows' => []];
            }

            $rows[] = ['row' => $r, 'school' => $values['school_name'], 'raw' => $values];
        }

        if ($rows === []) {
            return ['fileError' => 'לא נמצאו בקובץ שורות למילוי.', 'rows' => []];
        }

        return ['fileError' => null, 'rows' => $this->checkRows($rows)];
    }

    /** Excel cells as the plain strings the rules expect. */
    private function cellValue($cell, string $key): string
    {
        $value = $cell->getValue();

        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }

        if ($key === 'converted_at' && is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('d/m/Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        if (is_float($value) && floor($value) === $value) {
            $value = number_format($value, 0, '', '');
        }

        $value = trim((string) $value);

        // A phone typed into a number cell loses its leading 0 (0501234567 → 501234567).
        if (in_array($key, self::PHONE_COLUMNS, true) && is_numeric($cell->getValue()) && preg_match('/^[1-9]\d{7,8}$/', $value)) {
            $value = '0'.$value;
        }

        return $value;
    }

    private function checkRows(array $rows): array
    {
        $statuses = self::customerStatuses();
        $sources = self::leadSources();
        $formats = array_flip(School::CONTENT_FORMATS);

        $existingNames = School::query()->pluck('name')->mapWithKeys(fn ($name) => [Lead::normalizeName($name) => true]);
        $existingNumbers = School::query()->whereNotNull('business_number')->pluck('business_number')
            ->mapWithKeys(fn ($number) => [self::digits($number) => true])->forget('');

        $seenNames = [];
        $seenNumbers = [];

        foreach ($rows as &$row) {
            $v = $row['raw'];
            $v['gefen_budget'] = mb_strtolower($v['gefen_budget']);
            $errors = [];

            $validator = Validator::make($v, [
                'school_name' => ['required', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
                'school_phone' => ['nullable', 'string', 'max:50', new Phone],
                'school_email' => ['nullable', 'email', 'max:255'],
                'invoice_name' => ['nullable', 'string', 'max:255'],
                'business_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9][0-9\s-]*$/'],
                'status' => ['nullable', Rule::in($statuses->all())],
                'lead_source' => ['nullable', Rule::in($sources->all())],
                'converted_at' => ['nullable', function ($attribute, $value, $fail) {
                    $date = self::parseDate((string) $value);

                    if (! $date) {
                        $fail('תאריך לא תקין — יש לכתוב בפורמט 31/12/2025.');
                    } elseif ($date->isAfter(today())) {
                        $fail('תאריך עתידי.');
                    }
                }],
                'syllable' => ['nullable', 'string', 'max:255'],
                'classes_per_grade' => ['nullable', 'integer', 'min:0'],
                'ownership_name' => ['nullable', 'string', 'max:255'],
                'gefen_budget' => ['nullable', Rule::in(array_merge(self::YES, self::NO))],
                'content_format' => ['nullable', Rule::in(array_keys($formats))],
                'notes' => ['nullable', 'string'],
                'contact_name' => ['required', 'string', 'max:255'],
                'contact_role' => ['nullable', 'string', 'max:255'],
                'contact_phone' => ['required', 'string', 'max:50', new Phone],
                'contact_phone_secondary' => ['nullable', 'string', 'max:50', new Phone],
                'contact_email' => ['required', 'email', 'max:255'],
                'contact_email_secondary' => ['nullable', 'email', 'max:255'],
                'accounting_name' => [Rule::requiredIf(fn () => collect(self::ACCOUNTING_COLUMNS)->contains(fn ($k) => $v[$k] !== '')), 'nullable', 'string', 'max:255'],
                'accounting_role' => ['nullable', 'string', 'max:255'],
                'accounting_phone' => ['nullable', 'string', 'max:50', new Phone],
                'accounting_email' => ['nullable', 'email', 'max:255'],
            ], [
                'required' => 'שדה חובה — חסר.',
                'email' => 'כתובת דוא"ל לא תקינה.',
                'max' => 'ארוך מדי (עד :max תווים).',
                'integer' => 'צריך להיות מספר שלם.',
                'min' => 'צריך להיות 0 ומעלה.',
                'in' => 'ערך לא מהרשימה.',
                'business_number.regex' => 'ח.פ. יכול להכיל ספרות בלבד (ומקפים).',
                'gefen_budget.in' => 'יש לכתוב כן או לא.',
                'content_format.in' => 'יש לבחור וידאו או שקופיות.',
            ]);

            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors[self::COLUMNS[$key][0]] = $messages[0];
            }

            $name = Lead::normalizeName($v['school_name']);
            $number = self::digits($v['business_number']);

            if ($name !== '') {
                if (isset($existingNames[$name])) {
                    $errors[self::COLUMNS['school_name'][0]] = 'בית ספר בשם זה כבר קיים במערכת — לא ייובא.';
                } elseif (isset($seenNames[$name])) {
                    $errors[self::COLUMNS['school_name'][0]] = "מופיע כבר בשורה {$seenNames[$name]} בקובץ.";
                }

                $seenNames[$name] ??= $row['row'];
            }

            if ($number !== '' && ! isset($errors[self::COLUMNS['business_number'][0]])) {
                if (isset($existingNumbers[$number])) {
                    $errors[self::COLUMNS['business_number'][0]] = 'ח.פ. זה כבר קיים במערכת — לא ייובא.';
                } elseif (isset($seenNumbers[$number])) {
                    $errors[self::COLUMNS['business_number'][0]] = "ח.פ. זה מופיע כבר בשורה {$seenNumbers[$number]} בקובץ.";
                }

                $seenNumbers[$number] ??= $row['row'];
            }

            $row['errors'] = $errors;
            $row['data'] = $errors === [] ? $this->normalize($v, $formats) : null;
            unset($row['raw']);
        }

        return $rows;
    }

    private function normalize(array $v, array $formats): array
    {
        $blank = fn (string $value) => $value === '' ? null : $value;

        return [
            'school' => [
                'name' => trim($v['school_name']),
                'city' => $blank($v['city']),
                'address' => $blank($v['address']),
                'phone' => $blank($v['school_phone']),
                'email' => $blank($v['school_email']),
                'invoice_name' => $blank($v['invoice_name']),
                'business_number' => $blank($v['business_number']),
                'syllable' => $blank($v['syllable']),
                'classes_per_grade' => $v['classes_per_grade'] === '' ? null : (int) $v['classes_per_grade'],
                'ownership_name' => $blank($v['ownership_name']),
                'gefen_budget' => in_array($v['gefen_budget'], self::YES, true),
                'content_format' => $v['content_format'] === '' ? null : $formats[$v['content_format']],
                'notes' => $blank($v['notes']),
            ],
            'status' => $blank($v['status']),
            'lead_source' => $blank($v['lead_source']),
            'converted_at' => $v['converted_at'] === '' ? null : self::parseDate($v['converted_at'])->toDateString(),
            'contact' => [
                'name' => $v['contact_name'],
                'role' => $blank($v['contact_role']),
                'phone' => $v['contact_phone'],
                'phone_secondary' => $blank($v['contact_phone_secondary']),
                'email' => $v['contact_email'],
                'email_secondary' => $blank($v['contact_email_secondary']),
            ],
            'accounting' => $v['accounting_name'] === '' ? null : [
                'name' => $v['accounting_name'],
                'role' => $blank($v['accounting_role']),
                'phone' => $blank($v['accounting_phone']),
                'email' => $blank($v['accounting_email']),
            ],
        ];
    }

    // ===== The import =====

    /**
     * Imports the rows that passed analyze(). Each row is its own
     * transaction, so one failing row never undoes the others. A school that
     * appeared in the meantime is skipped, not duplicated.
     *
     * @param  callable(int $done): void|null  $progress
     * @return array{created: list<int>, failed: list<array{row: int, school: string, error: string}>}
     */
    public function import(array $rows, User $user, ActivityLogger $activityLogger, ?callable $progress = null, ?array $smove = null): array
    {
        $smove ??= ['state' => 'off', 'members' => []];
        $created = [];
        $failed = [];
        $this->smoveStats = ['linked' => 0, 'not_receiving' => 0, 'joined' => 0];

        foreach (array_values(array_filter($rows, fn ($row) => $row['data'] !== null)) as $i => $row) {
            try {
                $created[] = $this->importRow($row['data'], $user, $activityLogger, $smove)->id;
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['row' => $row['row'], 'school' => $row['school'], 'error' => $e instanceof \RuntimeException ? $e->getMessage() : 'שגיאה לא צפויה בשמירה.'];
            }

            if ($progress) {
                $progress($i + 1);
            }
        }

        $activityLogger->log('customers.imported', 'יובאו '.count($created).' לקוחות מקובץ אקסל'.($failed ? ' ('.count($failed).' נכשלו)' : '')
            .($smove['state'] === 'ok' ? " — {$this->smoveStats['linked']} אנשי קשר קושרו לרשימת Smove, {$this->smoveStats['joined']} צורפו אליה" : ''), [
            'user' => $user,
            'metadata' => ['created' => count($created), 'failed' => count($failed), 'smove' => $smove['state']] + $this->smoveStats,
        ]);

        return ['created' => $created, 'failed' => $failed, 'smove' => $this->smoveStats];
    }

    private array $smoveStats = ['linked' => 0, 'not_receiving' => 0, 'joined' => 0];

    /**
     * The system's Smove list, read once before a check or an import:
     *   state 'off'   — Smove isn't connected (nothing to link; joins behave as everywhere else)
     *         'error' — connected but the list couldn't be read (message says why)
     *         'ok'    — members = SmoveClient::listContacts()
     */
    public function smoveList(): array
    {
        // Checked first: SmoveClient would otherwise create the settings row, a
        // write a view-only user's check isn't allowed (ReadOnlyGuard).
        if (! \App\Models\ExternalIntegrationSetting::where('system', 'smove')->exists()) {
            return ['state' => 'off', 'members' => []];
        }

        $smove = app(SmoveClient::class);

        if (! $smove->isConfigured() || ! $smove->listId()) {
            return ['state' => 'off', 'members' => []];
        }

        try {
            return ['state' => 'ok', 'members' => $smove->listContacts($smove->listId())];
        } catch (Throwable $e) {
            return ['state' => 'error', 'members' => [], 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'לא ניתן לקרוא את רשימת התפוצה מ-Smove.'];
        }
    }

    /** For the check screen: how the valid rows' contacts match the Smove list. */
    public function smovePreview(array $rows, array $smove): array
    {
        $preview = ['state' => $smove['state'], 'message' => $smove['message'] ?? null, 'linked' => 0, 'not_receiving' => 0, 'new' => 0];

        foreach ($rows as $row) {
            foreach (self::contactEmails($row['data']) as $email) {
                $member = $smove['members'][$email] ?? null;

                if (! $member) {
                    $preview['new']++;
                } else {
                    $preview['linked']++;
                    $preview['not_receiving'] += $member['can_receive'] ? 0 : 1;
                }
            }
        }

        return $preview;
    }

    /** The lower-cased emails of a row's contacts (primary + accounting), once each. */
    private static function contactEmails(?array $data): array
    {
        if (! $data) {
            return [];
        }

        return array_values(array_unique(array_filter([
            mb_strtolower(trim((string) $data['contact']['email'])),
            mb_strtolower(trim((string) ($data['accounting']['email'] ?? ''))),
        ])));
    }

    private function importRow(array $data, User $user, ActivityLogger $activityLogger, array $smove): Customer
    {
        $customer = DB::transaction(function () use ($data, $user) {
            if (Lead::findExactDuplicateSchool($data['school']['name'])) {
                throw new \RuntimeException('בית ספר בשם זה נוסף למערכת בינתיים — לא יובא.');
            }

            $school = School::create($data['school']);

            Contact::create($data['contact'] + ['school_id' => $school->id, 'is_primary' => true, 'is_accounting_contact' => false]);

            if ($data['accounting']) {
                Contact::create($data['accounting'] + ['school_id' => $school->id, 'is_primary' => false, 'is_accounting_contact' => true]);
            }

            $newStatus = StatusDefinition::firstOrCreate(
                ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
                ['is_active' => true, 'sort_order' => 1],
            );

            return Lead::create([
                'school_id' => $school->id,
                'assigned_user_id' => $user->id,
                'lead_source_id' => $data['lead_source'] ? LeadSource::where('name', $data['lead_source'])->value('id') : null,
                'status_id' => $newStatus->id,
                'email' => $data['contact']['email'],
                'phone' => $data['contact']['phone'],
            ]);
        });

        // Converting also files the contacts under the customer and joins it
        // to the mailing list — pushing to Smove only the contacts not already
        // on its list (none at all when the list couldn't be read).
        $convertedAt = $data['converted_at'] ? Carbon::parse($data['converted_at']) : null;
        $emails = self::contactEmails($data);
        $onList = array_values(array_filter($emails, fn ($email) => isset($smove['members'][$email])));
        $noPush = $smove['state'] === 'error' ? $emails : $onList;

        $result = MailingMembership::withoutPushing($noPush, fn () => $customer->convertToCustomer($activityLogger, $convertedAt));

        foreach ($onList as $email) {
            $member = $smove['members'][$email];
            $result->contacts()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->update(['smove_contact_id' => $member['id']]);
            $this->smoveStats['linked']++;
            $this->smoveStats['not_receiving'] += $member['can_receive'] ? 0 : 1;
        }

        if ($smove['state'] === 'ok') {
            $this->smoveStats['joined'] += count($emails) - count($onList);
        }

        if ($data['status'] && $data['status'] !== Customer::DEFAULT_STATUS_NAME) {
            $statusId = StatusDefinition::where('scope', 'customer')->where('name', $data['status'])->value('id');
            $result->update(['status_id' => $statusId ?? $result->status_id]);
        }

        return $result;
    }

    // ===== Helpers =====

    public static function customerStatuses()
    {
        return StatusDefinition::where('scope', 'customer')->where('is_active', true)->orderBy('sort_order')->pluck('name')
            ->whenEmpty(fn ($names) => collect([Customer::DEFAULT_STATUS_NAME]));
    }

    public static function leadSources()
    {
        return LeadSource::where('is_active', true)->orderBy('name')->pluck('name');
    }

    public static function columnLetter(string $key): string
    {
        return Coordinate::stringFromColumnIndex(array_search($key, array_keys(self::COLUMNS), true) + 1);
    }

    private static function normalizeHeader(string $header): string
    {
        return preg_replace('/\s+/u', ' ', trim(str_replace('*', '', $header)));
    }

    private static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private static function parseDate(string $value): ?Carbon
    {
        foreach (['d/m/Y', 'j/n/Y', 'd.m.Y', 'j.n.Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }
}
