<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\EmailTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Models\DocumentTemplate;
use App\Models\Expense;
use App\Models\Lead;
use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class SuppliersExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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

        // Tests build their own fixtures — mirrors PaymentsCollectionsTest's
        // createTemplate() helper rather than relying on DocumentTemplatesSeeder.
        DocumentTemplate::create([
            'document_type' => 'expense_invoice',
            'name' => 'תבנית חשבונית הוצאה לבדיקה',
            'content' => 'תוכן בדיקה',
            'is_active' => true,
        ]);
    }

    // ----- auth / permissions -----

    public function test_an_expense_can_be_edited_and_deleted_only_while_it_has_no_invoice(): void
    {
        $supplier = $this->createSupplier();
        $expense = Expense::createForSupplier($supplier, 100, '2026-09-01');
        $documented = Expense::createForSupplier($supplier, 200, '2026-09-02');
        $documented->attachInvoice($this->invoiceFile());

        Livewire::actingAs($this->owner)->test('expenses')
            ->call('editExpense', $expense->id)
            ->set('editAmount', '120')
            ->set('editNotes', 'תוקן')
            ->call('updateExpense')
            ->assertHasNoErrors()
            ->call('deleteExpense', $documented->id)
            ->assertSet('expenseError', 'לא ניתן למחוק הוצאה שכבר צורפה לה חשבונית.')
            ->call('deleteExpense', $expense->id);

        $this->assertEquals(120, (float) Expense::withTrashed()->find($expense->id)->amount);
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
        $this->assertNotSoftDeleted('expenses', ['id' => $documented->id]);
    }

    public function test_a_supplier_without_expenses_can_be_deleted_but_one_with_expenses_cannot(): void
    {
        $unused = $this->createSupplier();
        $used = $this->createSupplier();
        Expense::createForSupplier($used, 100, '2026-09-01')->delete(); // even a deleted expense keeps its supplier

        Livewire::actingAs($this->owner)->test('suppliers')
            ->call('deleteSupplier', $used->id)
            ->call('deleteSupplier', $unused->id);

        $this->assertDatabaseHas('suppliers', ['id' => $used->id]);
        $this->assertDatabaseMissing('suppliers', ['id' => $unused->id]);
    }

    public function test_suppliers_page_requires_auth(): void
    {
        $this->get('/suppliers')->assertRedirect('/login');
    }

    public function test_suppliers_page_is_blocked_without_the_suppliers_permission(): void
    {
        $this->actingAs($this->userWithOnlyCustomersPermission())->get('/suppliers')->assertStatus(403);
    }

    public function test_suppliers_page_renders_for_a_user_with_the_permission(): void
    {
        $this->actingAs($this->owner)->get('/suppliers')->assertOk();
    }

    public function test_expenses_page_requires_auth(): void
    {
        $this->get('/expenses')->assertRedirect('/login');
    }

    public function test_expenses_page_is_blocked_without_the_expenses_permission(): void
    {
        $this->actingAs($this->userWithOnlyCustomersPermission())->get('/expenses')->assertStatus(403);
    }

    public function test_expenses_page_renders_for_a_user_with_the_permission(): void
    {
        $this->actingAs($this->owner)->get('/expenses')->assertOk();
    }

    public function test_cashflow_report_page_requires_auth(): void
    {
        $this->get('/cashflow-report')->assertRedirect('/login');
    }

    public function test_cashflow_report_page_is_blocked_without_the_expenses_permission(): void
    {
        $this->actingAs($this->userWithOnlyCustomersPermission())->get('/cashflow-report')->assertStatus(403);
    }

    public function test_cashflow_report_page_renders_for_a_user_with_the_permission(): void
    {
        $this->actingAs($this->owner)->get('/cashflow-report')->assertOk();
    }

    // ----- suppliers (FR-6.14/FR-6.15, FR-5.26) -----

    public function test_creating_a_supplier_joins_the_suppliers_mailing_list(): void
    {
        $supplier = $this->createSupplier();
        $supplier->joinSuppliersMailingList();

        $list = MailingList::suppliersList();
        $this->assertTrue(
            MailingMembership::where('mailing_list_id', $list->id)
                ->where('supplier_id', $supplier->id)
                ->where('membership_status', MailingMembership::STATUS_ACTIVE)
                ->exists()
        );
    }

    public function test_there_is_no_delete_route_for_suppliers_expenses_or_cashflow(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = strtolower($route->uri());
            if (str_contains($uri, 'supplier') || str_contains($uri, 'expense') || str_contains($uri, 'cashflow')) {
                $this->assertNotContains('DELETE', $route->methods(), "Unexpected DELETE route: {$uri}");
            }
        }
    }

    // ----- expenses (FR-6.5/FR-6.6) -----

    public function test_creating_an_expense_requires_a_positive_amount(): void
    {
        $supplier = $this->createSupplier();

        $this->expectException(RuntimeException::class);
        Expense::createForSupplier($supplier, 0, now()->toDateString());
    }

    public function test_creating_an_expense_without_a_program_succeeds(): void
    {
        $supplier = $this->createSupplier();

        $expense = Expense::createForSupplier($supplier, 500, now()->toDateString());

        $this->assertNull($expense->program_id);
        $this->assertSame(Expense::MISSING_INVOICE_STATUS_NAME, $expense->status?->name);
    }

    public function test_creating_an_expense_with_a_program_attributes_it(): void
    {
        $supplier = $this->createSupplier();
        $program = $this->createProgram();

        $expense = Expense::createForSupplier($supplier, 500, now()->toDateString(), $program, 'הערה');

        $this->assertSame($program->id, $expense->program_id);
        $this->assertSame('הערה', $expense->notes);
    }

    // ----- attaching an invoice (FR-6.6/FR-6.10) -----

    public function test_attaching_an_invoice_flips_the_expense_status_and_sets_document_id(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());

        $document = $expense->attachInvoice($this->invoiceFile());

        $expense->refresh();
        $this->assertSame($document->id, $expense->document_id);
        $this->assertSame(Expense::HAS_INVOICE_STATUS_NAME, $expense->status?->name);
        $this->assertSame('expense_invoice', $document->document_type);
        $this->assertNull($document->deal_id);
        $this->assertNull($document->business_entity_id);
    }

    public function test_deleting_an_invoice_removes_the_file_and_lets_a_new_one_be_uploaded(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 500, '2026-08-05');
        $first = $expense->attachInvoice($this->invoiceFile('wrong.pdf'));

        Livewire::actingAs($this->owner)->test('expenses')
            ->call('removeInvoice', $expense->id)
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertNull($expense->document_id);
        $this->assertSame(Expense::MISSING_INVOICE_STATUS_NAME, $expense->status?->name);
        $this->assertNull(Document::find($first->id));
        Storage::disk('local')->assertMissing($first->file_reference);
        $this->assertSame(1, Expense::missingInvoiceForClosedMonth()->where('id', $expense->id)->count());

        $second = $expense->attachInvoice($this->invoiceFile('right.jpg', 'image/jpeg'));

        $this->assertSame('right.jpg', $expense->fresh()->invoiceFileName());
        Storage::disk('local')->assertExists($second->file_reference);
    }

    public function test_the_expenses_excel_export_lists_every_expense_with_its_details(): void
    {
        $supplier = $this->createSupplier();
        $withInvoice = Expense::createForSupplier($supplier, 1250.5, '2026-09-10', null, 'הדפסת חוברות');
        $withInvoice->attachInvoice($this->invoiceFile('חשבונית 17.pdf'));
        Expense::createForSupplier($supplier, 300, '2026-09-12');
        Expense::createForSupplier($supplier, 999, '2026-09-13')->deleteIfNoInvoice();

        $response = $this->actingAs($this->owner)->get(route('expenses.export'))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('ספק', $sheet->getCell('C1')->getValue());
        // Newest first; the deleted expense is left out.
        $this->assertSame('12/09/2026', $sheet->getCell('B2')->getFormattedValue());
        $this->assertSame('חסרה', $sheet->getCell('K2')->getValue());
        $this->assertSame($supplier->name, $sheet->getCell('C3')->getValue());
        $this->assertEquals(1250.5, $sheet->getCell('I3')->getValue());
        $this->assertSame('יש', $sheet->getCell('K3')->getValue());
        $this->assertSame('חשבונית 17.pdf', $sheet->getCell('L3')->getValue());
        $this->assertSame('הדפסת חוברות', $sheet->getCell('N3')->getValue());
        $this->assertNull($sheet->getCell('A4')->getValue());
        $this->assertEquals(1550.5, $sheet->getCell('I5')->getCalculatedValue());
    }

    public function test_an_invoice_uploaded_on_the_expenses_page_can_be_viewed_and_downloaded(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());

        Livewire::actingAs($this->owner)->test('expenses')
            ->set("invoiceFiles.{$expense->id}", $this->invoiceFile('חשבונית ספק.pdf'))
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertTrue($expense->hasInvoiceFile());
        $this->assertSame(Expense::HAS_INVOICE_STATUS_NAME, $expense->status?->name);

        $this->actingAs($this->owner)->get(route('expenses.invoice', $expense))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->owner)->get(route('expenses.invoice', [$expense, 'download' => 1]))
            ->assertOk()
            // Browsers take the UTF-8 name (filename*) — the plain filename= is only an ASCII fallback.
            ->assertHeader('content-disposition', "attachment; filename=invoice-{$expense->id}.pdf; filename*=utf-8''".rawurlencode('חשבונית ספק.pdf'));
    }

    public function test_an_invoice_upload_rejects_a_file_that_is_not_a_pdf_or_an_image(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());

        Livewire::actingAs($this->owner)->test('expenses')
            ->set("invoiceFiles.{$expense->id}", UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->assertHasErrors("invoiceFiles.{$expense->id}");

        $this->assertNull($expense->fresh()->document_id);
    }

    public function test_an_expense_without_an_invoice_file_has_no_download(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());

        $this->actingAs($this->owner)->get(route('expenses.invoice', $expense))->assertNotFound();
    }

    // ----- asking the supplier for a missing invoice -----

    public function test_requesting_an_invoice_emails_the_supplier_the_template_text_and_an_upload_link(): void
    {
        $this->fakeSmove();
        EmailTemplate::supplierInvoiceRequest()->update([
            'subject' => 'חשבונית ל-{{תאריך_התשלום}}',
            'content' => "שלום {{שם_הספק}},\nשילמנו ₪{{סכום}} (הוצאה {{מספר_הוצאה}}).\n{{כפתור_העלאה}}\nתודה",
        ]);
        $supplier = $this->createSupplier();
        $expense = Expense::createForSupplier($supplier, 1250.5, '2026-09-10');

        Livewire::actingAs($this->owner)->test('expenses')
            ->call('requestInvoice', $expense->id)
            ->assertHasNoErrors();

        $this->assertNotNull($expense->fresh()->invoice_requested_at);
        $campaign = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn ($request) => str_contains($request->url(), '/Campaigns'));
        $this->assertSame('חשבונית ל-10/09/2026', $campaign['subject']);
        $body = $campaign['body'];
        $this->assertStringContainsString(e($supplier->name), $body);
        $this->assertStringContainsString('₪1,250.50 (הוצאה '.$expense->id.')', $body);
        $this->assertStringContainsString('להעלאת החשבונית', $body);
        $this->assertStringContainsString(e(url('/supplier-invoice/'.$expense->id)), $body);
        $this->assertStringNotContainsString('כפתור_העלאה', $body);
        // The button sits where the text placed it — before the sign-off.
        $this->assertLessThan(strpos($body, 'תודה'), strpos($body, 'להעלאת החשבונית'));
    }

    public function test_an_invoice_is_not_requested_when_the_supplier_has_no_email_or_it_is_already_uploaded(): void
    {
        $this->fakeSmove();
        $noEmail = Expense::createForSupplier(tap($this->createSupplier())->update(['email' => null]), 100, '2026-09-01');
        $documented = Expense::createForSupplier($this->createSupplier(), 100, '2026-09-01');
        $documented->attachInvoice($this->invoiceFile());

        Livewire::actingAs($this->owner)->test('expenses')
            ->call('requestInvoice', $noEmail->id)
            ->call('requestInvoice', $documented->id);

        $this->assertNull($noEmail->fresh()->invoice_requested_at);
        $this->assertNull($documented->fresh()->invoice_requested_at);
        Http::assertNothingSent();
    }

    public function test_a_failed_send_is_not_recorded_as_requested(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(['rest.smoove.io/*' => Http::response(['message' => 'nope'], 500)]);
        $expense = Expense::createForSupplier($this->createSupplier(), 100, '2026-09-01');

        Livewire::actingAs($this->owner)->test('expenses')->call('requestInvoice', $expense->id);

        $this->assertNull($expense->fresh()->invoice_requested_at);
        $this->assertDatabaseHas('external_operations', ['expense_id' => $expense->id, 'operation_type' => 'expense_invoice_request', 'status' => 'failed']);
    }

    public function test_requesting_from_all_suppliers_sends_one_email_per_supplier_with_a_link_per_missing_invoice(): void
    {
        $this->fakeSmove();
        $several = $this->createSupplier();
        $first = Expense::createForSupplier($several, 1000, '2026-09-03');
        $second = Expense::createForSupplier($several, 730.5, '2026-09-21');
        $documented = Expense::createForSupplier($several, 50, '2026-09-25');
        $documented->attachInvoice($this->invoiceFile());
        $one = $this->createSupplier();
        $single = Expense::createForSupplier($one, 200, '2026-09-10');
        $noEmail = Expense::createForSupplier(tap($this->createSupplier())->update(['email' => null]), 90, '2026-09-11');

        Livewire::actingAs($this->owner)->test('expenses')
            ->assertSee('בקשת חשבוניות מכל הספקים (3)')
            ->call('requestAllMissingInvoices')
            ->assertHasNoErrors();

        $campaigns = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($request) => str_contains($request->url(), '/Campaigns'))->values();
        $this->assertCount(2, $campaigns);

        $bulk = $campaigns->first(fn ($request) => str_contains($request['body'], e($several->name)));
        $this->assertSame('בקשה לחשבוניות עבור 2 תשלומים', $bulk['subject']);
        $this->assertStringContainsString('(סך הכול ₪1,730.50)', $bulk['body']);
        $this->assertStringContainsString('תשלום מתאריך 03/09/2026 · ₪1,000', $bulk['body']);
        $this->assertStringContainsString('תשלום מתאריך 21/09/2026 · ₪730.50', $bulk['body']);
        $this->assertStringContainsString(e(url('/supplier-invoice/'.$first->id)), $bulk['body']);
        $this->assertStringContainsString(e(url('/supplier-invoice/'.$second->id)), $bulk['body']);
        $this->assertStringNotContainsString('/supplier-invoice/'.$documented->id.'?', $bulk['body']);
        $this->assertStringNotContainsString('רשימת_ההוצאות', $bulk['body']);

        // A supplier missing just one invoice gets the single-expense email.
        $singleEmail = $campaigns->first(fn ($request) => str_contains($request['body'], e($one->name)));
        $this->assertSame(EmailTemplate::supplierInvoiceRequest()->render($single->invoiceRequestData())['subject'], $singleEmail['subject']);
        $this->assertStringContainsString(e(url('/supplier-invoice/'.$single->id)), $singleEmail['body']);

        $this->assertNotNull($first->fresh()->invoice_requested_at);
        $this->assertNotNull($second->fresh()->invoice_requested_at);
        $this->assertNotNull($single->fresh()->invoice_requested_at);
        $this->assertNull($documented->fresh()->invoice_requested_at);
        $this->assertNull($noEmail->fresh()->invoice_requested_at);
    }

    public function test_one_suppliers_failed_send_does_not_stop_the_others(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        $failing = $this->createSupplier();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response([], 200)
            : (str_contains($request->url(), '/Contacts') && $request['email'] === $failing->email
                ? Http::response(['message' => 'bad address'], 400)
                : Http::response(['id' => 123], 200)));
        $failed = Expense::createForSupplier($failing, 100, '2026-09-01');
        $sent = Expense::createForSupplier($this->createSupplier(), 100, '2026-09-01');

        Livewire::actingAs($this->owner)->test('expenses')->call('requestAllMissingInvoices');

        $this->assertNull($failed->fresh()->invoice_requested_at);
        $this->assertNotNull($sent->fresh()->invoice_requested_at);
    }

    public function test_the_supplier_uploads_the_invoice_from_the_emailed_link_without_logging_in(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 480, '2026-09-03', null, 'הערה פנימית');

        $this->get($expense->invoiceUploadUrl())
            ->assertOk()
            ->assertSee('03/09/2026')
            ->assertSee('480')
            ->assertDontSee('הערה פנימית');

        Livewire::test('supplier-invoice-upload', ['expense' => $expense])
            ->set('invoice', $this->invoiceFile('INV-2231.pdf'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('החשבונית התקבלה');

        $expense->refresh();
        $this->assertTrue($expense->hasInvoiceFile());
        $this->assertSame('INV-2231.pdf', $expense->invoiceFileName());
        $this->assertSame(Expense::HAS_INVOICE_STATUS_NAME, $expense->status?->name);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'expense.invoice_uploaded_by_supplier', 'expense_id' => $expense->id]);
    }

    public function test_the_supplier_upload_page_rejects_a_wrong_file_type_and_a_second_upload(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 480, '2026-09-03');

        Livewire::test('supplier-invoice-upload', ['expense' => $expense])
            ->set('invoice', UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'))
            ->call('save')
            ->assertHasErrors('invoice');
        $this->assertNull($expense->fresh()->document_id);

        $first = $expense->attachInvoice($this->invoiceFile('first.pdf'));

        Livewire::test('supplier-invoice-upload', ['expense' => $expense])
            ->set('invoice', $this->invoiceFile('second.pdf'))
            ->call('save');
        $this->assertSame('first.pdf', $expense->fresh()->invoiceFileName());
        $this->assertSame($first->file_reference, $expense->fresh()->document->file_reference);
    }

    public function test_the_supplier_upload_page_needs_a_valid_unexpired_link(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 480, '2026-09-03');

        $this->get('/supplier-invoice/'.$expense->id)->assertForbidden();
        $this->get($expense->invoiceUploadUrl().'0')->assertForbidden();

        $url = $expense->invoiceUploadUrl();
        $this->travel(Expense::INVOICE_UPLOAD_LINK_DAYS + 1)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_the_invoice_request_wording_is_edited_on_the_settings_email_templates_tab(): void
    {
        $single = EmailTemplate::supplierInvoiceRequest();
        $bulk = EmailTemplate::supplierInvoicesRequest();

        Livewire::actingAs($this->owner)->test('settings')
            ->set('activeTab', 'email-templates')
            ->assertSee($single->name)->assertSee($bulk->name)
            ->call('selectEmailTemplate', $single->id)
            ->assertSet('emailTemplateSubject', $single->subject)
            ->set('emailTemplateSubject', 'נושא חדש {{שם_הספק}}')
            ->set('emailTemplateContent', 'גוף חדש {{סכום}}')
            ->assertSee('נושא חדש דפוס הראל')
            ->assertSee('כפתור העלאת החשבונית')
            ->call('saveEmailTemplate')
            ->assertHasNoErrors()
            ->call('selectEmailTemplate', $bulk->id)
            ->set('emailTemplateSubject', 'חסרות {{מספר_החשבוניות_החסרות}} חשבוניות')
            ->assertSee('חסרות 2 חשבוניות')
            ->call('saveEmailTemplate')
            ->assertHasNoErrors();

        $this->assertSame('נושא חדש {{שם_הספק}}', $single->fresh()->subject);
        $this->assertSame('גוף חדש {{סכום}}', $single->fresh()->content);
        $this->assertSame('חסרות {{מספר_החשבוניות_החסרות}} חשבוניות', $bulk->fresh()->subject);
        // The expenses page finds them by type — saving never changes it.
        $this->assertSame(EmailTemplate::SUPPLIER_INVOICE_REQUEST, $single->fresh()->template_type);
        $this->assertSame(EmailTemplate::SUPPLIER_INVOICES_REQUEST, $bulk->fresh()->template_type);

        Livewire::actingAs($this->owner)->test('document-templates')->assertDontSee($single->name);
    }

    // ----- income statement page -----

    public function test_the_income_statement_page_filters_and_links_its_pdf_to_the_same_filters(): void
    {
        $supplier = $this->createSupplier();
        Expense::createForSupplier($supplier, 300, '2026-09-10');
        Expense::createForSupplier($supplier, 120, '2025-02-10');

        Livewire::actingAs($this->owner)->test('cashflow-report')
            ->assertSee('דוח הכנסות והוצאות')
            ->assertSee('₪420')
            ->set('years', ['2026'])
            ->assertSee('₪300')
            ->assertDontSee('₪420')
            ->assertSee(e(route('cashflow-report.pdf', ['years' => ['2026'], 'detail' => 'summary'])), false)
            ->assertSee(e(route('cashflow-report.pdf', ['years' => ['2026'], 'detail' => 'full'])), false)
            ->call('clearFilters')
            ->assertSee('₪420');

        foreach (['summary', 'full'] as $detail) {
            $this->actingAs($this->owner)->get(route('cashflow-report.pdf', ['years' => [2026], 'months' => [9], 'detail' => $detail]))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }

        $this->actingAs($this->owner)->get(route('cashflow-report.pdf', ['months' => [13]]))->assertSessionHasErrors('months.0');
        $this->actingAs($this->owner)->get(route('cashflow-report.pdf', ['detail' => 'huge']))->assertSessionHasErrors('detail');
    }

    public function test_income_statement_filters_start_at_all_and_unticking_keeps_the_rest(): void
    {
        $component = Livewire::actingAs($this->owner)->test('cashflow-report')
            ->assertSee('כל החודשים')
            ->assertSee('כל התוכניות');

        // From "all", unticking one month leaves the other eleven.
        $component->call('toggleFilter', 'months', '3');
        $this->assertCount(11, $component->get('months'));
        $this->assertNotContains('3', $component->get('months'));
        $component->assertSee('11 חודשים');

        // Ticking it back means everything again — stored as "all".
        $component->call('toggleFilter', 'months', '3')->assertSet('months', [])->assertSee('כל החודשים');

        // Narrow to two months, then "הכל" restores everything.
        $component->set('months', ['9', '10'])->assertSee('ספטמבר, אוקטובר')
            ->call('selectAll', 'months')->assertSet('months', []);

        // "הכל" again unticks everything — the report is empty and can't be printed...
        $component->call('selectAll', 'months')->assertSet('months', ['none'])
            ->assertSee('לא נבחר')
            ->assertSee('לא נבחר אף פריט')
            ->assertSet('years', [])
            ->assertDontSee(e(route('cashflow-report.pdf', ['months' => ['none'], 'detail' => 'full'])), false);
        $this->assertSame(0.0, $component->instance()->statement()['expense_total']);

        // ...until a single month is picked.
        $component->call('toggleFilter', 'months', '9')->assertSet('months', ['9'])->assertSee('ספטמבר')->assertDontSee('לא נבחר אף פריט');

        // Unticking that last one leaves nothing ticked again.
        $component->call('toggleFilter', 'months', '9')->assertSet('months', ['none']);

        $component->call('toggleFilter', 'months', '13')->assertStatus(404);
    }

    public function test_picking_a_single_month_after_clearing_reports_only_that_month(): void
    {
        $supplier = $this->createSupplier();
        Expense::createForSupplier($supplier, 300, '2026-09-10');
        Expense::createForSupplier($supplier, 120, '2026-02-10');

        Livewire::actingAs($this->owner)->test('cashflow-report')
            ->assertSee('₪420')
            ->call('selectAll', 'months')
            ->call('toggleFilter', 'months', '2')
            ->assertSee('₪120')
            ->assertDontSee('₪420')
            ->assertSee(e(route('cashflow-report.pdf', ['months' => ['2'], 'detail' => 'full'])), false);
    }

    public function test_the_short_pdf_has_totals_only_and_the_detailed_one_every_expense(): void
    {
        $supplier = $this->createSupplier();
        Expense::createForSupplier($supplier, 300, '2026-09-10', null, 'הדפסת חוברות לבדיקה');
        $statement = app(\App\Services\RevenueReport::class)->statement();

        $summary = view('reports.statement-pdf', ['statement' => $statement, 'detail' => 'summary'])->render();
        $full = view('reports.statement-pdf', ['statement' => $statement, 'detail' => 'full'])->render();

        $this->assertStringContainsString('₪300', $summary);
        $this->assertStringNotContainsString(e($supplier->name), $summary);
        $this->assertStringContainsString(e($supplier->name), $full);
        $this->assertStringContainsString('10/09/2026', $full);
        $this->assertStringContainsString('הדפסת חוברות לבדיקה', $full);
    }

    public function test_a_second_supplier_with_the_same_name_asks_before_saving(): void
    {
        $this->fakeSmove();
        $existing = Supplier::create(['name' => 'דפוס הצפון', 'classification' => 'עוסק פטור']);

        // Editing the supplier itself never asks.
        Livewire::actingAs($this->owner)->test('suppliers')
            ->call('editSupplier', $existing->id)
            ->set('notes', 'עודכן')
            ->call('saveSupplier')
            ->assertNoJs();
        $this->assertSame('עודכן', $existing->fresh()->notes);

        $component = Livewire::actingAs($this->owner)->test('suppliers')
            ->set('name', '  דפוס הצפון ')
            ->call('saveSupplier');

        $this->assertSame(1, Supplier::count(), 'Nothing is saved before the confirmation.');
        $this->assertStringContainsString('$wire.saveSupplier(true)', collect($component->effects['xjs'] ?? [])->pluck('expression')->implode(''));

        $component->call('saveSupplier', true);
        $this->assertSame(2, Supplier::count());
    }

    public function test_a_check_is_income_only_once_it_cleared_and_in_the_month_it_cleared(): void
    {
        $customer = $this->createCustomer();
        $deal = Deal::createForCustomer($customer, $this->createProgram(), null, 400);
        $check = PaymentMethod::create(['name' => "צ'קים", 'type' => 'check', 'is_active' => true]);
        $payment = $deal->recordPayment($deal->version, 400, '2026-09-05', $check->id, '100', '2026-11-01');
        $report = app(\App\Services\RevenueReport::class);

        $this->assertSame(0.0, (float) $report->statement()['revenue_total']);

        $payment->update(['check_status' => \App\Models\Payment::CHECK_CLEARED, 'cleared_date' => '2026-11-02 10:00:00']);

        $this->assertEquals(400, $report->statement()['revenue_total']);
        $this->assertEquals(400, $report->statement([2026], [11])['revenue_total']);
        $this->assertEquals(0, $report->statement([2026], [9])['revenue_total']);
    }

    private function fakeSmove(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        // Contact lookup finds nobody, so the contact is created, then the campaign sent.
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? [] : ['id' => 123], 200));
    }

    private function invoiceFile(string $name = 'invoice.pdf', string $mime = 'application/pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 30, $mime);
    }

    /** An expense invoice is the supplier's own document — attaching one needs no template (DocumentTemplate::SYSTEM_TYPES). */
    public function test_attaching_an_invoice_needs_no_template(): void
    {
        DocumentTemplate::where('document_type', 'expense_invoice')->delete();
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());

        $document = $expense->attachInvoice($this->invoiceFile());

        $this->assertNull($document->document_template_id);
        $this->assertSame('expense_invoice', $document->document_type);
    }

    /**
     * A deal document and an expense-invoice document coexist correctly:
     * every generateFor() branch sets exactly one of deal_id/expense_id,
     * matching the documents-table exactly-one-of CHECK constraint.
     */
    public function test_a_deal_document_and_an_expense_invoice_document_coexist(): void
    {
        $deal = $this->dealWithQuote();
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());
        $expenseInvoice = $expense->attachInvoice($this->invoiceFile());

        $dealDocument = $deal->documents()->where('document_type', 'quote')->firstOrFail();

        $this->assertNotNull($dealDocument->deal_id);
        $this->assertNull($dealDocument->expense_id);

        $this->assertNull($expenseInvoice->deal_id);
        $this->assertNotNull($expenseInvoice->expense_id);
    }

    // ----- missing-invoice detection (FR-6.7-FR-6.9) -----

    public function test_an_expense_in_the_still_open_current_month_is_not_flagged(): void
    {
        Carbon::setTestNow('2026-08-15');
        $expense = Expense::createForSupplier($this->createSupplier(), 500, '2026-08-05');

        $this->assertSame(0, Expense::missingInvoiceForClosedMonth()->where('id', $expense->id)->count());
    }

    public function test_the_same_expense_is_flagged_once_its_month_has_closed(): void
    {
        Carbon::setTestNow('2026-08-15');
        $expense = Expense::createForSupplier($this->createSupplier(), 500, '2026-08-05');

        Carbon::setTestNow('2026-09-01');

        $this->assertSame(1, Expense::missingInvoiceForClosedMonth()->where('id', $expense->id)->count());
    }

    public function test_an_expense_with_an_attached_document_is_never_flagged(): void
    {
        Carbon::setTestNow('2026-08-15');
        $expense = Expense::createForSupplier($this->createSupplier(), 500, '2026-08-05');
        $expense->attachInvoice($this->invoiceFile());

        Carbon::setTestNow('2026-09-01');

        $this->assertSame(0, Expense::missingInvoiceForClosedMonth()->where('id', $expense->id)->count());
    }

    public function test_reminder_job_logs_activity_for_a_closed_month_missing_invoice(): void
    {
        Mail::fake();
        Notification::fake();

        Carbon::setTestNow('2026-08-15');
        $expense = Expense::createForSupplier($this->createSupplier(), 500, '2026-08-05');
        Carbon::setTestNow('2026-09-01');

        $this->fakeSmove();

        $this->artisan('expenses:process-reminders');

        $this->assertTrue(ActivityLog::where('activity_type', 'expense.invoice_reminder_due')->where('expense_id', $expense->id)->exists());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        // The supplier is never emailed automatically — only from the expenses page.
        Http::assertNothingSent();
    }

    public function test_reminder_job_never_flags_an_expense_from_the_open_month(): void
    {
        Mail::fake();

        Carbon::setTestNow('2026-08-15');
        $expense = Expense::createForSupplier($this->createSupplier(), 500, '2026-08-05');

        $this->artisan('expenses:process-reminders');

        $this->assertFalse(ActivityLog::where('activity_type', 'expense.invoice_reminder_due')->where('expense_id', $expense->id)->exists());
        Mail::assertNothingSent();
    }

    // ----- helpers -----

    private function userWithOnlyCustomersPermission(): User
    {
        $limited = Role::create(['name' => 'עובדת מכירות מוגבלת '.random_int(1, 999999), 'is_active' => true]);
        $limited->permissions()->create(['resource' => 'customers', 'action' => 'manage', 'is_allowed' => true]);

        return User::create([
            'name' => 'שרית לוי', 'email' => 'sarit'.random_int(1, 999999).'@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);
    }

    private function createSupplier(): Supplier
    {
        return Supplier::create([
            'name' => 'ספק לבדיקה '.random_int(1, 999999),
            'company_number' => (string) random_int(100000000, 999999999),
            'classification' => 'עוסק פטור',
            'phone' => '03-0000000',
            'email' => 'supplier'.random_int(1, 999999).'@example.com',
            'notes' => null,
        ]);
    }

    private function createProgram(): Program
    {
        return Program::create([
            'name' => 'תוכנית לבדיקת הוצאות '.random_int(1, 999999),
            'description' => null,
            'price' => 400,
            'is_premium' => false,
            'is_subscription_type' => false,
            'is_active' => true,
        ]);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת הוצאות '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }

    private function dealWithQuote(): Deal
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();
        $deal = Deal::createForCustomer($customer, $program, null, 400);

        $template = DocumentTemplate::create([
            'document_type' => 'quote',
            'name' => 'תבנית הצעת מחיר לבדיקה '.random_int(1, 999999),
            'content' => 'תוכן בדיקה',
            'is_active' => true,
        ]);
        Document::generateFor($deal, $template);

        return $deal;
    }
}
