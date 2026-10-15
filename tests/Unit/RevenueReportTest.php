<?php

namespace Tests\Unit;

use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\RevenueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Build-plan 11 — App\Services\RevenueReport unit coverage (FR-6.1-FR-6.4/
 * FR-6.11-FR-6.13). Kept separate from SuppliersExpensesTest per this
 * stage's own instruction to keep the revenue math independently
 * unit-testable.
 */
class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private RevenueReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = app(RevenueReport::class);
    }

    // ----- ordinary (non-subscription) deals -----

    public function test_revenue_by_month_sums_payments_in_the_month_they_were_received(): void
    {
        $deal = $this->dealWithPaymentMethod(agreedAmount: 1000);

        Carbon::setTestNow('2026-03-10');
        $deal->recordPayment($deal->version, 400);

        Carbon::setTestNow('2026-04-05');
        $deal->recordPayment($deal->fresh()->version, 600);

        $revenue = $this->report->revenueByMonth();

        $this->assertSame(400.0, $revenue['2026-03']);
        $this->assertSame(600.0, $revenue['2026-04']);
    }

    public function test_revenue_by_program_groups_by_the_deals_program(): void
    {
        $program = $this->createProgram('תוכנית א');
        $deal = $this->dealWithPaymentMethod(agreedAmount: 500, program: $program);
        $deal->recordPayment($deal->version, 500);

        $revenue = $this->report->revenueByProgram();

        $this->assertSame(500.0, $revenue[$program->name]);
    }

    public function test_a_bundle_deals_revenue_is_bucketed_under_the_synthetic_bundle_label(): void
    {
        $bundle = \App\Models\Bundle::create([
            'name' => 'מארז לבדיקה', 'description' => null, 'price' => 300, 'is_active' => true,
        ]);
        $customer = $this->createCustomer();
        $deal = Deal::createForCustomer($customer, null, $bundle, 300);
        $method = PaymentMethod::create(['name' => 'אמצעי לבדיקה '.random_int(1, 999999), 'type' => 'bank_transfer', 'is_active' => true]);
        $deal->updatePaymentMethod($method->id);
        $deal->recordPayment($deal->version, 300);

        $revenue = $this->report->revenueByProgram();

        $this->assertSame(300.0, $revenue[RevenueReport::BUNDLE_BUCKET_LABEL]);
    }

    // ----- FR-6.4: subscription revenue is amortized, never a lump sum -----

    public function test_a_subscription_paid_in_full_in_one_month_is_spread_evenly_across_its_12_months(): void
    {
        Carbon::setTestNow('2026-01-15');

        $bundle = $this->createSubscriptionBundle();
        $deal = $this->dealWithPaymentMethod(agreedAmount: 4800, bundle: $bundle);
        // Paid in full immediately, in the very same month the subscription starts.
        $deal->recordPayment($deal->version, 4800);

        $revenue = $this->report->revenueByMonth();

        // 12 equal slices of 400, one per month from January through December —
        // never a single 4800 lump sum in January.
        foreach (range(1, 12) as $offset) {
            $month = Carbon::parse('2026-01-01')->addMonths($offset - 1)->format('Y-m');
            $this->assertArrayHasKey($month, $revenue, "Expected a revenue slice for {$month}");
            $this->assertEqualsWithDelta(400.0, $revenue[$month], 0.01, "Expected month {$month} to show the amortized slice, not a lump sum");
        }

        $this->assertEqualsWithDelta(4800.0, array_sum($revenue), 0.01);
    }

    public function test_subscription_revenue_recognition_is_independent_of_actual_payment_amount(): void
    {
        Carbon::setTestNow('2026-01-15');

        $bundle = $this->createSubscriptionBundle();
        $deal = $this->dealWithPaymentMethod(agreedAmount: 1200, bundle: $bundle);
        // Only a small partial payment has actually been received...
        $deal->recordPayment($deal->version, 100);

        // ...yet recognized revenue is still the full agreed_price spread
        // over 12 months (FR-6.4 spreads agreed_price, not cash received).
        $revenue = $this->report->revenueByMonth();
        $this->assertEqualsWithDelta(1200.0, array_sum($revenue), 0.01);

        $byProgram = $this->report->revenueByProgram();
        $this->assertEqualsWithDelta(1200.0, $byProgram[$bundle->name], 0.01);
    }

    // ----- profit = revenue minus program-attributed expenses -----

    public function test_profit_by_month_subtracts_every_expense_incurred_that_month_regardless_of_program(): void
    {
        Carbon::setTestNow('2026-05-01');
        $deal = $this->dealWithPaymentMethod(agreedAmount: 1000);
        $deal->recordPayment($deal->version, 1000);

        $supplier = $this->createSupplier();
        Expense::createForSupplier($supplier, 300, '2026-05-15'); // no program — still reduces the month total

        $profit = $this->report->profitByMonth();

        $this->assertSame(700.0, $profit['2026-05']);
    }

    public function test_profit_by_program_subtracts_only_expenses_attributed_to_that_program(): void
    {
        $program = $this->createProgram('תוכנית לבדיקת רווח');
        $deal = $this->dealWithPaymentMethod(agreedAmount: 1000, program: $program);
        $deal->recordPayment($deal->version, 1000);

        $supplier = $this->createSupplier();
        Expense::createForSupplier($supplier, 300, now()->toDateString(), $program);
        Expense::createForSupplier($supplier, 999, now()->toDateString()); // no program — excluded from by-program profit

        $profit = $this->report->profitByProgram();

        $this->assertSame(700.0, $profit[$program->name]);
    }

    // ----- the filtered income statement (⚡cashflow-report / its PDF) -----

    public function test_the_statement_lists_income_by_program_and_expenses_by_supplier_with_net_profit(): void
    {
        [$programA, $programB, $supplier] = $this->statementFixtures();

        $statement = $this->report->statement();

        $this->assertSame([[$programA->name, 1500.0, 2], [$programB->name, 800.0, 1]], array_map(fn ($line) => [$line['label'], $line['amount'], $line['count']], $statement['revenue']));
        $this->assertSame(2300.0, $statement['revenue_total']);
        $this->assertSame([[$supplier->name, 650.0, 3]], array_map(fn ($line) => [$line['label'], $line['amount'], $line['count']], $statement['expenses']));
        // Each line opens to its own items, oldest first.
        $this->assertSame(['10/09/2025', '10/09/2026'], array_column($statement['revenue'][0]['items'], 'date'));
        $this->assertStringStartsWith('תשלום · ', $statement['revenue'][0]['items'][0]['description']);
        $this->assertSame([200.0, 350.0, 100.0], array_column($statement['expenses'][0]['items'], 'amount'));
        $this->assertSame(650.0, $statement['expense_total']);
        $this->assertSame(1650.0, $statement['profit']);
        $this->assertSame('כל השנים', $statement['period']);
    }

    public function test_the_statement_filters_by_year_month_and_program_in_any_combination(): void
    {
        [$programA, $programB] = $this->statementFixtures();

        $year = $this->report->statement(years: [2026]);
        $this->assertSame(1600.0, $year['revenue_total']);
        $this->assertSame(450.0, $year['expense_total']);

        // September of both years.
        $september = $this->report->statement(months: [9]);
        $this->assertSame(1500.0, $september['revenue_total']);

        // Program A only: its income and its own expenses — never another program's or a program-less one.
        $onlyA = $this->report->statement(sources: ["program:{$programA->id}"]);
        $this->assertSame(1500.0, $onlyA['revenue_total']);
        $this->assertSame(100.0, $onlyA['expense_total']);

        // Combined: program B, 2026, March.
        $combined = $this->report->statement([2026], [3], ["program:{$programB->id}"]);
        $this->assertSame(800.0, $combined['revenue_total']);
        $this->assertSame(0.0, $combined['expense_total']);
        $this->assertSame("שנים: 2026 · חודשים: מרץ · תוכניות: {$programB->name}", $combined['period']);

        // Program-less expenses have their own filter option.
        $general = $this->report->statement(sources: [RevenueReport::NO_PROGRAM_SOURCE]);
        $this->assertSame(0.0, $general['revenue_total']);
        $this->assertSame(550.0, $general['expense_total']);
        $this->assertArrayHasKey(RevenueReport::NO_PROGRAM_SOURCE, $this->report->sourceOptions());
    }

    public function test_the_statement_spreads_a_subscription_and_files_it_under_its_bundle(): void
    {
        Carbon::setTestNow('2026-01-15');
        $bundle = $this->createSubscriptionBundle();
        $deal = $this->dealWithPaymentMethod(agreedAmount: 1200, bundle: $bundle);
        $deal->recordPayment($deal->version, 1200);

        $march = $this->report->statement(years: [2026], months: [3]);
        $this->assertEqualsWithDelta(100.0, $march['revenue_total'], 0.01);
        $this->assertSame($bundle->name, $march['revenue'][0]['label']);

        $this->assertEqualsWithDelta(1200.0, $this->report->statement(sources: ["bundle:{$bundle->id}"])['revenue_total'], 0.01);
        $this->assertContains(2026, $this->report->availableYears());
    }

    public function test_the_statement_pdf_renders(): void
    {
        $this->statementFixtures();

        $pdf = $this->report->statementPdf([2026]);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /**
     * Program A: ₪700 (Sep 2025) + ₪800 (Sep 2026); program B: ₪800 (Mar 2026).
     * Expenses, one supplier: ₪100 on A (Sep 2026), ₪200 general (Sep 2025), ₪350 general (Mar 2026).
     */
    private function statementFixtures(): array
    {
        $programA = $this->createProgram('תוכנית א לדוח');
        $programB = $this->createProgram('תוכנית ב לדוח');
        $supplier = $this->createSupplier();

        $dealA = $this->dealWithPaymentMethod(agreedAmount: 1500, program: $programA);
        Carbon::setTestNow('2025-09-10');
        $dealA->recordPayment($dealA->version, 700);
        Carbon::setTestNow('2026-09-10');
        $dealA->fresh()->recordPayment($dealA->fresh()->version, 800);

        $dealB = $this->dealWithPaymentMethod(agreedAmount: 800, program: $programB);
        Carbon::setTestNow('2026-03-05');
        $dealB->recordPayment($dealB->version, 800);

        Expense::createForSupplier($supplier, 100, '2026-09-12', $programA);
        Expense::createForSupplier($supplier, 200, '2025-09-20');
        Expense::createForSupplier($supplier, 350, '2026-03-20');

        return [$programA, $programB, $supplier];
    }

    // ----- helpers -----

    private function createSupplier(): Supplier
    {
        return Supplier::create([
            'name' => 'ספק לבדיקת דוח '.random_int(1, 999999),
            'company_number' => null,
            'classification' => 'עוסק פטור',
            'phone' => null,
            'email' => null,
            'notes' => null,
        ]);
    }

    private function createProgram(?string $name = null): Program
    {
        return Program::create([
            'name' => $name ?? 'תוכנית לבדיקת דוח '.random_int(1, 999999),
            'description' => null,
            'price' => 500,
            'is_premium' => false,
            'is_active' => true,
        ]);
    }

    private function createSubscriptionBundle(): Bundle
    {
        return Bundle::create([
            'name' => 'מנוי שנתי לבדיקת דוח '.random_int(1, 999999),
            'description' => null,
            'price' => 1200,
            'is_subscription_type' => true,
            'is_active' => true,
        ]);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת דוח '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => null,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }

    private function dealWithPaymentMethod(float $agreedAmount, ?Program $program = null, ?Bundle $bundle = null): Deal
    {
        $customer = $this->createCustomer();
        $program ??= ($bundle ? null : $this->createProgram());
        $deal = Deal::createForCustomer($customer, $program, $bundle, $agreedAmount);

        $method = PaymentMethod::create(['name' => 'אמצעי לבדיקת דוח '.random_int(1, 999999), 'type' => 'bank_transfer', 'is_active' => true]);
        $deal->updatePaymentMethod($method->id);

        return $deal->fresh();
    }
}
