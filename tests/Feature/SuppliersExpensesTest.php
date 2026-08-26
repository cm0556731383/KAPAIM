<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Expense;
use App\Models\Lead;
use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $document = $expense->attachInvoice();

        $expense->refresh();
        $this->assertSame($document->id, $expense->document_id);
        $this->assertSame(Expense::HAS_INVOICE_STATUS_NAME, $expense->status?->name);
        $this->assertSame('expense_invoice', $document->document_type);
        $this->assertNull($document->deal_id);
        $this->assertNull($document->business_entity_id);
    }

    public function test_attaching_an_invoice_twice_is_blocked(): void
    {
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());
        $expense->attachInvoice();

        $this->expectException(RuntimeException::class);
        $expense->fresh()->attachInvoice();
    }

    public function test_attaching_an_invoice_without_an_active_template_is_blocked(): void
    {
        DocumentTemplate::where('document_type', 'expense_invoice')->update(['is_active' => false]);
        $expense = Expense::createForSupplier($this->createSupplier(), 500, now()->toDateString());

        $this->expectException(RuntimeException::class);
        $expense->attachInvoice();
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
        $expenseInvoice = $expense->attachInvoice();

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
        $expense->attachInvoice();

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

        $this->artisan('expenses:process-reminders');

        $this->assertTrue(ActivityLog::where('activity_type', 'expense.invoice_reminder_due')->where('expense_id', $expense->id)->exists());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
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
