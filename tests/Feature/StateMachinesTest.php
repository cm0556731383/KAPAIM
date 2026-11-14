<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The implicit state machines tightened on 2026-11-12: deal statuses that
 * follow payments/invoices can't be claimed by hand, payment and document
 * status_id follow their real state, a customer's status can be changed,
 * a converted lead shows as converted, and tasks go open → done one way.
 */
class StateMachinesTest extends TestCase
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

        foreach ([Deal::DEFAULT_STATUS_NAME, Deal::INVOICE_SENT_STATUS_NAME, Deal::PAID_STATUS_NAME, Deal::CANCELLED_STATUS_NAME] as $i => $name) {
            StatusDefinition::firstOrCreate(['scope' => 'deal', 'name' => $name], ['is_active' => true, 'sort_order' => $i + 1]);
        }
    }

    // ----- deal -----

    public function test_an_unpaid_deal_cannot_be_marked_paid_by_hand(): void
    {
        $deal = $this->createDeal(1000);
        $paid = $this->dealStatus(Deal::PAID_STATUS_NAME);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('selectedStatusId', (string) $paid->id)
            ->call('updateStatus');

        $this->assertSame(Deal::DEFAULT_STATUS_NAME, $deal->fresh()->status->name);
        $this->assertNull($deal->fresh()->completed_at);
    }

    public function test_invoice_sent_cannot_be_claimed_without_an_invoice(): void
    {
        $deal = $this->createDeal(1000);

        $this->assertNotNull($deal->manualStatusChangeError($this->dealStatus(Deal::INVOICE_SENT_STATUS_NAME)));
    }

    public function test_the_dropdown_offers_only_statuses_a_user_may_set(): void
    {
        $deal = $this->createDeal(1000);

        $names = Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->instance()->selectableDealStatuses->pluck('name')->all();

        $this->assertContains(Deal::DEFAULT_STATUS_NAME, $names);
        $this->assertContains(Deal::CANCELLED_STATUS_NAME, $names);
        $this->assertNotContains(Deal::PAID_STATUS_NAME, $names);
        $this->assertNotContains(Deal::INVOICE_SENT_STATUS_NAME, $names);
    }

    public function test_a_fully_paid_deal_cannot_be_reopened_by_hand_but_can_be_cancelled(): void
    {
        $deal = $this->createDeal(400);
        $deal->recordPayment((int) $deal->version, 400, null, $this->cashMethod()->id);
        $deal->refresh();

        $this->assertSame(Deal::PAID_STATUS_NAME, $deal->status->name);
        $this->assertNotNull($deal->manualStatusChangeError($this->dealStatus(Deal::DEFAULT_STATUS_NAME)));
        $this->assertNull($deal->manualStatusChangeError($this->dealStatus(Deal::CANCELLED_STATUS_NAME)));
    }

    public function test_a_cancelled_deal_cannot_be_reopened(): void
    {
        $deal = $this->createDeal(1000);
        $deal->updateStatusWithLock((int) $deal->version, $this->dealStatus(Deal::CANCELLED_STATUS_NAME)->id);

        $this->assertNotNull($deal->fresh()->manualStatusChangeError($this->dealStatus(Deal::DEFAULT_STATUS_NAME)));
    }

    public function test_a_subscription_deal_is_not_cancelled_from_the_dropdown(): void
    {
        $customer = $this->createCustomer();
        $bundle = Bundle::create(['name' => 'מנוי בדיקה '.random_int(1, 999999), 'price' => 4200, 'is_subscription_type' => true, 'is_active' => true]);
        $deal = Deal::createForCustomer($customer, null, $bundle, 4200);

        $this->assertNotNull($deal->fresh()->manualStatusChangeError($this->dealStatus(Deal::CANCELLED_STATUS_NAME)));
    }

    // ----- payment -----

    public function test_a_cash_payment_is_settled_and_a_check_waits(): void
    {
        $deal = $this->createDeal(1000);
        $cash = $deal->recordPayment((int) $deal->version, 300, null, $this->cashMethod()->id);
        $deal->refresh();
        $check = $deal->recordPayment((int) $deal->version, 300, null, PaymentMethod::create(['name' => 'צ\'ק', 'type' => 'check', 'is_active' => true])->id, '1001', now()->toDateString());

        $this->assertSame(Payment::STATUS_SETTLED_NAME, $cash->fresh()->status->name);
        $this->assertSame(Payment::STATUS_PENDING_NAME, $check->fresh()->status->name);

        // Cleared but not yet receipted: still on its way (Payment::isSettled()).
        $check->fresh()->markCleared();
        $this->assertSame(Payment::STATUS_PENDING_NAME, $check->fresh()->status->name);
    }

    // ----- document -----

    public function test_a_documents_status_follows_its_dates(): void
    {
        $deal = $this->createDeal(1000);
        $document = Document::create(['deal_id' => $deal->id, 'document_type' => 'contract', 'format' => 'digital']);

        $this->assertSame(Document::DEFAULT_STATUS_NAME, $document->fresh()->status->name);

        $document->update(['sent_at' => now()]);
        $this->assertSame(Document::SENT_STATUS_NAME, $document->fresh()->status->name);

        $document->markSigned();
        $this->assertSame(Document::CONFIRMED_STATUS_NAME, $document->fresh()->status->name);
    }

    // ----- customer -----

    public function test_a_customers_status_can_be_changed_and_is_logged(): void
    {
        $customer = $this->createCustomer();
        $inactive = StatusDefinition::create(['scope' => 'customer', 'name' => 'לא פעילה', 'is_active' => true, 'sort_order' => 2]);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('selectedCustomerStatusId', (string) $inactive->id)
            ->assertDispatched('notify', type: 'success');

        $this->assertSame($inactive->id, $customer->fresh()->status_id);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'customer.status_changed', 'customer_id' => $customer->id]);
    }

    // ----- lead -----

    public function test_a_converted_lead_shows_as_converted_and_its_status_is_frozen(): void
    {
        $customer = $this->createCustomer();
        $lead = $customer->lead;
        $closed = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::CLOSED_NO_SALE_STATUS_NAME], ['is_active' => true, 'sort_order' => 4]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->assertSee(Lead::CONVERTED_LABEL)
            ->set('selectedStatusId', (string) $closed->id)
            ->call('updateStatus');

        $this->assertNotSame($closed->id, $lead->fresh()->status_id);
    }

    // ----- task -----

    public function test_a_task_is_marked_done_once(): void
    {
        $task = Task::create(['user_id' => $this->owner->id, 'title' => 'בדיקה', 'status' => Task::STATUS_OPEN, 'due_at' => now()]);

        $task->markDone();
        $firstCompletedAt = $task->fresh()->completed_at;
        $this->travel(1)->hours();
        $task->fresh()->markDone();

        $this->assertTrue($task->fresh()->isDone());
        $this->assertEquals($firstCompletedAt, $task->fresh()->completed_at);
    }

    // ----- fixtures -----

    private function dealStatus(string $name): StatusDefinition
    {
        return StatusDefinition::where('scope', 'deal')->where('name', $name)->firstOrFail();
    }

    private function cashMethod(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(['name' => 'מזומן לבדיקה'], ['type' => 'cash', 'is_active' => true]);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        $school = School::create(['name' => 'בית ספר לבדיקת מצבים '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }

    private function createDeal(float $agreedAmount): Deal
    {
        $program = Program::create(['name' => 'תוכנית בדיקה '.random_int(1, 999999), 'price' => $agreedAmount, 'is_premium' => false, 'is_active' => true]);

        return Deal::createForCustomer($this->createCustomer(), $program, null, $agreedAmount);
    }
}
