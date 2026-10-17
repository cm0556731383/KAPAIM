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
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
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

    // ----- QA pass 3: amount locking, overpayment confirmation, amount caps -----

    public function test_the_agreed_amount_locks_once_the_invoice_is_issued(): void
    {
        $open = $this->createDeal(400);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $open])
            ->set('agreedAmount', '450')
            ->call('saveDetails')
            ->assertHasNoErrors();
        $this->assertEquals(450, (float) $open->fresh()->agreed_amount);

        $invoiced = $this->dealWithInvoice(400);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $invoiced])
            ->set('agreedAmount', '5000')
            ->call('saveDetails')
            ->assertHasErrors('agreedAmount')
            // Other details still save while the amount stays as invoiced.
            ->set('agreedAmount', '400')
            ->set('studentsCount', '25')
            ->call('saveDetails')
            ->assertHasNoErrors();

        $this->assertEquals(400, (float) $invoiced->fresh()->agreed_amount);
        $this->assertSame(25, $invoiced->fresh()->students_count);
    }

    public function test_an_amount_above_the_database_limit_is_a_validation_error_not_a_crash(): void
    {
        $deal = $this->createDeal(400);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('agreedAmount', '99999999999999')
            ->call('saveDetails')
            ->assertHasErrors('agreedAmount');

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $deal->customer])
            ->set('dealItem', 'program:'.$deal->program_id)
            ->set('dealAgreedAmount', '99999999999999')
            ->call('createDeal')
            ->assertHasErrors('dealAgreedAmount')
            ->assertSet('dealError', null);
    }

    public function test_a_payment_above_the_balance_asks_for_confirmation_before_it_is_recorded(): void
    {
        $deal = $this->createDeal(400);
        $method = $this->createPaymentMethod();
        $deal->recordPayment($deal->version, 300, null, $method->id);

        $component = Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->call('selectPaymentMethod', $method->id)
            ->set('paymentAmount', '5000')
            ->call('recordPayment');

        $this->assertSame(1, $deal->payments()->count(), 'Nothing is recorded before the confirmation.');
        $js = collect($component->effects['xjs'] ?? [])->pluck('expression')->implode("\n");
        $this->assertStringContainsString('confirmDialog(', $js);
        $this->assertStringContainsString('$wire.recordPayment(true)', $js);
        $this->assertStringContainsString('100.00', $js, 'The dialog names the remaining balance.');

        $component->call('recordPayment', true);
        $this->assertSame(2, $deal->payments()->count());

        // Within the balance there is no dialog at all.
        $within = $this->createDeal(400);
        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $within])
            ->call('selectPaymentMethod', $method->id)
            ->set('paymentAmount', '400')
            ->call('recordPayment')
            ->assertNoJs();
        $this->assertSame(1, $within->payments()->count());
    }

    public function test_checks_above_the_balance_ask_for_confirmation_too(): void
    {
        $deal = $this->createDeal(400);
        $checks = $this->createPaymentMethod("צ'קים", 'check');
        $rows = [
            ['number' => '1', 'due_date' => now()->toDateString(), 'amount' => '300', 'bank' => '', 'branch' => '', 'account' => ''],
            ['number' => '2', 'due_date' => now()->addMonth()->toDateString(), 'amount' => '300', 'bank' => '', 'branch' => '', 'account' => ''],
        ];

        $component = Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->call('selectPaymentMethod', $checks->id)
            ->set('checkRows', $rows)
            ->call('recordChecks');
        $this->assertSame(0, $deal->payments()->count());

        $component->call('recordChecks', true);
        $this->assertSame(2, $deal->payments()->count());
    }

    // ----- correcting / removing a mistaken payment -----

    public function test_a_payment_without_a_receipt_can_be_edited_and_deleted_and_the_deal_status_follows(): void
    {
        $deal = $this->createDeal(400);
        $method = $this->createPaymentMethod();
        $payment = $deal->recordPayment($deal->version, 400, null, $method->id);
        $this->assertSame(Deal::PAID_STATUS_NAME, $deal->fresh()->status->name);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->call('editPayment', $payment->id)
            ->set('editPaymentAmount', '150')
            ->call('updatePayment')
            ->assertHasNoErrors()
            ->assertSet('paymentError', null);

        $this->assertEquals(150, (float) $payment->fresh()->amount);
        $this->assertSame(Deal::DEFAULT_STATUS_NAME, $deal->fresh()->status->name);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->call('deletePayment', $payment->id)
            ->assertSet('paymentError', null);

        $this->assertSoftDeleted('payments', ['id' => $payment->id]);
        $this->assertEquals(0, $deal->fresh()->totalPaid());
    }

    public function test_a_payment_with_a_receipt_or_from_sumit_cannot_be_edited_or_deleted(): void
    {
        $deal = $this->dealWithInvoice(400);
        $method = $this->createPaymentMethod();
        $withReceipt = $deal->recordPayment($deal->version, 100, null, $method->id);
        Receipt::create(['document_id' => $deal->issuedInvoice()->id, 'payment_id' => $withReceipt->id, 'issued_at' => now(), 'status' => Receipt::STATUS_ISSUED]);
        $fromSumit = $deal->fresh()->recordPayment($deal->fresh()->version, 100, null, $method->id);
        $fromSumit->update(['sumit_payment_id' => 'S-1']);

        foreach ([$withReceipt, $fromSumit] as $payment) {
            $this->assertFalse($payment->fresh()->isEditable());

            try {
                $deal->fresh()->deletePayment($deal->fresh()->version, $payment->fresh());
                $this->fail('A booked payment must not be deletable.');
            } catch (RuntimeException) {
            }

            $this->assertNotSoftDeleted('payments', ['id' => $payment->id]);
        }
    }

    public function test_only_an_unsent_draft_with_nothing_built_on_it_can_be_deleted(): void
    {
        $deal = $this->createDeal();
        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();
        $contract = Document::generateFor($deal, $this->createTemplate('contract'));

        $this->assertFalse($orderForm->fresh()->isDeletableDraft());
        $this->assertTrue($contract->fresh()->isDeletableDraft());

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->call('deleteDocument', $orderForm->id)
            ->assertSet('documentError', 'ניתן למחוק רק טיוטה שטרם נשלחה, נחתמה או שימשה בסיס למסמך הבא.')
            ->call('deleteDocument', $contract->id)
            ->assertSet('documentError', null);

        $this->assertDatabaseHas('documents', ['id' => $orderForm->id]);
        $this->assertDatabaseMissing('documents', ['id' => $contract->id]);
    }

    // ----- deal documents: no expense invoice; a credit note with the owner's own figures -----

    public function test_a_deal_never_offers_or_generates_an_expense_invoice(): void
    {
        $deal = $this->createDeal();
        $this->createTemplate('expense_invoice');

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->assertDontSee('חשבונית הוצאה')
            ->call('generateDocument', 'expense_invoice')
            ->assertSet('documentError', 'חשבונית הוצאה מצורפת להוצאה במסך הוצאות — לא לעסקה.');

        $this->assertSame(0, Document::where('deal_id', $deal->id)->count());
    }

    public function test_a_cancelled_deals_credit_note_is_issued_and_sent_with_the_figures_entered(): void
    {
        $deal = $this->dealWithInvoice(400);
        $deal->issuedInvoice()->businessEntity->update(['classification' => 'עוסק מורשה']);
        $this->summitConnectedFor($deal);
        $this->giveCustomerAnEmailContact($deal);
        $this->createTemplate('credit_note');

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->assertSee('+ הפקת חשבונית זיכוי')
            ->set('creditDescription', 'זיכוי בגין ביטול הסדנה')
            ->set('creditAmount', (string) ($deal->issuedInvoice()->totalAmount() + 1))
            ->call('generateDealCreditNote')
            ->assertHasErrors(['creditAmount'])
            ->set('creditAmount', '250')
            ->call('generateDealCreditNote')
            ->assertHasNoErrors()
            ->assertSet('documentError', null);

        $credit = Document::where('deal_id', $deal->id)->where('document_type', 'credit_note')->firstOrFail();
        $this->assertSame('זיכוי בגין ביטול הסדנה', $credit->lines()->first()->description);
        $this->assertEquals(250, $credit->totalAmount());
        $this->assertNotNull($credit->sent_at); // sent in the same click
        $this->assertSame(1, $credit->recipients()->count());
    }

    public function test_a_credit_note_sumit_rejects_is_deleted_with_a_message(): void
    {
        $deal = $this->dealWithInvoice(400);
        $deal->issuedInvoice()->businessEntity->update(['classification' => 'עוסק מורשה']);
        // Registered before summitConnectedFor()'s success stub, so it's the one that answers.
        \Illuminate\Support\Facades\Http::fake(['api.sumit.co.il/*' => \Illuminate\Support\Facades\Http::response(['Status' => 1, 'UserErrorMessage' => 'שגיאת בדיקה'], 200)]);
        $this->summitConnectedFor($deal);
        $this->giveCustomerAnEmailContact($deal);
        $this->createTemplate('credit_note');

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('creditAmount', '100')
            ->call('generateDealCreditNote')
            ->assertSet('documentError', fn ($message) => str_starts_with((string) $message, 'ההפקה ב-SUMIT נכשלה, ולכן טיוטת הזיכוי נמחקה'));

        $this->assertSame(0, Document::where('deal_id', $deal->id)->where('document_type', 'credit_note')->count());
    }

    public function test_an_exempt_dealers_credit_is_capped_at_what_was_paid_and_blocked_when_nothing_was(): void
    {
        $deal = $this->dealWithInvoice(400); // the test entity is an עוסק פטור
        $this->summitConnectedFor($deal);
        $this->giveCustomerAnEmailContact($deal);
        $this->createTemplate('credit_note');

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('creditAmount', '100')
            ->call('generateDealCreditNote')
            ->assertSet('documentError', 'לעסקה של עוסק פטור הופק רק חשבון עסקה ועדיין לא התקבל עליה תשלום — אין מה לזכות. די לבטל את העסקה; חשבון עסקה אינו מסמך מס.');

        $deal->update(['payment_method_id' => $this->createPaymentMethod()->id]);
        $deal->fresh()->recordPayment($deal->fresh()->version, 150);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->set('creditAmount', '200')
            ->call('generateDealCreditNote')
            ->assertSet('documentError', 'בעוסק פטור הזיכוי הוא קבלת זיכוי — החזר של כסף שהתקבל — ולכן הוא יכול להיות עד ₪150.00, הסכום ששולם בעסקה.')
            ->set('creditAmount', '150')
            ->call('generateDealCreditNote')
            ->assertSet('documentError', null);

        $this->assertEquals(150, Document::where('deal_id', $deal->id)->where('document_type', 'credit_note')->firstOrFail()->totalAmount());
    }

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

    public function test_collections_page_lists_an_unpaid_deal_with_a_sent_invoice_right_away_whatever_its_status(): void
    {
        $deal = $this->dealWithInvoice(1200);
        $this->assertSame('פתוחה', $deal->fresh()->status->name);

        Livewire::actingAs($this->owner)->test('collections')
            ->assertSee($deal->program_name_snapshot)
            ->assertSee('1,200')
            ->assertSet('totalOutstanding', 1200.0);
    }

    public function test_collections_page_lists_an_unpaid_deal_with_a_pending_payment_link(): void
    {
        $deal = $this->createDeal(800);
        \App\Models\PaymentLink::create([
            'deal_id' => $deal->id, 'token' => str_repeat('a', 40), 'amount' => 800, 'purpose' => 'payment',
            'url' => 'https://pay.example.com/x', 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);

        Livewire::actingAs($this->owner)->test('collections')
            ->assertSee($deal->program_name_snapshot)
            ->assertSee('קישור תשלום');
    }

    public function test_collections_page_leaves_out_paid_deals_and_deals_never_sent_an_invoice_or_link(): void
    {
        $notSent = $this->createDeal(500);
        $paid = $this->dealWithInvoice(300);
        $paid->recordPayment($paid->version, 300, null, $this->createPaymentMethod()->id);

        Livewire::actingAs($this->owner)->test('collections')
            ->assertDontSee($notSent->program_name_snapshot)
            ->assertDontSee($paid->program_name_snapshot)
            ->assertSee('אין כרגע עסקאות הממתינות לתשלום');
    }

    public function test_sending_an_invoice_moves_an_open_deal_to_invoice_sent(): void
    {
        $deal = $this->dealWithInvoice(600);
        $this->summitConnectedFor($deal);
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]); // generated, not yet sent

        $invoice->sendTo([['name' => 'אחראית חשבונות', 'email' => 'billing@example.com']], 'digital',
            app(\App\Services\ActivityLogger::class), app(\App\Services\Integrations\ExternalOperationRunner::class),
            app(\App\Services\Integrations\SummitClient::class), app(\App\Services\Integrations\SmoveClient::class));

        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertSame('נשלחה חשבונית', $deal->fresh()->status->name);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'deal.status_changed', 'deal_id' => $deal->id]);
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

    /** Owner's call, 2026-10-13: the deal's method is only its plan — a deal can be paid in several ways. */
    public function test_a_deal_can_be_paid_in_several_ways_and_its_planned_method_stays_editable(): void
    {
        $deal = $this->createDeal(agreedAmount: 1000);
        $methodA = $this->createPaymentMethod('אמצעי א');
        $methodB = $this->createPaymentMethod('אמצעי ב');

        $deal->updatePaymentMethod($methodA->id);
        $first = $deal->recordPayment($deal->version, 100);
        $second = $deal->fresh()->recordPayment($deal->fresh()->version, 200, null, $methodB->id);
        $deal->fresh()->updatePaymentMethod($methodB->id);

        $this->assertSame($methodA->id, $first->payment_method_id);
        $this->assertSame($methodB->id, $second->payment_method_id);
        $this->assertSame($methodB->id, $deal->fresh()->payment_method_id);
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

    public function test_the_deal_detail_screen_changes_the_planned_method_after_a_payment(): void
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
            ->assertSet('detailsError', null);

        $this->assertSame($methodB->id, $deal->fresh()->payment_method_id);
        $this->assertSame($methodA->id, $deal->payments()->first()->payment_method_id);
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
        Receipt::issueFor($deal, null, app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    // ----- FR-4.24-FR-4.26: receipt before payment -----

    public function test_receipt_before_payment_does_not_mark_the_deal_paid_or_change_its_balance(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 1000);

        $receipt = Receipt::issueFor($deal, null, app(ExternalOperationRunner::class), app(SummitClient::class));

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

        $receipt = Receipt::issueFor($deal, $payment, app(ExternalOperationRunner::class), app(SummitClient::class));

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
        Receipt::issueFor($deal, $payment, app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    public function test_check_payment_receipt_succeeds_once_cleared(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 500);
        $checkMethod = $this->createPaymentMethod('צ\'קים לבדיקה', 'check');
        $deal->updatePaymentMethod($checkMethod->id);
        $payment = $deal->recordPayment($deal->version, 500);

        $payment->markCleared();

        $receipt = Receipt::issueFor($deal, $payment->fresh(), app(ExternalOperationRunner::class), app(SummitClient::class));

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

        Receipt::issueFor($deal, $payment->fresh(), app(ExternalOperationRunner::class), app(SummitClient::class));

        $this->expectException(RuntimeException::class);
        Receipt::issueFor($deal, $payment->fresh(), app(ExternalOperationRunner::class), app(SummitClient::class));
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

        $invoice = Document::generateFor($deal, $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity()->id);
        $invoice->addLine('שורת בדיקה', (float) $deal->agreed_amount);
        $invoice->update(['sent_at' => now()]); // issued — see Deal::issuedInvoice()

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

    /** SUMIT on (faked), and the deal's invoice entity linked to it — so a credit note really gets issued. */
    private function summitConnectedFor(Deal $deal): void
    {
        \App\Models\ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        \Illuminate\Support\Facades\Http::fake(['api.sumit.co.il/*' => \Illuminate\Support\Facades\Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555]], 200)]);
        $deal->issuedInvoice()->businessEntity->update(['sumit_connection_name' => 'סאמיט לבדיקה', 'sumit_company_id' => '12345678', 'sumit_api_key' => 'x']);
    }

    private function giveCustomerAnEmailContact(Deal $deal): void
    {
        \App\Models\Contact::create([
            'school_id' => $deal->customer->school_id, 'customer_id' => $deal->customer_id,
            'name' => 'אחראית חשבונות', 'email' => 'billing'.random_int(1, 999999).'@example.com', 'is_primary' => true,
        ]);
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

        $customer = $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
        // These fixtures add their own contacts — drop the primary one conversion opens from the lead's details.
        $customer->contacts()->forceDelete();

        return $customer;
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
