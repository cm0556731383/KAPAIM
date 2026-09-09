<?php

namespace Tests\Unit;

use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\MaterialDelivery;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DashboardSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Tests\TestCase;

/**
 * Build-plan 15 — App\Services\DashboardSummary unit coverage (US-019,
 * FR-2.16/FR-7.3). Mirrors RevenueReportTest's style: each test builds its
 * own small fixture rather than relying on the demo seeders.
 */
class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private DashboardSummary $dashboard;

    private User $owner;

    private User $repA;

    private User $repB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard = app(DashboardSummary::class);

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);

        $salesRep = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $salesRep->permissions()->create(['resource' => 'leads', 'action' => 'view', 'is_allowed' => true]);

        $this->repA = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $salesRep->id, 'is_active' => true,
        ]);

        $this->repB = User::create([
            'name' => 'מיכל כהן', 'email' => 'michal@kapaim.test', 'password' => 'password',
            'role_id' => $salesRep->id, 'is_active' => true,
        ]);
    }

    // ----- newLeadsCount -----

    public function test_new_leads_count_only_counts_leads_with_the_new_status(): void
    {
        $this->createLead($this->owner, schoolName: 'ליד חדש א');
        $this->createLead($this->owner, schoolName: 'ליד חדש ב');
        $this->advanceLeadStatus($this->createLead($this->owner, schoolName: 'ליד בתהליך'), 'בתהליך מכירה');

        $this->assertSame(2, $this->dashboard->newLeadsCount($this->owner));
    }

    public function test_new_leads_count_is_scoped_to_the_reps_own_leads(): void
    {
        $this->createLead($this->repA, schoolName: 'ליד של שרית');
        $this->createLead($this->repB, schoolName: 'ליד של מיכל');

        $this->assertSame(1, $this->dashboard->newLeadsCount($this->repA));
        $this->assertSame(1, $this->dashboard->newLeadsCount($this->repB));
        $this->assertSame(2, $this->dashboard->newLeadsCount($this->owner));
    }

    // ----- awaitingCallbackCount -----

    public function test_awaiting_callback_count_matches_the_exact_sub_status(): void
    {
        $lead = $this->createLead($this->owner, schoolName: 'ממתינה לשיחה');
        $lead->update(['sub_status' => 'ממתינה לשיחה חוזרת']);

        $this->createLead($this->owner, schoolName: 'ליד ללא תת-סטטוס');

        $this->assertSame(1, $this->dashboard->awaitingCallbackCount($this->owner));
    }

    // ----- followUpsDueTodayCount: FOLLOW_UP.next_at, never a TASK row -----

    public function test_follow_ups_due_today_counts_only_next_at_today(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');

        $leadToday = $this->createLead($this->owner, schoolName: 'ליד להיום');
        FollowUp::create(['lead_id' => $leadToday->id, 'user_id' => $this->owner->id, 'occurred_at' => now(), 'next_at' => '2026-06-15 14:00:00']);

        $leadTomorrow = $this->createLead($this->owner, schoolName: 'ליד למחר');
        FollowUp::create(['lead_id' => $leadTomorrow->id, 'user_id' => $this->owner->id, 'occurred_at' => now(), 'next_at' => '2026-06-16 09:00:00']);

        $this->assertSame(1, $this->dashboard->followUpsDueTodayCount($this->owner));
    }

    /** A personal TASK reminder due today must never be conflated with a FollowUp (see class docblock). */
    public function test_follow_ups_due_today_never_counts_a_personal_task_reminder(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');

        $lead = $this->createLead($this->owner, schoolName: 'ליד עם תזכורת');
        Task::create([
            'user_id' => $this->owner->id, 'lead_id' => $lead->id, 'task_type' => 'reminder', 'status' => 'open',
            'title' => 'תזכורת', 'due_at' => '2026-06-15 12:00:00',
        ]);

        $this->assertSame(0, $this->dashboard->followUpsDueTodayCount($this->owner));
    }

    public function test_follow_ups_due_today_is_scoped_to_the_reps_own_leads(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');

        $leadA = $this->createLead($this->repA, schoolName: 'ליד שרית עם פולואפ');
        FollowUp::create(['lead_id' => $leadA->id, 'user_id' => $this->repA->id, 'occurred_at' => now(), 'next_at' => '2026-06-15 10:00:00']);

        $leadB = $this->createLead($this->repB, schoolName: 'ליד מיכל עם פולואפ');
        FollowUp::create(['lead_id' => $leadB->id, 'user_id' => $this->repB->id, 'occurred_at' => now(), 'next_at' => '2026-06-15 10:00:00']);

        $this->assertSame(1, $this->dashboard->followUpsDueTodayCount($this->repA));
        $this->assertSame(2, $this->dashboard->followUpsDueTodayCount($this->owner));
    }

    // ----- openCollectionsTotal / checksToDeposit: payments.manage gated -----

    public function test_open_collections_total_sums_outstanding_balance_across_deals_with_open_collection_tasks(): void
    {
        $dealA = $this->invoiceSentDealWithOpenCollectionTask(agreedAmount: 1000);
        $dealB = $this->invoiceSentDealWithOpenCollectionTask(agreedAmount: 500);

        $result = $this->dashboard->openCollectionsTotal($this->owner);

        $this->assertEqualsWithDelta(1500.0, $result['amount'], 0.01);
        $this->assertSame(2, $result['customerCount']);
    }

    public function test_open_collections_total_is_zero_for_a_leads_view_only_user(): void
    {
        $this->invoiceSentDealWithOpenCollectionTask(agreedAmount: 1000);

        $result = $this->dashboard->openCollectionsTotal($this->repA);

        $this->assertSame(0.0, $result['amount']);
        $this->assertSame(0, $result['customerCount']);
    }

    public function test_checks_to_deposit_counts_only_uncleared_check_payments(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 1000);
        $checkMethod = PaymentMethod::create(['name' => 'צ\'קים לבדיקה '.random_int(1, 999999), 'type' => 'check', 'is_active' => true]);
        $deal->updatePaymentMethod($checkMethod->id);
        $payment = $deal->recordPayment($deal->version, 1000);

        $result = $this->dashboard->checksToDeposit($this->owner);
        $this->assertSame(1, $result['count']);
        $this->assertEqualsWithDelta(1000.0, $result['amount'], 0.01);

        $payment->markCleared();

        $result = $this->dashboard->checksToDeposit($this->owner);
        $this->assertSame(0, $result['count']);
    }

    public function test_checks_to_deposit_is_zero_for_a_leads_view_only_user(): void
    {
        $deal = $this->dealWithInvoice(agreedAmount: 1000);
        $checkMethod = PaymentMethod::create(['name' => 'צ\'קים לבדיקה '.random_int(1, 999999), 'type' => 'check', 'is_active' => true]);
        $deal->updatePaymentMethod($checkMethod->id);
        $deal->recordPayment($deal->version, 1000);

        $result = $this->dashboard->checksToDeposit($this->repA);
        $this->assertSame(0, $result['count']);
        $this->assertSame(0.0, $result['amount']);
    }

    // ----- attentionItems: completed items never appear -----

    public function test_a_completed_personal_task_never_appears(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');
        $lead = $this->createLead($this->owner, schoolName: 'ליד עם תזכורת שהושלמה');
        $task = Task::create([
            'user_id' => $this->owner->id, 'lead_id' => $lead->id, 'task_type' => 'reminder', 'status' => 'open',
            'title' => 'תזכורת שתושלם', 'due_at' => '2026-06-15 12:00:00',
        ]);
        $task->update(['status' => 'done', 'completed_at' => now()]);

        $items = $this->dashboard->attentionItems($this->owner);

        $this->assertFalse($items->contains(fn ($item) => str_contains($item['label'], 'תזכורת שתושלם')));
    }

    public function test_a_fully_paid_deal_with_a_receipt_already_issued_never_appears_as_awaiting_receipt(): void
    {
        $deal = $this->fullyPaidDealWithInvoice(agreedAmount: 500, schoolName: 'בית ספר עם קבלה');
        $payment = $deal->payments()->first();
        Receipt::issueFor($deal, $payment, app(ExternalOperationRunner::class), app(SummitClient::class));

        $items = $this->dashboard->attentionItems($this->owner);

        $this->assertFalse($items->contains(fn ($item) => str_contains($item['label'], 'ממתינה להפקת קבלה') && str_contains($item['label'], 'בית ספר עם קבלה')));
    }

    public function test_a_fully_paid_deal_without_a_receipt_appears_as_awaiting_receipt(): void
    {
        $this->fullyPaidDealWithInvoice(agreedAmount: 500, schoolName: 'בית ספר ללא קבלה');

        $items = $this->dashboard->attentionItems($this->owner);

        $match = $items->first(fn ($item) => str_contains($item['label'], 'ממתינה להפקת קבלה') && str_contains($item['label'], 'בית ספר ללא קבלה'));
        $this->assertNotNull($match);
        $this->assertSame('לביצוע', $match['badge']);
    }

    public function test_a_fully_paid_deal_with_materials_already_sent_never_appears(): void
    {
        $deal = $this->fullyPaidDealWithInvoice(agreedAmount: 300, schoolName: 'בית ספר עם חומרים');
        $sentStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'material_delivery', 'name' => MaterialDelivery::STATUS_NOT_OPENED],
            ['is_active' => true, 'sort_order' => 1],
        );
        MaterialDelivery::create([
            'customer_id' => $deal->customer_id, 'program_id' => $deal->program_id,
            'status_id' => $sentStatus->id, 'sent_at' => now(),
        ]);

        $items = $this->dashboard->attentionItems($this->owner);

        $this->assertFalse($items->contains(fn ($item) => str_contains($item['label'], 'טרם נשלחו חומרים') && str_contains($item['label'], 'בית ספר עם חומרים')));
    }

    public function test_a_fully_paid_deal_with_no_material_delivery_at_all_appears_as_materials_not_sent(): void
    {
        $this->fullyPaidDealWithInvoice(agreedAmount: 300, schoolName: 'בית ספר בלי חומרים בכלל');

        $items = $this->dashboard->attentionItems($this->owner);

        $match = $items->first(fn ($item) => str_contains($item['label'], 'טרם נשלחו חומרים') && str_contains($item['label'], 'בית ספר בלי חומרים בכלל'));
        $this->assertNotNull($match);
        $this->assertSame('לשליחה', $match['badge']);
    }

    public function test_an_overdue_collection_task_appears_with_the_overdue_badge(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');
        $deal = $this->createDeal(agreedAmount: 800);
        Task::create([
            'user_id' => $this->owner->id, 'deal_id' => $deal->id, 'customer_id' => $deal->customer_id,
            'task_type' => 'collection', 'status' => 'open', 'title' => 'משימת גבייה',
            'due_at' => '2026-06-10 00:00:00',
        ]);

        $items = $this->dashboard->attentionItems($this->owner);

        $match = $items->first(fn ($item) => str_contains($item['subtitle'], $deal->program_name_snapshot) && $item['badge'] === 'באיחור');
        $this->assertNotNull($match);
    }

    /**
     * FR-7.3, the critical case: a leads.view-only user's attentionItems()
     * only ever contains their own lead-related rows, never a financial/
     * materials one, even when such items genuinely exist in the system.
     */
    public function test_attention_items_hides_every_financial_and_materials_row_from_a_leads_view_only_user(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');

        // Financial/materials items that DO exist in the system...
        $this->fullyPaidDealWithInvoice(agreedAmount: 500, schoolName: 'לקוחה פיננסית');
        $collectionDeal = $this->createDeal(agreedAmount: 400);
        Task::create([
            'user_id' => $this->owner->id, 'deal_id' => $collectionDeal->id, 'customer_id' => $collectionDeal->customer_id,
            'task_type' => 'collection', 'status' => 'open', 'title' => 'משימת גבייה', 'due_at' => '2026-06-10 00:00:00',
        ]);

        // ...plus repA's own lead-related item, which SHOULD show up for them.
        $leadA = $this->createLead($this->repA, schoolName: 'ליד של שרית לטיפול');
        FollowUp::create(['lead_id' => $leadA->id, 'user_id' => $this->repA->id, 'occurred_at' => now(), 'next_at' => '2026-06-15 10:00:00']);

        $items = $this->dashboard->attentionItems($this->repA);

        $this->assertTrue($items->contains(fn ($item) => str_contains($item['label'], 'ליד של שרית לטיפול')));
        $this->assertFalse($items->contains(fn ($item) => str_contains($item['label'], 'לקוחה פיננסית')));
        $this->assertFalse($items->contains(fn ($item) => str_contains($item['subtitle'], "עסקה #{$collectionDeal->id}")));
    }

    // ----- latestProgramSummary -----

    public function test_latest_program_summary_splits_purchased_from_merely_interested(): void
    {
        $this->createProgram('תוכנית ישנה');
        $latestProgram = $this->createProgram('התוכנית האחרונה');

        $buyerLead = $this->createLead($this->owner, schoolName: 'בית ספר שרכש');
        $buyerCustomer = $buyerLead->convertToCustomer(app(ActivityLogger::class));
        Deal::createForCustomer($buyerCustomer, $latestProgram, null, (float) $latestProgram->price);

        $interestedLead = $this->createLead($this->owner, schoolName: 'בית ספר שהתעניין');
        $interestedLead->interestedPrograms()->attach($latestProgram->id);

        $summary = $this->dashboard->latestProgramSummary($this->owner);

        $this->assertSame($latestProgram->id, $summary['program']->id);
        $this->assertTrue($summary['purchased']->contains(fn ($c) => $c->school?->name === 'בית ספר שרכש'));
        $this->assertTrue($summary['interested']->contains(fn ($l) => $l->school?->name === 'בית ספר שהתעניין'));
        $this->assertFalse($summary['interested']->contains(fn ($l) => $l->school?->name === 'בית ספר שרכש'));
    }

    public function test_latest_program_summary_is_null_for_a_user_with_no_relevant_permission(): void
    {
        $noAccessRole = Role::create(['name' => 'ללא הרשאות', 'is_active' => true]);
        $noAccessUser = User::create([
            'name' => 'ללא גישה', 'email' => 'noaccess@kapaim.test', 'password' => 'password',
            'role_id' => $noAccessRole->id, 'is_active' => true,
        ]);

        $this->createProgram('תוכנית');

        $this->assertNull($this->dashboard->latestProgramSummary($noAccessUser));
    }

    public function test_latest_program_summary_shows_only_the_reps_own_interested_leads(): void
    {
        $program = $this->createProgram('תוכנית לבדיקת הרשאות');

        $leadA = $this->createLead($this->repA, schoolName: 'בית ספר שרית מתעניין');
        $leadA->interestedPrograms()->attach($program->id);

        $leadB = $this->createLead($this->repB, schoolName: 'בית ספר מיכל מתעניין');
        $leadB->interestedPrograms()->attach($program->id);

        $summary = $this->dashboard->latestProgramSummary($this->repA);

        $this->assertTrue($summary['interested']->contains(fn ($l) => $l->school?->name === 'בית ספר שרית מתעניין'));
        $this->assertFalse($summary['interested']->contains(fn ($l) => $l->school?->name === 'בית ספר מיכל מתעניין'));
        $this->assertTrue($summary['purchased']->isEmpty());
    }

    // ----- helpers -----

    private function createLead(User $assignedUser, ?string $schoolName = null): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => $schoolName ?? 'בית ספר '.random_int(1, 999999)]);

        return Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $assignedUser->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    private function advanceLeadStatus(Lead $lead, string $statusName): void
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => $statusName],
            ['is_active' => true, 'sort_order' => 2],
        );
        $lead->update(['status_id' => $status->id]);
    }

    private function createProgram(?string $name = null): Program
    {
        return Program::create([
            'name' => $name ?? 'תוכנית '.random_int(1, 999999),
            'description' => null, 'price' => 500, 'is_premium' => false,
            'is_subscription_type' => false, 'is_active' => true,
        ]);
    }

    private function createCustomer(?string $schoolName = null): Customer
    {
        $lead = $this->createLead($this->owner, schoolName: $schoolName);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }

    private function createDeal(float $agreedAmount, ?string $schoolName = null): Deal
    {
        $customer = $this->createCustomer($schoolName);
        $program = $this->createProgram();

        return Deal::createForCustomer($customer, $program, null, $agreedAmount);
    }

    private function invoiceSentDealWithOpenCollectionTask(float $agreedAmount): Deal
    {
        $deal = $this->createDeal($agreedAmount);
        $invoiceSent = StatusDefinition::firstOrCreate(
            ['scope' => 'deal', 'name' => 'נשלחה חשבונית'],
            ['is_active' => true, 'sort_order' => 2],
        );
        $deal->updateStatusWithLock($deal->version, $invoiceSent->id);

        Task::create([
            'user_id' => $this->owner->id, 'deal_id' => $deal->id, 'customer_id' => $deal->customer_id,
            'task_type' => 'collection', 'status' => 'open', 'title' => 'משימת גבייה', 'due_at' => now()->addDays(7),
        ]);

        return $deal->fresh();
    }

    /** A deal with a real invoice Document generated (FR-4.5's prerequisite) but no payment yet. */
    private function dealWithInvoice(float $agreedAmount, ?string $schoolName = null): Deal
    {
        $deal = $this->createDeal($agreedAmount, $schoolName);

        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $this->createTemplate('contract'));
        $contract->markSigned();

        Document::generateFor($deal, $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity()->id);

        return $deal->fresh();
    }

    /** A deal fully paid, with an invoice, and no receipt/material delivery yet — attentionItems() fixture base. */
    private function fullyPaidDealWithInvoice(float $agreedAmount, ?string $schoolName = null): Deal
    {
        $deal = $this->dealWithInvoice($agreedAmount, $schoolName);
        $method = PaymentMethod::create(['name' => 'אמצעי לבדיקת דשבורד '.random_int(1, 999999), 'type' => 'bank_transfer', 'is_active' => true]);
        $deal->updatePaymentMethod($method->id);
        $deal->recordPayment($deal->version, $agreedAmount);

        return $deal->fresh();
    }

    private function createTemplate(string $documentType): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => ucfirst($documentType).' תבנית בדיקת דשבורד '.random_int(1, 999999),
            'content' => 'תוכן בדיקה עבור '.$documentType,
            'is_active' => true,
        ]);
    }

    private function createBusinessEntity(): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק לבדיקת דשבורד '.random_int(1, 999999),
            'classification' => 'עוסק פטור',
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'business'.random_int(1, 999999).'@example.com',
            'phone' => '03-0000000',
            'is_active' => true,
        ]);
    }
}
