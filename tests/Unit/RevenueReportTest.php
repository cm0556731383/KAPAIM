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
