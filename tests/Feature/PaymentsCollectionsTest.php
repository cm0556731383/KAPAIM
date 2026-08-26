<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PaymentsCollectionsTest extends TestCase
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
    }

    // ----- auth / permissions -----

    public function test_collections_page_requires_auth(): void
    {
        $this->get('/collections')->assertRedirect('/login');
    }

    public function test_collections_page_is_blocked_without_the_payments_permission(): void
    {
        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get('/collections')->assertStatus(403);
    }

    public function test_collections_page_renders_for_a_user_with_the_payments_permission(): void
    {
        $response = $this->actingAs($this->owner)->get('/collections');

        $response->assertOk();
    }

    // ----- FR-4.32/FR-4.33: partial vs. full payment -----

    public function test_partial_payment_does_not_close_the_deal(): void
    {
        $deal = $this->createDeal(agreedAmount: 1000);
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);

        $deal->recordPayment($deal->version, 400);

        $deal->refresh();
        $this->assertNotSame(Deal::PAID_STATUS_NAME, $deal->status?->name);
        $this->assertEquals(400, $deal->totalPaid());
        $this->assertEquals(600, $deal->outstandingBalance());
    }

    public function test_full_payment_closes_the_deal(): void
    {
        $deal = $this->createDeal(agreedAmount: 1000);
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);

        $deal->recordPayment($deal->version, 400);
        $deal->recordPayment($deal->version, 600);

        $deal->refresh();
        $this->assertSame(Deal::PAID_STATUS_NAME, $deal->status?->name);
        $this->assertEquals(1000, $deal->totalPaid());
        $this->assertEquals(0, $deal->outstandingBalance());
        $this->assertNotNull($deal->completed_at);
    }

    /**
     * FR-4.39/FR-4.40: a network-institution branch paying an amount that
     * differs from agreed_amount is tracked, never blocked.
     */
    public function test_a_payment_amount_different_from_the_agreed_amount_is_accepted(): void
    {
        $deal = $this->createDeal(agreedAmount: 1000);
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);

        $deal->recordPayment($deal->version, 900);

        $deal->refresh();
        $this->assertEquals(900, $deal->totalPaid());
        $this->assertNotSame(Deal::PAID_STATUS_NAME, $deal->status?->name);
    }

    // ----- FR-4.30/FR-4.31: payment method locked once a payment exists -----

    public function test_payment_method_cannot_change_once_a_payment_exists(): void
    {
        $deal = $this->createDeal();
        $methodA = $this->createPaymentMethod('אמצעי א');
        $methodB = $this->createPaymentMethod('אמצעי ב');

        $deal->updatePaymentMethod($methodA->id);
        $deal->recordPayment($deal->version, 100);

        $this->expectException(RuntimeException::class);
        $deal->updatePaymentMethod($methodB->id);
    }

    public function test_payment_method_can_still_change_freely_before_any_payment(): void
    {
        $deal = $this->createDeal();
        $methodA = $this->createPaymentMethod('אמצעי א');
        $methodB = $this->createPaymentMethod('אמצעי ב');

        $deal->updatePaymentMethod($methodA->id);
        $deal->updatePaymentMethod($methodB->id);

        $this->assertSame($methodB->id, $deal->fresh()->payment_method_id);
    }

    public function test_the_deal_detail_screen_blocks_a_payment_method_change_once_a_payment_exists(): void
    {
        $deal = $this->createDeal();
        $methodA = $this->createPaymentMethod('אמצעי א');
        $methodB = $this->createPaymentMethod('אמצעי ב');
        $deal->updatePaymentMethod($methodA->id);
        $deal->recordPayment($deal->version, 50);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('agreedAmount', (string) $deal->agreed_amount)
            ->set('paymentMethodId', (string) $methodB->id)
            ->call('saveDetails')
            ->assertSet('detailsError', fn ($message) => ! empty($message));

        $this->assertSame($methodA->id, $deal->fresh()->payment_method_id);
    }

    // ----- FR-8.19: optimistic locking on payment recording -----

    public function test_concurrent_payment_recording_is_rejected_with_a_conflict_error(): void
    {
        $deal = $this->createDeal(agreedAmount: 1000);
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);

        $staleVersion = $deal->version;

        // First user records a payment successfully — bumps `version`.
        $deal->recordPayment($staleVersion, 300);

        // Second user, still holding the stale version loaded before the
        // first payment, must be rejected rather than silently applied.
        $this->expectException(RuntimeException::class);
        $deal->recordPayment($staleVersion, 200);
    }

    public function test_a_rejected_concurrent_payment_never_creates_a_payment_row(): void
    {
        $deal = $this->createDeal(agreedAmount: 1000);
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);

        $staleVersion = $deal->version;
        $deal->recordPayment($staleVersion, 300);

        try {
            $deal->recordPayment($staleVersion, 200);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, Payment::where('deal_id', $deal->id)->count());
        $this->assertEquals(300, $deal->fresh()->totalPaid());
    }

    public function test_recording_a_payment_without_a_payment_method_is_blocked(): void
    {
        $deal = $this->createDeal();

        $this->expectException(RuntimeException::class);
        $deal->recordPayment($deal->version, 100);
    }

    // ----- FR-4.5: a receipt requires an invoice document -----

    public function test_receipt_is_blocked_without_an_invoice_document(): void
    {
        $deal = $this->createDeal();

        $this->expectException(RuntimeException::class);
        Receipt::issueFor($deal, null);
    }

    // ----- FR-4.24-FR-4.26: receipt before payment -----

    public function test_receipt_before_payment_does_not_mark_the_deal_paid_or_change_its_balance(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 1000);

        $receipt = Receipt::issueFor($deal, null);

        $this->assertTrue($receipt->issued_before_payment);
        $deal->refresh();
        $this->assertNotSame(Deal::PAID_STATUS_NAME, $deal->status?->name);
        $this->assertEquals(0, $deal->totalPaid());
        $this->assertEquals(1000, $deal->outstandingBalance());
    }

    public function test_normal_receipt_after_a_real_payment_is_not_marked_before_payment(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 1000);
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);
        $payment = $deal->recordPayment($deal->version, 1000);

        $receipt = Receipt::issueFor($deal, $payment);

        $this->assertFalse($receipt->issued_before_payment);
        $this->assertSame($payment->id, $receipt->payment_id);
    }

    // ----- FR-4.28/FR-4.29: check payments -----

    public function test_check_payment_receipt_is_blocked_until_the_check_clears(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 500);
        $checkMethod = $this->createPaymentMethod('צ\'קים לבדיקה', 'check');
        $deal->updatePaymentMethod($checkMethod->id);
        $payment = $deal->recordPayment($deal->version, 500);

        $this->assertSame(Payment::CHECK_RECEIVED, $payment->check_status);
        $this->assertNull($payment->cleared_date);

        $this->expectException(RuntimeException::class);
        Receipt::issueFor($deal, $payment);
    }

    public function test_check_payment_receipt_succeeds_once_cleared(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 500);
        $checkMethod = $this->createPaymentMethod('צ\'קים לבדיקה', 'check');
        $deal->updatePaymentMethod($checkMethod->id);
        $payment = $deal->recordPayment($deal->version, 500);

        $payment->markCleared();

        $receipt = Receipt::issueFor($deal, $payment->fresh());

        $this->assertNotNull($receipt->id);
        $this->assertNotNull($payment->fresh()->cleared_date);
    }

    public function test_at_most_one_receipt_per_check_payment(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 500);
        $checkMethod = $this->createPaymentMethod('צ\'קים לבדיקה', 'check');
        $deal->updatePaymentMethod($checkMethod->id);
        $payment = $deal->recordPayment($deal->version, 500);
        $payment->markCleared();

        Receipt::issueFor($deal, $payment->fresh());

        $this->expectException(RuntimeException::class);
        Receipt::issueFor($deal, $payment->fresh());
    }

    public function test_marking_a_non_check_payment_as_cleared_is_blocked(): void
    {
        $deal = $this->createDeal();
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);
        $payment = $deal->recordPayment($deal->version, 100);

        $this->expectException(RuntimeException::class);
        $payment->markCleared();
    }

    // ----- collections automation (FR-4.35-FR-4.37) -----

    public function test_no_collection_task_is_created_before_one_day_has_passed(): void
    {
        $deal = $this->invoiceSentDeal();

        $this->travel(12)->hours();
        $this->artisan('collections:process');

        $this->assertSame(0, Task::where('task_type', 'collection')->where('deal_id', $deal->id)->count());
    }

    public function test_collection_task_is_created_one_day_after_invoice_sent_with_no_payment(): void
    {
        $deal = $this->invoiceSentDeal();

        $this->travel(25)->hours();
        $this->artisan('collections:process');

        $this->assertSame(1, Task::where('task_type', 'collection')->where('deal_id', $deal->id)->where('status', 'open')->count());
    }

    public function test_collection_task_recurs_weekly_while_the_deal_stays_unpaid(): void
    {
        $deal = $this->invoiceSentDeal();

        $this->travel(25)->hours();
        $this->artisan('collections:process');
        $firstTask = Task::where('task_type', 'collection')->where('deal_id', $deal->id)->where('status', 'open')->firstOrFail();

        $this->travel(8)->days();
        $this->artisan('collections:process');

        $this->assertSame('done', $firstTask->fresh()->status);
        $openTasks = Task::where('task_type', 'collection')->where('deal_id', $deal->id)->where('status', 'open')->get();
        $this->assertCount(1, $openTasks);
        $this->assertNotSame($firstTask->id, $openTasks->first()->id);
    }

    public function test_collection_task_stops_recurring_once_the_deal_is_paid(): void
    {
        $deal = $this->invoiceSentDeal();
        $method = $this->createPaymentMethod();
        $deal->updatePaymentMethod($method->id);

        $this->travel(25)->hours();
        $this->artisan('collections:process');
        $openTask = Task::where('task_type', 'collection')->where('deal_id', $deal->id)->where('status', 'open')->firstOrFail();

        $deal->recordPayment($deal->version, (float) $deal->agreed_amount);

        $this->artisan('collections:process');

        $this->assertSame('done', $openTask->fresh()->status);
        $this->assertSame(0, Task::where('task_type', 'collection')->where('deal_id', $deal->id)->where('status', 'open')->count());
    }

    public function test_collections_job_never_sends_mail_or_notifications(): void
    {
        Mail::fake();
        Notification::fake();

        $this->invoiceSentDeal();
        $this->travel(25)->hours();
        $this->artisan('collections:process');

        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    // ----- no delete route / no deletion convention -----

    public function test_there_is_no_delete_route_for_payments_receipts_or_collections(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = strtolower($route->uri());
            if (str_contains($uri, 'payment') || str_contains($uri, 'receipt') || str_contains($uri, 'collection')) {
                $this->assertNotContains('DELETE', $route->methods(), "Unexpected DELETE route: {$uri}");
            }
        }
    }

    // ----- helpers -----

    private function createDeal(float $agreedAmount = 400): Deal
    {
        $customer = $this->createCustomer();
        $program = Program::create([
            'name' => 'תוכנית בדיקת תשלומים '.random_int(1, 999999),
            'price' => $agreedAmount, 'is_premium' => false, 'is_subscription_type' => false, 'is_active' => true,
        ]);

        return Deal::createForCustomer($customer, $program, null, $agreedAmount);
    }

    /** A deal with a real invoice Document already generated (FR-4.5's prerequisite). */
    private function dealWithInvoice(float $agreedAmount = 400): Deal
    {
        $deal = $this->createDeal($agreedAmount);

        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $this->createTemplate('contract'));
        $contract->markSigned();

        Document::generateFor($deal, $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity()->id);

        return $deal;
    }

    /** A deal already sitting in "נשלחה חשבונית" with no payment — collections automation's target. */
    private function invoiceSentDeal(float $agreedAmount = 1000): Deal
    {
        $deal = $this->createDeal($agreedAmount);
        $invoiceSent = StatusDefinition::firstOrCreate(
            ['scope' => 'deal', 'name' => 'נשלחה חשבונית'],
            ['is_active' => true, 'sort_order' => 2],
        );

        $deal->updateStatusWithLock($deal->version, $invoiceSent->id);

        return $deal->fresh();
    }

    private function createPaymentMethod(string $name = 'אמצעי תשלום לבדיקה', string $type = 'bank_transfer'): PaymentMethod
    {
        return PaymentMethod::create(['name' => $name.' '.random_int(1, 999999), 'type' => $type, 'is_active' => true]);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת תשלומים '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        return $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
    }

    private function createTemplate(string $documentType): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => ucfirst($documentType).' תבנית בדיקת תשלומים '.random_int(1, 999999),
            'content' => 'תוכן בדיקה עבור '.$documentType,
            'is_active' => true,
        ]);
    }

    private function createBusinessEntity(): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק לבדיקת תשלומים '.random_int(1, 999999),
            'classification' => 'עוסק פטור',
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'business'.random_int(1, 999999).'@example.com',
            'phone' => '03-0000000',
            'is_active' => true,
        ]);
    }
}
