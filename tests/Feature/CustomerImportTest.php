<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\CustomerImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // The import runs as a background job — here, right away.
        config(['queue.default' => 'sync']);

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);

        StatusDefinition::create(['scope' => 'customer', 'name' => 'פעילה', 'is_active' => true, 'sort_order' => 1]);
        StatusDefinition::create(['scope' => 'customer', 'name' => 'לא פעילה', 'is_active' => true, 'sort_order' => 2]);
        LeadSource::create(['name' => 'פייסבוק', 'is_active' => true]);

        $this->connectSmove();
    }

    /** The Smove list's contacts as the fake API returns them; null = the list can't be read. */
    private ?array $smoveList = [
        ['id' => 900, 'email' => 'Rachel@Orot.test', 'canReceiveEmails' => true],
        ['id' => 902, 'email' => 'books@orot.test', 'canReceiveEmails' => true],
        ['id' => 903, 'email' => 'n@noam.test', 'canReceiveEmails' => true],
        ['id' => 901, 'email' => 'gone@noam.test', 'canReceiveEmails' => false],
    ];

    private function connectSmove(): void
    {
        \App\Models\ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x', 'list_id' => 111]]);

        \Illuminate\Support\Facades\Http::fake(function ($request) {
            if (str_contains($request->url(), '/Lists/111/Contacts')) {
                return $this->smoveList === null
                    ? \Illuminate\Support\Facades\Http::response(['message' => 'down'], 500)
                    : \Illuminate\Support\Facades\Http::response($this->smoveList, 200);
            }

            return \Illuminate\Support\Facades\Http::response($request->method() === 'GET' ? [] : ['id' => 42], 200);
        });
    }

    private function postsToSmoveContacts(): int
    {
        return \Illuminate\Support\Facades\Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/Contacts'))->count();
    }

    protected function validRow(array $overrides = []): array
    {
        return array_merge([
            'school_name' => 'אורות',
            'city' => 'ירושלים',
            'school_phone' => '02-1234567',
            'business_number' => '580012345',
            'status' => 'לא פעילה',
            'lead_source' => 'פייסבוק',
            'converted_at' => '15/03/2025',
            'classes_per_grade' => '3',
            'gefen_budget' => 'כן',
            'content_format' => 'וידאו',
            'contact1_name' => 'רחל כהן',
            'contact1_role' => 'מנהלת',
            'contact1_phone' => '0501234567',
            'contact1_email' => 'rachel@orot.test',
            'contact2_name' => 'לאה לוי',
            'contact2_email' => 'books@orot.test',
            'contact2_accounting' => 'כן',
        ], $overrides);
    }

    /** A filled copy of the real template — rows as key => value. */
    protected function filledFile(array $rows): UploadedFile
    {
        $book = app(CustomerImport::class)->template();
        $sheet = $book->getSheet(0);

        foreach ($rows as $i => $row) {
            foreach ($row as $key => $value) {
                $sheet->setCellValue(CustomerImport::columnLetter($key).($i + 2), $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $file = UploadedFile::fake()->createWithContent('customers.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    public function test_the_template_downloads_with_every_customer_card_column(): void
    {
        $response = $this->actingAs($this->owner)->get(route('settings.import-template'))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getSheet(0);

        $this->assertSame('לקוחות', $sheet->getTitle());
        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('שם המוסד *', $sheet->getCell('A1')->getValue());
        $this->assertSame(count(CustomerImport::columns()), \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
    }

    public function test_a_valid_file_is_checked_then_imported_as_customers_with_their_contacts(): void
    {
        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([$this->validRow(), $this->validRow(['school_name' => 'נועם', 'business_number' => '', 'contact1_email' => 'n@noam.test', 'contact2_name' => '', 'contact2_email' => ''])]))
            ->call('checkImport')
            ->assertHasNoErrors()
            ->assertSet('importCheck.valid', 2)
            ->assertSet('importCheck.errors', [])
            ->tap(fn () => $this->assertSame(0, Customer::count()))
            ->call('runImport')
            ->assertSee('היבוא הסתיים: נוספו 2 לקוחות');

        $this->assertSame(2, Customer::count());

        $school = School::where('name', 'אורות')->first();
        $this->assertSame('ירושלים', $school->city);
        $this->assertSame(3, $school->classes_per_grade);
        $this->assertTrue($school->gefen_budget);
        $this->assertSame('video', $school->content_format);

        $customer = $school->customer;
        $this->assertSame('לא פעילה', $customer->status->name);
        $this->assertSame('2025-03-15', $customer->converted_at->toDateString());
        $this->assertSame(LeadSource::where('name', 'פייסבוק')->value('id'), $customer->lead->lead_source_id);
        $this->assertNotNull($customer->lead->converted_at);

        $primary = $customer->contacts()->where('is_primary', true)->sole();
        $this->assertSame('רחל כהן', $primary->name);
        $this->assertSame('0501234567', $primary->phone);
        $this->assertSame('לאה לוי', $customer->contacts()->where('is_accounting_contact', true)->sole()->name);

        $this->assertSame(1, School::where('name', 'נועם')->first()->customer->contacts()->count());
        $this->assertTrue(ActivityLog::where('activity_type', 'customers.imported')->exists());
        Storage::disk('local')->assertDirectoryEmpty('imports');
    }

    public function test_each_row_takes_several_contacts_each_with_its_own_primary_and_accounting_answer(): void
    {
        $this->smoveList[] = ['id' => 904, 'email' => 'third@orot.test', 'canReceiveEmails' => true];

        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([$this->validRow([
                'contact1_primary' => 'לא', 'contact1_accounting' => 'כן',
                'contact2_primary' => 'כן', 'contact2_accounting' => 'כן', 'contact2_phone' => '0527654321',
                'contact3_name' => 'דינה', 'contact3_email' => 'third@orot.test', 'contact3_primary' => 'כן', 'contact3_phone' => '0531112222',
            ])]))
            ->call('checkImport')
            ->assertSet('importCheck.errors', [])
            ->call('runImport');

        $contacts = School::where('name', 'אורות')->first()->customer->contacts()->orderBy('id')->get();

        $this->assertSame(['רחל כהן', 'לאה לוי', 'דינה'], $contacts->pluck('name')->all());
        $this->assertSame([false, true, true], $contacts->pluck('is_primary')->all());
        $this->assertSame([true, true, false], $contacts->pluck('is_accounting_contact')->all());
        $this->assertTrue($contacts[0]->isAccountingOnly());
        // The lead is opened from the first primary contact.
        $this->assertSame('books@orot.test', Lead::sole()->email);
        $this->assertSame(904, (int) $contacts[2]->smove_contact_id);
    }

    public function test_contact_flags_must_be_yes_or_no_and_a_primary_contact_needs_phone_and_email(): void
    {
        $component = Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([
                $this->validRow(['contact1_primary' => 'אולי', 'contact2_accounting' => 'בטח']),
                $this->validRow(['school_name' => 'נועם', 'business_number' => '', 'contact2_primary' => 'כן']),
                $this->validRow(['school_name' => 'שלישי', 'business_number' => '', 'contact1_name' => '', 'contact1_phone' => '', 'contact1_email' => '', 'contact1_role' => '']),
            ]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 0);

        $errors = collect($component->get('importCheck.errors'))->groupBy('row')->map(fn ($e) => $e->pluck('message', 'column')->all());

        $this->assertSame('יש לכתוב כן או לא.', $errors[2]['איש קשר 1 — ראשי']);
        $this->assertSame('יש לכתוב כן או לא.', $errors[2]['איש קשר 2 — גורם חשבונאי']);
        $this->assertSame('חובה לאיש קשר ראשי — חסר.', $errors[3]['איש קשר 2 — טלפון']);
        // At least one contact: contact 1's name is required.
        $this->assertSame('שדה חובה — חסר.', $errors[4]['איש קשר 1 — שם']);
    }

    /** 2026-11-17: a contact email another customer (or another row) already uses is imported, but shown as a warning — Smove holds one contact per email. */
    public function test_a_contact_email_another_customer_already_uses_is_a_warning_not_an_error(): void
    {
        $school = School::create(['name' => 'קיימת']);
        Contact::create(['school_id' => $school->id, 'name' => 'לאה', 'email' => 'Books@Orot.test']);

        $component = Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([
                $this->validRow(),
                $this->validRow(['school_name' => 'נועם', 'business_number' => '', 'contact1_email' => 'n@noam.test', 'contact2_email' => '', 'contact2_name' => 'שרה', 'contact3_name' => 'רחל', 'contact3_email' => 'rachel@orot.test']),
            ]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 2)
            ->assertSet('importCheck.errors', [])
            ->assertSee('אנשי קשר שהכתובת שלהם כבר שייכת ללקוחה אחרת');

        $warnings = collect($component->get('importCheck.warnings'))->groupBy('row')->map(fn ($w) => $w->pluck('message', 'column')->all());

        $this->assertStringContainsString('"קיימת"', $warnings[2]['איש קשר 2 — דוא"ל']);
        $this->assertStringContainsString('"אורות" (שורה 2 בקובץ)', $warnings[3]['איש קשר 3 — דוא"ל']);
        $this->assertArrayNotHasKey('איש קשר 1 — דוא"ל', $warnings[2]);

        $component->call('runImport');

        $this->assertSame(2, Customer::count());
        $this->assertSame(2, Contact::whereRaw('LOWER(email) = ?', ['rachel@orot.test'])->count());
    }

    public function test_errors_are_listed_per_row_and_column_and_nothing_is_saved(): void
    {
        School::create(['name' => 'קיים']);

        $component = Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([
                $this->validRow(['contact1_email' => 'not-an-email', 'contact1_phone' => '12']),
                $this->validRow(['school_name' => 'קיים', 'business_number' => '1']),
                $this->validRow(['school_name' => '', 'contact1_name' => '', 'business_number' => 'abc', 'status' => 'מומצא', 'converted_at' => '31/02/2025', 'gefen_budget' => 'אולי', 'classes_per_grade' => '-1']),
                $this->validRow(['school_name' => 'נוסף', 'business_number' => '2', 'contact2_name' => '', 'contact2_email' => 'x@y.test']),
            ]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 0)
            ->assertSet('importCheck.invalidRows', 4)
            ->assertDontSee('wire:click="runImport"', false);

        $errors = collect($component->get('importCheck.errors'))->groupBy('row')->map(fn ($e) => $e->pluck('message', 'column')->all());

        $this->assertSame('כתובת דוא"ל לא תקינה.', $errors[2]['איש קשר 1 — דוא"ל']);
        $this->assertArrayHasKey('איש קשר 1 — טלפון', $errors[2]);
        $this->assertStringContainsString('כבר קיים במערכת', $errors[3]['שם המוסד']);
        $this->assertSame('שדה חובה — חסר.', $errors[4]['שם המוסד']);
        $this->assertSame('שדה חובה — חסר.', $errors[4]['איש קשר 1 — שם']);
        $this->assertArrayHasKey('ח.פ.', $errors[4]);
        $this->assertArrayHasKey('סטטוס לקוחה', $errors[4]);
        $this->assertArrayHasKey('תאריך הצטרפות', $errors[4]);
        $this->assertArrayHasKey('תקציב גפן', $errors[4]);
        $this->assertArrayHasKey('מספר כיתות בשנתון', $errors[4]);
        $this->assertArrayHasKey('איש קשר 2 — שם', $errors[5]);

        $this->assertSame(0, Customer::count());
        $this->assertSame(1, School::count());
        $component->assertSet('importPath', null);
    }

    public function test_only_valid_rows_are_imported_and_duplicates_in_the_file_are_caught(): void
    {
        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([
                $this->validRow(),
                $this->validRow(['business_number' => '']),
                $this->validRow(['school_name' => 'אחר']),
                $this->validRow(['school_name' => 'שלישי', 'business_number' => '', 'contact1_phone' => 501234567]),
            ]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 2)
            ->assertSee('מופיע כבר בשורה 2')
            ->assertSee('ח.פ. זה מופיע כבר בשורה 2')
            ->call('runImport');

        $this->assertEqualsCanonicalizing(['אורות', 'שלישי'], School::pluck('name')->all());
        // A phone typed as a number gets its leading 0 back.
        $this->assertSame('0501234567', Contact::whereHas('school', fn ($q) => $q->where('name', 'שלישי'))->value('phone'));
    }

    public function test_a_file_missing_required_columns_is_rejected(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->fromArray([['שם המוסד', 'עיר'], ['אורות', 'ירושלים']]);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', UploadedFile::fake()->createWithContent('x.xlsx', file_get_contents($path)))
            ->call('checkImport')
            ->assertSee('בקובץ חסרות עמודות חובה');

        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', UploadedFile::fake()->createWithContent('x.csv', 'a,b'))
            ->call('checkImport')
            ->assertHasErrors('importFile');
    }

    public function test_a_view_only_user_can_check_but_not_import(): void
    {
        $viewer = Role::create(['name' => 'צפייה', 'is_active' => true]);
        $viewer->permissions()->create(['resource' => 'settings', 'action' => 'view', 'is_allowed' => true]);
        $user = User::create(['name' => 'צופה', 'email' => 'v@kapaim.test', 'password' => 'password', 'role_id' => $viewer->id, 'is_active' => true]);

        Livewire::actingAs($user)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([$this->validRow()]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 1)
            ->assertSee('ליבוא עצמו נדרשת הרשאת עריכה')
            ->call('runImport');

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_contacts_are_linked_to_the_smove_list_and_never_pushed_to_it(): void
    {
        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([
                $this->validRow(),
                $this->validRow(['school_name' => 'נועם', 'business_number' => '', 'contact1_email' => 'gone@noam.test', 'contact2_name' => '', 'contact2_email' => '']),
            ]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 2)
            ->assertSet('importCheck.smove.linked', 3)
            ->assertSet('importCheck.smove.not_receiving', 1)
            ->assertSee('אנשי קשר נמצאו ברשימה ויקושרו אליה')
            ->assertSet('importCheck.unlisted', [])
            ->assertDontSee('לא נמצאו ברשימת התפוצה ב־Smove')
            ->call('runImport')
            ->assertSee('3 אנשי קשר קושרו לאנשי הקשר ברשימת התפוצה ב־Smove');

        $primary = Contact::where('email', 'rachel@orot.test')->sole();
        $this->assertSame(900, (int) $primary->smove_contact_id);
        $this->assertSame(902, (int) Contact::where('email', 'books@orot.test')->value('smove_contact_id'));
        $this->assertSame(901, (int) Contact::where('email', 'gone@noam.test')->value('smove_contact_id'));

        // Never posted to Smove: that would reset their consent / resubscribe them.
        $this->assertSame(0, $this->postsToSmoveContacts());

        // Both customers are on the system's own list, so a mailing reaches them.
        $this->assertSame(2, \App\Models\MailingMembership::whereNotNull('customer_id')->where('membership_status', 'active')->count());

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $primary->customer])->assertSee('title="נמצא ברשימת התפוצה ב־Smove (לפי כתובת הדוא״ל)"', false);
    }

    /** Business owner, 2026-11-18: a contact not on the list no longer blocks its row — it asks for approval. */
    public function test_contacts_not_on_the_smove_list_are_listed_and_opened_after_approval(): void
    {
        $component = Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([
                $this->validRow(),
                $this->validRow(['school_name' => 'חדש', 'business_number' => '', 'contact1_email' => 'stranger@new.test', 'contact2_name' => '', 'contact2_email' => '']),
                $this->validRow(['school_name' => 'שלישי', 'business_number' => '', 'contact1_email' => 'n@noam.test', 'contact2_email' => 'unknown@noam.test']),
            ]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 3)
            ->assertSet('importCheck.invalidRows', 0)
            ->assertSet('importCheck.errors', [])
            ->assertSet('importCheck.smove.linked', 3)
            ->assertSet('importCheck.smove.unlisted', 2)
            ->assertSee('2 אנשי קשר לא נמצאו ברשימת התפוצה ב־Smove')
            ->assertSee('stranger@new.test')
            ->assertSee('אני מאשרת לפתוח את אנשי הקשר האלה');

        $this->assertSame(
            [[3, 'stranger@new.test', 'רחל כהן'], [4, 'unknown@noam.test', 'לאה לוי']],
            collect($component->get('importCheck.unlisted'))->map(fn ($c) => [$c['row'], $c['email'], $c['name']])->all(),
        );

        // Not approved yet: nothing is imported.
        $component->call('runImport')->assertSet('importId', null);
        $this->assertSame(0, Customer::count());

        $component->set('importApproveUnlisted', true)->call('runImport')
            ->assertSee('היבוא הסתיים: נוספו 3 לקוחות')
            ->assertSee('1 אנשי קשר שלא היו ברשימה נפתחו וצורפו אליה');

        $this->assertSame(3, Customer::count());
        // The ones found are linked and never posted; the approved ones join the Smove list.
        $this->assertSame(903, (int) Contact::where('email', 'n@noam.test')->value('smove_contact_id'));
        $posted = \Illuminate\Support\Facades\Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/Contacts'))
            ->map(fn ($pair) => $pair[0]['email'])->sort()->values()->all();
        // unknown@noam.test is contact 2 there, marked accounting-only in validRow() — accounting mail only, no list join.
        $this->assertSame(['stranger@new.test'], $posted);
        $this->assertTrue(Contact::where('email', 'unknown@noam.test')->exists());
    }

    public function test_nothing_is_imported_when_the_smove_list_cannot_be_read(): void
    {
        $this->smoveList = null;

        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([$this->validRow()]))
            ->call('checkImport')
            ->assertSee('לא ניתן לקרוא את רשימת התפוצה מ-Smove')
            ->assertSee('אי אפשר לבדוק שאנשי הקשר נמצאים ברשימת התפוצה')
            ->assertDontSee('wire:click="runImport"', false)
            ->assertSet('importPath', null);

        $this->assertSame(0, Customer::count());
    }

    public function test_nothing_is_imported_when_smove_is_not_connected(): void
    {
        \App\Models\ExternalIntegrationSetting::where('system', 'smove')->update(['is_active' => false]);

        Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([$this->validRow()]))
            ->call('checkImport')
            ->assertSee('Smove לא מחובר')
            ->assertDontSee('wire:click="runImport"', false);

        $this->assertSame(0, Customer::count());
    }

    public function test_a_contact_removed_from_smove_after_the_check_is_not_opened_without_approval(): void
    {
        $component = Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')
            ->set('importFile', $this->filledFile([
                $this->validRow(),
                $this->validRow(['school_name' => 'נועם', 'business_number' => '', 'contact1_email' => 'n@noam.test', 'contact2_name' => '', 'contact2_email' => '']),
            ]))
            ->call('checkImport')
            ->assertSet('importCheck.valid', 2);

        $this->smoveList = array_values(array_filter($this->smoveList, fn ($c) => $c['email'] !== 'n@noam.test'));

        // Now it isn't on the list, and nobody approved opening it: that row isn't imported.
        $component->call('runImport')
            ->assertSee('היבוא הסתיים: נוספו 1 לקוחות')
            ->assertSee('לא אושר לפתוח אותם');

        $this->assertSame(['אורות'], School::pluck('name')->all());
    }
}
