<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Document;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 2026-11-25: a payment and its receipt made outside the system (Receipt::recordExternalPayment()/recordExternalFor()). */
class ExternalReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Deal $deal;

    private PaymentMethod $transfer;

    private PaymentMethod $check;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $role->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);
        $this->owner = User::create(['name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password', 'role_id' => $role->id, 'is_active' => true]);

        $school = School::create(['name' => 'אורות', 'classes_per_grade' => 2]);
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        $lead = Lead::create(['school_id' => $school->id, 'status_id' => $status->id, 'assigned_user_id' => $this->owner->id, 'phone' => '0501234567', 'email' => 'a@orot.test']);
        $this->actingAs($this->owner);
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $this->deal = Deal::createForCustomer($customer, Program::create(['name' => 'מוגנות', 'price' => 1000, 'is_active' => true]), null);
        $this->transfer = PaymentMethod::create(['name' => 'העברה בנקאית לבדיקה', 'type' => 'bank_transfer', 'is_active' => true]);
        $this->check = PaymentMethod::create(['name' => "צ'ק לבדיקה", 'type' => 'check', 'is_active' => true]);
    }

    private function recordExternalInvoice(): void
    {
        Document::recordExternal($this->deal, 'invoice', 'sent', now()->subDays(20), 'INV-1', app(ActivityLogger::class));
        $this->deal->refresh();
    }

    public function test_a_payment_and_its_receipt_made_outside_are_recorded_from_the_deal_card_and_the_deal_is_paid(): void
    {
        $this->recordExternalInvoice();

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal])
            ->assertSee('+ רישום תשלום וקבלה שנעשו מחוץ למערכת')
            ->set('extPayMethodId', (string) $this->transfer->id)
            ->set('extPayAmount', '1000')
            ->set('extPayDate', now()->subDays(10)->toDateString())
            ->set('extReceiptNumber', 'R-77')
            ->call('recordExternalPayment')
            ->assertHasNoErrors()
            ->assertSet('paymentError', null)
            ->assertSee('קבלה מחוץ למערכת')
            ->assertSee('R-77');

        $receipt = Receipt::sole();
        $this->assertTrue($receipt->is_external);
        $this->assertSame(now()->subDays(10)->toDateString(), $receipt->issued_at->toDateString());
        $this->assertSame(1000.0, (float) $receipt->payment->amount);
        $this->assertSame(Deal::PAID_STATUS_NAME, $this->deal->fresh()->status->name);
        $this->assertDatabaseMissing('external_operations', ['operation' => 'issue_receipt']);
    }

    public function test_a_check_receipted_outside_counts_as_cleared_on_the_receipt_date(): void
    {
        $this->recordExternalInvoice();

        Receipt::recordExternalPayment($this->deal, $this->deal->version, $this->check->id, 1000, now()->subDays(30), now()->subDays(5), null, '123', app(ActivityLogger::class));

        $payment = $this->deal->payments()->sole();
        $this->assertSame('123', $payment->check_number);
        $this->assertSame(now()->subDays(5)->toDateString(), $payment->cleared_date->toDateString());
        $this->assertTrue($payment->isSettled());
        $this->assertSame(Deal::PAID_STATUS_NAME, $this->deal->fresh()->status->name);
    }

    public function test_without_an_invoice_nothing_is_recorded(): void
    {
        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal])
            ->set('extPayMethodId', (string) $this->transfer->id)
            ->set('extPayAmount', '500')
            ->set('extPayDate', now()->toDateString())
            ->call('recordExternalPayment')
            ->assertSet('paymentError', fn ($e) => str_contains((string) $e, 'אין חשבונית'));

        $this->assertSame(0, $this->deal->payments()->count());
        $this->assertSame(0, Receipt::count());
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->recordExternalInvoice();

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal])
            ->set('extPayMethodId', (string) $this->transfer->id)
            ->set('extPayAmount', '500')
            ->set('extPayDate', now()->addDay()->toDateString())
            ->call('recordExternalPayment')
            ->assertHasErrors('extPayDate');

        $this->assertSame(0, $this->deal->payments()->count());
    }

    public function test_an_existing_payment_gets_a_receipt_made_outside_once(): void
    {
        $this->recordExternalInvoice();
        $payment = $this->deal->recordPayment($this->deal->version, 400, now()->subDay()->toDateTimeString(), $this->transfer->id);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal->fresh()])
            ->assertSee('קבלה מחוץ למערכת')
            ->set('extReceiptDate', now()->toDateString())
            ->set('extReceiptNumber', 'R-5')
            ->call('recordExternalReceiptFor', $payment->id)
            ->assertHasNoErrors()
            ->assertSet('paymentError', null)
            ->set('extReceiptDate', now()->toDateString())
            ->call('recordExternalReceiptFor', $payment->id)
            ->assertSet('paymentError', 'כבר הופקה קבלה עבור תשלום זה.');

        $this->assertTrue($payment->fresh()->receipt->is_external);
        $this->assertSame(1, Receipt::count());
    }

    public function test_a_record_made_by_mistake_is_deleted_with_its_payment_and_the_deal_reopens(): void
    {
        $this->recordExternalInvoice();
        $receipt = Receipt::recordExternalPayment($this->deal, $this->deal->version, $this->transfer->id, 1000, now(), now(), null, null, app(ActivityLogger::class));

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal->fresh()])
            ->call('deleteExternalReceipt', $receipt->id)
            ->assertSet('paymentError', null);

        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, $this->deal->payments()->count());
        $this->assertNotSame(Deal::PAID_STATUS_NAME, $this->deal->fresh()->status->name);
    }

    public function test_a_receipt_issued_in_the_system_cannot_be_deleted_as_external(): void
    {
        $this->recordExternalInvoice();
        $payment = $this->deal->recordPayment($this->deal->version, 400, null, $this->transfer->id);
        $receipt = Receipt::create(['payment_id' => $payment->id, 'document_id' => $this->deal->issuedInvoice()->id, 'status' => Receipt::STATUS_ISSUED, 'issued_at' => now()]);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal->fresh()])
            ->call('deleteExternalReceipt', $receipt->id)
            ->assertSet('paymentError', 'ניתן למחוק רק קבלה שנרשמה מחוץ למערכת.');

        $this->assertSame(1, Receipt::count());
    }
}
