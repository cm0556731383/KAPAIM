<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\DocumentTemplate;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Services\CatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private DocumentTemplate $standard;

    private DocumentTemplate $premiumContract;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);

        $this->standard = DocumentTemplate::create(['document_type' => 'contract', 'name' => DocumentTemplate::STANDARD_CONTRACT_NAME, 'content' => 'x', 'is_active' => true]);
        $this->premiumContract = DocumentTemplate::create(['document_type' => 'contract', 'name' => 'חוזה פרימיום', 'content' => 'x', 'is_active' => true]);
    }

    /** A filled copy of the real template — ['programs' => [rows], 'bundles' => [rows]], rows as key => value. */
    private function filledFile(array $sheets): UploadedFile
    {
        $book = app(CatalogImport::class)->template();

        foreach ($sheets as $key => $rows) {
            $sheet = $book->getSheetByName(CatalogImport::SHEETS[$key]);
            $keys = array_keys(CatalogImport::columns($key));

            foreach ($rows as $i => $row) {
                foreach ($row as $column => $value) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex(array_search($column, $keys, true) + 1).($i + 2), $value);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'cat').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $file = UploadedFile::fake()->createWithContent('catalog.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    private function screen()
    {
        return Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')->set('importKind', 'catalog');
    }

    public function test_the_template_has_a_programs_sheet_and_a_bundles_sheet_with_every_catalog_field(): void
    {
        Program::create(['name' => 'קיימת', 'price' => 100, 'is_active' => true]);

        $response = $this->actingAs($this->owner)->get(route('settings.import-catalog-template'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $book = IOFactory::load($path);

        $programs = $book->getSheetByName('תוכניות');
        $bundles = $book->getSheetByName('מארזים');
        $this->assertSame(0, $book->getIndex($programs));
        $this->assertSame('שם *', $programs->getCell('A1')->getValue());
        $this->assertSame('מחיר 1–2 כיתות *', $programs->getCell('C1')->getValue());
        $this->assertSame(count(CatalogImport::columns('programs')), Coordinate::columnIndexFromString($programs->getHighestDataColumn()));
        $this->assertSame(count(CatalogImport::columns('bundles')), Coordinate::columnIndexFromString($bundles->getHighestDataColumn()));
        $this->assertSame('קיימת', $book->getSheetByName('קיים בקטלוג')->getCell('A2')->getValue());
        $this->assertNotNull($book->getSheetByName('הוראות'));
    }

    public function test_programs_and_bundles_are_checked_then_imported_with_the_programs_each_bundle_holds(): void
    {
        $existing = Program::create(['name' => 'תוכנית ותיקה', 'price' => 300, 'is_active' => true]);

        $this->screen()
            ->set('catalogFile', $this->filledFile([
                'programs' => [
                    ['name' => 'מוגנות', 'description' => 'תוכנית שנתית', 'price' => 1200, 'price_3_4' => '₪1,500', 'flag' => 'כן', 'contract' => 'חוזה פרימיום'],
                    ['name' => 'זהירות בדרכים', 'price' => 800.5, 'is_active' => 'לא'],
                ],
                'bundles' => [
                    ['name' => 'מארז בסיס', 'price' => 1800, 'programs' => "מוגנות\nתוכנית ותיקה"],
                    ['name' => 'מנוי שנתי', 'price' => 5000, 'price_5_6' => 7000, 'flag' => 'כן', 'school_year' => 'תשפ"ח', 'programs' => 'זהירות בדרכים', 'included_bundles' => 'מארז בסיס'],
                ],
            ]))
            ->call('checkCatalogImport')
            ->assertHasNoErrors()
            ->assertSet('catalogCheck.fileError', null)
            ->assertSet('catalogCheck.invalid', [])
            ->tap(fn () => $this->assertSame(1, Program::count()))
            ->call('runCatalogImport')
            ->assertSee('היבוא הסתיים: נוספו 2 תוכניות ו־2 מארזים');

        $program = Program::where('name', 'מוגנות')->sole();
        $this->assertSame('תוכנית שנתית', $program->description);
        $this->assertSame('1200.00', $program->price);
        $this->assertSame('1500.00', $program->price_3_4);
        $this->assertNull($program->price_5_6);
        $this->assertTrue($program->is_premium);
        $this->assertTrue($program->is_active);
        $this->assertSame($this->premiumContract->id, $program->contract_template_id);

        $road = Program::where('name', 'זהירות בדרכים')->sole();
        $this->assertSame('800.50', $road->price);
        $this->assertFalse($road->is_active);
        $this->assertSame($this->standard->id, $road->contract_template_id);

        $basic = Bundle::where('name', 'מארז בסיס')->sole();
        $this->assertEqualsCanonicalizing([$program->id, $existing->id], $basic->programs->pluck('id')->all());
        $this->assertFalse($basic->is_subscription_type);

        $subscription = Bundle::where('name', 'מנוי שנתי')->sole();
        $this->assertTrue($subscription->is_subscription_type);
        $this->assertSame(5788, $subscription->school_year);
        $this->assertNull($basic->school_year);
        $this->assertSame('7000.00', $subscription->price_5_6);
        $this->assertSame([$basic->id], $subscription->includedBundles->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$road->id, $program->id, $existing->id], $subscription->allPrograms()->pluck('id')->all());

        $this->assertTrue(ActivityLog::where('activity_type', 'catalog.imported')->exists());
        Storage::disk('local')->assertDirectoryEmpty('imports');
    }

    public function test_errors_are_listed_per_sheet_and_row_and_fixed_on_screen(): void
    {
        Program::create(['name' => 'קיימת', 'price' => 100, 'is_active' => true]);

        $component = $this->screen()
            ->set('catalogFile', $this->filledFile([
                'programs' => [
                    ['name' => 'קיימת', 'price' => 100],
                    ['name' => 'חדשה', 'price' => 'אלף', 'flag' => 'אולי', 'contract' => 'אין כזה'],
                    ['name' => 'חדשה', 'price' => 50],
                ],
                'bundles' => [
                    ['name' => 'מארז', 'price' => 900, 'programs' => 'חדשה, לא קיימת', 'included_bundles' => 'משהו'],
                ],
            ]))
            ->call('checkCatalogImport')
            ->assertSee('כבר קיים בקטלוג')
            ->assertSee('השם מופיע כבר בשורה 3')
            ->assertSee('wire:model="catalogFixes.programs.3.price"', false);

        $invalid = collect($component->get('catalogCheck.invalid'))->keyBy(fn ($r) => $r['sheet'].$r['row']);
        $this->assertSame(['name'], array_keys($invalid['programs2']['fields']));
        $this->assertSame(['price', 'flag', 'contract'], array_keys($invalid['programs3']['fields']));
        $this->assertSame(['name'], array_keys($invalid['programs4']['fields']));
        $this->assertSame(['programs', 'included_bundles'], array_keys($invalid['bundles2']['fields']));
        $this->assertStringContainsString('"לא קיימת" לא נמצאה', $invalid['bundles2']['fields']['programs']['message']);
        $this->assertStringContainsString('עדיין עם שגיאות', $invalid['bundles2']['fields']['programs']['message']);
        $this->assertSame('רק מארז מנוי שנתי יכול לכלול מארזים אחרים.', $invalid['bundles2']['fields']['included_bundles']['message']);
        $this->assertSame('אלף', $component->get('catalogFixes')['programs'][3]['price']);

        $component
            ->set('catalogFixes.programs.2.name', 'אחרת')
            ->set('catalogFixes.programs.3.price', '1,000')
            ->set('catalogFixes.programs.3.flag', 'כן')
            ->set('catalogFixes.programs.3.contract', '')
            ->set('catalogFixes.programs.4.name', 'שלישית')
            ->set('catalogFixes.bundles.2.programs', "חדשה\nקיימת")
            ->set('catalogFixes.bundles.2.included_bundles', '')
            ->call('fixCatalogImport')
            ->assertSet('catalogCheck.invalid', [])
            ->call('runCatalogImport')
            ->assertSee('נוספו 3 תוכניות ו־1 מארזים');

        $this->assertSame('1000.00', Program::where('name', 'חדשה')->value('price'));
        $this->assertTrue(Program::where('name', 'חדשה')->value('is_premium'));
        $this->assertEqualsCanonicalizing(['חדשה', 'קיימת'], Bundle::where('name', 'מארז')->sole()->programs->pluck('name')->all());
    }

    public function test_several_subscription_bundles_are_allowed_and_only_valid_rows_are_imported(): void
    {
        Bundle::create(['name' => 'מנוי ישן', 'price' => 100, 'is_subscription_type' => true, 'is_active' => true]);

        $component = $this->screen()
            ->set('catalogFile', $this->filledFile([
                'programs' => [['name' => 'א', 'price' => 10], ['name' => '', 'price' => 10, 'description' => 'בלי שם']],
                'bundles' => [
                    ['name' => 'מנוי תשפ"ו', 'price' => 100, 'flag' => 'כן', 'school_year' => '2025-2026', 'programs' => 'א'],
                    ['name' => 'מנוי תשפ"ז', 'price' => 120, 'flag' => 'כן', 'school_year' => '5787'],
                    ['name' => 'מנוי מושבת', 'price' => 100, 'flag' => 'כן', 'school_year' => 'תשפ"ה', 'is_active' => 'לא', 'included_bundles' => 'מנוי ישן'],
                ],
            ]))
            ->call('checkCatalogImport');

        $invalid = collect($component->get('catalogCheck.invalid'))->keyBy(fn ($r) => $r['sheet'].$r['row']);
        $this->assertSame(['programs3', 'bundles4'], $invalid->keys()->all());
        $this->assertStringContainsString('הוא מארז מנוי', $invalid['bundles4']['fields']['included_bundles']['message']);
        $this->assertSame(['name'], array_keys($invalid['programs3']['fields']));

        $component->call('runCatalogImport')->assertSee('נוספו 1 תוכניות ו־2 מארזים')->assertSee('2 שורות עם שגיאות לא יובאו');
        $this->assertSame(['א'], Program::pluck('name')->all());
        $this->assertSame(3, Bundle::where('is_subscription_type', true)->where('is_active', true)->count());
    }

    public function test_a_subscription_bundle_needs_a_school_year_and_a_plain_one_cannot_have_one(): void
    {
        $component = $this->screen()
            ->set('catalogFile', $this->filledFile(['bundles' => [
                ['name' => 'מנוי בלי שנה', 'price' => 100, 'flag' => 'כן'],
                ['name' => 'מנוי שנה שגויה', 'price' => 100, 'flag' => 'כן', 'school_year' => 'בקרוב'],
                ['name' => 'רגיל עם שנה', 'price' => 100, 'school_year' => 'תשפ"ז'],
            ]]))
            ->call('checkCatalogImport');

        $invalid = collect($component->get('catalogCheck.invalid'))->keyBy('row');
        $this->assertStringContainsString('חובה למארז מנוי', $invalid[2]['fields']['school_year']['message']);
        $this->assertStringContainsString('שנה לא מזוהה', $invalid[3]['fields']['school_year']['message']);
        $this->assertStringContainsString('רק למארז מנוי', $invalid[4]['fields']['school_year']['message']);

        $component->set('catalogFixes.bundles.2.school_year', '5788')
            ->set('catalogFixes.bundles.3.school_year', '5789')
            ->set('catalogFixes.bundles.4.school_year', '')
            ->call('fixCatalogImport')
            ->assertSet('catalogCheck.invalid', [])
            ->call('runCatalogImport');

        $this->assertSame([5788, 5789, null], Bundle::orderBy('id')->pluck('school_year')->all());
    }

    public function test_a_file_without_the_sheets_is_rejected(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->setTitle('גיליון1')->fromArray([['שם', 'מחיר'], ['x', 1]]);
        $path = tempnam(sys_get_temp_dir(), 'cat').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->screen()
            ->set('catalogFile', UploadedFile::fake()->createWithContent('x.xlsx', file_get_contents($path)))
            ->call('checkCatalogImport')
            ->assertSee('בקובץ אין גיליון "תוכניות" או "מארזים"');
    }

    public function test_importing_needs_edit_rights_in_settings_and_in_the_catalog(): void
    {
        $role = Role::create(['name' => 'הגדרות בלבד', 'is_active' => true]);
        $role->permissions()->create(['resource' => 'settings', 'action' => '*', 'is_allowed' => true]);
        $role->permissions()->create(['resource' => 'catalog', 'action' => 'view', 'is_allowed' => true]);
        $user = User::create(['name' => 'מזכירה', 'email' => 's@kapaim.test', 'password' => 'password', 'role_id' => $role->id, 'is_active' => true]);

        Livewire::actingAs($user)->test('settings')->set('activeTab', 'import')->set('importKind', 'catalog')
            ->set('catalogFile', $this->filledFile(['programs' => [['name' => 'א', 'price' => 10]]]))
            ->call('checkCatalogImport')
            ->assertSee('ליבוא עצמו נדרשת הרשאת עריכה בהגדרות ובקטלוג')
            ->call('runCatalogImport');

        $this->assertSame(0, Program::count());
    }
}
