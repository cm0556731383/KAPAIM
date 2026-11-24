<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\DocumentTemplate;
use App\Models\Program;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Catalog import from Excel (settings → יבוא נתונים → תוכניות ומארזים,
 * 2026-11-24): one file with a "תוכניות" sheet and a "מארזים" sheet, the
 * same fields the catalog screen (⚡programs-catalog) asks for, checked by
 * the same rules before anything is saved.
 *
 * Like the customer import it only ADDS: a name already in the catalog is a
 * row error, never an update. A bundle names its programs (and, for the
 * subscription bundle, the bundles it includes) by name — from the catalog or
 * from this file. The whole import is one transaction: programs first, then
 * bundles, then what each bundle holds.
 */
class CatalogImport
{
    public const MAX_ROWS = 1000;

    public const SHEETS = ['programs' => 'תוכניות', 'bundles' => 'מארזים'];

    private const YES = ['כן', 'yes', 'y', 'true', '1', 'v', '✓'];

    private const NO = ['לא', 'no', 'n', 'false', '0'];

    /** The fields both sheets share: key => [header, required, help]. */
    private const COMMON = [
        'name' => ['שם', true, 'שם ייחודי — לא יכול להיות זהה לשם שכבר קיים בקטלוג.'],
        'description' => ['תיאור', false, ''],
        'price' => ['מחיר 1–2 כיתות', true, 'מחיר בש"ח, גדול מ־0. זה המחיר הבסיסי.'],
        'price_3_4' => ['מחיר 3–4 כיתות', false, 'ריק = כמו מחיר 1–2 כיתות.'],
        'price_5_6' => ['מחיר 5–6 כיתות', false, 'ריק = כמו מחיר 3–4 כיתות (ואם גם הוא ריק — כמו 1–2).'],
    ];

    private const TAIL = [
        'contract' => ['חוזה ברירת מחדל', false, 'בחירה מהרשימה (תבניות החוזה הפעילות). ריק = החוזה הסטנדרטי.'],
        'is_active' => ['פעיל', false, 'כן / לא. ריק = כן.'],
    ];

    private const PRICE_KEYS = ['price', 'price_3_4', 'price_5_6'];

    /** A sheet's columns, in order: key => [header, required, help]. */
    public static function columns(string $sheet): array
    {
        if ($sheet === 'programs') {
            return self::COMMON + [
                'flag' => ['פרימיום', false, 'כן / לא. ריק = לא.'],
            ] + self::TAIL;
        }

        return self::COMMON + [
            'flag' => ['מארז מנוי שנתי', false, 'כן / לא. ריק = לא. מארז מנוי פותח מנוי שנתי במכירה. אפשר כמה מארזי מנוי פעילים (למשל אחד לכל שנה).'],
            'school_year' => ['שנת לימודים', false, 'חובה למארז מנוי: השנה שהמנוי שלה — בחירה מהרשימה, או למשל תשפ"ז / 2026-2027. המנוי של כל לקוחה שתקנה את המארז ירוץ בחודשי השנה הזו.'],
        ] + self::TAIL + [
            'programs' => ['תוכניות במארז', false, 'שמות התוכניות, מופרדים בפסיק או בשורה חדשה — מהקטלוג או מגיליון "תוכניות" בקובץ הזה.'],
            'included_bundles' => ['מארזים כלולים', false, 'רק למארז מנוי: שמות מארזים (לא מנוי) שכלולים בו, מופרדים בפסיק או בשורה חדשה.'],
        ];
    }

    // ===== The blank template =====

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('יבוא תוכניות ומארזים — כפיים');
        $book->removeSheetByIndex(0);

        $lists = $book->createSheet();
        $lists->setTitle('רשימות');
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $lists->setCellValueExplicit('A1', 'כן', DataType::TYPE_STRING);
        $lists->setCellValueExplicit('A2', 'לא', DataType::TYPE_STRING);
        $contracts = self::contractNames();
        $years = array_values(HebrewCalendar::schoolYearOptions());

        foreach ($years as $i => $label) {
            $lists->setCellValueExplicit('C'.($i + 1), $label, DataType::TYPE_STRING);
        }

        foreach ($contracts as $i => $name) {
            $lists->setCellValueExplicit('B'.($i + 1), $name, DataType::TYPE_STRING);
        }

        $lastRow = self::MAX_ROWS + 1;

        foreach (self::SHEETS as $key => $title) {
            $sheet = new Worksheet($book, $title);
            $book->addSheet($sheet, $key === 'programs' ? 0 : 1);
            $sheet->setRightToLeft(true);
            $index = 0;

            foreach (self::columns($key) as $column => [$header, $required, $help]) {
                $letter = Coordinate::stringFromColumnIndex(++$index);
                $sheet->setCellValue("{$letter}1", $required ? "{$header} *" : $header);
                $sheet->getColumnDimension($letter)->setWidth(in_array($column, ['description', 'programs', 'included_bundles'], true) ? 40 : max(14, mb_strlen($header) + 6));

                $style = $sheet->getStyle("{$letter}1");
                $style->getFont()->setBold(true);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($required ? 'F6D5C8' : 'E8EEF4');
                $style->getAlignment()->setWrapText(true)->setVertical('center');

                if ($help !== '') {
                    $sheet->getComment("{$letter}1")->getText()->createTextRun($help);
                }

                if (in_array($column, self::PRICE_KEYS, true)) {
                    $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                } elseif (in_array($column, ['description', 'programs', 'included_bundles'], true)) {
                    $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getAlignment()->setWrapText(true);
                    $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }

                $list = match (true) {
                    in_array($column, ['flag', 'is_active'], true) => "'רשימות'!\$A\$1:\$A\$2",
                    $column === 'contract' && $contracts !== [] => "'רשימות'!\$B\$1:\$B\$".count($contracts),
                    $column === 'school_year' => "'רשימות'!\$C\$1:\$C\$".count($years),
                    default => null,
                };

                if ($list) {
                    $validation = new DataValidation;
                    $validation->setType(DataValidation::TYPE_LIST)
                        // A school year may also be typed in another form (2026-2027) — a warning only.
                        ->setErrorStyle($column === 'school_year' ? DataValidation::STYLE_WARNING : DataValidation::STYLE_STOP)
                        ->setAllowBlank(true)
                        ->setShowDropDown(true)
                        ->setShowErrorMessage(true)
                        ->setErrorTitle('ערך לא מהרשימה')
                        ->setError('יש לבחור ערך מהרשימה.')
                        ->setFormula1($list);
                    $sheet->setDataValidation("{$letter}2:{$letter}{$lastRow}", $validation);
                }
            }

            $sheet->getRowDimension(1)->setRowHeight(36);
            $sheet->freezePane('B2');
        }

        $this->addCatalogSheet($book);
        $this->addInstructions($book);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** What's already in the catalog — names to copy into "תוכניות במארז", and names that can't be used again. */
    private function addCatalogSheet(Spreadsheet $book): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('קיים בקטלוג');
        $sheet->setRightToLeft(true);

        $programs = Program::orderBy('name')->get(['name', 'is_active']);
        $bundles = Bundle::orderBy('name')->get(['name', 'is_active', 'is_subscription_type']);

        $rows = [['תוכניות שכבר בקטלוג', 'פעילה', '', 'מארזים שכבר בקטלוג', 'פעיל', 'מנוי']];

        for ($i = 0; $i < max($programs->count(), $bundles->count()); $i++) {
            $p = $programs[$i] ?? null;
            $b = $bundles[$i] ?? null;
            $rows[] = [
                $p?->name ?? '', $p ? ($p->is_active ? 'כן' : 'לא') : '', '',
                $b?->name ?? '', $b ? ($b->is_active ? 'כן' : 'לא') : '', $b ? ($b->is_subscription_type ? 'כן' : '') : '',
            ];
        }

        $sheet->fromArray($rows, null, 'A1', true);
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(40);
    }

    private function addInstructions(Spreadsheet $book): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('הוראות');
        $sheet->setRightToLeft(true);

        $lines = [
            ['יבוא תוכניות ומארזים — הוראות מילוי'],
            [''],
            ['• בגיליון "תוכניות" — שורה לכל תוכנית. בגיליון "מארזים" — שורה לכל מארז. אין לשנות את שורות הכותרות.'],
            ['• עמודות המסומנות ב־* (ובצבע כתום) הן חובה. אפשר למלא רק אחד מהגיליונות.'],
            ['• היבוא רק מוסיף: שם שכבר קיים בקטלוג (ראו גיליון "קיים בקטלוג") לא ייובא, וכך גם שם שמופיע פעמיים בקובץ. שינוי תוכנית קיימת — במסך הקטלוג.'],
            ['• "תוכניות במארז": שמות התוכניות מופרדים בפסיק או בשורה חדשה (Alt+Enter). אפשר לכתוב תוכנית שכבר בקטלוג או תוכנית מגיליון "תוכניות" בקובץ הזה.'],
            ['• "מארזים כלולים" — רק במארז מנוי שנתי: מארזים רגילים שהתוכניות שלהם נכללות במנוי.'],
            ['• אפשר כמה מארזי מנוי פעילים — למשל מארז מנוי לכל שנה. למארז מנוי חובה למלא "שנת לימודים": המנוי של כל לקוחה שתקנה אותו ירוץ בחודשי השנה הזו, גם אם נמכר מראש.'],
            ['• לפני היבוא המערכת בודקת את כל הקובץ ומציגה את השגיאות לפי גיליון ושורה. אפשר לתקן אותן במסך עצמו או בקובץ ולהעלות שוב.'],
            [''],
            ['גיליון', 'עמודה', 'חובה', 'הסבר'],
        ];

        $headerRow = count($lines);

        foreach (self::SHEETS as $key => $title) {
            foreach (self::columns($key) as [$header, $required, $help]) {
                $lines[] = [$title, $header, $required ? 'כן' : '', $help];
            }
        }

        $sheet->fromArray($lines, null, 'A1', true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A{$headerRow}:D{$headerRow}")->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(8);
        $sheet->getColumnDimension('D')->setWidth(80);
    }

    // ===== Checking an uploaded file =====

    /**
     * Reads and checks a filled file. Returns:
     *   'fileError' => why the file can't be imported at all, else null
     *   'rows'      => [['sheet' => programs|bundles, 'row' => n, 'name' => ..., 'data' => normalized|null,
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
            $read = [];
            $foundSheet = false;

            foreach (self::SHEETS as $key => $title) {
                $sheet = $book->getSheetByName($title);

                if (! $sheet) {
                    continue;
                }

                $foundSheet = true;
                $columnOf = self::columnPositions($sheet, $key);
                $missing = collect(self::columns($key))->filter(fn ($c, $k) => $c[1] && ! isset($columnOf[$k]))->map(fn ($c) => $c[0]);

                if ($missing->isNotEmpty()) {
                    return ['fileError' => "בגיליון \"{$title}\" חסרות עמודות חובה: ".$missing->implode(', ').'. יש להשתמש בקובץ שהורד מכאן.', 'rows' => []];
                }

                $highestRow = $sheet->getHighestDataRow();

                for ($r = 2; $r <= $highestRow; $r++) {
                    $values = [];

                    foreach (array_keys(self::columns($key)) as $column) {
                        $values[$column] = isset($columnOf[$column]) ? self::cellValue($sheet->getCell([$columnOf[$column], $r])->getValue(), $column) : '';
                    }

                    if (collect($values)->every(fn ($v) => $v === '')) {
                        continue;
                    }

                    if (count($read) >= self::MAX_ROWS) {
                        return ['fileError' => 'בקובץ יותר מ־'.self::MAX_ROWS.' שורות. יש לפצל אותו לכמה קבצים.', 'rows' => []];
                    }

                    $read[] = ['sheet' => $key, 'row' => $r, 'name' => $values['name'], 'raw' => $values];
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }

        if (! $foundSheet) {
            return ['fileError' => 'בקובץ אין גיליון "תוכניות" או "מארזים". יש להשתמש בקובץ שהורד מכאן.', 'rows' => []];
        }

        if ($read === []) {
            return ['fileError' => 'לא נמצאו בקובץ שורות למילוי.', 'rows' => []];
        }

        return ['fileError' => null, 'rows' => $this->checkRows($read)];
    }

    private function checkRows(array $rows): array
    {
        $contracts = collect(self::contractNames())->mapWithKeys(fn ($name) => [self::key($name) => $name]);
        $existingPrograms = Program::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [self::key($name) => $id]);
        $existingBundles = Bundle::get(['id', 'name', 'is_subscription_type'])->keyBy(fn ($b) => self::key($b->name));

        $messages = [
            'required' => 'שדה חובה — חסר.',
            'max' => 'ארוך מדי (עד :max תווים).',
            'numeric' => 'צריך להיות מספר (למשל 1200 או 1200.50).',
            'gt' => 'צריך להיות גדול מ־0.',
            'price.max' => 'המחיר גבוה מדי.',
            'price_3_4.max' => 'המחיר גבוה מדי.',
            'price_5_6.max' => 'המחיר גבוה מדי.',
        ];

        // Pass 1: each row on its own, and names unique against the catalog and the file.
        $seen = ['programs' => [], 'bundles' => []];

        foreach ($rows as &$row) {
            $v = $row['raw'];
            $errors = [];

            $validator = Validator::make($v, [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'price' => ['required', 'numeric', 'gt:0', Money::maxRule()],
                'price_3_4' => ['nullable', 'numeric', 'gt:0', Money::maxRule()],
                'price_5_6' => ['nullable', 'numeric', 'gt:0', Money::maxRule()],
            ], $messages);

            foreach ($validator->errors()->messages() as $key => $list) {
                $errors[$key] = $list[0];
            }

            foreach (['flag', 'is_active'] as $key) {
                if ($v[$key] !== '' && self::flag($v[$key]) === null) {
                    $errors[$key] = 'יש לכתוב כן או לא.';
                }
            }

            if ($row['sheet'] === 'bundles') {
                $isSubscription = self::flag($v['flag']) === true;

                if ($v['school_year'] === '' && $isSubscription) {
                    $errors['school_year'] = 'חובה למארז מנוי — יש לבחור את שנת הלימודים.';
                } elseif ($v['school_year'] !== '' && HebrewCalendar::parseSchoolYear($v['school_year']) === null) {
                    $errors['school_year'] = 'שנה לא מזוהה — יש לבחור מהרשימה, או לכתוב למשל תשפ"ז או 2026-2027.';
                } elseif ($v['school_year'] !== '' && ! $isSubscription && ! isset($errors['flag'])) {
                    $errors['school_year'] = 'שנת לימודים רק למארז מנוי — יש לכתוב "כן" ב"מארז מנוי שנתי" או למחוק את השנה.';
                }
            }

            if ($v['contract'] !== '' && ! isset($contracts[self::key($v['contract'])])) {
                $errors['contract'] = 'אין תבנית חוזה פעילה בשם זה — יש לבחור מהרשימה.';
            }

            $name = self::key($v['name']);
            $existing = $row['sheet'] === 'programs' ? isset($existingPrograms[$name]) : isset($existingBundles[$name]);

            if ($name !== '' && ! isset($errors['name'])) {
                if ($existing) {
                    $errors['name'] = ($row['sheet'] === 'programs' ? 'תוכנית' : 'מארז').' בשם זה כבר קיים בקטלוג — לא ייובא.';
                } elseif (isset($seen[$row['sheet']][$name])) {
                    $errors['name'] = "השם מופיע כבר בשורה {$seen[$row['sheet']][$name]} בגיליון.";
                }

                $seen[$row['sheet']][$name] ??= $row['row'];
            }

            $row['errors'] = $errors;
        }
        unset($row);

        // What a bundle may name: the catalog's programs/bundles and this file's rows (by index into $rows).
        $fileIndex = ['programs' => [], 'bundles' => []];

        foreach ($rows as $i => $row) {
            $name = self::key($row['raw']['name']);

            if ($name !== '') {
                $fileIndex[$row['sheet']][$name] ??= $i;
            }
        }

        // Pass 2: each bundle's programs.
        foreach ($rows as &$row) {
            if ($row['sheet'] !== 'bundles') {
                continue;
            }

            $v = $row['raw'];
            $problems = [];

            foreach (self::names($v['programs']) as $program) {
                $key = self::key($program);

                if (isset($existingPrograms[$key])) {
                    continue;
                }

                $i = $fileIndex['programs'][$key] ?? null;

                if ($i === null) {
                    $problems[] = "\"{$program}\" לא נמצאה בקטלוג ולא בגיליון התוכניות";
                } elseif ($rows[$i]['errors']) {
                    $problems[] = "\"{$program}\" (גיליון תוכניות, שורה {$rows[$i]['row']}) עדיין עם שגיאות";
                }
            }

            if ($problems) {
                $row['errors']['programs'] = 'תוכנית '.implode('; ', $problems).'.';
            }
        }
        unset($row);

        // Pass 3: bundles included in the subscription bundle (after pass 2, so an included bundle's own errors are known).
        foreach ($rows as &$row) {
            $v = $row['raw'];
            $included = $row['sheet'] === 'bundles' ? self::names($v['included_bundles']) : [];

            if (! $included) {
                continue;
            }

            if (self::flag($v['flag']) !== true) {
                $row['errors']['included_bundles'] = 'רק מארז מנוי שנתי יכול לכלול מארזים אחרים.';

                continue;
            }

            $problems = [];

            foreach ($included as $bundle) {
                $key = self::key($bundle);
                $i = $fileIndex['bundles'][$key] ?? null;

                if ($key === self::key($v['name'])) {
                    $problems[] = 'מארז אינו יכול לכלול את עצמו';
                } elseif ($catalog = $existingBundles[$key] ?? null) {
                    if ($catalog->is_subscription_type) {
                        $problems[] = "\"{$bundle}\" הוא מארז מנוי — אי אפשר לכלול אותו";
                    }
                } elseif ($i === null) {
                    $problems[] = "\"{$bundle}\" לא נמצא בקטלוג ולא בגיליון המארזים";
                } elseif (self::flag($rows[$i]['raw']['flag']) === true) {
                    $problems[] = "\"{$bundle}\" הוא מארז מנוי — אי אפשר לכלול אותו";
                } elseif ($rows[$i]['errors'] || self::names($rows[$i]['raw']['included_bundles'])) {
                    $problems[] = "\"{$bundle}\" (שורה {$rows[$i]['row']}) עדיין עם שגיאות";
                }
            }

            if ($problems) {
                $row['errors']['included_bundles'] = implode('; ', $problems).'.';
            }
        }
        unset($row);

        foreach ($rows as &$row) {
            $row['data'] = $row['errors'] === [] ? self::normalize($row['sheet'], $row['raw'], $contracts) : null;
            $row['values'] = $row['errors'] === [] ? null : $row['raw'];
            unset($row['raw']);
        }

        return $rows;
    }

    private static function normalize(string $sheet, array $v, $contracts): array
    {
        $price = fn (string $value) => $value === '' ? null : round((float) $value, 2);
        $contract = $v['contract'] === ''
            ? DocumentTemplate::standardContract()?->id
            : DocumentTemplate::where('is_active', true)->where('document_type', 'contract')->where('name', $contracts[self::key($v['contract'])])->orderBy('id')->value('id');

        $data = [
            'name' => $v['name'],
            'description' => $v['description'] === '' ? null : $v['description'],
            'price' => $price($v['price']),
            'price_3_4' => $price($v['price_3_4']),
            'price_5_6' => $price($v['price_5_6']),
            'contract_template_id' => $contract,
            'is_active' => self::flag($v['is_active']) !== false,
        ];

        if ($sheet === 'programs') {
            return $data + ['is_premium' => self::flag($v['flag']) === true];
        }

        return $data + [
            'is_subscription_type' => self::flag($v['flag']) === true,
            'school_year' => HebrewCalendar::parseSchoolYear($v['school_year']),
            'programs' => self::names($v['programs']),
            'included_bundles' => self::names($v['included_bundles']),
        ];
    }

    // ===== Fixing rows from the check screen =====

    /**
     * Writes values fixed on the check screen back into the uploaded file
     * (the file stays the one source the import reads). $fixes =
     * [sheet key => [row => [column key => value]]].
     */
    public function applyFixes(string $path, array $fixes): void
    {
        $book = IOFactory::load($path);

        try {
            foreach ($fixes as $sheetKey => $rows) {
                $sheet = isset(self::SHEETS[$sheetKey]) ? $book->getSheetByName(self::SHEETS[$sheetKey]) : null;

                if (! $sheet || ! is_array($rows)) {
                    continue;
                }

                $columns = self::columns($sheetKey);
                $columnOf = self::columnPositions($sheet, $sheetKey);
                $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

                foreach ($rows as $row => $values) {
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
            }

            IOFactory::createWriter($book, 'Xlsx')->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    // ===== The import =====

    /**
     * Imports the rows that passed analyze(), all in one transaction — a
     * bundle and the programs it names arrive together or not at all.
     *
     * @return array{programs: int, bundles: int}
     */
    public function import(array $rows, User $user, ActivityLogger $activityLogger): array
    {
        $valid = array_values(array_filter($rows, fn ($row) => $row['data'] !== null));

        $result = DB::transaction(function () use ($valid) {
            $programs = [];
            $bundles = [];

            foreach ($valid as $row) {
                if ($row['sheet'] !== 'programs') {
                    continue;
                }

                if (Program::whereRaw('LOWER(TRIM(name)) = ?', [self::key($row['data']['name'])])->exists()) {
                    throw new \RuntimeException("התוכנית \"{$row['data']['name']}\" נוספה לקטלוג בינתיים — יש לבדוק את הקובץ שוב.");
                }

                $programs[] = Program::create($row['data']);
            }

            foreach ($valid as $row) {
                if ($row['sheet'] !== 'bundles') {
                    continue;
                }

                if (Bundle::whereRaw('LOWER(TRIM(name)) = ?', [self::key($row['data']['name'])])->exists()) {
                    throw new \RuntimeException("המארז \"{$row['data']['name']}\" נוסף לקטלוג בינתיים — יש לבדוק את הקובץ שוב.");
                }

                $bundles[] = [Bundle::create(collect($row['data'])->except(['programs', 'included_bundles'])->all()), $row['data']];
            }

            $programIds = Program::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [self::key($name) => $id]);
            $bundleIds = Bundle::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [self::key($name) => $id]);

            $idsOf = function (array $names, $ids, string $what) {
                return collect($names)->map(fn ($name) => $ids[self::key($name)]
                    ?? throw new \RuntimeException("{$what} \"{$name}\" לא נמצא/ה — יש לבדוק את הקובץ שוב."))->unique()->values();
            };

            foreach ($bundles as [$bundle, $data]) {
                $bundle->programs()->sync($idsOf($data['programs'], $programIds, 'התוכנית'));
                $bundle->includedBundles()->sync($idsOf($data['included_bundles'], $bundleIds, 'המארז'));
            }

            return ['programs' => $programs, 'bundles' => array_column($bundles, 0)];
        });

        $activityLogger->log('catalog.imported', 'יובאו מקובץ אקסל '.count($result['programs']).' תוכניות ו־'.count($result['bundles']).' מארזים', [
            'user' => $user,
            'metadata' => [
                'program_ids' => array_map(fn ($p) => $p->id, $result['programs']),
                'bundle_ids' => array_map(fn ($b) => $b->id, $result['bundles']),
            ],
        ]);

        return ['programs' => count($result['programs']), 'bundles' => count($result['bundles'])];
    }

    // ===== Helpers =====

    /** The active contract templates' names, once each — the "חוזה ברירת מחדל" pick list. */
    public static function contractNames(): array
    {
        return DocumentTemplate::where('is_active', true)->where('document_type', 'contract')->orderBy('name')->pluck('name')->unique()->values()->all();
    }

    private static function columnPositions(Worksheet $sheet, string $key): array
    {
        $columnOf = [];
        $highest = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($i = 1; $i <= $highest; $i++) {
            $header = self::normalizeHeader((string) $sheet->getCell([$i, 1])->getValue());

            foreach (self::columns($key) as $column => [$label]) {
                if ($header === self::normalizeHeader($label)) {
                    $columnOf[$column] ??= $i;
                }
            }
        }

        return $columnOf;
    }

    private static function cellValue(mixed $value, string $column): string
    {
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }

        if (is_float($value)) {
            $value = floor($value) === $value ? number_format($value, 0, '', '') : (string) round($value, 2);
        }

        $value = (string) $value;

        // Line breaks separate names in the list columns; elsewhere they're spaces.
        if (! in_array($column, ['programs', 'included_bundles', 'description'], true)) {
            $value = preg_replace('/\s*\R\s*/u', ' ', $value) ?? $value;
        }

        $value = in_array($column, ['description', 'programs', 'included_bundles'], true)
            ? trim(preg_replace('/[^\S\n]+/u', ' ', preg_replace('/\p{Cf}+/u', '', str_replace("\r", '', $value)) ?? $value) ?? $value)
            : CustomerImport::clean($value);

        // "₪1,200" / "1,200 ש"ח" → 1200.
        if (in_array($column, self::PRICE_KEYS, true)) {
            $value = trim(str_replace(['₪', 'ש"ח', 'ש״ח', ',', ' '], '', $value));
        }

        return $value;
    }

    /** A list cell's names: split on commas, semicolons and line breaks. */
    private static function names(string $value): array
    {
        return collect(preg_split('/[,;،\n]+/u', $value) ?: [])
            ->map(fn ($name) => trim($name))
            ->filter(fn ($name) => $name !== '')
            ->unique(fn ($name) => self::key($name))
            ->values()
            ->all();
    }

    /** כן → true, לא → false, anything else → null. */
    private static function flag(string $value): ?bool
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, self::YES, true) ? true : (in_array($value, self::NO, true) ? false : null);
    }

    /** Names compared without case and extra spaces. */
    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    private static function normalizeHeader(string $header): string
    {
        return preg_replace('/\s+/u', ' ', trim(str_replace('*', '', $header)));
    }
}
