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
 * One row = one customer: its school card and up to CONTACTS contacts, each
 * with its own כן/לא for "ראשי" and "גורם חשבונאי" (2026-11-17). At least one
 * contact (contact 1) is required; when no contact is marked ראשי, contact 1
 * is. A customer only ever exists as a converted lead
 * (Lead::convertToCustomer()), so each row opens a lead from the first
 * primary contact's email and phone — which, like a lead's, are required for
 * every primary contact — and converts it at once.
 *
 * A school already in the system (by name or ח.פ.), as a lead or a customer,
 * is reported and never imported: the import only adds, it never changes an
 * existing card.
 *
 * Smove: each contact email is first looked up on the system's Smove list
 * (smoveList()). One found there is LINKED (contacts.smove_contact_id) and
 * never pushed to Smove — a join would reset a confirmed contact's consent or
 * resubscribe one who unsubscribed. One not found (2026-11-18, business
 * owner's rule — it used to be a row error) is listed by the check and opened
 * only once the user approves; it then joins the Smove list like any new
 * contact. When the list can't be read (or Smove isn't connected) the whole
 * file is refused, since nothing could be linked.
 */
class CustomerImport
{
    public const MAX_ROWS = 2000;

    public const SHEET_TITLE = 'לקוחות';

    /** Boolean cells: what counts as yes / no. */
    private const YES = ['כן', 'yes', 'y', 'true', '1', 'v', '✓'];

    private const NO = ['לא', 'no', 'n', 'false', '0', ''];

    /** How many contact column groups the template has. */
    public const CONTACTS = 3;

    /** School / customer columns: key => [header, required, help]. The contact groups follow (columns()). */
    private const SCHOOL_COLUMNS = [
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
    ];

    /** Each contact group's fields: field => [header suffix, help]. Keys are "contact{n}_{field}". */
    private const CONTACT_FIELDS = [
        'name' => ['שם', 'שם מלא. חובה אם מולא פרט כלשהו של איש הקשר.'],
        'role' => ['תפקיד', ''],
        'phone' => ['טלפון', 'לפחות 7 ספרות. חובה לאיש קשר ראשי.'],
        'phone_secondary' => ['טלפון נוסף', ''],
        'email' => ['דוא"ל', 'כתובת דוא"ל תקינה. חובה לאיש קשר ראשי. אם הכתובת ברשימת התפוצה ב־Smove — איש הקשר יקושר אליה; אם לא — המערכת תבקש אישור לפתוח אותו.'],
        'email_secondary' => ['דוא"ל נוסף', ''],
        'primary' => ['ראשי', 'כן / לא. אפשר יותר מאחד. אם אף איש קשר לא סומן — איש קשר 1 יהיה הראשי.'],
        'accounting' => ['גורם חשבונאי', 'כן / לא. גורם חשבונאי שאינו ראשי מקבל רק דואר הנהלת חשבונות (חשבוניות, קבלות, קישורי תשלום).'],
    ];

    /** Contact fields that are a phone / a yes-no choice. */
    private const CONTACT_PHONE_FIELDS = ['phone', 'phone_secondary'];

    private const CONTACT_FLAG_FIELDS = ['primary', 'accounting'];

    /**
     * The template's columns, in order: key => [header, required, help].
     * The header is how a column is found in an uploaded file, so the
     * columns may be reordered (or optional ones removed) freely.
     */
    public static function columns(): array
    {
        $columns = self::SCHOOL_COLUMNS;

        for ($n = 1; $n <= self::CONTACTS; $n++) {
            foreach (self::CONTACT_FIELDS as $field => [$label, $help]) {
                $columns["contact{$n}_{$field}"] = ["איש קשר {$n} — {$label}", $n === 1 && $field === 'name', $help];
            }
        }

        return $columns;
    }

    private static function phoneColumns(): array
    {
        $keys = ['school_phone'];

        for ($n = 1; $n <= self::CONTACTS; $n++) {
            foreach (self::CONTACT_PHONE_FIELDS as $field) {
                $keys[] = "contact{$n}_{$field}";
            }
        }

        return $keys;
    }

    private static function textColumns(): array
    {
        return [...self::phoneColumns(), 'business_number'];
    }

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

        foreach (self::columns() as $key => [$header, $required, $help]) {
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

            if (in_array($key, self::textColumns(), true)) {
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

        for ($n = 1; $n <= self::CONTACTS; $n++) {
            foreach (self::CONTACT_FLAG_FIELDS as $field) {
                $lists["contact{$n}_{$field}"] = ['כן', 'לא'];
            }
        }

        $listSheet = $book->createSheet();
        $listSheet->setTitle('רשימות');
        $listSheet->setSheetState('hidden');

        $sheet = $book->getSheet(0);
        $listColumn = 0;

        $listLetters = [];

        foreach ($lists as $key => $values) {
            if ($values === []) {
                continue;
            }

            // Identical lists (the many כן/לא columns) share one column of the hidden sheet.
            $listLetter = $listLetters[implode("\n", $values)] ??= Coordinate::stringFromColumnIndex(++$listColumn);

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
            ['• כל כתובת דוא"ל של איש קשר נבדקת מול רשימת התפוצה ב־Smove: איש קשר שנמצא שם יקושר אליה. איש קשר שלא נמצא — המערכת תציג אותו ותבקש אישור לפתוח אותו, ואז הוא יצורף לרשימה.'],
            ['• לפני היבוא המערכת בודקת את כל הקובץ ומציגה את השגיאות לפי מספר שורה. אפשר לתקן ולהעלות שוב.'],
            ['• אפשר למלא עד '.self::CONTACTS.' אנשי קשר לכל לקוחה (איש קשר 1 חובה). לכל אחד כותבים כן / לא ב"ראשי" וב"גורם חשבונאי".'],
            ['• אם אף איש קשר לא סומן "ראשי" — איש קשר 1 יהיה הראשי. לאיש קשר ראשי חובה טלפון ודוא"ל.'],
            ['• אנשי קשר נוספים, עסקאות ומסמכים מוסיפים אחר כך בכרטיס הלקוח.'],
            [''],
            ['עמודה', 'חובה', 'הסבר'],
        ];

        $headerRow = count($lines);

        foreach (self::columns() as [$header, $required, $help]) {
            $lines[] = [$header, $required ? 'כן' : '', $help];
        }

        $sheet->fromArray($lines, null, 'A1', true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A{$headerRow}:C{$headerRow}")->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(8);
        $sheet->getColumnDimension('C')->setWidth(70);
    }

    // ===== Checking an uploaded file =====

    /**
     * Reads and checks a filled file. Returns:
     *   'fileError' => a reason the file can't be imported at all, else null
     *   'rows'      => [['row' => sheet row number, 'school' => name, 'data' => normalized values, 'errors' => [column header => message], 'warnings' => [column header => message]]]
     *   'smove'     => the Smove list it was checked against (smoveList())
     */
    public function analyze(string $path, ?array $smove = null): array
    {
        $book = null;

        try {
            $result = $this->analyzeFile($path, $book, $smove);

            return $result + ['smove' => $smove ?? ['state' => 'off', 'members' => []]];
        } finally {
            // PhpSpreadsheet's objects reference each other — free them explicitly.
            $book?->disconnectWorksheets();
        }
    }

    private function analyzeFile(string $path, ?Spreadsheet &$book, ?array &$smove): array
    {
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheet(0);
        } catch (Throwable) {
            return ['fileError' => 'לא ניתן לקרוא את הקובץ. יש להעלות קובץ אקסל (xlsx) שהורד מכאן ומולא.', 'rows' => []];
        }

        // Find each column by its header (a trailing "*" and spaces ignored).
        $columns = self::columns();
        $columnOf = [];
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($i = 1; $i <= $highestColumn; $i++) {
            $header = self::normalizeHeader((string) $sheet->getCell([$i, 1])->getValue());

            foreach ($columns as $key => [$label]) {
                if ($header === self::normalizeHeader($label)) {
                    $columnOf[$key] = $i;
                }
            }
        }

        $missing = collect($columns)
            ->filter(fn ($column, $key) => $column[1] && ! isset($columnOf[$key]))
            ->map(fn ($column) => $column[0]);

        if ($missing->isNotEmpty()) {
            return ['fileError' => 'בקובץ חסרות עמודות חובה: '.$missing->implode(', ').'. יש להשתמש בקובץ שהורד מכאן.', 'rows' => []];
        }

        $rows = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($r = 2; $r <= $highestRow; $r++) {
            $values = [];

            foreach (array_keys($columns) as $key) {
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

        // Every contact must be found on the Smove list, so without the list nothing can be imported.
        $smove ??= $this->smoveList();

        if ($smove['state'] === 'off') {
            return ['fileError' => 'Smove לא מחובר (או שלא הוגדר מזהה רשימה), ולכן אי אפשר לבדוק שאנשי הקשר נמצאים ברשימת התפוצה. יש לחבר את Smove בהגדרות ← אינטגרציות ולהעלות את הקובץ שוב.', 'rows' => []];
        }

        if ($smove['state'] === 'error') {
            return ['fileError' => $smove['message'].' לכן אי אפשר לבדוק שאנשי הקשר נמצאים ברשימת התפוצה. יש לנסות שוב בעוד כמה דקות, או לבדוק את החיבור בהגדרות ← אינטגרציות.', 'rows' => []];
        }

        return ['fileError' => null, 'rows' => $this->checkRows($rows, $smove['members'])];
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
        if (in_array($key, self::phoneColumns(), true) && is_numeric($cell->getValue()) && preg_match('/^[1-9]\d{7,8}$/', $value)) {
            $value = '0'.$value;
        }

        return $value;
    }

    private function checkRows(array $rows, array $smoveMembers): array
    {
        $statuses = self::customerStatuses();
        $sources = self::leadSources();
        $formats = array_flip(School::CONTENT_FORMATS);

        $existingNames = School::query()->pluck('name')->mapWithKeys(fn ($name) => [Lead::normalizeName($name) => true]);
        $existingNumbers = School::query()->whereNotNull('business_number')->pluck('business_number')
            ->mapWithKeys(fn ($number) => [self::digits($number) => true])->forget('');

        $columns = self::columns();
        $seenNames = [];
        $seenNumbers = [];

        // A contact email already used elsewhere is a warning, not an error (an
        // accountant may well serve several schools) — but Smove keeps one
        // contact per email, so both customers share that one Smove contact.
        $existingEmails = Contact::query()->join('schools', 'schools.id', '=', 'contacts.school_id')
            ->whereNotNull('contacts.email')->where('contacts.email', '!=', '')
            ->get(['contacts.email', 'schools.name'])
            ->groupBy(fn ($contact) => mb_strtolower(trim($contact->email)))
            ->map(fn ($contacts) => $contacts->pluck('name')->unique()->values()->all());
        $seenEmails = [];

        foreach ($rows as &$row) {
            $v = $row['raw'];
            $v['gefen_budget'] = mb_strtolower($v['gefen_budget']);
            $errors = [];

            // Contacts: which slots are filled, and which are primary (none marked → contact 1).
            $filled = [];
            $primary = [];

            for ($n = 1; $n <= self::CONTACTS; $n++) {
                foreach (self::CONTACT_FLAG_FIELDS as $field) {
                    $v["contact{$n}_{$field}"] = mb_strtolower($v["contact{$n}_{$field}"]);
                }

                $details = collect(array_keys(self::CONTACT_FIELDS))->diff(self::CONTACT_FLAG_FIELDS)
                    ->contains(fn ($field) => $v["contact{$n}_{$field}"] !== '');

                if ($n === 1 || $details) {
                    $filled[] = $n;

                    if (in_array($v["contact{$n}_primary"], self::YES, true)) {
                        $primary[] = $n;
                    }
                }
            }

            if ($primary === []) {
                $primary = [1];
            }

            $rules = [
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
            ];

            for ($n = 1; $n <= self::CONTACTS; $n++) {
                $isFilled = in_array($n, $filled, true);
                $isPrimary = in_array($n, $primary, true);

                $rules += [
                    "contact{$n}_name" => [Rule::requiredIf($isFilled), 'nullable', 'string', 'max:255'],
                    "contact{$n}_role" => ['nullable', 'string', 'max:255'],
                    "contact{$n}_phone" => [Rule::requiredIf($isPrimary), 'nullable', 'string', 'max:50', new Phone],
                    "contact{$n}_phone_secondary" => ['nullable', 'string', 'max:50', new Phone],
                    "contact{$n}_email" => [Rule::requiredIf($isPrimary), 'nullable', 'email', 'max:255'],
                    "contact{$n}_email_secondary" => ['nullable', 'email', 'max:255'],
                    "contact{$n}_primary" => ['nullable', Rule::in(array_merge(self::YES, self::NO))],
                    "contact{$n}_accounting" => ['nullable', Rule::in(array_merge(self::YES, self::NO))],
                ];
            }

            $flagMessages = [];

            for ($n = 1; $n <= self::CONTACTS; $n++) {
                $flagMessages["contact{$n}_primary.in"] = 'יש לכתוב כן או לא.';
                $flagMessages["contact{$n}_accounting.in"] = 'יש לכתוב כן או לא.';
                $flagMessages["contact{$n}_phone.required"] = 'חובה לאיש קשר ראשי — חסר.';
                $flagMessages["contact{$n}_email.required"] = 'חובה לאיש קשר ראשי — חסר.';
            }

            $validator = Validator::make($v, $rules, $flagMessages + [
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
                $errors[$columns[$key][0]] = $messages[0];
            }

            $name = Lead::normalizeName($v['school_name']);
            $number = self::digits($v['business_number']);

            if ($name !== '') {
                if (isset($existingNames[$name])) {
                    $errors[$columns['school_name'][0]] = 'בית ספר בשם זה כבר קיים במערכת — לא ייובא.';
                } elseif (isset($seenNames[$name])) {
                    $errors[$columns['school_name'][0]] = "מופיע כבר בשורה {$seenNames[$name]} בקובץ.";
                }

                $seenNames[$name] ??= $row['row'];
            }

            if ($number !== '' && ! isset($errors[$columns['business_number'][0]])) {
                if (isset($existingNumbers[$number])) {
                    $errors[$columns['business_number'][0]] = 'ח.פ. זה כבר קיים במערכת — לא ייובא.';
                } elseif (isset($seenNumbers[$number])) {
                    $errors[$columns['business_number'][0]] = "ח.פ. זה מופיע כבר בשורה {$seenNumbers[$number]} בקובץ.";
                }

                $seenNumbers[$number] ??= $row['row'];
            }

            // A contact not on the Smove list is no longer a row error (2026-11-18):
            // the check lists it, and the import opens it only once the user
            // approves — then it joins the list like any new contact.
            $unlisted = [];

            foreach ($filled as $n) {
                $key = "contact{$n}_email";
                $column = $columns[$key][0];
                $email = mb_strtolower(trim($v[$key]));

                if ($email !== '' && ! isset($errors[$column]) && ! isset($smoveMembers[$email])) {
                    $unlisted[$column] = ['email' => trim($v[$key]), 'name' => trim($v["contact{$n}_name"])];
                }
            }

            $warnings = [];

            foreach ($filled as $n) {
                $column = $columns["contact{$n}_email"][0];
                $email = mb_strtolower(trim($v["contact{$n}_email"]));

                if ($email === '' || isset($errors[$column])) {
                    continue;
                }

                $elsewhere = [];

                foreach ($existingEmails[$email] ?? [] as $school) {
                    $elsewhere[] = "\"{$school}\"";
                }

                foreach ($seenEmails[$email] ?? [] as [$otherRow, $otherSchool]) {
                    if ($otherRow !== $row['row']) {
                        $elsewhere[] = "\"{$otherSchool}\" (שורה {$otherRow} בקובץ)";
                    }
                }

                if ($elsewhere) {
                    $warnings[$column] = 'כתובת זו כבר שייכת לאיש קשר של '.implode(', ', array_unique($elsewhere))
                        .'. ב־Smove זה איש קשר אחד לכולן — הוא ייובא וישויך לשתי הלקוחות.';
                }

                $seenEmails[$email][$row['row']] = [$row['row'], trim($v['school_name'])];
            }

            $row['errors'] = $errors;
            $row['warnings'] = $errors === [] ? $warnings : [];
            $row['unlisted'] = $errors === [] ? $unlisted : [];
            $row['data'] = $errors === [] ? $this->normalize($v, $formats, $filled, $primary) : null;
            unset($row['raw']);
        }

        return $rows;
    }

    private function normalize(array $v, array $formats, array $filled, array $primary): array
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
            'contacts' => array_map(fn (int $n) => [
                'name' => trim($v["contact{$n}_name"]),
                'role' => $blank($v["contact{$n}_role"]),
                'phone' => $blank($v["contact{$n}_phone"]),
                'phone_secondary' => $blank($v["contact{$n}_phone_secondary"]),
                'email' => $blank($v["contact{$n}_email"]),
                'email_secondary' => $blank($v["contact{$n}_email_secondary"]),
                'is_primary' => in_array($n, $primary, true),
                'is_accounting_contact' => in_array($v["contact{$n}_accounting"], self::YES, true),
            ], $filled),
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
    public function import(array $rows, User $user, ActivityLogger $activityLogger, ?callable $progress = null, ?array $smove = null, bool $approveUnlisted = false): array
    {
        $smove ??= ['state' => 'off', 'members' => []];
        $created = [];
        $failed = [];
        $this->smoveStats = ['linked' => 0, 'not_receiving' => 0, 'joined' => 0];

        foreach (array_values(array_filter($rows, fn ($row) => $row['data'] !== null)) as $i => $row) {
            try {
                // Contacts not on the Smove list are opened only with the user's approval.
                if (! empty($row['unlisted']) && ! $approveUnlisted) {
                    throw new \RuntimeException('יש בשורה אנשי קשר שלא נמצאו ברשימת התפוצה ב־Smove, ולא אושר לפתוח אותם.');
                }

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
            .($smove['state'] === 'ok' ? " — {$this->smoveStats['linked']} אנשי קשר קושרו לרשימת Smove" : '')
            .($this->smoveStats['joined'] ? ", {$this->smoveStats['joined']} שלא היו ברשימה צורפו אליה" : ''), [
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

    /** For the check screen: how many of the valid rows' contacts will be linked, and how many of those don't receive emails. */
    public function smovePreview(array $rows, array $smove): array
    {
        $preview = ['state' => $smove['state'], 'linked' => 0, 'not_receiving' => 0, 'unlisted' => 0];

        foreach ($rows as $row) {
            $preview['unlisted'] += count($row['unlisted'] ?? []);

            foreach (self::contactEmails($row['data']) as $email) {
                if ($member = $smove['members'][$email] ?? null) {
                    $preview['linked']++;
                    $preview['not_receiving'] += $member['can_receive'] ? 0 : 1;
                }
            }
        }

        return $preview;
    }

    /** The lower-cased emails of a row's contacts, once each. */
    private static function contactEmails(?array $data): array
    {
        if (! $data) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (array $contact) => mb_strtolower(trim((string) $contact['email'])),
            $data['contacts'],
        ))));
    }

    private function importRow(array $data, User $user, ActivityLogger $activityLogger, array $smove): Customer
    {
        // Contacts found on the Smove list are linked and never pushed (a join
        // would reset their consent). Approved ones not on it join it as usual.
        $emails = self::contactEmails($data);
        $linked = array_intersect_key($smove['members'], array_flip($emails));

        $customer = $this->createCustomer($data, $user, $activityLogger, $linked, array_keys($linked));

        foreach ($linked as $member) {
            $this->smoveStats['linked']++;
            $this->smoveStats['not_receiving'] += $member['can_receive'] ? 0 : 1;
        }

        // Accounting-only contacts get accounting mail only, so they never join the list.
        $this->smoveStats['joined'] += collect($data['contacts'])
            ->filter(fn (array $c) => filled($c['email']) && ! ($c['is_accounting_contact'] && ! $c['is_primary']))
            ->map(fn (array $c) => mb_strtolower(trim($c['email'])))
            ->unique()
            ->reject(fn (string $email) => isset($linked[$email]))
            ->count();

        return $customer;
    }

    /**
     * Opens one customer from normalize()-shaped $data — the import's rows and
     * the manual "לקוחה חדשה" form (⚡customers.blade.php) both come through
     * here. A customer only ever exists as a converted lead, so this opens a
     * lead from the first primary contact and converts it at once.
     *
     * @param  array<string, array{id: int}>  $smoveLinks  lower-cased email => Smove contact: those contacts are linked (smove_contact_id)
     * @param  list<string>  $noPushEmails  lower-cased emails not pushed to Smove on conversion
     */
    public function createCustomer(array $data, User $user, ActivityLogger $activityLogger, array $smoveLinks = [], array $noPushEmails = []): Customer
    {
        $customer = DB::transaction(function () use ($data, $user) {
            if (Lead::findExactDuplicateSchool($data['school']['name'])) {
                throw new \RuntimeException('בית ספר בשם זה כבר קיים במערכת — לא נוצרה לקוחה.');
            }

            $school = School::create($data['school']);

            foreach ($data['contacts'] as $contact) {
                Contact::create($contact + ['school_id' => $school->id]);
            }

            // The lead is opened from the first primary contact (its email and phone are required).
            $lead = collect($data['contacts'])->firstWhere('is_primary', true);

            $newStatus = StatusDefinition::firstOrCreate(
                ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
                ['is_active' => true, 'sort_order' => 1],
            );

            return Lead::create([
                'school_id' => $school->id,
                'assigned_user_id' => $user->id,
                'lead_source_id' => $data['lead_source'] ? LeadSource::where('name', $data['lead_source'])->value('id') : null,
                'status_id' => $newStatus->id,
                'email' => $lead['email'],
                'phone' => $lead['phone'],
            ]);
        });

        // Converting also files the contacts under the customer and puts it on
        // the system's mailing list (pushing to Smove all but $noPushEmails).
        $convertedAt = $data['converted_at'] ? Carbon::parse($data['converted_at']) : null;

        $result = MailingMembership::withoutPushing($noPushEmails, fn () => $customer->convertToCustomer($activityLogger, $convertedAt));

        foreach ($smoveLinks as $email => $member) {
            $result->contacts()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->update(['smove_contact_id' => $member['id']]);
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
        return Coordinate::stringFromColumnIndex(array_search($key, array_keys(self::columns()), true) + 1);
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
