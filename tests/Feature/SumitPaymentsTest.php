<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Models\Lead;
use App\Models\PaymentLink;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Payments taken in SUMIT: payment pages sent to the customer (PaymentLink),
 * the card charge's receipt, standing orders, and the payment sync that
 * records them (sumit:sync-payments).
 */
class SumitPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_URL = 'https://pay.sumit.co.il/x/a/redirectpayment/?redirectid=abc';

    protected function setUp(): void
    {
        parent::setUp();

        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
    }

    /** What SUMIT's payments/get and payments/list return — see fakeSumit(). */
    private array $sumitPayments = [];

    /** What SUMIT's recurring/charge returns: the first month's charge and the recurring item. */
    private array $recurringResponse = [
        'Payment' => ['ID' => 500, 'ValidPayment' => true, 'Amount' => 40],
        'DocumentID' => 600,
        'RecurringCustomerItemIDs' => [88],
    ];

    /**
     * One fake for the whole test (Http::fake() stubs stack, the first match
     * wins); passing $payments only changes what it answers from now on.
     *
     * @param array<int, array>|null $payments
     */
    private function fakeSumit(?array $payments = null): void
    {
        if ($payments !== null) {
            $this->sumitPayments = $payments;

            return;
        }

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['Status' => 0, 'Data' => match ($path) {
                '/billing/payments/beginredirect/' => ['RedirectURL' => self::PAGE_URL],
                '/billing/payments/charge/' => ['Payment' => ['ID' => 31, 'ValidPayment' => true]],
                '/billing/recurring/charge/' => $this->recurringResponse,
                '/billing/payments/get/' => ['Payment' => collect($this->sumitPayments)->firstWhere('ID', $request['PaymentID']) ?? []],
                '/billing/payments/list/' => ['Payments' => $this->sumitPayments, 'HasNextPage' => false],
                default => ['DocumentID' => 555],
            }], 200);
        });
    }

    private function sumitPayment(PaymentLink $link, int $id = 9001, ?float $amount = null): array
    {
        return [
            'ID' => $id, 'ValidPayment' => true, 'Amount' => $amount ?? (float) $link->amount,
            'ExternalIdentifier' => $link->externalIdentifier(), 'DocumentID' => 777, 'Date' => now()->toIso8601String(),
        ];
    }

    private function newLink(Deal $deal, float $amount = 400): PaymentLink
    {
        return PaymentLink::createFor($deal, $amount, app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    public function test_a_payment_page_is_opened_in_sumit_for_the_deal(): void
    {
        $this->fakeSumit();
        $deal = $this->cardDealWithInvoice();

        $link = $this->newLink($deal, 150);

        $this->assertSame(self::PAGE_URL, $link->url);
        $this->assertSame(PaymentLink::STATUS_PENDING, $link->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/billing/payments/beginredirect/')
            && $request['ExternalIdentifier'] === "kapaim-paylink-{$link->id}"
            && $request['DocumentType'] === 2
            && $request['Items'][0]['UnitPrice'] == 150
            && $request['RedirectURL'] === route('payment-links.return', $link->token)
            && $request['Credentials']['CompanyID'] === 12345678);
    }

    public function test_a_payment_link_needs_an_invoice_and_a_balance(): void
    {
        $this->fakeSumit();

        $noInvoice = $this->cardDeal();
        $this->assertRefused(fn () => $this->newLink($noInvoice));

        $deal = $this->cardDealWithInvoice();
        $this->assertRefused(fn () => $this->newLink($deal, 401)); // more than the balance

        $this->assertSame(0, PaymentLink::count());

        // The deal's planned method doesn't matter — any deal can be paid by link.
        $deal->update(['payment_method_id' => PaymentMethod::create(['name' => 'העברה', 'type' => 'bank_transfer', 'is_active' => true])->id]);
        $this->assertSame(PaymentLink::STATUS_PENDING, $this->newLink($deal->fresh(), 100)->status);
    }

    public function test_returning_from_a_paid_page_records_the_payment_and_sumits_receipt_once(): void
    {
        $deal = $this->cardDealWithInvoice();
        $this->fakeSumit();
        $link = $this->newLink($deal);
        $this->fakeSumit([$this->sumitPayment($link)]);

        $this->get(route('payment-links.return', $link->token).'?OG-PaymentID=9001')->assertOk()->assertSee('התשלום התקבל');
        $this->get(route('payment-links.return', $link->token).'?OG-PaymentID=9001')->assertOk(); // refresh

        $payment = $deal->payments()->sole();
        $this->assertEquals(400, $payment->amount);
        $this->assertSame('9001', $payment->sumit_payment_id);
        $this->assertSame('777', $payment->sumit_document_id);
        $this->assertSame(PaymentLink::STATUS_PAID, $link->fresh()->status);
        $this->assertSame(Deal::PAID_STATUS_NAME, $deal->fresh()->status->name);
        $this->assertSame(1, Receipt::where('payment_id', $payment->id)->count());

        // SUMIT already issued the receipt — the app must not issue another.
        $this->expectException(RuntimeException::class);
        Receipt::issueFor($deal->fresh(), $payment, app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    public function test_a_payment_of_another_link_or_an_invalid_one_is_not_recorded(): void
    {
        $deal = $this->cardDealWithInvoice();
        $this->fakeSumit();
        $link = $this->newLink($deal, 100);
        $other = $this->newLink($deal, 100);
        $this->fakeSumit([$this->sumitPayment($other, 1), ['ValidPayment' => false] + $this->sumitPayment($link, 2)]);

        $this->get(route('payment-links.return', $link->token).'?OG-PaymentID=1')->assertOk()->assertSee('התשלום בבדיקה');
        $this->get(route('payment-links.return', $link->token).'?OG-PaymentID=2')->assertOk()->assertSee('התשלום בבדיקה');

        $this->assertSame(0, $deal->payments()->count());
        $this->assertSame(PaymentLink::STATUS_PENDING, $link->fresh()->status);
    }

    public function test_a_cancelled_payment_page_says_nothing_was_charged(): void
    {
        $this->fakeSumit();
        $link = $this->newLink($this->cardDealWithInvoice());

        $this->get(route('payment-links.return', ['token' => $link->token, 'cancelled' => 1]))->assertOk()->assertSee('התשלום לא בוצע');
        $this->get('/pay/'.str_repeat('a', 40).'/return')->assertNotFound();
    }

    public function test_the_sync_records_a_paid_link_whose_customer_never_returned(): void
    {
        $deal = $this->cardDealWithInvoice();
        $this->fakeSumit();
        $link = $this->newLink($deal, 250);
        $this->fakeSumit([$this->sumitPayment($link, 42)]);

        $this->artisan('sumit:sync-payments')->assertSuccessful();
        $this->artisan('sumit:sync-payments')->assertSuccessful();

        $this->assertSame(1, $deal->payments()->count());
        $this->assertSame('42', $deal->payments()->first()->sumit_payment_id);
        $this->assertSame(PaymentLink::STATUS_PAID, $link->fresh()->status);
    }

    public function test_a_failed_sync_is_recorded_for_the_dashboard(): void
    {
        $this->cardDealWithInvoice();
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 1, 'UserErrorMessage' => 'Invalid credentials'], 200)]);

        $this->artisan('sumit:sync-payments')->assertSuccessful();

        $this->assertDatabaseHas('external_operations', ['operation_type' => 'payments_sync', 'status' => 'failed', 'trigger_source' => 'scheduled_job']);
    }

    public function test_charging_a_saved_card_issues_the_receipt_right_away(): void
    {
        $this->fakeSumit();
        $deal = $this->cardDealWithInvoice();
        $this->actingAsOwner();

        Livewire::test('deal-detail', ['deal' => $deal])->set('paymentAmount', '400')->call('chargeCard')->assertSet('paymentError', null);

        $payment = $deal->payments()->sole();
        $this->assertSame(1, Receipt::where('payment_id', $payment->id)->count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/accounting/documents/create/') && $request['Details']['Type'] === 2);
    }

    public function test_a_charge_whose_receipt_fails_keeps_the_payment_and_says_why(): void
    {
        $this->fakeSumit();
        $deal = $this->cardDeal(); // no invoice — so no receipt yet
        BusinessEntity::create([
            'name' => 'עוסק', 'classification' => 'עוסק פטור', 'company_number' => '1', 'email' => 'b@example.com', 'phone' => '0',
            'is_active' => true, 'sumit_connection_name' => 'סאמיט', 'sumit_company_id' => '12345678', 'sumit_api_key' => 'x',
        ]);
        $this->actingAsOwner();

        $component = Livewire::test('deal-detail', ['deal' => $deal])->set('paymentAmount', '100')->call('chargeCard');

        $this->assertStringContainsString('הקבלה לא הופקה', (string) $component->get('paymentError'));
        $this->assertSame(1, $deal->payments()->count());
        $this->assertSame(0, Receipt::count());
    }

    // ----- balance -----

    public function test_an_issued_credit_note_comes_off_the_balance_and_a_draft_doesnt(): void
    {
        DocumentTemplate::create(['document_type' => 'credit_note', 'name' => 'זיכוי', 'content' => 'זיכוי', 'is_active' => true]);
        $deal = $this->cardDealWithInvoice(400);
        $deal->issuedInvoice()->businessEntity->update(['classification' => 'עוסק מורשה']); // a tax invoice, credited regardless of payment
        $deal->recordPayment($deal->version, 100);
        $creditNote = Document::generateCreditNoteFor($deal->fresh(), 250);

        $this->assertEquals(300, $deal->fresh()->outstandingBalance()); // draft: not credited yet

        $creditNote->update(['sent_at' => now()]);
        $deal = $deal->fresh();

        $this->assertEquals(250, $deal->totalCredited());
        $this->assertEquals(50, $deal->outstandingBalance());
        $this->assertFalse($deal->isFullyPaid());

        $deal->recordPayment($deal->version, 50);
        $this->assertSame(Deal::PAID_STATUS_NAME, $deal->fresh()->status->name);
        $this->assertEquals(0, $deal->fresh()->outstandingBalance());
    }

    // ----- standing orders -----

    public function test_registering_a_standing_order_records_the_first_charge_and_its_receipt(): void
    {
        $this->fakeSumit();
        $deal = $this->standingOrderDeal();

        $payment = $deal->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class));

        $this->assertEquals(40, $payment->amount);
        $this->assertSame('500', $payment->sumit_payment_id);
        $this->assertSame('600', $payment->sumit_document_id);
        $this->assertSame(1, Receipt::where('payment_id', $payment->id)->count());
        $this->assertSame(['88'], $deal->standingOrderItemIds());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/billing/recurring/charge/')
            && $request['DocumentType'] === 2 && $request['UpdateCustomerByEmail'] === true
            && $request['Items'][0]['UnitPrice'] == 400 && $request['Items'][0]['Recurrence'] === 1
            && ! isset($request['Items'][0]['Date_Start']));

        // A second registration would charge her twice.
        $this->assertRefused(fn () => $deal->fresh()->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class)));
    }

    public function test_a_standing_order_after_a_first_payment_starts_next_month_for_the_rest(): void
    {
        $this->recurringResponse = ['RecurringCustomerItemIDs' => [89]]; // nothing charged today
        $this->fakeSumit();
        $deal = $this->standingOrderDeal();
        $deal->recordPayment($deal->version, 100);

        $this->assertNull($deal->fresh()->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class)));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/billing/recurring/charge/')
            && $request['Items'][0]['UnitPrice'] == 300
            && $request['Items'][0]['Date_Start'] === now()->addMonthNoOverflow()->toDateString());
        $this->assertSame(1, $deal->payments()->count());
    }

    public function test_the_sync_records_each_monthly_collection_of_a_standing_order_once(): void
    {
        $this->fakeSumit();
        $deal = $this->standingOrderDeal();
        $deal->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class));
        $month2 = ['ID' => 501, 'ValidPayment' => true, 'Amount' => 40, 'DocumentID' => 601, 'RecurringCustomerItemIDs' => [88]];
        $someoneElse = ['ID' => 502, 'ValidPayment' => true, 'Amount' => 40, 'DocumentID' => 602, 'RecurringCustomerItemIDs' => [99]];
        $this->fakeSumit([$this->recurringResponse['Payment'] + ['RecurringCustomerItemIDs' => [88]], $month2, $someoneElse]);

        $this->artisan('sumit:sync-payments')->assertSuccessful();
        $this->artisan('sumit:sync-payments')->assertSuccessful();

        $this->assertSame(['500', '501'], $deal->payments()->pluck('sumit_payment_id')->all());
        $this->assertSame(2, Receipt::count());
        $this->assertTrue(\App\Models\ActivityLog::where('activity_type', 'payment.recorded')->where('deal_id', $deal->id)->exists());
    }

    public function test_no_standing_order_for_a_cancelled_subscription(): void
    {
        $this->fakeSumit();
        $deal = $this->standingOrderDeal();
        $deal->openSubscriptionIfApplicable(\App\Models\Bundle::create(['name' => 'מנוי', 'price' => 400, 'is_active' => true, 'is_subscription_type' => true]));
        $deal->fresh()->subscription->cancel();

        $this->assertRefused(fn () => $deal->fresh()->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class)));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/billing/recurring/charge/'));
    }

    public function test_a_standing_order_link_takes_the_first_month_then_registers_the_rest(): void
    {
        $this->recurringResponse = ['RecurringCustomerItemIDs' => [90]]; // starts next month: nothing charged now
        $this->fakeSumit();
        $deal = $this->standingOrderDeal(); // ₪400, no subscription
        $link = PaymentLink::createFor($deal, 100, app(ExternalOperationRunner::class), app(SummitClient::class), PaymentLink::PURPOSE_STANDING_ORDER);
        $this->fakeSumit([$this->sumitPayment($link, 70)]);

        // SUMIT's page itself says it's a standing order's first payment.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/billing/payments/beginredirect/')
            && str_contains($request['Items'][0]['Item']['Name'], 'הוראת קבע: תשלום 1 מתוך 4'));

        $this->get(route('payment-links.return', $link->token).'?OG-PaymentID=70')->assertOk()->assertSee('התשלום התקבל');

        $this->assertSame(['90'], $deal->fresh()->standingOrderItemIds());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/billing/recurring/charge/')
            && $request['Items'][0]['UnitPrice'] == 100
            && $request['Items'][0]['Recurrence'] === 3
            && $request['Items'][0]['Date_Start'] === now()->addMonthNoOverflow()->toDateString());

        // A card deal gets no standing-order link, and a registered one gets no second link.
        $this->assertRefused(fn () => PaymentLink::createFor($deal->fresh(), 100, app(ExternalOperationRunner::class), app(SummitClient::class), PaymentLink::PURPOSE_STANDING_ORDER));
        $this->assertRefused(fn () => PaymentLink::createFor($this->cardDealWithInvoice(), 100, app(ExternalOperationRunner::class), app(SummitClient::class), PaymentLink::PURPOSE_STANDING_ORDER));
    }

    // ----- the deal screen's payments box -----

    public function test_recording_a_bank_transfer_issues_its_receipt(): void
    {
        $this->fakeSumit();
        $deal = $this->cardDealWithInvoice();
        $this->actingAsOwner();
        $transfer = PaymentMethod::create(['name' => 'העברה בנקאית', 'type' => 'bank_transfer', 'is_active' => true]);

        Livewire::test('deal-detail', ['deal' => $deal])
            ->call('selectPaymentMethod', $transfer->id)
            ->set('paymentAmount', '150')
            ->call('recordPayment')
            ->assertSet('paymentError', null)
            ->assertDispatched('notify', type: 'success');

        $payment = $deal->payments()->sole();
        $this->assertSame($transfer->id, $payment->payment_method_id);
        $this->assertSame(1, Receipt::where('payment_id', $payment->id)->count());
    }

    public function test_a_checks_receipt_is_issued_when_it_clears_and_the_deal_can_also_be_paid_another_way(): void
    {
        $this->fakeSumit();
        $deal = $this->cardDealWithInvoice();
        $this->actingAsOwner();
        $check = PaymentMethod::create(['name' => "צ'קים", 'type' => 'check', 'is_active' => true]);

        $component = Livewire::test('deal-detail', ['deal' => $deal])
            ->call('selectPaymentMethod', $check->id)
            ->set('paymentAmount', '200')
            ->call('recordPayment');
        $payment = $deal->payments()->sole();
        $this->assertSame(0, Receipt::count());

        $component->call('markCheckCleared', $payment->id);
        $this->assertSame(1, Receipt::where('payment_id', $payment->id)->count());

        // A second payment of the same deal, another way.
        $transfer = PaymentMethod::create(['name' => 'העברה בנקאית', 'type' => 'bank_transfer', 'is_active' => true]);
        $component->call('selectPaymentMethod', $transfer->id)->set('paymentAmount', '100')->call('recordPayment')->assertSet('paymentError', null);

        $this->assertEqualsCanonicalizing([$check->id, $transfer->id], $deal->payments()->pluck('payment_method_id')->all());
        $this->assertSame(2, Receipt::count());
        $this->assertNotSame($check->id, $deal->fresh()->payment_method_id); // the box never touches the deal's planned method
    }

    // ----- checks -----

    public function test_several_checks_are_recorded_with_numbers_and_due_dates_and_no_receipt_yet(): void
    {
        $this->fakeSumit();
        $deal = $this->cardDealWithInvoice(900);
        $this->actingAsOwner();
        $checks = PaymentMethod::create(['name' => "צ'קים", 'type' => 'check', 'is_active' => true]);

        $component = Livewire::test('deal-detail', ['deal' => $deal])
            ->call('selectPaymentMethod', $checks->id)
            ->set('checkRows.0', ['number' => '1001', 'due_date' => '2026-11-01', 'amount' => '300', 'bank' => '12', 'branch' => '600', 'account' => '123456'])
            ->call('addCheckRow')
            ->call('addCheckRow');

        // A new row continues the last one — same checkbook.
        $this->assertSame(['number' => '1002', 'due_date' => '2026-12-01', 'amount' => '300', 'bank' => '12', 'branch' => '600', 'account' => '123456'], $component->get('checkRows.1'));

        $component->call('recordChecks')->assertSet('paymentError', null)->assertHasNoErrors();

        $this->assertSame(['1001', '1002', '1003'], $deal->payments()->orderBy('id')->pluck('check_number')->all());
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01'], $deal->payments()->orderBy('id')->get()->map(fn ($p) => $p->check_due_date->toDateString())->all());
        $this->assertSame(0, Receipt::count());
        $this->assertEquals(900, $deal->fresh()->totalPaid());
        $first = $deal->payments()->orderBy('id')->first();
        $this->assertSame([12, '600', '123456'], [$first->check_bank_number, $first->check_branch_number, $first->check_account_number]);
        $this->assertSame('בנק הפועלים (12) · סניף 600 · חשבון 123456', $first->checkBankLabel());
    }

    public function test_a_check_row_needs_a_number_due_date_and_amount(): void
    {
        $deal = $this->cardDealWithInvoice();
        $this->actingAsOwner();
        $checks = PaymentMethod::create(['name' => "צ'קים", 'type' => 'check', 'is_active' => true]);

        Livewire::test('deal-detail', ['deal' => $deal])
            ->call('selectPaymentMethod', $checks->id)
            ->set('checkRows.0', ['number' => '', 'due_date' => '', 'amount' => '0', 'bank' => 'x', 'branch' => 'abc', 'account' => 'a'])
            ->call('recordChecks')
            ->assertHasErrors(['checkRows.0.number', 'checkRows.0.due_date', 'checkRows.0.amount', 'checkRows.0.bank', 'checkRows.0.branch', 'checkRows.0.account']);

        $this->assertSame(0, $deal->payments()->count());
    }

    public function test_on_its_due_date_a_check_asks_for_its_receipt_on_the_dashboard(): void
    {
        $this->fakeSumit();
        $deal = $this->cardDealWithInvoice(900);
        $this->actingAsOwner();
        $method = PaymentMethod::create(['name' => "צ'קים", 'type' => 'check', 'is_active' => true]);
        [$due, $later] = $deal->recordChecks($deal->version, [
            ['number' => '501', 'due_date' => today()->toDateString(), 'amount' => 300, 'bank' => '10', 'branch' => '800', 'account' => '55-123'],
            ['number' => '502', 'due_date' => today()->addMonth()->toDateString(), 'amount' => 300],
        ], $method->id);

        $home = Livewire::test('home')->assertSee('לפירעון — להפיק קבלה')->assertSee("מס' 501")->assertDontSee("מס' 502");

        $home->call('clearCheck', $due->id);

        $this->assertNotNull($due->fresh()->cleared_date);
        $this->assertSame(1, Receipt::where('payment_id', $due->id)->count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/accounting/documents/create/')
            && ($request['Payments'][0]['Type'] ?? null) === 4
            && $request['Payments'][0]['Details_Cheque'] == ['ChequeNumber' => '501', 'DueDate' => today()->toDateString(), 'BankNumber' => 10, 'BranchNumber' => 800, 'AccountNumber' => '55-123']);
        $home->assertDontSee("מס' 501");

        // A check not due yet can't be cleared from the reminder.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('home')->call('clearCheck', $later->id);
    }

    public function test_a_check_reminder_can_wait_until_tomorrow(): void
    {
        $deal = $this->cardDealWithInvoice();
        $this->actingAsOwner();
        $method = PaymentMethod::create(['name' => "צ'קים", 'type' => 'check', 'is_active' => true]);
        $deal->recordChecks($deal->version, [['number' => '77', 'due_date' => today()->subDay()->toDateString(), 'amount' => 100]], $method->id);

        Livewire::test('home')->assertSee("מס' 77")
            ->call('snoozeCheck', $deal->payments()->first()->id)
            ->assertDontSee("מס' 77");

        $this->travel(1)->days();
        Livewire::test('home')->assertSee("מס' 77");
    }

    // ----- fixtures -----

    private function standingOrderDeal(): Deal
    {
        $deal = $this->cardDealWithInvoice();
        $deal->update(['payment_method_id' => PaymentMethod::create(['name' => 'הוראת קבע', 'type' => 'recurring', 'is_active' => true])->id]);

        return $deal->fresh();
    }

    private function actingAsOwner(): void
    {
        $role = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $role->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);
        $this->actingAs(User::create(['name' => 'בעלת העסק', 'email' => 'owner@example.com', 'password' => 'x', 'role_id' => $role->id, 'is_active' => true]));
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the payment link to be refused.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function cardDeal(float $amount = 400): Deal
    {
        StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        $school = School::create(['name' => 'בית ספר לבדיקת קישורי תשלום '.random_int(1, 999999), 'email' => 'school@example.com']);
        $lead = Lead::create([
            'school_id' => $school->id,
            'status_id' => StatusDefinition::where('scope', 'lead')->value('id'),
            'email' => 'lead@example.com', 'phone' => '050-0000000',
        ]);
        $customer = $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
        $program = Program::create(['name' => 'תוכנית '.random_int(1, 999999), 'price' => $amount, 'is_premium' => false, 'is_active' => true]);
        $card = PaymentMethod::create(['name' => 'אשראי '.random_int(1, 999999), 'type' => 'card', 'is_active' => true]);

        return Deal::createForCustomer($customer, $program, null, $amount, null, $card->id);
    }

    private function cardDealWithInvoice(float $amount = 400): Deal
    {
        $deal = $this->cardDeal($amount);
        $template = fn (string $type) => DocumentTemplate::create(['document_type' => $type, 'name' => "{$type} ".random_int(1, 999999), 'content' => 'תוכן', 'is_active' => true]);

        Document::generateFor($deal, $template('order_form'))->markReceived();
        Document::generateFor($deal, $template('contract'))->markSigned();
        $entity = BusinessEntity::create([
            'name' => 'עוסק לבדיקה', 'classification' => 'עוסק פטור', 'company_number' => '123456789',
            'email' => 'b@example.com', 'phone' => '03-0000000', 'is_active' => true,
            'sumit_connection_name' => 'סאמיט', 'sumit_company_id' => '12345678', 'sumit_api_key' => 'x',
        ]);
        Document::generateFor($deal, $template('invoice'), 'digital', $entity->id)->update(['sent_at' => now()]);
        $deal->lockToBusinessEntity($entity);

        return $deal->fresh();
    }
}
