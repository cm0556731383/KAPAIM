<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Deal import from Excel (settings → יבוא נתונים → עסקאות, 2026-11-24): one
 * row = one deal for an existing customer, picked from a drop-down of the
 * system's customers, with up to ITEMS lines picked from the catalog's
 * active programs and bundles. Checked by the same rules as the new-deal
 * window (Deal::createWithItems()) before anything is saved, and created
 * through it — so a subscription bundle opens its subscription and the
 * mailing lists are joined exactly as for a deal made by hand.
 *
 * Payments made before the system come in with their receipt issued outside
 * it (Receipt::recordExternalPayment(), 2026-11-25) — nothing goes to SUMIT,
 * so a receipt needs the row's (external) invoice. Every imported deal starts
 * "פתוחה" ("נשלחה חשבונית" with an invoice, "שולמה" once fully paid).
 */
class DealImport
{
    public const MAX_ROWS = 2000;

    public const SHEET_TITLE = 'עסקאות';

    /** How many item lines (item + amount) the template has. */
    public const ITEMS = 6;

    /** How many payments (method + amount + date + receipt number) the template has. */
    public const PAYMENTS = 6;

    private const PROGRAM_PREFIX = 'תוכנית: ';

    private const BUNDLE_PREFIX = 'מארז: ';

    /** The template's columns, in order: key => [header, required, help]. */
    public static function columns(): array
    {
        $columns = [
            'customer' => ['לקוחה', true, 'בחירה מהרשימה — הלקוחות שבמערכת (לפי שם המוסד).'],
            'purchased_at' => ['תאריך רכישה', false, 'תאריך בפורמט 31/12/2025. ריק = היום. לא תאריך עתידי.'],
            'class_tier' => ['מספר כיתות בשנתון (לתמחור)', false, 'בחירה מהרשימה. ריק = לפי מספר הכיתות שבכרטיס הלקוחה. חובה כשאין מספר כיתות בכרטיס והמחיר של פריט תלוי בו.'],
        ];

        for ($n = 1; $n <= self::ITEMS; $n++) {
            $columns["item{$n}"] = ["פריט {$n}", $n === 1, $n === 1
                ? 'בחירה מהרשימה — תוכנית או מארז פעילים בקטלוג. פריט 1 חובה; עסקה יכולה לכלול עד '.self::ITEMS.' פריטים.'
                : 'בחירה מהרשימה. ריק = אין פריט נוסף.'];
            $columns["amount{$n}"] = ["סכום פריט {$n}", false, 'בש"ח. ריק = המחיר בקטלוג לפי מספר הכיתות (כולל הנחת מנוי 10% לתוכנית פרימיום, כמו בעסקה ידנית).'];
        }

        $columns += [
            'students_count' => ['מספר תלמידות', false, 'מספר שלם, 0 ומעלה.'],
            'payment_method' => ['אמצעי תשלום מתוכנן', false, 'בחירה מהרשימה (אמצעי התשלום שבהגדרות).'],
            'special_request' => ['בקשה מיוחדת / הערות', false, ''],
        ];

        // Documents made before the system / outside it (Document::recordExternal(), 2026-11-25).
        foreach (Document::EXTERNAL_TYPES as $type) {
            $label = Document::TYPE_LABELS[$type];
            $states = implode(' / ', Document::externalStates($type));
            $columns["{$type}_state"] = ["{$label} — מצב", false, "בחירה מהרשימה: {$states}. ריק = אין {$label} מחוץ למערכת. המסמך רק נרשם בעסקה — לא נשלח ללקוחה ולא מופק ב־SUMIT."
                .($type === 'invoice' ? ' חשבונית שנרשמת כך נחשבת לחשבונית של העסקה: הסטטוס יהיה "נשלחה חשבונית" והסכום יינעל.' : '')];
            $columns["{$type}_date"] = ["{$label} — תאריך", false, 'תאריך בפורמט 31/12/2025. ריק = תאריך הרכישה.'];
        }

        $columns['invoice_number'] = ['חשבונית — מספר', false, 'מספר החשבונית שהופקה מחוץ למערכת (לא חובה).'];

        // Payments made before the system, each with its receipt issued outside it.
        for ($n = 1; $n <= self::PAYMENTS; $n++) {
            $columns["pay{$n}_method"] = ["תשלום {$n} — אמצעי תשלום", false, $n === 1
                ? 'בחירה מהרשימה. ריק = אין תשלום. כל תשלום נרשם עם קבלה שהופקה מחוץ למערכת — שום דבר לא נשלח ללקוחה ולא מופק ב־SUMIT. קבלה נרשמת על חשבונית, ולכן תשלום דורש חשבונית בעמודות החשבונית.'
                : 'בחירה מהרשימה. ריק = אין תשלום נוסף.'];
            $columns["pay{$n}_amount"] = ["תשלום {$n} — סכום", false, 'בש"ח. חובה כשנבחר אמצעי תשלום.'];
            $columns["pay{$n}_date"] = ["תשלום {$n} — תאריך", false, 'תאריך בפורמט 31/12/2025. ריק = תאריך החשבונית. צ\'ק נחשב שנפרע בתאריך זה.'];
            $columns["pay{$n}_receipt"] = ["תשלום {$n} — מספר קבלה", false, 'מספר הקבלה שהופקה מחוץ למערכת (לא חובה).'];
        }

        return $columns;
    }

    /** "נחתם" / "נשלחה" … typed in a state column => 'signed' / 'sent' (null = not recognised). */
    private static function externalState(string $type, string $value): ?string
    {
        $value = mb_strtolower(trim(str_replace(['"', '״'], '', $value)));
        $sent = ['נשלח', 'נשלחה', 'sent', 'הופקה', 'הופקה ונשלחה', 'הופק', 'כן'];
        $signed = ['נחתם', 'נחתמה', 'חתום', 'חתומה', 'אושר', 'אושרה', 'signed'];
        $state = in_array($value, $sent, true) ? 'sent' : (in_array($value, $signed, true) ? 'signed' : null);

        return $state && array_key_exists($state, Document::externalStates($type)) ? $state : null;
    }

    private static function amountKeys(): array
    {
        return [
            ...array_map(fn ($n) => "amount{$n}", range(1, self::ITEMS)),
            ...array_map(fn ($n) => "pay{$n}_amount", range(1, self::PAYMENTS)),
        ];
    }

    // ===== The blank template =====

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('יבוא עסקאות — כפיים');

        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::SHEET_TITLE);
        $sheet->setRightToLeft(true);

        $lastRow = self::MAX_ROWS + 1;
        $index = 0;

        foreach (self::columns() as $key => [$header, $required, $help]) {
            $letter = Coordinate::stringFromColumnIndex(++$index);
            $sheet->setCellValue("{$letter}1", $required ? "{$header} *" : $header);
            $sheet->getColumnDimension($letter)->setWidth(match (true) {
                $key === 'customer', str_starts_with($key, 'item') => 34,
                $key === 'special_request' => 40,
                default => max(14, mb_strlen($header) + 4),
            });

            $style = $sheet->getStyle("{$letter}1");
            $style->getFont()->setBold(true);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($required ? 'F6D5C8' : 'E8EEF4');
            $style->getAlignment()->setWrapText(true)->setVertical('center');

            if ($help !== '') {
                $sheet->getComment("{$letter}1")->getText()->createTextRun($help);
            }

            if ($key === 'purchased_at' || str_ends_with($key, '_date')) {
                $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } elseif (in_array($key, self::amountKeys(), true)) {
                $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->freezePane('B2');

        $this->addPickLists($book, $lastRow);
        $this->addInstructions($book);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function addPickLists(Spreadsheet $book, int $lastRow): void
    {
        $lists = [
            'customer' => self::customerNames(),
            'class_tier' => array_values(Program::CLASS_TIERS),
            'payment_method' => self::paymentMethodNames(),
        ];
        $items = self::itemLabels();

        foreach (Document::EXTERNAL_TYPES as $type) {
            $lists["{$type}_state"] = array_values(Document::externalStates($type));
        }

        for ($n = 1; $n <= self::ITEMS; $n++) {
            $lists["item{$n}"] = $items;
        }

        for ($n = 1; $n <= self::PAYMENTS; $n++) {
            $lists["pay{$n}_method"] = $lists['payment_method'];
        }

        $listSheet = $book->createSheet();
        $listSheet->setTitle('רשימות');
        $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $sheet = $book->getSheet(0);
        $letters = [];
        $listColumn = 0;
        $keys = array_keys(self::columns());

        foreach ($lists as $key => $values) {
            if ($values === []) {
                continue;
            }

            $listLetter = $letters[implode("\n", $values)] ??= Coordinate::stringFromColumnIndex(++$listColumn);

            foreach (array_values($values) as $i => $value) {
                $listSheet->setCellValueExplicit($listLetter.($i + 1), $value, DataType::TYPE_STRING);
            }

            $letter = Coordinate::stringFromColumnIndex(array_search($key, $keys, true) + 1);
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
            ['יבוא עסקאות — הוראות מילוי'],
            [''],
            ['• כל שורה בגיליון "עסקאות" היא עסקה אחת. אין לשנות את שורת הכותרות.'],
            ['• את הלקוחה ואת הפריטים בוחרים מהרשימות הנפתחות — לקוחות שכבר במערכת, ותוכניות ומארזים פעילים בקטלוג. לקוחה חדשה מוסיפים קודם ביבוא הלקוחות.'],
            ['• עמודות המסומנות ב־* (ובצבע כתום) הן חובה.'],
            ['• סכום ריק = המחיר בקטלוג לפי מספר הכיתות בשנתון, כמו בעסקה שנפתחת בכרטיס הלקוחה.'],
            ['• עסקה עם מארז מנוי פותחת מנוי לשנת הלימודים של המארז, וכל עסקה מצרפת את הלקוחה לרשימות התפוצה — בדיוק כמו עסקה ידנית.'],
            ['• מסמכים שכבר נשלחו או נחתמו לפני המערכת (הצעת מחיר, טופס הזמנה, חוזה, חשבונית) — בוחרים להם מצב ותאריך בעמודות המסמכים. הם רק נרשמים בעסקה: שום דבר לא נשלח ללקוחה ולא מופק ב־SUMIT, והמערכת ממשיכה מהם (למשל הפקת חשבונית אחרי חוזה שנחתם).'],
            ['• תשלומים שכבר התקבלו (עד '.self::PAYMENTS.' לעסקה) — אמצעי, סכום, תאריך ומספר קבלה. כל תשלום נרשם עם קבלה שהופקה מחוץ למערכת, ולכן צריך לסמן לעסקה גם חשבונית. שום דבר לא נשלח ללקוחה ולא מופק ב־SUMIT.'],
            ['• כל עסקה נפתחת בסטטוס "פתוחה" — "נשלחה חשבונית" כשנרשמה לה חשבונית, ו"שולמה" כשהתשלומים מכסים את הסכום.'],
            ['• עסקה זהה לעסקה שכבר קיימת (אותה לקוחה, אותו תאריך ואותם פריטים) לא תיובא — כדי שהעלאה חוזרת של אותו קובץ לא תכפיל עסקאות.'],
            ['• לפני היבוא המערכת בודקת את כל הקובץ ומציגה את השגיאות לפי מספר שורה. אפשר לתקן אותן במסך עצמו או בקובץ ולהעלות שוב.'],
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
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(8);
        $sheet->getColumnDimension('C')->setWidth(90);
    }

    // ===== Checking an uploaded file =====

    /**
     * Reads and checks a filled file. Returns:
     *   'fileError' => why the file can't be imported at all, else null
     *   'rows'      => [['row' => n, 'customer' => typed name, 'data' => normalized|null,
     *                    'errors' => [column key => message], 'values' => [column key => raw] (rows with errors)]]
     */
    public function analyze(string $path): array
    {
        try {
            $book = IOFactory::load($path);
        } catch (Throwable) {
            return ['fileError' => 'לא ניתן לקרוא את הקובץ. יש להעלות קובץ אקסל (xlsx) שהורד מכאן ומולא.', 'rows' => []];
        }

        try {
            $sheet = $book->getSheetByName(self::SHEET_TITLE) ?? $book->getSheet(0);
            $columnOf = self::columnPositions($sheet);
            $missing = collect(self::columns())->filter(fn ($c, $k) => $c[1] && ! isset($columnOf[$k]))->map(fn ($c) => $c[0]);

            if ($missing->isNotEmpty()) {
                return ['fileError' => 'בקובץ חסרות עמודות חובה: '.$missing->implode(', ').'. יש להשתמש בקובץ שהורד מכאן.', 'rows' => []];
            }

            $rows = [];
            $highestRow = $sheet->getHighestDataRow();

            for ($r = 2; $r <= $highestRow; $r++) {
                $values = [];

                foreach (array_keys(self::columns()) as $key) {
                    $values[$key] = isset($columnOf[$key]) ? self::cellValue($sheet->getCell([$columnOf[$key], $r])->getValue(), $key) : '';
                }

                if (collect($values)->every(fn ($v) => $v === '')) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    return ['fileError' => 'בקובץ יותר מ־'.self::MAX_ROWS.' שורות. יש לפצל אותו לכמה קבצים.', 'rows' => []];
                }

                $rows[] = ['row' => $r, 'customer' => $values['customer'], 'raw' => $values];
            }
        } finally {
            $book->disconnectWorksheets();
        }

        if ($rows === []) {
            return ['fileError' => 'לא נמצאו בקובץ שורות למילוי.', 'rows' => []];
        }

        return ['fileError' => null, 'rows' => $this->checkRows($rows)];
    }

    private function checkRows(array $rows): array
    {
        $customers = Customer::with('school')->get()
            ->filter(fn (Customer $c) => $c->school)
            ->keyBy(fn (Customer $c) => self::key($c->school->name));
        $programs = Program::where('is_active', true)->get()->keyBy(fn ($p) => self::key($p->name));
        $bundles = Bundle::where('is_active', true)->get()->keyBy(fn ($b) => self::key($b->name));
        $methods = PaymentMethod::where('is_active', true)->get()->keyBy(fn ($m) => self::key($m->name));
        $tiers = collect(Program::CLASS_TIERS)->mapWithKeys(fn ($label, $tier) => [self::key($label) => $tier, self::key($tier) => $tier]);

        // Deals already in the system, as "customer|date|items" — an identical one isn't imported again.
        $existing = Deal::with('items')->whereIn('customer_id', $customers->pluck('id'))->get()
            ->mapWithKeys(fn (Deal $deal) => [self::signature($deal->customer_id, $deal->purchased_at?->toDateString(), $deal->items->map(fn ($i) => $i->program_id ? "p{$i->program_id}" : "b{$i->bundle_id}")->all()) => true]);
        $seen = [];
        // Subscription bundles already picked for a customer earlier in the file.
        $seenSubscriptions = [];

        foreach ($rows as &$row) {
            $v = $row['raw'];
            $errors = [];

            $customer = $v['customer'] === '' ? null : $customers[self::key($v['customer'])] ?? null;

            if ($v['customer'] === '') {
                $errors['customer'] = 'שדה חובה — חסר.';
            } elseif (! $customer) {
                $errors['customer'] = 'אין במערכת לקוחה בשם זה — יש לבחור מהרשימה.';
            }

            $purchasedAt = null;

            if ($v['purchased_at'] !== '') {
                $purchasedAt = self::parseDate($v['purchased_at']);

                if (! $purchasedAt) {
                    $errors['purchased_at'] = 'תאריך לא תקין — יש לכתוב בפורמט 31/12/2025.';
                } elseif ($purchasedAt->isAfter(today())) {
                    $errors['purchased_at'] = 'תאריך עתידי.';
                }
            }

            $tier = null;

            if ($v['class_tier'] !== '') {
                $tier = $tiers[self::key($v['class_tier'])] ?? null;

                if (! $tier) {
                    $errors['class_tier'] = 'ערך לא מהרשימה — 1–2, 3–4 או 5–6 כיתות.';
                }
            }

            // The items.
            $items = [];

            for ($n = 1; $n <= self::ITEMS; $n++) {
                $label = $v["item{$n}"];
                $amount = $v["amount{$n}"];

                if ($label === '') {
                    if ($n === 1) {
                        $errors['item1'] = 'שדה חובה — לעסקה צריך לפחות פריט אחד.';
                    } elseif ($amount !== '') {
                        $errors["amount{$n}"] = "יש סכום בלי פריט — יש לבחור את פריט {$n} או למחוק את הסכום.";
                    }

                    continue;
                }

                [$program, $bundle, $problem] = self::resolveItem($label, $programs, $bundles);

                if ($problem) {
                    $errors["item{$n}"] = $problem;
                } elseif (collect($items)->contains(fn ($i) => ($i['program'] ?? $i['bundle']) === ($program ?? $bundle))) {
                    $errors["item{$n}"] = 'הפריט מופיע כבר בעסקה.';
                }

                if ($amount !== '' && (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > Money::MAX_AMOUNT)) {
                    $errors["amount{$n}"] = 'צריך להיות סכום (למשל 1200 או 1200.50), 0 ומעלה.';
                }

                if (! isset($errors["item{$n}"])) {
                    $items[] = ['n' => $n, 'program' => $program, 'bundle' => $bundle, 'amount' => $amount === '' || isset($errors["amount{$n}"]) ? null : round((float) $amount, 2)];
                }
            }

            // Subscription bundles: one per deal, its year not over, not one the customer already has active.
            $subscriptionItems = array_values(array_filter($items, fn ($i) => $i['bundle']?->is_subscription_type));

            if (count($subscriptionItems) > 1) {
                $errors["item{$subscriptionItems[1]['n']}"] = 'עסקה יכולה לכלול מארז מנוי אחד בלבד.';
            }

            if ($subscription = $subscriptionItems[0]['bundle'] ?? null) {
                $key = "item{$subscriptionItems[0]['n']}";

                if ($subscription->schoolYearHasEnded()) {
                    $errors[$key] = "שנת הלימודים של מארז המנוי ({$subscription->schoolYearLabel()}) כבר הסתיימה — לא ניתן למכור אותו.";
                } elseif ($customer && $customer->subscriptions()->whereHas('deal', fn ($q) => $q->withItem($subscription))->get()->contains->isActive()) {
                    $errors[$key] = 'ללקוחה כבר יש מנוי פעיל במארז זה.';
                } elseif ($customer && isset($seenSubscriptions[$customer->id][$subscription->id])) {
                    $errors[$key] = "מארז המנוי הזה כבר נמכר ללקוחה בשורה {$seenSubscriptions[$customer->id][$subscription->id]} בקובץ.";
                }

                if ($customer && ! isset($errors[$key])) {
                    $seenSubscriptions[$customer->id][$subscription->id] = $row['row'];
                }
            }

            // A price by classes needs the tier when the customer card has no number of classes.
            if ($customer && ! $tier && ! isset($errors['class_tier'])
                && ! Program::classTierFor($customer->school?->classes_per_grade)
                && collect($items)->contains(fn ($i) => $i['amount'] === null && ($i['program'] ?? $i['bundle'])->hasClassTierPrices())) {
                $errors['class_tier'] = 'בכרטיס הלקוחה אין מספר כיתות בשנתון, ומחיר של פריט תלוי בו — יש לבחור כאן, או למלא את הסכום בעצמך.';
            }

            if ($v['students_count'] !== '' && ! preg_match('/^\d+$/', $v['students_count'])) {
                $errors['students_count'] = 'צריך להיות מספר שלם, 0 ומעלה.';
            }

            $method = null;

            if ($v['payment_method'] !== '') {
                $method = $methods[self::key($v['payment_method'])] ?? null;

                if (! $method) {
                    $errors['payment_method'] = 'אין אמצעי תשלום פעיל בשם זה — יש לבחור מהרשימה.';
                }
            }

            // Documents made outside the system.
            $documents = [];

            foreach (Document::EXTERNAL_TYPES as $type) {
                $stateValue = $v["{$type}_state"];
                $dateValue = $v["{$type}_date"];

                if ($stateValue === '') {
                    if ($dateValue !== '') {
                        $errors["{$type}_state"] = 'יש תאריך בלי מצב — יש לבחור מצב מהרשימה או למחוק את התאריך.';
                    }

                    continue;
                }

                $state = self::externalState($type, $stateValue);

                if (! $state) {
                    $errors["{$type}_state"] = 'ערך לא מהרשימה — '.implode(' / ', Document::externalStates($type)).'.';
                }

                $date = null;

                if ($dateValue !== '') {
                    $date = self::parseDate($dateValue);

                    if (! $date) {
                        $errors["{$type}_date"] = 'תאריך לא תקין — יש לכתוב בפורמט 31/12/2025.';
                    } elseif ($date->isAfter(today())) {
                        $errors["{$type}_date"] = 'תאריך עתידי.';
                    }
                }

                if ($state && ! isset($errors["{$type}_date"])) {
                    $documents[] = ['type' => $type, 'state' => $state, 'date' => $date?->toDateString()];
                }
            }

            if ($v['invoice_number'] !== '' && $v['invoice_state'] === '') {
                $errors['invoice_number'] = 'יש מספר חשבונית בלי מצב — יש לבחור "הופקה ונשלחה" במצב החשבונית, או למחוק את המספר.';
            } elseif (mb_strlen($v['invoice_number']) > 50) {
                $errors['invoice_number'] = 'ארוך מדי (עד 50 תווים).';
            }

            // Payments, each with its receipt issued outside the system.
            $payments = [];
            $invoiceDate = collect($documents)->firstWhere('type', 'invoice')['date'] ?? null;

            for ($n = 1; $n <= self::PAYMENTS; $n++) {
                $methodValue = $v["pay{$n}_method"];
                $amountValue = $v["pay{$n}_amount"];
                $dateValue = $v["pay{$n}_date"];
                $receiptValue = $v["pay{$n}_receipt"];

                if ($methodValue === '') {
                    if ($amountValue !== '' || $dateValue !== '' || $receiptValue !== '') {
                        $errors["pay{$n}_method"] = "יש פרטי תשלום בלי אמצעי תשלום — יש לבחור אמצעי לתשלום {$n} או למחוק את הפרטים.";
                    }

                    continue;
                }

                $payMethod = $methods[self::key($methodValue)] ?? null;

                if (! $payMethod) {
                    $errors["pay{$n}_method"] = 'אין אמצעי תשלום פעיל בשם זה — יש לבחור מהרשימה.';
                }

                if ($amountValue === '') {
                    $errors["pay{$n}_amount"] = 'שדה חובה כשנבחר אמצעי תשלום.';
                } elseif (! is_numeric($amountValue) || (float) $amountValue <= 0 || (float) $amountValue > Money::MAX_AMOUNT) {
                    $errors["pay{$n}_amount"] = 'צריך להיות סכום גדול מאפס (למשל 1200 או 1200.50).';
                }

                $payDate = null;

                if ($dateValue !== '') {
                    $payDate = self::parseDate($dateValue);

                    if (! $payDate) {
                        $errors["pay{$n}_date"] = 'תאריך לא תקין — יש לכתוב בפורמט 31/12/2025.';
                    } elseif ($payDate->isAfter(today())) {
                        $errors["pay{$n}_date"] = 'תאריך עתידי.';
                    }
                }

                if (mb_strlen($receiptValue) > 50) {
                    $errors["pay{$n}_receipt"] = 'ארוך מדי (עד 50 תווים).';
                }

                if ($v['invoice_state'] === '') {
                    $errors["pay{$n}_method"] = 'תשלום נרשם עם קבלה, וקבלה נרשמת על חשבונית — יש לסמן לעסקה חשבונית ("הופקה ונשלחה") בעמודות החשבונית.';
                }

                if (! array_intersect_key($errors, array_flip(["pay{$n}_method", "pay{$n}_amount", "pay{$n}_date", "pay{$n}_receipt"]))) {
                    $payments[] = [
                        'payment_method_id' => $payMethod->id,
                        'payment_method' => $payMethod->name,
                        'amount' => round((float) $amountValue, 2),
                        'date' => $payDate?->toDateString() ?? $invoiceDate,
                        'receipt_number' => $receiptValue === '' ? null : $receiptValue,
                    ];
                }
            }

            // The same deal twice — already in the system, or earlier in the file.
            if ($errors === [] && $customer) {
                $signature = self::signature($customer->id, ($purchasedAt ?? today())->toDateString(), array_map(fn ($i) => $i['program'] ? "p{$i['program']->id}" : "b{$i['bundle']->id}", $items));

                if (isset($existing[$signature])) {
                    $errors['customer'] = 'כבר קיימת במערכת עסקה זהה ללקוחה זו (אותו תאריך ואותם פריטים) — לא תיובא שוב.';
                } elseif (isset($seen[$signature])) {
                    $errors['customer'] = "עסקה זהה מופיעה כבר בשורה {$seen[$signature]} בקובץ.";
                } else {
                    $seen[$signature] = $row['row'];
                }
            }

            $row['errors'] = $errors;
            $row['data'] = $errors === [] ? [
                'customer_id' => $customer->id,
                'customer' => $customer->school->name,
                'purchased_at' => $purchasedAt?->toDateString(),
                'class_tier' => $tier,
                'items' => array_map(fn ($i) => [
                    'program_id' => $i['program']?->id,
                    'bundle_id' => $i['bundle']?->id,
                    'name' => $i['program']?->name ?? $i['bundle']->name,
                    'type' => $i['program'] ? 'program' : 'bundle',
                    'amount' => $i['amount'],
                ], $items),
                'students_count' => $v['students_count'] === '' ? null : (int) $v['students_count'],
                'payment_method_id' => $method?->id,
                'payment_method' => $method?->name,
                'special_request' => $v['special_request'] === '' ? null : $v['special_request'],
                'documents' => $documents,
                'invoice_number' => $v['invoice_number'] === '' ? null : $v['invoice_number'],
                'payments' => $payments,
            ] : null;
            $row['values'] = $errors === [] ? null : $v;
            unset($row['raw']);
        }

        return $rows;
    }

    /** [?Program, ?Bundle, ?error] for a picked item: "תוכנית: X" / "מארז: X", or a bare name that's unambiguous. */
    private static function resolveItem(string $label, $programs, $bundles): array
    {
        if (str_starts_with($label, trim(self::PROGRAM_PREFIX))) {
            $name = self::key(trim(mb_substr($label, mb_strlen(trim(self::PROGRAM_PREFIX)))));

            return isset($programs[$name]) ? [$programs[$name], null, null] : [null, null, self::itemMissing($label, 'program')];
        }

        if (str_starts_with($label, trim(self::BUNDLE_PREFIX))) {
            $name = self::key(trim(mb_substr($label, mb_strlen(trim(self::BUNDLE_PREFIX)))));

            return isset($bundles[$name]) ? [null, $bundles[$name], null] : [null, null, self::itemMissing($label, 'bundle')];
        }

        $name = self::key($label);
        $program = $programs[$name] ?? null;
        $bundle = $bundles[$name] ?? null;

        return match (true) {
            $program && $bundle => [null, null, 'יש גם תוכנית וגם מארז בשם זה — יש לבחור מהרשימה ("תוכנית: …" או "מארז: …").'],
            (bool) $program => [$program, null, null],
            (bool) $bundle => [null, $bundle, null],
            default => [null, null, self::itemMissing($label, null)],
        };
    }

    private static function itemMissing(string $label, ?string $type): string
    {
        $name = trim(str_replace([trim(self::PROGRAM_PREFIX), trim(self::BUNDLE_PREFIX)], '', $label));
        $inactive = match ($type) {
            'program' => Program::where('name', $name)->exists(),
            'bundle' => Bundle::where('name', $name)->exists(),
            default => Program::where('name', $name)->exists() || Bundle::where('name', $name)->exists(),
        };

        return $inactive
            ? 'הפריט מושבת בקטלוג — אי אפשר למכור אותו.'
            : 'אין בקטלוג תוכנית או מארז בשם זה — יש לבחור מהרשימה.';
    }

    // ===== Fixing rows from the check screen =====

    /** Writes values fixed on the check screen back into the uploaded file. $fixes = [row => [column key => value]]. */
    public function applyFixes(string $path, array $fixes): void
    {
        $columns = self::columns();
        $book = IOFactory::load($path);

        try {
            $sheet = $book->getSheetByName(self::SHEET_TITLE) ?? $book->getSheet(0);
            $columnOf = self::columnPositions($sheet);
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

            foreach ($fixes as $row => $values) {
                $row = (int) $row;

                if ($row < 2 || ! is_array($values)) {
                    continue;
                }

                foreach ($values as $key => $value) {
                    if (! isset($columns[$key])) {
                        continue;
                    }

                    if (! isset($columnOf[$key])) {
                        $columnOf[$key] = ++$highestColumn;
                        $sheet->setCellValue([$highestColumn, 1], $columns[$key][0]);
                    }

                    $sheet->setCellValueExplicit([$columnOf[$key], $row], CustomerImport::clean((string) $value), DataType::TYPE_STRING);
                }
            }

            IOFactory::createWriter($book, 'Xlsx')->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    // ===== The import =====

    /**
     * Creates the deals of the rows that passed analyze(), each on its own —
     * one failing row never undoes the others.
     *
     * @param  callable(int $done): void|null  $progress
     * @return array{created: list<int>, failed: list<array{row: int, customer: string, error: string}>}
     */
    public function import(array $rows, User $user, ActivityLogger $activityLogger, ?callable $progress = null): array
    {
        $created = [];
        $failed = [];

        foreach (array_values(array_filter($rows, fn ($row) => $row['data'] !== null)) as $i => $row) {
            $data = $row['data'];

            try {
                $customer = Customer::with('school')->findOrFail($data['customer_id']);
                $items = array_map(fn ($item) => [
                    'program' => $item['program_id'] ? Program::findOrFail($item['program_id']) : null,
                    'bundle' => $item['bundle_id'] ? Bundle::findOrFail($item['bundle_id']) : null,
                    'amount' => $item['amount'],
                ], $data['items']);

                $deal = Deal::createWithItems(
                    $customer,
                    $items,
                    $data['special_request'],
                    $data['payment_method_id'],
                    $data['purchased_at'] ? Carbon::parse($data['purchased_at'], 'Asia/Jerusalem')->setTime(12, 0) : null,
                    $data['class_tier'],
                );

                if ($data['students_count'] !== null) {
                    $deal->update(['students_count' => $data['students_count']]);
                }

                // Documents made outside the system — on the document's date, else the purchase date.
                try {
                    foreach ($data['documents'] ?? [] as $document) {
                        Document::recordExternal(
                            $deal->fresh(['items', 'status']),
                            $document['type'],
                            $document['state'],
                            Carbon::parse($document['date'] ?? $deal->purchased_at, 'Asia/Jerusalem'),
                            $document['type'] === 'invoice' ? $data['invoice_number'] : null,
                            $activityLogger,
                        );
                    }
                } catch (Throwable $e) {
                    report($e);
                    $failed[] = ['row' => $row['row'], 'customer' => $row['customer'], 'error' => "העסקה נוצרה (#{$deal->id}), אבל רישום המסמכים שלה נכשל"
                        .($e instanceof \RuntimeException ? ': '.$e->getMessage() : '').' — אפשר לרשום אותם בכרטיס העסקה.'];
                    $data['payments'] = [];
                }

                // Payments with their receipts issued outside the system — on the payment's date, else the invoice's, else the purchase date.
                $recorded = 0;

                try {
                    foreach ($data['payments'] ?? [] as $payment) {
                        $fresh = $deal->fresh();
                        $date = Carbon::parse($payment['date'] ?? $fresh->issuedInvoice()?->sent_at ?? $fresh->purchased_at, 'Asia/Jerusalem');
                        Receipt::recordExternalPayment($fresh, (int) $fresh->version, $payment['payment_method_id'], (float) $payment['amount'], $date, $date, $payment['receipt_number'], null, $activityLogger);
                        $recorded++;
                    }
                } catch (Throwable $e) {
                    report($e);
                    $failed[] = ['row' => $row['row'], 'customer' => $row['customer'], 'error' => "העסקה נוצרה (#{$deal->id}), אבל רישום התשלומים שלה נכשל"
                        .($recorded ? " אחרי {$recorded} תשלומים" : '')
                        .($e instanceof \RuntimeException ? ': '.$e->getMessage() : '').' — אפשר להשלים אותם בכרטיס העסקה.'];
                }

                $activityLogger->log('deal.created', "נוצרה עסקה #{$deal->id} מיבוא אקסל עבור לקוחה \"{$customer->school?->name}\": {$deal->itemsLabel()}", [
                    'deal_id' => $deal->id, 'customer_id' => $customer->id, 'user' => $user,
                ]);

                $created[] = $deal->id;
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['row' => $row['row'], 'customer' => $row['customer'], 'error' => $e instanceof \RuntimeException ? $e->getMessage() : 'שגיאה לא צפויה בשמירה.'];
            }

            if ($progress) {
                $progress($i + 1);
            }
        }

        $activityLogger->log('deals.imported', 'יובאו '.count($created).' עסקאות מקובץ אקסל'.($failed ? ' ('.count($failed).' נכשלו)' : ''), [
            'user' => $user,
            'metadata' => ['created' => count($created), 'failed' => count($failed)],
        ]);

        return ['created' => $created, 'failed' => $failed];
    }

    // ===== Helpers =====

    /** The customers drop-down: every customer's school name, once each. */
    public static function customerNames(): array
    {
        return Customer::query()->join('schools', 'schools.id', '=', 'customers.school_id')
            ->orderBy('schools.name')->pluck('schools.name')->unique()->values()->all();
    }

    /** The items drop-down: "תוכנית: X" for each active program, then "מארז: X" for each active bundle. */
    public static function itemLabels(): array
    {
        return [
            ...Program::where('is_active', true)->orderBy('name')->pluck('name')->map(fn ($n) => self::PROGRAM_PREFIX.$n)->all(),
            ...Bundle::where('is_active', true)->orderBy('name')->pluck('name')->map(fn ($n) => self::BUNDLE_PREFIX.$n)->all(),
        ];
    }

    public static function paymentMethodNames(): array
    {
        return PaymentMethod::where('is_active', true)->orderBy('name')->pluck('name')->unique()->values()->all();
    }

    private static function signature(int $customerId, ?string $date, array $items): string
    {
        sort($items);

        return $customerId.'|'.$date.'|'.implode(',', $items);
    }

    private static function columnPositions(Worksheet $sheet): array
    {
        $columnOf = [];
        $highest = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($i = 1; $i <= $highest; $i++) {
            $header = self::normalizeHeader((string) $sheet->getCell([$i, 1])->getValue());

            foreach (self::columns() as $key => [$label]) {
                if ($header === self::normalizeHeader($label)) {
                    $columnOf[$key] ??= $i;
                }
            }
        }

        return $columnOf;
    }

    private static function cellValue(mixed $value, string $key): string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (($key === 'purchased_at' || str_ends_with($key, '_date')) && is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('d/m/Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        if (is_float($value)) {
            $value = floor($value) === $value ? number_format($value, 0, '', '') : (string) round($value, 2);
        }

        $value = CustomerImport::clean(preg_replace('/\s*\R\s*/u', $key === 'special_request' ? "\n" : ' ', (string) $value) ?? (string) $value);

        if (in_array($key, self::amountKeys(), true)) {
            $value = trim(str_replace(['₪', 'ש"ח', 'ש״ח', ',', ' '], '', $value));
        }

        return $value;
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

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    private static function normalizeHeader(string $header): string
    {
        return preg_replace('/\s+/u', ' ', trim(str_replace('*', '', $header)));
    }
}
