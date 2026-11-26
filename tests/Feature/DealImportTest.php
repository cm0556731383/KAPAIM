<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentRecipient;
use App\Models\ExternalOperation;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DealImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DealImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $orot;

    private Customer $noam;

    private Program $safety;

    private Program $premium;

    private Bundle $basic;

    private Bundle $subscription;

    private PaymentMethod $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['queue.default' => 'sync']);
        $this->travelTo('2026-11-24 10:00');

        $role = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $role->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);
        $this->owner = User::create(['name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password', 'role_id' => $role->id, 'is_active' => true]);

        $this->orot = $this->customer('אורות', 3);
        $this->noam = $this->customer('נועם', null);

        $this->safety = Program::create(['name' => 'מוגנות', 'price' => 1000, 'price_3_4' => 1500, 'is_active' => true]);
        $this->premium = Program::create(['name' => 'פרימיום', 'price' => 2000, 'is_premium' => true, 'is_active' => true]);
        Program::create(['name' => 'ישנה', 'price' => 10, 'is_active' => false]);
        $this->basic = Bundle::create(['name' => 'מארז בסיס', 'price' => 3000, 'is_active' => true]);
        $this->subscription = Bundle::create(['name' => 'מנוי תשפ"ז', 'price' => 5000, 'is_subscription_type' => true, 'school_year' => 5787, 'is_active' => true]);
        $this->transfer = PaymentMethod::create(['name' => 'העברה בנקאית', 'type' => 'other', 'is_active' => true]);
    }

    private function customer(string $name, ?int $classes): Customer
    {
        $school = School::create(['name' => $name, 'classes_per_grade' => $classes]);
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        $lead = Lead::create(['school_id' => $school->id, 'status_id' => $status->id, 'assigned_user_id' => $this->owner->id, 'phone' => '0501234567', 'email' => $name.'@school.test']);
        $this->actingAs($this->owner);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }

    private function filledFile(array $rows): UploadedFile
    {
        $book = app(DealImport::class)->template();
        $sheet = $book->getSheet(0);
        $keys = array_keys(DealImport::columns());

        foreach ($rows as $i => $row) {
            foreach ($row as $key => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(array_search($key, $keys, true) + 1).($i + 2), $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'deal').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $file = UploadedFile::fake()->createWithContent('deals.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    private function screen()
    {
        return Livewire::actingAs($this->owner)->test('settings')->set('activeTab', 'import')->set('importKind', 'deals');
    }

    public function test_the_template_lists_the_customers_and_the_active_catalog_items(): void
    {
        $response = $this->actingAs($this->owner)->get(route('settings.import-deals-template'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $book = IOFactory::load($path);

        $sheet = $book->getSheet(0);
        $this->assertSame('עסקאות', $sheet->getTitle());
        $this->assertSame('לקוחה *', $sheet->getCell('A1')->getValue());
        $this->assertSame(count(DealImport::columns()), Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));

        $lists = $book->getSheetByName('רשימות');
        $values = collect($lists->toArray())->flatten()->filter()->all();
        $this->assertContains('אורות', $values);
        $this->assertContains('נועם', $values);
        $this->assertContains('תוכנית: מוגנות', $values);
        $this->assertContains('מארז: מנוי תשפ"ז', $values);
        $this->assertNotContains('תוכנית: ישנה', $values);
        $this->assertContains('העברה בנקאית', $values);
    }

    public function test_deals_are_checked_then_created_like_a_deal_made_by_hand(): void
    {
        $this->screen()
            ->set('dealFile', $this->filledFile([
                [
                    'customer' => 'אורות', 'purchased_at' => '15/09/2026',
                    'item1' => 'תוכנית: מוגנות', 'item2' => 'מארז: מארז בסיס', 'amount2' => '₪2,500',
                    'students_count' => 120, 'payment_method' => 'העברה בנקאית', 'special_request' => 'לשלוח חוברות',
                ],
                ['customer' => 'נועם', 'class_tier' => '1–2 כיתות', 'item1' => 'מארז: מנוי תשפ"ז'],
            ]))
            ->call('checkDealImport')
            ->assertHasNoErrors()
            ->assertSet('dealCheck.invalid', [])
            ->tap(fn () => $this->assertSame(0, Deal::count()))
            ->call('runDealImport')
            ->assertSee('היבוא הסתיים: נוספו 2 עסקאות');

        $deal = $this->orot->deals()->with('items')->sole();
        $this->assertSame('2026-09-15', $deal->purchased_at->toDateString());
        $this->assertSame('3-4', $deal->class_tier);
        $this->assertEquals([1500.0, 2500.0], $deal->items->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertEquals(4000.0, (float) $deal->agreed_amount);
        $this->assertSame(120, $deal->students_count);
        $this->assertSame($this->transfer->id, $deal->payment_method_id);
        $this->assertSame('לשלוח חוברות', $deal->special_request);
        $this->assertSame(Deal::DEFAULT_STATUS_NAME, $deal->status->name);

        $subscriptionDeal = $this->noam->deals()->sole();
        $this->assertSame('1-2', $subscriptionDeal->class_tier);
        $this->assertNotNull($subscriptionDeal->subscription);
        // Month 1 of תשפ"ז (the start month here is the default, תשרי).
        $this->assertSame('2026-09-12', $subscriptionDeal->subscription->first_month_date->toDateString());

        $this->assertSame(2, ActivityLog::where('activity_type', 'deal.created')->count());
        $this->assertTrue(ActivityLog::where('activity_type', 'deals.imported')->exists());
        Storage::disk('local')->assertDirectoryEmpty('imports');
    }

    public function test_errors_are_listed_and_fixed_on_screen(): void
    {
        $component = $this->screen()
            ->set('dealFile', $this->filledFile([
                ['customer' => 'לא קיימת', 'item1' => 'תוכנית: מוגנות'],
                ['customer' => 'אורות', 'purchased_at' => '31/02/2026', 'item1' => 'תוכנית: ישנה', 'amount2' => 100, 'students_count' => 'הרבה'],
                ['customer' => 'נועם', 'item1' => 'תוכנית: מוגנות'],
                ['customer' => 'אורות', 'item1' => 'מארז: מנוי תשפ"ז', 'item2' => 'מארז: מנוי תשפ"ז', 'payment_method' => 'ביטקוין'],
                ['customer' => 'נועם', 'class_tier' => '5–6 כיתות', 'item1' => 'מוגנות'],
            ]))
            ->call('checkDealImport')
            ->assertSee('אין במערכת לקוחה בשם זה')
            ->assertSee('wire:model="dealFixes.3.item1"', false);

        $invalid = collect($component->get('dealCheck.invalid'))->keyBy('row');
        $this->assertSame(['customer'], array_keys($invalid[2]['fields']));
        $this->assertSame(['purchased_at', 'item1', 'amount1', 'item2', 'amount2', 'students_count'], array_keys($invalid[3]['fields']));
        $this->assertSame('הפריט מושבת בקטלוג — אי אפשר למכור אותו.', $invalid[3]['fields']['item1']['message']);
        // נועם has no number of classes, and מוגנות is priced by it.
        $this->assertSame(['class_tier'], array_keys($invalid[4]['fields']));
        $this->assertSame('הפריט מופיע כבר בעסקה.', $invalid[5]['fields']['item2']['message']);
        $this->assertStringContainsString('אין אמצעי תשלום', $invalid[5]['fields']['payment_method']['message']);

        $component
            ->set('dealFixes.2.customer', 'אורות')
            ->set('dealFixes.3.purchased_at', '28/02/2026')
            ->set('dealFixes.3.item1', 'תוכנית: פרימיום')
            ->set('dealFixes.3.amount2', '')
            ->set('dealFixes.3.students_count', '40')
            ->set('dealFixes.4.class_tier', '5–6 כיתות')
            ->set('dealFixes.5.item2', '')
            ->set('dealFixes.5.payment_method', '')
            ->call('fixDealImport');

        // Row 4, now fixed, is the same deal as row 6 (same customer, date and items) — row 6 is the duplicate.
        $invalid = collect($component->get('dealCheck.invalid'))->keyBy('row');
        $this->assertSame([6], $invalid->keys()->all());
        $this->assertStringContainsString('עסקה זהה מופיעה כבר בשורה 4', $invalid[6]['fields']['customer']['message']);

        $component->call('runDealImport')->assertSee('נוספו 4 עסקאות')->assertSee('1 שורות לא יובאו');

        $this->assertSame('2026-02-28', $this->orot->deals()->whereHas('items', fn ($q) => $q->where('program_id', $this->premium->id))->sole()->purchased_at->toDateString());
        $this->assertSame(3, $this->orot->deals()->count());
        $this->assertSame(1, $this->noam->deals()->count());
        $this->assertSame('5-6', $this->noam->deals()->sole()->class_tier);
    }

    public function test_the_same_file_uploaded_again_does_not_duplicate_deals(): void
    {
        $row = ['customer' => 'אורות', 'purchased_at' => '01/10/2026', 'item1' => 'מוגנות'];

        $this->screen()->set('dealFile', $this->filledFile([$row]))->call('checkDealImport')->call('runDealImport');
        $this->assertSame(1, Deal::count());

        $component = $this->screen()->set('dealFile', $this->filledFile([$row]))->call('checkDealImport');
        $this->assertStringContainsString('כבר קיימת במערכת עסקה זהה', $component->get('dealCheck.invalid')[0]['fields']['customer']['message']);
        $this->assertSame([], $component->get('dealCheck.valid'));
    }

    public function test_subscription_rules_are_checked_before_the_import(): void
    {
        Deal::createForCustomer($this->orot, null, $this->subscription);
        $ended = Bundle::create(['name' => 'מנוי תשפ"ה', 'price' => 4000, 'is_subscription_type' => true, 'school_year' => 5785, 'is_active' => true]);

        $component = $this->screen()
            ->set('dealFile', $this->filledFile([
                ['customer' => 'אורות', 'item1' => 'מארז: מנוי תשפ"ז'],
                ['customer' => 'נועם', 'item1' => 'מארז: מנוי תשפ"ה'],
                ['customer' => 'נועם', 'item1' => 'מארז: מנוי תשפ"ז', 'purchased_at' => '01/10/2026'],
                ['customer' => 'נועם', 'item1' => 'מארז: מנוי תשפ"ז', 'purchased_at' => '02/10/2026'],
            ]))
            ->call('checkDealImport');

        $invalid = collect($component->get('dealCheck.invalid'))->keyBy('row');
        $this->assertSame('ללקוחה כבר יש מנוי פעיל במארז זה.', $invalid[2]['fields']['item1']['message']);
        $this->assertStringContainsString('כבר הסתיימה', $invalid[3]['fields']['item1']['message']);
        $this->assertStringContainsString('כבר נמכר ללקוחה בשורה 4', $invalid[5]['fields']['item1']['message']);
        $this->assertSame([4], array_column($component->get('dealCheck.valid'), 'row'));
    }

    /** 2026-11-25: documents sent / signed before the system are recorded with the deal — nothing is sent or issued. */
    public function test_documents_made_before_the_system_are_recorded_with_the_deal(): void
    {
        Http::fake();

        $this->screen()
            ->set('dealFile', $this->filledFile([
                [
                    'customer' => 'אורות', 'purchased_at' => '01/09/2026', 'item1' => 'תוכנית: מוגנות',
                    'quote_state' => 'אושרה', 'quote_date' => '20/08/2026',
                    'order_form_state' => 'נחתם', 'contract_state' => 'נחתם', 'contract_date' => '03/09/2026',
                    'invoice_state' => 'הופקה ונשלחה', 'invoice_date' => '10/09/2026', 'invoice_number' => '10045',
                ],
                ['customer' => 'נועם', 'class_tier' => '1–2 כיתות', 'item1' => 'מוגנות', 'contract_state' => 'נשלח'],
            ]))
            ->call('checkDealImport')
            ->assertSet('dealCheck.invalid', [])
            ->assertSee('חוזה: נחתם 03/09/2026')
            ->call('runDealImport')
            ->assertSee('נוספו 2 עסקאות');

        $deal = $this->orot->deals()->sole();
        $documents = $deal->documents()->get()->keyBy('document_type');
        $this->assertSame(['quote', 'order_form', 'contract', 'invoice'], $documents->keys()->all());
        $this->assertTrue($documents->every->is_external);

        $this->assertSame('2026-08-20', $documents['quote']->confirmed_at->toDateString());
        $this->assertSame('2026-09-01', $documents['order_form']->received_at->toDateString()); // no date = the purchase date
        $this->assertSame('2026-09-03', $documents['contract']->signed_at->toDateString());
        $this->assertSame($documents['order_form']->id, $documents['contract']->preceding_document_id);
        $this->assertSame(Document::CONFIRMED_STATUS_NAME, $documents['contract']->status->name);

        // The invoice counts as the deal's invoice: status, amount lock, no second invoice.
        $this->assertSame('10045', $documents['invoice']->external_number);
        $this->assertSame('2026-09-10', $documents['invoice']->sent_at->toDateString());
        $this->assertEquals(1500.0, $documents['invoice']->totalAmount());
        $this->assertSame($documents['invoice']->id, $deal->issuedInvoice()?->id);
        $this->assertSame(Deal::INVOICE_SENT_STATUS_NAME, $deal->fresh()->status->name);
        $this->assertNotNull($deal->fresh()->amountLockReason());
        $this->assertFalse(Document::canGenerate($deal, 'invoice'));

        // A contract sent but not yet signed — the next document may follow it, and it can be marked signed later.
        $noamDeal = $this->noam->deals()->sole();
        $contract = $noamDeal->documents()->sole();
        $this->assertNull($contract->signed_at);
        $this->assertTrue($contract->canBeMarkedSignedByHand());
        $this->assertTrue(Document::canGenerate($noamDeal, 'invoice'));
        $this->assertSame(Deal::DEFAULT_STATUS_NAME, $noamDeal->status->name);

        // No document went out: nothing sent or issued for any of them (the deal's own mailing-list joins aside).
        Http::assertNothingSent();
        $this->assertSame(['mailing_list_join'], ExternalOperation::pluck('operation_type')->unique()->values()->all());
        $this->assertSame(0, ExternalOperation::whereNotNull('document_id')->count());
        $this->assertSame(0, DocumentRecipient::count());
    }

    public function test_document_columns_are_checked(): void
    {
        $component = $this->screen()
            ->set('dealFile', $this->filledFile([[
                'customer' => 'אורות', 'item1' => 'מוגנות',
                'quote_date' => '01/09/2026',
                'order_form_state' => 'אולי',
                'contract_state' => 'נחתם', 'contract_date' => '01/01/2030',
                'invoice_state' => 'נחתם',
                'invoice_number' => '77',
            ], [
                'customer' => 'נועם', 'class_tier' => '1–2 כיתות', 'item1' => 'מוגנות', 'invoice_number' => '78',
            ]]))
            ->call('checkDealImport');

        $invalid = collect($component->get('dealCheck.invalid'))->keyBy('row');
        $this->assertSame(['quote_state', 'order_form_state', 'contract_date', 'invoice_state'], array_values(array_filter(array_keys($invalid[2]['fields']), fn ($k) => $invalid[2]['fields'][$k]['message'] !== null)));
        // Each document's state and date (and the invoice number) show together for fixing.
        $this->assertSame(['quote_state', 'quote_date', 'order_form_state', 'order_form_date', 'contract_state', 'contract_date', 'invoice_state', 'invoice_date', 'invoice_number'], array_keys($invalid[2]['fields']));
        $this->assertStringContainsString('יש תאריך בלי מצב', $invalid[2]['fields']['quote_state']['message']);
        $this->assertSame('ערך לא מהרשימה — הופקה ונשלחה.', $invalid[2]['fields']['invoice_state']['message']);
        $this->assertStringContainsString('בלי מצב', $invalid[3]['fields']['invoice_number']['message']);

        $component->set('dealFixes.2.quote_date', '')
            ->set('dealFixes.2.order_form_state', 'נשלח')
            ->set('dealFixes.2.contract_date', '02/09/2026')
            ->set('dealFixes.2.invoice_state', '')
            ->set('dealFixes.2.invoice_number', '')
            ->set('dealFixes.3.invoice_number', '')
            ->call('fixDealImport');

        $this->assertSame([], $component->get('dealCheck.invalid'), json_encode($component->get('dealCheck.invalid'), JSON_UNESCAPED_UNICODE));
    }

    public function test_payments_made_before_the_system_are_recorded_with_receipts_made_outside(): void
    {
        Http::fake();
        $check = PaymentMethod::create(['name' => "צ'ק", 'type' => 'check', 'is_active' => true]);

        $this->screen()
            ->set('dealFile', $this->filledFile([
                [
                    'customer' => 'אורות', 'purchased_at' => '01/09/2026', 'item1' => 'תוכנית: מוגנות',
                    'invoice_state' => 'הופקה ונשלחה', 'invoice_date' => '10/09/2026', 'invoice_number' => '10045',
                    'pay1_method' => 'העברה בנקאית', 'pay1_amount' => '1000', 'pay1_date' => '12/09/2026', 'pay1_receipt' => 'R-1',
                    'pay2_method' => "צ'ק", 'pay2_amount' => '500',
                ],
                [
                    'customer' => 'נועם', 'class_tier' => '1–2 כיתות', 'item1' => 'מוגנות',
                    'invoice_state' => 'הופקה ונשלחה', 'pay1_method' => 'העברה בנקאית', 'pay1_amount' => '400',
                ],
            ]))
            ->call('checkDealImport')
            ->assertSet('dealCheck.invalid', [])
            ->assertSee('קבלה R-1')
            ->call('runDealImport')
            ->assertSee('נוספו 2 עסקאות');

        // Fully paid: 1000 + a check of 500 = 1500 (3 classes).
        $deal = $this->orot->deals()->sole();
        $payments = $deal->payments()->with('receipt')->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertTrue($payments->every(fn ($p) => $p->receipt?->is_external));
        $this->assertSame('R-1', $payments[0]->receipt->external_number);
        $this->assertSame('2026-09-12', $payments[0]->payment_date->toDateString());
        $this->assertSame('2026-09-10', $payments[1]->payment_date->toDateString()); // no date = the invoice's
        $this->assertSame('2026-09-10', $payments[1]->cleared_date->toDateString());
        $this->assertSame(Deal::PAID_STATUS_NAME, $deal->fresh()->status->name);

        // Partly paid: the invoice stays the deal's status.
        $noamDeal = $this->noam->deals()->sole();
        $this->assertSame(400.0, $noamDeal->totalPaid());
        $this->assertSame(Deal::INVOICE_SENT_STATUS_NAME, $noamDeal->status->name);

        // Nothing went to SUMIT.
        Http::assertNothingSent();
        $this->assertSame(0, ExternalOperation::where('operation_type', 'issue_receipt')->count());
    }

    public function test_a_file_from_the_earlier_template_headings_still_imports(): void
    {
        Http::fake();
        $file = $this->filledFile([[
            'customer' => 'אורות', 'purchased_at' => '01/09/2026', 'item1' => 'תוכנית: מוגנות', 'special_request' => 'לשלוח חוברות',
            'invoice_state' => 'הופקה ונשלחה', 'invoice_date' => '10/09/2026',
            'pay1_method' => 'העברה בנקאית', 'pay1_amount' => '1500', 'pay1_receipt' => 'R-7',
        ]]);

        // Put back the headings the template had before 2026-11-25.
        $book = IOFactory::load($file->getRealPath());
        $sheet = $book->getSheet(0);
        $keys = array_keys(DealImport::columns());
        $sheet->setCellValue(Coordinate::stringFromColumnIndex(array_search('special_request', $keys, true) + 1).'1', 'בקשה מיוחדת / הערות');
        $sheet->setCellValue(Coordinate::stringFromColumnIndex(array_search('pay1_receipt', $keys, true) + 1).'1', 'תשלום 1 — מספר קבלה');
        $path = tempnam(sys_get_temp_dir(), 'deal').'.xlsx';
        (new Xlsx($book))->save($path);
        $old = UploadedFile::fake()->createWithContent('deals.xlsx', file_get_contents($path));
        unlink($path);

        $this->assertSame('בקשת התאמה מיוחדת', DealImport::columns()['special_request'][0]);
        $this->assertSame('תשלום 1 — אסמכתא (מספר קבלה)', DealImport::columns()['pay1_receipt'][0]);

        $this->screen()->set('dealFile', $old)->call('checkDealImport')->assertSet('dealCheck.invalid', [])->call('runDealImport')->assertSee('נוספו 1 עסקאות');

        $deal = $this->orot->deals()->sole();
        $this->assertSame('לשלוח חוברות', $deal->special_request);
        $this->assertSame('R-7', $deal->payments()->sole()->receipt->external_number);
    }

    public function test_payment_columns_are_checked(): void
    {
        $component = $this->screen()
            ->set('dealFile', $this->filledFile([[
                'customer' => 'אורות', 'item1' => 'מוגנות',
                'pay1_method' => 'העברה בנקאית', 'pay1_amount' => '300',
                'pay2_amount' => '200',
                'pay3_method' => 'ביטקוין', 'pay3_amount' => '-5', 'pay3_date' => '01/01/2030',
                'pay4_receipt' => 'R-9',
            ]]))
            ->call('checkDealImport');

        $fields = collect($component->get('dealCheck.invalid'))->sole()['fields'];
        $this->assertStringContainsString('הופקה ונשלחה', $fields['invoice_state']['message']);
        // Payments fine apart from the missing invoice aren't listed; empty optional cells never are.
        $this->assertArrayNotHasKey('pay1_method', $fields);
        $this->assertArrayNotHasKey('pay2_amount', $fields);
        $this->assertArrayNotHasKey('pay4_method', $fields);
        $this->assertArrayNotHasKey('pay4_date', $fields);
        $this->assertArrayHasKey('pay4_receipt', $fields);
        $this->assertStringContainsString('בלי סכום', $fields['pay4_amount']['message']);
        $this->assertNotNull($fields['pay3_amount']['message']);
        $this->assertSame('תאריך עתידי.', $fields['pay3_date']['message']);
        // The invoice's fields show, to be filled in.
        $this->assertArrayHasKey('invoice_date', $fields);

        $component->set('dealFixes.2.invoice_state', 'הופקה ונשלחה')
            ->set('dealFixes.2.pay4_amount', '50')
            ->set('dealFixes.2.pay3_method', '')
            ->set('dealFixes.2.pay3_amount', '')
            ->set('dealFixes.2.pay3_date', '')
            ->call('fixDealImport');

        $this->assertSame([], $component->get('dealCheck.invalid'), json_encode($component->get('dealCheck.invalid'), JSON_UNESCAPED_UNICODE));
        $this->assertCount(3, $component->get('dealCheck.valid')[0]['data']['payments']);
    }

    public function test_a_payment_with_only_an_amount_is_recorded_with_unknown_details(): void
    {
        Http::fake();

        $this->screen()
            ->set('dealFile', $this->filledFile([[
                'customer' => 'אורות', 'purchased_at' => '01/09/2026', 'item1' => 'תוכנית: מוגנות',
                'invoice_state' => 'הופקה ונשלחה', 'invoice_date' => '10/09/2026',
                'pay1_amount' => '700',
            ]]))
            ->call('checkDealImport')
            ->assertSet('dealCheck.invalid', [])
            ->assertSee('תשלום לא ידוע')
            ->call('runDealImport')
            ->assertSee('נוספו 1 עסקאות');

        $deal = $this->orot->deals()->sole();
        $payment = $deal->payments()->with('receipt', 'paymentMethod')->sole();
        $this->assertSame(700.0, (float) $payment->amount);
        $this->assertSame('לא ידוע', $payment->paymentMethod->name);
        $this->assertFalse($payment->paymentMethod->is_active);
        $this->assertTrue($payment->date_unknown);
        $this->assertSame('2026-09-10', $payment->payment_date->toDateString());
        $this->assertTrue($payment->receipt->is_external);
        $this->assertNull($payment->receipt->external_number);

        // The deal card says "לא ידוע"; the settings list doesn't offer the method.
        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])->assertSee('תאריך לא ידוע')->assertSeeInOrder(['תאריך לא ידוע', "מס'", 'לא ידוע']);
        Http::assertNothingSent();
    }

    public function test_importing_needs_edit_rights_in_settings_and_customers(): void
    {
        $role = Role::create(['name' => 'הגדרות בלבד', 'is_active' => true]);
        $role->permissions()->create(['resource' => 'settings', 'action' => '*', 'is_allowed' => true]);
        $role->permissions()->create(['resource' => 'customers', 'action' => 'view', 'is_allowed' => true]);
        $user = User::create(['name' => 'מזכירה', 'email' => 's@kapaim.test', 'password' => 'password', 'role_id' => $role->id, 'is_active' => true]);

        Livewire::actingAs($user)->test('settings')->set('activeTab', 'import')->set('importKind', 'deals')
            ->set('dealFile', $this->filledFile([['customer' => 'אורות', 'item1' => 'תוכנית: מוגנות']]))
            ->call('checkDealImport')
            ->assertSee('ליבוא עצמו נדרשת הרשאת עריכה בהגדרות ובלקוחות')
            ->call('runDealImport');

        $this->assertSame(0, Deal::count());
    }
}
